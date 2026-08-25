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
	public const PLATFORM = 'smf';
		public const PLUGIN_VERSION = '1.0.3';
	protected const HOURLY_SYNC_MIN_INTERVAL = 540;
	protected const ENDPOINT_HEALTH_REFRESH_SECONDS = 3600;
	protected const ENDPOINT_HEALTH_DEGRADED_REFRESH_SECONDS = 300;
	protected const ENDPOINT_HEALTH_SLOW_TRIGGER_MS = 100;
	protected const ENDPOINT_HEALTH_RECOVERY_MS = 80;
	/** Edge /v1/check-ready can exceed default forum HTTP timeout while dataset apply runs. */
	protected const ENDPOINT_CHECK_READY_PROBE_SECONDS = 15;
	protected const ENDPOINT_REFRESH_REQUEST_MAX_DELAY_SECONDS = 60;
	protected const CONNECTION_TEST_TIMEOUT_SECONDS = 2;
	protected const CONNECTION_TEST_TOTAL_BUDGET_SECONDS = 5;
	protected const PLAN_REFRESH_SECONDS = 86400;
	protected const MODERATION_SYNC_SECONDS = 600;

	protected static bool $moderation_sync_in_progress = false;
	protected static int $last_moderation_sync_at = 0;

	protected SmfConfig $config;
	protected SmfUser $user;
	protected SmfAuth $auth;
	protected SmfRequest $request;
	protected string $root_path;
	protected string $php_ext;
	protected ModerationBridge $moderation_bridge;
	protected TimeoutQueue $timeout_queue;

	/** @var string|null Last transport/HTTP/parse error for ACP diagnostics */
	protected ?string $last_request_error = null;
	protected ?\Throwable $last_retryable_exception = null;

	protected bool $last_check_had_timeout = false;

	public function __construct(
		SmfConfig $config,
		SmfUser $user,
		SmfAuth $auth,
		SmfRequest $request,
		string $root_path,
		string $php_ext,
		?ModerationBridge $moderation_bridge = null,
		?TimeoutQueue $timeout_queue = null
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

	public function queue_timeout_recovery(string $endpoint, array $payload, array $context = []): void
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

	public function bootstrap_if_needed(): ?array
	{
		if (!$this->is_enabled() || trim((string) ($this->config['ffprotect_api_key'] ?? '')) !== '')
		{
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
		if (!$response && $this->fetch_node_endpoints_catalog(true))
		{
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

	public function check(string $endpoint, array $payload): ?array
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

	public function report(string $endpoint, array $payload): ?array
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

	public function health(?int $timeoutOverride = null): ?array
	{
		if (!$this->is_enabled())
		{
			$this->last_request_error = null;
			return null;
		}

		return $this->request_json('GET', '/health', [], $timeoutOverride ?? self::CONNECTION_TEST_TIMEOUT_SECONDS);
	}

	public function capabilities(?int $timeoutOverride = null): ?array
	{
		if (!$this->is_enabled())
		{
			$this->last_request_error = null;
			return null;
		}

		return $this->request_json_control_plane('GET', '/v1/capabilities', [], $timeoutOverride ?? self::CONNECTION_TEST_TIMEOUT_SECONDS);
	}

	public function site_status(): ?array
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

		return $this->request_json_control_plane('GET', '/v1/site/status', [
			'api_key' => $api_key,
			'domain' => $this->get_domain(),
		]);
	}

	public function forum_stats(): ?array
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

	public function plugin_release(): ?array
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

	public function register_site(string $email): ?array
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

	public function portal_launch(): ?array
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

	/**
	 * Check-in: updates forum row (domain, platform, phpBB + plugin versions, last_seen).
	 */
	public function site_ping(): ?array
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
			if (!defined('USER_FOUNDER'))
			{
				include $this->root_path . 'includes/constants.' . $this->php_ext;
			}

			if ((int) ($this->user->data['user_type'] ?? 0) === USER_FOUNDER || $this->auth->acl_get('a_'))
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

	public function activate_attack_mode(): ?array
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

	public function deactivate_attack_mode(): ?array
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

	protected function assert_attack_mode_response(?array $response, bool $enabled): array
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

	public function hourly_sync(): void
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

		try
		{
			$this->refresh_endpoint_catalog_and_health();
		}
		catch (\Throwable $e)
		{
		}

		try
		{
			$this->site_ping();
		}
		catch (\Throwable $e)
		{
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

	public function run_moderation_sync_cycle(bool $force = false, bool $system_execution = false): void
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

	public function get_last_request_error(): ?string
	{
		return $this->last_request_error;
	}

	public function clear_last_request_error(): void
	{
		$this->last_request_error = null;
	}

	public function get_domain(): string
	{
		$stored = trim((string) ($this->config['ffprotect_primary_domain'] ?? ''));
		if ($stored !== '')
		{
			return self::normalize_domain($stored);
		}

		$server_name = trim((string) ($this->config['server_name'] ?? ''));
		if ($server_name !== '')
		{
			return self::normalize_domain($this->strip_host_port($server_name));
		}

		return self::normalize_domain($this->strip_host_port((string) $this->request->server('HTTP_HOST', '')));
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

	protected function language_hint(string $value): ?string
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

	protected function timezone_name(): ?string
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

	protected function timezone_offset_minutes(): ?int
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
		$configured = $this->normalise_base_url((string) ($this->config['ffprotect_control_base_url'] ?? ''));
		if ($configured !== '')
		{
			return $configured;
		}

		return $this->derive_control_plane_base_from_manual($this->get_manual_base_url());
	}

	protected function get_preferred_base_override(): string
	{
		return $this->normalise_base_url((string) ($this->config['ffprotect_preferred_endpoint'] ?? ''));
	}

	protected function derive_control_plane_base_from_manual(string $manual): string
	{
		$manual = $this->normalise_base_url($manual);
		if ($manual === '')
		{
			return '';
		}
		$host = parse_url($manual, PHP_URL_HOST);
		if (!is_string($host) || $host === '')
		{
			return '';
		}
		$host = strtolower($host);
		if (strpos($host, 'api.') === 0 && strpos($host, '.') !== false)
		{
			return $this->normalise_base_url('https://control.' . substr($host, 4));
		}
		if (strpos($host, 'control.') === 0)
		{
			return $manual;
		}

		return '';
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
		if (\FfApiResilience::apiRegionIsLocked($this->get_api_region()))
		{
			return \FfApiResilience::uniqueOrderedBases(
				\FfApiResilience::regionLockedCheckBases($this->get_api_region(), $this->allow_global_emergency_fallback()),
				[$this->get_control_plane_base_url()]
			);
		}
		return \FfApiResilience::bootstrapBasesOrdered(
			$this->get_control_plane_base_url(),
			$this->get_hot_failover_api_base_url(),
			$this->get_manual_base_url(),
			$this->edge_bases_from_state()
		);
	}

	/** @return list<string> */
	protected function catalog_fetch_bases(): array
	{
		return \FfApiResilience::catalogFetchBases(
			$this->get_control_plane_base_url(),
			$this->get_hot_failover_api_base_url(),
			$this->edge_bases_from_state()
		);
	}

	/** @return list<string> */
	protected function control_plane_request_bases(): array
	{
		return $this->catalog_fetch_bases();
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
	protected function invalidate_endpoint_health_state(array &$state): void
	{
		$state['last_health_at'] = 0;
		$state['health_day'] = '';
	}

	protected function fetch_node_endpoints_catalog(bool $force = false): bool
	{
		$state = $this->load_endpoint_state();
		$previous_endpoints = is_array($state['endpoints'] ?? null) ? $state['endpoints'] : [];
		$now = (int) time();
		$stored_generated_at = (int) ($state['catalog_generated_at'] ?? 0);

		if (!$force && !\FfApiResilience::isEndpointCatalogStale($state))
		{
			return true;
		}
		if (!$force && \FfApiResilience::shouldBackoffEndpointCatalogRefresh($state, $now))
		{
			return false;
		}

		$urls = [];
		$endpoint_meta = [];
		foreach ($this->catalog_fetch_bases() as $catalog_base)
		{
			$res = $this->raw_get_json($catalog_base, '/v1/node-endpoints', self::CONNECTION_TEST_TIMEOUT_SECONDS);
			if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300 || !is_array($res['data'] ?? null))
			{
				continue;
			}
			$data = $res['data'];
			$live_generated_at = (int) ($data['generated_at'] ?? 0);
			if (
				!$force
				&& !empty($state['catalog_fetched_at'])
				&& ($now - (int) $state['catalog_fetched_at']) <= \FfApiResilience::ENDPOINT_CATALOG_TTL_SECONDS
				&& is_array($state['endpoints'] ?? null)
				&& $state['endpoints']
				&& ($live_generated_at <= 0 || $live_generated_at <= $stored_generated_at)
			)
			{
				return true;
			}
			$endpoints = $data['endpoints'] ?? null;
			if (!is_array($endpoints))
			{
				continue;
			}
			$state['control_check_fallback'] = !empty($data['control_check_fallback']);
			$endpoint_meta = [];
			foreach ($endpoints as $row)
			{
				if (!is_array($row))
				{
					continue;
				}
				$url = $this->normalise_base_url((string) ($row['url'] ?? ''));
				if ($url === '')
				{
					continue;
				}
				$urls[$url] = true;
				$endpoint_meta[$url] = [
					'check_ready' => array_key_exists('check_ready', $row) ? (bool) $row['check_ready'] : null,
					'status' => isset($row['status']) ? (string) $row['status'] : '',
					'role' => isset($row['role']) ? (string) $row['role'] : '',
					'traffic_tier' => array_key_exists('traffic_tier', $row)
						? \FfApiResilience::normaliseTrafficTier($row['traffic_tier'])
						: null,
				];
			}
			if ($urls)
			{
				if ($live_generated_at > 0)
				{
					$state['catalog_generated_at'] = $live_generated_at;
				}
				break;
			}
		}
		if (!$urls)
		{
			\FfApiResilience::noteEndpointCatalogRefreshFailure($state, $now);
			$this->save_endpoint_state($state);

			return false;
		}
		$new_endpoints = array_values(array_keys($urls));
		if ($this->endpoint_catalog_changed($previous_endpoints, $new_endpoints))
		{
			$this->invalidate_endpoint_health_state($state);
		}
		$state['catalog_fetched_at'] = $now;
		$state['endpoints'] = $new_endpoints;
		$state['endpoint_meta'] = $endpoint_meta;
		\FfApiResilience::noteEndpointCatalogRefreshSuccess($state);
		$this->save_endpoint_state($state);

		return true;
	}

	public function refresh_endpoint_catalog_if_stale(): void
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

	protected function maybe_refresh_endpoint_catalog_after_check_in(string $request_path): void
	{
		if (!\FfApiResilience::shouldRefreshEndpointCatalogOnCheckIn($request_path))
		{
			return;
		}

		$this->refresh_endpoint_catalog_if_stale();
	}

	protected function is_catalog_backup_role(?string $role): bool
	{
		$role = strtolower(trim((string) $role));

		return in_array($role, ['backup', 'control-fallback', 'control'], true);
	}

	protected function is_catalog_backup_endpoint_url(string $base_url, ?string $role = null): bool
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

	protected function should_probe_endpoint_health(string $base_url, array $state): bool
	{
		$base_url = $this->normalise_base_url($base_url);
		if ($base_url === '')
		{
			return false;
		}
		$endpoint_meta = is_array($state['endpoint_meta'] ?? null) ? $state['endpoint_meta'] : [];
		$meta = isset($endpoint_meta[$base_url]) && is_array($endpoint_meta[$base_url]) ? $endpoint_meta[$base_url] : [];
		$role = isset($meta['role']) ? (string) $meta['role'] : null;
		if ($this->is_catalog_backup_endpoint_url($base_url, $role))
		{
			return !$this->edges_healthy_for_check_traffic($state);
		}
		if (!$this->is_shared_api_round_robin_base($base_url))
		{
			return true;
		}
		return false;
	}

	/** Bootstrap, catalog, capabilities, plugin-release: control, hot api, then edges. */
	protected function request_json_control_plane(string $method, string $path, array $payload): ?array
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
	protected function save_endpoint_state(array $state): void
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
		$preferred_override = $this->get_preferred_base_override();
		$endpoints = is_array($state['endpoints'] ?? null) ? $state['endpoints'] : [];
		$endpoints = $this->normalise_and_sanitise_endpoints($endpoints, $manual);
		$health_ms = is_array($state['health_ms'] ?? null) ? $state['health_ms'] : [];
		$normalised_health = [];
		foreach ($health_ms as $base => $ms)
		{
			$normalized_base = $this->normalise_base_url((string) $base);
			if ($normalized_base === '')
			{
				continue;
			}
			$normalised_health[$normalized_base] = is_int($ms) ? $ms : null;
		}

		return [
			'catalog_fetched_at' => (int) ($state['catalog_fetched_at'] ?? 0),
			'endpoints' => $endpoints,
			'health_day' => (string) ($state['health_day'] ?? ''),
			'health_ms' => $normalised_health,
			'last_health_at' => (int) ($state['last_health_at'] ?? 0),
			'last_responded' => $this->normalise_base_url((string) ($state['last_responded'] ?? '')),
			'last_responded_node' => trim((string) ($state['last_responded_node'] ?? '')),
			'last_response_at' => (int) ($state['last_response_at'] ?? 0),
			'last_site_ping_at' => (int) ($state['last_site_ping_at'] ?? 0),
			'last_failure' => is_array($state['last_failure'] ?? null) ? $state['last_failure'] : null,
			'preferred' => $this->normalise_base_url((string) ($preferred_override !== '' ? $preferred_override : ($state['preferred'] ?? $manual))),
			'preferred_missing' => $this->normalise_base_url((string) ($state['preferred_missing'] ?? '')),
			'preferred_missing_at' => (int) ($state['preferred_missing_at'] ?? 0),
		];
	}

	protected function hydrate_endpoint_state_if_stale(): void
	{
		$state = $this->load_endpoint_state();
		$needs_hydration = false;
		if (!is_array($state['endpoints'] ?? null) || !$state['endpoints'])
		{
			$needs_hydration = true;
		}
		if (!is_array($state['health_ms'] ?? null))
		{
			$needs_hydration = true;
		}
		if ((int) ($state['catalog_fetched_at'] ?? 0) <= 0 || (int) ($state['last_health_at'] ?? 0) <= 0)
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

	protected function edges_healthy_for_check_traffic(array $state): bool
	{
		$health_ms = is_array($state['health_ms'] ?? null) ? $state['health_ms'] : [];
		$endpoint_meta = is_array($state['endpoint_meta'] ?? null) ? $state['endpoint_meta'] : [];
		$is_backup = function (string $base, ?string $role): bool {
			return $this->is_catalog_backup_endpoint_url($base, $role);
		};

		return \FfApiResilience::edgesHealthyForCheckTraffic($health_ms, $endpoint_meta, $is_backup);
	}

	public function endpoint_health_display_label(string $endpoint_url, ?array $state = null): string
	{
		$state = $state ?? $this->load_endpoint_state();
		$endpoint_url = $this->normalise_base_url($endpoint_url);
		if ($endpoint_url === '')
		{
			return 'unreachable';
		}
		$health_ms = is_array($state['health_ms'] ?? null) ? $state['health_ms'] : [];
		$ms = array_key_exists($endpoint_url, $health_ms) ? $health_ms[$endpoint_url] : null;
		if (is_int($ms) && $ms >= 0)
		{
			return $ms . ' ms';
		}
		$endpoint_meta = is_array($state['endpoint_meta'] ?? null) ? $state['endpoint_meta'] : [];
		$meta = isset($endpoint_meta[$endpoint_url]) && is_array($endpoint_meta[$endpoint_url]) ? $endpoint_meta[$endpoint_url] : [];
		$role = isset($meta['role']) ? (string) $meta['role'] : null;
		if (!$this->should_probe_endpoint_health($endpoint_url, $state))
		{
			if ($this->is_shared_api_round_robin_base($endpoint_url))
			{
				return 'shared route';
			}
			if ($this->is_catalog_backup_endpoint_url($endpoint_url, $role))
			{
				if ($this->edges_healthy_for_check_traffic($state))
				{
					if (!empty($meta['check_ready']))
					{
						return 'standby (check-ready backup)';
					}

					return 'standby (not used for checks)';
				}

				return 'standby (backup)';
			}

			return 'not probed';
		}
		if (array_key_exists('check_ready', $meta) && empty($meta['check_ready']))
		{
			$health_only_ms = isset($meta['health_ms']) && is_int($meta['health_ms']) ? $meta['health_ms'] : null;
			if ($health_only_ms === null && strtolower((string) ($meta['status'] ?? '')) === 'healthy')
			{
				return 'reachable (not check-ready)';
			}
			if ($health_only_ms !== null && $health_only_ms >= 0)
			{
				return $health_only_ms . ' ms (not check-ready)';
			}

			return 'not check-ready';
		}

		return 'unreachable';
	}

	/**
	 * @return list<array{endpoint: string, latency: string, is_preferred: bool}>
	 */
	public function build_endpoint_latency_rows(): array
	{
		$this->hydrate_endpoint_state_if_stale();
		if ($this->is_enabled() && $this->get_manual_base_url() !== '')
		{
			try
			{
				$this->fetch_node_endpoints_catalog(false);
				$probe_state = $this->load_endpoint_state();
				$needs_probe = (int) ($probe_state['last_health_at'] ?? 0) === 0;
				if (!$needs_probe)
				{
					$health_ms = is_array($probe_state['health_ms'] ?? null) ? $probe_state['health_ms'] : [];
					foreach (is_array($probe_state['endpoints'] ?? null) ? $probe_state['endpoints'] : [] as $endpoint)
					{
						$base = $this->normalise_base_url((string) $endpoint);
						if ($base === '' || !$this->should_probe_endpoint_health($base, $probe_state))
						{
							continue;
						}
						if (!array_key_exists($base, $health_ms))
						{
							$needs_probe = true;
							break;
						}
					}
				}
				if ($needs_probe)
				{
					$this->refresh_endpoint_catalog_and_health(false);
				}
			}
			catch (\Throwable $e)
			{
			}
		}
		$state = $this->load_endpoint_state();
		$summary = $this->endpoint_state_summary();
		$preferred = $this->normalise_base_url((string) ($summary['preferred'] ?? ''));
		$endpoint_list = is_array($state['endpoints'] ?? null) ? $state['endpoints'] : [];
		$health_ms = is_array($state['health_ms'] ?? null) ? $state['health_ms'] : [];
		$display_targets = array_values(array_unique(array_merge($endpoint_list, array_keys($health_ms))));
		sort($display_targets);
		$rows = [];
		foreach ($display_targets as $endpoint)
		{
			$endpoint_url = (string) $endpoint;
			$rows[] = [
				'endpoint' => $endpoint_url,
				'latency' => $this->endpoint_health_display_label($endpoint_url, $state),
				'is_preferred' => $endpoint_url === $preferred,
			];
		}

		return $rows;
	}

	public function refresh_endpoint_catalog_and_health(bool $force = false): void
	{
		if (!$this->is_enabled())
		{
			return;
		}
		$manual = $this->get_manual_base_url();
		if ($manual === '')
		{
			return;
		}
		$state = $this->load_endpoint_state();
		$now = (int) time();
		$refresh_requested_at = (int) ($state['refresh_requested_at'] ?? 0);
		$last_health_at = (int) ($state['last_health_at'] ?? 0);
		if (
			!$force
			&& $refresh_requested_at > 0
			&& $refresh_requested_at > $last_health_at
			&& ($now - $refresh_requested_at) >= self::ENDPOINT_REFRESH_REQUEST_MAX_DELAY_SECONDS
		)
		{
			$force = true;
		}
		$force_health_refresh = false;
		if ($force)
		{
			$state['catalog_fetched_at'] = 0;
			$this->invalidate_endpoint_health_state($state);
			$state['refresh_requested_at'] = 0;
			$force_health_refresh = true;
		}
		$previous_endpoints = is_array($state['endpoints'] ?? null) ? $state['endpoints'] : [];
		$day_key = gmdate('Y-m-d', $now);

		if (!$this->fetch_node_endpoints_catalog($force))
		{
			if (empty($state['catalog_fetched_at']))
			{
				$state['catalog_fetched_at'] = $now;
				$state['endpoints'] = [$manual];
			}
		}
		else
		{
			$state = $this->load_endpoint_state();
			if ($force_health_refresh)
			{
				$this->invalidate_endpoint_health_state($state);
			}
		}

		$list = $state['endpoints'] ?? null;
		if (!is_array($list) || !$list)
		{
			$state['endpoints'] = [$manual];
		}
		$state['endpoints'] = $this->normalise_and_sanitise_endpoints(
			is_array($state['endpoints']) ? $state['endpoints'] : [],
			$manual
		);
		$preferred_override = $this->get_preferred_base_override();
		if ($preferred_override !== '')
		{
			$with_override = is_array($state['endpoints']) ? $state['endpoints'] : [];
			$with_override[] = $preferred_override;
			$state['endpoints'] = $this->normalise_and_sanitise_endpoints($with_override, $manual);
		}
		if (!$state['endpoints'])
		{
			$state['endpoints'] = [$manual];
		}
		if (
			!$force_health_refresh
			&& $this->endpoint_catalog_changed($previous_endpoints, is_array($state['endpoints']) ? $state['endpoints'] : [])
		)
		{
			$this->invalidate_endpoint_health_state($state);
		}

		$last_health = (int) ($state['last_health_at'] ?? 0);
		$health_day = (string) ($state['health_day'] ?? '');
		$health_refresh_interval = $this->endpoint_health_refresh_interval_seconds($state);

		if ($health_day !== $day_key || ($now - $last_health) > $health_refresh_interval)
		{
			$candidates = is_array($state['endpoints']) ? $state['endpoints'] : [];
			$candidates = array_values(array_unique(array_map(function ($u) {
				return $this->normalise_base_url((string) $u);
			}, $candidates)));
			$candidates = array_filter($candidates, function ($u) {
				return $u !== '';
			});
			$candidates = array_values($candidates);
			if ($manual !== '' && !in_array($manual, $candidates, true) && $this->should_probe_endpoint_health($manual, $state))
			{
				$candidates[] = $manual;
			}
			$endpoint_meta = is_array($state['endpoint_meta'] ?? null) ? $state['endpoint_meta'] : [];
			$probe_health_ms = [];
			foreach (is_array($state['health_ms'] ?? null) ? $state['health_ms'] : [] as $probe_base => $probe_ms)
			{
				$normalised_probe_base = $this->normalise_base_url((string) $probe_base);
				if ($normalised_probe_base === '')
				{
					continue;
				}
				$probe_health_ms[$normalised_probe_base] = is_int($probe_ms) ? $probe_ms : null;
			}
			$is_backup = function (string $base, ?string $role): bool {
				return $this->is_catalog_backup_endpoint_url($base, $role);
			};
			$candidates = \FfApiResilience::sortBasesByHealthyLatency($candidates, $probe_health_ms, $endpoint_meta, $is_backup);
			$latencies = [];
			$started_at = microtime(true);
			foreach ($candidates as $base)
			{
				if ((microtime(true) - $started_at) >= self::CONNECTION_TEST_TOTAL_BUDGET_SECONDS)
				{
					$state['health_timed_out'] = true;
					break;
				}
				if (!$this->should_probe_endpoint_health($base, $state))
				{
					continue;
				}
				$t0 = microtime(true);
				$hr = $this->raw_get_json($base, '/health', \FfApiResilience::RUNTIME_CHECK_ENDPOINT_TIMEOUT_SECONDS);
				$ms = null;
				if (($hr['status'] ?? 0) >= 200 && ($hr['status'] ?? 0) < 300)
				{
					$health_ms = (int) round((microtime(true) - $t0) * 1000);
					$cr = $this->raw_get_json($base, '/v1/check-ready', \FfApiResilience::RUNTIME_CHECK_ENDPOINT_TIMEOUT_SECONDS);
					$live_ready = ($cr['status'] ?? 0) >= 200 && ($cr['status'] ?? 0) < 300;
					if (!isset($endpoint_meta[$base]) || !is_array($endpoint_meta[$base]))
					{
						$endpoint_meta[$base] = [];
					}
					$endpoint_meta[$base]['check_ready'] = $live_ready;
					$endpoint_meta[$base]['status'] = 'healthy';
					$endpoint_meta[$base]['health_ms'] = $health_ms;
					$ms = $live_ready ? $health_ms : null;
				}
				$latencies[$base] = $ms;
			}
			$state['endpoint_meta'] = $endpoint_meta;
			$best = \FfApiResilience::resolvePreferredHealthyBase(
				array_keys($latencies),
				$latencies,
				$endpoint_meta,
				$is_backup,
				$manual
			);
			$current_preferred = $this->normalise_base_url((string) ($state['preferred'] ?? $manual));
			$current_preferred_healthy = \FfApiResilience::isHealthyLatency($latencies[$current_preferred] ?? null);
			if ($current_preferred !== '' && $best !== '' && $best !== $current_preferred && $current_preferred_healthy)
			{
				$candidate = $this->normalise_base_url((string) ($state['preferred_candidate'] ?? ''));
				$streak = $candidate === $best ? ((int) ($state['preferred_candidate_streak'] ?? 0) + 1) : 1;
				$state['preferred_candidate'] = $best;
				$state['preferred_candidate_streak'] = $streak;
				if ($streak < 2)
				{
					$best = $current_preferred;
				}
			}
			else
			{
				unset($state['preferred_candidate'], $state['preferred_candidate_streak']);
			}
			$best_ms = \FfApiResilience::isHealthyLatency($latencies[$best] ?? null)
				? (int) $latencies[$best]
				: 999999;
			$has_healthy = false;
			foreach ($latencies as $ms)
			{
				if (\FfApiResilience::isHealthyLatency($ms))
				{
					$has_healthy = true;
					break;
				}
			}
			$state['last_health_at'] = $now;
			$state['health_day'] = $day_key;
			$state['health_ms'] = $latencies;
			$state['preferred'] = $best;
			$was_slow = !empty($state['slow_health_mode']);
			$is_slow = !$has_healthy
				|| $best_ms > self::ENDPOINT_HEALTH_SLOW_TRIGGER_MS
				|| ($was_slow && $best_ms > self::ENDPOINT_HEALTH_RECOVERY_MS);
			$state['slow_health_mode'] = $is_slow;
			$state['best_latency_ms'] = $has_healthy ? (int) $best_ms : 0;
			$state['refresh_requested_at'] = 0;
		}
		if ($preferred_override !== '')
		{
			$state['preferred'] = $preferred_override;
		}

		$this->save_endpoint_state($state);
	}

	public function refresh_endpoints_before_connection_test(): void
	{
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
	 * Routing: catalog from control; health probes edges only; checks on check_ready edges;
	 * control for checks only when control_check_fallback or no healthy edge. api.ffapi.net is
	 * legacy shared DNS (edges proxy health/catalog); do not treat control as down when two edges
	 * are healthy (see edges_healthy_for_check_traffic / should_probe_endpoint_health).
	 */
	protected function base_url_may_serve_check_traffic(string $base_url): bool
	{
		$base_url = $this->normalise_base_url($base_url);
		if ($base_url === '')
		{
			return false;
		}
		$control = $this->get_control_plane_base_url();
		if ($control !== '' && $base_url === $control)
		{
			$state = $this->load_endpoint_state();
			return !empty($state['control_check_fallback']) || !$this->edges_healthy_for_check_traffic($state);
		}
		$manual = $this->get_manual_base_url();
		if ($manual !== '' && $base_url === $manual)
		{
			$host = parse_url($manual, PHP_URL_HOST);
			if (is_string($host) && strpos(strtolower($host), 'api.') === 0)
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
	protected function get_ordered_bases_for_requests(?string $request_path = null): array
	{
		$manual = $this->get_manual_base_url();
		if ($manual === '')
		{
			return [];
		}
		$state = $this->load_endpoint_state();
		if (is_string($request_path) && strpos($request_path, '/v1/check') === 0 && \FfApiResilience::apiRegionIsLocked($this->get_api_region()) && !$this->is_offline_api_key())
		{
			return \FfApiResilience::regionLockedCheckBases($this->get_api_region(), $this->allow_global_emergency_fallback());
		}
		$is_check_path = is_string($request_path) && strpos($request_path, '/v1/check') === 0;
		if (
			is_string($request_path)
			&& strpos($request_path, '/v1/check') === 0
			&& $this->is_offline_api_key()
		)
		{
			$pinned = \FfApiResilience::offlinePinnedCheckBases($state);
			if ($pinned)
			{
				return $pinned;
			}
		}
		if (is_string($request_path) && \FfApiResilience::isStrictSupernodeSyncPath($request_path))
		{
			$bases = \FfApiResilience::moderationSyncBasesOrdered(
				$this->get_hot_failover_api_base_url(),
				$this->get_control_plane_base_url()
			);
			if ($bases !== [])
			{
				return $bases;
			}
		}
		$refresh_requested_at = (int) ($state['refresh_requested_at'] ?? 0);
		if (!$is_check_path && $refresh_requested_at > 0 && (time() - $refresh_requested_at) >= self::ENDPOINT_REFRESH_REQUEST_MAX_DELAY_SECONDS)
		{
			try
			{
				$this->refresh_endpoint_catalog_and_health(true);
				$state = $this->load_endpoint_state();
			}
			catch (\Throwable $e)
			{
			}
		}
		$endpoints = $state['endpoints'] ?? null;
		if (!is_array($endpoints) || !$endpoints)
		{
			$endpoints = [$manual];
		}
		$endpoints = array_values(array_unique(array_map(function ($u) {
			return $this->normalise_base_url((string) $u);
		}, $endpoints)));
		$endpoints = array_filter($endpoints, function ($u) {
			return $u !== '';
		});
		$endpoints = array_values($endpoints);
		$endpoint_meta = is_array($state['endpoint_meta'] ?? null) ? $state['endpoint_meta'] : [];
		$health_ms = is_array($state['health_ms'] ?? null) ? $state['health_ms'] : [];
		$latency_by_base = [];
		foreach ($health_ms as $base => $ms)
		{
			$normalised_base = $this->normalise_base_url((string) $base);
			if ($normalised_base === '')
			{
				continue;
			}
			$latency_by_base[$normalised_base] = is_int($ms) ? $ms : null;
		}
		$is_backup = function (string $base, ?string $role): bool {
			return $this->is_catalog_backup_endpoint_url($base, $role);
		};
		$sorted = \FfApiResilience::sortBasesByHealthyLatency($endpoints, $latency_by_base, $endpoint_meta, $is_backup);
		$preferred_override = $this->get_preferred_base_override();
		$routing_fallback = in_array($manual, $endpoints, true) ? $manual : ($sorted[0] ?? $manual);
		$computed_preferred = \FfApiResilience::resolvePreferredHealthyBase(
			$sorted,
			$latency_by_base,
			$endpoint_meta,
			$is_backup,
			$routing_fallback
		);
		$preferred = $preferred_override !== '' ? $preferred_override : $computed_preferred;
		$preferred_candidate = $preferred;
		if ($preferred === '' || !in_array($preferred, $endpoints, true))
		{
			$state['preferred_missing'] = $preferred_candidate;
			$state['preferred_missing_at'] = (int) time();
			$state['refresh_requested_at'] = (int) time();
			$preferred = $sorted[0] ?? $manual;
			if ($preferred_override === '')
			{
				$state['preferred'] = $preferred;
			}
			$this->save_endpoint_state($state);
		}
		else if (isset($state['preferred_missing']))
		{
			unset($state['preferred_missing']);
			unset($state['preferred_missing_at']);
			$this->save_endpoint_state($state);
		}

		$out = \FfApiResilience::uniqueOrderedBases(
			$sorted,
			$preferred !== '' ? [$preferred] : []
		);
		if (!in_array($manual, $out, true))
		{
			$out[] = $manual;
		}
		if ($is_check_path)
		{
			$out = array_values(array_filter($out, function ($b) use ($endpoint_meta, $latency_by_base) {
				$base = (string) $b;
				if (!$this->base_url_may_serve_check_traffic($base))
				{
					return false;
				}
				$ms = array_key_exists($base, $latency_by_base) && is_int($latency_by_base[$base])
					? $latency_by_base[$base]
					: null;

				return \FfApiResilience::endpointEligibleForCheckTraffic($endpoint_meta, $base, $ms);
			}));
			$control = $this->normalise_base_url($this->get_control_plane_base_url());
			if ($control !== '' && $this->base_url_may_serve_check_traffic($control) && !in_array($control, $out, true))
			{
				$out[] = $control;
			}
			$out = \FfApiResilience::orderCheckBasesControlLast($out, $control);
			$hot_api = $this->get_hot_failover_api_base_url();
			$out = \FfApiResilience::uniqueOrderedBases(
				array_values(array_filter($out, function ($base) use ($control) { return $base !== $control; })),
				$hot_api !== '' ? [$hot_api] : [],
				$control !== '' && $this->base_url_may_serve_check_traffic($control) ? [$control] : []
			);
			$unsuppressed = array_values(array_filter($out, function ($base) use ($state) {
				return !$this->is_endpoint_suppressed((string) $base, $state);
			}));
			$out = $unsuppressed ?: $out;
		}
		return $out;
	}

	protected function is_endpoint_suppressed(string $base_url, ?array $state = null): bool
	{
		$base_url = $this->normalise_base_url($base_url);
		if ($base_url === '')
		{
			return false;
		}
		$state = $state ?? $this->load_endpoint_state();
		$suppressed = is_array($state['suppressed_endpoints'] ?? null) ? $state['suppressed_endpoints'] : [];
		return (int) ($suppressed[$base_url] ?? 0) > time();
	}

	protected function mark_preferred_base_after_failover(string $base_url): void
	{
		$base_url = $this->normalise_base_url($base_url);
		if ($base_url === '')
		{
			return;
		}
		if ($this->get_preferred_base_override() !== '')
		{
			return;
		}
		$state = $this->load_endpoint_state();
		$suppressed = is_array($state['suppressed_endpoints'] ?? null) ? $state['suppressed_endpoints'] : [];
		unset($suppressed[$base_url]);
		$state['suppressed_endpoints'] = $suppressed;
		$state['preferred'] = $base_url;
		unset($state['preferred_candidate'], $state['preferred_candidate_streak']);
		$state['failover_at'] = (int) time();
		$state['refresh_requested_at'] = 0;
		$this->save_endpoint_state($state);
		$this->log('info', 'Forum Fortress API switched to a responsive endpoint', [
			'base' => $base_url,
		]);
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

	public function request_json(string $method, string $path, array $payload): ?array
	{
		return $this->request_json_with_retry($method, $path, $payload, true, null, false);
	}

	public function request_json_with_retry(
		string $method,
		string $path,
		array $payload,
		bool $allow_rebootstrap,
		?int $timeout_override,
		bool $suppress_timeout_error,
		bool $timeout_retry_attempted = false
	): ?array {
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
		if (strpos($path, '/v1/check') === 0)
		{
			$this->throw_last_retryable_exception_if_fail_closed($suppress_timeout_error);
			return null;
		}
		try
		{
			$this->refresh_endpoint_catalog_and_health(true);
		}
		catch (\Throwable $e)
		{
		}

		$result = $this->request_json_with_retry_pass(
			$method,
			$path,
			$payload,
			false,
			$timeout_override,
			$suppress_timeout_error,
			$timeout_retry_attempted
		);
		if ($result === null)
		{
			$this->throw_last_retryable_exception_if_fail_closed($suppress_timeout_error);
		}
		return $result;
	}

	protected function throw_last_retryable_exception_if_fail_closed(bool $suppress_timeout_error): void
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
		?int $timeout_override,
		bool $suppress_timeout_error,
		bool $timeout_retry_attempted
	): ?array {
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
				if ($is_check || $idx > 0)
				{
					$this->mark_preferred_base_after_failover($base_url);
				}
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
					$this->mark_preferred_base_after_failover($hot_api);
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
		?int $timeout_override,
		bool $suppress_timeout_error,
		bool $timeout_retry_attempted
	): ?array {
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
		?int $timeout_override,
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
				return ['ok' => false, 'failover' => false];
			}
			if ($status === 401 && $allow_rebootstrap && $this->should_rebootstrap($status, (string) $raw, $path))
			{
				$this->reset_identity();
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
			if (in_array($error, ['invalid_key', 'unknown_site', 'invalid api key', 'site not found'], true))
			{
				$this->config->set('ffprotect_api_key', '');
				$this->config->set('ffprotect_site_id', '');
				$this->bootstrap_if_needed();
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

	protected function record_http_error(int $status, string $raw): void
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
			[$key, $value] = explode(':', $text, 2);
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

		if ($status !== 401)
		{
			return false;
		}
		if ($path === '/v1/site/bootstrap' || trim((string) ($this->config['ffprotect_api_key'] ?? '')) === '')
		{
			return false;
		}
		$data = json_decode($body, true);
		if (!is_array($data))
		{
			return false;
		}
		// Backend can emit either a flat body ({"error": "...", "message": "..."},
		// produced by our HTTPException handler) or the FastAPI default
		// ({"detail": {...}} or {"detail": "..."}). Match both shapes.
		$candidates = [];
		if (isset($data['error']))
		{
			$candidates[] = strtolower(trim((string) $data['error']));
		}
		$detail = $data['detail'] ?? null;
		if (is_array($detail) && isset($detail['error']))
		{
			$candidates[] = strtolower(trim((string) $detail['error']));
		}
		foreach ($candidates as $candidate)
		{
			if (in_array($candidate, ['invalid_key', 'unknown_site', 'invalid_key_format'], true))
			{
				return true;
			}
		}
		$detail_text = is_string($detail) ? strtolower(trim($detail)) : '';
		return in_array($detail_text, ['invalid api key', 'site not found'], true);
	}

	protected function reset_identity(): void
	{
		$this->config->set('ffprotect_api_key', '');
		$this->config->set('ffprotect_site_id', '');
		$this->config->set('ffprotect_primary_domain', '');
	}

	public function persist_identity(array $response, string $used_base = ''): void
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

	public static function extract_domain(string $value): ?string
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

	public static function email_domain(?string $email): ?string
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

	protected function endpoint_health_refresh_interval_seconds(array $state): int
	{
		$best_latency = (int) ($state['best_latency_ms'] ?? 0);
		$slow_mode = !empty($state['slow_health_mode']);
		if ($slow_mode)
		{
			if ($best_latency > 0 && $best_latency <= self::ENDPOINT_HEALTH_RECOVERY_MS)
			{
				return self::ENDPOINT_HEALTH_REFRESH_SECONDS;
			}
			return self::ENDPOINT_HEALTH_DEGRADED_REFRESH_SECONDS;
		}
		if ($best_latency > self::ENDPOINT_HEALTH_SLOW_TRIGGER_MS)
		{
			return self::ENDPOINT_HEALTH_DEGRADED_REFRESH_SECONDS;
		}
		return self::ENDPOINT_HEALTH_REFRESH_SECONDS;
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

	protected function refresh_plan_cache_if_stale(bool $force): void
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
		?int $status = null,
		string $message = ''
	): void {
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
		$suppressed = is_array($state['suppressed_endpoints'] ?? null) ? $state['suppressed_endpoints'] : [];
		$retryable = $status === null || \FfApiResilience::shouldFailoverOnEndpointStatus((int) $status);
		if ($retryable && $failure['base'] !== '')
		{
			$suppressed[$failure['base']] = $failure['at'] + \FfApiResilience::ENDPOINT_SUPPRESSION_SECONDS;
		}
		foreach ($suppressed as $base => $until)
		{
			if ((int) $until <= $failure['at'])
			{
				unset($suppressed[$base]);
			}
		}
		$state['suppressed_endpoints'] = $suppressed;
		$this->save_endpoint_state($state);
	}

	protected function log(string $level, string $message, array $context = []): void
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

	protected function mark_daily_task_run(string $key): void
	{
		$state = $this->load_endpoint_state();
		$state[$key] = (int) time();
		$this->save_endpoint_state($state);
	}
}
