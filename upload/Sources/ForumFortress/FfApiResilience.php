<?php
/**
 * Shared Forum Fortress API resilience helpers (bootstrap / catalog / hot failover).
 *
 * Copied into XenForo, phpBB, and Invision plugin trees on release; keep copies in sync.
 *
 * Manual verification matrix (when changing this file):
 * - control.ffapi.net down: bootstrap succeeds via api.ffapi.net or edge /v1/node-endpoints + edge bootstrap
 * - preferred edge down: check retries api.ffapi.net then next edge; catalog refresh second pass
 * - capabilities: control -> api.ffapi.net -> edge bases
 * - tier-3 edge with lower health_ms wins over tier-1 edge for preferred / request order
 * - offline ff_ob_* keys: checks pinned to issuer preferred_endpoint until control returns normal key
 * - POST /v1/check: edges first; control.ffapi.net only when catalog control_check_fallback,
 *   no eligible edge, or last retry after edge failure (see edgesHealthyForCheckTraffic)
 */
declare(strict_types=1);

final class FfApiResilience
{
	public const OFFLINE_TOKEN_PREFIX = 'ff_ob_';
	public const DEFAULT_API_REGION = 'global';
	public const GLOBAL_API_BASE_URL = 'https://api.ffapi.net';
	private const API_REGION_BASE_URLS = [
		'global' => self::GLOBAL_API_BASE_URL,
		'uk' => 'https://api-uk.ffapi.net',
		'eu' => 'https://api-eu.ffapi.net',
		'us' => 'https://api-us.ffapi.net',
	];

	public static function normaliseApiRegion(?string $region): string
	{
		$value = strtolower(trim((string) $region));
		return array_key_exists($value, self::API_REGION_BASE_URLS) ? $value : self::DEFAULT_API_REGION;
	}

	public static function apiBaseUrlForRegion(?string $region): string
	{
		return self::API_REGION_BASE_URLS[self::normaliseApiRegion($region)];
	}

	public static function apiRegionFromLegacyBaseUrl(?string $baseUrl): string
	{
		$normalised = strtolower(self::normaliseBaseUrl((string) $baseUrl));
		foreach (self::API_REGION_BASE_URLS as $region => $url)
		{
			if ($normalised === strtolower($url))
			{
				return $region;
			}
		}
		return self::DEFAULT_API_REGION;
	}

	/** @return list<string> */
	public static function regionLockedCheckBases(?string $region, bool $allowGlobalFallback): array
	{
		$region = self::normaliseApiRegion($region);
		$primary = self::apiBaseUrlForRegion($region);
		return self::uniqueOrderedBases(
			[$primary],
			$region !== self::DEFAULT_API_REGION && $allowGlobalFallback ? [self::GLOBAL_API_BASE_URL] : []
		);
	}

	public static function apiRegionIsLocked(?string $region): bool
	{
		return self::normaliseApiRegion($region) !== self::DEFAULT_API_REGION;
	}

	public static function isLocalDevelopmentBaseUrl(?string $baseUrl): bool
	{
		$host = strtolower((string) parse_url(self::normaliseBaseUrl((string) $baseUrl), PHP_URL_HOST));
		return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
	}

	/** Default TTL before GET /v1/node-endpoints is refreshed (4 hours). */
	public const ENDPOINT_CATALOG_TTL_SECONDS = 14400;

	/** Back off catalog discovery after failure to avoid hammering control. */
	public const ENDPOINT_CATALOG_REFRESH_BACKOFF_SECONDS = 300;

	public const ENDPOINT_CATALOG_FAILED_AT_KEY = 'catalog_refresh_failed_at';
	public const RUNTIME_CHECK_ENDPOINT_TIMEOUT_SECONDS = 1;
	public const RUNTIME_CHECK_TOTAL_BUDGET_SECONDS = 5;
	public const ENDPOINT_SUPPRESSION_SECONDS = 120;

	public static function normaliseBaseUrl(string $value): string
	{
		return rtrim(trim($value), '/');
	}

	public static function normaliseDomain(string $domain): string
	{
		$domain = strtolower(trim($domain));
		$domain = rtrim($domain, '.');
		if (str_starts_with($domain, 'www.'))
		{
			$domain = substr($domain, 4);
		}

		return $domain;
	}

	public static function isOfflineBootstrapKey(?string $apiKey, ?string $keyType = null): bool
	{
		if ($keyType === 'offline_bootstrap')
		{
			return true;
		}
		$apiKey = trim((string) $apiKey);

		return $apiKey !== '' && str_starts_with($apiKey, self::OFFLINE_TOKEN_PREFIX);
	}

	/**
	 * @param array<string, mixed> $bootstrapResponse
	 * @param array<string, mixed> $state
	 */
	public static function applyOfflineBootstrapRouting(array $bootstrapResponse, array &$state, string $usedBase): void
	{
		$keyType = isset($bootstrapResponse['key_type']) ? (string) $bootstrapResponse['key_type'] : '';
		$apiKey = isset($bootstrapResponse['api_key']) ? (string) $bootstrapResponse['api_key'] : '';
		if (!self::isOfflineBootstrapKey($apiKey, $keyType !== '' ? $keyType : null))
		{
			unset(
				$state['offline_pinned'],
				$state['issuer_node_id'],
				$state['offline_preferred_endpoint'],
				$state['offline_rebootstrap_at'],
				$state['offline_canonical_domain'],
				$state['fallback_bootstrap_endpoints']
			);

			return;
		}

		$preferred = self::normaliseBaseUrl((string) ($bootstrapResponse['preferred_endpoint'] ?? $usedBase));
		$rebootstrapAfter = (int) ($bootstrapResponse['rebootstrap_after_seconds'] ?? 600);
		if ($rebootstrapAfter < 60)
		{
			$rebootstrapAfter = 600;
		}
		$jitter = random_int(0, min(120, (int) floor($rebootstrapAfter / 4)));

		$state['offline_pinned'] = true;
		$state['issuer_node_id'] = (string) ($bootstrapResponse['issuer_node_id'] ?? '');
		$state['offline_preferred_endpoint'] = $preferred;
		$state['offline_canonical_domain'] = (string) ($bootstrapResponse['canonical_domain'] ?? '');
		$state['offline_rebootstrap_at'] = time() + $rebootstrapAfter + $jitter;
		$fallback = $bootstrapResponse['fallback_bootstrap_endpoints'] ?? null;
		$state['fallback_bootstrap_endpoints'] = is_array($fallback)
			? array_values(array_filter(array_map(
				static fn ($u) => self::normaliseBaseUrl((string) $u),
				$fallback
			)))
			: [];
	}

	/**
	 * @param array<string, mixed> $state
	 * @return list<string>
	 */
	public static function offlinePinnedCheckBases(array $state): array
	{
		if (empty($state['offline_pinned']))
		{
			return [];
		}
		$preferred = self::normaliseBaseUrl((string) ($state['offline_preferred_endpoint'] ?? ''));
		if ($preferred === '')
		{
			return [];
		}

		return [$preferred];
	}

	/**
	 * @param array<string, mixed> $state
	 * @return list<string>
	 */
	public static function offlineRebootstrapBases(array $state, string $controlBase, string $apiBase, array $edgeBases, string $manualBase): array
	{
		$fallback = is_array($state['fallback_bootstrap_endpoints'] ?? null)
			? $state['fallback_bootstrap_endpoints']
			: [];

		return self::uniqueOrderedBases(
			$fallback,
			self::bootstrapBasesOrdered($controlBase, $apiBase, $manualBase, $edgeBases)
		);
	}

	public static function shouldRebootstrapOfflineNow(array $state): bool
	{
		if (empty($state['offline_pinned']))
		{
			return false;
		}
		$at = (int) ($state['offline_rebootstrap_at'] ?? 0);

		return $at > 0 && time() >= $at;
	}

	/**
	 * @param array<string, mixed>|null $decodedBody
	 */
	public static function isNodeMismatchResponse(?array $decodedBody): bool
	{
		if (!is_array($decodedBody))
		{
			return false;
		}
		$candidates = [];
		if (isset($decodedBody['error']))
		{
			$candidates[] = strtolower(trim((string) $decodedBody['error']));
		}
		$detail = $decodedBody['detail'] ?? null;
		if (is_array($detail) && isset($detail['error']))
		{
			$candidates[] = strtolower(trim((string) $detail['error']));
		}

		return in_array('node_mismatch', $candidates, true);
	}

	public static function normaliseTrafficTier(mixed $raw): int
	{
		if (!is_int($raw) && !is_float($raw) && !is_string($raw))
		{
			return 1;
		}
		$tier = (int) $raw;
		if ($tier < 1)
		{
			return 1;
		}
		if ($tier > 3)
		{
			return 3;
		}

		return $tier;
	}

	public static function isHealthyLatency(mixed $ms): bool
	{
		return is_int($ms) && $ms >= 0;
	}

	/**
	 * @param array<string, array<string, mixed>> $endpointMeta
	 */
	public static function endpointMetaForBase(array $endpointMeta, string $base): array
	{
		return isset($endpointMeta[$base]) && is_array($endpointMeta[$base]) ? $endpointMeta[$base] : [];
	}

	/**
	 * Whether a base may serve /v1/check* after catalog + local health probes.
	 *
	 * @param array<string, array<string, mixed>> $endpointMeta
	 */
	/**
	 * True when at least one non-backup base can serve /v1/check* (mirrors endpoint eligibility).
	 *
	 * @param array<string, int|null> $healthMs
	 * @param array<string, array<string, mixed>> $endpointMeta
	 * @param callable(string, ?string): bool $isBackupEndpoint
	 */
	public static function edgesHealthyForCheckTraffic(
		array $healthMs,
		array $endpointMeta,
		callable $isBackupEndpoint
	): bool {
		foreach ($healthMs as $base => $ms)
		{
			$base = self::normaliseBaseUrl((string) $base);
			if ($base === '')
			{
				continue;
			}
			$meta = self::endpointMetaForBase($endpointMeta, $base);
			$role = isset($meta['role']) ? (string) $meta['role'] : null;
			if ($isBackupEndpoint($base, $role))
			{
				continue;
			}
			$health = is_int($ms) ? $ms : null;
			if (self::endpointEligibleForCheckTraffic($endpointMeta, $base, $health))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Keep control.ffapi.net last on check paths so edges are always tried first.
	 *
	 * @param list<string> $bases
	 * @return list<string>
	 */
	public static function orderCheckBasesControlLast(array $bases, string $controlBase): array
	{
		$controlBase = self::normaliseBaseUrl($controlBase);
		if ($controlBase === '')
		{
			return $bases;
		}
		$rest = [];
		$control = null;
		foreach ($bases as $base)
		{
			$base = self::normaliseBaseUrl((string) $base);
			if ($base === '')
			{
				continue;
			}
			if ($base === $controlBase)
			{
				$control = $base;
				continue;
			}
			if (!in_array($base, $rest, true))
			{
				$rest[] = $base;
			}
		}
		if ($control !== null)
		{
			$rest[] = $control;
		}

		return $rest;
	}

	public static function endpointEligibleForCheckTraffic(
		array $endpointMeta,
		string $baseUrl,
		?int $healthMs
	): bool {
		$baseUrl = self::normaliseBaseUrl($baseUrl);
		if ($baseUrl === '')
		{
			return false;
		}
		$meta = self::endpointMetaForBase($endpointMeta, $baseUrl);
		if (!array_key_exists('check_ready', $meta))
		{
			return true;
		}
		if (!empty($meta['check_ready']))
		{
			return true;
		}

		return self::isHealthyLatency($healthMs);
	}

	/**
	 * @param array<string, int|null> $healthMs
	 * @param array<string, array<string, mixed>> $endpointMeta
	 * @param callable(string, ?string): bool $isBackupEndpoint
	 */
	public static function lowestLatencyHealthyBase(
		array $bases,
		array $healthMs,
		array $endpointMeta,
		callable $isBackupEndpoint,
		bool $requireCheckReady,
		string $fallback
	): string {
		$fallback = self::normaliseBaseUrl($fallback);
		$best = $fallback;
		$bestMs = PHP_INT_MAX;
		foreach ($bases as $base)
		{
			$base = self::normaliseBaseUrl((string) $base);
			if ($base === '')
			{
				continue;
			}
			$ms = $healthMs[$base] ?? null;
			if (!self::isHealthyLatency($ms))
			{
				continue;
			}
			$meta = self::endpointMetaForBase($endpointMeta, $base);
			$role = isset($meta['role']) ? (string) $meta['role'] : null;
			if ($isBackupEndpoint($base, $role))
			{
				continue;
			}
			// Stale catalog check_ready=false must not hide a live healthy probe (e.g. tier-2 edge).
			if (
				$requireCheckReady
				&& array_key_exists('check_ready', $meta)
				&& empty($meta['check_ready'])
				&& !self::isHealthyLatency($ms)
			)
			{
				continue;
			}
			if ($ms < $bestMs)
			{
				$bestMs = (int) $ms;
				$best = $base;
			}
		}

		return $best !== '' ? $best : $fallback;
	}

	/**
	 * @param list<string> $bases
	 * @param array<string, int|null> $healthMs
	 * @param array<string, array<string, mixed>> $endpointMeta
	 * @param callable(string, ?string): bool $isBackupEndpoint
	 * @return list<string>
	 */
	public static function sortBasesByHealthyLatency(
		array $bases,
		array $healthMs,
		array $endpointMeta,
		callable $isBackupEndpoint
	): array {
		$normalised = [];
		foreach ($bases as $base)
		{
			$base = self::normaliseBaseUrl((string) $base);
			if ($base !== '' && !in_array($base, $normalised, true))
			{
				$normalised[] = $base;
			}
		}
		usort($normalised, function ($left, $right) use ($healthMs, $endpointMeta, $isBackupEndpoint) {
			$leftMs = $healthMs[$left] ?? null;
			$rightMs = $healthMs[$right] ?? null;
			$leftMeta = self::endpointMetaForBase($endpointMeta, $left);
			$rightMeta = self::endpointMetaForBase($endpointMeta, $right);
			$leftHealthy = self::isHealthyLatency($leftMs);
			$rightHealthy = self::isHealthyLatency($rightMs);
			if ($leftHealthy && $rightHealthy)
			{
				$leftRole = isset($leftMeta['role']) ? (string) $leftMeta['role'] : null;
				$rightRole = isset($rightMeta['role']) ? (string) $rightMeta['role'] : null;
				$leftBackup = $isBackupEndpoint($left, $leftRole) ? 1 : 0;
				$rightBackup = $isBackupEndpoint($right, $rightRole) ? 1 : 0;
				if ($leftBackup !== $rightBackup)
				{
					return $leftBackup <=> $rightBackup;
				}
				if ((int) $leftMs !== (int) $rightMs)
				{
					return (int) $leftMs <=> (int) $rightMs;
				}
			}
			elseif ($leftHealthy)
			{
				return -1;
			}
			elseif ($rightHealthy)
			{
				return 1;
			}
			$leftRole = isset($leftMeta['role']) ? (string) $leftMeta['role'] : null;
			$rightRole = isset($rightMeta['role']) ? (string) $rightMeta['role'] : null;
			$leftBackup = $isBackupEndpoint($left, $leftRole) ? 1 : 0;
			$rightBackup = $isBackupEndpoint($right, $rightRole) ? 1 : 0;
			if ($leftBackup !== $rightBackup)
			{
				return $leftBackup <=> $rightBackup;
			}

			return strcmp($left, $right);
		});

		return $normalised;
	}

	/**
	 * @param list<string> $bases
	 * @param array<string, int|null> $healthMs
	 * @param array<string, array<string, mixed>> $endpointMeta
	 * @param callable(string, ?string): bool $isBackupEndpoint
	 */
	public static function resolvePreferredHealthyBase(
		array $bases,
		array $healthMs,
		array $endpointMeta,
		callable $isBackupEndpoint,
		string $fallback
	): string {
		$checkReady = self::lowestLatencyHealthyBase(
			$bases,
			$healthMs,
			$endpointMeta,
			$isBackupEndpoint,
			true,
			''
		);
		if ($checkReady !== '')
		{
			return $checkReady;
		}

		return self::lowestLatencyHealthyBase(
			$bases,
			$healthMs,
			$endpointMeta,
			$isBackupEndpoint,
			false,
			$fallback
		);
	}

	public static function hotFailoverApiBaseUrl(string $manualBase, string $controlBase): string
	{
		$manualBase = self::normaliseBaseUrl($manualBase);
		if ($manualBase !== '')
		{
			$host = parse_url($manualBase, PHP_URL_HOST);
			if (is_string($host) && strpos(strtolower($host), 'api.') === 0)
			{
				return $manualBase;
			}
		}
		$controlBase = self::normaliseBaseUrl($controlBase);
		if ($controlBase !== '')
		{
			$host = parse_url($controlBase, PHP_URL_HOST);
			if (is_string($host) && strpos(strtolower($host), 'control.') === 0 && strpos($host, '.') !== false)
			{
				return self::normaliseBaseUrl('https://api.' . substr($host, 8));
			}
		}

		return 'https://api.ffapi.net';
	}

	/**
	 * @param list<list<string>> $lists
	 * @return list<string>
	 */
	public static function uniqueOrderedBases(array ...$lists): array
	{
		$out = [];
		foreach ($lists as $list)
		{
			foreach ($list as $base)
			{
				$base = self::normaliseBaseUrl((string) $base);
				if ($base !== '' && !in_array($base, $out, true))
				{
					$out[] = $base;
				}
			}
		}

		return $out;
	}

	/**
	 * Bootstrap order: control, hot api, cached edges, manual fallback.
	 *
	 * @param list<string> $edgeBases
	 * @return list<string>
	 */
	public static function bootstrapBasesOrdered(
		string $controlBase,
		string $apiBase,
		string $manualBase,
		array $edgeBases
	): array {
		$controlBase = self::normaliseBaseUrl($controlBase);
		$apiBase = self::normaliseBaseUrl($apiBase);
		$manualBase = self::normaliseBaseUrl($manualBase);

		return self::uniqueOrderedBases(
			$controlBase !== '' ? [$controlBase] : [],
			($apiBase !== '' && $apiBase !== $controlBase) ? [$apiBase] : [],
			$edgeBases,
			($manualBase !== '' && $manualBase !== $controlBase && $manualBase !== $apiBase) ? [$manualBase] : []
		);
	}

	/**
	 * Catalog fetch order: control, hot api, cached edges (edges may proxy discovery to control).
	 *
	 * @param list<string> $edgeBases
	 * @return list<string>
	 */
	public static function catalogFetchBases(string $controlBase, string $apiBase, array $edgeBases): array
	{
		$controlBase = self::normaliseBaseUrl($controlBase);
		$apiBase = self::normaliseBaseUrl($apiBase);

		return self::uniqueOrderedBases(
			$controlBase !== '' ? [$controlBase] : [],
			($apiBase !== '' && $apiBase !== $controlBase) ? [$apiBase] : [],
			$edgeBases
		);
	}

	/**
	 * Whether cached node-endpoint list should be refetched (catalog_fetched_at / empty list).
	 *
	 * @param array<string, mixed> $state Plugin endpoint state blob
	 */
	public static function isEndpointCatalogStale(array $state, ?int $ttlSeconds = null): bool
	{
		$ttl = $ttlSeconds ?? self::ENDPOINT_CATALOG_TTL_SECONDS;
		$fetchedAt = (int) ($state['catalog_fetched_at'] ?? 0);
		$endpoints = $state['endpoints'] ?? null;
		if ($fetchedAt <= 0 || !is_array($endpoints) || $endpoints === [])
		{
			return true;
		}

		return (time() - $fetchedAt) > $ttl;
	}

	/**
	 * @param array<string, mixed> $state
	 */
	public static function shouldBackoffEndpointCatalogRefresh(array $state, ?int $now = null): bool
	{
		$now = $now ?? time();
		$failedAt = (int) ($state[self::ENDPOINT_CATALOG_FAILED_AT_KEY] ?? 0);
		if ($failedAt <= 0)
		{
			return false;
		}

		return ($now - $failedAt) < self::ENDPOINT_CATALOG_REFRESH_BACKOFF_SECONDS;
	}

	/**
	 * @param array<string, mixed> $state
	 */
	public static function noteEndpointCatalogRefreshFailure(array &$state, ?int $now = null): void
	{
		$state[self::ENDPOINT_CATALOG_FAILED_AT_KEY] = $now ?? time();
	}

	/**
	 * @param array<string, mixed> $state
	 */
	public static function noteEndpointCatalogRefreshSuccess(array &$state): void
	{
		unset($state[self::ENDPOINT_CATALOG_FAILED_AT_KEY]);
	}

	/**
	 * After successful check-in / site sync, optionally refresh discovery catalog when stale.
	 */
	public static function shouldRefreshEndpointCatalogOnCheckIn(?string $requestPath): bool
	{
		if (!is_string($requestPath) || $requestPath === '')
		{
			return false;
		}
		foreach (
			[
				'/v1/check',
				'/v1/site/ping',
				'/v1/site/status',
				'/v1/site/register',
				'/v1/site/bootstrap',
			] as $prefix
		)
		{
			if ($requestPath === $prefix || str_starts_with($requestPath, $prefix . '/'))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Moderation sync must use api.ffapi.net then control only (never edge hostnames).
	 *
	 * @return list<string>
	 */
	public static function moderationSyncBasesOrdered(string $apiBase, string $controlBase): array
	{
		$apiBase = self::normaliseBaseUrl($apiBase);
		$controlBase = self::normaliseBaseUrl($controlBase);

		return self::uniqueOrderedBases(
			$apiBase !== '' ? [$apiBase] : [],
			($controlBase !== '' && $controlBase !== $apiBase) ? [$controlBase] : []
		);
	}

	public static function isStrictSupernodeSyncPath(?string $requestPath): bool
	{
		if (!is_string($requestPath) || $requestPath === '')
		{
			return false;
		}
		foreach (['/v1/moderation-queue/', '/v1/moderation-actions/'] as $prefix)
		{
			if (str_starts_with($requestPath, $prefix))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Read-only plugin calls that should prefer healthy edges over control/api when reachable.
	 */
	public static function isEdgePreferredReadPath(?string $requestPath): bool
	{
		if (!is_string($requestPath) || $requestPath === '')
		{
			return false;
		}
		foreach (
			[
				'/v1/forum/stats',
				'/v1/site/portal',
			] as $path
		)
		{
			if ($requestPath === $path)
			{
				return true;
			}
		}

		return false;
	}

	public static function shouldFailoverOnIntermittentStatus(int $status, ?string $requestPath): bool
	{
		if (!in_array($status, [401, 404], true))
		{
			return false;
		}

		return self::isEdgePreferredReadPath($requestPath)
			|| self::isStrictSupernodeSyncPath($requestPath)
			|| $requestPath === '/v1/plugin-release';
	}

	public static function shouldFailoverOnEndpointStatus(int $status): bool
	{
		return in_array($status, [500, 502, 503, 504], true);
	}

	public static function shouldStopEndpointFailoverForStatus(int $status): bool
	{
		return $status >= 400 && $status < 500 && !self::shouldFailoverOnEndpointStatus($status);
	}

	/**
	 * Prefer edge bases for read-only plugin calls when reachable (not moderation sync).
	 *
	 * @param list<string> $bases
	 * @param callable(string, ?string): bool $isBackupEndpoint
	 * @return list<string>
	 */
	public static function filterOrderedBasesForReachableEdges(
		array $bases,
		callable $isBackupEndpoint,
		string $controlBase,
		string $hotApiBase
	): array {
		$controlBase = self::normaliseBaseUrl($controlBase);
		$hotApiBase = self::normaliseBaseUrl($hotApiBase);
		$filtered = [];
		foreach ($bases as $base)
		{
			$base = self::normaliseBaseUrl((string) $base);
			if ($base === '')
			{
				continue;
			}
			if ($controlBase !== '' && $base === $controlBase)
			{
				continue;
			}
			if ($hotApiBase !== '' && $base === $hotApiBase)
			{
				continue;
			}
			if ($isBackupEndpoint($base, null))
			{
				continue;
			}
			$filtered[] = $base;
		}

		return $filtered !== [] ? $filtered : $bases;
	}

	/**
	 * Background site sync and moderation should not fan out across edge hostnames.
	 */
	public static function isControlPlanePreferredPath(?string $requestPath): bool
	{
		if (!is_string($requestPath) || $requestPath === '')
		{
			return false;
		}
		foreach (
			[
				'/v1/site/ping',
				'/v1/site/status',
				'/v1/site/register',
				'/v1/site/attack-mode',
				'/v1/site/attack-mode/end',
			] as $path
		)
		{
			if ($requestPath === $path)
			{
				return true;
			}
		}

		return false;
	}

	/** Minimum HTTP timeout for contact-form checks (slow enrichment path). */
	public const CONTACT_PAGE_MIN_TIMEOUT_SECONDS = 6;

	/** Maximum HTTP timeout for contact-form checks. */
	public const CONTACT_PAGE_MAX_TIMEOUT_SECONDS = 12;

	/** At most one regional edge plus api.ffapi.net per contact_page attempt cycle. */
	public const CONTACT_PAGE_FAILOVER_MAX_BASES = 2;

	/** Throttle repeated timeout warnings in forum error logs. */
	public const API_TIMEOUT_LOG_THROTTLE_SECONDS = 300;

	/** Log transient background failures only after this many consecutive errors. */
	public const CONSECUTIVE_TRANSIENT_LOG_THRESHOLD = 3;

	public static function isContactPageCheckPath(?string $requestPath): bool
	{
		if (!is_string($requestPath) || $requestPath === '')
		{
			return false;
		}

		return $requestPath === '/v1/check/contact_page'
			|| str_starts_with($requestPath, '/v1/check/contact_page');
	}

	public static function isContactPageUnifiedCheckPayload(array $payload): bool
	{
		return strtolower(trim((string) ($payload['check_endpoint'] ?? ''))) === 'contact_page';
	}

	public static function shouldUseContactPageRouting(?string $requestPath, array $payload): bool
	{
		return self::isContactPageCheckPath($requestPath)
			|| ($requestPath === '/v1/check' && self::isContactPageUnifiedCheckPayload($payload));
	}

	public static function contactPageCheckTimeoutSeconds(int $configuredTimeoutSeconds): int
	{
		$configured = max(1, $configuredTimeoutSeconds);
		$scaled = $configured * 2;

		return max(
			self::CONTACT_PAGE_MIN_TIMEOUT_SECONDS,
			min(self::CONTACT_PAGE_MAX_TIMEOUT_SECONDS, $scaled)
		);
	}

	public static function shouldSuppressCheckTimeoutException(?string $requestPath): bool
	{
		return self::isContactPageCheckPath($requestPath);
	}

	public static function isTransientNetworkMessage(?string $message): bool
	{
		if (!is_string($message) || $message === '')
		{
			return false;
		}
		$lower = strtolower($message);

		return str_contains($lower, 'curl error 28')
			|| str_contains($lower, 'timed out')
			|| str_contains($lower, 'timeout')
			|| str_contains($lower, 'curl error 6')
			|| str_contains($lower, 'curl error 7')
			|| str_contains($lower, 'could not resolve host')
			|| str_contains($lower, 'failed to connect')
			|| str_contains($lower, 'connection refused')
			|| str_contains($lower, 'network is unreachable');
	}

	/**
	 * Route contact_page through api.ffapi.net first, then at most one catalog edge.
	 * Avoids serial 3s timeouts across every unhealthy edge hostname.
	 *
	 * @param list<string> $orderedBases
	 * @return list<string>
	 */
	public static function contactPageCheckBasesOrdered(
		array $orderedBases,
		string $hotApiBase,
		int $maxBases = self::CONTACT_PAGE_FAILOVER_MAX_BASES
	): array {
		$hotApiBase = self::normaliseBaseUrl($hotApiBase);
		$out = [];
		if ($hotApiBase !== '')
		{
			$out[] = $hotApiBase;
		}
		foreach ($orderedBases as $base)
		{
			$base = self::normaliseBaseUrl((string) $base);
			if ($base === '' || $base === $hotApiBase)
			{
				continue;
			}
			$out[] = $base;
		}
		$out = self::uniqueOrderedBases($out);
		$maxBases = max(1, $maxBases);

		return array_slice($out, 0, $maxBases);
	}

	/**
	 * @param array<string, mixed> $state
	 */
	public static function shouldLogThrottledApiFailure(
		array &$state,
		string $throttleKey,
		int $intervalSeconds = self::API_TIMEOUT_LOG_THROTTLE_SECONDS,
		?int $now = null
	): bool {
		$now = $now ?? time();
		$key = 'log_throttle_' . preg_replace('/[^a-z0-9_]+/i', '_', strtolower($throttleKey));
		$last = (int) ($state[$key] ?? 0);
		if ($last > 0 && ($now - $last) < max(60, $intervalSeconds))
		{
			return false;
		}
		$state[$key] = $now;

		return true;
	}

	/**
	 * Require N consecutive transient failures before logging; reset streak on success.
	 *
	 * @param array<string, mixed> $state
	 */
	public static function shouldLogConsecutiveTransientFailure(
		array &$state,
		string $failureKey,
		bool $failed,
		int $threshold = self::CONSECUTIVE_TRANSIENT_LOG_THRESHOLD
	): bool {
		$threshold = max(1, $threshold);
		$key = 'fail_streak_' . preg_replace('/[^a-z0-9_]+/i', '_', strtolower($failureKey));
		if (!$failed)
		{
			unset($state[$key]);

			return false;
		}
		$streak = (int) ($state[$key] ?? 0) + 1;
		$state[$key] = $streak;
		if ($streak < $threshold)
		{
			return false;
		}

		return self::shouldLogThrottledApiFailure($state, $failureKey);
	}
}
