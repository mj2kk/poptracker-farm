<?php

declare(strict_types=1);
require_once __DIR__.'/farm.php';
require_once __DIR__.'/production_analytics.php';
require_once __DIR__.'/production_feed.php';
require_once __DIR__.'/production_apify.php';
require_once __DIR__.'/production_live_feed.php';
require_once __DIR__.'/production_phone_comments.php';

const PRODUCTION_COMMANDS = [
    'IMPORT_FEED' => ['Import Feed', 'Импорт сохранённых строк южноафриканского Feed Apify. Новые авторы попадают в кандидаты.'],
    'VALIDATE_GEO' => ['Validate Author GEO', 'Проверка страны кандидатов и сохранение доказательства региона.'],
    'DISCOVER_VIDEOS' => ['Discover Videos', 'Поиск новых публикаций у авторов каталога.'],
    'REFRESH_METRICS' => ['Refresh Metrics', 'Новое наблюдение статистики для уже известного видео.'],
    'COLLECT_COMMENTS' => ['Collect Comments', 'Отбор сохранённых текстов Vuka для видео, одобренных последней оценкой AI.'],
];
const PRODUCTION_BLOCKERS = [
    'VALIDATE_GEO' => 'В Farm пока не подключён источник страны аккаунта TikTok.',
    'DISCOVER_VIDEOS' => 'Источник ограничил частоту запросов. Поиск новых публикаций приостановлен.',
    'REFRESH_METRICS' => 'На телефонах пока не настроено обновление просмотров и реакций.',
];
const PROD_STATES = ['pending', 'running', 'completed', 'failed', 'blocked', 'cancelled'];

function prodCatalogName(): string
{
    $name = getenv('FARM_CATALOG_DB') ?: 'dataroom_development';
    if (! preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $name)) {
        throw new RuntimeException('invalid_catalog');
    }

    return $name;
}

function prodCatalogConnection(): PDO
{
    $db = database();
    $db->exec('USE `'.prodCatalogName().'`');

    return $db;
}

function prodTxn(PDO $db, callable $action): mixed
{
    $db->beginTransaction();
    try {
        $value = $action();
        $db->commit();

        return $value;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        } throw $e;
    }
}

function prodFinish(PDO $db, string $id, string $state, string $message): void
{
    $error = $state === 'blocked' || $state === 'failed';
    query($db, 'UPDATE production_jobs SET status=?,finished_at=UTC_TIMESTAMP(6),device_task_id=NULL,result=?,error=? WHERE id=?',
        [$state, $error ? null : $message, $error ? $message : null, $id]);
}

function prodModel(): ?string
{
    return function_exists('productionLocalModelReady') && productionLocalModelReady() ? PRODUCTION_LOCAL_MODEL_VERSION : null;
}

function prodLatestApproval(): string
{
    $cat = prodCatalogName();

    return "e.observation_version=(SELECT MAX(o.id) FROM $cat.poptracker_observations o WHERE o.video_id=e.video_id AND o.view_count IS NOT NULL) AND e.status='evaluated' AND e.collect_comments=1 AND NOT EXISTS
        (SELECT 1 FROM production_evaluations n WHERE n.video_id=e.video_id AND n.id>e.id)";
}

// Counters reflect persisted source rows. Refresh in the timer so each open browser
// does not independently scan the full historical catalog every five seconds.
function prodRefreshSummary(PDO $db): void
{
    $retryAt = query($db, "SELECT COALESCE(catalog_summary->>'$.geo_registry_retry_at',0) FROM production_state
        WHERE id=1 AND (summary_at IS NULL OR summary_at<UTC_TIMESTAMP()-INTERVAL 60 SECOND)")->fetchColumn();
    if ($retryAt === false) {
        return;
    }
    if ((int)$retryAt <= time()) {
        try {
            prodPrepareGeoCandidates($db);
            $retryAt = 0;
        } catch (RuntimeException | JsonException $error) {
            $retryAt = time() + 900;
            $code = preg_match('/\A[a-z0-9_]{1,80}\z/D', $error->getMessage()) ? $error->getMessage() : get_class($error);
            error_log('Farm GEO archive preparation deferred: '.$code);
        }
    }
    $cat = prodCatalogName();
    $counts = query($db, "SELECT
        (SELECT COUNT(DISTINCT g.author_id) FROM $cat.poptracker_author_geo_evidence g
           WHERE g.kind='mobile_user_region' AND g.country_code='ZA' AND NOT EXISTS
           (SELECT 1 FROM $cat.poptracker_author_geo_evidence n WHERE n.author_id=g.author_id
             AND n.kind='mobile_user_region' AND CHAR_LENGTH(n.country_code)=2 AND ASCII(SUBSTRING(n.country_code,1,1)) BETWEEN 65 AND 90 AND ASCII(SUBSTRING(n.country_code,2,1)) BETWEEN 65 AND 90 AND n.country_code NOT IN ('ZZ','XX')
             AND (n.observed_at>g.observed_at OR (n.observed_at=g.observed_at AND n.id>g.id)))) AS za_authors,
        (SELECT COUNT(*) FROM production_feed_candidates WHERE geo_status IN ('pending','check_history')) AS geo_candidates,
        (SELECT COUNT(*) FROM $cat.poptracker_videos) + (SELECT COUNT(*) FROM production_feed_videos f
            WHERE NOT EXISTS (SELECT 1 FROM $cat.poptracker_videos v WHERE v.id=f.video_id)) AS videos,
        (SELECT COUNT(*) FROM $cat.poptracker_observations) AS observations,
        (SELECT COUNT(*) FROM production_evaluations e WHERE ".prodLatestApproval().") AS ai_selected,
        (SELECT COUNT(DISTINCT video_id) FROM $cat.poptracker_comments WHERE body IS NOT NULL AND body<>'') AS commented_videos")->fetch();
    $counts = array_map('intval', $counts);
    $archiveError = null;
    try {
        $datasets = productionFeedTargets();
    } catch (Throwable $e) {
        $datasets = [];
        $archiveError = 'Не удалось проверить манифест закрытого архива Feed.';
    }
    $summary = ['overview' => $counts, 'datasets' => $datasets, 'archive_error' => $archiveError];
    if ($retryAt) { $summary['geo_registry_retry_at'] = (int)$retryAt; }
    query($db, 'UPDATE production_state SET catalog_summary=?,summary_at=UTC_TIMESTAMP(6) WHERE id=1', [json_encode($summary, JSON_THROW_ON_ERROR)]);
}

function prodKey(string $type, string $target, string $revision): string
{
    return hash('sha256', json_encode([$type, $target, $revision], JSON_THROW_ON_ERROR));
}

function prodFeedPending(PDO $db, array $datasets): array
{
    $eligible = [];
    foreach ($datasets as $id => $count) {
        if (! query($db, 'SELECT 1 FROM production_jobs WHERE retained_key=?', [prodKey('IMPORT_FEED', (string) $id, 'archive-v1')])->fetchColumn()) {
            $eligible[$id] = $count;
        }
    }

    return $eligible;
}

function prodCommentScope(): string
{
    $cat = prodCatalogName();

    return 'FROM production_evaluations e WHERE '.prodLatestApproval()."
        AND NOT EXISTS (SELECT 1 FROM production_jobs j WHERE j.type='COLLECT_COMMENTS' AND j.target=e.video_id AND j.status IN ('pending','running'))
        AND EXISTS (SELECT 1 FROM $cat.poptracker_comments c WHERE c.video_id=e.video_id AND c.body IS NOT NULL AND c.body<>''
           AND NOT EXISTS (SELECT 1 FROM production_comment_context pc WHERE pc.video_id=c.video_id AND pc.comment_id=c.id))";
}

function prodCommentTargets(PDO $db, int $limit = 20): array
{
    return query($db, 'SELECT e.video_id,e.id AS revision,e.attention_status '.prodCommentScope()."
        ORDER BY FIELD(e.attention_status,'high','medium','low'),
            CAST(JSON_UNQUOTE(JSON_EXTRACT(e.decision,'$.features.views_per_hour')) AS DECIMAL(24,6)) DESC,e.id DESC LIMIT ".(int) $limit)->fetchAll();
}

function prodSnapshot(PDO $db, ?string $type, ?string $status): array
{
    if (($type !== null && ! isset(PRODUCTION_COMMANDS[$type]) && $type !== 'EVALUATE_VIDEO') || ($status !== null && ! in_array($status, PROD_STATES, true))) {
        reject(422, 'invalid_filter');
    }
    $state = query($db, 'SELECT * FROM production_state WHERE id=1')->fetch();
    $summary = json_decode($state['catalog_summary'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
    $totals = array_fill_keys(PROD_STATES, 0);
    foreach (query($db, 'SELECT status,COUNT(*) AS n FROM production_jobs GROUP BY status') as $row) {
        $totals[$row['status']] = (int) $row['n'];
    }
    $totals['paused'] = (bool) $state['paused'];
    $totals['last_success_at'] = query($db, "SELECT MAX(finished_at) FROM production_jobs WHERE status='completed'")->fetchColumn() ?: null;
    $commands = [];
    foreach (PRODUCTION_COMMANDS as $code => [$label,$description]) {
        $last = query($db, 'SELECT * FROM production_jobs WHERE type=? ORDER BY created_at DESC,id DESC LIMIT 1', [$code])->fetch() ?: null;
        $reason = PRODUCTION_BLOCKERS[$code] ?? null;
        $unavailableCode = null;
        $pending = null;
        $source = null;
        if ($code === 'IMPORT_FEED') {
            $pending = count(prodFeedPending($db, $summary['datasets'] ?? []));
            $configured = apifyFeedReady();
            $source = ($configured || !$pending) ? 'apify' : 'archive';
            if ($source === 'apify') {
                $description = 'Лента TikTok Explore для ЮАР через Apify. Публикации за последние 7 дней. Новые авторы попадают в кандидаты на проверку страны.';
                $pending = null;
                $reason = liveFeedBusyReason($db) ?? ($configured ? null : 'Подключите API-токен Apify к Farm на Vuka.');
            }
        } elseif ($code === 'VALIDATE_GEO') {
            $pending = $summary['overview']['geo_candidates'] ?? null;
            if (query($db,"SELECT 1 FROM device_agents WHERE revoked_at IS NULL AND fresh_geo_seen_at>=UTC_TIMESTAMP()-INTERVAL 150 SECOND LIMIT 1")->fetchColumn()) {
                $reason = null;
            } elseif ($reason !== null) {
                $unavailableCode = 'geo_source_unavailable';
            }
        } elseif ($code === 'COLLECT_COMMENTS') {
            $pending = (int) query($db, 'SELECT COUNT(*) '.prodCommentScope())->fetchColumn();
            if (! $pending) {
                $reason = 'Пока нет видео, для которых ИИ запросил комментарии и в базе есть новые тексты.';
            }
        }
        $commands[] = ['type' => $code, 'label' => $label, 'description' => $description, 'available' => $reason === null, 'reason' => $reason, 'unavailable_code' => $unavailableCode, 'pending_targets' => $pending, 'source_mode' => $source,
            'pricing' => $source === 'apify' ? apifyFeedPricing() : null,
            'last_run_at' => $last['started_at'] ?? $last['created_at'] ?? null, 'last_result' => $last['error'] ?? $last['result'] ?? null,
            'progress' => isset($last['total']) ? ['done' => (int) $last['cursor_position'], 'total' => (int) $last['total']] : null];
    }
    $jobs = prodJobHistory($db, $type, $status);
    $model = prodModel();

    return ['devices' => listDevices($db), 'sampled_at' => gmdate('Y-m-d\TH:i:s\Z'), 'overview' => $summary['overview'] ?? array_fill_keys(['za_authors', 'geo_candidates', 'videos', 'observations', 'ai_selected', 'commented_videos'], null),
        'summary_at' => $state['summary_at'], 'commands' => $commands, 'jobs' => $jobs, 'status' => $totals,
        'analytics' => ['available' => $model !== null, 'model' => $model, 'pending_targets' => null, 'reason' => $model === null ? 'Оценка видео временно недоступна.' : null]];
}

function prodJobHistory(PDO $db, ?string $type, ?string $status): array
{
    $where = [];
    $bindings = [];
    foreach (['type' => $type, 'status' => $status] as $column => $value) {
        if ($value !== null) {
            $where[] = "j.$column=?";
            $bindings[] = $value;
        }
    }
    $filter = $where ? ' WHERE '.implode(' AND ', $where) : '';
    $jobs = query($db, "SELECT j.id,j.type,j.target,j.priority,j.status,j.device_id,
        j.created_at,j.started_at,j.finished_at,j.next_run_at,j.attempts,j.result,j.error,
        j.cursor_position,j.total,j.written,j.rejected,f.phase AS feed_phase,f.batch_number,
        IF(f.job_id IS NULL,NULL,JSON_OBJECT('requested_count',f.requested_count,
            'new_candidates',f.new_candidates,'duplicate_videos',f.duplicate_videos,
            'cost_usd',CAST(f.provider_cost_usd AS CHAR))) AS feed,
        IF(e.id IS NULL,NULL,JSON_OBJECT('status',e.status,'attention_status',e.attention_status,
            'collect_comments',e.collect_comments,'reason',e.decision->>'$.reason')) AS evaluation
        FROM production_jobs j
        LEFT JOIN production_apify_feed_runs f ON f.job_id=j.id
        LEFT JOIN production_evaluations e ON e.job_id=j.id
        $filter ORDER BY j.created_at DESC,j.id DESC LIMIT 100", $bindings)->fetchAll();
    foreach ($jobs as &$job) {
        if ($job['feed'] !== null) {
            $job['feed'] = json_decode($job['feed'], true, 8, JSON_THROW_ON_ERROR);
        }
        if ($job['evaluation'] !== null) {
            $job['evaluation'] = json_decode($job['evaluation'], true, 8, JSON_THROW_ON_ERROR);
            $job['evaluation']['collect_comments'] = (bool)$job['evaluation']['collect_comments'];
        }
        $job['cancellable'] = in_array($job['status'], ['pending','blocked'], true)
            && liveFeedCanCancel($job['feed_phase'], $job['batch_number'] === null ? null : (int)$job['batch_number']);
        unset($job['feed_phase'], $job['batch_number']);
    }
    unset($job);
    return $jobs;
}

function prodEnqueue(PDO $db, string $type, string $target, string $revision, int $priority, ?int $total): bool
{
    $key = prodKey($type, $target, $revision);
    $insert = query($db, 'INSERT INTO production_jobs(id,type,target,revision,identity_key,priority,total,created_at,next_run_at)
        VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE id=production_jobs.id', [deviceId(), $type, $target, $revision, $key, $priority, $total]);

    return $insert->rowCount() === 1;
}

function prodEvaluationTargets(PDO $db, string $version): array
{
    $cat = prodCatalogName();
    // A backfill can change the revision without changing the two points used by trendObservations().
    return query($db, "WITH recent AS (
            SELECT video_id, MAX(id) AS ver FROM $cat.poptracker_observations
            WHERE view_count IS NOT NULL GROUP BY video_id
        )
        SELECT recent.video_id, recent.ver FROM recent
        JOIN $cat.poptracker_videos v ON v.id=recent.video_id
        LEFT JOIN $cat.poptracker_observations latest ON latest.id=(
            SELECT o.id FROM $cat.poptracker_observations o
            WHERE o.video_id=recent.video_id AND o.view_count IS NOT NULL AND o.observed_at<=UTC_TIMESTAMP(6)
            ORDER BY o.observed_at DESC,o.id DESC LIMIT 1)
        LEFT JOIN $cat.poptracker_observations prev ON prev.id=(
            SELECT o.id FROM $cat.poptracker_observations o
            WHERE o.video_id=recent.video_id AND o.view_count IS NOT NULL AND o.observed_at<latest.observed_at
            ORDER BY o.observed_at DESC,o.id DESC LIMIT 1)
        LEFT JOIN $cat.poptracker_scans ls ON ls.id=latest.scan_id
        LEFT JOIN $cat.poptracker_scans ps ON ps.id=prev.scan_id
        WHERE NOT EXISTS (SELECT 1 FROM production_jobs a WHERE a.type='EVALUATE_VIDEO' AND a.target=recent.video_id AND a.status IN ('pending','running'))
          AND NOT EXISTS (SELECT 1 FROM production_jobs j WHERE j.type='EVALUATE_VIDEO' AND j.target=recent.video_id
            AND j.revision=CONCAT(recent.ver,':',?) AND j.retained_key IS NOT NULL)
        ORDER BY (prev.id IS NOT NULL AND CAST(ls.source AS BINARY)=CAST(ps.source AS BINARY)
            AND latest.view_count>=prev.view_count AND latest.observed_at>=v.published_at) DESC,
            (prev.id IS NOT NULL) DESC, recent.ver DESC LIMIT 5", [$version])->fetchAll();
}

function prodSubmit(PDO $db, string $type, ?string $source = null, ?string $requestId = null,
    ?int $requestedCount = null, ?int $maxChargeCents = null): array
{
    if (($source !== null || $requestId !== null) && ($type !== 'IMPORT_FEED' || $source !== 'apify' || $requestId === null || !isUuid($requestId))) {
        reject(422, 'invalid_apify_request');
    }
    if (($requestedCount === null) !== ($maxChargeCents === null)
        || ($requestedCount !== null && $source !== 'apify')) {
        reject(422, 'invalid_apify_quote');
    }
    if ($source === 'apify') {
        // An older client displayed a fixed offer of 100 posts for at most 10 cents.
        $requestedCount ??= 100;
        $maxChargeCents ??= 10;
        $pricing = apifyFeedPricing();
        if ($requestedCount < $pricing['min_quantity'] || $requestedCount > $pricing['max_quantity']) {
            reject(422, 'invalid_apify_quantity');
        }
        if ($maxChargeCents !== apifyFeedQuote($requestedCount)['max_charge_cents']) {
            reject(409, 'Стоимость сбора изменилась. Обновите страницу и проверьте сумму перед запуском.');
        }
    }
    if (! isset(PRODUCTION_COMMANDS[$type]) && $type !== 'EVALUATE_VIDEO') {
        reject(422, 'invalid_command');
    }
    if (isset(PRODUCTION_BLOCKERS[$type])) {
        reject(409, PRODUCTION_BLOCKERS[$type]);
    }

    return prodTxn($db, function () use ($db, $type, $source, $requestId, $requestedCount) {
        $state = query($db, 'SELECT catalog_summary FROM production_state WHERE id=1 FOR UPDATE')->fetch();
        $n = 0;
        if ($source === 'apify') {
            if (!apifyFeedReady()) {
                reject(409, 'Подключите API-токен Apify к Farm на Vuka.');
            }
            return ['queued' => liveFeedSubmit($db, $requestId, $requestedCount)];
        } elseif ($type === 'IMPORT_FEED') {
            $datasets = productionFeedTargets();
            foreach (prodFeedPending($db, $datasets) as $id => $count) {
                $n += (int) prodEnqueue($db, $type, (string) $id, 'archive-v1', 50, (int) $count);
            }
        } elseif ($type === 'COLLECT_COMMENTS') {
            $cat = prodCatalogName();
            foreach (prodCommentTargets($db) as $row) {
                $rev = (string) $row['revision'];
                $version = query($db, "SELECT COUNT(*) FROM $cat.poptracker_comments WHERE video_id=? AND body IS NOT NULL AND body<>''", [$row['video_id']])->fetchColumn();
                $n += (int) prodEnqueue($db, $type, $row['video_id'], $rev.':'.$version, $row['attention_status'] === 'high' ? 70 : 60, null);
            }
        } else {
            $model = prodModel();
            if (! $model) {
                reject(409, 'Локальная модель недоступна.');
            }
            $version = substr(hash('sha256', PRODUCTION_TREND_RULES.'|'.$model), 0, 32);
            foreach (prodEvaluationTargets($db, $version) as $row) {
                $n += (int) prodEnqueue($db, $type, $row['video_id'], $row['ver'].':'.$version, 40, 1);
            }
        }
        if (! $n) {
            reject(409, 'Новых целей для этой команды нет.');
        }

        return ['queued' => $n];
    });
}

function prodControl(PDO $db, bool $paused): array
{
    query($db, 'UPDATE production_state SET paused=? WHERE id=1', [(int) $paused]);

    return ['paused' => $paused];
}

function prodCancel(PDO $db, string $id): array
{
    if (! isUuid($id)) {
        reject(422,'invalid_id');
    }
    return prodTxn($db, function () use ($db, $id) {
        $job = query($db, 'SELECT type,target,status FROM production_jobs WHERE id=? FOR UPDATE', [$id])->fetch();
        if (!$job || !in_array($job['status'], ['pending','blocked'], true)) {
            reject(409, 'Отменить можно только ожидающее или заблокированное задание.');
        }
        if ($job['type'] === 'IMPORT_FEED' && $job['target'] === 'ZA' && !liveFeedCancelAllowed($db, $id)) {
            reject(409, 'Запуск уже передан Apify. Farm ожидает подтверждения результата.');
        }
        query($db, "UPDATE production_jobs SET status='cancelled',finished_at=UTC_TIMESTAMP(6) WHERE id=?", [$id]);
        return ['id' => $id, 'status' => 'cancelled'];
    });
}
