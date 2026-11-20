<?php

declare(strict_types=1);
require dirname(__DIR__).'/production.php';

function checkTargetSelection(bool $ok, string $name): void
{
    if (! $ok) {
        throw new RuntimeException($name);
    }
    echo "PASS $name\n";
}

$db = database();
$cat = prodCatalogName();
if (! str_starts_with((string) $db->query('SELECT DATABASE()')->fetchColumn(), 'poptracker_farm_test_')
    || ! str_starts_with($cat, 'dataroom_test_')) {
    throw new RuntimeException('Disposable farm and catalog databases required');
}
$catalog = prodCatalogConnection();
query($catalog, "INSERT INTO poptracker_authors(id,username,last_seen_at) VALUES('1000','fixture',UTC_TIMESTAMP(6))");
foreach (array_merge(range(101, 106), range(201, 206)) as $id) {
    $published = $id === 203 ? '2020-01-05' : '2020-01-01';
    query($catalog, "INSERT INTO poptracker_videos(id,author_id,url,published_at,first_seen_at,last_seen_at)
        VALUES(?,'1000',?,?,?,?)", [$id, 'https://example.test/video/'.$id, $published, $published, $published]);
}
function observation(PDO $catalog, int $video, string $time, ?int $views, string $source = 'Feed'): int
{
    query($catalog, "INSERT INTO poptracker_scans(source,external_run_id,finished_at,request_market,payload_sha256,imported_at,row_count,new_video_count)
        VALUES(?,?,UTC_TIMESTAMP(6),'ZA',REPEAT('a',64),UTC_TIMESTAMP(6),1,0)", [$source, deviceId()]);
    $scan = $catalog->lastInsertId();
    query($catalog, 'INSERT INTO poptracker_observations(scan_id,video_id,observed_at,source_row_number,view_count)
        VALUES(?,?,?,1,?)', [$scan, $video, $time, $views]);
    return (int) $catalog->lastInsertId();
}
$first = '2020-01-02';
$second = '2020-01-03';
foreach (range(101, 106) as $id) {
    observation($catalog, $id, $first, 10);
    observation($catalog, $id, $second, 10);
}
observation($catalog, 101, $first, 50, 'other');
observation($catalog, 101, $first, 10);
observation($catalog, 101, $second, 1, 'other');
observation($catalog, 101, $second, 10);
observation($catalog, 101, $second, null, 'other');
$backfill = observation($catalog, 101, '2020-01-01', 90, 'other');
$future = observation($catalog, 102, '2099-01-01', 1, 'other');
observation($catalog, 201, $first, 10);
observation($catalog, 201, $second, 20, 'feed');
observation($catalog, 202, $first, 10);
observation($catalog, 202, $second, 9);
observation($catalog, 203, $first, 10);
observation($catalog, 203, $second, 11);
observation($catalog, 204, $first, 10);
observation($catalog, 205, $first, 10);
observation($catalog, 205, $first, 11);
observation($catalog, 206, $first, null);

$version = 'fixture-v1';
$targets = prodEvaluationTargets($db, $version);
checkTargetSelection(array_column($targets, 'video_id') === ['102','101','106','105','104'],
    'at most five comparable videos are selected ahead of more recently ingested insufficient histories');
checkTargetSelection((int) $targets[0]['ver'] === $future && (int) $targets[1]['ver'] === $backfill,
    'revision uses the maximum non-null observation ID independently of evaluation time');
$model = static fn (array $packet): array => [
    'video_id' => $packet['video_id'], 'input_sha256' => $packet['input_sha256'], 'attention_status' => 'low',
    'collect_comments' => false, 'potential_trend' => false, 'reason' => 'fixture',
];
foreach ($targets as $row) {
    $decision = productionEvaluateVideo($catalog, $row['video_id'], $model, $version);
    checkTargetSelection($decision['status'] === 'evaluated' && $decision['features']['views_per_hour'] === 0.0,
        'evaluator agrees for video '.$row['video_id']);
}
prodEnqueue($db, 'EVALUATE_VIDEO', '101', 'older', 40, 1);
prodEnqueue($db, 'EVALUATE_VIDEO', '102', $future.':'.$version, 40, 1);
query($db, "UPDATE production_jobs SET status='completed' WHERE target='102'");
$targets = prodEvaluationTargets($db, $version);
checkTargetSelection(array_column($targets, 'video_id') === ['106','105','104','103','203'],
    'pending target and completed revision are excluded while sparse input remains eligible');
observation($catalog, 102, $first, 10);
checkTargetSelection(prodEvaluationTargets($db, $version)[0]['video_id'] === '102', 'new revision becomes eligible after completion');
foreach (range(102, 106) as $id) {
    prodEnqueue($db, 'EVALUATE_VIDEO', (string) $id, 'new-active', 40, 1);
}
$fallback = prodEvaluationTargets($db, $version);
checkTargetSelection(array_column($fallback, 'video_id') === ['203','202','201','205','204'],
    'pre-publication points, corrections, case-sensitive source changes and one-timestamp histories remain available');
foreach ($fallback as $row) {
    checkTargetSelection(productionEvaluateVideo($catalog, $row['video_id'], $model, $version)['status'] === 'insufficient_data',
        'evaluator returns insufficient data for video '.$row['video_id']);
}
