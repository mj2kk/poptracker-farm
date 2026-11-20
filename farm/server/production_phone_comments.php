<?php

declare(strict_types=1);

const PHONE_COMMENTS_COUNT = 20;
const PHONE_COMMENTS_BYTES = 32768;
const PHONE_COMMENTS_JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

/** Recheck the decision and observation revision for every batch, including on return from Android. */
function prodCommentApproval(PDO $db, array $job): ?string
{
    $approval = query($db, 'SELECT id FROM production_evaluations e WHERE video_id=? AND '.prodLatestApproval().' FOR UPDATE', [$job['target']])->fetchColumn();
    if (!$approval || (string)$approval !== explode(':', $job['revision'])[0]) {
        prodFinish($db, $job['id'], 'blocked', 'Актуальная оценка ИИ не разрешает обработку комментариев.');
        return null;
    }
    return (string)$approval;
}

function prodCommentProgress(PDO $db, array $job, int $written): void
{
    $cat = prodCatalogName();
    $counts = query($db, "SELECT COUNT(*) AS total,COUNT(p.comment_id) AS done FROM $cat.poptracker_comments c
        LEFT JOIN production_comment_context p ON p.video_id=c.video_id AND p.comment_id=c.id
        WHERE c.video_id=? AND c.body IS NOT NULL AND c.body<>''", [$job['target']])->fetch();
    $total = (int)$counts['total'];
    $done = (int)$counts['done'];
    query($db, "UPDATE production_jobs SET written=written+?,cursor_position=?,total=?,device_task_id=NULL,
        status='pending',next_run_at=UTC_TIMESTAMP(6) WHERE id=?", [$written, $done, $total, $job['id']]);
    if (!$total) {
        prodFinish($db, $job['id'], 'blocked', 'В архиве нет текста комментариев для этого видео.');
    } elseif ($done === $total) {
        prodFinish($db, $job['id'], 'completed', "Обработано комментариев из архива Vuka: $done.");
    }
}

/** Called inside the same transaction that authenticates the device and creates the lease. */
function prodClaimPhoneComments(PDO $db, string $device): ?array
{
    if (query($db, 'SELECT paused FROM production_state WHERE id=1 FOR SHARE')->fetchColumn()) {
        return null;
    }
    $job = query($db, "SELECT id,target,revision FROM production_jobs WHERE type='COLLECT_COMMENTS'
        AND status='pending' AND device_task_id IS NULL AND next_run_at<=UTC_TIMESTAMP(6)
        ORDER BY priority DESC,next_run_at,created_at,id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch();
    if (!$job || prodCommentApproval($db, $job) === null) {
        return null;
    }
    $cat = prodCatalogName();
    $rows = query($db, "SELECT c.id,c.body FROM $cat.poptracker_comments c
        WHERE c.video_id=? AND c.body IS NOT NULL AND c.body<>'' AND NOT EXISTS
        (SELECT 1 FROM production_comment_context p WHERE p.video_id=c.video_id AND p.comment_id=c.id)
        ORDER BY c.id LIMIT ".PHONE_COMMENTS_COUNT, [$job['target']])->fetchAll();
    $payload = ['video_id' => $job['target'], 'comments' => []];
    $proof = $payload;
    if (!preg_match('/\A[0-9]{1,32}\z/D', $job['target'])) {
        return null;
    }
    foreach ($rows as $row) {
        $id = (string)$row['id'];
        // A row that cannot fit the device contract remains eligible for the normal server executor.
        if (!preg_match('/\A[0-9]{1,32}\z/D', $id)) { break; }
        $payload['comments'][] = ['id' => $id, 'text' => $row['body']];
        if (strlen(json_encode($payload, PHONE_COMMENTS_JSON)) > PHONE_COMMENTS_BYTES) {
            array_pop($payload['comments']);
            break;
        }
        $proof['comments'][] = ['id' => $id, 'sha256' => hash('sha256', $row['body'])];
    }
    if (!$proof['comments']) {
        return null;
    }

    $task = deviceId();
    // Only the authenticated claim receives texts. The technical queue and its history retain hashes.
    query($db, "INSERT INTO device_tasks(task_id,device_id,type,production_job_id,payload_json,created_at,updated_at)
        VALUES(?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",
        [$task,$device,ARCHIVE_COMMENTS_TASK,$job['id'],json_encode($proof, JSON_THROW_ON_ERROR)]);
    query($db, "UPDATE production_jobs SET device_task_id=?,device_id=?,status='running',
        started_at=COALESCE(started_at,UTC_TIMESTAMP(6)),attempts=GREATEST(1,attempts) WHERE id=?", [$task,$device,$job['id']]);
    return ['task_id'=>$task, 'type'=>ARCHIVE_COMMENTS_TASK, 'schema_version'=>1, 'payload_json'=>json_encode($payload, PHONE_COMMENTS_JSON)];
}

/** The attempt, exact ordered digest report and lease have been validated in the enclosing transaction. */
function prodAcceptPhoneComments(PDO $db, string $device, string $task, object $proof, bool $succeeded): void
{
    $job = query($db, "SELECT id,target,revision FROM production_jobs
        WHERE device_task_id=? AND device_id=? AND status='running' FOR UPDATE", [$task,$device])->fetch();
    if (!$job) { reject(409, 'attempt_closed'); }
    if (!$succeeded) {
        prodFinish($db, $job['id'], 'blocked', 'Телефон не смог проверить партию комментариев.');
        return;
    }
    $approval = prodCommentApproval($db, $job);
    if ($approval === null) { return; }

    $cat = prodCatalogName();
    $ids = array_column($proof->comments, 'id');
    $marks = implode(',', array_fill(0, count($ids), '?'));
    // Lock these source rows so the bytes, identity and credited provenance cannot change
    // between independent comparison of the phone report and insertion of the accepted context.
    $rows = query($db, "SELECT id, SHA2(CAST(body AS BINARY),256) AS digest FROM $cat.poptracker_comments
        WHERE video_id=? AND id IN ($marks) FOR SHARE", [$job['target'],...$ids])->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($proof->comments as $expected) {
        if (($rows[$expected->id] ?? null) !== $expected->sha256) {
            prodFinish($db, $job['id'], 'blocked', 'Исходные комментарии изменились во время проверки на телефоне.');
            return;
        }
    }

    $written = query($db, "INSERT INTO production_comment_context(video_id,comment_id,evaluation_id,source,source_run_id,
        commenter_id,body,published_at,like_count,observed_at,production_job_id,device_task_id,device_id)
        SELECT c.video_id,c.id,?,c.source,c.source_run_id,c.commenter_id,c.body,c.published_at,c.like_count,c.observed_at,?,?,?
        FROM $cat.poptracker_comments c WHERE c.video_id=? AND c.id IN ($marks) AND NOT EXISTS
        (SELECT 1 FROM production_comment_context p WHERE p.video_id=c.video_id AND p.comment_id=c.id)",
        [$approval,$job['id'],$task,$device,$job['target'],...$ids])->rowCount();
    prodCommentProgress($db, $job, $written);
}

/** The timer handles disconnected phones even if no further claim or report arrives. */
function prodExpirePhoneComments(PDO $db): void
{
    $expired = query($db, "SELECT j.id,j.device_task_id FROM production_jobs j
        LEFT JOIN device_task_attempts a ON a.task_id=j.device_task_id
        WHERE j.status='running' AND j.device_task_id IS NOT NULL AND
        (a.attempt_id IS NULL OR a.status='expired' OR (a.status='leased' AND a.deliver_until<=UTC_TIMESTAMP()))")->fetchAll();
    foreach ($expired as $job) {
        query($db, "UPDATE production_jobs SET status='blocked',device_task_id=NULL,finished_at=UTC_TIMESTAMP(6),
            error='Телефон не доставил результат проверки до окончания срока.'
            WHERE id=? AND status='running' AND device_task_id=?", [$job['id'],$job['device_task_id']]);
    }
}
