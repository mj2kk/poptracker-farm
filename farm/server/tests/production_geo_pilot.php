<?php

declare(strict_types=1);
require dirname(__DIR__).'/bin/geo-pilot.php';

$db = database();
$catalog = feedCatalogName();
if (!str_starts_with((string)$db->query('SELECT DATABASE()')->fetchColumn(), 'poptracker_farm_test_')
    || !str_starts_with($catalog, 'dataroom_test_')) {
    throw new RuntimeException('Disposable farm and catalog required');
}

function checkPilot(bool $ok, string $name): void
{
    if (!$ok) { throw new RuntimeException($name); }
    echo "PASS $name\n";
}

[, $history] = feedGeoRegistry();
$db->exec("CREATE TEMPORARY TABLE $catalog.poptracker_authors(id VARCHAR(32) PRIMARY KEY,username VARCHAR(80))");
$db->exec("CREATE TEMPORARY TABLE $catalog.poptracker_videos(id VARCHAR(32) PRIMARY KEY,author_id VARCHAR(32))");
$db->exec("CREATE TEMPORARY TABLE $catalog.poptracker_author_geo_evidence(id INT AUTO_INCREMENT PRIMARY KEY,
    author_id VARCHAR(32),video_id VARCHAR(32),kind VARCHAR(40),country_code CHAR(2),observed_at DATETIME(6),evidence_key CHAR(64),sources JSON)");
$attempted = (string)array_key_first($history);
$ids = [$attempted, '1001','1002','1003','1004','1005','1006','1007','1008','1009','1010','1011'];
$job = deviceId();
$sha = str_repeat('f', 64);
$rows = [];
foreach ($ids as $n=>$uid) {
    $rows[] = ['videoAuthorId'=>$uid,'videoId'=>(string)(3000+$n),'videoAuthorUsername'=>'candidate'.$n,'country'=>'ZA'];
}
$db->beginTransaction();
try {
    query($db,"INSERT INTO production_jobs(id,type,target,revision,identity_key,status,priority,created_at,next_run_at)
        VALUES(?,'IMPORT_FEED','ZA','test-source',SHA2(?,256),'completed',1,NOW(),NOW())", [$job,$job]);
    $rows[4]['videoAuthorId'] = '9988';
    $rows[5]['videoId'] = '6969';
    $rows[6]['videoAuthorUsername'] = 'different';
    query($db,"INSERT INTO production_apify_feed_runs(job_id,request_id,actor_id,build_id,requested_input,max_charge_usd,phase,
        run_id,dataset_id,source_sha,projected_rows) VALUES(?,?,'fixture','fixture',JSON_OBJECT(),0,'done','testRun','testData',?,?)",
        [$job,deviceId(),$sha,json_encode($rows, JSON_THROW_ON_ERROR)]);
    query($db,"INSERT INTO production_apify_feed_batches(job_id,batch_number,requested_count,max_charge_usd,run_id,dataset_id,
        source_sha,row_count,finished_at) VALUES(?,1,100,0,'testRun','testData',?,?,NOW())", [$job,$sha,count($rows)]);
    foreach ($ids as $n=>$uid) {
        $vid = (string)(3000+$n);
        query($db, "INSERT INTO production_feed_candidates VALUES(?,?,?,'pending',?)",
            [$uid,'candidate'.$n,$vid,$n===0 ? '2000-01-01' : '2000-01-02']);
        query($db, "INSERT INTO production_feed_videos (video_id,author_uid,metadata,discovered_at) VALUES(?,?,JSON_OBJECT(),NOW())",
            [$vid,$n===3 ? '8888' : $uid]);
        query($db, "INSERT INTO production_feed_items(dataset_id,`row_number`,run_id,source_sha,video_id,received_at)
            VALUES('testData',?,?,?,?,NOW())", [$n,$n===7 ? 'differentRun':'testRun',$n===8 ? str_repeat('0',64):$sha,$vid]);
    }
    query($db,"INSERT INTO $catalog.poptracker_authors (id) VALUES(?)", [$ids[1]]);
    query($db,"INSERT INTO production_geo_history VALUES(?,'unknown',NULL,?,'222')", [$ids[2],$sha]);
    $oldJob = deviceId();
    query($db,"INSERT INTO production_jobs(id,type,target,revision,identity_key,status,priority,created_at,next_run_at)
        VALUES(?,'VALIDATE_GEO',?,'test-old',SHA2(?,256),'failed',1,NOW(),NOW())", [$oldJob,$ids[9],$oldJob]);

    $first = geoPilotTarget($db);
    checkPilot($first !== null && $first['author_uid'] === $ids[10] && $first['username'] === 'candidate10'
        && $first['video_id'] === '3010', 'selects first eligible pair after exclusions for registry, catalog, previous jobs and mismatched identities');
    checkPilot($first['source']['job_id'] === $job && $first['source']['row_index'] === 10
        && $first['source']['dataset_sha256'] === $sha && $first['source']['run_id'] === 'testRun'
        && $first['source']['dataset_id'] === 'testData', 'source locator identifies the exact row of the completed batch');
    checkPilot(array_column(geoPilotTargets($db), 'author_uid') === [$ids[10], $ids[11]],
        'preview includes distinct eligible authors in order');
    checkPilot(geoPilotTarget($db) === $first, 'repeat is deterministic and leaves the candidate eligible');
    query($db,"UPDATE production_feed_candidates SET geo_status='unknown' WHERE author_uid=?",[$ids[10]]);
    checkPilot(geoPilotTarget($db)['author_uid'] === $ids[11], 'next eligible author follows a finalized candidate');
    query($db,"UPDATE production_jobs SET status='failed' WHERE id=?",[$job]);
    checkPilot(geoPilotTarget($db) === null, 'failed parent Feed cannot prepare a candidate');
    query($db,"UPDATE production_jobs SET status='completed' WHERE id=?",[$job]);
    query($db,"UPDATE production_apify_feed_runs SET projected_rows=NULL WHERE job_id=?",[$job]);
    checkPilot(geoPilotTarget($db) === null, 'missing saved projection cannot attest a candidate identity');

    [$au,$za] = geoPilotControls($db);
    checkPilot($au['reference_status'] === 'catalog_author_missing' && $au['historical_reference'] === null,
        'missing control author is reported without inventing a country');
    foreach ([$au,$za] as $control) {
        query($db,"INSERT INTO $catalog.poptracker_authors VALUES(?,?)",[$control['author_uid'],$control['username']]);
    }
    $save = static function (array $control, string $kind, string $country, string $time, array $source = []) use ($db,$catalog): void {
        query($db,"INSERT INTO $catalog.poptracker_author_geo_evidence(author_id,kind,country_code,observed_at,evidence_key,sources)
            VALUES(?,?,?,?,?,?)",[$control['author_uid'],$kind,$country,$time,hash('sha256',serialize([$kind,$country,$time,$source])),
                json_encode((object)$source,JSON_THROW_ON_ERROR)]);
    };
    $save($au,'mobile_user_region','US','2026-11-10');
    $save($au,'mobile_user_region','AU','2026-11-11');
    $save($au,'mobile_user_region','XX','2026-11-12');
    $save($au,'euler_account_region','GB','2026-11-13');
    [$a,$z] = geoPilotControls($db);
    checkPilot($a['historical_reference']['country_code'] === 'AU' && $a['historical_reference']['observed_at_utc'] === '2026-11-11T00:00:00.000000Z'
        && $a['historical_reference']['evidence_key'] !== null && $a['video_binding'] === 'E078_archive_only'
        && $a['reference_status'] === 'historical_country_matches', 'latest explicit mobile country with observation date is used; archived VID is labeled independently');
    checkPilot($z['historical_reference'] === null && $z['reference_status'] === 'historical_country_missing',
        'missing country is not replaced with the configured ZA baseline');
    $save($za,'mobile_user_region','ZA','2026-11-11');
    $save($za,'mobile_user_region','BR','2026-11-11');
    $z = geoPilotControls($db)[1];
    checkPilot($z['configured_country_code'] === 'ZA' && $z['historical_reference']['country_code'] === 'BR'
        && $z['reference_status'] === 'historical_country_mismatch', 'conflicting latest record wins timestamp ties by evidence ID and is visible as a mismatch');
    $save($za,'mobile_user_region','ZA','2026-11-15', ['video_id'=>$za['video_id'],'expected_uid'=>$za['author_uid'],
        'observed_uid'=>$za['author_uid'],'identity_status'=>'exact_uid_and_video']);
    checkPilot(geoPilotControls($db)[1]['video_binding'] === 'historical_geo_evidence', 'exact historical source VID and UID can bind a control absent from the video table');
    query($db,"INSERT INTO $catalog.poptracker_videos VALUES(?,?)", [$za['video_id'],$au['author_uid']]);
    $z = geoPilotControls($db)[1];
    checkPilot($z['video_binding'] === 'catalog_owner_conflict' && $z['reference_status'] === 'catalog_video_owner_mismatch',
        'a different catalog video owner cannot be hidden by a matching historical country');
    query($db,"UPDATE $catalog.poptracker_videos SET author_id=? WHERE id=?", [$za['author_uid'],$za['video_id']]);
    checkPilot(geoPilotControls($db)[1]['video_binding'] === 'catalog_owner_matches', 'catalog agreement is labeled as such');
    query($db,"UPDATE $catalog.poptracker_authors SET username='different' WHERE id=?",[$za['author_uid']]);
    checkPilot(geoPilotControls($db)[1]['reference_status'] === 'catalog_username_mismatch', 'changed control handle is reported separately');
} finally {
    $db->rollBack();
}
