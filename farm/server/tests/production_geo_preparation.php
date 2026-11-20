<?php
declare(strict_types=1);

require dirname(__DIR__).'/production_worker.php';

function checkGeo(bool $ok, string $case): void
{
    if (!$ok) {
        throw new RuntimeException($case);
    }
    echo "PASS $case\n";
}

function expectGeoError(callable $operation, string $case): void
{
    try {
        $operation();
    } catch (RuntimeException $e) {
        checkGeo(!($e instanceof PDOException), $case);
        return;
    }
    throw new RuntimeException($case);
}

$db = database();
$schema = (string) $db->query('SELECT DATABASE()')->fetchColumn();
$cat = prodCatalogName();
if (!str_starts_with($schema,'poptracker_farm_test_') || !str_starts_with($cat,'dataroom_test_')) {
    throw new RuntimeException('Disposable farm and catalog required');
}
$archive = getenv('FARM_TEST_GEO_REGISTRY') ?: null;
$scratch = sys_get_temp_dir().'/geo-preparation-'.deviceId();
mkdir($scratch,0700);
mkdir($scratch.'/geo-history',0700);
$fresh = '1001';
$stale = '1002';
$unknown = '1003';
$attempt = '1004';
$unseen = '1005';
$oldPending = '1006';
$facts = [
    $fresh=>['status'=>'ZA','region'=>'ZA','video_id'=>'80001'],
    $stale=>['status'=>'non-ZA','region'=>'GB','video_id'=>'80002'],
    $unknown=>['status'=>'unknown','region'=>null,'video_id'=>'80003'],
    $oldPending=>['status'=>'ZA','region'=>'ZA','video_id'=>'80006'],
];
$previous = array_fill_keys([$fresh,$stale,$unknown,$attempt,$oldPending],true);
$reader = static fn() => [$facts,$previous];
$insert = function (string $uid,string $status) use($db): void {
    query($db, "INSERT INTO production_feed_candidates(author_uid,sample_video_id,geo_status,discovered_at)
        VALUES(?,'99999',?,UTC_TIMESTAMP(6))",[$uid,$status]);
};
$statuses = fn() => query($db,'SELECT author_uid,geo_status FROM production_feed_candidates ORDER BY author_uid')->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ([$fresh,$stale,$unknown,$attempt,$unseen] as $uid) {
    $insert($uid,'check_history');
}
$insert($oldPending,'pending');
$insert('1007','unknown');
$original = $statuses();
try {
    putenv('FARM_FEED_ARCHIVE='.$scratch);
    expectGeoError(fn() => prodPrepareGeoCandidates($db), 'missing archive is never evidence');
    file_put_contents($scratch.'/geo-history/all-qualified-outcomes.json','[]');
    expectGeoError(fn() => prodPrepareGeoCandidates($db), 'corrupt archive does not release unverified candidates');
    checkGeo($statuses() === $original, 'missing and corrupt files preserve the original statuses');
    prodControl($db,true);
    checkGeo(prodPrepareGeoCandidates($db, $reader) === 0 && $statuses() === $original, 'pause holds historical preparation');
    prodControl($db,false);
    query($db,"INSERT INTO production_geo_history(author_uid,status,region,proof_sha,video_id)
        VALUES(?,'non-ZA','GB',REPEAT('f',64),'80808')",[$stale]);
    expectGeoError(fn() => prodPrepareGeoCandidates($db,$reader), 'conflicting old evidence aborts the whole batch');
    checkGeo($statuses() === $original && (int)query($db,'SELECT COUNT(*) FROM production_geo_history')->fetchColumn() === 1,
        'conflict rolls back both earlier evidence and candidate updates');
    query($db,'DELETE FROM production_geo_history WHERE author_uid=?',[$stale]);
    checkGeo(prodPrepareGeoCandidates($db,$reader) === 5, 'only previously unverified candidates are reconciled');
    $states = $statuses();
    checkGeo($states[$fresh] === 'ZA' && $states[$stale] === 'non-ZA' && $states[$unknown] === 'unknown',
        'verified country and recorded unknown retain distinct outcomes');
    checkGeo($states[$attempt] === 'previous_attempt' && $states[$unseen] === 'pending',
        'previous attempt without a country is distinct from a never-checked author');
    checkGeo($states[$oldPending] === 'pending' && $states[1007] === 'unknown', 'previously checked and final rows survive reconciliation unchanged');
    foreach ([$fresh,$stale,$unknown] as $uid) {
        $row = query($db,'SELECT proof_sha,video_id FROM production_geo_history WHERE author_uid=?',[$uid])->fetch();
        if ($row['video_id'] !== $facts[$uid]['video_id'] || $row['proof_sha'] !== E078_GEO_SOURCE) {
            throw new RuntimeException('lost prior proof identity');
        }
    }
    checkGeo((int)query($db,'SELECT COUNT(*) FROM production_geo_history')->fetchColumn() === 3,
        'historical proof points to the original video for the same author');
    checkGeo(prodPrepareGeoCandidates($db,$reader) === 0 && $states === $statuses(), 'another run does not reprocess checked candidates');
    $item = ['country'=>'ZA','videoId'=>'92345','videoAuthorId'=>'2345','videoAuthorUsername'=>'fixture'];
    $source = ['dataset'=>'fixture','run'=>'fixture','row'=>0,'sha'=>str_repeat('a',64),'received'=>'2026-11-10 10:00:00'];
    $new = prodTxn($db,fn() => feedApplyItem($db,$item,$source,$cat,[],[],'check_history'));
    checkGeo($new === [1,0,1] && $statuses()[2345] === 'check_history', 'a saved Feed row can wait for subsequent background preparation');
    query($db,'UPDATE production_state SET summary_at=NULL,catalog_summary=NULL WHERE id=1');
    prodRefreshSummary($db);
    $summary = json_decode(query($db,'SELECT catalog_summary FROM production_state WHERE id=1')->fetchColumn(),true,flags:JSON_THROW_ON_ERROR);
    checkGeo(($summary['geo_registry_retry_at'] ?? 0) > time() && $statuses()[2345] === 'check_history'
        && !(bool)query($db,'SELECT paused FROM production_state WHERE id=1')->fetchColumn(),
        'scheduler backs off an invalid archive while keeping the overall queue available');
    if ($archive !== null) {
        putenv('FARM_FEED_ARCHIVE='.$archive);
        [$verified,$allPrevious] = feedGeoRegistry();
        $historic = (string)array_key_first($verified);
        $insert($historic,'check_history');
        query($db,'UPDATE production_state SET summary_at=NULL WHERE id=1');
        prodRefreshSummary($db);
        checkGeo($statuses()[$historic] === 'check_history', 'refresh during backoff defers even a now-valid archive');
        query($db,"UPDATE production_state SET summary_at=NULL,catalog_summary=JSON_SET(catalog_summary,'$.geo_registry_retry_at',0) WHERE id=1");
        prodRefreshSummary($db);
        checkGeo($statuses()[$historic] === $verified[$historic]['status'] && count($verified) === 3471 && count($allPrevious) === 18801,
            'normal refresh consumes the independently verified archive once backoff expires');
    }
    checkGeo((int)query($db,'SELECT COUNT(*) FROM production_jobs')->fetchColumn() === 0
        && (int)query($db,"SELECT COUNT(*) FROM $cat.poptracker_authors")->fetchColumn() === 0,
        'preparation creates no GEO jobs and does not promote catalogue authors');
} finally {
    if (is_file($scratch.'/geo-history/all-qualified-outcomes.json')) {
        unlink($scratch.'/geo-history/all-qualified-outcomes.json');
    }
    rmdir($scratch.'/geo-history');
    rmdir($scratch);
}
