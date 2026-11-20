<?php

declare(strict_types=1);
require dirname(__DIR__).'/production_worker.php';

function verifyProd(bool $ok, string $case): void
{
    if (! $ok) {
        throw new RuntimeException($case);
    }
    echo "PASS $case\n";
}
$db = database();
$cat = prodCatalogName();
if (! str_contains($cat, 'test_') || ! str_contains((string) $db->query('SELECT DATABASE()')->fetchColumn(), 'test_')) {
    throw new RuntimeException('Test databases required');
}
query($db, "INSERT INTO $cat.poptracker_authors(id,username,last_seen_at) VALUES('10001','scheduler-fixture',UTC_TIMESTAMP(6))");
query($db, "INSERT INTO $cat.poptracker_videos(id,author_id,url,published_at,first_seen_at,last_seen_at) VALUES('10002','10001','https://example.test/video/10002',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))");
query($db, "INSERT INTO $cat.poptracker_scans(id,source,external_run_id,finished_at,request_market,payload_sha256,imported_at,row_count,new_video_count) VALUES (10,'fixture','scheduler',UTC_TIMESTAMP(6),'ZA',REPEAT('a',64),UTC_TIMESTAMP(6),1,0)");
query($db, "INSERT INTO $cat.poptracker_observations(id,scan_id,video_id,observed_at,source_row_number,view_count) VALUES (10,10,'10002',UTC_TIMESTAMP(6),1,200)");
foreach (range(1, 101) as $i) {
    query($db, "INSERT INTO $cat.poptracker_comments(id,video_id,body,source,source_run_id,observed_at,country_status)
        VALUES(?,'10002',?,'archive','saved-run',UTC_TIMESTAMP(6),'unknown')", [(string) (90000 + $i), 'Fixture '.$i]);
}
function testEvaluation(PDO $db, bool $allowed): string
{
    $id = deviceId();
    query($db, "INSERT INTO production_evaluations(job_id,video_id,observation_version,status,attention_status,collect_comments,input_sha256,rules_version,model_version,evaluated_at,decision)
        VALUES(?,'10002',10,'evaluated',?,?,?,?,'fixture',UTC_TIMESTAMP(6),JSON_OBJECT())",
        [$id, $allowed ? 'high' : 'low', (int) $allowed, str_repeat('a', 64), PRODUCTION_TREND_RULES]);

    return $db->lastInsertId();
}
verifyProd(! prodCommentTargets($db), 'no approved decision excludes texts');
testEvaluation($db, false);
verifyProd(! prodCommentTargets($db), 'AI rejection excludes texts');
$a = testEvaluation($db, true);
verifyProd(count(prodCommentTargets($db)) === 1, 'latest positive decision selects only its video');
$enqueued = prodSubmit($db, 'COLLECT_COMMENTS');
verifyProd($enqueued['queued'] === 1, 'one durable target enqueued');
try {
    prodSubmit($db, 'COLLECT_COMMENTS');
    throw new RuntimeException('duplicate accepted');
} catch (ApiError $e) {
    verifyProd($e->getCode() === 409, 'repeated submission is deduplicated');
}
prodControl($db, true);
verifyProd(! prodWorkerTick($db), 'manual pause prevents claims');
prodControl($db, false);
verifyProd(prodWorkerTick($db) && (int) $db->query('SELECT COUNT(*) FROM production_comment_context')->fetchColumn() === 100, 'atomic partial batch checkpoints 100 of 101');
testEvaluation($db, false);
prodWorkerTick($db);
verifyProd((int) $db->query("SELECT COUNT(*) FROM production_jobs WHERE status='blocked'")->fetchColumn() === 1, 'revoked AI approval blocks remaining work');
$fresh = testEvaluation($db, true);
prodSubmit($db, 'COLLECT_COMMENTS');
prodWorkerTick($db);
verifyProd((int) $db->query('SELECT COUNT(*) FROM production_comment_context')->fetchColumn() === 101, 'new eligible evaluation resumes remaining text without duplicates');
verifyProd(! prodCommentTargets($db), 'fully processed cached context is not collected twice');
$legacy = (int) $db->query("SELECT COUNT(*) FROM $cat.poptracker_comments")->fetchColumn();
verifyProd($legacy === 101, 'original comments preserved');
foreach (array_keys(PRODUCTION_BLOCKERS) as $type) {
    try {
        prodSubmit($db, $type);
        throw new RuntimeException('unavailable accepted');
    } catch (ApiError $e) {
        verifyProd($e->getCode() === 409, 'unavailable source rejected '.$type);
    }
}
prodEnqueue($db, 'IMPORT_FEED', 'test-cancel', 'v1', 1, 1);
$id = query($db, "SELECT id FROM production_jobs WHERE target='test-cancel'")->fetchColumn();
verifyProd(prodCancel($db, $id)['status'] === 'cancelled', 'pending job may be cancelled');
prodEnqueue($db, 'IMPORT_FEED', 'missing-test-archive', 'v1', 1, 1);
prodWorkerTick($db);
verifyProd((bool) $db->query('SELECT paused FROM production_state')->fetchColumn() && (int) $db->query("SELECT COUNT(*) FROM production_jobs WHERE status='failed'")->fetchColumn() === 1, 'source failure stops the queue without automatic retry');
prodControl($db, false);
prodEnqueue($db, 'IMPORT_FEED', 'interrupted-test', 'v1', 1, 1);
query($db, "UPDATE production_jobs SET status='running',attempts=2 WHERE target='interrupted-test'");
prodWorkerTick($db);
verifyProd(query($db, "SELECT status FROM production_jobs WHERE target='interrupted-test'")->fetchColumn() === 'blocked', 'repeated worker crash becomes blocked');
$state = prodSnapshot($db, 'COLLECT_COMMENTS', 'completed');
verifyProd(count($state['jobs']) === 1 && $state['overview']['commented_videos'] === 1, 'filtered history and measured overview');

query($db,"INSERT INTO $cat.poptracker_scans(id,source,external_run_id,finished_at,request_market,payload_sha256,imported_at,row_count,new_video_count) VALUES (11,'fixture','scheduler2',UTC_TIMESTAMP(6),'ZA',REPEAT('b',64),UTC_TIMESTAMP(6),1,0)");
query($db,"INSERT INTO $cat.poptracker_observations(id,scan_id,video_id,observed_at,source_row_number,view_count) VALUES (11,11,'10002',UTC_TIMESTAMP(6),2,201)");
verifyProd(! (bool) query($db,'SELECT 1 FROM production_evaluations e WHERE '.prodLatestApproval())->fetchColumn(),'newer catalog observation invalidates AI approval even before reevaluation');
