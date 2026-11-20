<?php
declare(strict_types=1);

require_once __DIR__.'/tasks.php';

const FARM_PREFIX = '/api/device/v1/';
const STATUS_SQL = "CASE WHEN revoked_at IS NOT NULL THEN 'revoked'
    WHEN last_seen_at IS NULL THEN 'registered'
    WHEN last_seen_at >= UTC_TIMESTAMP() - INTERVAL 150 SECOND THEN 'online'
    ELSE 'offline' END";

function database(): PDO
{
    return new PDO(getenv('FARM_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=poptracker_farm;charset=utf8mb4',
        getenv('FARM_DB_USER') ?: 'popfarm', getenv('FARM_DB_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED",
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
}

function query(PDO $db, string $sql, array $bindings = []): PDOStatement
{
    $statement = $db->prepare($sql);
    $statement->execute($bindings);
    return $statement;
}

final class ApiError extends RuntimeException {}
function reject(int $status, string $reason): never { throw new ApiError($reason, $status); }

function deviceId(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 15) | 64);
    $b[8] = chr((ord($b[8]) & 63) | 128);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function issue(PDO $db, string $label): array
{
    if (!preg_match('/\A[\pL\pN ._()-]{1,80}\z/u', $label)) {
        throw new InvalidArgumentException('Use a device label of 1–80 letters, digits, spaces or ._()-');
    }
    $id = deviceId();
    $code = strtoupper(bin2hex(random_bytes(8)));
    query($db, 'INSERT INTO enrollment_codes (device_id,display_name,code_hash,expires_at)
        VALUES (?,?,UNHEX(?),UTC_TIMESTAMP() + INTERVAL 10 MINUTE)', [$id, $label, hash('sha256', $code)]);
    return ['device_id' => $id, 'code' => $code];
}

function versionField(array $data, string $key): string
{
    $value = $data[$key] ?? null;
    if (!is_string($value) || !preg_match('/\A[0-9A-Za-z._+ -]{1,32}\z/', $value)) {
        reject(422, 'invalid_' . $key);
    }
    return $value;
}

function enroll(PDO $db, array $data): array
{
    $version = versionField($data, 'agent_version');
    $android = versionField($data, 'android_version');
    $code = $data['code'] ?? '';
    if (!is_string($code) || !preg_match('/\A[0-9A-F]{16}\z/i', $code)) {
        reject(403, 'invalid_enrollment');
    }
    $enrollment = query($db, 'SELECT device_id,display_name FROM enrollment_codes
        WHERE code_hash=UNHEX(?) AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()
        FOR UPDATE', [hash('sha256', strtoupper($code))])->fetch();
    if (!$enrollment) { reject(403, 'invalid_enrollment'); }
    $id = $enrollment['device_id'];
    $token = bin2hex(random_bytes(32));
    query($db, 'INSERT INTO device_agents (device_id,display_name,token_hash,agent_version,android_version,created_at)
        VALUES (?,?,UNHEX(?),?,?,UTC_TIMESTAMP())', [$id, $enrollment['display_name'], hash('sha256', $token), $version, $android]);
    query($db, 'UPDATE enrollment_codes SET used_at=UTC_TIMESTAMP() WHERE device_id=?', [$id]);
    return ['device_id' => $id, 'token' => $token];
}

function listDevices(PDO $db): array
{
    $rows = query($db,"SELECT d.device_id,d.display_name,d.manufacturer,d.model,d.android_version,d.agent_version,
        ".STATUS_SQL." AS status,d.last_seen_at,d.screen_reported_at,
        IF(d.screen_reported_at>=UTC_TIMESTAMP()-INTERVAL 150 SECOND,d.screen_on,NULL) AS screen_on,
        (SELECT h.battery_percent FROM device_heartbeats h WHERE h.device_id=d.device_id ORDER BY h.received_at DESC,h.id DESC LIMIT 1) AS battery_percent,
        (SELECT COUNT(*) FROM device_tasks t WHERE t.device_id=d.device_id AND t.status='succeeded') AS completed_tasks,
        (SELECT JSON_OBJECT('task_id',t.task_id,'type',t.type,'attempt_id',a.attempt_id,'assignment_mode',t.assignment_mode,
                    'phase',IF(a.lease_expires_at<=UTC_TIMESTAMP(),'awaiting_result','leased'))
         FROM device_tasks t JOIN device_task_attempts a USING(task_id)
         WHERE t.device_id=d.device_id AND t.status='leased' AND a.status='leased'
           AND COALESCE(a.deliver_until,a.lease_expires_at)>UTC_TIMESTAMP()
         ORDER BY t.created_at,t.task_id LIMIT 1) AS current_task,
        (SELECT JSON_OBJECT('task_id',t.task_id,'code',COALESCE(a.error_code,'LEASE_EXPIRED'),
                    'at',DATE_FORMAT(COALESCE(a.completed_at,a.deliver_until,a.lease_expires_at),'%Y-%m-%dT%H:%i:%sZ'))
         FROM device_tasks t JOIN device_task_attempts a USING(task_id)
         WHERE t.device_id=d.device_id AND (a.error_code IS NOT NULL OR
             (a.status='leased' AND COALESCE(a.deliver_until,a.lease_expires_at)<=UTC_TIMESTAMP()))
         ORDER BY COALESCE(a.completed_at,a.deliver_until,a.lease_expires_at) DESC,a.attempt_id DESC LIMIT 1) AS last_error
        FROM device_agents d ORDER BY d.created_at DESC,d.device_id")->fetchAll();
    foreach ($rows as &$row) {
        $row['screen_on'] = $row['status']==='online' && $row['screen_on']!==null ? (bool)$row['screen_on'] : null;
        $row['completed_tasks'] = (int)$row['completed_tasks'];
        $row['current_task'] = json_decode($row['current_task'] ?? 'null');
        $row['last_error'] = json_decode($row['last_error'] ?? 'null');
    }
    return $rows;
}

function operatorDevices(PDO $db, string $authorization): array
{
    $path = getenv('FARM_OPERATOR_HASH_FILE') ?: '/etc/poptracker-farm/operator.sha256';
    $expected = trim((string)@file_get_contents($path));
    if (!preg_match('/\ABearer ([0-9a-f]{64})\z/', $authorization, $match)
        || !hash_equals($expected, hash('sha256',$match[1]))) { reject(401,'unauthorized'); }
    return ['devices'=>listDevices($db)];
}

function dispatch(PDO $db, string $route, array $data, string $authorization): ?array
{
    if ($route === 'devices') { return operatorDevices($db,$authorization); }
    $db->beginTransaction();
    try {
        if ($route === 'enroll') {
            $result = enroll($db, $data);
        } else {
            if (!preg_match('/\ABearer ([a-f0-9]{64})\z/i', $authorization, $match)) {
                reject(401, 'unauthorized');
            }
            $row = query($db, 'SELECT device_id, '.STATUS_SQL.' AS status,
                DATE_FORMAT(last_seen_at, "%Y-%m-%dT%H:%i:%sZ") AS last_seen_at
                FROM device_agents WHERE token_hash=UNHEX(?) AND revoked_at IS NULL FOR UPDATE', [hash('sha256', $match[1])])->fetch();
            if (!$row) { reject(401, 'unauthorized'); }
            $result = $row;
            if ($route === 'tasks/claim') {
                $result = claimTask($db,$row['device_id'],$data,$row['status']==='online');
            } elseif (preg_match('~^tasks/('.UUID_PATTERN.')/result$~D',$route,$match)) {
                $result = acceptTaskResult($db,$row['device_id'],$match[1],$data);
            } elseif ($route === 'display') {
                exactFields($data,['device_id','screen_on']);
                if ($data['device_id'] !== $row['device_id']) { reject(403,'device_mismatch'); }
                if (!is_bool($data['screen_on'])) { reject(422,'invalid_screen_state'); }
                query($db,'UPDATE device_agents SET screen_on=?,screen_reported_at=UTC_TIMESTAMP() WHERE device_id=?',
                    [(int)$data['screen_on'],$row['device_id']]);
                $result = ['accepted'=>true];
            } elseif ($route === 'heartbeat') {
                if (($data['device_id'] ?? null) !== $row['device_id']) { reject(403, 'device_mismatch'); }
                $battery = $data['battery_percent'] ?? null;
                if ($battery !== null && (!is_int($battery) || $battery < 0 || $battery > 100)) { reject(422, 'invalid_battery'); }
                $sent = $data['sent_at'] ?? '';
                if (!is_string($sent) || !preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/', $sent)
                    || !date_create_immutable_from_format('!Y-m-d\TH:i:s\Z', $sent)
                    || DateTimeImmutable::getLastErrors() !== false) { reject(422, 'invalid_sent_at'); }
                $version = versionField($data, 'agent_version');
                $android = versionField($data, 'android_version');
                $screen = $data['screen_on'] ?? null;
                if ($screen !== null && !is_bool($screen)) { reject(422,'invalid_screen_state'); }
                query($db, 'UPDATE device_agents SET last_seen_at=UTC_TIMESTAMP(),agent_version=?,android_version=?,
                    screen_on=?,screen_reported_at=IF(?=1,UTC_TIMESTAMP(),NULL) WHERE device_id=?',
                    [$version,$android,$screen===null ? null : (int)$screen,(int)($screen!==null),$row['device_id']]);
                query($db, 'INSERT INTO device_heartbeats (device_id,received_at,battery_percent)
                    SELECT device_id,last_seen_at,? FROM device_agents WHERE device_id=?', [$battery, $row['device_id']]);
                $result = ['received_at' => query($db, 'SELECT DATE_FORMAT(last_seen_at, "%Y-%m-%dT%H:%i:%sZ")
                    FROM device_agents WHERE device_id=?', [$row['device_id']])->fetchColumn()];
            }
        }
        $db->commit();
        if ($result instanceof ApiError) { throw $result; }
        return $result;
    } catch (Throwable $error) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $error;
    }
}
