<?php
declare(strict_types=1);
require dirname(__DIR__).'/farm.php';
$db=database();
if (!str_starts_with(query($db,'SELECT DATABASE()')->fetchColumn(),'poptracker_farm_test_')) { exit(2); }
$identity=issue($db,'HTTP test');
$c=dispatch($db,'enroll',['code'=>$identity['code'],'agent_version'=>'0.2.0','android_version'=>'13'],'');
$socket=stream_socket_server('tcp://127.0.0.1:0'); $addr=stream_socket_get_name($socket,false); fclose($socket);
$proc=proc_open([PHP_BINARY,'-S',$addr,dirname(__DIR__).'/public/index.php'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
try {
    for ($i=0;$i<50;$i++) {
        if ($conn=@stream_socket_client('tcp://'.$addr,$n,$msg,0.1)) { fclose($conn); break; }
        usleep(20000);
    }
    $base='http://'.$addr.FARM_PREFIX;
    $post=function(string $route,string $body,string $content,string $token,int $expect) use ($base): string {
        $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: $content\r\nAuthorization: Bearer $token\r\n",'content'=>$body,'ignore_errors'=>true,'timeout'=>5]]);
        $result=file_get_contents($base.$route,false,$ctx);
        $headers=function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : $http_response_header;
        if (!str_contains($headers[0]," $expect ")) { throw new RuntimeException("Expected $expect for $route"); }
        echo "PASS HTTP $expect\n";
        return $result;
    };
    $claim=json_encode(['device_id'=>$c['device_id'],'agent_version'=>'0.2.0']);
    if ($post('tasks/claim',$claim,'application/json',$c['token'],204)!=='') { throw new RuntimeException('204 contained a body'); }
    $post('tasks/claim','{','application/json',$c['token'],400);
    $post('tasks/claim',str_repeat(' ',4097),'application/json',$c['token'],413);
    $post('tasks/claim',$claim,'text/plain',$c['token'],415);
    $post('tasks/claim',$claim,'application/json','invalid',401);
    $id=createTask($db,$c['device_id'],'PING',(object)[]);
    $cl=json_decode($post('tasks/claim',$claim,'application/json',$c['token'],200));
    $body=json_encode(['attempt_id'=>$cl->attempt_id,'lease_token'=>$cl->lease_token,'outcome'=>'succeeded','result'=>['pong'=>true,'executed_at'=>gmdate('Y-m-d\TH:i:s\Z')],'error'=>null,'completed_at'=>gmdate('Y-m-d\TH:i:s\Z')]);
    $ack=json_decode($post("tasks/$id/result",$body,'application/json',$c['token'],200));
    if ($ack->replayed || !$ack->result->pong || !$ack->received_at) { throw new RuntimeException('bad receipt'); }
    if (!json_decode($post("tasks/$id/result",$body,'application/json',$c['token'],200))->replayed) { throw new RuntimeException('bad replay'); }
    $post('tasks/create','{}','application/json',$c['token'],404);
    $next=createTask($db,$c['device_id'],'PING',(object)[]);
    $v2=json_encode(['device_id'=>$c['device_id'],'agent_version'=>'0.3.2','result_protocol'=>2],JSON_THROW_ON_ERROR);
    $assignment=json_decode($post('tasks/claim',$v2,'application/json',$c['token'],200));
    $data=json_decode($body,true);
    $data['attempt_id']=$assignment->attempt_id;
    $data['lease_token']=$assignment->lease_token;
    $data['execution_timing']=['start_ms'=>5,'finish_ms'=>7];
    $encoded=json_encode($data,JSON_THROW_ON_ERROR);
    $route="tasks/$next/result";
    $stream=stream_socket_client('tcp://'.$addr);
    stream_set_timeout($stream,5);
    fwrite($stream,"POST ".FARM_PREFIX.$route." HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n".
        "Authorization: Bearer ".$c['token']."\r\nContent-Type: application/json\r\nContent-Length: ".strlen($encoded)."\r\n\r\n".$encoded);
    if (!str_contains((string)fgets($stream),' 200 ')) { throw new RuntimeException('result did not commit'); }
    fclose($stream); // Client never consumes the JSON receipt.
    $recovered=json_decode($post($route,$encoded,'application/json',$c['token'],200));
    if (!$recovered->replayed || $recovered->attempt_id!==$assignment->attempt_id) { throw new RuntimeException('lost receipt'); }
    echo "PASS v2 closed connection after commit, receipt recovered\n";
} finally { proc_terminate($proc); proc_close($proc); }
