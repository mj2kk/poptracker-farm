<?php
declare(strict_types=1);
require dirname(__DIR__).'/farm.php';
$db=database();
if (!str_starts_with(query($db,'SELECT DATABASE()')->fetchColumn(),'poptracker_farm_test_')) { exit(2); }
function checkDelivery(bool $test,string $name): void {
    if (!$test) { throw new RuntimeException("FAILED ".$name); }
    echo "PASS $name\n";
}
function reason(callable $call): string {
    try { $call(); return 'accepted'; } catch (ApiError $e) { return $e->getCode().'/'.$e->getMessage(); }
}
$enroll=issue($db,'Delivery fixture');
$a=dispatch($db,'enroll',['code'=>$enroll['code'],'android_version'=>'13','agent_version'=>'0.3.2'],'');
$id=$a['device_id']; $auth='Bearer '.$a['token'];
$new=function() use ($db,$id,$auth) {
    $t=createTask($db,$id,'PING',(object)[]);
    $j=dispatch($db,'tasks/claim',['device_id'=>$id,'agent_version'=>'0.3.2','result_protocol'=>2],$auth);
    if ($j['task_id']!==$t || $j['result_protocol']!==2) { throw new RuntimeException('unexpected claim'); }
    $stamp=gmdate('Y-m-d\TH:i:s\Z');
    return [$j,'tasks/'.$t.'/result',['attempt_id'=>$j['attempt_id'],'lease_token'=>$j['lease_token'],'completed_at'=>$stamp,
        'outcome'=>'succeeded','error'=>null,'result'=>(object)['pong'=>true,'executed_at'=>$stamp],
        'execution_timing'=>(object)['start_ms'=>50,'finish_ms'=>400]]];
};
$age=function($j,int $seconds) use ($db) {
    query($db,'UPDATE device_task_attempts SET leased_at=UTC_TIMESTAMP()-INTERVAL ? SECOND,
        lease_expires_at=UTC_TIMESTAMP()+INTERVAL ? SECOND,deliver_until=UTC_TIMESTAMP()+INTERVAL ? SECOND WHERE attempt_id=?',
        [$seconds,120-$seconds,1020-$seconds,$j['attempt_id']]);
};
foreach ([130,300] as $seconds) {
    [$j,$route,$body]=$new(); $age($j,$seconds);
    $before=taskCommand($db,['farm','task:show',$j['task_id']]);
    checkDelivery($before['attempts'][0]['delivery_phase']==='awaiting_result',"waiting after $seconds seconds");
    $ack=dispatch($db,$route,$body,$auth);
    checkDelivery(!$ack['replayed'] && $ack['outcome']==='succeeded',"first delivery accepted after $seconds seconds");
    $age($j,1200);
    $again=dispatch($db,$route,$body,$auth);
    checkDelivery($again['replayed'] && $again['received_at']===$ack['received_at'],'lost response after commit returns immutable receipt');
    $changed=$body; $changed['execution_timing']=(object)['start_ms'=>51,'finish_ms'=>400];
    checkDelivery(reason(fn()=>dispatch($db,$route,$changed,$auth))==='409/result_conflict','conflicting timing cannot overwrite receipt');
}
[$j,$route,$body]=$new(); $age($j,150);
foreach ([['start_ms'=>20001,'finish_ms'=>21000],['start_ms'=>100,'finish_ms'=>120001]] as $bad) {
    $wrong=['execution_timing'=>(object)$bad]+$body;
    checkDelivery(reason(fn()=>dispatch($db,$route,$wrong,$auth))==='409/execution_window','outside execution bounds rejected');
}
checkDelivery(reason(fn()=>dispatch($db,$route,['execution_timing'=>null]+$body,$auth))==='422/invalid_timing','success requires timing');
$age($j,1020);
checkDelivery(reason(fn()=>dispatch($db,$route,$body,$auth))==='409/delivery_expired','exclusive admission boundary');
checkDelivery(taskCommand($db,['farm','task:show',$j['task_id']])['status']==='failed','expired delivery finalizes task');
[$j,$route,$body]=$new();
$newer=deviceId();
query($db,"INSERT INTO device_task_attempts (attempt_id,task_id,attempt_no,lease_token_hash,leased_at,lease_expires_at,status)
    VALUES (?,?,2,UNHEX(SHA2('fixture',256)),UTC_TIMESTAMP(),UTC_TIMESTAMP()+INTERVAL 120 SECOND,'leased')",[$newer,$j['task_id']]);
$age($j,1100);
expireTaskLeases($db,$id);
checkDelivery(reason(fn()=>dispatch($db,$route,$body,$auth))==='409/attempt_superseded','old assignment fenced');
checkDelivery(query($db,'SELECT status FROM device_tasks WHERE task_id=?',[$j['task_id']])->fetchColumn()==='leased'
    && query($db,'SELECT status FROM device_task_attempts WHERE attempt_id=?',[$newer])->fetchColumn()==='leased','new attempt unchanged');
query($db,'UPDATE device_agents SET revoked_at=UTC_TIMESTAMP() WHERE device_id=?',[$id]);
checkDelivery(reason(fn()=>dispatch($db,$route,$body,$auth))==='401/unauthorized','revoked bearer cannot deliver');
