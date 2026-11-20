<?php
declare(strict_types=1);

// The test pool has only a Unix-socket grant for this disposable schema.
$dsn = 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=poptracker_farm_test_step05;charset=utf8mb4';
if (getenv('FARM_DSN') !== $dsn || getenv('FARM_DB_USER') !== 'popfarmstep5') {
    http_response_code(503);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!is_string($path) || !preg_match('~^/step05/api/device/v1/(?:enroll|heartbeat|tasks/claim|tasks/[0-9a-f-]{36}/result)$~D', $path)) {
    http_response_code(404);
    exit;
}
$_SERVER['REQUEST_URI'] = substr($path, strlen('/step05'));
require __DIR__.'/public/index.php';
