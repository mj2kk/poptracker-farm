<?php
declare(strict_types=1);

// This adapter is copied only into the dedicated STEP05 schema and HTTP entry.
const TEST_BATCH_TASK = 'TEST_AUTHOR_GEO_V1';
const TEST_BATCH_REGIONS = ['ZA','BR','KZ','AU','GB','CA','NG','ZA','US','KE'];

function testBatchRequest(string $id, int $number): object {
    $suffix = sprintf('%03d',$number);
    $members = [(object)['uid'=>'test_author_'.$suffix,'vid'=>'test_video_'.$suffix,'role'=>'target'],
        (object)['uid'=>'test_control_au','vid'=>'test_control_video_au','role'=>'control'],
        (object)['uid'=>'test_control_za','vid'=>'test_control_video_za','role'=>'control']];
    return (object)['version'=>1,'request_id'=>$id,'scope_sha256'=>testBatchDigest($members),'members'=>$members];
}
function testBatchDigest(array $members): string {
    return hash('sha256',implode('',array_map(static fn($m)=>$m->role.'|'.$m->uid.'|'.$m->vid."\n",$members)));
}
function testBatchMembers(object $payload): array {
    exactFields((array)$payload,['version','request_id','scope_sha256','members']);
    if ($payload->version!==1 || !isUuid($payload->request_id) || !is_string($payload->scope_sha256)
        || !is_array($payload->members) || count($payload->members)<3 || count($payload->members)>12) { reject(422,'invalid_synthetic_batch'); }
    $expected=[]; $controls=[];
    foreach ($payload->members as $m) {
        if (!$m instanceof stdClass) { reject(422,'invalid_synthetic_member'); }
        exactFields((array)$m,['uid','vid','role']);
        if (!is_string($m->uid) || !is_string($m->vid) || isset($expected[$m->uid])) { reject(422,'invalid_synthetic_identity'); }
        if ($m->role==='target' && preg_match('/\Atest_author_(00[1-9]|010)\z/D',$m->uid,$match)
            && $m->vid===str_replace('author','video',$m->uid)) {
            $region=TEST_BATCH_REGIONS[(int)$match[1]-1];
        } elseif ($m->role==='control' && in_array($m->uid,['test_control_au','test_control_za'],true)
            && $m->vid===str_replace('control_','control_video_',$m->uid)) {
            $region=strtoupper(substr($m->uid,-2));$controls[]=$region;
        } else { reject(422,'synthetic_identity_required'); }
        $expected[$m->uid]=['vid'=>$m->vid,'role'=>$m->role,'region'=>$region];
    }
    sort($controls);
    if ($controls!==['AU','ZA'] || !hash_equals(testBatchDigest($payload->members),$payload->scope_sha256)) { reject(422,'synthetic_scope_mismatch'); }
    return $expected;
}
function validateTestBatchReport(object $payload,object $result): void {
    exactFields((array)$result,['request_id','scope_sha256','source','synthetic','observations']);
    $expected=testBatchMembers($payload);
    if ($result->request_id!==$payload->request_id || $result->scope_sha256!==$payload->scope_sha256
        || $result->source!=='synthetic_test_provider' || $result->synthetic!==true || !is_array($result->observations)
        || count($result->observations)!==count($expected)) { reject(422,'synthetic_report_mismatch'); }
    foreach ($result->observations as $o) {
        if (!$o instanceof stdClass) { reject(422,'invalid_synthetic_observation'); }
        exactFields((array)$o,['uid','vid','role','region','observed_ms','evidence_sha256']);
        $m=is_string($o->uid)?($expected[$o->uid]??null):null;
        if (!$m || $o->vid!==$m['vid'] || $o->role!==$m['role'] || $o->region!==$m['region']
            || !is_int($o->observed_ms) || $o->observed_ms<1 || $o->observed_ms>(time()+1)*1000
            || !is_string($o->evidence_sha256) || !preg_match('/\A[0-9a-f]{64}\z/D',$o->evidence_sha256)) { reject(422,'invalid_synthetic_observation'); }
        unset($expected[$o->uid]);
    }
}
