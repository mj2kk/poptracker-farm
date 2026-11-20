<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/production_worker.php';
require_once dirname(__DIR__).'/production_apify.php';
require_once dirname(__DIR__).'/production_live_feed.php';

function verifyLiveFeed(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS $message\n";
}

$db = database();
$schema = $db->query('SELECT DATABASE()')->fetchColumn();
if (!str_starts_with($schema, 'poptracker_farm_test_') ||
    (int)$db->query('SELECT COUNT(*) FROM production_apify_feed_runs')->fetchColumn() > 0) {
    throw new RuntimeException('Use an isolated test database with an empty Apify ledger.');
}
$observer = database();
putenv('FARM_CATALOG_DB='.$schema);
putenv('FARM_FEED_ARCHIVE='.sys_get_temp_dir().'/absent-fixture-'.deviceId());
$db->exec('CREATE TEMPORARY TABLE poptracker_authors(id VARCHAR(32) PRIMARY KEY)');
$db->exec('CREATE TEMPORARY TABLE poptracker_videos(id VARCHAR(32) PRIMARY KEY,author_id VARCHAR(32) NOT NULL)');
$db->exec('CREATE TEMPORARY TABLE poptracker_observations(id INTEGER,video_id VARCHAR(32),observed_at DATETIME,view_count BIGINT)');
$db->exec("INSERT INTO poptracker_authors VALUES('1001')");
$db->exec("INSERT INTO poptracker_videos VALUES('2001','1001')");

$submit = static fn(string $key) => prodTxn($db, static fn() => liveFeedSubmit($db,$key));
$byKey = static fn(string $key) => query($db,'SELECT job_id FROM production_apify_feed_runs WHERE request_id=?',[$key])->fetchColumn();
$phase = static fn(string $id) => query($db,'SELECT phase FROM production_apify_feed_runs WHERE job_id=?',[$id])->fetchColumn();
$status = static fn(string $id) => query($db,'SELECT status FROM production_jobs WHERE id=?',[$id])->fetchColumn();
$tick = static function (string $id, array $provider) use ($db) {
    // Advance one persisted job without waiting for the wall clock between GETs.
    $claim = query($db, "UPDATE production_jobs SET status='running' WHERE id=? AND status='pending'", [$id]);
    if ($claim->rowCount() !== 1) {
        throw new LogicException('test could not claim pending job');
    }
    liveFeedStep($db, ['id'=>$id], $provider);
};

$key = deviceId();
verifyLiveFeed($submit($key) === 1 && $submit($key) === 0, 'retrying one request ID enqueues one job');
$id = $byKey($key);
verifyLiveFeed(liveFeedCancelAllowed($db,$id), 'queued request can be cancelled before a paid start');
prodCancel($db,$id);
verifyLiveFeed($submit($key) === 0 && !liveFeedBusyReason($db), 'cancelled request cannot be replayed and frees the queue');

$key = deviceId();
$submit($key);
$id = $byKey($key);
try {
    $submit(deviceId());
    throw new LogicException('second concurrent collection accepted');
} catch (ApiError $e) {
    verifyLiveFeed($e->getCode() === 409, 'second active user request is refused');
}
$postCount = 0;
$readCount = 0;
$provider = [
    'preflight'=>static fn() => 'fixture-owner',
    'start'=>static function (string $owner) use ($observer,$db,&$id,&$postCount) {
        $postCount++;
        verifyLiveFeed(!$db->inTransaction() && $owner === 'fixture-owner' &&
            query($observer,'SELECT phase FROM production_apify_feed_runs WHERE job_id=?',[$id])->fetchColumn() === 'starting',
            'separate connection sees durable intent before POST');
        return ['run_id'=>'fixture-run-'.$postCount,'dataset_id'=>'fixture-dataset-'.$postCount];
    },
];
$tick($id,$provider);
verifyLiveFeed($postCount === 1 && $phase($id) === 'polling' && !liveFeedCancelAllowed($db,$id),
    'provider IDs are persisted; an active provider run cannot be cancelled locally');
$observer->exec('START TRANSACTION READ ONLY');
$pendingHistory = prodJobHistory($observer, 'IMPORT_FEED', 'pending');
$observer->exec('ROLLBACK');
verifyLiveFeed(count($pendingHistory) === 1 && $pendingHistory[0]['id'] === $id
    && !$pendingHistory[0]['cancellable'] && $pendingHistory[0]['feed']['cost_usd'] === null,
    'reading an in-flight command journal takes no write locks and preserves unknown cost');
$provider['poll'] = static function (string $owner,string $run,string $dataset) use (&$readCount) {
    $readCount++;
    verifyLiveFeed([$owner,$run,$dataset] === ['fixture-owner','fixture-run-1','fixture-dataset-1'], 'GET addresses the original run');
    throw new ApifyFeedTransient('apify_http_429');
};
$tick($id,$provider);
verifyLiveFeed($status($id) === 'pending' && !(bool)$db->query('SELECT paused FROM production_state')->fetchColumn() &&
    (bool)query($db,'SELECT next_run_at>UTC_TIMESTAMP(6) FROM production_jobs WHERE id=?',[$id])->fetchColumn(),
    'transient GET backs off without pausing other jobs');
$row = ['country'=>'ZA','videoAuthorId'=>'1002','videoId'=>'2002','videoAuthorUsername'=>'new'];
$snap = ['sha'=>str_repeat('a',64),'cost'=>'0.00499000','started'=>'2026-01-01 12:00:00.000000',
    'finished'=>'2026-01-01 12:00:30.000000','received'=>'2026-01-01 12:01:00.000000',
    'rows'=>[$row,$row,array_replace($row,['videoAuthorId'=>'1001','videoId'=>'2001']),array_replace($row,['videoAuthorId'=>null])]];
$provider['poll'] = static function () use (&$readCount,&$snap) {
    $readCount++;
    return $snap;
};
$tick($id,$provider);
verifyLiveFeed($phase($id) === 'polling', 'one terminal read does not open import');
$snap['sha'] = str_repeat('b',64);
$tick($id,$provider);
verifyLiveFeed($phase($id) === 'polling', 'a changed hash requires another terminal confirmation');
$snap['received'] = '2026-01-01 12:01:21.000000';
$tick($id,$provider);
verifyLiveFeed($phase($id) === 'importing' && $readCount === 4 &&
    query($observer,'SELECT source_sha FROM production_apify_feed_runs WHERE job_id=?',[$id])->fetchColumn() === $snap['sha'],
    'stable terminal samples stage rows and source digest durably');
$tick($id,[]);
$job = query($db,'SELECT * FROM production_jobs WHERE id=?',[$id])->fetch();
$record = query($db,'SELECT * FROM production_apify_feed_runs WHERE job_id=?',[$id])->fetch();
verifyLiveFeed($job['status'] === 'completed' && (int)$job['written'] === 1 && (int)$job['rejected'] === 1 &&
    (int)$record['new_candidates'] === 1 && (int)$record['duplicate_videos'] === 2 &&
    (int)$job['cursor_position'] === 4 && (int)$job['total'] === 4, 'import distinguishes new videos, known videos, candidates and rejected rows');
verifyLiveFeed(query($db,"SELECT geo_status FROM production_feed_candidates WHERE author_uid='1002'")->fetchColumn() === 'check_history' &&
    (int)$db->query('SELECT COUNT(*) FROM poptracker_authors')->fetchColumn() === 1, 'without GEO history a new candidate waits for checking');
verifyLiveFeed((int)query($db,'SELECT COUNT(*) FROM production_feed_items WHERE dataset_id=? AND run_id=? AND source_sha=?',
    ['fixture-dataset-1','fixture-run-1',$snap['sha']])->fetchColumn() === 4 && $record['provider_cost_usd'] === $snap['cost'],
    'row locators, verified source hash and actual charge persist');
$entry = prodJobHistory($db, 'IMPORT_FEED', 'completed')[0];
verifyLiveFeed($entry['id'] === $id && $entry['feed']['cost_usd'] === '0.00499000'
    && $entry['feed']['new_candidates'] === 1 && $entry['feed']['duplicate_videos'] === 2
    && (int)$entry['written'] === 1 && (int)$entry['cursor_position'] === 4 && $entry['evaluation'] === null,
    'a command journal exposes the stored cost and per-run counts without parsing log text');
verifyLiveFeed($submit($key) === 0 && $postCount === 1, 'replay after completion does not buy another run');

$knownKey = deviceId();
$submit($knownKey);
$id = $byKey($knownKey);
$tick($id,$provider);
$provider['poll'] = static function () { throw new RuntimeException('apify_run_aborted'); };
$tick($id,$provider);
verifyLiveFeed($status($id) === 'failed' && !liveFeedBusyReason($db), 'verified terminal failure is recorded and releases the paid slot');

$preKey = deviceId();
$submit($preKey);
$id = $byKey($preKey);
$preError = ['preflight'=>static function () { throw new RuntimeException('apify_input_schema_changed'); },'start'=>$provider['start']];
$tick($id,$preError);
verifyLiveFeed($status($id) === 'failed' && $postCount === 2 && !liveFeedBusyReason($db),
    'invalid preflight prevents spending and releases the slot');

$staleKey = deviceId();
$submit($staleKey);
$id = $byKey($staleKey);
query($db, "UPDATE production_apify_feed_runs SET requested_input=JSON_SET(requested_input,'$.maxVideos',99) WHERE job_id=?", [$id]);
$tick($id,$provider);
verifyLiveFeed($status($id) === 'failed' && $postCount === 2 && !liveFeedBusyReason($db),
    'a changed run contract is refused before any provider call');

$crashKey = deviceId();
$submit($crashKey);
$id = $byKey($crashKey);
query($db,"UPDATE production_apify_feed_runs SET phase='starting',start_intent_at=UTC_TIMESTAMP(6) WHERE job_id=?",[$id]);
$tick($id,$provider);
verifyLiveFeed($status($id) === 'blocked' && $phase($id) === 'uncertain' && !liveFeedCancelAllowed($db,$id) && $postCount === 2,
    'recovery from an interrupted paid POST never retries the POST');
verifyLiveFeed($submit($crashKey) === 0, 'replaying the ambiguous request stays idempotent');
try {
    $submit(deviceId());
    throw new LogicException('uncertain spend permitted a second POST');
} catch (ApiError $e) {
    verifyLiveFeed($e->getCode() === 409 && liveFeedBusyReason($db) !== null, 'unresolved charge blocks new purchase requests');
}
// Simulate a completed manual reconciliation only in this isolated fixture.
query($db,"UPDATE production_apify_feed_runs SET phase='failed' WHERE job_id=?",[$id]);
$timeoutKey = deviceId();
$submit($timeoutKey);
$id = $byKey($timeoutKey);
$tick($id,$provider);
query($db,'UPDATE production_apify_feed_runs SET poll_count=90 WHERE job_id=?',[$id]);
$tick($id,$provider);
verifyLiveFeed($status($id) === 'blocked' && $postCount === 3 && str_contains(liveFeedBusyReason($db), 'fixture-run-3') &&
    !(bool)$db->query('SELECT paused FROM production_state')->fetchColumn(), 'bounded GET polling stops with original run ID and keeps unrelated jobs active');

// The next scenario uses its own reconciled ledger and synthetic provider.
query($db,"UPDATE production_apify_feed_runs SET phase='failed' WHERE job_id=?",[$id]);
$sequenceKey = deviceId();
$quantity = 4001;
prodTxn($db, fn () => liveFeedSubmit($db,$sequenceKey,$quantity));
$id = $byKey($sequenceKey);
try {
    prodTxn($db, fn () => liveFeedSubmit($db,$sequenceKey,$quantity-1));
    throw new LogicException('Changed quantity accepted for existing intent');
} catch (ApiError $error) {
    verifyLiveFeed($error->getCode() === 409, 'an existing request ID cannot authorize a different volume');
}
$partSizes = [];
$multiProvider = [
    'preflight'=>static fn() => 'fixture-owner',
    'start'=>static function ($owner,$http,$size) use (&$partSizes) {
        $partSizes[] = $size;
        $n = count($partSizes);
        return ['run_id'=>"multi-$n",'dataset_id'=>"multi-set-$n"];
    },
    'poll'=>static function ($owner,$run,$dataset,$http,$size) use ($row,$snap) {
        $n = (int)substr($run,strlen('multi-'));
        $rows = [$row];
        if ($n === 1) {
            $rows = [];
            for ($i=0; $i<101; $i++) {
                $rows[] = array_replace($row, ['videoId'=>(string)(50000+$i)]);
            }
        }
        return array_replace($snap, ['rows'=>$rows,'sha'=>hash('sha256',$run),'cost'=>$n === 1 ? '0.10099000' : '0.00199000']);
    },
];
for ($i=0; $i<25 && $status($id) !== 'completed'; $i++) {
    $tick($id,$multiProvider);
    $cursor = query($db,'SELECT import_cursor FROM production_apify_feed_runs WHERE job_id=?',[$id])->fetchColumn();
    if ((int)$cursor === 100) {
        verifyLiveFeed($phase($id) === 'importing' && (int)query($db,'SELECT cursor_position FROM production_jobs WHERE id=?',[$id])->fetchColumn() === 100,
            'a 100-row checkpoint and its imported rows survive the next worker process');
    }
}
$r = query($db,'SELECT * FROM production_apify_feed_runs WHERE job_id=?',[$id])->fetch();
$j = query($db,'SELECT * FROM production_jobs WHERE id=?',[$id])->fetch();
verifyLiveFeed($partSizes === [2000,2000,1] && $j['status'] === 'completed' && (int)$j['cursor_position'] === 103 && (int)$j['written'] === 101,
    'each bounded tranche is bought once and a repeated video is not counted as new across datasets');
verifyLiveFeed($r['provider_cost_usd'] === '0.10497000' && $r['max_charge_usd'] === '3.99000000' &&
    (int)query($db,'SELECT COUNT(*) FROM production_apify_feed_batches WHERE job_id=? AND row_count IS NOT NULL',[$id])->fetchColumn() === 3,
    'actual costs add across all three retained provider receipts inside the approved cap');

$emptyKey = deviceId();
prodTxn($db, fn () => liveFeedSubmit($db,$emptyKey,10000));
$id = $byKey($emptyKey);
$multiProvider['poll'] = static fn () => array_replace($snap, ['rows'=>[],'cost'=>'0.00100000']);
for ($i=0; $i<4; $i++) { $tick($id,$multiProvider); }
verifyLiveFeed($partSizes === [2000,2000,1,2000] && $status($id) === 'completed' &&
    (int)query($db,'SELECT total FROM production_jobs WHERE id=?',[$id])->fetchColumn() === 0,
    'a verified empty batch stops the request before any further paid starts');
