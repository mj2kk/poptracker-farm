<?php
declare(strict_types=1);
require dirname(__DIR__).'/farm.php';

// Set FARM_DSN to a disposable migrated database owned by the test runner.
$db = database();
if (!str_starts_with(query($db, 'SELECT DATABASE()')->fetchColumn(), 'poptracker_farm_test_')) {
    throw new RuntimeException('A dedicated poptracker_farm_test_* database is required.');
}
function checkThat(bool $ok, string $name): void {
    if (!$ok) { throw new RuntimeException($name); }
    echo "PASS $name\n";
}
function denied(int $expected, callable $call): bool {
    try { $call(); } catch (ApiError $e) { return $e->getCode() === $expected; }
    return false;
}
$base = ['android_version'=>'13','agent_version'=>'0.1.0'];
$issued = issue($db, 'API test');
$payload = ['code'=>$issued['code']] + $base;
$identity = dispatch($db, 'enroll', $payload, '');
checkThat($identity['device_id'] === $issued['device_id'], 'valid enrollment');
checkThat(denied(403, fn() => dispatch($db, 'enroll', $payload, '')), 'code is single use');
checkThat(denied(403, fn() => dispatch($db, 'enroll', ['code'=>str_repeat('0',16)] + $base, '')), 'bad code');
$expired = issue($db, 'Expired');
query($db, 'UPDATE enrollment_codes SET expires_at=UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE device_id=?', [$expired['device_id']]);
checkThat(denied(403, fn() => dispatch($db, 'enroll', ['code'=>$expired['code']] + $base, '')), 'expired code');
$auth = 'Bearer '.$identity['token'];
$id = $identity['device_id'];
$beat = $base + ['device_id'=>$id,'battery_percent'=>50,'sent_at'=>'2001-01-01T00:00:00Z'];
checkThat(dispatch($db, 'me', [], $auth)['status'] === 'registered', 'status before first heartbeat');
checkThat(denied(401, fn() => dispatch($db, 'heartbeat', $beat, '')), 'missing token');
checkThat(denied(403, fn() => dispatch($db, 'heartbeat', ['device_id'=>deviceId()] + $beat, $auth)), 'identity mismatch');
checkThat(denied(422, fn() => dispatch($db, 'heartbeat', ['battery_percent'=>999] + $beat, $auth)), 'invalid battery');
checkThat(denied(422, fn() => dispatch($db, 'heartbeat', ['sent_at'=>'2026-02-31T00:00:00Z'] + $beat, $auth)), 'invalid timestamp');
$result = dispatch($db, 'heartbeat', $beat, $auth);
$me = dispatch($db, 'me', [], $auth);
checkThat($me['last_seen_at'] === $result['received_at'] && $me['last_seen_at'] !== $beat['sent_at'] && $me['status']==='online', 'server time and online status');
checkThat((int)query($db,'SELECT COUNT(*) FROM device_heartbeats WHERE device_id=?', [$id])->fetchColumn() === 1, 'one accepted heartbeat');
query($db,'UPDATE device_agents SET last_seen_at=UTC_TIMESTAMP() - INTERVAL 151 SECOND WHERE device_id=?', [$id]);
checkThat(dispatch($db,'me',[],$auth)['status']==='offline', 'offline threshold');
dispatch($db,'heartbeat',$beat,$auth);
checkThat(dispatch($db,'me',[],$auth)['status']==='online', 'recovery');
query($db,'UPDATE device_agents SET revoked_at=UTC_TIMESTAMP() WHERE device_id=?', [$id]);
checkThat(denied(401, fn() => dispatch($db, 'heartbeat', $beat, $auth)), 'revoked token');
