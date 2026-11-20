<?php
declare(strict_types=1);

require dirname(__DIR__).'/farm.php';
require dirname(__DIR__).'/fresh_geo.php';

// This test owns an empty isolated queue and uses explicit synthetic catalog rows.
function geoPilotTargets(PDO $db): array {
    return [['author_uid'=>'1001','video_id'=>'2001']];
}
function geoPilotControls(PDO $db): array {
    return array_map(static fn($u,$v,$c)=>['author_uid'=>$u,'video_id'=>$v,'configured_country_code'=>$c,
        'reference_status'=>'historical_country_matches'],['101','102'],['201','202'],['AU','ZA']);
}
function checkGeo(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException($label); }
    echo "PASS $label\n";
}
function expectGeoConflict(callable $action): bool {
    try { $action(); } catch (ApiError $e) { return $e->getCode()===409; }
    return false;
}

$db=database();
if (!preg_match('/^poptracker_farm_test_geo_[a-z0-9]+$/D',(string)$db->query('SELECT DATABASE()')->fetchColumn())
    || $db->query('SELECT COUNT(*) FROM device_tasks')->fetchColumn()!=0) { throw new RuntimeException('Empty disposable database required'); }
$device=deviceId();
query($db,"INSERT INTO device_agents(device_id,display_name,token_hash,agent_version,android_version,created_at)
    VALUES(?,'fixture',RANDOM_BYTES(32),'0.6.0','15',NOW())",[$device]);
query($db,'INSERT INTO production_state(id,paused) VALUES(1,0)');
$scope=freshGeoPreview($db);
$id=deviceId(); $hash=$scope['scope_sha256'];
$submit=fn()=>freshGeoSubmit($db,$device,$id,$hash);
checkGeo($scope['targets'][0]['geo_observation']===null,'no accepted measurement is distinct from an empty region');
checkGeo($scope['ready_devices']===[] && expectGeoConflict($submit),'source unavailable creates no job');
$poll=static function (bool $ready) use ($db,$device): ?array {
    $db->beginTransaction();
    try {
        $r=claimTask($db,$device,['device_id'=>$device,'agent_version'=>'0.6.0','result_protocol'=>2,
            'capabilities'=>$ready?[FRESH_GEO_TASK]:[]],true);
        $db->commit();
        return $r;
    } catch (Throwable $error) { $db->rollBack(); throw $error; }
};
$poll(true);
checkGeo(count(freshGeoPreview($db)['ready_devices'])===1,'online source advertised');
checkGeo(expectGeoConflict(fn()=>freshGeoSubmit($db,$device,deviceId(),str_repeat('f',64))),'stale scope rejected');
query($db,'UPDATE production_state SET paused=1 WHERE id=1');
checkGeo(expectGeoConflict($submit),'paused queue rejects submission');
query($db,'UPDATE production_state SET paused=0 WHERE id=1');
checkGeo($submit()===$submit(),'repeated submission returns the same receipt');
checkGeo(expectGeoConflict(fn()=>freshGeoSubmit($db,$device,deviceId(),$hash)),'concurrent batch blocked');
checkGeo($poll(false)===null && !freshGeoPreview($db)['ready_devices'],'agent losing source cannot claim');
$task=$poll(true);
checkGeo(($task['task_id']??null)===$id && count($task['payload']->members)===3,'ready phone claims original batch');
$report=(object)['request_id'=>$id,'scope_sha256'=>$hash,'observations'=>[]];
$now=time();
foreach ($task['payload']->members as $m) {
    $report->observations[]=(object)['uid'=>$m->uid,'vid'=>$m->vid,'role'=>$m->role,'region'=>$m->expected??null,
        'observed_ms'=>$now*1000,'evidence_sha256'=>str_repeat('a',64)];
}
checkGeo(validFreshGeoReport($task['payload'],$report,$now,$now),'matching controls and empty target accepted');
$report->observations[1]->region='BR';
checkGeo(!validFreshGeoReport($task['payload'],$report,$now,$now),'wrong control rejects entire batch');
$report->observations[1]->region='AU';
checkGeo(!validFreshGeoReport($task['payload'],$report,$now+5,$now+8),'prior measurement rejected');
$report->observations[0]->vid='999';
checkGeo(!validFreshGeoReport($task['payload'],$report,$now,$now),'unrequested video rejected');
$report->observations[0]->vid='2001';
$body=['attempt_id'=>$task['attempt_id'],'lease_token'=>$task['lease_token'],'result'=>$report,'error'=>null,
    'completed_at'=>gmdate('Y-m-d\TH:i:s\Z'),'outcome'=>'succeeded','execution_timing'=>(object)['start_ms'=>5,'finish_ms'=>10]];
$db->beginTransaction();
try {
    $receipt=acceptTaskResult($db,$device,$id,$body);
    checkGeo(is_array($receipt) && !$receipt['replayed'] && $receipt['outcome']==='succeeded',
        'validated synthetic report saved for this attempt');
    $repeat=acceptTaskResult($db,$device,$id,$body);
    checkGeo(is_array($repeat) && $repeat['replayed'],'identical report receives prior receipt');
    $db->commit();
} finally {
    if ($db->inTransaction()) { $db->rollBack(); }
}
checkGeo(freshGeoPreview($db)['last_task']['result']->observations[0]->region===null,'panel returns empty target with verified controls');

$matched=freshGeoTargetObservations($db,[['author_uid'=>'1001','video_id'=>'2001'],
    ['author_uid'=>'1001','video_id'=>'999'],['author_uid'=>'101','video_id'=>'201']]);
checkGeo($matched[0]['geo_observation']===['region'=>null,'observed_ms'=>$now*1000]
    && $matched[1]['geo_observation']===null && $matched[2]['geo_observation']===null,
    'separate a measured empty field, different video and control from a target region');
$later=deviceId();
freshGeoSubmit($db,$device,$later,$hash);
$laterTask=$poll(true);
$report->request_id=$later;
$report->observations[0]->region='BR';
$body['attempt_id']=$laterTask['attempt_id'];
$body['lease_token']=$laterTask['lease_token'];
foreach ($report->observations as $o) { $o->observed_ms=max($now*1000+1,time()*1000); }
$db->beginTransaction();
try { acceptTaskResult($db,$device,$later,$body); $db->commit(); }
finally { if ($db->inTransaction()) { $db->rollBack(); } }
checkGeo(freshGeoPreview($db)['targets'][0]['geo_observation']['region']==='BR','latest accepted country appears on the exact target row');
