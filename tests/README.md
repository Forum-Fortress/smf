# FfApiResilience contract tests

Run the shared resilience contract without booting SMF:

```sh
php tests/run_ff_api_resilience_contract.php
```

`FfApiResilienceContract.php` is identical across the SMF, XenForo, phpBB,
and Invision sources. The test checks observable shared behavior instead of
source bytes, allowing legitimate namespace and platform adapter differences.
