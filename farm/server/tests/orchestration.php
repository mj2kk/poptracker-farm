<?php
declare(strict_types=1);
require dirname(__DIR__).'/farm.php';
$db=database();
if (!str_starts_with(query($db,'SELECT DATABASE()')->fetchColumn(),'poptracker_farm_test_')) { exit(2); }
function check(bool $ok,string $name): void {
    if (!$ok) { throw new RuntimeException($name); }
    echo "PASS $name\n";
}
function claim(PDO $db,array $c,array $caps=[]): ?array {
    return dispatch($db,'tasks/claim',['device_id'=>$c['device_id'],'agent_version'=>'0.3.3',
        'result_protocol'=>2,'capabilities'=>$caps],'Bearer '.$c['token']);
}
function submit(PDO $db,array $c,string $task,array $body): array {
    // Match the nested objects and arrays produced by the JSON router.
    return dispatch($db,"tasks/$task/result",(array)json_decode(json_encode($body,JSON_THROW_ON_ERROR)),
        'Bearer '.$c['token']);
}
if (($argv[1] ?? '')==='worker') {
    $op=json_decode(fgets(STDIN),true,flags:JSON_THROW_ON_ERROR);
    echo "ready\n"; flush(); fgets(STDIN);
    $result=$op[0]==='claim' ? claim($db,$op[1]) : submit($db,$op[1],$op[2],$op[3]);
    echo json_encode($result,JSON_THROW_ON_ERROR);
    exit;
}
function parallel(array ...$ops): array {
    $children=[];
    foreach ($ops as $op) {
        $p=proc_open([PHP_BINARY,__FILE__,'worker'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
        fwrite($pipes[0],json_encode($op,JSON_THROW_ON_ERROR)."\n");
        $children[]=[$p,$pipes];
    }
    foreach ($children as [,$pipes]) { if (trim((string)fgets($pipes[1]))!=='ready') { throw new RuntimeException('worker not ready'); } }
    foreach ($children as [,$pipes]) { fwrite($pipes[0],"go\n"); fclose($pipes[0]); }
    $results=[];
    foreach ($children as [$p,$pipes]) {
        $raw=stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err=stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($p)!==0) { throw new RuntimeException('worker failed: '.$err); }
        $results[]=json_decode($raw,true,flags:JSON_THROW_ON_ERROR);
    }
    return $results;
}
function enrollFixture(PDO $db,string $label): array {
    $code=issue($db,$label);
    return dispatch($db,'enroll',['code'=>$code['code'],'agent_version'=>'0.3.3','android_version'=>'12'],'');
}
function beat(PDO $db,array $agent): void {
    dispatch($db,'heartbeat',['device_id'=>$agent['device_id'],'agent_version'=>'0.3.3','android_version'=>'12',
        'sent_at'=>gmdate('Y-m-d\TH:i:s\Z')],'Bearer '.$agent['token']);
}
function envelope(array $job, array $result): array {
    return ['attempt_id'=>$job['attempt_id'],'lease_token'=>$job['lease_token'],'error'=>null,'outcome'=>'succeeded',
        'completed_at'=>gmdate('Y-m-d\TH:i:s\Z'),'result'=>$result,'execution_timing'=>['start_ms'=>3,'finish_ms'=>7]];
}
$a=enrollFixture($db,'Orchestration A'); $b=enrollFixture($db,'Orchestration B');
$any=taskCommand($db,['farm','task:create','any','PING'])['task_id'];
check(taskCommand($db,['farm','task:show',$any])['device_id']===null,'unassigned task can be inspected');
check(claim($db,$a)===null,'any requires a recent heartbeat');
beat($db,$a); beat($db,$b);
$jobs=parallel(['claim',$a],['claim',$b]);
$winners=array_keys(array_filter($jobs));
check(count($winners)===1 && $jobs[$winners[0]]['task_id']===$any,'two simultaneous eligible claimants, one winner');
$i=$winners[0]; $winner=[$a,$b][$i]; $loser=[$b,$a][$i];
$job=$jobs[$i];
check(taskCommand($db,['farm','task:show',$any])['device_id']===$winner['device_id'],'server binds winning device to any task');
$next=createTask($db,'any','PING',(object)[]);
check(claim($db,$winner)===null && claim($db,$loser)['task_id']===$next,'busy device cannot take new work, other device proceeds');
$body=envelope($job,['pong'=>true,'executed_at'=>gmdate('Y-m-d\TH:i:s\Z')]);
try { submit($db,$loser,$any,$body); throw new RuntimeException('wrong owner accepted'); }
catch (ApiError $e) { check($e->getCode()===404,'other device cannot complete leased any task'); }
$roster=array_column(listDevices($db),null,'device_id');
check($roster[$winner['device_id']]['current_task']->task_id===$any && $roster[$winner['device_id']]['status']==='online',
    'operator sees distinct online leased tasks');
submit($db,$winner,$any,$body);
$roster=array_column(listDevices($db),null,'device_id');
check($roster[$winner['device_id']]['current_task']===null && $roster[$winner['device_id']]['completed_tasks']===1,
    'operator counts completed work and clears current task');
$anyCollection=createTask($db,'any',COLLECTION_TYPE,(object)['dataset'=>COLLECTION_DATASET,'record_count'=>10]);
check(claim($db,$winner)===null,'capability filters unassigned tasks');
$target=createTask($db,$winner['device_id'],'PING',(object)[]);
check(claim($db,$winner,[COLLECTION_TYPE])['task_id']===$target,'targeted task takes priority over eligible any task');
taskCommand($db,['farm','task:cancel',$anyCollection]);
$c=enrollFixture($db,'Concurrent batch C'); $d=enrollFixture($db,'Concurrent batch D');
$local=deviceId(); $ops=[];
foreach ([$c,$d] as $index=>$agent) {
    $id=createTask($db,$agent['device_id'],COLLECTION_TYPE,(object)['dataset'=>COLLECTION_DATASET,'record_count'=>100]);
    $j=claim($db,$agent,[COLLECTION_TYPE]); $rows=[]; $stamp=gmdate('Y-m-d\TH:i:s\Z');
    for ($n=1;$n<=100;$n++) {
        $rows[]=['external_id'=>sprintf('test_video_%03d',$n),'author_id'=>sprintf('test_author_%03d',$n),'description'=>'fixture',
            'published_at'=>$stamp,'collected_at'=>$stamp,'views'=>$index,'likes'=>0,'comments'=>0,'shares'=>0];
    }
    $ops[]=['submit',$agent,$id,envelope($j,['task_id'=>$id,'attempt_id'=>$j['attempt_id'],'device_id'=>$agent['device_id'],
        'batch_id'=>$local,'schema_version'=>1,'records'=>$rows,'created_at'=>$stamp])];
}
$acks=parallel(...$ops);
check(!$acks[0]['replayed'] && !$acks[1]['replayed'],'both simultaneous submissions with identical local batch_id are stored');
$again=parallel(...$ops);
check($again[0]['replayed'] && $again[1]['replayed'],'both original receipts replay independently');
$counts=query($db,'SELECT b.device_id,COUNT(*) AS n,COUNT(DISTINCT r.external_id) AS uniq,SUM(r.views) AS total
    FROM collection_records r JOIN collection_batches b USING(batch_id) WHERE b.local_batch_id=? GROUP BY b.device_id',[$local])->fetchAll();
check(count($counts)===2,'two independent storage packages');
foreach ($counts as $r) { check((int)$r['n']===100 && (int)$r['uniq']===100 &&
    (int)$r['total']===($r['device_id']===$c['device_id'] ? 0 : 100),'owner-specific row values and 100 unique IDs'); }
query($db,'UPDATE device_agents SET revoked_at=UTC_TIMESTAMP() WHERE device_id=?',[$c['device_id']]);
try { claim($db,$c); throw new RuntimeException('revoked device accepted'); }
catch(ApiError $e) { check($e->getCode()===401 && claim($db,$d)===null,'revoking one registration leaves the other valid'); }

$info=createTask($db,$d['device_id'],'GET_DEVICE_STATUS',(object)[]);
$j=claim($db,$d);
submit($db,$d,$info,envelope($j,['manufacturer'=>'Xiaomi','model'=>'Fixture','agent_version'=>'0.3.3','android_version'=>'12','api_level'=>31]));
$expired=createTask($db,$d['device_id'],'PING',(object)[]);
$j=claim($db,$d);
query($db,'UPDATE device_task_attempts SET lease_expires_at=UTC_TIMESTAMP()-INTERVAL 60 SECOND,deliver_until=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE attempt_id=?',[$j['attempt_id']]);
query($db,'UPDATE device_agents SET last_seen_at=UTC_TIMESTAMP()-INTERVAL 151 SECOND WHERE device_id=?',[$d['device_id']]);
$r=array_column(listDevices($db),null,'device_id')[$d['device_id']];
check($r['model']==='Fixture' && $r['manufacturer']==='Xiaomi' && $r['last_error']->code==='LEASE_EXPIRED'
    && $r['last_error']->task_id===$expired && $r['current_task']===null && $r['status']==='offline',
    'monitor reads hardware profile and expired assignment even when offline');
