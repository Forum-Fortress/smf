<?php

declare(strict_types=1);

/**
 * Platform-free behavioral contract for the shared FfApiResilience helper.
 *
 * Keep this file identical in every plugin test tree. The runner is the only
 * platform adapter: it loads the implementation and supplies its class name.
 */
final class FfApiResilienceContract
{
	private const REVISION = '2026-09-06.2';
	public const IDENTITYLESS_ROUTING_TEST = 'identityless response preserves offline routing';

	/** @param list<string> $knownFailures */
	public static function run(string $implementationClass, array $knownFailures = []): int
	{
		if (!class_exists($implementationClass))
		{
			fwrite(STDERR, "Missing resilience class: {$implementationClass}\n");
			return 1;
		}

		$tests = self::tests($implementationClass);
		$failures = 0;
		$passes = 0;
		$expectedFailures = 0;
		foreach ($knownFailures as $name)
		{
			if (!array_key_exists($name, $tests))
			{
				fwrite(STDERR, "ERROR unknown expected failure: {$name}\n");
				$failures++;
			}
		}
		foreach ($tests as $name => $test)
		{
			$isKnownFailure = in_array($name, $knownFailures, true);
			try
			{
				$test();
				if ($isKnownFailure)
				{
					fwrite(STDERR, "XPASS {$name} (remove it from the runner's expected-failure list)\n");
					$failures++;
					continue;
				}
				echo "PASS  {$name}\n";
				$passes++;
			}
			catch (Throwable $error)
			{
				if ($isKnownFailure)
				{
					echo "XFAIL {$name}: {$error->getMessage()}\n";
					$expectedFailures++;
					continue;
				}
				fwrite(STDERR, "FAIL  {$name}: {$error->getMessage()}\n");
				$failures++;
			}
		}

		$digest = hash('sha256', self::REVISION . "\0" . implode("\0", array_keys($tests)));
		echo 'Contract revision: ' . self::REVISION . "\n";
		echo "Semantic contract digest: {$digest}\n";
		echo "Result: {$passes} passed, {$expectedFailures} expected failure(s), {$failures} failure(s)\n";

		return $failures === 0 ? 0 : 1;
	}

	/** @return array<string, callable(): void> */
	private static function tests(string $class): array
	{
		return [
			'legacy latency promotion remains retired' => static function () use ($class): void {
				foreach ([
					'isHealthyLatency',
					'lowestLatencyHealthyBase',
					'sortBasesByHealthyLatency',
					'resolvePreferredHealthyBase',
				] as $method)
				{
					self::assertFalse(method_exists($class, $method));
				}
			},

			'GeoDNS base remains first without active-base promotion' => static function () use ($class): void {
				self::assertSame(
					[
						'https://api.ffapi.net',
						'https://edge-a.example',
						'https://edge-b.example',
					],
					$class::uniqueOrderedBases(
						['https://api.ffapi.net'],
						['https://edge-a.example', 'https://edge-b.example', 'https://api.ffapi.net']
					)
				);
			},

			'endpoint metadata preserves check_ready state' => static function () use ($class): void {
				$base = 'https://edge-a.example';
				self::assertSame(
					['check_ready' => false, 'role' => 'edge'],
					$class::endpointMetaForBase(
						[$base => ['check_ready' => false, 'role' => 'edge']],
						$base
					)
				);
				self::assertSame([], $class::endpointMetaForBase([], $base));
			},

			'route lock limits regional check bases' => static function () use ($class): void {
				self::assertFalse($class::apiRegionIsLocked('global'));
				self::assertTrue($class::apiRegionIsLocked('eu'));
				self::assertSame(
					['https://api-eu.ffapi.net'],
					$class::regionLockedCheckBases('eu', false)
				);
				self::assertSame(
					['https://api-eu.ffapi.net', 'https://api.ffapi.net'],
					$class::regionLockedCheckBases('eu', true)
				);
			},

			'control route stays last on check failover' => static function () use ($class): void {
				self::assertSame(
					[
						'https://api.ffapi.net',
						'https://edge-a.example',
						'https://fortress.ffapi.net',
					],
					$class::orderCheckBasesControlLast(
						[
							'https://fortress.ffapi.net',
							'https://api.ffapi.net',
							'https://edge-a.example',
							'https://api.ffapi.net',
						],
						'https://fortress.ffapi.net'
					)
				);
			},

			'offline bootstrap pins issuer routing' => static function () use ($class): void {
				$state = ['unrelated' => 'keep'];
				$before = time();
				$class::applyOfflineBootstrapRouting(
					[
						'api_key' => 'ff_ob_temporary',
						'key_type' => 'offline_bootstrap',
						'issuer_node_id' => 'edge-a',
						'preferred_endpoint' => 'https://edge-a.example/',
						'canonical_domain' => 'forum.example',
						'rebootstrap_after_seconds' => 600,
						'fallback_bootstrap_endpoints' => [
							'https://edge-b.example/',
							'',
							'https://control.example',
						],
					],
					$state,
					'https://used.example'
				);
				$after = time();
				self::assertTrue(!empty($state['offline_pinned']));
				self::assertSame('edge-a', $state['issuer_node_id']);
				self::assertSame('https://edge-a.example', $state['offline_preferred_endpoint']);
				self::assertSame('forum.example', $state['offline_canonical_domain']);
				self::assertSame(
					['https://edge-b.example', 'https://control.example'],
					$state['fallback_bootstrap_endpoints']
				);
				self::assertTrue($state['offline_rebootstrap_at'] >= $before + 600);
				self::assertTrue($state['offline_rebootstrap_at'] <= $after + 720);
				self::assertSame('keep', $state['unrelated']);
			},

			'normal complete bootstrap clears offline routing' => static function () use ($class): void {
				$state = self::rememberedOfflineState();
				$class::applyOfflineBootstrapRouting(
					['api_key' => 'ff_live_key', 'key_type' => 'standard', 'site_id' => 'site-1'],
					$state,
					'https://control.example'
				);
				self::assertSame(['unrelated' => 'keep'], $state);
			},

			self::IDENTITYLESS_ROUTING_TEST => static function () use ($class): void {
				$state = self::rememberedOfflineState();
				$before = $state;
				$class::applyOfflineBootstrapRouting(
					['decision' => 'allow', 'request_id' => 'check-1'],
					$state,
					'https://edge-b.example'
				);
				self::assertSame($before, $state);
			},

			'stale offline state keeps pin and deterministic rebootstrap routes' => static function () use ($class): void {
				$state = self::rememberedOfflineState();
				$state['offline_preferred_endpoint'] = 'https://edge-a.example/';
				$state['offline_rebootstrap_at'] = time() - 1;
				$state['fallback_bootstrap_endpoints'] = [
					'https://edge-b.example',
					'https://control.example',
				];
				self::assertSame(
					['https://edge-a.example'],
					$class::offlinePinnedCheckBases($state)
				);
				self::assertTrue($class::shouldRebootstrapOfflineNow($state));
				self::assertSame(
					[
						'https://edge-b.example',
						'https://control.example',
						'https://api.example',
						'https://edge-c.example',
					],
					$class::offlineRebootstrapBases(
						$state,
						'https://control.example',
						'https://api.example',
						['https://edge-c.example'],
						'https://api.example'
					)
				);
			},

			'bootstrap order is control then GeoDNS then catalog edges' => static function () use ($class): void {
				self::assertSame(
					[
						'https://fortress.ffapi.net',
						'https://api.ffapi.net',
						'https://edge-a.example',
					],
					$class::bootstrapBasesOrdered(
						'https://fortress.ffapi.net',
						'https://api.ffapi.net',
						'https://api.ffapi.net',
						['https://edge-a.example']
					)
				);
			},

			'stale and empty catalogs require refresh' => static function () use ($class): void {
				self::assertTrue($class::isEndpointCatalogStale([]));
				self::assertFalse($class::isEndpointCatalogStale([
					'catalog_fetched_at' => time(),
					'endpoints' => ['https://edge-a.example'],
				]));
				self::assertTrue($class::isEndpointCatalogStale([
					'catalog_fetched_at' => time() - 301,
					'endpoints' => ['https://edge-a.example'],
				], 300));
			},

			'invalid routing bases are rejected' => static function () use ($class): void {
				self::assertSame('', $class::normaliseBaseUrl('http://api.ffapi.net'));
				self::assertSame('', $class::normaliseBaseUrl('https://user:pass@api.ffapi.net'));
				self::assertSame('', $class::normaliseBaseUrl('https://api.ffapi.net/?token=secret'));
				self::assertSame('https://api.ffapi.net', $class::normaliseBaseUrl('https://api.ffapi.net/'));
			},
		];
	}

	/** @return array<string, mixed> */
	private static function rememberedOfflineState(): array
	{
		return [
			'offline_pinned' => true,
			'issuer_node_id' => 'edge-a',
			'offline_preferred_endpoint' => 'https://edge-a.example',
			'offline_rebootstrap_at' => time() + 600,
			'offline_canonical_domain' => 'forum.example',
			'fallback_bootstrap_endpoints' => ['https://control.example'],
			'unrelated' => 'keep',
		];
	}

	private static function assertTrue(bool $condition): void
	{
		if (!$condition)
		{
			throw new RuntimeException('expected true');
		}
	}

	private static function assertFalse(bool $condition): void
	{
		if ($condition)
		{
			throw new RuntimeException('expected false');
		}
	}

	private static function assertSame($expected, $actual): void
	{
		if ($expected !== $actual)
		{
			throw new RuntimeException(
				'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
			);
		}
	}
}
