<?php
declare(strict_types=1);
require dirname(__DIR__).'/farm.php';
$db=database();
if (!str_starts_with(query($db,'SELECT DATABASE()')->fetchColumn(),'poptracker_farm_test_')) { exit(2); }
function verified(bool $ok,string $name): void {
    if (!$ok) { throw new RuntimeException($name); }
    echo "PASS $name\n";
}
function blocked(int $status,callable $call): bool {
    try { $call(); } catch (ApiError $e) { return $e->getCode()===$status; }
    return false;
}
$c=issue($db,'UI test');
$d=dispatch($db,'enroll',['code'=>$c['code'],'agent_version'=>'0.3.0','android_version'=>'13'],'');
$id=$d['device_id']; $auth='Bearer '.$d['token'];
$p=(object)['scenario'=>OWN_UI_SCENARIO];
$claim=function(string $v,array $caps=[]) use($db,$id,$auth) {
    return dispatch($db,'tasks/claim',['device_id'=>$id,'agent_version'=>$v]+$caps,$auth);
};
$cap=['capabilities'=>[OWN_UI_SCENARIO]];
verified(blocked(422,fn()=>createTask($db,$id,'UI_TEST_SCENARIO',(object)['scenario'=>'OTHER'])),'only the fixed scenario');
verified(blocked(422,fn()=>createTask($db,$id,'UI_TEST_SCENARIO',(object)['scenario'=>OWN_UI_SCENARIO,'package'=>'other'])),'no target override');
$t=createTask($db,$id,'UI_TEST_SCENARIO',$p);
$ping=createTask($db,$id,'PING',(object)[]);
$old=$claim('0.2.1');
verified($old['task_id']===$ping,'old client skips UI and still receives PING');
$stamp=gmdate('Y-m-d\TH:i:s\Z');
$makeBody=fn($job,$result)=>['attempt_id'=>$job['attempt_id'],'lease_token'=>$job['lease_token'],'completed_at'=>$stamp,
    'outcome'=>'succeeded','error'=>null,'result'=>$result];
dispatch($db,'tasks/'.$ping.'/result',$makeBody($old,(object)['pong'=>true,'executed_at'=>$stamp]),$auth);
verified($claim('0.2.1',$cap)===null && $claim('0.3.0')===null,'requires compatible version and capability');
verified(blocked(422,fn()=>$claim('0.3.0',['capabilities'=>null])),'malformed capability rejected');
$job=$claim('0.3.0',$cap);
$t2=createTask($db,$id,'UI_TEST_SCENARIO',$p);
verified($job['task_id']===$t && $claim('0.3.0',$cap)===null,'two UI tasks execute serially');
$observed=['counter_zero'=>'0','counter_verify'=>'1','scroll_find_item'=>'Test Item 25'];
$steps=[];
foreach (['permission_check','screen_check','reset','counter_zero','button_click','counter_verify','scroll_find_item'] as $name) {
    $s=(object)['name'=>$name,'status'=>'PASSED','duration_ms'=>1];
    if (isset($observed[$name])) { $s->observed_value=$observed[$name]; }
    $steps[]=$s;
}
$r=(object)['schema_version'=>1,'scenario'=>OWN_UI_SCENARIO,'status'=>'PASSED','duration_ms'=>10,'steps'=>$steps,'error_code'=>null];
$route='tasks/'.$t.'/result'; $body=$makeBody($job,$r);
verified(blocked(401,fn()=>dispatch($db,$route,$body,'')),'UI result requires device identity');
$r->steps[6]->observed_value='extraneous text';
verified(blocked(422,fn()=>dispatch($db,$route,$body,$auth)),'reject arbitrary observed text');
$r->steps[6]->observed_value='Test Item 25';
verified(dispatch($db,$route,$body,$auth)['outcome']==='succeeded','validated structured success');
$r->steps[0]=(object)array_reverse((array)$r->steps[0],true);
verified(dispatch($db,$route,$body,$auth)['replayed'],'same nested result is idempotent');
$r->duration_ms=11;
verified(blocked(409,fn()=>dispatch($db,$route,$body,$auth)),'changed UI result conflicts');
$second=$claim('0.3.0',$cap); $route2='tasks/'.$t2.'/result';
$r->status='FAILED'; $r->steps=[(object)['name'=>'permission_check','status'=>'FAILED','duration_ms'=>1]]; $r->error_code='ACCESSIBILITY_DISABLED';
$bad=['outcome'=>'failed','error'=>(object)['code'=>'ACCESSIBILITY_DISABLED','message'=>'Failed at permission_check']]+$makeBody($second,$r);
verified(dispatch($db,$route2,$bad,$auth)['result']->status==='FAILED','failed report retains diagnostic step');
$t3=createTask($db,$id,'UI_TEST_SCENARIO',$p); $late=$claim('0.3.0',$cap);
query($db,'UPDATE device_task_attempts SET leased_at=UTC_TIMESTAMP()-INTERVAL 150 SECOND,lease_expires_at=UTC_TIMESTAMP()-INTERVAL 30 SECOND WHERE task_id=?',[$t3]);
verified(blocked(409,fn()=>dispatch($db,'tasks/'.$t3.'/result',['lease_token'=>$late['lease_token'],'attempt_id'=>$late['attempt_id']]+$bad,$auth)),'first UI delivery after lease rejected');
$row=taskCommand($db,['farm','task:show',$t3]);
verified($row['attempts'][0]['late_results']==1 && $row['status']==='failed' && count($row['attempts'])===1,'late UI report never queues a new click');
