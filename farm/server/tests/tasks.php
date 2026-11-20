<?php
declare(strict_types=1);
require dirname(__DIR__).'/farm.php';
$db = database();
if (!str_starts_with(query($db,'SELECT DATABASE()')->fetchColumn(),'poptracker_farm_test_')) { exit(2); }
if (($argv[1] ?? '') === '--claim') {
    [$device,$auth] = json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
    $reply = dispatch($db,'tasks/claim',['device_id'=>$device,'agent_version'=>'0.2.0'],$auth);
    echo json_encode($reply);
    exit;
}
function ok(bool $condition,string $label): void {
    if (!$condition) { throw new RuntimeException("FAILED: ".$label); }
    echo "PASS $label\n";
}
function rejects(int $status, callable $call): bool {
    try { $call(); } catch (ApiError $error) { return $error->getCode() === $status; }
    return false;
}
function fixture(PDO $db,string $label): array {
    $code = issue($db,$label);
    return dispatch($db,'enroll',['code'=>$code['code'],'android_version'=>'13','agent_version'=>'0.2.0'],'');
}
function candidate(PDO $db,array $agent): ?array {
    return dispatch($db,'tasks/claim',['device_id'=>$agent['device_id'],'agent_version'=>'0.2.0'],'Bearer '.$agent['token']);
}
$a=fixture($db,'Task A'); $b=fixture($db,'Task B'); $device=$a['device_id']; $bearer='Bearer '.$a['token'];
$empty=(object)[];
$task=createTask($db,$device,'PING',$empty);
$statusTask=createTask($db,$device,'GET_DEVICE_STATUS',$empty);
$sleepTask=createTask($db,$device,'EXECUTE_TEST_TASK',(object)['duration_ms'=>1000]);
ok($task!==$statusTask && $task!==$sleepTask,'create three types');
foreach ([['COMMAND',$empty],['PING',(object)['url'=>'x']],['EXECUTE_TEST_TASK',(object)['duration_ms'=>5001]],
    ['EXECUTE_TEST_TASK',(object)['duration_ms'=>'1000']],['PING',[]]] as [$type,$payload]) {
    ok(rejects(422,fn()=>createTask($db,$device,$type,$payload)),'reject invalid type or input');
}
ok(candidate($db,$b)===null,'B cannot receive A work; empty claim');
ok(rejects(403,fn()=>dispatch($db,'tasks/claim',['device_id'=>$device,'agent_version'=>'x'],'Bearer '.$b['token'])),'device mismatch');
ok(rejects(401,fn()=>dispatch($db,'tasks/claim',[],'Bearer '.str_repeat('0',64))),'invalid bearer claim');

// Hold the same device row until two independent PHP/MySQL clients are ready.
$db->beginTransaction();
query($db,'SELECT device_id FROM device_agents WHERE device_id=? FOR UPDATE',[$device]);
$workers=[];
for ($i=0;$i<2;$i++) {
    $proc=proc_open([PHP_BINARY,__FILE__,'--claim'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
    fwrite($pipes[0],json_encode([$device,$bearer])); fclose($pipes[0]);
    $workers[]=[$proc,$pipes];
}
usleep(200000);
$db->commit(); $winners=[];
foreach ($workers as [$proc,$pipes]) {
    $reply=stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($proc)!==0) { throw new RuntimeException('claim worker failed'); }
    $claim=json_decode($reply,true,flags:JSON_THROW_ON_ERROR);
    if ($claim!==null) { $winners[]=$claim; }
}
ok(count($winners)===1 && $winners[0]['task_id']===$task,'two concurrent claims, one winner; second queued task stays queued');
$claim=$winners[0]; $route='tasks/'.$task.'/result';
$body=['attempt_id'=>$claim['attempt_id'],'lease_token'=>$claim['lease_token'],'outcome'=>'succeeded',
    'result'=>(object)['pong'=>true,'executed_at'=>'2026-01-01T00:00:00Z'],'error'=>null,'completed_at'=>'2026-01-01T00:00:00Z'];
ok(rejects(401,fn()=>dispatch($db,$route,$body,'')),'invalid bearer result');
ok(rejects(404,fn()=>dispatch($db,$route,$body,'Bearer '.$b['token'])),'foreign result rejected');
ok(rejects(403,fn()=>dispatch($db,$route,['lease_token'=>str_repeat('0',64)]+$body,$bearer)),'wrong lease');
ok(rejects(403,fn()=>dispatch($db,$route,['attempt_id'=>deviceId()]+$body,$bearer)),'wrong attempt');
ok(rejects(422,fn()=>dispatch($db,$route,['result'=>(object)['pong'=>true,'extra'=>'x']]+$body,$bearer)),'strict result schema');
$ack=dispatch($db,$route,$body,$bearer);
ok($ack['outcome']==='succeeded' && $ack['result']->pong && !$ack['replayed'],'success persisted');
query($db,'UPDATE device_task_attempts SET lease_expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE attempt_id=?',[$claim['attempt_id']]);
$body['result']=(object)['executed_at'=>'2026-01-01T00:00:00Z','pong'=>true];
$again=dispatch($db,$route,$body,$bearer);
ok($again['replayed'] && $again['received_at']===$ack['received_at'] && query($db,'SELECT COUNT(*) FROM device_task_attempts WHERE task_id=?',[$task])->fetchColumn()==1,'lost reply replay after lease deadline returns original receipt');
ok(rejects(409,fn()=>dispatch($db,$route,['completed_at'=>'2026-01-01T00:00:01Z']+$body,$bearer)),'conflicting replay');
$next=candidate($db,$a);
ok($next['task_id']===$statusTask,'FIFO next task');
query($db,'UPDATE device_task_attempts SET lease_expires_at=UTC_TIMESTAMP() WHERE task_id=?',[$statusTask]);
$late=['attempt_id'=>$next['attempt_id'],'lease_token'=>$next['lease_token']]+$body;
ok(rejects(409,fn()=>dispatch($db,'tasks/'.$statusTask.'/result',$late,$bearer)),'late result rejected');
$shown=taskCommand($db,['farm','task:show',$statusTask]);
ok($shown['status']==='failed' && $shown['attempts'][0]['status']==='expired' && $shown['attempts'][0]['late_results']==1,'expired audit committed, no new attempt');
taskCommand($db,['farm','task:cancel',$sleepTask]);
ok(candidate($db,$a)===null,'cancelled task not claimable');
$failed=createTask($db,$device,'PING',$empty); $cl=candidate($db,$a);
$unknown=['attempt_id'=>$cl['attempt_id'],'lease_token'=>$cl['lease_token'],'outcome'=>'failed','result'=>null,
    'error'=>(object)['code'=>'EXECUTION_UNKNOWN','message'=>'Interrupted'],'completed_at'=>'2026-01-01T00:00:00Z'];
ok(dispatch($db,'tasks/'.$failed.'/result',$unknown,$bearer)['error']['code']==='EXECUTION_UNKNOWN','unknown execution reconciled as failed');
query($db,'UPDATE device_agents SET revoked_at=UTC_TIMESTAMP() WHERE device_id=?',[$device]);
ok(rejects(401,fn()=>candidate($db,$a)) && rejects(401,fn()=>dispatch($db,$route,$body,$bearer)),'revoked bearer rejected for both routes');
