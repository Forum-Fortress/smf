<?php
// Copyright (c) 2026 Marscastle Ltd trading as Forum Fortress
// SPDX-License-Identifier: GPL-2.0-or-later
if (!defined('SMF')) { die('No direct access...'); }
global $smcFunc, $modSettings;
db_extend('packages');
$smcFunc['db_create_table']('{db_prefix}ffprotect_repeat_state', [
    ['name'=>'member_id','type'=>'int','size'=>10,'unsigned'=>true,'default'=>0],
    ['name'=>'pending','type'=>'int','size'=>1,'unsigned'=>true,'default'=>0],
    ['name'=>'touched','type'=>'int','size'=>10,'unsigned'=>true,'default'=>0],
    ['name'=>'attempted','type'=>'int','size'=>10,'unsigned'=>true,'default'=>0],
], [
    ['type'=>'primary','columns'=>['member_id']],
    ['name'=>'pending','type'=>'index','columns'=>['pending','touched']],
], ['engine'=>'InnoDB']);
$smcFunc['db_create_table']('{db_prefix}ffprotect_repeat_events', [
    ['name'=>'id','type'=>'int','size'=>10,'unsigned'=>true,'auto'=>true],
    ['name'=>'member_id','type'=>'int','size'=>10,'unsigned'=>true,'default'=>0],
    ['name'=>'body_hash','type'=>'char','size'=>64,'default'=>''],
    ['name'=>'ref_hash','type'=>'char','size'=>64,'default'=>''],
    ['name'=>'observed','type'=>'int','size'=>10,'unsigned'=>true,'default'=>0],
    ['name'=>'consumed','type'=>'int','size'=>1,'unsigned'=>true,'default'=>0],
], [
    ['type'=>'primary','columns'=>['id']],
    ['name'=>'body','type'=>'unique','columns'=>['member_id','body_hash']],
    ['name'=>'reference','type'=>'unique','columns'=>['member_id','ref_hash']],
    ['name'=>'observed','type'=>'index','columns'=>['observed']],
    ['name'=>'evidence','type'=>'index','columns'=>['member_id','consumed','observed']],
], ['engine'=>'InnoDB']);
// A reinstall/upgrade never overwrites an existing setting or site identity.
// updateSettings intentionally omits absent zero values; use the native insert API.
$smcFunc['db_insert']('ignore', '{db_prefix}settings', ['variable'=>'string-255','value'=>'string-65534'], ['ffprotect_repeat_ban','0'], ['variable']);
reloadSettings();
$smcFunc['db_insert']('ignore', '{db_prefix}scheduled_tasks',
    ['next_time'=>'int','time_offset'=>'int','time_regularity'=>'int','time_unit'=>'string-1','disabled'=>'int','task'=>'string-24','callable'=>'string-60'],
    [time()+60,0,1,'m',0,'ffprotect_repeat_ban','scheduled_ffprotect_repeat_ban'], ['task']);
$smcFunc['db_query']('', 'UPDATE {db_prefix}scheduled_tasks SET time_regularity=1,time_unit={string:unit},callable={string:callable} WHERE task={string:task}', ['unit'=>'m','callable'=>'scheduled_ffprotect_repeat_ban','task'=>'ffprotect_repeat_ban']);
