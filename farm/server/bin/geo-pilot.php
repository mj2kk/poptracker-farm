<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/farm.php';
require_once dirname(__DIR__).'/production_feed.php';

/** Only a completed Apify batch whose original row projection is still available can supply this preview. */
function geoPilotTargets(PDO $db): array
{
    [, $attempted] = feedGeoRegistry();
    $catalog = feedCatalogName();
    $excluded = json_encode(array_map('strval', array_keys($attempted)), JSON_THROW_ON_ERROR);
    $rows = query($db, "WITH eligible AS (SELECT c.author_uid,c.username,v.video_id,j.id AS job_id,b.batch_number,i.run_id,
            i.dataset_id,i.`row_number`,i.source_sha,i.received_at,c.discovered_at,
            ROW_NUMBER() OVER (PARTITION BY c.author_uid ORDER BY i.received_at,i.dataset_id,i.`row_number`,j.id,b.batch_number) AS source_position
        FROM production_feed_candidates c
        JOIN production_feed_videos v ON v.video_id=c.sample_video_id AND v.author_uid=c.author_uid
        JOIN production_feed_items i ON i.video_id=v.video_id AND i.error_code IS NULL
        JOIN production_apify_feed_batches b ON b.dataset_id=i.dataset_id AND b.run_id=i.run_id
            AND BINARY b.source_sha=BINARY i.source_sha AND i.`row_number`<b.row_count AND b.finished_at IS NOT NULL
        JOIN production_apify_feed_runs r ON r.job_id=b.job_id AND r.batch_number=b.batch_number
            AND r.dataset_id=b.dataset_id AND r.run_id=b.run_id AND BINARY r.source_sha=BINARY b.source_sha
            AND r.phase='done' AND JSON_LENGTH(r.projected_rows)=b.row_count
        JOIN production_jobs j ON j.id=r.job_id AND j.type='IMPORT_FEED' AND j.target='ZA' AND j.status='completed'
        JOIN JSON_TABLE(JSON_EXTRACT(r.projected_rows,CONCAT('$[',i.`row_number`,']')), '$' COLUMNS(
            uid VARCHAR(64) PATH '$.videoAuthorId', vid VARCHAR(64) PATH '$.videoId',
            username VARCHAR(255) PATH '$.videoAuthorUsername', market VARCHAR(8) PATH '$.country'
        )) src ON BINARY src.uid=BINARY c.author_uid AND BINARY src.vid=BINARY v.video_id
            AND BINARY src.username=BINARY c.username AND BINARY src.market=BINARY 'ZA'
        WHERE c.geo_status='pending' AND REGEXP_LIKE(c.author_uid,'^[1-9][0-9]{0,31}$','c')
            AND REGEXP_LIKE(v.video_id,'^[1-9][0-9]{0,31}$','c')
            AND REGEXP_LIKE(c.username,'^[A-Za-z0-9._]{1,80}$','c')
            AND REGEXP_LIKE(i.source_sha,'^[0-9a-f]{64}$','c')
            AND NOT EXISTS(SELECT 1 FROM $catalog.poptracker_authors a WHERE a.id=CAST(c.author_uid AS BINARY))
            AND NOT EXISTS(SELECT 1 FROM production_geo_history h WHERE h.author_uid=c.author_uid)
            AND NOT EXISTS(SELECT 1 FROM production_jobs g WHERE g.type='VALIDATE_GEO' AND g.target=c.author_uid
                AND (g.status<>'cancelled' OR g.started_at IS NOT NULL))
            AND NOT EXISTS(SELECT 1 FROM JSON_TABLE(?, '$[*]' COLUMNS(uid VARCHAR(32) PATH '$')) prev
                WHERE prev.uid=CAST(c.author_uid AS BINARY))
        ) SELECT * FROM eligible WHERE source_position=1
        ORDER BY discovered_at,author_uid LIMIT 10", [$excluded])->fetchAll();
    return array_map(static fn (array $result): array => [
        'author_uid'=>$result['author_uid'], 'username'=>$result['username'], 'video_id'=>$result['video_id'],
        'source'=>['job_id'=>$result['job_id'], 'batch_number'=>(int)$result['batch_number'],
            'run_id'=>$result['run_id'], 'dataset_id'=>$result['dataset_id'], 'row_index'=>(int)$result['row_number'],
            'dataset_sha256'=>$result['source_sha'], 'received_at_utc'=>$result['received_at']],
    ], $rows);
}

function geoPilotTarget(PDO $db): ?array
{
    return geoPilotTargets($db)[0] ?? null;
}

/** The UID/VID pairs are fixed E078 controls. Their reference countries are read from Vuka on each preview. */
function geoPilotControls(PDO $db): array
{
    $pairs = [
        ['7632224740756898837', 'cobblewobbleoce', '7678277351934856466', 'AU'],
        ['7460188221663233029', 'bontsoanethemba04', '7678816459077766418', 'ZA'],
    ];
    $catalog = feedCatalogName();
    $controls = [];
    foreach ($pairs as [$uid,$username,$vid,$baseline]) {
        $row = query($db, "SELECT a.username,v.author_id AS video_owner,
                g.country_code,g.evidence_key,g.video_id AS evidence_video_id,
                g.sources->>'$.video_id' AS source_video_id,g.sources->>'$.expected_uid' AS source_expected_uid,
                g.sources->>'$.observed_uid' AS source_observed_uid,g.sources->>'$.identity_status' AS source_identity,
                DATE_FORMAT(g.observed_at,'%Y-%m-%dT%H:%i:%s.%fZ') AS observed_at
            FROM $catalog.poptracker_authors a
            LEFT JOIN $catalog.poptracker_videos v ON v.id=?
            LEFT JOIN $catalog.poptracker_author_geo_evidence g ON g.author_id=a.id AND g.kind='mobile_user_region'
                AND REGEXP_LIKE(g.country_code,'^[A-Z]{2}$','c') AND g.country_code NOT IN ('ZZ','XX')
            WHERE a.id=? ORDER BY g.observed_at DESC,g.id DESC LIMIT 1", [$vid,$uid])->fetch();
        $owner = $row['video_owner'] ?? null;
        $country = $row['country_code'] ?? null;
        $evidenceBindsVideo = $row && ($row['evidence_video_id'] === $vid || ($row['source_video_id'] === $vid
            && $row['source_expected_uid'] === $uid && $row['source_observed_uid'] === $uid
            && $row['source_identity'] === 'exact_uid_and_video'));
        $controls[] = [
            'author_uid'=>$uid, 'username'=>$username, 'video_id'=>$vid, 'video_id_source'=>'E078_control_pair',
            'catalog_username'=>$row['username'] ?? null,
            'video_binding'=>match (true) {
                $owner === $uid => 'catalog_owner_matches',
                $owner !== null => 'catalog_owner_conflict',
                $evidenceBindsVideo => 'historical_geo_evidence',
                default => 'E078_archive_only',
            },
            'configured_country_code'=>$baseline,
            'historical_reference'=>$country === null ? null : [
                'kind'=>'mobile_user_region', 'country_code'=>$country,
                'observed_at_utc'=>$row['observed_at'], 'evidence_key'=>$row['evidence_key'],
            ],
            'reference_status'=>match (true) {
                !$row => 'catalog_author_missing',
                strcasecmp((string)$row['username'], $username) !== 0 => 'catalog_username_mismatch',
                $owner !== null && $owner !== $uid => 'catalog_video_owner_mismatch',
                $country === null => 'historical_country_missing',
                $country !== $baseline => 'historical_country_mismatch',
                default => 'historical_country_matches',
            },
        ];
    }
    return $controls;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) { return; }
if (PHP_SAPI !== 'cli' || $argc !== 1) {
    fwrite(STDERR, "Usage: php geo-pilot.php\n");
    exit(2);
}
try {
    $db = database();
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    $db->beginTransaction();
    $candidate = geoPilotTarget($db);
    $controls = geoPilotControls($db);
    $db->commit();
    echo json_encode(['scope'=>'completed_apify_za_feed_with_saved_projection', 'candidate'=>$candidate, 'historical_controls'=>$controls],
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
    $code = preg_match('/\A[a-z0-9_]{1,80}\z/D', $error->getMessage()) ? $error->getMessage() : 'preview_unavailable';
    fwrite(STDERR, "GEO preview failed ($code)\n");
    exit(1);
}
