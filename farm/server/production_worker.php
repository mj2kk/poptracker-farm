<?php

declare(strict_types=1);
require_once __DIR__.'/production.php';

function prodScoreFeed(PDO $db, string $dataset): void
{
    $model = prodModel();
    if (! $model) {
        return;
    }
    $catalog = prodCatalogName();
    $rows = query($db, "SELECT i.video_id, MAX(o.id) AS ver FROM production_feed_items i JOIN $catalog.poptracker_observations o ON o.video_id=CAST(i.video_id AS BINARY)
        WHERE i.dataset_id=? AND i.error_code IS NULL AND o.view_count IS NOT NULL GROUP BY i.video_id
        ORDER BY (COUNT(DISTINCT o.observed_at)>1) DESC,ver DESC LIMIT 5", [$dataset])->fetchAll();
    $suffix = substr(hash('sha256', PRODUCTION_TREND_RULES.'|'.$model), 0, 32);
    foreach ($rows as $row) {
        prodEnqueue($db, 'EVALUATE_VIDEO', $row['video_id'], $row['ver'].':'.$suffix, 40, 1);
    }
}

function prodFeedStep(PDO $db, array $job): void
{
    $p = productionFeedImport($db, $job['target'], (int) $job['cursor_position']);
    $written = (int) $job['written'] + $p['inserted'];
    $rejected = (int) $job['rejected'] + $p['rejected'];
    $summary = "Строк: {$p['next_cursor']} из {$p['total']}. Добавлено видео: $written. Отклонено строк: $rejected.";
    query($db, "UPDATE production_jobs SET cursor_position=?,total=?,written=?,rejected=?,result=?,status='pending',next_run_at=UTC_TIMESTAMP(6) WHERE id=?",
        [$p['next_cursor'], $p['total'], $written, $rejected, $summary, $job['id']]);
    if ($p['done']) {
        prodFinish($db, $job['id'], 'completed', $summary);
        prodScoreFeed($db, $job['target']);
        query($db, 'UPDATE production_state SET summary_at=NULL WHERE id=1');
    }
}

function prodSaveEvaluation(PDO $db, array $job, array $evaluation): void
{
    $revision = explode(':', $job['revision'])[0];
    $cat = prodCatalogName();
    $ver = query($db, "SELECT MAX(id) FROM $cat.poptracker_observations WHERE video_id=? AND view_count IS NOT NULL", [$job['target']])->fetchColumn();
    if ((string) $ver !== $revision) {
        prodFinish($db, $job['id'], 'blocked', 'История изменилась во время оценки. Запросите новую оценку.');

        return;
    }
    if ($evaluation['status'] === 'blocked') {
        prodFinish($db, $job['id'], 'blocked', $evaluation['reason']);

        return;
    }
    query($db, 'INSERT INTO production_evaluations(job_id,video_id,observation_version,status,attention_status,collect_comments,input_sha256,rules_version,model_version,evaluated_at,decision)
        VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6),?)', [$job['id'], $job['target'], $revision, $evaluation['status'], $evaluation['attention_status'],
        (int) $evaluation['collect_comments'], $evaluation['input_sha256'], $evaluation['rules_version'], $evaluation['model_version'],
        json_encode($evaluation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)]);
    $approval = $db->lastInsertId();
    $cat = prodCatalogName();
    if ($evaluation['status'] === 'evaluated' && $evaluation['collect_comments']) {
        $count = (int) query($db, "SELECT COUNT(*) FROM $cat.poptracker_comments WHERE video_id=? AND body IS NOT NULL AND body<>''", [$job['target']])->fetchColumn();
        if ($count) {
            prodEnqueue($db, 'COLLECT_COMMENTS', $job['target'], $approval.':'.$count, $evaluation['attention_status'] === 'high' ? 70 : 60, null);
        }
    }
    query($db, 'UPDATE production_jobs SET cursor_position=1,total=1 WHERE id=?', [$job['id']]);
    prodFinish($db, $job['id'], 'completed', mb_substr($evaluation['status'] === 'insufficient_data' ? 'Недостаточно данных. '.$evaluation['reason'] :
        $evaluation['attention_status'].' · '.$evaluation['reason'], 0, 510));
    query($db, 'UPDATE production_state SET summary_at=NULL WHERE id=1');
}

function prodCommentsStep(PDO $db, array $job): void
{
    $approval = prodCommentApproval($db, $job);
    if ($approval === null) {
        return;
    }
    $cat = prodCatalogName();
    $written = query($db, "INSERT INTO production_comment_context(video_id,comment_id,evaluation_id,source,source_run_id,commenter_id,body,published_at,like_count,observed_at)
        SELECT c.video_id,c.id,?,c.source,c.source_run_id,c.commenter_id,c.body,c.published_at,c.like_count,c.observed_at FROM $cat.poptracker_comments c
        WHERE c.video_id=? AND c.body IS NOT NULL AND c.body<>'' AND NOT EXISTS
        (SELECT 1 FROM production_comment_context p WHERE p.video_id=c.video_id AND p.comment_id=c.id)
        ORDER BY c.id LIMIT 100", [$approval, $job['target']])->rowCount();
    prodCommentProgress($db, $job, $written);
}

function prodWorkerTick(PDO $db): bool
{
    $lock = 'production-'.substr(hash('sha256', (string) $db->query('SELECT DATABASE()')->fetchColumn()), 0, 32);
    if ((int) query($db, 'SELECT GET_LOCK(?,0)', [$lock])->fetchColumn() !== 1) {
        return false;
    }
    try {
        prodRefreshSummary($db);
        prodExpirePhoneComments($db);
        $job = prodTxn($db, function () use ($db) {
            if (query($db, 'SELECT paused FROM production_state WHERE id=1 FOR UPDATE')->fetchColumn()) {
                return null;
            }
            // Owning the connection-scoped lock proves the previous executor exited.
            $old = query($db, "SELECT id,attempts FROM production_jobs WHERE status='running' AND device_task_id IS NULL FOR UPDATE")->fetchAll();
            foreach ($old as $r) {
                if ((int) $r['attempts'] >= 2) {
                    prodFinish($db, $r['id'], 'blocked', 'Исполнитель дважды прерван. Нужна проверка.');
                } else {
                    query($db, "UPDATE production_jobs SET status='pending',attempts=attempts+1,next_run_at=UTC_TIMESTAMP(6) WHERE id=?", [$r['id']]);
                }
            }
            $hasPhone = query($db, "SELECT 1 FROM device_agents WHERE archive_comments_seen_at>=UTC_TIMESTAMP()-INTERVAL 150 SECOND
                AND last_seen_at>=UTC_TIMESTAMP()-INTERVAL 150 SECOND AND revoked_at IS NULL LIMIT 1")->fetchColumn();
            $row = query($db, "SELECT * FROM production_jobs WHERE status='pending' AND next_run_at<=UTC_TIMESTAMP(6)
                AND (type<>'COLLECT_COMMENTS' OR ?=0 OR next_run_at<=UTC_TIMESTAMP()-INTERVAL 35 SECOND)
                ORDER BY priority DESC,next_run_at,created_at,id LIMIT 1 FOR UPDATE", [(int)(bool)$hasPhone])->fetch();
            if (! $row) {
                return null;
            }
            query($db, "UPDATE production_jobs SET status='running',attempts=GREATEST(1,attempts),started_at=COALESCE(started_at,UTC_TIMESTAMP(6)) WHERE id=?", [$row['id']]);

            return $row;
        });
        if (! $job) {
            return false;
        }
        try {
            if ($job['type'] === 'EVALUATE_VIDEO') {
                $model = prodModel();
                if (! $model) {
                    prodFinish($db, $job['id'], 'blocked', 'Локальная модель недоступна.');

                    return true;
                }
                $stamp = substr(hash('sha256', PRODUCTION_TREND_RULES.'|'.$model), 0, 32);
                if (explode(':', $job['revision'])[1] !== $stamp) {
                    prodFinish($db, $job['id'], 'blocked', 'Изменилась версия модели или правил. Запросите новую оценку.');

                    return true;
                }
                $catalog = prodCatalogConnection();
                $catalog->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $evaluation = prodTxn($catalog, function () use ($catalog, $job, $model) {
                    $current = query($catalog, 'SELECT MAX(id) FROM poptracker_observations WHERE video_id=? AND view_count IS NOT NULL', [$job['target']])->fetchColumn();
                    if ((string) $current !== explode(':', $job['revision'])[0]) {
                        return null;
                    }

                    return productionEvaluateVideo($catalog, $job['target'], 'productionLocalModelDecision', $model);
                });
                if (! $evaluation) {
                    prodFinish($db, $job['id'], 'blocked', 'Появились новые наблюдения. Нужна оценка актуальной версии.');

                    return true;
                }
                prodTxn($db, fn () => prodSaveEvaluation($db, $job, $evaluation));
            } elseif ($job['type'] === 'IMPORT_FEED') {
                if ($job['target'] === 'ZA' && isUuid($job['revision'])) {
                    liveFeedStep($db, $job);
                } else {
                    prodTxn($db, fn () => prodFeedStep($db, $job));
                }
            } elseif ($job['type'] === 'COLLECT_COMMENTS') {
                prodTxn($db, fn () => prodCommentsStep($db, $job));
            } else {
                prodFinish($db, $job['id'], 'blocked', PRODUCTION_BLOCKERS[$job['type']] ?? 'Исполнитель недоступен.');
            }
        } catch (Throwable $e) {
            $code = preg_match('/\A[a-z0-9_]{1,80}\z/',$e->getMessage()) ? $e->getMessage() : 'worker_error';
            error_log('Production job '.$job['id'].' '.get_class($e).' '.$code);
            prodTxn($db,function () use ($db,$job,$code) {
                prodFinish($db,$job['id'],'failed','Проверка источника или обработка не прошла ('.$code.'). Очередь приостановлена.');
                prodControl($db,true);
            });
        }

        return true;
    } finally {
        query($db,'SELECT RELEASE_LOCK(?)',[$lock]);
    }
}
