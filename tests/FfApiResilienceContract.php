<?php

declare(strict_types=1);

/**
 * Platform-free behavioral contract for the shared FfApiResilience helper.
 *
 * Keep this file identical in every plugin repository. Platform runners should
 * contain only the implementation path and any temporary expected failures.
 */
final class FfApiResilienceContract
{
	private const REVISION = '2026-09-06.1';
	public const IDENTITYLESS_ROUTING_TEST = 'identityless response preserves offline routing';

	/**
	 * @param list<string> $knownFailures
	 */
	public static function run(array $knownFailures = []): int
	{
		$tests = self::tests();
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
		echo "Contract revision: " . self::REVISION . "\n";
		echo "Semantic contract digest: {$digest}\n";
		echo "Result: {$passes} passed, {$expectedFailures} expected failure(s), {$failures} failure(s)\n";

		return $failures === 0 ? 0 : 1;
	}

	/**
	 * @return array<string, callable(): void>
	 */
	private static function tests(): array
	{
		return [
			'healthy latency selects the fastest live base' => static function (): void {
				$selected = FfApiResilience::lowestLatencyHealthyBase(
					['https://edge-a.example', 'https://edge-b.example', 'https://edge-c.example'],
					[
						'https://edge-a.example' => 45,
						'https://edge-b.example' => 11,
						'https://edge-c.example' => 27,
					],
					[],
					self::neverBackup(),
					false,
					'https://fallback.example'
				);
				self::assertSame('https://edge-b.example', $selected);
			},

			'unhealthy and null latencies are rejected' => static function (): void {
				foreach ([null, -1, '12', 12.5, false] as $latency)
				{
					self::assertFalse(FfApiResilience::isHealthyLatency($latency));
				}
				self::assertTrue(FfApiResilience::isHealthyLatency(0));
				self::assertSame(
					'https://fallback.example',
					FfApiResilience::lowestLatencyHealthyBase(
						['https://edge-a.example', 'https://edge-b.example'],
						['https://edge-a.example' => null, 'https://edge-b.example' => -1],
						[],
						self::neverBackup(),
						false,
						'https://fallback.example'
					)
				);
			},

			'check_ready metadata follows the live-probe contract' => static function (): void {
				$base = 'https://edge-a.example';
				self::assertTrue(FfApiResilience::endpointEligibleForCheckTraffic([], $base, null));
				self::assertTrue(FfApiResilience::endpointEligibleForCheckTraffic(
					[$base => ['check_ready' => true]],
					$base,
					null
				));
				self::assertFalse(FfApiResilience::endpointEligibleForCheckTraffic(
					[$base => ['check_ready' => false]],
					$base,
					null
				));
				self::assertTrue(FfApiResilience::endpointEligibleForCheckTraffic(
					[$base => ['check_ready' => false]],
					$base,
					23
				));
			},

			'current healthy probe overrides stale check_ready false' => static function (): void {
				$bases = ['https://edge-a.example', 'https://edge-b.example'];
				$health = ['https://edge-a.example' => 8, 'https://edge-b.example' => 31];
				$meta = [
					'https://edge-a.example' => ['check_ready' => false],
					'https://edge-b.example' => ['check_ready' => true],
				];
				$withRequirement = FfApiResilience::lowestLatencyHealthyBase(
					$bases,
					$health,
					$meta,
					self::neverBackup(),
					true,
					'https://fallback.example'
				);
				$withoutRequirement = FfApiResilience::lowestLatencyHealthyBase(
					$bases,
					$health,
					$meta,
					self::neverBackup(),
					false,
					'https://fallback.example'
				);
				self::assertSame('https://edge-a.example', $withRequirement);
				self::assertSame($withoutRequirement, $withRequirement);
			},

			'active base stays synchronized with healthy latency ordering' => static function (): void {
				$bases = [
					'https://edge-a.example',
					'https://backup.example',
					'https://edge-b.example',
				];
				$health = [
					'https://edge-a.example' => 41,
					'https://backup.example' => 2,
					'https://edge-b.example' => 14,
				];
				$meta = ['https://backup.example' => ['role' => 'backup']];
				$isBackup = self::roleBackup();
				$ordered = FfApiResilience::sortBasesByHealthyLatency($bases, $health, $meta, $isBackup);
				$active = FfApiResilience::resolvePreferredHealthyBase(
					$bases,
					$health,
					$meta,
					$isBackup,
					'https://fallback.example'
				);
				self::assertSame('https://edge-b.example', $ordered[0]);
				self::assertSame($ordered[0], $active);
			},

			'route lock limits regional check bases' => static function (): void {
				self::assertFalse(FfApiResilience::apiRegionIsLocked('global'));
				self::assertTrue(FfApiResilience::apiRegionIsLocked('eu'));
				self::assertSame(
					['https://api-eu.ffapi.net'],
					FfApiResilience::regionLockedCheckBases('eu', false)
				);
				self::assertSame(
					['https://api-eu.ffapi.net', 'https://api.ffapi.net'],
					FfApiResilience::regionLockedCheckBases('eu', true)
				);
			},

			'offline bootstrap response pins issuer routing' => static function (): void {
				$state = ['unrelated' => 'keep'];
				$before = time();
				FfApiResilience::applyOfflineBootstrapRouting(
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

			'normal complete bootstrap clears remembered offline routing' => static function (): void {
				$state = self::rememberedOfflineState();
				FfApiResilience::applyOfflineBootstrapRouting(
					['api_key' => 'ff_live_key', 'key_type' => 'standard', 'site_id' => 'site-1'],
					$state,
					'https://control.example'
				);
				self::assertSame(['unrelated' => 'keep'], $state);
			},

			self::IDENTITYLESS_ROUTING_TEST => static function (): void {
				$state = self::rememberedOfflineState();
				$before = $state;
				FfApiResilience::applyOfflineBootstrapRouting(
					['decision' => 'allow', 'request_id' => 'check-1'],
					$state,
					'https://edge-b.example'
				);
				self::assertSame($before, $state);
			},

			'stale offline state keeps its pin and deterministic rebootstrap routes' => static function (): void {
				$state = self::rememberedOfflineState();
				$state['offline_preferred_endpoint'] = 'https://edge-a.example/';
				$state['offline_rebootstrap_at'] = time() - 1;
				$state['fallback_bootstrap_endpoints'] = [
					'https://edge-b.example',
					'https://control.example',
				];
				self::assertSame(
					['https://edge-a.example'],
					FfApiResilience::offlinePinnedCheckBases($state)
				);
				self::assertTrue(FfApiResilience::shouldRebootstrapOfflineNow($state));
				$state['offline_rebootstrap_at'] = time() + 600;
				self::assertFalse(FfApiResilience::shouldRebootstrapOfflineNow($state));
				self::assertSame(
					[
						'https://edge-b.example',
						'https://control.example',
						'https://api.example',
						'https://edge-a.example',
						'https://manual.example',
					],
					FfApiResilience::offlineRebootstrapBases(
						$state,
						'https://control.example',
						'https://api.example',
						['https://edge-a.example', 'https://edge-b.example'],
						'https://manual.example'
					)
				);
				self::assertSame([], FfApiResilience::offlinePinnedCheckBases([
					'offline_pinned' => false,
					'offline_preferred_endpoint' => 'https://edge-a.example',
				]));
			},
		];
	}

	/** @return callable(string, ?string): bool */
	private static function neverBackup(): callable
	{
		return static function (string $base, ?string $role): bool {
			return false;
		};
	}

	/** @return callable(string, ?string): bool */
	private static function roleBackup(): callable
	{
		return static function (string $base, ?string $role): bool {
			return $role === 'backup';
		};
	}

	/** @return array<string, mixed> */
	private static function rememberedOfflineState(): array
	{
		return [
			'offline_pinned' => true,
			'issuer_node_id' => 'edge-old',
			'offline_preferred_endpoint' => 'https://edge-old.example',
			'offline_rebootstrap_at' => 1,
			'offline_canonical_domain' => 'forum.example',
			'fallback_bootstrap_endpoints' => ['https://fallback-old.example'],
			'unrelated' => 'keep',
		];
	}

	/** @param mixed $expected @param mixed $actual */
	private static function assertSame($expected, $actual): void
	{
		if ($expected !== $actual)
		{
			throw new RuntimeException(
				'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
			);
		}
	}

	private static function assertTrue(bool $actual): void
	{
		self::assertSame(true, $actual);
	}

	private static function assertFalse(bool $actual): void
	{
		self::assertSame(false, $actual);
	}
}
