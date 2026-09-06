<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/upload/Sources/ForumFortress/FfApiResilience.php';
require_once __DIR__ . '/FfApiResilienceContract.php';

exit(FfApiResilienceContract::run([
	FfApiResilienceContract::IDENTITYLESS_ROUTING_TEST,
]));
