<?php

/**
 * Admin settings for Forum Fortress (SMF).
 */

if (!defined('SMF'))
{
	die('No direct access...');
}

require_once __DIR__ . '/ForumFortressProtect.php';

use ForumFortress\Smf\ApiClient;
use function ForumFortress\Smf\ffp_client;

function ModifyForumFortressSettings()
{
	global $context, $txt, $scripturl, $modSettings, $sourcedir;

	isAllowedTo('admin_forum');
	loadLanguage('ForumFortressProtect');
	loadLanguage('ManageSettings');

	$client = ffp_client();
	$action_message = '';

	if (isset($_POST['ffp_portal']))
	{
		$portal_url = '';
		if (checkSession('post') === '')
		{
			try
			{
				$launch = $client->portal_launch();
				if (is_array($launch) && is_string($launch['portal_url'] ?? null) && $client->is_safe_portal_url($launch['portal_url']))
				{
					// Native redirect helpers expect a lowercase scheme.
					$portal_url = 'https://' . substr($launch['portal_url'], strlen('https://'));
				}
			}
			catch (\Throwable $e)
			{
			}
		}
		if ($portal_url !== '')
		{
			redirectexit($portal_url);
			return;
		}
		$action_message = $txt['ffp_portal_error'];
	}

	if (isset($_POST['ffp_save']) && checkSession('post') === '')
	{
		$api_region = \FfApiResilience::normaliseApiRegion((string) ($_POST['ffprotect_api_region'] ?? 'global'));
		$settings = [
			'ffprotect_enabled' => !empty($_POST['ffprotect_enabled']) ? '1' : '0',
			'ffprotect_repeat_ban' => !empty($_POST['ffprotect_repeat_ban']) ? '1' : '0',
			'ffprotect_api_base_url' => \FfApiResilience::apiBaseUrlForRegion($api_region),
			'ffprotect_api_region' => $api_region,
			'ffprotect_allow_global_fallback' => !empty($_POST['ffprotect_allow_global_fallback']) ? '1' : '0',
			'ffprotect_preferred_endpoint' => '',
			'ffprotect_timeout' => max(1, (int) ($_POST['ffprotect_timeout'] ?? 3)),
			'ffprotect_site_id' => trim((string) ($_POST['ffprotect_site_id'] ?? '')),
			'ffprotect_fail_open' => !empty($_POST['ffprotect_fail_open']) ? '1' : '0',
			'ffprotect_debug_log' => !empty($_POST['ffprotect_debug_log']) ? '1' : '0',
			'ffprotect_send_ham' => !empty($_POST['ffprotect_send_ham']) ? '1' : '0',
			'ffprotect_delete_rejected_users' => !empty($_POST['ffprotect_delete_rejected_users']) ? '1' : '0',
			'ffprotect_bypass_administrators' => !empty($_POST['ffprotect_bypass_administrators']) ? '1' : '0',
			'ffprotect_bypass_moderators' => !empty($_POST['ffprotect_bypass_moderators']) ? '1' : '0',
		];
		// Never interpret an empty write-only field as a request to remove a live key.
		$replacement_key = trim((string) ($_POST['ffprotect_api_key'] ?? ''));
		if ($replacement_key !== '' && preg_match('/[\r\n]/', $replacement_key))
		{
			$action_message = $txt['ffp_api_key_invalid'];
		}
		else
		{
			if ($replacement_key !== '')
			{
				$settings['ffprotect_api_key'] = $replacement_key;
			}
			updateSettings($settings);
			if ($settings['ffprotect_enabled'] === '0' || $settings['ffprotect_repeat_ban'] === '0') { \ForumFortress\Smf\RepeatOffenders::cancelPending(); }
			$action_message = $txt['ffp_settings_saved'];
		}
	}

	// Clearing the key is deliberately separate from routine saves. Disable
	// automatic protection first so the same request does not re-bootstrap it.
	if (isset($_POST['ffp_clear_api_key']) && checkSession('post') === '')
	{
		updateSettings(['ffprotect_enabled' => '0', 'ffprotect_api_key' => '']);
		\ForumFortress\Smf\RepeatOffenders::cancelPending();
		$action_message = $txt['ffp_api_key_cleared'];
	}

	if (isset($_POST['ffp_test']) && checkSession('post') === '')
	{
		try
		{
			$client->bootstrap_if_needed();
			$client->refresh_endpoints_before_connection_test();
			$parts = [];
			foreach ([
				'bootstrap' => $client->bootstrap_if_needed(),
				'health' => $client->health(),
				'capabilities' => $client->capabilities(),
				'site_status' => $client->site_status(),
				'forum_stats' => $client->forum_stats(),
				'site_ping' => $client->site_ping(),
			] as $label => $payload)
			{
				$parts[] = $label . ': ' . ($payload !== null ? 'OK' : 'no response');
			}
			$summary = $client->endpoint_state_summary();
			$parts[] = 'preferred: ' . ($summary['preferred'] ?? '');
			$action_message = implode(' | ', $parts);
		}
		catch (\Throwable $e)
		{
			$action_message = $e->getMessage();
		}
	}

	if (isset($_POST['ffp_attack_on']) && checkSession('post') === '')
	{
		try
		{
			$payload = $client->activate_attack_mode();
			$action_message = $payload !== null
				? $txt['ffp_attack_mode_enabled']
				: ($client->get_last_request_error() ?: $txt['ffp_no_response']);
		}
		catch (\Throwable $e)
		{
			$action_message = $e->getMessage();
		}
	}

	if (isset($_POST['ffp_attack_off']) && checkSession('post') === '')
	{
		try
		{
			$payload = $client->deactivate_attack_mode();
			$action_message = $payload !== null
				? $txt['ffp_attack_mode_disabled']
				: ($client->get_last_request_error() ?: $txt['ffp_no_response']);
		}
		catch (\Throwable $e)
		{
			$action_message = $e->getMessage();
		}
	}

	if (isset($_POST['ffp_register']) && checkSession('post') === '')
	{
		$email = trim((string) ($_POST['ffp_registration_email'] ?? ''));
		if ($email === '')
		{
			$action_message = $txt['ffp_registration_email_required'];
		}
		else
		{
			try
			{
				$payload = $client->register_site($email);
				$action_message = $payload !== null ? $txt['ffp_registration_complete'] : $txt['ffp_no_response'];
			}
			catch (\Throwable $e)
			{
				$action_message = $e->getMessage();
			}
		}
	}

	if (!empty($modSettings['ffprotect_enabled']) && $client->is_enabled() && trim((string) ($modSettings['ffprotect_api_key'] ?? '')) === '')
	{
		try
		{
			$client->bootstrap_if_needed();
		}
		catch (\Throwable $e)
		{
		}
	}

	try
	{
		$client->refresh_endpoint_catalog_and_health(false);
	}
	catch (\Throwable $e)
	{
	}

	$site_status = null;
	$forum_stats = null;
	$attack_mode_active = null;
	$show_registration = empty($modSettings['ffprotect_site_id']);

	if ($client->is_enabled() && trim((string) ($modSettings['ffprotect_api_key'] ?? '')) !== '')
	{
		try
		{
			$site_status = $client->site_status();
			if (is_array($site_status))
			{
				$attack_mode_active = array_key_exists('attack_mode_active', $site_status)
					? (bool) $site_status['attack_mode_active']
					: (array_key_exists('enabled', $site_status)
						? (bool) $site_status['enabled']
						: (is_array($site_status['attack_mode'] ?? null) && array_key_exists('enabled', $site_status['attack_mode'])
							? (bool) $site_status['attack_mode']['enabled']
							: null));
				$show_registration = $show_registration || !empty($site_status['registration_required']);
			}
			$forum_stats = $client->forum_stats();
		}
		catch (\Throwable $e)
		{
		}
	}

	$can_portal = trim((string) ($modSettings['ffprotect_site_id'] ?? '')) !== '' && trim((string) ($modSettings['ffprotect_api_key'] ?? '')) !== '';

	$context['page_title'] = $txt['ffp_settings_title'];
	$context[$context['admin_menu_name']]['tab_data'] = [
		'title' => $txt['ffp_settings_title'],
		'description' => $txt['ffp_settings_desc'],
	];

	$latency_rows = $client->build_endpoint_latency_rows();
	$endpoint_summary = $client->endpoint_state_summary();
	$endpoint_snapshot = $client->endpoint_state_snapshot();

	// Only non-secret configuration belongs in template context.
	$public_settings = $modSettings;
	unset($public_settings['ffprotect_api_key']);
	loadTemplate('ForumFortressProtect');
	$context['sub_template'] = 'admin_forumfortress';
	$context['ffp'] = [
		'action_message' => $action_message,
		'plugin_version' => ApiClient::PLUGIN_VERSION,
		'domain' => $client->get_domain(),
		'endpoint_summary' => $endpoint_summary,
		'endpoint_snapshot' => $endpoint_snapshot,
		'latency_rows' => $latency_rows,
		'modSettings' => $public_settings,
		'repeat_available' => \ForumFortress\Smf\RepeatOffenders::available(),
		'repeat_recent' => \ForumFortress\Smf\RepeatOffenders::recent(),
		'api_key_configured' => trim((string) ($modSettings['ffprotect_api_key'] ?? '')) !== '',
		'form_url' => $scripturl . '?action=admin;area=modsettings;sa=forumfortress',
		'site_status' => $site_status,
		'forum_stats' => $forum_stats,
		'attack_mode_active' => $attack_mode_active,
		'can_portal' => $can_portal,
		'show_registration' => $show_registration,
	];
}
