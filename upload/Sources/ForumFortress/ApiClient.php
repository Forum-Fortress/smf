<?php

namespace ForumFortress\Smf;

require_once __DIR__ . '/FfApiResilience.php';


use function array_merge;
use function bin2hex;
use function explode;
use function in_array;
use function is_array;
use function json_decode;
use function json_encode;
use function parse_url;
use function preg_match;
use function preg_match_all;
use function random_bytes;
use function rtrim;
use function strtolower;
use function max;
use function min;
use function microtime;
use function array_values;
use function array_unique;
use function array_map;
use function array_filter;
use function array_keys;
use function round;
use function ksort;
use function gmdate;
use function strpos;
use function substr;
use function time;
use function trim;

class ApiClient
{
	const PLATFORM = 'smf';
	const PLUGIN_VERSION = '1.1.2';
	const CONTROL_PLANE_BASE_URL = 'https://api.ffapi.net';
	const HOURLY_SYNC_MIN_INTERVAL = 540;
	const STANDARD_HEARTBEAT_INTERVAL_SECONDS = 3600;
	const PRO_HEARTBEAT_INTERVAL_SECONDS = 600;
	const ENDPOINT_REFRESH_REQUEST_MAX_DELAY_SECONDS = 60;
	const CONNECTION_TEST_TIMEOUT_SECONDS = 2;
	const CONNECTION_TEST_TOTAL_BUDGET_SECONDS = 5;
	const PLAN_REFRESH_SECONDS = 86400;
	const MODERATION_SYNC_SECONDS = 600;

	protected static $moderation_sync_in_progress = false;
	protected static $last_moderation_sync_at = 0;

	protected $config;
	protected $user;
	protected $auth;
	protected $request;
	protected $root_path;
	protected $php_ext;
	protected $moderation_bridge;
	protected $timeout_queue;

	/** @var string|null Last transport/HTTP/parse error for ACP diagnostics */
	protected $last_request_error = null;
	protected $last_retryable_exception = null;

	protected $last_check_had_timeout = false;

	public function __construct(
		SmfConfig $config,
		SmfUser $user,
		SmfAuth $auth,
		SmfRequest $request,
		string $root_path,
		string $php_ext,
		$moderation_bridge = null,
		$timeout_queue = null
	) {
		$this->config = $config;
		$this->user = $user;
		$this->auth = $auth;
		$this->request = $request;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
		$this->timeout_queue = $timeout_queue ?? new TimeoutQueue($this);
		$this->moderation_bridge = $moderation_bridge ?? new ModerationBridge($this);
	}

	public function get_timeout_queue(): TimeoutQueue
	{
		return $this->timeout_queue;
	}

	public function last_check_had_timeout(): bool
	{
		return $this->last_check_had_timeout;
	}

	public function queue_timeout_recovery(string $endpoint, array $payload, array $context = [])
	{
		$this->timeout_queue->enqueue($endpoint, $payload, $context);
	}

	public function is_enabled(): bool
	{
		return (bool) ($this->config['ffprotect_enabled'] ?? false);
	}

	public function fail_open(): bool
	{
		return (bool) ($this->config['ffprotect_fail_open'] ?? true);
	}

	public function bootstrap_if_needed()
	{
		if (!$this->is_enabled())
		{
			$this->last_request_error = null;
			return null;
		}
		if (trim((string) ($this->config['ffprotect_api_key'] ?? '')) !== '')
		{
			if (trim((string) ($this->config['ffprotect_site_id'] ?? '')) === '')
			{
				return $this->site_status();
			}
			$this->last_request_error = null;
			return null;
		}

		if (!$this->bootstrap_bases_ordered())
		{
			$this->last_request_error = 'Forum Fortress API base URL is not configured.';
			return null;
		}

		$payload = [
			'domain' => $this->get_bootstrap_domain(),
			'platform' => self::PLATFORM,
			'platform_version' => SMF_VERSION,
			'plugin_version' => self::PLUGIN_VERSION,
			'api_key' => null,
		];
		$response = null;
		foreach ($this->bootstrap_bases_ordered() as $base)
		{
			$attempt = $this->request_json_on_base(
				'POST',
				'/v1/site/bootstrap',
				$payload,
				$base,
				false,
				null,
				false
			);
			$data = !empty($attempt['ok']) && isset($attempt['data']) && is_array($attempt['data']) ? $attempt['data'] : null;
			if ($data && !empty($data['api_key']))
			{
				$response = $data;
				break;
			}
		}
		if ($response)
		{
			$this->persist_identity($response);
		}
		else if ($this->last_request_error === null)
		{
			$this->last_request_error = 'Bootstrap did not return an API key.';
		}

		return $response;
	}

	public function check(string $endpoint, array $payload)
	{
		if (!$this->is_enabled())
		{
			return null;
		}

		$this->last_check_had_timeout = false;
		// Generate this once before any endpoint retry/failover. The API uses it
		// to deduplicate one logical check across edge and control attempts.
		$prepared = $this->with_check_request_id($this->prepare_payload($payload));
		if ($endpoint === 'register')
		{
			$timeout = max(1, min(2, (int) ($this->config['ffprotect_timeout'] ?? 3)));
			$response = $this->request_json_with_retry(
				'POST',
				'/v1/check/register',
				$prepared,
				true,
				$timeout,
				true
			);
		}
		else
		{
			$path = '/v1/check/' . $endpoint;
			$response = $this->request_json('POST', $path, $prepared);
			if ($response === null && $endpoint === 'contact_page')
			{
				$legacy = $prepared;
				$legacy['check_endpoint'] = 'contact_page';
				$response = $this->request_json('POST', '/v1/check', $legacy);
			}
		}
		if ($response)
		{
			$this->persist_identity($response);
			$this->maybe_refresh_endpoint_catalog_after_check_in(
				$endpoint === 'register' ? '/v1/check/register' : '/v1/check/' . $endpoint
			);
		}
		return $response;
	}

	public function report(string $endpoint, array $payload)
	{
		if (!$this->is_enabled())
		{
			return null;
		}

		$prepared = $this->prepare_payload($payload);
		$response = $this->request_json('POST', '/v1/report/' . $endpoint, $prepared);
		if ($response)
		{
			$this->persist_identity($response);
		}
		return $response;
	}

	public function health($timeoutOverride = null)
	{
		return $this->site_ping();
	}

	public function capabilities($timeoutOverride = null)
	{
		if (!$this->is_enabled())
		{
			$this->last_request_error = null;
			return null;
		}

		return $this->request_json_control_plane('GET', '/v1/capabilities', [], $timeoutOverride ?? self::CONNECTION_TEST_TIMEOUT_SECONDS);
	}

	public function site_status()
	{
		if (!$this->is_enabled())
		{
			$this->last_request_error = null;
			return null;
		}

		$api_key = trim((string) ($this->config['ffprotect_api_key'] ?? ''));
		if ($api_key === '')
		{
			$this->last_request_error = null;
			return null;
		}

		$response = $this->request_json_control_plane('GET', '/v1/site/status', [
			'api_key' => $api_key,
			'domain' => $this->get_domain(),
		]);
		if (is_array($response))
		{
			$this->persist_identity($response);
		}
		return $response;
	}

	public function forum_stats()
	{
		if (!$this->is_enabled())
		{
			$this->last_request_error = null;
			return null;
		}

		$api_key = trim((string) ($this->config['ffprotect_api_key'] ?? ''));
		if ($api_key === '')
		{
			$this->last_request_error = null;
			return null;
		}

		return $this->request_json_control_plane('GET', '/v1/forum/stats', [
			'api_key' => $api_key,
			'domain' => $this->get_domain(),
		]);
	}

	public function plugin_release()
	{
		if (!$this->is_enabled())
		{
			return null;
		}

		return $this->request_json_control_plane('GET', '/v1/plugin-release', [
			'platform' => self::PLATFORM,
			'current_version' => self::PLUGIN_VERSION,
		]);
	}

	public function register_site(string $email)
	{
		if (!$this->is_enabled())
		{
			$this->last_request_error = null;
			return null;
		}

		$site_id = trim((string) ($this->config['ffprotect_site_id'] ?? ''));
		if ($site_id === '')
		{
			$this->bootstrap_if_needed();
			$site_id = trim((string) ($this->config['ffprotect_site_id'] ?? ''));
		}

		if ($site_id === '')
		{
			$this->last_request_error = 'Site is not bootstrapped yet (no site_id). Run bootstrap or save API settings first.';
			return null;
		}

		$register_payload = [
			'domain' => $this->get_domain(),
			'email' => trim($email),
			'site_id' => $site_id,
		];
		$api_key = trim((string) ($this->config['ffprotect_api_key'] ?? ''));
		if ($api_key !== '')
		{
			$register_payload['api_key'] = $api_key;
		}

		$response = $this->request_json('POST', '/v1/site/register', $register_payload);

		if ($response)
		{
			$this->persist_identity($response);
		}

		return $response;
	}

	public function portal_launch()
	{
		if (!$this->is_enabled())
		{
			return null;
		}

		$site_id = trim((string) ($this->config['ffprotect_site_id'] ?? ''));
		if ($site_id === '')
		{
			$this->bootstrap_if_needed();
			$site_id = trim((string) ($this->config['ffprotect_site_id'] ?? ''));
		}

		$api_key = trim((string) ($this->config['ffprotect_api_key'] ?? ''));
		if ($site_id === '' || $api_key === '')
		{
			return null;
		}

		return $this->request_json('POST', '/v1/site/portal', [
			'api_key' => $api_key,
			'site_id' => $site_id,
			'domain' => $this->get_domain(),
			'platform' => self::PLATFORM,
			'platform_version' => SMF_VERSION,
			'plugin_version' => self::PLUGIN_VERSION,
		]);
	}

	public function is_safe_portal_url(string $value): bool
	{
		if (trim($value) === '' || filter_var($value, FILTER_VALIDATE_URL) === false)
		{
			return false;
		}
		$parts = parse_url($value);
		if (!is_array($parts))
		{
			return false;
		}
		$host = strtolower((string) ($parts['host'] ?? ''));
		$trusted_host = $host === 'forumfortress.com'
			|| substr($host, -strlen('.forumfortress.com')) === '.forumfortress.com'
			|| $host === 'ffapi.net'
			|| substr($host, -strlen('.ffapi.net')) === '.ffapi.net';
		$path = '/' . ltrim((string) ($parts['path'] ?? ''), '/');
		$query = [];
		parse_str((string) ($parts['query'] ?? ''), $query);
		return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
			&& $trusted_host
			&& !array_key_exists('user', $parts)
			&& !array_key_exists('pass', $parts)
			&& !array_key_exists('fragment', $parts)
			&& (!array_key_exists('port', $parts) || (int) $parts['port'] === 443)
			&& rtrim($path, '/') === '/access'
			&& is_string($query['token'] ?? null)
			&& trim($query['token']) !== '';
	}

	/**
	 * Check-in: updates forum row (domain, platform, phpBB + plugin versions, last_seen).
	 */
	public function site_ping()
	{
		if (!$this->is_enabled())
		{
			return null;
		}

		$site_id = trim((string) ($this->config['ffprotect_site_id'] ?? ''));
		$api_key = trim((string) ($this->config['ffprotect_api_key'] ?? ''));
		if ($site_id === '' || $api_key === '')
		{
			return null;
		}

		$payload = $this->request_json_control_plane('POST', '/v1/site/ping', [
			'api_key' => $api_key,
			'site_id' => $site_id,
			'domain' => $this->get_domain(),
			'platform' => self::PLATFORM,
			'platform_version' => SMF_VERSION,
			'plugin_version' => self::PLUGIN_VERSION,
		]);
		if (is_array($payload))
		{
			$state = $this->load_endpoint_state();
			$state['last_site_ping_at'] = (int) time();
			$this->save_endpoint_state($state);
			$this->maybe_refresh_endpoint_catalog_after_check_in('/v1/site/ping');
		}
		return $payload;
	}

	public function send_ham_enabled(): bool
	{
		return (bool) ($this->config['ffprotect_send_ham'] ?? true);
	}

	public function delete_rejected_users_enabled(): bool
	{
		return (bool) ($this->config['ffprotect_delete_rejected_users'] ?? false);
	}

	public function bypass_administrators_enabled(): bool
	{
		return (bool) ($this->config['ffprotect_bypass_administrators'] ?? true);
	}

	public function bypass_moderators_enabled(): bool
	{
		return (bool) ($this->config['ffprotect_bypass_moderators'] ?? true);
	}

	/**
	 * Skip spam checks for staff when configured.
	 */
	public function protection_checks_bypassed(): bool
	{
		if (empty($this->user->data['user_id']))
		{
			return false;
		}

		if ($this->bypass_administrators_enabled())
		{
			// SMF has no phpBB USER_FOUNDER constant or includes/constants.php.
			// The admin_forum permission is the native SMF authority check.
			if ($this->auth->acl_get('a_'))
			{
				return true;
			}
		}

		if ($this->bypass_moderators_enabled() && $this->auth->acl_getf_global('m_'))
		{
			return true;
		}

		return false;
	}

	public function activate_attack_mode()
	{
		if (!$this->is_enabled())
		{
			return null;
		}

		$site_id = trim((string) ($this->config['ffprotect_site_id'] ?? ''));
		$api_key = trim((string) ($this->config['ffprotect_api_key'] ?? ''));
		if ($site_id === '' || $api_key === '')
		{
			return null;
		}

		// Attack mode is control-plane only; edge nodes return 404 for this path.
		$response = $this->request_json_control_plane('POST', '/v1/site/attack-mode', [
			'site_id' => $site_id,
			'api_key' => $api_key,
			'domain' => $this->get_domain(),
		]);

		return $this->assert_attack_mode_response($response, true);
	}

	public function deactivate_attack_mode()
	{
		if (!$this->is_enabled())
		{
			return null;
		}

		$site_id = trim((string) ($this->config['ffprotect_site_id'] ?? ''));
		$api_key = trim((string) ($this->config['ffprotect_api_key'] ?? ''));
		if ($site_id === '' || $api_key === '')
		{
			return null;
		}

		$response = $this->request_json_control_plane('POST', '/v1/site/attack-mode/end', [
			'site_id' => $site_id,
			'api_key' => $api_key,
			'domain' => $this->get_domain(),
		]);

		return $this->assert_attack_mode_response($response, false);
	}

	protected function assert_attack_mode_response($response, bool $enabled): array
	{
		$actual = null;
		if (is_array($response) && array_key_exists('attack_mode_active', $response))
		{
			$actual = (bool) $response['attack_mode_active'];
		}
		elseif (is_array($response) && array_key_exists('enabled', $response))
		{
			$actual = (bool) $response['enabled'];
		}
		elseif (is_array($response) && is_array($response['attack_mode'] ?? null) && array_key_exists('enabled', $response['attack_mode']))
		{
			$actual = (bool) $response['attack_mode']['enabled'];
		}
		if (
			$actual === null
			|| $actual !== $enabled
		)
		{
			throw new \RuntimeException(
				$enabled
					? 'Forum Fortress did not confirm that attack mode is active.'
					: 'Forum Fortress did not confirm that attack mode has ended.'
			);
		}

		$response['attack_mode_active'] = $actual;
		return $response;
	}

	public function hourly_sync()
	{
		if (!$this->is_enabled())
		{
			return;
		}

		$gate_state = $this->load_endpoint_state();
		$last_hourly = (int) ($gate_state['hourly_sync_last_at'] ?? 0);
		if ($last_hourly > 0 && (time() - $last_hourly) < self::HOURLY_SYNC_MIN_INTERVAL)
		{
			return;
		}
		$gate_state['hourly_sync_last_at'] = (int) time();
		$this->save_endpoint_state($gate_state);

		try
		{
			$this->bootstrap_if_needed();
		}
		catch (\Throwable $e)
		{
		}

		try
		{
			if ($this->should_run_daily_task('plugin_release_last_at'))
			{
				$this->plugin_release();
				$this->mark_daily_task_run('plugin_release_last_at');
			}
		}
		catch (\Throwable $e)
		{
		}

		$heartbeat_state = $this->load_endpoint_state();
		$last_heartbeat = max(
			(int) ($heartbeat_state['last_site_ping_at'] ?? 0),
			(int) ($heartbeat_state['last_site_ping_attempt_at'] ?? 0)
		);
		$plan = strtolower(trim((string) ($heartbeat_state['plan_name'] ?? '')));
		$heartbeat_interval = in_array($plan, ['pro', 'multimod'], true)
			? self::PRO_HEARTBEAT_INTERVAL_SECONDS
			: self::STANDARD_HEARTBEAT_INTERVAL_SECONDS;
		if ($last_heartbeat <= 0 || (time() - $last_heartbeat) >= $heartbeat_interval)
		{
			$heartbeat_state['last_site_ping_attempt_at'] = time();
			$this->save_endpoint_state($heartbeat_state);
			try
			{
				$this->site_ping();
			}
			catch (\Throwable $e)
			{
			}
		}

		try
		{
			$this->refresh_plan_cache_if_stale(true);
		}
		catch (\Throwable $e)
		{
		}

		$this->run_moderation_sync_cycle(true, true);
		$this->config->set('ffprotect_cron_sync_last', time());
	}

	public function run_moderation_sync_cycle(bool $force = false, bool $system_execution = false)
	{
		if (!$this->is_enabled() || self::$moderation_sync_in_progress)
		{
			return;
		}

		$site_id = trim((string) ($this->config['ffprotect_site_id'] ?? ''));
		$api_key = trim((string) ($this->config['ffprotect_api_key'] ?? ''));
		if ($site_id === '' || $api_key === '')
		{
			return;
		}

		$state = $this->load_endpoint_state();
		$last_sync_at = (int) ($state['moderation_last_sync_at'] ?? 0);
		$interval_seconds = $this->get_moderation_sync_interval_seconds($state);
		if (!$force && (time() - $last_sync_at) < $interval_seconds)
		{
			return;
		}

		self::$moderation_sync_in_progress = true;
		self::$last_moderation_sync_at = time();

		try
		{
			$sync_payload = $this->request_json('POST', '/v1/moderation-queue/sync', [
				'api_key' => $api_key,
				'site_id' => $site_id,
				'domain' => $this->get_domain(),
				'platform' => self::PLATFORM,
				'platform_version' => SMF_VERSION,
				'plugin_version' => self::PLUGIN_VERSION,
				'block_reject_action' => $this->get_block_reject_action(),
				// The bridge intentionally pages local moderation items, so this is
				// never an authoritative snapshot of the complete queue.
				'snapshot_complete' => false,
				'items' => $this->moderation_bridge->collect_queue_items(),
			]);
			if (is_array($sync_payload) && !empty($sync_payload['queue_notes']) && is_array($sync_payload['queue_notes']))
			{
				$this->moderation_bridge->apply_queue_notes($sync_payload['queue_notes']);
			}

			$pending_remaining = 0;
			for ($pass = 0; $pass < 8; $pass++)
			{
				$actions_payload = $this->request_json('POST', '/v1/moderation-actions/pull', [
					'api_key' => $api_key,
					'site_id' => $site_id,
					'domain' => $this->get_domain(),
					'platform' => self::PLATFORM,
					'platform_version' => SMF_VERSION,
					'plugin_version' => self::PLUGIN_VERSION,
					'limit' => 25,
				]) ?? [];
				$actions = is_array($actions_payload['actions'] ?? null) ? $actions_payload['actions'] : [];
				$pending_remaining = (int) ($actions_payload['pending_actions'] ?? 0);
				if (!$actions)
				{
					break;
				}
				$results = $system_execution
					? $this->moderation_bridge->execute_system_actions($actions)
					: $this->moderation_bridge->execute_actions($actions);
				$this->request_json('POST', '/v1/moderation-actions/ack', [
					'api_key' => $api_key,
					'site_id' => $site_id,
					'domain' => $this->get_domain(),
					'platform' => self::PLATFORM,
					'platform_version' => SMF_VERSION,
					'plugin_version' => self::PLUGIN_VERSION,
					'results' => $results,
				]);
			}
			if (is_array($sync_payload))
			{
				$pending_remaining = max($pending_remaining, (int) ($sync_payload['pending_actions'] ?? 0));
			}
			$state['moderation_pending_actions'] = max(0, $pending_remaining);
			$state['moderation_last_sync_at'] = (int) time();
			$this->save_endpoint_state($state);
		}
		catch (\Throwable $e)
		{
			$this->log('error', 'Forum Fortress moderation sync failed', ['message' => $e->getMessage()]);
		}
		finally
		{
			self::$moderation_sync_in_progress = false;
		}
	}

	protected function with_check_request_id(array $payload): array
	{
		if (!isset($payload['check_request_id']) || trim((string) $payload['check_request_id']) === '')
		{
			$payload['check_request_id'] = bin2hex(random_bytes(16));
		}

		return $payload;
	}

	public function prepare_payload(array $payload): array
	{
		$domain = $payload['domain'] ?? $this->get_domain();
		$api_key = trim((string) ($this->config['ffprotect_api_key'] ?? ''));
		$defaults = [
			'domain' => $domain,
			'platform' => self::PLATFORM,
			'platform_version' => SMF_VERSION,
			'plugin_version' => self::PLUGIN_VERSION,
		];
		if ($api_key !== '')
		{
			$defaults['api_key'] = $api_key;
		}
		return array_merge($defaults, $payload);
	}

	public function get_last_request_error()
	{
		return $this->last_request_error;
	}

	public function clear_last_request_error()
	{
		$this->last_request_error = null;
	}

	public function get_domain(): string
	{
		global $boardurl;
		$stored = trim((string) ($this->config['ffprotect_primary_domain'] ?? ''));
		if ($stored !== '')
		{
			return self::normalize_domain($stored);
		}

		// SMF's configured URL is authoritative. Never bootstrap a site from
		// the request Host header, which an unauthenticated caller controls.
		$board_host = parse_url((string) ($boardurl ?? ''), PHP_URL_HOST);
		if (is_string($board_host) && $board_host !== '')
		{
			return self::normalize_domain($board_host);
		}

		$server_name = trim((string) ($this->config['server_name'] ?? ''));
		if ($server_name !== '')
		{
			return self::normalize_domain($this->strip_host_port($server_name));
		}

		return '';
	}

	protected function get_bootstrap_domain(): string
	{
		$state = $this->load_endpoint_state();
		$canonical = trim((string) ($state['offline_canonical_domain'] ?? ''));

		return $canonical !== '' ? $canonical : $this->get_domain();
	}

	protected function is_offline_api_key(): bool
	{
		return \FfApiResilience::isOfflineBootstrapKey(trim((string) ($this->config['ffprotect_api_key'] ?? '')), null);
	}

	/**
	 * Remove :port from host (HTTP_HOST / server_name). Keeps API domain aligned with forum_domains.
	 */
	protected function strip_host_port(string $host): string
	{
		$host = trim($host);
		if ($host === '' || strpos($host, ':') === false)
		{
			return $host;
		}

		if ($host[0] === '[')
		{
			$end = strpos($host, ']:');
			if ($end !== false)
			{
				return strtolower(substr($host, 1, $end - 1));
			}

			return strtolower($host);
		}

		$parsed = parse_url('http://' . $host);
		if (!empty($parsed['host']))
		{
			return strtolower((string) $parsed['host']);
		}

		return strtolower($host);
	}

	public function get_root_path(): string
	{
		return $this->root_path;
	}

	public function get_php_ext(): string
	{
		return $this->php_ext;
	}

	protected function language_hint(string $value)
	{
		$raw = trim(str_replace('_', '-', $value));
		if ($raw === '')
		{
			return null;
		}
		$parts = explode('-', $raw, 2);
		$lang = strtolower(trim((string) ($parts[0] ?? '')));
		return $lang !== '' ? $lang : null;
	}

	protected function timezone_name()
	{
		$tz = $this->user->timezone ?? null;
		if ($tz && method_exists($tz, 'getName'))
		{
			$name = trim((string) $tz->getName());
			if ($name !== '')
			{
				return $name;
			}
		}
		return null;
	}

	protected function timezone_offset_minutes()
	{
		$tz = $this->user->timezone ?? null;
		if (!$tz || !method_exists($tz, 'getOffset'))
		{
			return null;
		}
		try
		{
			$seconds = (int) $tz->getOffset(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
			return (int) round($seconds / 60);
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}

	protected function normalise_base_url(string $value): string
	{
		$value = rtrim(trim($value), '/');
		if ($value === '' || preg_match('/\s/', $value))
		{
			return '';
		}

		$parsed = parse_url($value);
		if (!is_array($parsed) || empty($parsed['scheme']) || empty($parsed['host']))
		{
			return '';
		}
		if (isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['query']) || isset($parsed['fragment']))
		{
			return '';
		}

		$scheme = strtolower((string) $parsed['scheme']);
		if ($scheme !== 'https')
		{
			return '';
		}

		return $value;
	}

	protected function get_manual_base_url(): string
	{
		$legacy = (string) ($this->config['ffprotect_api_base_url'] ?? '');
		if (!isset($this->config['ffprotect_api_region']) && \FfApiResilience::isLocalDevelopmentBaseUrl($legacy))
		{
			return $this->normalise_base_url($legacy);
		}
		return \FfApiResilience::apiBaseUrlForRegion($this->get_api_region());
	}

	protected function get_api_region(): string
	{
		$stored = (string) ($this->config['ffprotect_api_region'] ?? '');
		return \FfApiResilience::normaliseApiRegion($stored !== '' ? $stored : \FfApiResilience::apiRegionFromLegacyBaseUrl((string) ($this->config['ffprotect_api_base_url'] ?? '')));
	}

	protected function allow_global_emergency_fallback(): bool
	{
		return !empty($this->config['ffprotect_allow_global_fallback']);
	}

	protected function get_control_plane_base_url(): string
	{
		return self::CONTROL_PLANE_BASE_URL;
	}

	protected function get_hot_failover_api_base_url(): string
	{
		return \FfApiResilience::hotFailoverApiBaseUrl(
			$this->get_manual_base_url(),
			$this->get_control_plane_base_url()
		);
	}

	/** @return list<string> */
	protected function edge_bases_from_state(): array
	{
		$state = $this->load_endpoint_state();
		$endpoint_list = is_array($state['endpoints'] ?? null) ? $state['endpoints'] : [];
		$edges = [];
		foreach ($endpoint_list as $row)
		{
			$edges[] = (string) $row;
		}

		return $edges;
	}

	/** @return list<string> */
	protected function bootstrap_bases_ordered(): array
	{
		$manual = $this->get_manual_base_url();
		if (\FfApiResilience::isLocalDevelopmentBaseUrl($manual))
		{
			return [$manual];
		}
		return \FfApiResilience::regionLockedCheckBases(
			$this->get_api_region(),
			$this->allow_global_emergency_fallback()
		);
	}

	/** @return list<string> */
	protected function catalog_fetch_bases(): array
	{
		return $this->bootstrap_bases_ordered();
	}

	/** @return list<string> */
	protected function control_plane_request_bases(): array
	{
		return $this->bootstrap_bases_ordered();
	}

	/** @param list<string> $endpoints */
	protected function normalised_endpoint_list(array $endpoints): array
	{
		$normalised = array_values(array_unique(array_map(function ($u) {
			return $this->normalise_base_url((string) $u);
		}, $endpoints)));
		$normalised = array_values(array_filter($normalised, function ($u) {
			return $u !== '';
		}));
		sort($normalised);

		return $normalised;
	}

	/** @param list<string> $previous @param list<string> $next */
	protected function endpoint_catalog_changed(array $previous, array $next): bool
	{
		return $this->normalised_endpoint_list($previous) !== $this->normalised_endpoint_list($next);
	}

	/** @param array<string, mixed> $state */
	protected function invalidate_endpoint_health_state(array &$state)
	{
		$state['last_health_at'] = 0;
		$state['health_day'] = '';
	}

	protected function fetch_node_endpoints_catalog(bool $force = false): bool
	{
		$state = $this->load_endpoint_state();
		$state['endpoints'] = $this->bootstrap_bases_ordered();
		$state['catalog_fetched_at'] = 0;
		unset($state['endpoint_meta'], $state['control_check_fallback'], $state['catalog_generated_at']);
		$this->save_endpoint_state($state);

		return true;
	}

	public function refresh_endpoint_catalog_if_stale()
	{
		if (!$this->is_enabled() || $this->get_manual_base_url() === '')
		{
			return;
		}

		try
		{
			$this->fetch_node_endpoints_catalog(false);
		}
		catch (\Throwable $e)
		{
		}
	}

	protected function maybe_refresh_endpoint_catalog_after_check_in(string $request_path)
	{
		if (!\FfApiResilience::shouldRefreshEndpointCatalogOnCheckIn($request_path))
		{
			return;
		}

		$this->refresh_endpoint_catalog_if_stale();
	}

	protected function is_catalog_backup_role($role): bool
	{
		$role = strtolower(trim((string) $role));

		return in_array($role, ['backup', 'control-fallback', 'control'], true);
	}

	protected function is_catalog_backup_endpoint_url(string $base_url, $role = null): bool
	{
		if ($this->is_catalog_backup_role($role))
		{
			return true;
		}
		$control = $this->get_control_plane_base_url();

		return $control !== '' && $this->normalise_base_url($base_url) === $control;
	}

	protected function is_shared_api_round_robin_base(string $base_url): bool
	{
		$manual = $this->get_manual_base_url();
		$base_url = $this->normalise_base_url($base_url);
		if ($manual === '' || $base_url !== $manual)
		{
			return false;
		}
		$host = parse_url($manual, PHP_URL_HOST);

		return is_string($host) && strpos(strtolower($host), 'api.') === 0;
	}

	/** Bootstrap, catalog, capabilities, plugin-release: control, hot api, then edges. */
	protected function request_json_control_plane(string $method, string $path, array $payload)
	{
		$this->last_request_error = null;
		$bases = $this->control_plane_request_bases();
		if (!$bases)
		{
			$manual = $this->get_manual_base_url();
			if ($manual !== '')
			{
				$bases = [$manual];
			}
		}
		if (!$bases)
		{
			$this->last_request_error = 'Forum Fortress API base URL is not configured.';
			return null;
		}
		foreach ($bases as $base)
		{
			$attempt = $this->request_json_on_base($method, $path, $payload, $base, false, null, false);
			if (!empty($attempt['ok']) && isset($attempt['data']) && is_array($attempt['data']))
			{
				return $attempt['data'];
			}
		}

		return null;
	}

	/** @return array<string, mixed> */
	protected function load_endpoint_state(): array
	{
		$raw = trim((string) ($this->config['ffprotect_endpoint_state'] ?? ''));
		if ($raw === '')
		{
			return [];
		}
		$data = json_decode($raw, true);
		return is_array($data) ? $data : [];
	}

	/** @param array<string, mixed> $state */
	protected function save_endpoint_state(array $state)
	{
		ksort($state);
		$encoded = json_encode($state, JSON_UNESCAPED_SLASHES);
		if (!is_string($encoded))
		{
			return;
		}
		$current = trim((string) ($this->config['ffprotect_endpoint_state'] ?? ''));
		if ($current === $encoded)
		{
			return;
		}
		$this->config->set('ffprotect_endpoint_state', $encoded);
	}

	public function endpoint_state_snapshot(): array
	{
		$this->hydrate_endpoint_state_if_stale();
		$state = $this->load_endpoint_state();
		$manual = $this->get_manual_base_url();
		$endpoints = is_array($state['endpoints'] ?? null) ? $state['endpoints'] : [];
		$endpoints = $this->normalise_and_sanitise_endpoints($endpoints, $manual);

		return [
			'catalog_fetched_at' => (int) ($state['catalog_fetched_at'] ?? 0),
			'endpoints' => $endpoints,
			// Retain empty compatibility fields for older admin templates; the
			// plugin no longer probes or stores endpoint latency.
			'health_day' => '',
			'health_ms' => [],
			'last_health_at' => (int) ($state['catalog_fetched_at'] ?? 0),
			'last_responded' => $this->normalise_base_url((string) ($state['last_responded'] ?? '')),
			'last_responded_node' => trim((string) ($state['last_responded_node'] ?? '')),
			'last_response_at' => (int) ($state['last_response_at'] ?? 0),
			'last_site_ping_at' => (int) ($state['last_site_ping_at'] ?? 0),
			'last_failure' => is_array($state['last_failure'] ?? null) ? $state['last_failure'] : null,
			// Compatibility fields for existing admin templates. GeoDNS owns
			// route selection, so legacy preference state is intentionally ignored.
			'preferred' => $manual,
			'preferred_missing' => '',
			'preferred_missing_at' => 0,
		];
	}

	protected function hydrate_endpoint_state_if_stale()
	{
		$state = $this->load_endpoint_state();
		$needs_hydration = false;
		if (!is_array($state['endpoints'] ?? null) || !$state['endpoints'])
		{
			$needs_hydration = true;
		}
		if ((int) ($state['catalog_fetched_at'] ?? 0) <= 0)
		{
			$needs_hydration = true;
		}
		if (!$needs_hydration)
		{
			return;
		}
		try
		{
			$this->refresh_endpoint_catalog_and_health();
		}
		catch (\Throwable $e)
		{
		}
	}

	/** @return array{preferred: string, last_responded: string, endpoints_count: int, last_health_at: int, preferred_missing: string, last_site_ping_at: int} */
	public function endpoint_state_summary(): array
	{
		$state = $this->endpoint_state_snapshot();
		$preferred = (string) ($state['preferred'] ?? '');
		$last_responded_node = trim((string) ($state['last_responded_node'] ?? ''));
		$last_responded_base = $this->normalise_base_url((string) ($state['last_responded'] ?? ''));
		$last_responded = $last_responded_node !== '' ? $last_responded_node : $last_responded_base;
		$endpoints_count = is_array($state['endpoints'] ?? null) ? count($state['endpoints']) : 0;
		return [
			'preferred' => $preferred,
			'last_responded' => $last_responded,
			'endpoints_count' => $endpoints_count,
			'last_health_at' => (int) ($state['last_health_at'] ?? 0),
			'preferred_missing' => trim((string) ($state['preferred_missing'] ?? '')),
			'last_site_ping_at' => (int) ($state['last_site_ping_at'] ?? 0),
		];
	}

	public function endpoint_health_display_label(string $endpoint_url, $state = null): string
	{
		$state = $state ?? $this->load_endpoint_state();
		$endpoint_url = $this->normalise_base_url($endpoint_url);
		if ($endpoint_url === $this->get_manual_base_url())
		{
			return 'GeoDNS primary';
		}
		$meta = is_array($state['endpoint_meta'][$endpoint_url] ?? null) ? $state['endpoint_meta'][$endpoint_url] : [];
		$role = strtolower((string) ($meta['role'] ?? ''));
		if ($this->is_catalog_backup_endpoint_url($endpoint_url, $role))
		{
			return 'control fallback';
		}
		return array_key_exists('check_ready', $meta) && empty($meta['check_ready'])
			? 'catalog standby'
			: 'catalog fallback';
	}
	/**
	 * @return list<array{endpoint: string, latency: string, is_preferred: bool}>
	 */
	public function build_endpoint_latency_rows(): array
	{
		$this->hydrate_endpoint_state_if_stale();
		$state = $this->load_endpoint_state();
		$primary = $this->get_manual_base_url();
		$targets = \FfApiResilience::uniqueOrderedBases(
			$primary !== '' ? [$primary] : [],
			is_array($state['endpoints'] ?? null) ? $state['endpoints'] : []
		);
		$rows = [];
		foreach ($targets as $endpoint_url)
		{
			$rows[] = [
				'endpoint' => $endpoint_url,
				'latency' => $this->endpoint_health_display_label($endpoint_url, $state),
				'is_preferred' => $endpoint_url === $primary,
			];
		}
		return $rows;
	}
	public function refresh_endpoint_catalog_and_health(bool $force = false)
	{
		if (!$this->is_enabled())
		{
			return;
		}
		$primary = $this->get_manual_base_url();
		if ($primary === '')
		{
			return;
		}
		$state = $this->load_endpoint_state();
		if ($force)
		{
			$state['catalog_fetched_at'] = 0;
			$this->save_endpoint_state($state);
		}
		if ($force || \FfApiResilience::isEndpointCatalogStale($state))
		{
			$this->fetch_node_endpoints_catalog($force);
			$state = $this->load_endpoint_state();
		}
		$endpoints = is_array($state['endpoints'] ?? null) ? $state['endpoints'] : [];
		$state['endpoints'] = $this->normalise_and_sanitise_endpoints($endpoints, $primary);
		$state['preferred'] = $primary;
		$state['refresh_requested_at'] = 0;
		unset(
			$state['health_day'],
			$state['health_ms'],
			$state['last_health_at'],
			$state['health_timed_out'],
			$state['slow_health_mode'],
			$state['best_latency_ms'],
			$state['preferred_candidate'],
			$state['preferred_candidate_streak'],
			$state['preferred_missing'],
			$state['preferred_missing_at'],
			$state['suppressed_endpoints']
		);
		$this->save_endpoint_state($state);
	}
	public function refresh_endpoints_before_connection_test()
	{
		if (\FfApiResilience::apiRegionIsLocked($this->get_api_region()))
		{
			return;
		}
		$this->refresh_endpoint_catalog_and_health(true);
	}

	/**
	 * @param list<string> $endpoints
	 * @return list<string>
	 */
	protected function normalise_and_sanitise_endpoints(array $endpoints, string $manual_base): array
	{
		$manual_base = $this->normalise_base_url($manual_base);
		$normalised = array_values(array_unique(array_map(function ($u) {
			return $this->normalise_base_url((string) $u);
		}, $endpoints)));
		$normalised = array_values(array_filter($normalised, function ($u) {
			return $u !== '';
		}));
		if ($manual_base !== '' && count($normalised) > 1)
		{
			$normalised = array_values(array_filter($normalised, function ($u) use ($manual_base) {
				return $u !== $manual_base;
			}));
		}
		if (!$normalised && $manual_base !== '')
		{
			$normalised = [$manual_base];
		}
		return $normalised;
	}

	/**
	 * The catalog controls whether the concrete control fallback may serve checks.
	 * Normal check routing itself always starts at the GeoDNS hostname.
	 */
	protected function base_url_may_serve_check_traffic(string $base_url): bool
	{
		$base_url = $this->normalise_base_url($base_url);
		$control = $this->normalise_base_url($this->get_control_plane_base_url());
		if ($base_url === '' || $base_url !== $control)
		{
			return $base_url !== '';
		}
		$state = $this->load_endpoint_state();
		if (!empty($state['control_check_fallback']))
		{
			return true;
		}
		foreach (is_array($state['endpoint_meta'] ?? null) ? $state['endpoint_meta'] : [] as $url => $meta)
		{
			if (!is_array($meta) || empty($meta['check_ready']))
			{
				continue;
			}
			$role = isset($meta['role']) ? (string) $meta['role'] : null;
			if (!$this->is_catalog_backup_endpoint_url((string) $url, $role))
			{
				return false;
			}
		}
		return true;
	}

	/**
	 * @return array{status: int, data: ?array, body: string}
	 */
	protected function raw_get_json(string $base, string $path, int $timeout): array
	{
		$base = $this->normalise_base_url($base);
		$out = ['status' => 0, 'data' => null, 'body' => ''];
		if ($base === '')
		{
			return $out;
		}
		$url = $base . $path;
		$ctx = stream_context_create([
			'http' => [
				'method' => 'GET',
				'timeout' => $timeout,
				'ignore_errors' => true,
				'follow_location' => 0,
				'header' => "Accept: application/json\r\n",
			],
		]);
		$http_response_header = [];
		$raw = @file_get_contents($url, false, $ctx);
		$out['status'] = $this->parse_http_status($http_response_header);
		if (is_string($raw))
		{
			$out['body'] = $raw;
			$decoded = json_decode($raw, true);
			$out['data'] = is_array($decoded) ? $decoded : null;
		}
		return $out;
	}

	/**
	 * @return list<string>
	 */
	protected function get_ordered_bases_for_requests($request_path = null): array
	{
		$primary = $this->get_manual_base_url();
		if ($primary === '')
		{
			return [];
		}
		if (\FfApiResilience::isLocalDevelopmentBaseUrl($primary))
		{
			return [$primary];
		}
		$state = $this->load_endpoint_state();
		if ($this->is_offline_api_key())
		{
			$pinned = \FfApiResilience::offlinePinnedCheckBases($state);
			if ($pinned)
			{
				return $pinned;
			}
		}
		return \FfApiResilience::regionLockedCheckBases(
			$this->get_api_region(),
			$this->allow_global_emergency_fallback()
		);
	}
	public function build_user_payload(array $user_row = []): array
	{
		$email = trim((string) ($user_row['user_email'] ?? ''));
		$email_domain = self::email_domain($email);
		return [
			'ip' => (string) $this->user->ip,
			'username' => (string) ($user_row['username'] ?? $this->user->data['username'] ?? ''),
			'email' => $email !== '' ? $email : null,
			'email_domain' => $email_domain,
			'user_agent' => (string) $this->request->header('User-Agent'),
			'account_age_seconds' => !empty($user_row['user_regdate']) ? max(0, time() - (int) $user_row['user_regdate']) : 0,
			'post_count' => (int) ($user_row['user_posts'] ?? 0),
		];
	}

	public function request_json(string $method, string $path, array $payload)
	{
		return $this->request_json_with_retry($method, $path, $payload, true, null, false);
	}

	public function request_json_with_retry(
		string $method,
		string $path,
		array $payload,
		bool $allow_rebootstrap,
		$timeout_override,
		bool $suppress_timeout_error,
		bool $timeout_retry_attempted = false
	) {
		$this->last_request_error = null;
		$this->last_retryable_exception = null;
		$result = $this->request_json_with_retry_pass(
			$method,
			$path,
			$payload,
			$allow_rebootstrap,
			$timeout_override,
			$suppress_timeout_error,
			$timeout_retry_attempted
		);
		if ($result !== null)
		{
			return $result;
		}
		$this->throw_last_retryable_exception_if_fail_closed($suppress_timeout_error);
		return null;
	}

	protected function throw_last_retryable_exception_if_fail_closed(bool $suppress_timeout_error)
	{
		if (!$suppress_timeout_error && !(bool) ($this->config['ffprotect_fail_open'] ?? true) && $this->last_retryable_exception !== null)
		{
			throw $this->last_retryable_exception;
		}
	}

	protected function request_json_with_retry_pass(
		string $method,
		string $path,
		array $payload,
		bool $allow_rebootstrap,
		$timeout_override,
		bool $suppress_timeout_error,
		bool $timeout_retry_attempted
	) {
		$bases = $this->get_ordered_bases_for_requests($path);
		if (!$bases)
		{
			$this->last_request_error = 'Forum Fortress API base URL is not configured.';
			return null;
		}

		$hot_api = $this->get_hot_failover_api_base_url();
		$is_check = strpos($path, '/v1/check') === 0;
		$is_contact_check = \FfApiResilience::shouldUseContactPageRouting($path, $payload);
		$enforce_budget = $is_check && !$is_contact_check;
		$attempt_timeout = $timeout_override;
		if ($enforce_budget)
		{
			$attempt_timeout = min(max(1, $timeout_override ?? (int) ($this->config['ffprotect_timeout'] ?? 3)), \FfApiResilience::RUNTIME_CHECK_ENDPOINT_TIMEOUT_SECONDS);
		}
		if ($is_check)
		{
			$hot_api = '';
		}
		$tried = [];
		$started_at = microtime(true);

		foreach ($bases as $idx => $base_url)
		{
			if ($enforce_budget && (microtime(true) - $started_at) >= \FfApiResilience::RUNTIME_CHECK_TOTAL_BUDGET_SECONDS)
			{
				break;
			}
			$base_url = $this->normalise_base_url($base_url);
			if ($base_url === '' || in_array($base_url, $tried, true))
			{
				continue;
			}
			$tried[] = $base_url;

			$attempt = $this->request_json_on_base(
				$method,
				$path,
				$payload,
				$base_url,
				$allow_rebootstrap && $idx === 0,
				$attempt_timeout,
				$suppress_timeout_error,
				$timeout_retry_attempted
			);
			if (!empty($attempt['ok']))
			{
				/** @var array $out */
				$out = $attempt['data'];
				return $out;
			}
			if (empty($attempt['failover']))
			{
				return null;
			}

			if ($hot_api !== '' && !in_array($hot_api, $tried, true))
			{
				$tried[] = $hot_api;
				$hot_attempt = $this->request_json_on_base(
					$method,
					$path,
					$payload,
					$hot_api,
					false,
					$attempt_timeout,
					$suppress_timeout_error,
					$timeout_retry_attempted
				);
				if (!empty($hot_attempt['ok']))
				{
					/** @var array $out */
					$out = $hot_attempt['data'];
					return $out;
				}
				if (empty($hot_attempt['failover']))
				{
					return null;
				}
			}
		}
		if ($enforce_budget && (microtime(true) - $started_at) >= \FfApiResilience::RUNTIME_CHECK_TOTAL_BUDGET_SECONDS)
		{
			return null;
		}

		if ($is_check && \FfApiResilience::apiRegionIsLocked($this->get_api_region()))
		{
			return null;
		}

		return $this->request_control_check_fallback_after_edges(
			$method,
			$path,
			$payload,
			$tried,
			$attempt_timeout,
			$suppress_timeout_error,
			$timeout_retry_attempted
		);
	}

	/**
	 * @param list<string> $tried
	 */
	protected function request_control_check_fallback_after_edges(
		string $method,
		string $path,
		array $payload,
		array $tried,
		$timeout_override,
		bool $suppress_timeout_error,
		bool $timeout_retry_attempted
	) {
		if (strpos($path, '/v1/check') !== 0)
		{
			return null;
		}
		$state = $this->load_endpoint_state();
		if (empty($state['control_check_fallback']))
		{
			return null;
		}
		$control = $this->normalise_base_url($this->get_control_plane_base_url());
		if ($control === '' || in_array($control, $tried, true))
		{
			return null;
		}
		if (!$this->base_url_may_serve_check_traffic($control))
		{
			return null;
		}
		$attempt = $this->request_json_on_base(
			$method,
			$path,
			$payload,
			$control,
			false,
			$timeout_override,
			$suppress_timeout_error,
			$timeout_retry_attempted
		);
		if (!empty($attempt['ok']))
		{
			/** @var array $out */
			$out = $attempt['data'];

			return $out;
		}

		return null;
	}

	/**
	 * @return array{ok: bool, data?: array, failover: bool}
	 */
	protected function request_json_on_base(
		string $method,
		string $path,
		array $payload,
		string $base_url,
		bool $allow_rebootstrap,
		$timeout_override,
		bool $suppress_timeout_error,
		bool $timeout_retry_attempted = false
	): array {
		$base_url = $this->normalise_base_url($base_url);
		if ($base_url === '')
		{
			return ['ok' => false, 'failover' => true];
		}

		$url = $base_url . $path;
		$timeout = $timeout_override ?? max(1, (int) ($this->config['ffprotect_timeout'] ?? 3));
		$headers = "Accept: application/json\r\nContent-Type: application/json\r\n";
		$api_key = trim((string) ($payload['api_key'] ?? ''));
		if ($api_key === '')
		{
			$api_key = trim((string) ($this->config['ffprotect_api_key'] ?? ''));
		}
		if ($api_key !== '')
		{
			$headers .= 'X-FF-Key: ' . str_replace(["\r", "\n"], '', $api_key) . "\r\n";
		}
		$options = [
			'http' => [
				'method' => $method,
				'timeout' => $timeout,
				'ignore_errors' => true,
				// Never forward X-FF-Key to a Location supplied by a server.
				'follow_location' => 0,
				'header' => $headers,
			],
		];
		if ($method !== 'GET')
		{
			$options['http']['content'] = json_encode($payload);
		}
		else if ($payload)
		{
			$query_payload = $payload;
			unset($query_payload['api_key']);
			if ($query_payload)
			{
				$url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query($query_payload, '', '&', PHP_QUERY_RFC3986);
			}
		}

		$http_response_header = [];
		$raw = @file_get_contents($url, false, stream_context_create($options));
		$status = $this->parse_http_status($http_response_header);
		if ($status === 0 && $raw !== false && $raw !== '')
		{
			$status = 200;
		}

		if ($raw === false)
		{
			$msg = 'Could not reach the API (network, TLS, DNS, or allow_url_fopen disabled).';
			$last = error_get_last();
			if (is_array($last) && !empty($last['message']))
			{
				$msg .= ' ' . $last['message'];
			}
			$is_timeout = $this->is_timeout_error_message($msg);
			if ($is_timeout)
			{
				$this->last_check_had_timeout = true;
			}
			if ($is_timeout && !$timeout_retry_attempted && (strpos($path, '/v1/check') !== 0 || \FfApiResilience::apiRegionIsLocked($this->get_api_region())))
			{
				return $this->request_json_on_base(
					$method,
					$path,
					$payload,
					$base_url,
					$allow_rebootstrap,
					$timeout_override,
					$suppress_timeout_error,
					true
				);
			}
			$this->last_request_error = $msg;
			if ($suppress_timeout_error)
			{
				$this->record_endpoint_failure_and_request_refresh('timeout', $base_url, $path, null, $msg);
				return ['ok' => false, 'failover' => true];
			}
			$this->last_retryable_exception = new \RuntimeException('Forum Fortress request failed: ' . $msg);
			if ($is_timeout)
			{
				$this->record_endpoint_failure_and_request_refresh('timeout', $base_url, $path, null, $msg);
			}
			else
			{
				$this->record_endpoint_failure_and_request_refresh('connection_failure', $base_url, $path, null, $msg);
			}
			return ['ok' => false, 'failover' => true];
		}

		if ($status < 200 || $status >= 300)
		{
			$this->record_http_error($status, (string) $raw);
			$decoded_err = json_decode((string) $raw, true);
			if (
				$this->is_offline_api_key()
				&& \FfApiResilience::isNodeMismatchResponse(is_array($decoded_err) ? $decoded_err : null)
			)
			{
				$previous_key = (string) ($this->config['ffprotect_api_key'] ?? '');
				$previous_site = (string) ($this->config['ffprotect_site_id'] ?? '');
				$previous_state = $this->load_endpoint_state();
				$this->reset_identity();
				$state = $this->load_endpoint_state();
				unset($state['offline_pinned'], $state['issuer_node_id'], $state['offline_preferred_endpoint']);
				$this->save_endpoint_state($state);
				$bootstrap = $this->bootstrap_if_needed();
				if ($bootstrap)
				{
					$retried = $payload;
					if (array_key_exists('api_key', $retried))
					{
						$retried['api_key'] = trim((string) ($this->config['ffprotect_api_key'] ?? ''));
					}
					if (array_key_exists('site_id', $retried))
					{
						$retried['site_id'] = trim((string) ($this->config['ffprotect_site_id'] ?? ''));
					}
					if (array_key_exists('domain', $retried))
					{
						$retried['domain'] = $this->get_bootstrap_domain();
					}
					return $this->request_json_on_base(
						$method,
						$path,
						$retried,
						$base_url,
						false,
						$timeout_override,
						$suppress_timeout_error,
						$timeout_retry_attempted
					);
				}
				$this->config->set('ffprotect_api_key', $previous_key);
				$this->config->set('ffprotect_site_id', $previous_site);
				$this->save_endpoint_state($previous_state);
				return ['ok' => false, 'failover' => false];
			}
			if ($allow_rebootstrap && $this->should_rebootstrap($status, (string) $raw, $path))
			{
				$previous_key = (string) ($this->config['ffprotect_api_key'] ?? '');
				$previous_site = (string) ($this->config['ffprotect_site_id'] ?? '');
				$previous_domain = (string) ($this->config['ffprotect_primary_domain'] ?? '');
				if ($status === 409 && $this->response_error_code((string) $raw) === 'stale_site')
				{
					$this->config->set('ffprotect_site_id', '');
				}
				else
				{
					$this->reset_identity();
				}
				$bootstrap = $this->bootstrap_if_needed();
				if ($bootstrap)
				{
					// Swap *all* identity fields the retry payload carries. The
					// freshly-minted credentials have a new site_id, so leaving
					// stale values here causes the server to return 409 stale_site.
					$retried = $payload;
					if (array_key_exists('api_key', $retried))
					{
						$retried['api_key'] = trim((string) ($this->config['ffprotect_api_key'] ?? ''));
					}
					if (array_key_exists('site_id', $retried))
					{
						$retried['site_id'] = trim((string) ($this->config['ffprotect_site_id'] ?? ''));
					}
					return $this->request_json_on_base(
						$method,
						$path,
						$retried,
						$base_url,
						false,
						$timeout_override,
						$suppress_timeout_error,
						$timeout_retry_attempted
					);
				}
				$this->config->set('ffprotect_api_key', $previous_key);
				$this->config->set('ffprotect_site_id', $previous_site);
				$this->config->set('ffprotect_primary_domain', $previous_domain);
			}

			$this->record_endpoint_failure_and_request_refresh('non_success_status', $base_url, $path, (int) $status);
			$failover = \FfApiResilience::shouldFailoverOnEndpointStatus((int) $status)
				|| \FfApiResilience::shouldFailoverOnIntermittentStatus((int) $status, $path);
			return ['ok' => false, 'failover' => $failover];
		}

		$data = json_decode((string) $raw, true);
		if (!is_array($data))
		{
			$this->last_request_error = 'API response was not valid JSON (HTTP ' . $status . ').';
			$this->record_endpoint_failure_and_request_refresh('invalid_json', $base_url, $path, (int) $status);
			return ['ok' => false, 'failover' => true];
		}

		if (
			$path !== '/v1/site/bootstrap'
			&& $method !== 'GET'
			&& trim((string) ($this->config['ffprotect_api_key'] ?? '')) !== ''
			&& isset($data['detail'])
		)
		{
			$detail = $data['detail'];
			$error = '';
			if (is_array($detail))
			{
				$error = strtolower(trim((string) ($detail['error'] ?? '')));
			}
			else
			{
				$error = strtolower(trim((string) $detail));
			}
			if (in_array($error, ['invalid_key', 'invalid_api_key', 'unknown_site', 'invalid_key_format', 'site_not_found', 'invalid api key', 'site not found'], true))
			{
				$previous_key = (string) ($this->config['ffprotect_api_key'] ?? '');
				$previous_site = (string) ($this->config['ffprotect_site_id'] ?? '');
				$previous_domain = (string) ($this->config['ffprotect_primary_domain'] ?? '');
				$this->config->set('ffprotect_api_key', '');
				$this->config->set('ffprotect_site_id', '');
				$bootstrap = $this->bootstrap_if_needed();
				if (!$bootstrap)
				{
					$this->config->set('ffprotect_api_key', $previous_key);
					$this->config->set('ffprotect_site_id', $previous_site);
					$this->config->set('ffprotect_primary_domain', $previous_domain);
				}
			}
		}

		$this->last_request_error = null;
		$node_header = $this->parse_response_header($http_response_header, 'X-ForumFortress-Node');
		$state = $this->load_endpoint_state();
		$state['last_responded'] = $base_url;
		$state['last_responded_node'] = $node_header;
		$state['last_response_at'] = (int) time();
		$this->save_endpoint_state($state);

		$this->maybe_refresh_endpoint_catalog_after_check_in($path);

		return ['ok' => true, 'data' => $data, 'failover' => false];
	}

	protected function is_timeout_error_message(string $message): bool
	{
		$normalized = strtolower(trim($message));
		if ($normalized === '')
		{
			return false;
		}
		return strpos($normalized, 'timed out') !== false
			|| strpos($normalized, 'timeout') !== false
			|| strpos($normalized, 'operation time') !== false;
	}

	protected function record_http_error(int $status, string $raw)
	{
		$snippet = trim($raw);
		if (strlen($snippet) > 800)
		{
			$snippet = substr($snippet, 0, 800) . '...';
		}
		$decoded = json_decode($raw, true);
		if (is_array($decoded))
		{
			$this->last_request_error = 'HTTP ' . $status . ': ' . json_encode($decoded, JSON_UNESCAPED_SLASHES);
			return;
		}
		$this->last_request_error = 'HTTP ' . $status . ($snippet !== '' ? ': ' . $snippet : ': (empty body)');
	}

	protected function parse_http_status(array $headers): int
	{
		if (!isset($headers[0]) || !preg_match('#HTTP/\S+\s+(\d{3})#', (string) $headers[0], $m))
		{
			return 0;
		}
		return (int) $m[1];
	}

	protected function parse_response_header(array $headers, string $name): string
	{
		$needle = strtolower(trim($name));
		if ($needle === '')
		{
			return '';
		}
		foreach ($headers as $line)
		{
			$text = trim((string) $line);
			if ($text === '' || strpos($text, ':') === false)
			{
				continue;
			}
			list($key, $value) = explode(':', $text, 2);
			if (strtolower(trim($key)) === $needle)
			{
				return trim($value);
			}
		}
		return '';
	}

	protected function should_rebootstrap(int $status, string $body, string $path): bool
	{
		if ($status === 403)
		{
			$data = json_decode($body, true);
			if (\FfApiResilience::isNodeMismatchResponse(is_array($data) ? $data : null))
			{
				return $this->is_offline_api_key();
			}
		}

		if ($path === '/v1/site/bootstrap' || trim((string) ($this->config['ffprotect_api_key'] ?? '')) === '')
		{
			return false;
		}
		$code = $this->response_error_code($body);
		if ($status === 409)
		{
			return $code === 'stale_site';
		}
		if ($status !== 401)
		{
			return false;
		}
		return in_array($code, ['invalid_key', 'invalid_api_key', 'unknown_site', 'invalid_key_format', 'site_not_found', 'invalid api key', 'site not found'], true);
	}

	protected function response_error_code(string $body): string
	{
		$data = json_decode($body, true);
		if (!is_array($data))
		{
			return '';
		}
		if (isset($data['error']))
		{
			return strtolower(trim((string) $data['error']));
		}
		$detail = $data['detail'] ?? null;
		if (is_array($detail) && isset($detail['error']))
		{
			return strtolower(trim((string) $detail['error']));
		}
		return is_string($detail) ? strtolower(trim($detail)) : '';
	}

	protected function reset_identity()
	{
		$this->config->set('ffprotect_api_key', '');
		$this->config->set('ffprotect_site_id', '');
		$this->config->set('ffprotect_primary_domain', '');
	}

	public function persist_identity(array $response, string $used_base = '')
	{
		$was_offline = $this->is_offline_api_key();
		if (!empty($response['api_key']))
		{
			$this->config->set('ffprotect_api_key', (string) $response['api_key']);
		}
		if (!empty($response['site_id']))
		{
			$this->config->set('ffprotect_site_id', (string) $response['site_id']);
		}
		$canonical = (string) ($response['canonical_domain'] ?? $response['primary_domain'] ?? '');
		if ($canonical !== '')
		{
			$this->config->set('ffprotect_primary_domain', self::normalize_domain($canonical));
		}
		$state = $this->load_endpoint_state();
		$key_type = isset($response['key_type']) ? (string) $response['key_type'] : '';
		$api_key = isset($response['api_key']) ? (string) $response['api_key'] : '';
		if (\FfApiResilience::isOfflineBootstrapKey($api_key, $key_type !== '' ? $key_type : null))
		{
			\FfApiResilience::applyOfflineBootstrapRouting($response, $state, $used_base);
		}
		else
		{
			\FfApiResilience::applyOfflineBootstrapRouting($response, $state, $used_base);
		}
		$this->save_endpoint_state($state);
		if ($was_offline && $api_key !== '' && !\FfApiResilience::isOfflineBootstrapKey($api_key, null))
		{
			// migrated to normal ff_* key
		}
	}

	public static function extract_links(string $text): array
	{
		if ($text === '')
		{
			return [];
		}

		preg_match_all('#https?://[^\s<>"\']+#i', $text, $matches);
		$links = [];
		foreach ($matches[0] ?? [] as $url)
		{
			$parsed = parse_url($url);
			if (!is_array($parsed) || empty($parsed['host']))
			{
				continue;
			}
			$scheme = isset($parsed['scheme']) ? strtolower((string) $parsed['scheme']) : 'https';
			$host = strtolower((string) $parsed['host']);
			$path = isset($parsed['path']) ? (string) $parsed['path'] : '';
			$links[] = $scheme . '://' . $host . $path;
		}

		return array_values(array_unique($links));
	}

	public static function filter_external_links(array $links, string $forum_domain): array
	{
		$normalized_forum_domain = self::normalize_domain($forum_domain);
		if ($normalized_forum_domain === '')
		{
			return array_values(array_unique($links));
		}

		$filtered = [];
		foreach ($links as $link)
		{
			$domain = self::extract_domain((string) $link);
			if ($domain !== null && self::is_forum_owned_domain($domain, $normalized_forum_domain))
			{
				continue;
			}
			$filtered[] = (string) $link;
		}

		return array_values(array_unique($filtered));
	}

	public static function extract_domain(string $value)
	{
		$value = trim($value);
		if ($value === '')
		{
			return null;
		}

		if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $value))
		{
			$value = 'https://' . $value;
		}

		$host = parse_url($value, PHP_URL_HOST);
		if (!$host)
		{
			return null;
		}

		$normalized = self::normalize_domain((string) $host);
		return $normalized !== '' ? $normalized : null;
	}

	public static function email_domain($email)
	{
		if (!$email || strpos($email, '@') === false)
		{
			return null;
		}

		$parts = explode('@', $email, 2);
		return strtolower(trim($parts[1]));
	}

	protected static function normalize_domain(string $domain): string
	{
		return \FfApiResilience::normaliseDomain($domain);
	}

	protected static function is_forum_owned_domain(string $candidate, string $forum_domain): bool
	{
		$normalized_candidate = self::normalize_domain($candidate);
		$normalized_forum_domain = self::normalize_domain($forum_domain);
		if ($normalized_candidate === '' || $normalized_forum_domain === '')
		{
			return false;
		}

		return $normalized_candidate === $normalized_forum_domain
			|| substr($normalized_candidate, -strlen('.' . $normalized_forum_domain)) === '.' . $normalized_forum_domain;
	}

	protected function get_moderation_sync_interval_seconds(array $state): int
	{
		if ((int) ($state['moderation_pending_actions'] ?? 0) > 0)
		{
			return 60;
		}

		return self::MODERATION_SYNC_SECONDS;
	}

	protected function get_block_reject_action(): string
	{
		return $this->delete_rejected_users_enabled() ? 'spam_clean' : 'reject';
	}

	protected function refresh_plan_cache_if_stale(bool $force)
	{
		$state = $this->load_endpoint_state();
		$last = (int) ($state['plan_checked_at'] ?? 0);
		if (!$force && $last > 0 && (time() - $last) < self::PLAN_REFRESH_SECONDS)
		{
			return;
		}
		$status = $this->site_status();
		$state['plan_checked_at'] = (int) time();
		if (is_array($status) && !empty($status['plan']))
		{
			$state['plan_name'] = strtolower(trim((string) $status['plan']));
		}
		$this->save_endpoint_state($state);
	}

	protected function record_endpoint_failure_and_request_refresh(
		string $reason,
		string $base_url,
		string $path,
		$status = null,
		string $message = ''
	) {
		$failure = [
			'at' => (int) time(),
			'reason' => $reason,
			'base' => $this->normalise_base_url($base_url),
			'path' => (string) $path,
		];
		if ($status !== null)
		{
			$failure['status'] = $status;
		}
		$message = trim($message);
		if ($message !== '')
		{
			$failure['message'] = substr($message, 0, 240);
		}

		$state = $this->load_endpoint_state();
		$state['last_failure'] = $failure;
		$state['refresh_requested_at'] = $failure['at'];
		$this->save_endpoint_state($state);
	}

	protected function log(string $level, string $message, array $context = [])
	{
		if (!(bool) ($this->config['ffprotect_debug_log'] ?? false) && $level === 'info')
		{
			return;
		}

		$parts = [];
		foreach ($context as $key => $value)
		{
			if (is_array($value))
			{
				$value = json_encode($value);
			}
			$parts[] = $key . '=' . $value;
		}
		$line = '[ForumFortress] ' . $message;
		if ($parts)
		{
			$line .= ' | ' . implode(' ', $parts);
		}
		error_log($line);
	}

	protected function should_run_daily_task(string $key): bool
	{
		$state = $this->load_endpoint_state();
		$last = (int) ($state[$key] ?? 0);
		return $last <= 0 || (time() - $last) >= 86400;
	}

	protected function mark_daily_task_run(string $key)
	{
		$state = $this->load_endpoint_state();
		$state[$key] = (int) time();
		$this->save_endpoint_state($state);
	}
}
