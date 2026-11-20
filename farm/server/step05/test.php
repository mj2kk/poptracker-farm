<?php
declare(strict_types=1);
require __DIR__.'/farm.php';
$db = database();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'poptracker_farm_test_step05' || !defined('TEST_REGION_TASK')) {
    throw new RuntimeException('Dedicated STEP05 schema and adapter required');
}

function assertCase(bool $passed, string $label): void
{
    if (!$passed) { throw new RuntimeException($label); }
    echo "PASS $label\n";
}

function expectError(callable $action, string $expected): void
{
    try { $action(); }
    catch (ApiError $error) {
        assertCase($error->getMessage() === $expected, $expected);
        return;
    }
    throw new RuntimeException("Missing error $expected");
}

$credentials = issue($db, 'STEP05 server fixture');
$agent = dispatch($db, 'enroll', ['code'=>$credentials['code'],'android_version'=>'13','agent_version'=>'step05-fixture'], '');
$id = $agent['device_id'];
$auth = 'Bearer '.$agent['token'];
$payload = (object)['author_uid'=>'test_author_001'];
$claim = ['device_id'=>$id,'agent_version'=>'step05-fixture','result_protocol'=>2,'capabilities'=>[TEST_REGION_TASK]];
expectError(fn()=>createTask($db,'any',TEST_REGION_TASK,$payload),'unsupported_test_task');
expectError(fn()=>createTask($db,$id,TEST_REGION_TASK,(object)['author_uid'=>'outside_fixture']),'invalid_test_author');
$taskId = createTask($db, $id, TEST_REGION_TASK, $payload);
$emptyCaps = array_merge($claim, ['capabilities'=>[]]);
expectError(fn()=>dispatch($db,'tasks/claim',$emptyCaps,$auth),'invalid_capabilities');
$job = dispatch($db,'tasks/claim',$claim,$auth);
assertCase($job['task_id'] === $taskId && $job['result_protocol'] === 2, 'targeted test task claimed');
$route = 'tasks/'.$taskId.'/result';
$result = (object)['request_id'=>$taskId,'status'=>'success','author_uid'=>$payload->author_uid,'region'=>'ZA','source'=>'synthetic_test_provider'];
$body = ['attempt_id'=>$job['attempt_id'],'lease_token'=>$job['lease_token'],'completed_at'=>gmdate('Y-m-d\TH:i:s\Z'),
    'execution_timing'=>(object)['start_ms'=>10,'finish_ms'=>30], 'outcome'=>'succeeded','error'=>null,'result'=>$result];
$altered = $body;
$altered['result'] = clone $result;
$altered['result']->request_id = deviceId();
expectError(fn()=>dispatch($db,$route,$altered,$auth),'test_correlation_mismatch');
$altered['result']->request_id = $taskId;
$altered['result']->source = 'external';
expectError(fn()=>dispatch($db,$route,$altered,$auth),'invalid_result');
$other = issue($db,'STEP05 isolated owner fixture');
$otherAgent = dispatch($db,'enroll',['code'=>$other['code'],'android_version'=>'13','agent_version'=>'step05-fixture'],'');
expectError(fn()=>dispatch($db,$route,$body,'Bearer '.$otherAgent['token']),'task_not_found');
$ack = dispatch($db,$route,$body,$auth);
$again = dispatch($db,$route,$body,$auth);
assertCase($ack['replayed'] === false && $again['replayed'] === true && $ack['received_at'] === $again['received_at'], 'immutable replay receipt');
expectError(fn()=>dispatch($db,$route,$altered,$auth),'result_conflict');
$attempt = query($db,'SELECT result_json,replay_count FROM device_task_attempts WHERE task_id=?',[$taskId])->fetchAll();
assertCase(count($attempt) === 1 && (int)$attempt[0]['replay_count'] === 1 && json_decode($attempt[0]['result_json']) == $result,'one stored synthetic result');
query($db,'UPDATE device_agents SET revoked_at=UTC_TIMESTAMP() WHERE device_id IN (?,?)',[$id,$otherAgent['device_id']]);
