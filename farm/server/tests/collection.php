<?php
declare(strict_types=1);
require dirname(__DIR__).'/farm.php';
$db = database();
if (!str_starts_with(query($db,'SELECT DATABASE()')->fetchColumn(),'poptracker_farm_test_')) { exit(2); }

function verify(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
    echo "PASS $message\n";
}
function registered(PDO $db): array {
    $code = issue($db,'Collection fixture');
    return dispatch($db,'enroll',['code'=>$code['code'],'android_version'=>'13','agent_version'=>'0.3.3'],'');
}
function fixtureBody(array $claim, int $count): array {
    $stamp = gmdate('Y-m-d\TH:i:s\Z');
    $records = [];
    for ($i=1;$i<=$count;$i++) {
        $records[] = ['external_id'=>sprintf('test_video_%03d',$i),'author_id'=>sprintf('test_author_%03d',$i),
            'published_at'=>$stamp,'description'=>'Synthetic fixture','views'=>100,'likes'=>10,'comments'=>2,'shares'=>1,'collected_at'=>$stamp];
    }
    return ['attempt_id'=>$claim['attempt_id'],'lease_token'=>$claim['lease_token'],'outcome'=>'succeeded',
        'error'=>null,'completed_at'=>$stamp,'execution_timing'=>['start_ms'=>10,'finish_ms'=>20],
        'result'=>['device_id'=>$claim['device_id'],'attempt_id'=>$claim['attempt_id'],'task_id'=>$claim['task_id'],
            'batch_id'=>deviceId(),'schema_version'=>1,'created_at'=>$stamp,'records'=>$records]];
}
$a=registered($db);
$b=registered($db);
$operator=bin2hex(random_bytes(32));
$file=tempnam(sys_get_temp_dir(),'farm-operator-');
file_put_contents($file,hash('sha256',$operator));
putenv('FARM_OPERATOR_HASH_FILE='.$file);
$sock=stream_socket_server('tcp://127.0.0.1:0');
$addr=stream_socket_get_name($sock,false);
fclose($sock);
$proc=proc_open([PHP_BINARY,'-S',$addr,dirname(__DIR__).'/public/index.php'],
    [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
try {
    for ($i=0;$i<50;$i++) {
        if ($conn=@stream_socket_client('tcp://'.$addr,$err,$msg,0.1)) { fclose($conn); break; }
        usleep(20000);
    }
    $request=function(string $route,string $token,array|string|null $data,int $status,?string $reason=null) use ($addr): ?array {
        $body=is_array($data) ? json_encode($data,JSON_THROW_ON_ERROR) : $data;
        $http=['method'=>$body===null ? 'GET' : 'POST','header'=>"Authorization: Bearer $token\r\nContent-Type: application/json\r\n",
            'ignore_errors'=>true,'timeout'=>5];
        if ($body!==null) { $http['content']=$body; }
        $result=file_get_contents("http://$addr".FARM_PREFIX.$route,false,stream_context_create(['http'=>$http]));
        $headers=function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : $http_response_header;
        if (!str_contains($headers[0]," $status ")) { throw new RuntimeException("$route expected $status: ".$headers[0]); }
        $json=$result==='' ? null : json_decode($result,true,flags:JSON_THROW_ON_ERROR);
        if ($reason!==null && ($json['error'] ?? null)!==$reason) { throw new RuntimeException("Expected $reason"); }
        return $json;
    };
    $request('devices',$a['token'],null,401);
    $rows=$request('devices',$operator,null,200)['devices'];
    $me=array_values(array_filter($rows,fn($r)=>$r['device_id']===$a['device_id']))[0];
    verify($me['status']==='registered' && isset($me['agent_version'],$me['android_version']) && !array_key_exists('token_hash',$me) && !array_key_exists('token',$me),'operator can list device states, agent cannot list fleet');
    $claim=function($agent, $capabilities) use ($request) {
        return $request('tasks/claim',$agent['token'],['device_id'=>$agent['device_id'],'agent_version'=>'0.3.3','result_protocol'=>2,
            'capabilities'=>$capabilities],in_array(COLLECTION_TYPE,$capabilities,true) ? 200 : 204);
    };
    foreach ([9,101,'10'] as $bad) {
        try { createTask($db,$a['device_id'],COLLECTION_TYPE,(object)['record_count'=>$bad,'dataset'=>COLLECTION_DATASET]); throw new RuntimeException('invalid creation'); }
        catch (ApiError $e) { verify($e->getCode()===422,'bounded integer record_count at creation'); }
    }
    $id=createTask($db,$a['device_id'],COLLECTION_TYPE,(object)['record_count'=>10,'dataset'=>COLLECTION_DATASET]);
    verify($claim($a,[OWN_UI_SCENARIO])===null,'older agent skips collection');
    $j=$claim($a,[COLLECTION_TYPE,OWN_UI_SCENARIO]);
    verify($j['task_id']===$id && $j['device_id']===$a['device_id'],'new agent receives its collection assignment');
    $route="tasks/$id/result";
    $body=fixtureBody($j,10);
    $request($route,$b['token'],$body,404,'task_not_found');
    $request($route,$a['token'],'{',400,'invalid_json');
    $request($route,$a['token'],str_repeat(' ',COLLECTION_MAX_BYTES+1),413,'body_too_large');
    foreach (['device_id','attempt_id','task_id'] as $key) {
        $bad=$body; $bad['result'][$key]=deviceId(); $request($route,$a['token'],$bad,403,'collection_owner_mismatch');
    }
    foreach (['batch_id'=>'bad','created_at'=>'2026-02-30T10:00:00Z','schema_version'=>'1','records'=>[]] as $key=>$val) {
        $bad=$body; $bad['result'][$key]=$val; $request($route,$a['token'],$bad,422);
    }
    foreach (['views'=>-1,'likes'=>'10','shares'=>4294967296,'description'=>str_repeat('x',513),
        'external_id'=>'test_video_001','author_id'=>false,'published_at'=>'2026-02-30T10:00:00Z'] as $key=>$val) {
        $bad=$body; $bad['result']['records'][9][$key]=$val; $request($route,$a['token'],$bad,422);
    }
    $bad=$body; unset($bad['result']['records'][9]['comments']); $request($route,$a['token'],$bad,422);
    $encoded=json_encode($body,JSON_THROW_ON_ERROR);
    $requestWire=function(string $bytes) use ($addr,$a,$route,$encoded) {
        $socket=stream_socket_client('tcp://'.$addr);
        stream_set_timeout($socket,5);
        fwrite($socket,'POST '.FARM_PREFIX.$route." HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\nAuthorization: Bearer ".$a['token'].
            "\r\nContent-Type: application/json\r\nContent-Length: ".strlen($encoded)."\r\n\r\n".$bytes);
        return $socket;
    };
    fclose($requestWire(substr($encoded,0,intdiv(strlen($encoded),2))));
    $request('devices',$operator,null,200); // Wait until the single HTTP worker handles the disconnect.
    verify((int)query($db,'SELECT COUNT(*) FROM collection_batches WHERE task_id=?',[$id])->fetchColumn()===0,
        'malformed JSON, wrong owner, invalid rows and interrupted upload leave zero batches');
    query($db,'UPDATE device_task_attempts SET lease_expires_at=UTC_TIMESTAMP()-INTERVAL 5 SECOND WHERE attempt_id=?',[$j['attempt_id']]);
    $socket=$requestWire($encoded);
    verify(str_contains((string)fgets($socket),' 200 '),'late upload committed');
    fclose($socket); // The response body and its batch receipt are lost.
    $ack=$request($route,$a['token'],$body,200);
    verify($ack['replayed'] && $ack['result']['batch_id']===$body['result']['batch_id'] && $ack['result']['record_count']===10
        && $ack['result']['status']==='stored','lost receipt recovered by exact replay');
    $bad=$body; $bad['result']['records'][0]['likes']++; $request($route,$a['token'],$bad,409,'result_conflict');
    $raw=json_decode(query($db,'SELECT raw_json FROM collection_batches WHERE task_id=?',[$id])->fetchColumn(),true,flags:JSON_THROW_ON_ERROR);
    verify($raw==$body['result'] && (int)query($db,'SELECT COUNT(DISTINCT external_id) FROM collection_records WHERE batch_id=?',[$raw['batch_id']])->fetchColumn()===10,
        'one intact raw package and ten unique stored records after conflicting replay');
    $other=createTask($db,$b['device_id'],COLLECTION_TYPE,(object)['record_count'=>100,'dataset'=>COLLECTION_DATASET]);
    $otherBody=fixtureBody($claim($b,[COLLECTION_TYPE]),100);
    $otherBody['result']['batch_id']=$raw['batch_id'];
    $ok=$request("tasks/$other/result",$b['token'],$otherBody,200);
    verify($ok['result']['record_count']===100 && $ok['result']['batch_id']===$raw['batch_id'] && strlen(json_encode($ok))<1024,
        'independent device accepts the same local batch ID with a compact receipt');
    $request("tasks/$other/result",$b['token'],$otherBody,200);
    $counts=query($db,'SELECT b.device_id,COUNT(*) AS n FROM collection_batches b JOIN collection_records r USING(batch_id)
        WHERE b.local_batch_id=? GROUP BY b.device_id',[$raw['batch_id']])->fetchAll(PDO::FETCH_KEY_PAIR);
    verify((int)$counts[$a['device_id']]===10 && (int)$counts[$b['device_id']]===100,'overlapping local IDs stay isolated on replay');
    $duplicate=createTask($db,$b['device_id'],COLLECTION_TYPE,(object)['record_count'=>10,'dataset'=>COLLECTION_DATASET]);
    $collision=fixtureBody($claim($b,[COLLECTION_TYPE]),10);
    $collision['result']['batch_id']=$raw['batch_id'];
    $request("tasks/$duplicate/result",$b['token'],$collision,409,'batch_conflict');
} finally {
    proc_terminate($proc); proc_close($proc); unlink($file);
}
