<?php

declare(strict_types=1);
require dirname(__DIR__).'/production_worker.php';
if (PHP_SAPI !== 'cli' || $argc !== 1) {
    exit(1);
}
$db = database();
$until = microtime(true) + 15;
do {
    $worked = prodWorkerTick($db);
} while ($worked && microtime(true) < $until);
