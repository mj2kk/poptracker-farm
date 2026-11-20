<?php
declare(strict_types=1);
require dirname(__DIR__).'/farm.php';
$db=database();
if (!str_starts_with(query($db,'SELECT DATABASE()')->fetchColumn(),'poptracker_farm_test_')) { exit(2); }
function test(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$reg=issue($db,'Display test');
$base=['agent_version'=>'0.4.0','android_version'=>'12'];
$identity=dispatch($db,'enroll',$base+['code'=>$reg['code']],'');
$device=['device_id'=>$identity['device_id']];
$token='Bearer '.$identity['token'];
$beat=$device+$base+['sent_at'=>gmdate('Y-m-d\TH:i:s\Z')];
$read=function() use ($db,$device) { return array_column(listDevices($db),null,'device_id')[$device['device_id']]; };
dispatch($db,'heartbeat',$beat+['screen_on'=>true],$token);
test($read()['screen_on']===true,'online plus lit screen');
dispatch($db,'display',$device+['screen_on'=>false],$token);
test($read()['screen_on']===false,'screen off independently of online');
try { dispatch($db,'display',['device_id'=>deviceId(),'screen_on'=>true],$token); throw new RuntimeException('mismatch accepted'); }
catch (ApiError $e) { test($e->getCode()===403,'foreign display update denied'); }
try { dispatch($db,'display',$device+['screen_on'=>'false'],$token); throw new RuntimeException('string accepted'); }
catch (ApiError $e) { test($e->getCode()===422,'strict boolean'); }
query($db,'UPDATE device_agents SET last_seen_at=UTC_TIMESTAMP()-INTERVAL 151 SECOND WHERE device_id=?',array_values($device));
dispatch($db,'display',$device+['screen_on'=>true],$token);
test($read()['status']==='offline' && $read()['screen_on']===null,'display event cannot make device online');
dispatch($db,'heartbeat',$beat,$token);
test($read()['screen_on']===null,'legacy heartbeat clears unknown telemetry');
