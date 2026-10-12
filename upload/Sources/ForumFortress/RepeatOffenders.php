<?php
// Copyright (c) 2026 Marscastle Ltd trading as Forum Fortress
// SPDX-License-Identifier: GPL-2.0-or-later
namespace ForumFortress\Smf;

if (!defined('SMF')) { die('No direct access...'); }

/** Certified evidence is persisted at request shutdown; only the native task bans. */
class RepeatOffenders
{
    private static $staged = [];
    private static $armed = false;

    private static function q($sql, array $params = [])
    {
        global $smcFunc;
        $params['db_error_skip'] = true;
        $result = $smcFunc['db_query']('', $sql, $params);
        if ($result === false) { throw new \RuntimeException('Repeat enforcement SQL failed'); }
        return $result;
    }

    private static function rows($sql, array $params = [])
    {
        global $smcFunc;
        $result = self::q($sql, $params);
        $rows = [];
        while ($row = $smcFunc['db_fetch_assoc']($result)) { $rows[] = $row; }
        $smcFunc['db_free_result']($result);
        return $rows;
    }

    private static function idle()
    {
        global $db_type, $db_connection;
        if (!in_array($db_type, ['mysql', 'mysqli'], true) || !($db_connection instanceof \mysqli)) { return false; }
        try {
            $r = self::rows('SELECT @@autocommit AS a, @@in_transaction AS t');
            return (int) $r[0]['a'] === 1 && (int) $r[0]['t'] === 0;
        } catch (\Throwable $e) { return false; }
    }

    public static function available()
    {
        global $db_prefix, $sourcedir, $db_name;
        if (!self::idle() || !is_file($sourcedir . '/ManageBans.php') || !is_file($sourcedir . '/ForumFortressScheduled.php')) { return false; }
        try {
            $required = ['ffprotect_repeat_state', 'ffprotect_repeat_events', 'members', 'membergroups', 'permissions', 'board_permissions', 'moderators', 'moderator_groups', 'ban_groups', 'ban_items', 'settings', 'log_actions', 'log_online', 'scheduled_tasks'];
            $prefix = preg_match('~^(`?)(.+?)\\1\\.(.*?)$~', $db_prefix, $match) === 1 ? $match[3] : $db_prefix;
            $database = !empty($match[2]) ? $match[2] : $db_name;
            $engines = [];
            foreach (self::rows('SELECT TABLE_NAME AS name,ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA={string:database}', ['database'=>$database]) as $r) { $engines[$r['name']] = $r['engine']; }
            foreach ($required as $table) { if (($engines[$prefix . $table] ?? '') !== 'InnoDB') { return false; } }
            $task = self::rows('SELECT callable FROM {db_prefix}scheduled_tasks WHERE task = {string:task} AND disabled = 0', ['task' => 'ffprotect_repeat_ban']);
            return isset($task[0]) && $task[0]['callable'] === 'scheduled_ffprotect_repeat_ban';
        } catch (\Throwable $e) { return false; }
    }

    private static function tx($kind)
    {
        global $smcFunc;
        if (!$smcFunc['db_transaction']($kind)) { throw new \RuntimeException('Native transaction failed'); }
        if ($kind === 'begin' && (int) self::rows('SELECT @@in_transaction AS t')[0]['t'] !== 1) { throw new \RuntimeException('No owned transaction'); }
    }

    private static function rollback() { try { self::tx('rollback'); } catch (\Throwable $e) {} }
    private static function mutex()
    {
        self::q('INSERT INTO {db_prefix}ffprotect_repeat_state (member_id,pending,touched,attempted) VALUES (0,0,0,0) ON DUPLICATE KEY UPDATE member_id=VALUES(member_id)');
        self::q('SELECT member_id FROM {db_prefix}ffprotect_repeat_state WHERE member_id=0 FOR UPDATE');
    }
    private static function settings()
    {
        $s = [];
        foreach (self::rows('SELECT variable,value FROM {db_prefix}settings WHERE variable IN ({array_string:keys}) FOR UPDATE', ['keys' => ['ffprotect_enabled', 'ffprotect_repeat_ban']]) as $r) { $s[$r['variable']] = $r['value']; }
        return ($s['ffprotect_enabled'] ?? '') === '1' && ($s['ffprotect_repeat_ban'] ?? '') === '1';
    }

    /** Fresh database roles deliberately protect custom/secondary groups and any staff grant. */
    private static function member($id)
    {
        $rows = self::rows('SELECT * FROM {db_prefix}members WHERE id_member={int:id} FOR UPDATE', ['id' => $id]);
        $m = $rows[0] ?? null;
        if (!$m || $id <= 1 || trim($m['member_name']) === '' || trim($m['email_address']) === '' || (int) $m['is_activated'] !== 1 || (int) $m['id_group'] !== 0 || trim($m['additional_groups']) !== '' || (int) $m['posts'] > 10 || (int) $m['date_registered'] <= 0 || (int) $m['date_registered'] > time() || (int) $m['date_registered'] < time() - 604800 || (int) $m['warning'] > 0) { return null; }
        $groups = [0, (int) $m['id_post_group']];
        $g = self::rows('SELECT id_group,id_parent,min_posts FROM {db_prefix}membergroups WHERE id_group={int:g} FOR UPDATE', ['g' => $groups[1]]);
        if (!$g || (int) $g[0]['id_parent'] !== -2 || (int) $g[0]['min_posts'] < 0) { return null; }
        $privileged = ['admin_forum','moderate_forum','manage_membergroups','manage_permissions','manage_bans','manage_boards','approve_posts','remove_any','modify_any','lock_any','move_any','split_any','merge_any'];
        if (self::rows('SELECT id_group FROM {db_prefix}permissions WHERE id_group IN ({array_int:g}) AND permission IN ({array_string:p}) AND add_deny=1 FOR UPDATE', ['g' => $groups, 'p' => $privileged]) || self::rows('SELECT id_group FROM {db_prefix}board_permissions WHERE id_group IN ({array_int:g}) AND permission IN ({array_string:p}) AND add_deny=1 FOR UPDATE', ['g' => $groups, 'p' => $privileged]) || self::rows('SELECT id_member FROM {db_prefix}moderators WHERE id_member={int:id} FOR UPDATE', ['id' => $id]) || self::rows('SELECT id_group FROM {db_prefix}moderator_groups WHERE id_group IN ({array_int:g}) FOR UPDATE', ['g' => $groups])) { return null; }
        // Do not layer automatic bans on any existing member/email/IP restriction.
        if (self::rows('SELECT id_ban FROM {db_prefix}ban_items WHERE id_member={int:id} OR {string:email} LIKE email_address OR {raw:ip} BETWEEN ip_low AND ip_high OR {raw:ip2} BETWEEN ip_low AND ip_high OR hostname != {string:blank} FOR UPDATE', ['id' => $id, 'email' => $m['email_address'], 'ip' => "UNHEX('" . bin2hex($m['member_ip'] ?? '') . "')", 'ip2' => "UNHEX('" . bin2hex($m['member_ip2'] ?? '') . "')", 'blank' => ''])) { return null; }
        return $m;
    }

    public static function stage($id, $body, $response, callable $validated = null)
    {
        global $modSettings, $user_info;
        if (empty($modSettings['ffprotect_repeat_ban']) || empty($modSettings['ffprotect_enabled']) || $id <= 1 || $id !== (int) ($user_info['id'] ?? 0) || $body === '' || strtolower(trim((string) ($response['decision'] ?? ''))) !== 'block' || ($response['escalation_eligible'] ?? null) !== true || count(self::$staged) >= 16) { return; }
        $ref = $response['decision_reference'] ?? null;
        if (is_string($ref) && trim($ref) !== '' && strlen(trim($ref)) <= 256) { $ref = 'reference:' . trim($ref); }
        else {
            $ref = $response['decision_id'] ?? null;
            if (!(is_int($ref) || is_string($ref)) || !preg_match('/^[0-9]{1,32}$/', (string) $ref) || trim((string) $ref, '0') === '') { return; }
            $ref = 'decision:' . ltrim((string) $ref, '0');
        }
        self::$staged[] = [$id, hash('sha256', $body), hash('sha256', $ref), $validated];
        if (!self::$armed) { self::$armed = true; register_shutdown_function([self::class, 'flush']); }
    }
    public static function flush()
    {
        $staged = self::$staged; self::$staged = [];
        foreach ($staged as list($id,$body,$ref,$validated)) {
            try { if ($validated !== null && !$validated()) { continue; } } catch (\Throwable $e) { continue; }
            self::record($id,$body,$ref);
        }
    }
    private static function count($id)
    {
        return (int) self::rows('SELECT COUNT(*) AS n FROM {db_prefix}ffprotect_repeat_events WHERE member_id={int:id} AND consumed=0 AND observed BETWEEN {int:since} AND {int:now}', ['id' => $id, 'since' => time()-86400, 'now' => time()])[0]['n'];
    }
    private static function record($id,$body,$ref)
    {
        if (!self::available()) { return; }
        try {
            self::tx('begin'); self::mutex();
            if (!self::settings() || !self::member($id)) { self::tx('commit'); return; }
            $state = self::rows('SELECT * FROM {db_prefix}ffprotect_repeat_state WHERE member_id={int:id} FOR UPDATE', ['id' => $id]);
            if (!empty($state[0]['attempted'])) { self::tx('commit'); return; }
            if ((int) self::rows('SELECT COUNT(*) AS n FROM {db_prefix}ffprotect_repeat_events')[0]['n'] >= 50000 || (int) self::rows('SELECT COUNT(*) AS n FROM {db_prefix}ffprotect_repeat_state')[0]['n'] >= 100000 || (int) self::rows('SELECT COUNT(*) AS n FROM {db_prefix}ffprotect_repeat_events WHERE member_id={int:id}', ['id'=>$id])[0]['n'] >= 128) { self::tx('commit'); return; }
            self::q('INSERT INTO {db_prefix}ffprotect_repeat_state (member_id,pending,touched,attempted) VALUES ({int:id},0,{int:now},0) ON DUPLICATE KEY UPDATE touched=VALUES(touched)', ['id'=>$id,'now'=>time()]);
            self::q('INSERT IGNORE INTO {db_prefix}ffprotect_repeat_events (member_id,body_hash,ref_hash,observed,consumed) VALUES ({int:id},{string:body},{string:ref},{int:now},0)', ['id'=>$id,'body'=>$body,'ref'=>$ref,'now'=>time()]);
            if (self::count($id) >= 2) { self::q('UPDATE {db_prefix}ffprotect_repeat_state SET pending=1 WHERE member_id={int:id} AND attempted=0', ['id'=>$id]); }
            self::tx('commit');
        } catch (\Throwable $e) { self::rollback(); }
    }
    private static function consume($id)
    {
        self::q('UPDATE {db_prefix}ffprotect_repeat_events SET consumed=1 WHERE member_id={int:id}', ['id'=>$id]);
        self::q('UPDATE {db_prefix}ffprotect_repeat_state SET pending=0 WHERE member_id={int:id}', ['id'=>$id]);
    }
    public static function run()
    {
        if (!self::available()) { return; }
        try {
            foreach (self::rows('SELECT member_id FROM {db_prefix}ffprotect_repeat_state WHERE pending=1 ORDER BY touched LIMIT 50') as $r) { self::apply((int) $r['member_id']); }
            self::prune();
        } catch (\Throwable $e) { self::rollback(); }
    }
    private static function apply($id)
    {
        global $sourcedir, $context, $user_info;
        if (!self::available()) { return; }
        $oldContext = $context; $oldActor = $user_info;
        try {
            self::tx('begin'); self::mutex();
            $active = self::settings();
            $s = self::rows('SELECT * FROM {db_prefix}ffprotect_repeat_state WHERE member_id={int:id} FOR UPDATE', ['id'=>$id]);
            if (empty($s[0]['pending']) || !empty($s[0]['attempted'])) { self::tx('commit'); return; }
            $member = $active ? self::member($id) : null;
            if (!$member || self::count($id) < 2) { self::consume($id); self::tx('commit'); return; }
            require_once $sourcedir . '/ManageBans.php';
            require_once $sourcedir . '/Logging.php';
            $context['ban_errors'] = [];
            $user_info = ['id'=>0,'ip'=>'127.0.0.1','is_guest'=>true,'is_admin'=>false];
            $until = time()+604800;
            $ban = insertBanGroup(['name'=>'FF-' . $id,'db_expiration'=>$until,'cannot'=>['access'=>1,'post'=>0,'register'=>0,'login'=>0],'reason'=>'Repeated confirmed spam','notes'=>'Forum Fortress: two distinct certified original-score >80 BLOCKs within 24 hours.']);
            if (!$ban || !empty($context['ban_errors']) || !addTriggers($ban, ['user'=>['id_member'=>$id]], [['bantype'=>'user','value'=>$id]])) { throw new \RuntimeException('Native ban failed'); }
            updateSettings(['banLastUpdated'=>time()]);
            updateBanMembers();
            $b = self::rows('SELECT bg.expire_time FROM {db_prefix}ban_groups bg INNER JOIN {db_prefix}ban_items bi ON bi.id_ban_group=bg.id_ban_group WHERE bg.id_ban_group={int:ban} AND bi.id_member={int:id}', ['ban'=>$ban,'id'=>$id]);
            if (!$b || (int) $b[0]['expire_time'] !== $until) { throw new \RuntimeException('Native ban was not persisted'); }
            self::q('UPDATE {db_prefix}ffprotect_repeat_state SET attempted={int:now},touched={int:now} WHERE member_id={int:id} AND attempted=0', ['id'=>$id,'now'=>time()]);
            self::consume($id); self::tx('commit');
        } catch (\Throwable $e) { self::rollback(); }
        finally { $context = $oldContext; $user_info = $oldActor; }
    }
    public static function cancelPending()
    {
        if (!self::idle()) { return; }
        try { self::tx('begin'); self::mutex(); self::q('UPDATE {db_prefix}ffprotect_repeat_events SET consumed=1 WHERE consumed=0'); self::q('UPDATE {db_prefix}ffprotect_repeat_state SET pending=0 WHERE pending=1'); self::tx('commit'); }
        catch (\Throwable $e) { self::rollback(); }
    }
    private static function prune()
    {
        if (!self::idle()) { return; }
        try {
            self::tx('begin'); self::mutex();
            self::q('DELETE FROM {db_prefix}ffprotect_repeat_events WHERE observed<{int:old} ORDER BY observed LIMIT 500', ['old'=>time()-604800]);
            $ids = self::rows('SELECT s.member_id FROM {db_prefix}ffprotect_repeat_state s LEFT JOIN {db_prefix}members m ON m.id_member=s.member_id WHERE s.member_id>0 AND (m.id_member IS NULL OR (s.attempted=0 AND s.pending=0 AND s.touched<{int:old})) LIMIT 500', ['old'=>time()-604800]);
            foreach ($ids as $r) { self::q('DELETE FROM {db_prefix}ffprotect_repeat_events WHERE member_id={int:id}', ['id'=>$r['member_id']]); self::q('DELETE FROM {db_prefix}ffprotect_repeat_state WHERE member_id={int:id}', ['id'=>$r['member_id']]); }
            self::tx('commit');
        } catch (\Throwable $e) { self::rollback(); }
    }
    public static function recent()
    {
        try { return self::rows('SELECT member_id,attempted FROM {db_prefix}ffprotect_repeat_state WHERE attempted>0 ORDER BY attempted DESC LIMIT 15'); }
        catch (\Throwable $e) { return []; }
    }
}
