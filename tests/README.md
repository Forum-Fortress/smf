# FfApiResilience contract tests

Run the shared resilience contract without booting the forum platform:

```sh
php tests/run_ff_api_resilience_contract.php
```

`FfApiResilienceContract.php` is the platform-independent behavioral contract
and is kept identical across the SMF, XenForo, phpBB, and Invision repositories.
The runner is the only adapter: it selects the platform file and declares any
temporary, audited expected failure.

The suite tests observable results instead of comparing production source
bytes, so namespaces, packaging paths, compatibility helpers, and legitimate
platform-only methods may differ. A shared-method behavior change fails its
named assertion. The contract revision and semantic digest also make it easy
to confirm that all four checkouts are running the same contract.

An unexpected pass is a failure: once a known regression is fixed, its runner
must remove the expected-failure entry so the behavior remains mandatory.
