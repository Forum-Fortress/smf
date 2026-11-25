<?php
// Copyright (c) 2026 Marscastle Ltd trading as Forum Fortress
// SPDX-License-Identifier: GPL-2.0-or-later

namespace ForumFortress\Proxy;

use InvalidArgumentException;

/** Shared visitor IP resolver, including MyBB installations on legacy PHP. */
final class ClientIp
{
    private static $serverKeys = array(
        'X-Forwarded-For' => 'HTTP_X_FORWARDED_FOR',
        'Forwarded' => 'HTTP_FORWARDED',
        'X-Real-IP' => 'HTTP_X_REAL_IP',
        'CF-Connecting-IP' => 'HTTP_CF_CONNECTING_IP',
        'True-Client-IP' => 'HTTP_TRUE_CLIENT_IP',
    );

    public static function resolve($native, array $server, $trustedProxies = '')
    {
        $native = trim($native);
        $peer = trim((string) (isset($server['REMOTE_ADDR']) ? $server['REMOTE_ADDR'] : ''));
        $fallback = self::packIp($native) !== null ? $native : (self::packIp($peer) !== null ? $peer : '');

        try {
            $ranges = self::parseTrustedProxies($trustedProxies);
        } catch (InvalidArgumentException $exception) {
            return $fallback;
        }

        if (self::packIp($native) !== null && !self::isLocalOrReserved($native) && !self::isTrusted($native, $ranges)) {
            return $native;
        }

        // Explicit proxy ranges take precedence over the local-peer default.
        if ($ranges === array()) {
            if (!self::isLocalOrReserved($peer)) {
                return $fallback;
            }
            $ranges = self::parseTrustedProxies($peer);
        } elseif (!self::isTrusted($peer, $ranges)) {
            return $fallback;
        }

        foreach (self::$serverKeys as $header => $serverKey) {
            $value = isset($server[$serverKey]) ? $server[$serverKey] : null;
            $client = self::resolveHeader($header, $value, $ranges);
            if ($client !== null) {
                return $client;
            }
        }

        return $fallback;
    }

    /** @param list<array{string, int}> $ranges */
    private static function resolveHeader($header, $value, array $ranges)
    {
        if (! is_string($value) || $value === '' || strlen($value) > 8192) {
            return null;
        }

        $isList = $header === 'Forwarded' || $header === 'X-Forwarded-For';
        $chain = array($value);
        if ($header === 'Forwarded') {
            $chain = self::forwardedNodes($value);
        } elseif ($isList) {
            $chain = explode(',', $value);
        }

        // A single-address header must be set by the proxy itself. List headers
        // give the order of the connection hops, oldest first.
        $client = null;
        foreach ($chain as $node) {
            $ip = $isList ? self::nodeIp($node) : (filter_var(trim($node, " \t"), FILTER_VALIDATE_IP) ?: null);
            if ($ip === null) {
                return null;
            }
            if (! self::isTrusted($ip, $ranges)) {
                $client = $ip;
            }
        }

        return $client;
    }

    /** @return list<array{string, int}> */
    public static function parseTrustedProxies($value)
    {
        if (trim($value) === '') {
            return array();
        }

        $ranges = array();
        foreach (explode(',', $value) as $entry) {
            list($address, $prefix) = array_pad(explode('/', trim($entry), 2), 2, null);
            $network = self::packIp(trim($address));
            if ($network === null) {
                throw new InvalidArgumentException('Enter comma-separated proxy IP addresses or CIDR ranges.');
            }
            $bits = strlen($network) * 8;
            if ($prefix !== null && (! ctype_digit($prefix) || (int) $prefix > $bits)) {
                throw new InvalidArgumentException('Enter valid CIDR prefixes. Use ranges that contain only trusted proxies.');
            }

            $ranges[] = array($network, $prefix === null ? $bits : (int) $prefix);
        }

        return $ranges;
    }

    /** @param list<array{string, int}> $ranges */
    private static function isTrusted($ip, array $ranges)
    {
        $packed = self::packIp($ip);
        if ($packed === null) {
            return false;
        }

        foreach ($ranges as $range) {
            list($network, $bits) = $range;
            $bytes = (int) ($bits / 8);
            $remaining = $bits % 8;
            if (strlen($packed) !== strlen($network) || substr($packed, 0, $bytes) !== substr($network, 0, $bytes)) {
                continue;
            }
            if ($remaining === 0 || (ord($packed[$bytes] ^ $network[$bytes]) >> (8 - $remaining)) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function packIp($ip)
    {
        return filter_var($ip, FILTER_VALIDATE_IP) === false ? null : inet_pton($ip);
    }

    private static function isLocalOrReserved($ip)
    {
        $packed = self::packIp($ip);
        if ($packed === null) {
            return false;
        }

        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            $ip = inet_ntop(substr($packed, 12));
        }

        if (defined('FILTER_FLAG_GLOBAL_RANGE')) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        // PHP versions before 8.2 lack the global-range filter.
        static $extraRanges = null;
        if ($extraRanges === null) {
            $extraRanges = self::parseTrustedProxies('100.64.0.0/10, 192.0.2.0/24, 198.18.0.0/15, 198.51.100.0/24, 203.0.113.0/24, 2001:db8::/32');
        }
        return self::isTrusted($ip, $extraRanges);
    }

    private static function nodeIp($node)
    {
        $node = trim($node, " \t");
        if (filter_var($node, FILTER_VALIDATE_IP) !== false) {
            return $node;
        }

        // Ports are allowed with IPv4 or bracketed IPv6. Bare IPv6 is handled
        // above so an address ending in digits is never mistaken for a port.
        if (preg_match('/\A\[([^\]]+)\](?::([0-9]{1,5}))?\z/', $node, $matches)) {
            $flag = FILTER_FLAG_IPV6;
        } elseif (preg_match('/\A([^:]+):([0-9]{1,5})\z/', $node, $matches)) {
            $flag = FILTER_FLAG_IPV4;
        } else {
            return null;
        }

        if (isset($matches[2]) && (int) $matches[2] > 65535) {
            return null;
        }

        return filter_var($matches[1], FILTER_VALIDATE_IP, $flag) ? $matches[1] : null;
    }

    /** @return list<string> An empty list signals a malformed header. */
    private static function forwardedNodes($value)
    {
        // Skip complete quoted strings when splitting Forwarded parameters.
        // Backslash escapes are rejected to avoid interpreting ambiguous boundaries.
        if (strpos($value, '\\') !== false || substr_count($value, '"') % 2 !== 0 || preg_match('/[^\x09\x20-\x7e]/', $value)) {
            return array();
        }

        $nodes = array();
        foreach (preg_split('/"[^"]*"(*SKIP)(*F)|,/', $value) as $element) {
            $node = null;
            foreach (preg_split('/"[^"]*"(*SKIP)(*F)|;/', $element) as $parameter) {
                if (! preg_match('/\A([a-z0-9_-]+)=(?:"([^"]*)"|([^"\s]+))\z/i', trim($parameter, " \t"), $match)) {
                    return array();
                }
                if (strcasecmp($match[1], 'for') !== 0) {
                    continue;
                }
                if ($node !== null) {
                    return array();
                }
                $node = isset($match[3]) ? $match[3] : $match[2];
            }
            if ($node === null) {
                return array();
            }
            $nodes[] = $node;
        }

        return $nodes;
    }
}
