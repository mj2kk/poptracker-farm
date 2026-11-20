<?php
declare(strict_types=1);

// This adapter reads closed, independently audited provider exports from operator-owned files.
const E077_FEED_AUDIT = 'f8fa8c3315f5ab0fc233c5481a1a1565e92e9f20447bc01bf4878c692fa65b8c';
const E078_GEO_OUTCOMES = '5337de3ee6a527fe9d71fdbf2f1e79f76f088d82564edd1ceb72dad002aa7ec1';
const E078_GEO_SOURCE = '11ed746e81ffd1fdea5b3e9d2db01c43e756530b0b83e28f3f17637672715521';
const E078_ATTEMPTED = '914f859949382960fded9e1a84626a9c7bf20b5fd13127036550def0990d86ce';

function feedRequire(bool $condition, string $code): void
{
    if (!$condition) { throw new RuntimeException($code); }
}

function feedRoot(): string
{
    return rtrim(getenv('FARM_FEED_ARCHIVE') ?: '/var/lib/poptracker-farm/feed', '/');
}

function feedRead(string $path, ?string $digest = null): array
{
    $size = is_file($path) ? filesize($path) : false;
    feedRequire($size !== false && $size > 0 && $size <= 32 * 1024 * 1024, 'feed_file_missing_or_oversized');
    $bytes = file_get_contents($path);
    feedRequire(is_string($bytes) && ($digest === null || hash_equals($digest, hash('sha256', $bytes))), 'feed_archive_hash_mismatch');
    $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    feedRequire(is_array($data), 'feed_invalid_json');
    return $data;
}

function feedIndex(): array
{
    $trusted = [E077_FEED_AUDIT];
    // Future archives require an independent audit and an explicit operator-approved digest.
    $trustFile = getenv('FARM_FEED_TRUST_FILE') ?: '/etc/poptracker-farm/feed-audits.sha256';
    if (is_readable($trustFile)) {
        foreach (file($trustFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            feedRequire((bool) preg_match('/\A[0-9a-f]{64}\z/D', $line), 'invalid_approved_feed_digest');
            $trusted[] = $line;
        }
    }
    $out = [];
    foreach (glob(feedRoot().'/*/independent-final-source-candidate-audit.json') ?: [] as $path) {
        $digest = hash_file('sha256', $path);
        feedRequire(is_string($digest) && in_array($digest, $trusted, true), 'feed_audit_not_approved');
        $audit = feedRead($path, $digest);
        $experiment = $audit['experiment'] ?? '';
        feedRequire(is_string($experiment) && preg_match('/\AE[0-9]{3,6}\z/D', $experiment) === 1
            && ($audit['status'] ?? '') === 'PASS_INDEPENDENT_'.$experiment.'_FULL_CLOSED_SOURCE_CANDIDATE_AUDIT'
            && ($audit['source_files_modified'] ?? true) === false, 'feed_audit_not_closed');
        $handoffProofs = array_filter($audit['checked_file_sha256'] ?? [], static fn($p) => str_ends_with((string)$p, '/offline-final-handoff/result.json'), ARRAY_FILTER_USE_KEY);
        feedRequire(count($handoffProofs) === 1, 'feed_missing_handoff_evidence');
        $directory = dirname($path);
        $handoff = feedRead($directory.'/offline-final-handoff/result.json', array_values($handoffProofs)[0]);
        feedRequire(($handoff['status'] ?? '') === 'PASS_PRIVATE_PENDING_CANDIDATE_HANDOFF', 'feed_handoff_incomplete');
        $batches = array_column($handoff['exports'] ?? [], null, 'batch_id');
        $sources = $audit['source_exports'] ?? [];
        feedRequire(is_array($sources) && count($sources) === count($batches) && count($sources) > 0, 'feed_audit_batch_mismatch');
        foreach ($sources as $source) {
            $batch = $source['batch_id'] ?? '';
            $dataset = $source['dataset_id'] ?? '';
            $run = $source['run_id'] ?? '';
            $rows = $source['rows'] ?? null;
            $bound = $batches[$batch] ?? [];
            feedRequire(is_string($batch) && preg_match('/\Ab[0-9]{2,6}\z/D', $batch) === 1
                && is_string($dataset) && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $dataset) === 1
                && is_string($run) && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $run) === 1
                && is_int($rows) && $rows >= 0 && $rows <= 20000 && !isset($out[$dataset])
                && ($bound['dataset_id'] ?? '') === $dataset && ($bound['run_id'] ?? '') === $run
                && ($bound['rows'] ?? null) === $rows && ($bound['export']['sha256'] ?? '') === $source['export_sha256']
                && ($bound['provenance']['sha256'] ?? '') === $source['provenance_sha256'], 'feed_manifest_conflict');
            $out[$dataset] = ['path' => $directory.'/batches/'.$batch, 'run' => $run, 'rows' => $rows,
                'export' => $source['export_sha256'], 'provenance' => $source['provenance_sha256'], 'closed' => $bound['closed']['sha256']];
        }
    }
    return $out;
}

function productionFeedTargets(): array
{
    $index = feedIndex();
    if ($index !== []) {
        feedGeoRegistry();
        foreach ($index as $dataset => $entry) {
            feedDataset((string)$dataset, $entry);
        }
    }
    return array_map(static fn($entry) => $entry['rows'], $index);
}

function feedDate(mixed $value): ?string
{
    if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D', $value)) { return null; }
    try { $date = new DateTimeImmutable($value); } catch (Exception) { return null; }
    if (DateTimeImmutable::getLastErrors() !== false) { return null; }
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
}

function feedId(mixed $value): ?string
{
    if (!is_int($value) && !is_string($value)) { return null; }
    $id = (string)$value;
    return preg_match('/\A[1-9][0-9]{0,31}\z/D', $id) ? $id : null;
}

function feedDataset(string $dataset, ?array $entry = null): array
{
    $entry ??= feedIndex()[$dataset] ?? null;
    feedRequire($entry !== null, 'feed_dataset_unknown');
    $dir = $entry['path'];
    $closed = feedRead($dir.'/closed.json', $entry['closed']);
    $proof = feedRead($dir.'/export-provenance.json', $entry['provenance']);
    if (is_file($dir.'/verified-rows.json')) {
        $trust = feedRead(getenv('FARM_FEED_PROJECTIONS') ?: '/etc/poptracker-farm/feed-projections.json');
        $projection = $trust['datasets'][$dataset] ?? [];
        feedRequire(($trust['protocol'] ?? '') === 'closed-feed-projection-v1'
            && ($projection['run_id'] ?? '') === $entry['run']
            && ($projection['source_sha256'] ?? '') === $entry['export']
            && is_string($projection['projection_sha256'] ?? null), 'feed_projection_not_approved');
        $rows = feedRead($dir.'/verified-rows.json', $projection['projection_sha256']);
    } else {
        $rows = feedRead($dir.'/run.json', $entry['export']);
    }
    $received = feedDate($proof['observed_at_utc'] ?? null);
    feedRequire(($closed['status'] ?? '') === 'CLOSED_STABLE_PROVIDER_EXPORT' && ($closed['provider_status'] ?? '') === 'SUCCEEDED'
        && ($closed['run_id'] ?? '') === $entry['run'] && ($closed['dataset_id'] ?? '') === $dataset
        && ($closed['export_sha256'] ?? '') === $entry['export'] && ($closed['rows'] ?? null) === $entry['rows']
        && ($proof['coherent'] ?? null) === true && ($proof['two_stable_terminal_reads'] ?? null) === true
        && ($proof['run']['id'] ?? '') === $entry['run'] && ($proof['run']['status'] ?? '') === 'SUCCEEDED'
        && ($proof['dataset_id'] ?? '') === $dataset && ($proof['actual_rows'] ?? null) === count($rows)
        && ($proof['dataset_count'] ?? null) === count($rows) && $entry['rows'] === count($rows)
        && array_is_list($rows) && $received !== null, 'feed_dataset_evidence_conflict');
    return [$entry, $received, $rows];
}

function feedGeoRegistry(): array
{
    $root = feedRoot().'/geo-history';
    $facts = feedRead($root.'/all-qualified-outcomes.json', E078_GEO_OUTCOMES);
    $audit = feedRead($root.'/source-audit.json', E078_GEO_SOURCE);
    $history = feedRead($root.'/attempted-UID-union-after-E078.json', E078_ATTEMPTED);
    feedRequire(($audit['status'] ?? '') === 'PASS_INDEPENDENT_CLOSED_SOURCE_AUDIT_COMPLETE'
        && ($audit['external_experiment'] ?? '') === 'E078' && $audit['collection_completed_all'] === true
        && $history['closed_E078_source']['audit']['sha256'] === E078_GEO_SOURCE, 'geo_history_source_mismatch');
    $previous = array_fill_keys($history['all_previously_attempted_UIDs'], true);
    $unknowns = array_column($audit['normal_unknown_targets'], 'video_id', 'uid');
    $out = $countries = [];
    foreach ($facts as $row) {
        $uid = feedId($row['uid']);
        $vid = feedId($row['video_id']);
        feedRequire($uid !== null && $vid !== null && isset($previous[$uid]) && !isset($out[$uid]), 'geo_history_identity_mismatch');
        $region = $row['country_code'] ?? null;
        if ($row['status'] === 'unknown') {
            feedRequire(($unknowns[$uid] ?? '') === $vid && $region === null, 'geo_unknown_identity_mismatch');
            $status = 'unknown';
        } else {
            feedRequire($row['status'] === 'country_code_observed' && is_string($region) && preg_match('/\A[A-Z]{2}\z/D', $region) === 1, 'geo_history_outcome_invalid');
            $countries[$region] = ($countries[$region] ?? 0) + 1;
            $status = $region === 'ZA' ? 'ZA' : 'non-ZA';
        }
        $out[$uid] = ['video_id' => $vid, 'status' => $status, 'region' => $region];
    }
    feedRequire(count($out) === $audit['counts']['actually_attempted_unique_target_UIDs']
        && $countries == $audit['target_country_counts'] && count($previous) === $history['count'], 'geo_history_incomplete');
    return [$out, $previous];
}

function feedMetadata(array $item): array
{
    $keys = ['videoUrl'=>'url','videoDescription'=>'caption','hashtags'=>'hashtags','musicId'=>'music_id',
        'musicTitle'=>'music_title','musicAuthor'=>'music_author','isPhotoPost'=>'is_photo','videoPlayCount'=>'view_count',
        'videoLikeCount'=>'like_count','videoCommentCount'=>'comment_count','videoShareCount'=>'share_count',
        'videoSaveCount'=>'save_count','category'=>'category','scrapedAt'=>'provider_scraped_at',
        'language'=>'language','durationSeconds'=>'duration_seconds','country'=>'feed_market'];
    $result = [];
    foreach ($keys as $source => $dest) { $result[$dest] = $item[$source] ?? null; }
    return $result;
}

function feedCatalogName(): string
{
    $name = getenv('FARM_CATALOG_DB') ?: 'dataroom_development';
    feedRequire(preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]{0,63}\z/D', $name) === 1, 'invalid_catalog_schema');
    return $name;
}

function feedGeoStatus(string $uid, array $facts, array $previous, string $unseen = 'pending'): string
{
    return $facts[$uid]['status'] ?? (isset($previous[$uid]) ? 'previous_attempt' : $unseen);
}

function feedStoreGeoFact(PDO $db, string $uid, ?array $fact): void
{
    if ($fact === null) { return; }
    query($db, 'INSERT INTO production_geo_history(author_uid,status,region,proof_sha,video_id) VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE author_uid=production_geo_history.author_uid',
        [$uid,$fact['status'],$fact['region'],E078_GEO_SOURCE,$fact['video_id']]);
    $old = query($db, 'SELECT status,region,proof_sha,video_id FROM production_geo_history WHERE author_uid=?', [$uid])->fetch();
    feedRequire($old['status'] === $fact['status'] && $old['region'] === $fact['region'] && $old['proof_sha'] === E078_GEO_SOURCE
        && $old['video_id'] === $fact['video_id'], 'geo_history_conflict');
}

/** Resolve the backlog created when an import had no verified GEO archive on disk. */
function prodPrepareGeoCandidates(PDO $db, ?callable $read = null): int
{
    if (!query($db, "SELECT 1 FROM production_feed_candidates c JOIN production_state s ON s.id=1
        WHERE s.paused=0 AND c.geo_status='check_history' LIMIT 1")->fetchColumn()) {
        return 0;
    }
    // Verify the complete registry before taking locks. A missing or corrupt registry
    // must never turn a previously attempted author into a fresh target.
    [$facts,$previous] = ($read ?? 'feedGeoRegistry')();
    return prodTxn($db, function () use ($db,$facts,$previous) {
        if (query($db, 'SELECT paused FROM production_state WHERE id=1 FOR SHARE')->fetchColumn()) {
            return 0;
        }
        $uids = query($db, "SELECT author_uid FROM production_feed_candidates WHERE geo_status='check_history'
            ORDER BY discovered_at,author_uid LIMIT 100 FOR UPDATE SKIP LOCKED")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($uids as $uid) {
            feedStoreGeoFact($db, $uid, $facts[$uid] ?? null);
            query($db, "UPDATE production_feed_candidates SET geo_status=? WHERE author_uid=? AND geo_status='check_history'",
                [feedGeoStatus($uid,$facts,$previous), $uid]);
        }
        return count($uids);
    });
}

function feedApplyItem(PDO $db, array $item, array $source, string $catalog, array $facts, array $previous, string $unseenGeoStatus = 'pending'): array
{
    $vid = feedId($item['videoId'] ?? null);
    $uid = feedId($item['videoAuthorId'] ?? null);
    $name = $item['videoAuthorUsername'] ?? null;
    $published = feedDate($item['videoCreatedAt'] ?? null);
    $error = match (true) {
        $vid === null => 'missing_or_invalid_vid', $uid === null => 'missing_or_invalid_uid',
        ($item['country'] ?? '') !== 'ZA' => 'invalid_feed_market',
        $name !== null && (!is_string($name) || !preg_match('/\A[A-Za-z0-9._]{1,80}\z/D', $name)) => 'invalid_author_handle',
        isset($item['videoCreatedAt']) && $published === null => 'invalid_publication_time', default => null,
    };
    $record = query($db, 'SELECT run_id,source_sha,video_id,error_code FROM production_feed_items WHERE dataset_id=? AND `row_number`=?',
        [$source['dataset'],$source['row']])->fetch(PDO::FETCH_ASSOC);
    if ($record) {
        feedRequire($record['run_id'] === $source['run'] && $record['source_sha'] === $source['sha']
            && $record['video_id'] === $vid && $record['error_code'] === $error, 'feed_source_item_changed');
        if ($error === null) {
            // A separately validated author can reach the catalog after the first import.
            query($db, "UPDATE production_feed_videos v JOIN `$catalog`.poptracker_authors a ON a.id=?
                SET v.catalog_author_id=a.id WHERE v.video_id=? AND v.author_uid=? AND v.catalog_author_id IS NULL", [$uid,$vid,$uid]);
        }
        return [0, $error !== null ? 1 : 0, 0];
    }
    $inserted = 0;
    $newCandidate = 0;
    if ($error === null) {
        $known = query($db, "SELECT id FROM `$catalog`.poptracker_authors WHERE id=?", [$uid])->fetchColumn();
        $knownVid = query($db, "SELECT author_id FROM `$catalog`.poptracker_videos WHERE id=?", [$vid])->fetchColumn();
        feedRequire($knownVid === false || (string)$knownVid === $uid, 'feed_catalog_video_owner_conflict');
        if ($known === false) {
            $status = feedGeoStatus($uid,$facts,$previous,$unseenGeoStatus);
            $candidate = query($db, 'INSERT INTO production_feed_candidates(author_uid,username,sample_video_id,geo_status,discovered_at)
                VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE geo_status=IF(production_feed_candidates.geo_status=\'check_history\', ?, production_feed_candidates.geo_status)',
                [$uid,$name,$vid,$status,$source['received'],$status]);
            $newCandidate = $candidate->rowCount() === 1 ? 1 : 0;
        }
        feedStoreGeoFact($db, $uid, $facts[$uid] ?? null);
        $write = query($db, 'INSERT INTO production_feed_videos(video_id,author_uid,catalog_author_id,published_at,metadata,discovered_at)
            VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE video_id=production_feed_videos.video_id',
            [$vid,$uid, $known === false ? null : $uid, $published, json_encode(feedMetadata($item),JSON_THROW_ON_ERROR),$source['received']]);
        $inserted = $knownVid === false && $write->rowCount() === 1 ? 1 : 0;
        $old = query($db, 'SELECT author_uid,published_at,catalog_author_id FROM production_feed_videos WHERE video_id=?', [$vid])->fetch(PDO::FETCH_ASSOC);
        feedRequire($old['author_uid'] === $uid && ($published === null || $old['published_at'] === null || $old['published_at'] === $published), 'feed_video_identity_conflict');
        if ($known !== false && $old['catalog_author_id'] === null) {
            query($db, 'UPDATE production_feed_videos SET catalog_author_id=? WHERE video_id=? AND catalog_author_id IS NULL', [$uid,$vid]);
        }
    }
    query($db, 'INSERT INTO production_feed_items(dataset_id,`row_number`,run_id,source_sha,video_id,received_at,error_code)
        VALUES (?,?,?,?,?,?,?)', [$source['dataset'],$source['row'],$source['run'],$source['sha'],$vid,$source['received'],$error]);
    return [$inserted, $error === null ? 0 : 1, $newCandidate];
}

/** The scheduler commits these rows and its next cursor in one transaction. */
function productionFeedImport(PDO $db, string $datasetId, int $cursor, int $limit = 100): array
{
    feedRequire($db->inTransaction() && $limit >= 1 && $limit <= 1000, 'feed_requires_bounded_transaction');
    [$entry,$received,$rows] = feedDataset($datasetId);
    [$facts,$previous] = feedGeoRegistry();
    $catalog = feedCatalogName();
    $total = count($rows);
    feedRequire($cursor >= 0 && $cursor <= $total, 'feed_cursor_invalid');
    $end = min($total, $cursor + $limit);
    $inserted = $rejected = 0;
    for ($i = $cursor; $i < $end; $i++) {
        feedRequire(is_array($rows[$i]), 'feed_item_not_object');
        [$n,$bad] = feedApplyItem($db, $rows[$i], ['dataset'=>$datasetId,'row'=>$i,'sha'=>$entry['export'],
            'run'=>$entry['run'],'received'=>$received], $catalog, $facts, $previous);
        $inserted += $n; $rejected += $bad;
    }
    return ['next_cursor'=>$end, 'total'=>$total,'done'=>$end === $total,'inserted'=>$inserted,'rejected'=>$rejected];
}
