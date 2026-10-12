<?php
// Copyright (c) 2026 Marscastle Ltd trading as Forum Fortress
// SPDX-License-Identifier: GPL-2.0-or-later
if (!defined('SMF')) { die('No direct access...'); }
global $smcFunc, $modSettings;
// Native bans are moderator-owned once applied; uninstall never reverses them.
db_extend('packages');
$smcFunc['db_drop_table']('{db_prefix}ffprotect_repeat_events');
$smcFunc['db_drop_table']('{db_prefix}ffprotect_repeat_state');
$smcFunc['db_query']('', 'DELETE FROM {db_prefix}scheduled_tasks WHERE task={string:task}', ['task'=>'ffprotect_repeat_ban']);
$smcFunc['db_query']('', 'DELETE FROM {db_prefix}settings WHERE variable={string:key}', ['key'=>'ffprotect_repeat_ban']);
unset($modSettings['ffprotect_repeat_ban']);
