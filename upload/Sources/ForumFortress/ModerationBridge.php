<?php

namespace ForumFortress\Smf;

use function in_array;
use function is_array;
use function ctype_digit;
use function preg_replace;
use function strip_tags;
use function substr;
use function time;
use function trim;
use function strtolower;

class ModerationBridge
{
	protected ApiClient $client;
	protected bool $system_execution = false;

	public function __construct(ApiClient $client)
	{
		$this->client = $client;
	}

	public function collect_queue_items(): array
	{
		global $smcFunc, $scripturl;

		$output = [];
		$keys = [];
		$timeout_items = $this->client->get_timeout_queue()->sync_items();
		foreach ($timeout_items as $item)
		{
			if (is_array($item) && $this->add_queue_item($output, $keys, $item))
			{
				continue;
			}
		}

		$request = $smcFunc['db_query']('', '
			SELECT m.id_msg, m.id_topic, m.id_board, m.poster_time, m.body, m.subject,
				m.id_member, mem.member_name, mem.email_address,
				t.id_first_msg, t.id_last_msg
			FROM {db_prefix}messages AS m
			INNER JOIN {db_prefix}topics AS t ON (t.id_topic = m.id_topic)
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = m.id_member)
			WHERE m.approved = {int:not_approved}
			ORDER BY m.poster_time ASC
			LIMIT 200',
			['not_approved' => 0]
		);
		while ($row = $smcFunc['db_fetch_assoc']($request))
		{
			$mapped = $this->map_message_row($row, $scripturl);
			if ($mapped)
			{
				$this->add_queue_item($output, $keys, $mapped);
			}
		}
		$smcFunc['db_free_result']($request);

		$request = $smcFunc['db_query']('', '
			SELECT id_member, member_name, email_address, date_registered
			FROM {db_prefix}members
			WHERE is_activated = {int:awaiting}
			ORDER BY date_registered ASC
			LIMIT 50',
			['awaiting' => 3]
		);
		while ($row = $smcFunc['db_fetch_assoc']($request))
		{
			$this->add_queue_item($output, $keys, [
				'remote_content_type' => 'user',
				'remote_content_id' => (string) (int) $row['id_member'],
				'title' => 'User registration',
				'excerpt' => trim((string) ($row['email_address'] ?? '')),
				'username' => trim((string) ($row['member_name'] ?? '')),
				'remote_user_id' => (string) (int) $row['id_member'],
				'content_date' => (int) ($row['date_registered'] ?? 0),
				'content_url' => null,
				'available_actions' => ['approve', 'reject'],
				'payload' => ['content_type' => 'user'],
			]);
		}
		$smcFunc['db_free_result']($request);

		return $output;
	}

	public function execute_actions(array $actions): array
	{
		global $sourcedir;
		require_once $sourcedir . '/Subs-Post.php';
		require_once $sourcedir . '/Subs-Members.php';
		require_once $sourcedir . '/RemoveTopic.php';

		$results = [];
		$previous_system_execution = $this->system_execution;
		$this->system_execution = false;
		try
		{
			$results = $this->execute_actions_with_current_actor($actions);
		}
		finally
		{
			$this->system_execution = $previous_system_execution;
		}

		return $results;
	}

	public function execute_system_actions(array $actions): array
	{
		if (!defined('SMF') || SMF !== 'BACKGROUND')
		{
			return $this->execute_actions($actions);
		}

		$previous_system_execution = $this->system_execution;
		$this->system_execution = true;
		try
		{
			return $this->with_system_actor(fn(): array => $this->execute_actions_with_current_actor($actions));
		}
		finally
		{
			$this->system_execution = $previous_system_execution;
		}
	}

	protected function execute_actions_with_current_actor(array $actions): array
	{
		$results = [];
		foreach ($actions as $action)
		{
			$action_id = (int) ($action['id'] ?? 0);
			$content_type = strtolower(trim((string) ($action['remote_content_type'] ?? '')));
			$content_id = trim((string) ($action['remote_content_id'] ?? ''));
			$requested = strtolower(trim((string) ($action['action'] ?? '')));

			if (!$action_id || $content_id === '')
			{
				$results[] = ['id' => $action_id, 'status' => 'failed', 'message' => 'Invalid action payload'];
				continue;
			}

			try
			{
				$result = null;
				if ($content_type === 'user')
				{
					$result = $this->execute_user_action($content_id, $requested);
				}
				elseif (in_array($content_type, ['thread', 'post'], true))
				{
					$result = $this->execute_post_action($content_type, $content_id, $requested);
				}
				else
				{
					$this->client->get_timeout_queue()->retire($content_type, $content_id);
					$results[] = ['id' => $action_id, 'status' => 'failed', 'message' => 'Unsupported content type'];
					continue;
				}

				if (!is_array($result))
				{
					$result = ['status' => 'failed', 'message' => 'Action did not return a result'];
				}
				if ($result['status'] === 'applied' || !empty($result['terminal']))
				{
					$this->client->get_timeout_queue()->retire($content_type, $content_id);
				}
				$results[] = [
					'id' => $action_id,
					'status' => $result['status'],
					'message' => $result['message'],
				];
			}
			catch (\Throwable $e)
			{
				$results[] = [
					'id' => $action_id,
					'status' => 'failed',
					'message' => substr(trim((string) $e->getMessage()), 0, 500) ?: 'Action failed',
				];
			}
		}

		return $results;
	}

	public function apply_queue_notes(array $notes): void
	{
		global $smcFunc;
		foreach ($notes as $note)
		{
			if (!is_array($note))
			{
				continue;
			}
			$type = (string) ($note['remote_content_type'] ?? '');
			$id = (int) ($note['remote_content_id'] ?? 0);
			$reason = trim((string) ($note['fortress_public_reason'] ?? ''));
			if ($id <= 0 || $reason === '' || !in_array($type, ['thread', 'post'], true))
			{
				continue;
			}
			$smcFunc['db_query']('', '
				UPDATE {db_prefix}messages
				SET modified_reason = {string:reason}
				WHERE id_msg = {int:id_msg}
					AND (modified_reason = {string:empty_reason} OR modified_reason IS NULL)',
				[
					'reason' => substr($reason, 0, 2000),
					'empty_reason' => '',
					'id_msg' => $id,
				]
			);
		}
	}

	protected function map_message_row(array $row, string $scripturl): ?array
	{
		$post_id = (int) ($row['id_msg'] ?? 0);
		if ($post_id <= 0)
		{
			return null;
		}
		$is_topic = $post_id === (int) ($row['id_first_msg'] ?? 0);
		$title = trim((string) ($row['subject'] ?? ''));
		if ($title === '')
		{
			$title = $is_topic ? 'Topic' : 'Reply';
		}
		$excerpt = $this->excerpt((string) ($row['body'] ?? ''));
		$username = trim((string) ($row['member_name'] ?? ''));
		$content_url = $scripturl . '?msg=' . $post_id . '#msg' . $post_id;

		$available = ['approve', 'reject'];
		if ((int) ($row['id_member'] ?? 0) > 0)
		{
			$available[] = 'spam_clean';
		}

		$payload = [
			'content_type' => $is_topic ? 'thread' : 'post',
			'topic_id' => (int) ($row['id_topic'] ?? 0),
			'board_id' => (int) ($row['id_board'] ?? 0),
		];
		$meta = $this->client->get_timeout_queue()->message_meta($post_id);
		if (is_array($meta))
		{
			$payload = array_merge($payload, $meta);
		}

		return [
			'remote_content_type' => $is_topic ? 'thread' : 'post',
			'remote_content_id' => (string) $post_id,
			'title' => $title,
			'excerpt' => $excerpt !== '' ? $excerpt : null,
			'username' => $username !== '' ? $username : null,
			'remote_user_id' => isset($row['id_member']) ? (string) (int) $row['id_member'] : null,
			'content_date' => (int) ($row['poster_time'] ?? 0),
			'content_url' => $content_url,
			'available_actions' => $available,
			'payload' => $payload,
		];
	}

	protected function excerpt(string $text): string
	{
		$plain = preg_replace('/\\[\\/?[^\\]]+\\]/', ' ', $text);
		$plain = trim(strip_tags(str_replace("\n", ' ', (string) $plain)));
		return substr($plain, 0, 280);
	}

	protected function execute_post_action(string $content_type, string $content_id, string $action): array
	{
		global $smcFunc;
		$transaction_started = isset($smcFunc['db_transaction']) && is_callable($smcFunc['db_transaction']);
		if ($transaction_started)
		{
			$smcFunc['db_transaction']('begin');
		}
		try
		{
			$result = $this->execute_post_action_locked($content_type, $content_id, $action);
			if ($transaction_started)
			{
				$smcFunc['db_transaction']('commit');
			}
			return $result;
		}
		catch (\Throwable $e)
		{
			if ($transaction_started)
			{
				$smcFunc['db_transaction']('rollback');
			}
			throw $e;
		}
	}

	protected function execute_post_action_locked(string $content_type, string $content_id, string $action): array
	{
		global $smcFunc;
		if (!ctype_digit($content_id) || (int) $content_id < 1)
		{
			return $this->terminal_failure('Invalid message identifier');
		}
		$msg_id = (int) $content_id;
		if (!in_array($action, ['approve', 'reject', 'spam_clean'], true))
		{
			return $this->terminal_failure('Unsupported post action');
		}
		if (!$this->can_moderate())
		{
			return ['status' => 'failed', 'message' => 'Insufficient local moderation permission'];
		}

		$request = $smcFunc['db_query']('', '
			SELECT m.id_msg, m.id_topic, m.id_board, m.id_member, m.approved,
				 t.id_first_msg
			FROM {db_prefix}messages AS m
			INNER JOIN {db_prefix}topics AS t ON (t.id_topic = m.id_topic)
			WHERE m.id_msg = {int:id_msg}
			LIMIT 1
			FOR UPDATE',
			['id_msg' => $msg_id]
		);
		$row = $smcFunc['db_fetch_assoc']($request);
		$smcFunc['db_free_result']($request);
		if (!is_array($row))
		{
			return $this->terminal_failure('Message no longer exists');
		}
		$is_first = $msg_id === (int) $row['id_first_msg'];
		if (($content_type === 'thread') !== $is_first)
		{
			return $this->terminal_failure('Message type no longer matches the queued item');
		}
		if ($action === 'approve')
		{
			if ((int) $row['approved'] !== 0)
			{
				return $this->terminal_failure('Message is already approved');
			}
			if (!approvePosts([$msg_id], true, true))
			{
				return ['status' => 'failed', 'message' => 'SMF did not approve the message'];
			}
			return ['status' => 'applied', 'message' => 'Action applied'];
		}

		if ((int) $row['approved'] !== 0)
		{
			return $this->terminal_failure('Refusing to remove an already-approved message');
		}
		if ($action === 'spam_clean' && (int) $row['id_member'] < 1)
		{
			return $this->terminal_failure('Spam cleanup is not supported for guest content');
		}
		if (!$this->can_delete_message((int) $row['id_board']))
		{
			return ['status' => 'failed', 'message' => 'Local SMF permissions do not allow removing this message'];
		}
		if ($content_type === 'thread')
		{
			removeTopics((int) $row['id_topic'], true, $action === 'spam_clean');
			return ['status' => 'applied', 'message' => 'Topic removed through SMF moderation APIs'];
		}
		if (!$this->can_delete_message((int) $row['id_board']))
		{
			return ['status' => 'failed', 'message' => 'Local SMF permissions do not allow removing this message'];
		}
		// SMF returns true only when removing the message also removed its whole
		// topic. A false return is still a successful single-message removal.
		removeMessage($msg_id, true);
		return ['status' => 'applied', 'message' => 'Message removed through SMF moderation APIs'];
	}

	protected function execute_user_action(string $content_id, string $action): array
	{
		global $smcFunc;
		$transaction_started = isset($smcFunc['db_transaction']) && is_callable($smcFunc['db_transaction']);
		if ($transaction_started)
		{
			$smcFunc['db_transaction']('begin');
		}
		try
		{
			$result = $this->execute_user_action_locked($content_id, $action);
			if ($transaction_started)
			{
				$smcFunc['db_transaction']('commit');
			}
			return $result;
		}
		catch (\Throwable $e)
		{
			if ($transaction_started)
			{
				$smcFunc['db_transaction']('rollback');
			}
			throw $e;
		}
	}

	protected function execute_user_action_locked(string $content_id, string $action): array
	{
		global $smcFunc, $modSettings;
		if (!in_array($action, ['approve', 'reject'], true))
		{
			return $this->terminal_failure('Unsupported user action');
		}
		if (!$this->can_moderate())
		{
			return ['status' => 'failed', 'message' => 'Insufficient local moderation permission'];
		}

		$timeout_entry = $this->client->get_timeout_queue()->find_entry('user', $content_id);
		$member_id = ctype_digit($content_id) ? (int) $content_id : 0;
		if (is_array($timeout_entry))
		{
			$payload = is_array($timeout_entry['check_payload'] ?? null) ? $timeout_entry['check_payload'] : [];
			$username = trim((string) ($payload['username'] ?? ''));
			$email = trim((string) ($payload['email'] ?? ''));
			if ($username === '' && $email === '')
			{
				return $this->terminal_failure('Timed-out registration has no safe member identity');
			}
			$request = $smcFunc['db_query']('', '
				SELECT id_member, member_name, email_address, is_activated
				FROM {db_prefix}members
				WHERE is_activated = {int:pending}
					AND (' . ($username !== '' ? 'member_name = {string:member_name}' : 'email_address = {string:email_address}') . ')
				LIMIT 1
				FOR UPDATE',
				[
					'pending' => 3,
					'member_name' => $username,
					'email_address' => $email,
				]
			);
			$row = $smcFunc['db_fetch_assoc']($request);
			$smcFunc['db_free_result']($request);
			if (!is_array($row))
			{
				return $this->terminal_failure('Pending registration no longer exists');
			}
			$member_id = (int) $row['id_member'];
		}
		if ($member_id < 1)
		{
			return $this->terminal_failure('Invalid member identifier');
		}

		$request = $smcFunc['db_query']('', '
			SELECT id_member, member_name, is_activated, id_group, additional_groups
			FROM {db_prefix}members
			WHERE id_member = {int:id_member}
			LIMIT 1
			FOR UPDATE',
			['id_member' => $member_id]
		);
		$row = $smcFunc['db_fetch_assoc']($request);
		$smcFunc['db_free_result']($request);
		if (!is_array($row))
		{
			return $this->terminal_failure('Member no longer exists');
		}
		if ((int) $row['is_activated'] !== 3)
		{
			return $this->terminal_failure('Member is no longer awaiting approval');
		}

		if ($action === 'approve')
		{
			call_integration_hook('integrate_activate', [$row['member_name']]);
			updateMemberData($member_id, ['is_activated' => 1, 'validation_code' => '']);
			if ((int) ($modSettings['unapprovedMembers'] ?? 0) > 0)
			{
				updateSettings(['unapprovedMembers' => max(0, (int) $modSettings['unapprovedMembers'] - 1)]);
			}
			updateStats('member', false);
			if (function_exists('logAction'))
			{
				logAction('approve_member', ['member' => $member_id], 'admin');
			}
			return ['status' => 'applied', 'message' => 'Member approved through SMF APIs'];
		}

		if (!function_exists('deleteMembers') || (!allowedTo('admin_forum') && !allowedTo('profile_remove_any')))
		{
			return ['status' => 'failed', 'message' => 'Local SMF permissions do not allow removing this member'];
		}
		$additional_groups = array_filter(array_map('trim', explode(',', (string) ($row['additional_groups'] ?? ''))));
		if ((int) ($row['id_group'] ?? 0) === 1 || in_array('1', $additional_groups, true))
		{
			return $this->terminal_failure('Refusing to remove an administrator account');
		}
		deleteMembers($member_id, true);
		return ['status' => 'applied', 'message' => 'Member removed through SMF APIs'];
	}

	protected function add_queue_item(array &$output, array &$keys, array $item): bool
	{
		$type = trim((string) ($item['remote_content_type'] ?? ''));
		$id = trim((string) ($item['remote_content_id'] ?? ''));
		if ($type === '' || $id === '')
		{
			return false;
		}
		$key = strtolower($type) . ':' . $id;
		if (isset($keys[$key]))
		{
			$output[$keys[$key]] = array_merge($output[$keys[$key]], $item);
			return true;
		}
		$keys[$key] = count($output);
		$output[] = $item;
		return true;
	}

	protected function can_moderate(): bool
	{
		if ($this->system_execution)
		{
			return true;
		}
		return function_exists('allowedTo') && (allowedTo('admin_forum') || allowedTo('moderate_forum'));
	}

	protected function can_delete_message(int $board_id): bool
	{
		if ($this->system_execution)
		{
			return true;
		}
		if (function_exists('allowedTo') && allowedTo('admin_forum'))
		{
			return true;
		}
		if (!function_exists('boardsAllowedTo'))
		{
			return false;
		}
		$boards = boardsAllowedTo('delete_any');
		return in_array(0, $boards, true) || in_array($board_id, $boards, true);
	}

	protected function terminal_failure(string $message): array
	{
		return ['status' => 'failed', 'message' => $message, 'terminal' => true];
	}

	/**
	 * SMF 2.1 cron deliberately has no logged-in user. Native destructive APIs
	 * still require an administrator-shaped $user_info context, so create a
	 * temporary actor from an existing administrator only for this one
	 * API-key-authorized background pass, and always restore the original state.
	 */
	protected function with_system_actor(callable $callback): array
	{
		global $smcFunc;
		$had_user_info = array_key_exists('user_info', $GLOBALS);
		$previous_user_info = $had_user_info ? $GLOBALS['user_info'] : null;
		$request = $smcFunc['db_query']('', '
			SELECT id_member, member_name, real_name, email_address
			FROM {db_prefix}members
			WHERE id_group = {int:admin_group}
				OR FIND_IN_SET({int:admin_group}, additional_groups) != 0
			ORDER BY id_member ASC
			LIMIT 1',
			['admin_group' => 1]
		);
		$admin = $smcFunc['db_fetch_assoc']($request);
		$smcFunc['db_free_result']($request);
		if (!is_array($admin))
		{
			throw new \RuntimeException('No SMF administrator is available for scheduled moderation actions');
		}

		$GLOBALS['user_info'] = is_array($previous_user_info) ? array_merge($previous_user_info, [
			'id' => (int) $admin['id_member'],
			'username' => (string) $admin['member_name'],
			'name' => (string) ($admin['real_name'] ?? $admin['member_name']),
			'email' => (string) ($admin['email_address'] ?? ''),
			'is_guest' => false,
			'is_admin' => true,
			'is_mod' => true,
			'groups' => [1],
			'permissions' => ['admin_forum', 'moderate_forum', 'delete_any', 'profile_remove_any'],
			'can_manage_boards' => true,
		]) : [
			'id' => (int) $admin['id_member'],
			'username' => (string) $admin['member_name'],
			'name' => (string) ($admin['real_name'] ?? $admin['member_name']),
			'email' => (string) ($admin['email_address'] ?? ''),
			'is_guest' => false,
			'is_admin' => true,
			'is_mod' => true,
			'groups' => [1],
			'permissions' => ['admin_forum', 'moderate_forum', 'delete_any', 'profile_remove_any'],
			'can_manage_boards' => true,
		];

		try
		{
			return $callback();
		}
		finally
		{
			if ($had_user_info)
			{
				$GLOBALS['user_info'] = $previous_user_info;
			}
			else
			{
				unset($GLOBALS['user_info']);
			}
		}
	}
}
