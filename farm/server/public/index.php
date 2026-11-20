<?php
declare(strict_types=1);
require dirname(__DIR__).'/farm.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $methods = [FARM_PREFIX.'enroll'=>'POST', FARM_PREFIX.'heartbeat'=>'POST', FARM_PREFIX.'display'=>'POST', FARM_PREFIX.'me'=>'GET',FARM_PREFIX.'devices'=>'GET',FARM_PREFIX.'tasks/claim'=>'POST'];
    $isResult = preg_match('~^'.FARM_PREFIX.'tasks/'.UUID_PATTERN.'/result$~D', $path) === 1;
    if ($isResult) { $methods[$path]='POST'; }
    if (!isset($methods[$path])) { reject(404, 'not_found'); }
    if ($_SERVER['REQUEST_METHOD'] !== $methods[$path]) {
        header('Allow: '.$methods[$path]);
        reject(405, 'method_not_allowed');
    }
    $data = [];
    if ($methods[$path] === 'POST') {
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
            reject(415, 'expected_json');
        }
        $limit = $isResult ? COLLECTION_MAX_BYTES : 4096;
        $body = file_get_contents('php://input', false, null, 0, $limit + 1);
        if (strlen($body) > $limit) { reject(413, 'body_too_large'); }
        $obj = json_decode($body, false, 8);
        if (!is_object($obj)) { reject(400, 'invalid_json'); }
        if (strlen($body)>4096 && !isset($obj->result->records)) { reject(413,'body_too_large'); }
        $data = (array)$obj;
    }
    $route = substr($path, strlen(FARM_PREFIX));
    $response = dispatch(database(), $route, $data, $_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($response === null) { http_response_code(204); exit; }
    http_response_code($route === 'enroll' ? 201 : 200);
} catch (ApiError $error) {
    http_response_code($error->getCode());
    $response = ['error' => $error->getMessage()];
} catch (Throwable $error) {
    error_log('Farm API failed: '.get_class($error).' ('.$error->getCode().')');
    http_response_code(500);
    $response = ['error' => 'server_error'];
}
echo json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
