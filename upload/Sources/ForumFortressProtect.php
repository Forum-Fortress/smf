<?php

/**
 * Forum Fortress integration hooks for SMF 2.1.
 */

if (!defined('SMF'))
{
	die('No direct access...');
}

require_once __DIR__ . '/ForumFortress/SmfAdapters.php';
require_once __DIR__ . '/ForumFortress/ApiClient.php';
require_once __DIR__ . '/ForumFortress/DecisionMapper.php';
require_once __DIR__ . '/ForumFortress/TimeoutQueue.php';
require_once __DIR__ . '/ForumFortress/ModerationBridge.php';
require_once __DIR__ . '/ManageForumFortress.php';

use ForumFortress\Smf\ApiClient;
use ForumFortress\Smf\DecisionMapper;
use function ForumFortress\Smf\ffp_client;

/** @var string|null */
$GLOBALS['ffp_pending_register_decision'] = null;

/** @var string|null */

/** @var array<string, mixed>|null */
$GLOBALS['ffp_pending_post_timeout'] = null;

function ffp_load_language(): void
{
	loadLanguage('ForumFortressProtect');
}

/**
 * A transport/schema exception must be handled by the caller's fail-open
 * policy. Returning null here lets the same code path handle both exceptions
 * and ordinary unsuccessful responses without leaking an API error to SMF.
 */
function ffp_run_check(ApiClient $client, string $endpoint, array $payload): ?array
{
	try
	{
		return $client->check($endpoint, $payload);
	}
	catch (\Throwable $e)
	{
		return null;
	}
}

function ffp_integrate_register_check(&$regOptions, &$reg_errors): void
{
	$client = ffp_client();
	if (!$client->is_enabled() || $client->protection_checks_bypassed())
	{
		return;
	}
	ffp_load_language();
	global $txt;
	try
	{
		$client->bootstrap_if_needed();
	}
	catch (\Throwable $e)
	{
	}
	$payload = [
		'ip' => (string) (($regOptions['interface'] ?? '') === 'admin' ? '127.0.0.1' : ($_SERVER['REMOTE_ADDR'] ?? '')),
		'username' => (string) ($regOptions['username'] ?? ''),
		'email' => (string) ($regOptions['email'] ?? ''),
		'email_domain' => ApiClient::email_domain((string) ($regOptions['email'] ?? '')),
		'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
		'links' => [],
	];
	$response = ffp_run_check($client, 'register', $payload);
	if ($response === null && $client->last_check_had_timeout())
	{
		$client->queue_timeout_recovery('register', $payload, [
			'remote_content_type' => 'user',
			'username' => (string) ($regOptions['username'] ?? ''),
			'title' => 'Registration (FFTimeout)',
		]);
		if (!$client->fail_open())
		{
			$reg_errors[] = ['lang', 'ffp_blocked', false];
		}
		return;
	}
	if (DecisionMapper::is_above_limit($response) && !$client->fail_open())
	{
		$reg_errors[] = ['lang', 'ffp_above_limit', false];
		return;
	}
	$decision = DecisionMapper::decision($response, $client->fail_open());
	$GLOBALS['ffp_pending_register_decision'] = $decision;
	if ($decision === 'block')
	{
		$reg_errors[] = ['lang', 'ffp_blocked', false];
	}
}

function ffp_integrate_register_after($regOptions, $memberID): void
{
	$client = ffp_client();
	if (!$client->is_enabled())
	{
		return;
	}
	$decision = $GLOBALS['ffp_pending_register_decision'] ?? null;
	if (
		$decision === 'block'
		&& $client->delete_rejected_users_enabled()
		&& function_exists('allowedTo')
		&& allowedTo('admin_forum')
	)
	{
		require_once $GLOBALS['sourcedir'] . '/Subs-Members.php';
		deleteMembers((int) $memberID);
	}
	$client->report('register', [
		'event_type' => 'register',
		'ip' => (string) ($regOptions['register_vars']['member_ip'] ?? ''),
		'username' => (string) ($regOptions['username'] ?? ''),
		'email' => (string) ($regOptions['email'] ?? ''),
		'email_domain' => ApiClient::email_domain((string) ($regOptions['email'] ?? '')),
		'payload' => ['smf_member_id' => (int) $memberID],
	]);
	$GLOBALS['ffp_pending_register_decision'] = null;
}

function ffp_integrate_post2_pre(&$post_errors): void
{
	global $user_info;
	global $smcFunc;
	global $board;
	$client = ffp_client();
	if (!$client->is_enabled() || $client->protection_checks_bypassed())
	{
		return;
	}
	ffp_load_language();
	try
	{
		$client->bootstrap_if_needed();
	}
	catch (\Throwable $e)
	{
	}
	$message = (string) ($_POST['message'] ?? '');
	$subject = (string) ($_POST['subject'] ?? '');
	$topic_id = (int) ($_REQUEST['topic'] ?? $_POST['topic'] ?? 0);
	$msg_id = (int) ($_REQUEST['msg'] ?? 0);
	$board_id = (int) ($board ?? ($_REQUEST['board'] ?? 0));
	$is_topic_edit = false;
	if ($msg_id > 0 && isset($smcFunc['db_query']))
	{
		$request = $smcFunc['db_query']('', '
			SELECT m.id_topic, m.id_board, t.id_first_msg
			FROM {db_prefix}messages AS m
			INNER JOIN {db_prefix}topics AS t ON (t.id_topic = m.id_topic)
			WHERE m.id_msg = {int:id_msg}
			LIMIT 1',
			['id_msg' => $msg_id]
		);
		$row = $smcFunc['db_fetch_assoc']($request);
		$smcFunc['db_free_result']($request);
		if (is_array($row))
		{
			$topic_id = (int) ($row['id_topic'] ?? $topic_id);
			$board_id = (int) ($row['id_board'] ?? $board_id);
			$is_topic_edit = $msg_id === (int) ($row['id_first_msg'] ?? 0);
		}
	}
	$endpoint = $msg_id > 0
		? ($is_topic_edit ? 'topic_edit' : 'reply_edit')
		: ($topic_id > 0 ? 'reply' : 'topic');
	$is_guest = !empty($user_info['is_guest']);
	$username = $is_guest
		? (string) ($_POST['guestname'] ?? $user_info['name'] ?? $user_info['username'] ?? '')
		: (string) ($user_info['username'] ?? '');
	$email = $is_guest
		? (string) ($_POST['email'] ?? '')
		: (string) ($user_info['email'] ?? '');
	$content = $message;
	if (in_array($endpoint, ['topic', 'topic_edit'], true) && trim($subject) !== '')
	{
		$content = $subject . "\n\n" . $message;
	}
	$links = ApiClient::filter_external_links(ApiClient::extract_links($content), $client->get_domain());
	$payload = [
		'ip' => (string) ($user_info['ip'] ?? ''),
		'username' => $username,
		'email' => $email,
		'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
		'forum_id' => $board_id > 0 ? $board_id : null,
		'content_id' => $msg_id > 0 ? (string) $msg_id : null,
		'thread_id' => $topic_id > 0 ? (string) $topic_id : null,
		'content' => $content,
		'links' => $links,
		'post_count' => (int) ($user_info['posts'] ?? 0),
		'account_age_seconds' => !empty($user_info['register_date']) ? max(0, time() - (int) $user_info['register_date']) : 0,
	];
	$response = ffp_run_check($client, $endpoint, $payload);
	if ($response === null && $client->last_check_had_timeout())
	{
		$is_topic_endpoint = in_array($endpoint, ['topic', 'topic_edit'], true);
		$GLOBALS['ffp_pending_post_timeout'] = ['endpoint' => $endpoint, 'payload' => $payload, 'message' => $message];
		$client->queue_timeout_recovery($endpoint, $payload, [
			'remote_content_type' => $is_topic_endpoint ? 'thread' : 'post',
			'username' => $username,
			'title' => $is_topic_endpoint ? 'Topic (FFTimeout)' : 'Reply (FFTimeout)',
			'excerpt' => $message,
		]);
		if (!$client->fail_open())
		{
			$post_errors[] = 'ffp_blocked';
		}
		return;
	}
	$decision = DecisionMapper::decision($response, $client->fail_open());
	if ($decision === 'block')
	{
		$post_errors[] = 'ffp_blocked';
	}
}

function ffp_integrate_after_create_post($msgOptions, $topicOptions, $posterOptions, $message_columns, $message_parameters): void
{
	global $user_info;
	$client = ffp_client();
	if (!$client->is_enabled())
	{
		return;
	}
	$msg_id = (int) ($msgOptions['id'] ?? 0);
	if (is_array($GLOBALS['ffp_pending_post_timeout'] ?? null) && $msg_id > 0)
	{
		$pending = $GLOBALS['ffp_pending_post_timeout'];
		$client->get_timeout_queue()->attach_message_meta(
			$msg_id,
			(string) ($pending['endpoint'] ?? 'reply'),
			(array) ($pending['payload'] ?? [])
		);
		$GLOBALS['ffp_pending_post_timeout'] = null;
	}
	if (empty($msgOptions['approved']))
	{
		$message = (string) ($msgOptions['body'] ?? '');
		$client->report('moderation', [
			'event_type' => empty($topicOptions['first_msg']) ? 'topic' : 'reply',
			'ip' => (string) ($user_info['ip'] ?? ''),
			'username' => (string) ($user_info['username'] ?? ''),
			'domain' => $client->get_domain(),
			'links' => ApiClient::filter_external_links(ApiClient::extract_links($message), $client->get_domain()),
			'content_hash' => $message !== '' ? hash('sha256', $message) : null,
			'payload' => [
				'msg_id' => $msg_id,
				'topic_id' => (int) ($topicOptions['id'] ?? 0),
				'board_id' => (int) ($topicOptions['board'] ?? 0),
			],
		]);
	}
}

function ffp_integrate_profile_save(&$profile_vars, &$post_errors, $memID, $cur_profile, $current_area): void
{
	global $user_info;
	$client = ffp_client();
	if (!$client->is_enabled() || $client->protection_checks_bypassed())
	{
		return;
	}
	ffp_load_language();
	try
	{
		$client->bootstrap_if_needed();
	}
	catch (\Throwable $e)
	{
	}
	if ($current_area !== 'forumprofile' && $current_area !== 'theme')
	{
		return;
	}
	if (!empty($profile_vars['signature']))
	{
		$sig = trim((string) $profile_vars['signature']);
		if ($sig !== '')
		{
			$response = ffp_run_check($client, 'signature_edit', [
				'ip' => (string) ($user_info['ip'] ?? ''),
				'username' => (string) ($user_info['username'] ?? ''),
				'content_id' => (string) $memID,
				'signature_text' => $sig,
				'links' => ApiClient::filter_external_links(ApiClient::extract_links($sig), $client->get_domain()),
				'post_count' => (int) ($user_info['posts'] ?? 0),
				'account_age_seconds' => !empty($user_info['register_date']) ? max(0, time() - (int) $user_info['register_date']) : 0,
			]);
			ffp_apply_content_decision($response, $post_errors, $client);
		}
	}
	$profile_text = '';
	foreach ($profile_vars as $key => $val)
	{
		if (!is_string($val) || trim($val) === '' || $key === 'signature')
		{
			continue;
		}
		$profile_text .= trim($val) . "\n";
	}
	$profile_text = trim($profile_text);
	if ($profile_text === '')
	{
		return;
	}
	$response = ffp_run_check($client, 'profile_edit', [
		'ip' => (string) ($user_info['ip'] ?? ''),
		'username' => (string) ($user_info['username'] ?? ''),
		'content_id' => (string) $memID,
		'content' => $profile_text,
		'profile_fields' => array_filter($profile_vars, static function ($value, $key): bool {
			return $key !== 'signature' && is_string($value) && trim($value) !== '';
		}, ARRAY_FILTER_USE_BOTH),
		'links' => ApiClient::filter_external_links(ApiClient::extract_links($profile_text), $client->get_domain()),
		'post_count' => (int) ($user_info['posts'] ?? 0),
		'account_age_seconds' => !empty($user_info['register_date']) ? max(0, time() - (int) $user_info['register_date']) : 0,
	]);
	ffp_apply_content_decision($response, $post_errors, $client);
}

function ffp_apply_content_decision(?array $response, array &$post_errors, ApiClient $client): void
{
	if (DecisionMapper::is_above_limit($response) && !$client->fail_open())
	{
		$post_errors['ffp_above_limit'] = true;
		return;
	}
	$decision = DecisionMapper::decision($response, $client->fail_open());
	if ($decision === 'block')
	{
		$post_errors['ffp_blocked'] = true;
	}
}

function ffp_integrate_after_approve_posts($approve, $msgs, $topic_changes, $member_post_changes): void
{
	$client = ffp_client();
	if (!$client->is_enabled() || !$approve || !$client->send_ham_enabled())
	{
		return;
	}
	global $smcFunc;
	$request = $smcFunc['db_query']('', '
		SELECT m.id_msg, m.id_topic, m.id_board, m.body, m.poster_ip, m.id_member, mem.member_name, mem.email_address,
			t.id_first_msg
		FROM {db_prefix}messages AS m
		INNER JOIN {db_prefix}topics AS t ON (t.id_topic = m.id_topic)
		LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = m.id_member)
		WHERE m.id_msg IN ({array_int:msgs})',
		['msgs' => array_map('intval', (array) $msgs)]
	);
	while ($row = $smcFunc['db_fetch_assoc']($request))
	{
		$msg_id = (int) $row['id_msg'];
		$is_topic = $msg_id === (int) $row['id_first_msg'];
		$body = (string) ($row['body'] ?? '');
		$client->report('ham', [
			'event_type' => $is_topic ? 'topic' : 'reply',
			'domain' => $client->get_domain(),
			'ip' => (string) ($row['poster_ip'] ?? ''),
			'username' => (string) ($row['member_name'] ?? ''),
			'email_domain' => ApiClient::email_domain((string) ($row['email_address'] ?? '')),
			'links' => ApiClient::filter_external_links(ApiClient::extract_links($body), $client->get_domain()),
			'content_hash' => $body !== '' ? hash('sha256', $body) : null,
			'payload' => ['msg_id' => $msg_id],
		]);
	}
	$smcFunc['db_free_result']($request);
}

function ffp_integrate_modify_modifications(&$subActions): void
{
	global $context;

	$subActions['forumfortress'] = 'ModifyForumFortressSettings';

	if (!empty($context['admin_menu_name']) && isset($context[$context['admin_menu_name']]['tab_data']['tabs']))
	{
		$context[$context['admin_menu_name']]['tab_data']['tabs']['forumfortress'] = [];
	}
}

function ffp_integrate_admin_areas(&$menuData): void
{
	global $txt;

	loadLanguage('ForumFortressProtect');
	$menuData['config']['areas']['modsettings']['subsections']['forumfortress'] = [$txt['ffp_settings_title']];
}
