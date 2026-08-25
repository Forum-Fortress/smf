<?php

namespace ForumFortress\Smf;

use function hash;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function strlen;
use function substr;
use function time;
use function trim;

class TimeoutQueue
{
	public const TIMEOUT_TAG = 'FFTimeout';
	public const SOURCE = 'ff_timeout';
	public const PENDING_TTL_SECONDS = 604800;
	public const MAX_PENDING_ENTRIES = 100;
	public const MAX_PENDING_BYTES = 262144;
	public const MESSAGE_META_TTL_SECONDS = 604800;
	public const MAX_MESSAGE_META_ENTRIES = 200;
	public const MAX_MESSAGE_META_BYTES = 524288;
	public const MAX_STRING_LENGTH = 20000;

	protected ApiClient $client;

	public function __construct(ApiClient $client)
	{
		$this->client = $client;
	}

	public function enqueue(string $endpoint, array $check_payload, array $context = []): void
	{
		$pending = $this->load_pending();
		$check_payload = $this->sanitise_check_payload($check_payload);
		$remote_type = (string) ($context['remote_content_type'] ?? 'pending_check');
		$remote_id = (string) ($context['remote_content_id'] ?? '');
		if ($remote_id === '')
		{
			$seed = json_encode([$endpoint, $check_payload['email'] ?? '', $check_payload['username'] ?? '', microtime(true)]);
			$remote_id = substr(hash('sha256', (string) $seed), 0, 24);
		}
		$now = time();
		$key = $remote_type . ':' . $remote_id;
		$pending[$remote_type . ':' . $remote_id] = [
			'remote_content_type' => $remote_type,
			'remote_content_id' => $remote_id,
			'endpoint' => $endpoint,
			'check_payload' => $check_payload,
			'username' => $this->clip((string) ($context['username'] ?? $check_payload['username'] ?? ''), 255),
			'title' => $this->clip((string) ($context['title'] ?? 'Timed-out protection check'), 200),
			'excerpt' => $this->clip((string) ($context['excerpt'] ?? ''), 280),
			'queued_at' => $now,
			'expires_at' => $now + self::PENDING_TTL_SECONDS,
		];
		if (!isset($pending[$key]['queued_at']))
		{
			$pending[$key]['queued_at'] = $now;
		}
		$this->save_pending($pending);
	}

	public function sync_items(): array
	{
		$items = [];
		foreach ($this->load_pending() as $entry)
		{
			if (!is_array($entry))
			{
				continue;
			}
			$check_payload = $entry['check_payload'] ?? [];
			if (!is_array($check_payload))
			{
				continue;
			}
			$check_payload = $this->sanitise_check_payload($check_payload);
			$items[] = [
				'remote_content_type' => (string) ($entry['remote_content_type'] ?? 'pending_check'),
				'remote_content_id' => (string) ($entry['remote_content_id'] ?? ''),
				'title' => (string) ($entry['title'] ?? 'FFTimeout'),
				'excerpt' => (string) ($entry['excerpt'] ?? ''),
				'username' => (string) ($entry['username'] ?? ''),
				'available_actions' => ['approve', 'reject'],
				'payload' => [
					'source' => self::SOURCE,
					'forumfortress_challenge_tag' => self::TIMEOUT_TAG,
					'ff_timeout_expires_at' => (int) ($entry['expires_at'] ?? 0),
					'endpoint' => (string) ($entry['endpoint'] ?? 'register'),
					'check_payload' => $check_payload,
					'fortress_public_reason' => 'Forum Fortress check timed out; queued for automatic recovery on next sync (FFTimeout).',
				],
			];
		}
		return $items;
	}

	public function find_entry(string $remote_type, string $remote_id): ?array
	{
		$entry = $this->load_pending()[$remote_type . ':' . $remote_id] ?? null;
		return is_array($entry) ? $entry : null;
	}

	public function retire(string $remote_type, string $remote_id): void
	{
		$pending = $this->load_pending();
		$key = $remote_type . ':' . $remote_id;
		$entry = $pending[$key] ?? null;
		unset($pending[$key]);
		if (is_array($entry))
		{
			$entry_type = (string) ($entry['remote_content_type'] ?? '');
			$entry_id = (string) ($entry['remote_content_id'] ?? '');
			unset($pending[$entry_type . ':' . $entry_id]);
		}
		$this->save_pending($pending);

		if (in_array($remote_type, ['thread', 'post'], true) && ctype_digit($remote_id))
		{
			$this->retire_message_meta((int) $remote_id);
		}
	}

	public function message_meta(int $msg_id): ?array
	{
		if ($msg_id <= 0)
		{
			return null;
		}
		$all = $this->load_message_meta();
		$meta = $all[(string) $msg_id] ?? null;
		return is_array($meta) ? $meta : null;
	}

	public function attach_message_meta(int $msg_id, string $endpoint, array $check_payload): void
	{
		if ($msg_id <= 0)
		{
			return;
		}
		$check_payload = $this->sanitise_check_payload($check_payload);
		$pending = $this->load_pending();
		$match_key = null;
		$match_time = 0;
		$payload_hash = $this->payload_hash($check_payload);
		foreach ($pending as $key => $entry)
		{
			if (!is_array($entry) || (string) ($entry['endpoint'] ?? '') !== $endpoint)
			{
				continue;
			}
			$entry_payload = is_array($entry['check_payload'] ?? null) ? $entry['check_payload'] : [];
			if ($this->payload_hash($entry_payload) !== $payload_hash)
			{
				continue;
			}
			$queued_at = (int) ($entry['queued_at'] ?? 0);
			if ($queued_at >= $match_time)
			{
				$match_key = $key;
				$match_time = $queued_at;
			}
		}
		if ($match_key !== null)
		{
			$entry = $pending[$match_key];
			unset($pending[$match_key]);
			$entry['remote_content_type'] = in_array(strtolower($endpoint), ['topic', 'topic_edit'], true) ? 'thread' : 'post';
			$entry['remote_content_id'] = (string) $msg_id;
			$entry['last_seen_at'] = time();
			$pending[$entry['remote_content_type'] . ':' . $msg_id] = $entry;
			$this->save_pending($pending);
		}

		$all = $this->load_message_meta();
		$all[(string) $msg_id] = [
			'source' => self::SOURCE,
			'forumfortress_challenge_tag' => self::TIMEOUT_TAG,
			'endpoint' => $endpoint,
			'check_payload' => $check_payload,
			'fortress_public_reason' => 'Forum Fortress check timed out; queued for automatic recovery on next sync (FFTimeout).',
			'attached_at' => time(),
		];
		$this->save_message_meta($all);
	}

	protected function load_pending(): array
	{
		global $modSettings;
		$raw = (string) ($modSettings['ffprotect_timeout_pending'] ?? '');
		if ($raw === '')
		{
			return [];
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded))
		{
			return [];
		}
		$pruned = $this->prune_pending($decoded);
		if ($pruned !== $decoded)
		{
			$this->save_pending($pruned);
		}
		return $pruned;
	}

	protected function save_pending(array $pending): void
	{
		$pending = $this->prune_pending($pending);
		$json = $this->encode_json($pending);
		updateSettings(['ffprotect_timeout_pending' => $json]);
		global $modSettings;
		$modSettings['ffprotect_timeout_pending'] = $json;
	}

	protected function load_message_meta(): array
	{
		global $modSettings;
		$raw = (string) ($modSettings['ffprotect_timeout_post_meta'] ?? '');
		if ($raw === '')
		{
			return [];
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded))
		{
			return [];
		}
		$pruned = $this->prune_message_meta($decoded);
		if ($pruned !== $decoded)
		{
			$this->save_message_meta($pruned);
		}
		return $pruned;
	}

	protected function save_message_meta(array $meta): void
	{
		$json = $this->encode_json($this->prune_message_meta($meta));
		updateSettings(['ffprotect_timeout_post_meta' => $json]);
		global $modSettings;
		$modSettings['ffprotect_timeout_post_meta'] = $json;
	}

	protected function retire_message_meta(int $msg_id): void
	{
		$meta = $this->load_message_meta();
		if (array_key_exists((string) $msg_id, $meta))
		{
			unset($meta[(string) $msg_id]);
			$this->save_message_meta($meta);
		}
	}

	protected function prune_pending(array $pending): array
	{
		$now = time();
		$valid = [];
		foreach ($pending as $key => $entry)
		{
			if (!is_array($entry))
			{
				continue;
			}
			$queued_at = (int) ($entry['queued_at'] ?? 0);
			$expires_at = (int) ($entry['expires_at'] ?? ($queued_at + self::PENDING_TTL_SECONDS));
			if ($queued_at < 1 || $expires_at <= $now)
			{
				continue;
			}
			$entry['check_payload'] = $this->sanitise_check_payload((array) ($entry['check_payload'] ?? []));
			$entry['excerpt'] = $this->clip((string) ($entry['excerpt'] ?? ''), 280);
			$entry['expires_at'] = $expires_at;
			$valid[(string) $key] = $entry;
		}
		uasort($valid, static function (array $left, array $right): int {
			return ((int) ($left['queued_at'] ?? 0)) <=> ((int) ($right['queued_at'] ?? 0));
		});
		if (count($valid) > self::MAX_PENDING_ENTRIES)
		{
			$valid = array_slice($valid, -self::MAX_PENDING_ENTRIES, null, true);
		}
		while ($valid && strlen($this->encode_json($valid)) > self::MAX_PENDING_BYTES)
		{
			array_shift($valid);
		}
		return $valid;
	}

	protected function prune_message_meta(array $meta): array
	{
		$now = time();
		$valid = [];
		foreach ($meta as $key => $entry)
		{
			if (!is_array($entry))
			{
				continue;
			}
			$attached_at = (int) ($entry['attached_at'] ?? $now);
			if ($attached_at < $now - self::MESSAGE_META_TTL_SECONDS)
			{
				continue;
			}
			$entry['check_payload'] = $this->sanitise_check_payload((array) ($entry['check_payload'] ?? []));
			$entry['attached_at'] = $attached_at;
			$valid[(string) $key] = $entry;
		}
		if (count($valid) > self::MAX_MESSAGE_META_ENTRIES)
		{
			uasort($valid, static function (array $left, array $right): int {
				return ((int) ($left['attached_at'] ?? 0)) <=> ((int) ($right['attached_at'] ?? 0));
			});
			$valid = array_slice($valid, -self::MAX_MESSAGE_META_ENTRIES, null, true);
		}
		while ($valid && strlen($this->encode_json($valid)) > self::MAX_MESSAGE_META_BYTES)
		{
			array_shift($valid);
		}
		return $valid;
	}

	protected function sanitise_check_payload(array $payload): array
	{
		$allowed = [
			'ip', 'username', 'email', 'email_domain', 'user_agent', 'links', 'content',
			'post_count', 'account_age_seconds', 'platform', 'platform_version', 'plugin_version',
			'domain', 'forum_id', 'profile_fields', 'signature_text', 'content_id', 'thread_id',
			'previous_content_summary', 'check_request_id', 'check_endpoint',
		];
		$result = [];
		foreach ($allowed as $key)
		{
			if (!array_key_exists($key, $payload))
			{
				continue;
			}
			$value = $payload[$key];
			if ($key === 'links' && is_array($value))
			{
				$result[$key] = [];
				foreach (array_slice($value, 0, 50) as $link)
				{
					if (is_string($link) && trim($link) !== '')
					{
						$result[$key][] = $this->clip($link, 2048);
					}
				}
				continue;
			}
			if ($key === 'profile_fields' && is_array($value))
			{
				$result[$key] = [];
				foreach (array_slice($value, 0, 20, true) as $field => $field_value)
				{
					if (is_scalar($field_value))
					{
						$result[$key][$this->clip((string) $field, 100)] = $this->clip((string) $field_value, 1000);
					}
				}
				continue;
			}
			if (is_scalar($value))
			{
				$result[$key] = is_bool($value) || is_int($value) || is_float($value)
					? $value
					: $this->clip((string) $value, self::MAX_STRING_LENGTH);
			}
		}
		return $result;
	}

	protected function payload_hash(array $payload): string
	{
		return hash('sha256', $this->encode_json($this->sanitise_check_payload($payload)));
	}

	protected function clip(string $value, int $length): string
	{
		return substr(trim($value), 0, $length);
	}

	protected function encode_json(array $value): string
	{
		$json = json_encode($value);
		return is_string($json) ? $json : '{}';
	}
}
