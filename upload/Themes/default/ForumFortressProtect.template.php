<?php

/**
 * @template Admin_ForumFortress
 */

function template_admin_forumfortress(): void
{
	global $context, $txt;

	$ffp = $context['ffp'];
	$ms = $ffp['modSettings'];
	$site_status = is_array($ffp['site_status'] ?? null) ? $ffp['site_status'] : null;
	$forum_stats = is_array($ffp['forum_stats'] ?? null) ? $ffp['forum_stats'] : null;
	$endpoint_summary = is_array($ffp['endpoint_summary'] ?? null) ? $ffp['endpoint_summary'] : [];
	$endpoint_snapshot = is_array($ffp['endpoint_snapshot'] ?? null) ? $ffp['endpoint_snapshot'] : [];
	$last_failure = is_array($endpoint_snapshot['last_failure'] ?? null) ? $endpoint_snapshot['last_failure'] : null;
	$connected = $site_status !== null;

	echo '
	<style>
	.ffDashboard{--ff-green:#087443;--ff-green-bright:#159458;--ff-green-soft:rgba(8,116,67,.1);--ff-amber:#b86313;--ff-border:rgba(127,127,127,.22);display:grid;gap:14px;padding:0;background:transparent}.ffDashboard *{box-sizing:border-box}.ffCard{overflow:hidden;margin:0!important;padding:0!important;border:1px solid var(--ff-border);border-radius:10px;background:var(--window-bg,#fff);box-shadow:0 2px 10px rgba(0,0,0,.05)}.ffHero{border-top:3px solid var(--ff-green)}.ffHeroHeader{display:flex;align-items:center;gap:13px;padding:18px;background:linear-gradient(135deg,var(--ff-green-soft),transparent 62%)}.ffMark{display:grid;flex:0 0 46px;width:46px;height:50px;place-content:center;gap:4px;clip-path:polygon(50% 0,94% 16%,88% 67%,70% 88%,50% 100%,30% 88%,12% 67%,6% 16%);background:linear-gradient(145deg,var(--ff-green-bright),#034f2e);filter:drop-shadow(0 2px 2px rgba(0,0,0,.18))}.ffMark i{display:block;width:25px;height:4px;border-radius:3px;background:#f4f0e5}.ffMark i:nth-child(2){width:20px}.ffMark i:nth-child(3){width:15px}.ffHeroCopy{display:grid;flex:1;gap:2px}.ffHeroCopy strong{font-size:17px}.ffHeroCopy span,.ffSectionHeader span,.ffMaintenance small{color:#6b7480}.ffPill{padding:5px 10px;border-radius:999px;background:rgba(108,116,128,.12);color:#6b7480;font-size:12px;font-weight:700}.ffPill.is-connected{background:var(--ff-green-soft);color:var(--ff-green-bright)}.ffVersion{padding:0 18px 15px;font-size:12px;color:#6b7480}.ffActionGrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding:0 18px 18px}.ffActionGrid form,.ffActionGrid a,.ffActionGrid span{display:flex;width:100%;margin:0!important}.ffButton{display:flex!important;align-items:center;justify-content:center;min-height:42px;width:100%;border-radius:6px!important;font-weight:600!important;text-align:center;text-decoration:none}.ffButton--primary{background:var(--ff-green)!important;border-color:var(--ff-green)!important;color:#fff!important}.ffButton--attack{color:var(--ff-amber)!important;border-color:rgba(184,99,19,.45)!important}.ffButton--quiet{background:var(--window-bg,#fff)!important}.ffNotice{display:flex;align-items:flex-start;gap:9px;padding:11px 13px;border:1px solid rgba(21,148,88,.28);border-radius:8px;background:rgba(21,148,88,.09);color:var(--ff-green);line-height:1.45}.ffNotice span{flex:1}.ffSectionHeader{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 16px;border-bottom:1px solid var(--ff-border)}.ffSectionHeader>div{display:grid;gap:2px}.ffSectionHeader strong{font-size:14px}.ffSectionHeader a{color:var(--ff-green);font-size:12px;font-weight:600}.ffStatusGrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.ffMetric{display:grid;gap:4px;min-width:0;padding:13px 16px;border-bottom:1px solid var(--ff-border)}.ffMetric:nth-child(odd){border-right:1px solid var(--ff-border)}.ffMetric:nth-last-child(-n+2){border-bottom:0}.ffMetric span{color:#6b7480;font-size:12px}.ffMetric strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:14px}.ffMetric.is-good strong{color:var(--ff-green-bright)}.ffMaintenance summary{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 16px;cursor:pointer;list-style:none}.ffMaintenance summary::-webkit-details-marker{display:none}.ffMaintenance summary span{display:grid;gap:2px}.ffMaintenance[open] summary{border-bottom:1px solid var(--ff-border)}.ffMaintenanceBody{padding:14px 16px 16px}.ffMaintenanceBody p{margin:0 0 12px;color:#6b7480}.ffMaintenanceBody form{margin:0}.ffDashboard dl.settings{display:grid;grid-template-columns:minmax(190px,38%) minmax(0,1fr);gap:0;margin:0}.ffDashboard dl.settings dt,.ffDashboard dl.settings dd{width:auto;margin:0;padding:11px 0;border-bottom:1px solid rgba(127,127,127,.14)}.ffDashboard input[type=text],.ffDashboard input[type=email]{max-width:100%}.ffDashboard .button{min-height:38px;border-radius:6px;font-weight:600}.ffDashboard .table_grid{overflow:hidden;margin:0;border:0;border-radius:0}.ffDashboard .table_grid th,.ffDashboard .table_grid td{padding:9px 8px;border-top:1px solid rgba(127,127,127,.14)}@media(max-width:700px){.ffHeroHeader{align-items:flex-start}.ffPill{margin-left:auto}.ffActionGrid,.ffStatusGrid{grid-template-columns:1fr}.ffMetric:nth-child(odd){border-right:0}.ffMetric:nth-last-child(2){border-bottom:1px solid var(--ff-border)}.ffDashboard dl.settings{grid-template-columns:1fr}.ffDashboard dl.settings dt{padding-bottom:4px;border-bottom:0}.ffDashboard dl.settings dd{padding-top:0}}
	</style>
	<div class="ffDashboard">
		<section class="ffCard ffHero">
			<div class="ffHeroHeader"><div class="ffMark" aria-hidden="true"><i></i><i></i><i></i></div><div class="ffHeroCopy"><strong>Forum Fortress</strong><span>', $txt['ffp_settings_desc'], '</span></div><span class="ffPill', $connected ? ' is-connected' : '', '">', $connected ? 'Connected' : 'Configured', '</span></div>
			<div class="ffActionGrid">';
	if (!empty($ffp['portal_launch_url']))
	{
		echo '<a href="', htmlspecialchars($ffp['portal_launch_url']), '" target="_blank" rel="noopener noreferrer" class="button ffButton ffButton--primary">', $txt['ffp_portal_login'], '</a>';
	}
	else
	{
		echo '<span class="button disabled ffButton ffButton--quiet">', $txt['ffp_portal_login'], '</span>';
	}
	if ($ffp['can_portal'])
	{
		if (!empty($ffp['attack_mode_active']))
		{
			echo '<form action="', $ffp['form_url'], '" method="post"><input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '"><input type="submit" name="ffp_attack_off" value="', $txt['ffp_attack_mode_off'], '" class="button ffButton"></form>';
		}
		else
		{
			echo '<form action="', $ffp['form_url'], '" method="post"><input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '"><input type="submit" name="ffp_attack_on" value="', $txt['ffp_attack_mode_on'], '" class="button ffButton ffButton--attack"></form>';
		}
	}
	echo '<form action="', $ffp['form_url'], '" method="post"><input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '"><input type="submit" name="ffp_test" value="', $txt['ffp_connection_test'], '" class="button ffButton"></form>';
	echo '</div><div class="ffVersion">', $txt['ffp_plugin_version'], ': ', htmlspecialchars($ffp['plugin_version']), '</div></section>';
	if (!empty($ffp['action_message']))
	{
		echo '<div class="ffNotice"><span>', htmlspecialchars($ffp['action_message']), '</span></div>';
	}

	echo '<section class="ffCard"><div class="ffSectionHeader"><div><strong>', $txt['ffp_section_site_status'], '</strong><span>Current protection and service details.</span></div><a href="', htmlspecialchars($ffp['form_url']), '">Refresh</a></div><div class="ffStatusGrid">';
	$metrics = [
		'Protection' => $connected ? 'Active' : 'Configured',
		$txt['ffp_plan'] => $site_status !== null ? (string) ($site_status['plan'] ?? $txt['ffp_unknown']) : $txt['ffp_unknown'],
		$txt['ffp_preferred_endpoint_status'] => (string) ($endpoint_summary['preferred'] ?? $txt['ffp_unknown']),
		$txt['ffp_test_answered'] => (string) ($endpoint_summary['last_responded'] ?? $txt['ffp_unknown']),
		$txt['ffp_current_month_checks'] => $forum_stats !== null ? (string) ($forum_stats['current_month_checks'] ?? 0) : $txt['ffp_unknown'],
		'Decisions' => $forum_stats !== null ? (int) ($forum_stats['allows'] ?? 0) . ' allowed / ' . (int) ($forum_stats['blocks'] ?? 0) . ' blocked' : $txt['ffp_unknown'],
	];
	foreach ($metrics as $label => $value)
	{
		echo '<div class="ffMetric', $label === 'Protection' && $connected ? ' is-good' : '', '"><span>', htmlspecialchars((string) $label), '</span><strong>', htmlspecialchars((string) $value), '</strong></div>';
	}
	echo '</div></section>';

	echo '<details class="ffCard ffMaintenance"><summary><span><strong>Maintenance and configuration</strong><small>Connection, protection policy, and registration</small></span><span aria-hidden="true">⌄</span></summary><div class="ffMaintenanceBody"><p>', $txt['ffp_settings_desc'], '</p>';
	echo '<form action="', $ffp['form_url'], '" method="post"><input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '">
			<dl class="settings">
				<dt><label for="ffprotect_enabled">', $txt['ffp_enabled'], '</label></dt><dd><input type="checkbox" name="ffprotect_enabled" id="ffprotect_enabled"', !empty($ms['ffprotect_enabled']) ? ' checked' : '', '></dd>
				<dt><label for="ffprotect_api_region">', $txt['ffp_api_region'], '</label><br><small>', $txt['ffp_api_region_desc'], '</small></dt><dd><select name="ffprotect_api_region" id="ffprotect_api_region">';
	$selected_region = \FfApiResilience::normaliseApiRegion((string) ($ms['ffprotect_api_region'] ?? \FfApiResilience::apiRegionFromLegacyBaseUrl((string) ($ms['ffprotect_api_base_url'] ?? ''))));
	foreach (['global' => $txt['ffp_region_global'], 'uk' => $txt['ffp_region_uk'], 'eu' => $txt['ffp_region_eu'], 'us' => $txt['ffp_region_us']] as $region => $label)
		echo '<option value="', $region, '"', $selected_region === $region ? ' selected' : '', '>', $label, '</option>';
	echo '</select></dd>
				<dt><label for="ffprotect_allow_global_fallback">', $txt['ffp_allow_global_fallback'], '</label><br><small>', $txt['ffp_allow_global_fallback_desc'], '</small></dt><dd><input type="checkbox" name="ffprotect_allow_global_fallback" id="ffprotect_allow_global_fallback"', !empty($ms['ffprotect_allow_global_fallback']) ? ' checked' : '', '></dd>
				<dt><label for="ffprotect_control_base_url">', $txt['ffp_control_base_url'], '</label></dt><dd><input type="text" name="ffprotect_control_base_url" id="ffprotect_control_base_url" size="60" value="', htmlspecialchars($ms['ffprotect_control_base_url'] ?? 'https://control.ffapi.net'), '"></dd>
				<dt><label for="ffprotect_timeout">', $txt['ffp_timeout'], '</label></dt><dd><input type="number" min="1" max="30" name="ffprotect_timeout" id="ffprotect_timeout" value="', (int) ($ms['ffprotect_timeout'] ?? 3), '"></dd>
				<dt><label for="ffprotect_fail_open">', $txt['ffp_fail_open'], '</label></dt><dd><input type="checkbox" name="ffprotect_fail_open" id="ffprotect_fail_open"', !empty($ms['ffprotect_fail_open']) ? ' checked' : '', '></dd>
				<dt><label for="ffprotect_api_key">', $txt['ffp_api_key'], '</label></dt><dd><input type="text" name="ffprotect_api_key" id="ffprotect_api_key" size="60" value="', htmlspecialchars($ms['ffprotect_api_key'] ?? ''), '"></dd>
				<dt><label for="ffprotect_site_id">', $txt['ffp_site_id'], '</label></dt><dd><input type="text" name="ffprotect_site_id" id="ffprotect_site_id" size="40" value="', htmlspecialchars($ms['ffprotect_site_id'] ?? ''), '"></dd>
				<dt>', $txt['ffp_domain'], '</dt><dd>', htmlspecialchars($ffp['domain']), '</dd>
			</dl>
			<h3>', $txt['ffp_section_protection'], '</h3>
			<dl class="settings">
				<dt><label for="ffprotect_bypass_administrators">', $txt['ffp_bypass_administrators'], '</label></dt><dd><input type="checkbox" name="ffprotect_bypass_administrators" id="ffprotect_bypass_administrators"', !empty($ms['ffprotect_bypass_administrators']) ? ' checked' : '', '></dd>
				<dt><label for="ffprotect_bypass_moderators">', $txt['ffp_bypass_moderators'], '</label></dt><dd><input type="checkbox" name="ffprotect_bypass_moderators" id="ffprotect_bypass_moderators"', !empty($ms['ffprotect_bypass_moderators']) ? ' checked' : '', '></dd>
				<dt><label for="ffprotect_send_ham">', $txt['ffp_send_ham'], '</label></dt><dd><input type="checkbox" name="ffprotect_send_ham" id="ffprotect_send_ham"', !empty($ms['ffprotect_send_ham']) ? ' checked' : '', '></dd>
				<dt><label for="ffprotect_delete_rejected_users">', $txt['ffp_delete_rejected_users'], '</label></dt><dd><input type="checkbox" name="ffprotect_delete_rejected_users" id="ffprotect_delete_rejected_users"', !empty($ms['ffprotect_delete_rejected_users']) ? ' checked' : '', '></dd>
				<dt><label for="ffprotect_debug_log">', $txt['ffp_debug_log'], '</label></dt><dd><input type="checkbox" name="ffprotect_debug_log" id="ffprotect_debug_log"', !empty($ms['ffprotect_debug_log']) ? ' checked' : '', '></dd>
			</dl>
			<input type="submit" name="ffp_save" value="', $txt['save'], '" class="button ffButton ffButton--primary">
			<input type="submit" name="ffp_test" value="', $txt['ffp_connection_test'], '" class="button ffButton ffButton--quiet">
		</form>';
	$last_failure_text = $txt['ffp_none'];
	if ($last_failure)
	{
		$parts = [(string) ($last_failure['reason'] ?? 'unknown')];
		if (!empty($last_failure['status']))
		{
			$parts[] = 'status ' . (int) $last_failure['status'];
		}
		if (!empty($last_failure['path']))
		{
			$parts[] = 'on ' . (string) $last_failure['path'];
		}
		$last_failure_text = implode(' ', $parts);
	}
	echo '<h3>Diagnostics</h3><dl class="settings"><dt>', $txt['ffp_registration_required'], '</dt><dd>', $site_status !== null && !empty($site_status['registration_required']) ? $txt['ffp_yes'] : $txt['ffp_no'], '</dd><dt>', $txt['ffp_preferred_missing'], '</dt><dd>', htmlspecialchars((string) ($endpoint_summary['preferred_missing'] ?? $txt['ffp_no'])), '</dd><dt>', $txt['ffp_last_health'], '</dt><dd>', (int) ($endpoint_snapshot['last_health_at'] ?? 0) > 0 ? gmdate('Y-m-d H:i:s', (int) $endpoint_snapshot['last_health_at']) . ' UTC' : $txt['ffp_unknown'], '</dd><dt>', $txt['ffp_last_failure'], '</dt><dd>', htmlspecialchars($last_failure_text), '</dd><dt>', $txt['ffp_forum_last_synced'], '</dt><dd>', (int) ($endpoint_summary['last_site_ping_at'] ?? 0) > 0 ? gmdate('Y-m-d H:i:s', (int) $endpoint_summary['last_site_ping_at']) . ' UTC' : $txt['ffp_unknown'], '</dd><dt>', $txt['ffp_dataset_version'], '</dt><dd>', htmlspecialchars((string) ($site_status['dataset_version'] ?? $txt['ffp_unknown'])), '</dd></dl>';
	if (!empty($ffp['show_registration']))
	{
		echo '<form action="', $ffp['form_url'], '" method="post" style="margin-top:14px;"><input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '"><label for="ffp_registration_email">', $txt['ffp_registration_email'], '</label><input type="email" name="ffp_registration_email" id="ffp_registration_email" size="40" value=""><input type="submit" name="ffp_register" value="', $txt['ffp_complete_registration'], '" class="button ffButton ffButton--primary"></form>';
	}
	echo '</div></details>';

	if (!empty($ffp['latency_rows']))
	{
		echo '<section class="ffCard"><div class="ffSectionHeader"><div><strong>', $txt['ffp_section_endpoint_health'], '</strong><span>Measured from this forum server.</span></div></div><table class="table_grid"><thead><tr><th>', $txt['ffp_endpoint_col'], '</th><th>', $txt['ffp_latency_col'], '</th></tr></thead><tbody>';
		foreach ($ffp['latency_rows'] as $row)
		{
			$pref = !empty($row['is_preferred']) ? ' *' : '';
			echo '<tr><td>', htmlspecialchars($row['endpoint'] ?? ''), $pref, '</td><td>', htmlspecialchars($row['latency'] ?? ''), '</td></tr>';
		}
		echo '</tbody></table></section>';
	}
	echo '</div>';
}
