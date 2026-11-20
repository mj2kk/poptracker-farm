<?php
declare(strict_types=1);
require __DIR__.'/farm.php';
if (!defined('TEST_BATCH_TASK')) { throw new RuntimeException('Controlled batch adapter required'); }
function assertBatch(bool $condition,string $name): void {
    if (!$condition) { throw new RuntimeException($name); }
    echo "PASS $name\n";
}
for ($n=1;$n<=10;$n++) {
    $p=testBatchRequest(deviceId(),$n);$rows=[];
    foreach (testBatchMembers($p) as $uid=>$m) {
        $rows[]=(object)(['uid'=>$uid]+$m+['observed_ms'=>time()*1000,'evidence_sha256'=>str_repeat('f',64)]);
    }
    $result=(object)['request_id'=>$p->request_id,'scope_sha256'=>$p->scope_sha256,'source'=>'synthetic_test_provider','synthetic'=>true,'observations'=>$rows];
    validateTestBatchReport($p,$result);
    assertBatch($rows[0]->region===TEST_BATCH_REGIONS[$n-1],"synthetic fixture $n round trip");
}
$cases=[
    static function($p,$r) { $p->members[0]->uid='1000000000000000001'; $p->scope_sha256=testBatchDigest($p->members); },
    static function($p,$r) { $r->observations[0]->region='XX'; },
    static function($p,$r) { $r->request_id=deviceId(); },
    static function($p,$r) { $r->synthetic=false; },
    static function($p,$r) { $r->observations[1]->vid='test_video_010'; },
    static function($p,$r) { $r->observations[1]=$r->observations[0]; },
];
$template=serialize([$p,$result]);
foreach ($cases as $index=>$mutate) {
    [$p,$r]=unserialize($template);$mutate($p,$r);
    $rejected=false;
    try { validateTestBatchReport($p,$r); } catch (ApiError $e) { $rejected=$e->getCode()===422; }
    assertBatch($rejected,'reject changed identity, source or control '.($index+1));
}
$unusedDb=new class extends PDO { public function __construct() {} };
foreach ([[[]],[1],[TEST_BATCH_TASK,TEST_BATCH_TASK],[TEST_BATCH_TASK,null]] as $index=>$caps) {
    $id=deviceId();$rejected=false;
    try { claimTask($unusedDb,$id,['device_id'=>$id,'agent_version'=>'controlled-test','result_protocol'=>2,'capabilities'=>$caps]); }
    catch (ApiError $e) { $rejected=$e->getMessage()==='invalid_capabilities'; }
    assertBatch($rejected,'malformed capability array '.($index+1));
}
