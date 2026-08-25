<?php

/**
 * SMF scheduled tasks for Forum Fortress maintenance and moderation sync.
 */

if (!defined('SMF'))
{
	die('No direct access...');
}

use function ForumFortress\Smf\ffp_client;

function ffp_scheduled_pre_load(): void
{
	// Hook loads this file before SMF's scheduler resolves task callables.
}

function scheduled_ffprotect_endpoint_catalog(): bool
{
	global $boarddir, $sourcedir;

	if (!defined('SMF_VERSION'))
	{
		require_once $boarddir . '/Settings.php';
	}

	require_once $sourcedir . '/ForumFortressProtect.php';
	ffp_client()->refresh_endpoint_catalog_if_stale();
	return true;
}

function scheduled_ffprotect_hourly_sync(): bool
{
	global $boarddir, $sourcedir;

	if (!defined('SMF_VERSION'))
	{
		require_once $boarddir . '/Settings.php';
	}

	require_once $sourcedir . '/ForumFortressProtect.php';
	ffp_client()->hourly_sync();
	return true;
}
