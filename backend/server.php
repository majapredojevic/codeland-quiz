<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

use CodeLandQuiz\Application;
use OpenSwoole\Runtime;

require __DIR__ . '/vendor/autoload.php';

Runtime::enableCoroutine(
    true,
    Runtime::HOOK_ALL,
);

(new Application())->run();
