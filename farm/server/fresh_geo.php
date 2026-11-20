<?php
declare(strict_types=1);


function freshGeoMembers(array $targets, array $controls): array {
    $members=[];
    foreach ([['target',$targets],['control',$controls]] as [$role,$rows]) {
        foreach ($rows as $row) {
            $member=['role'=>$role, 'uid'=>$row['author_uid'], 'vid'=>$row['video_id']];
            if ($role==='control') { $member['expected']=$row['configured_country_code']; }
            $members[]=$member;
        }
    }
    return $members;
}

function freshGeoDigest(array $members): string {
    return hash('sha256', implode('', array_map(static fn(array $m): string =>
        $m['role'].'|'.$m['uid'].'|'.$m['vid']."\n", $members)));
}

/** Latest accepted phone observation for each exact target pair in the preview. */
function freshGeoTargetObservations(PDO $db, array $targets): array {
    if (!$targets) { return []; }
    $pairs = array_map(static fn(array $t): array => ['uid'=>$t['author_uid'], 'vid'=>$t['video_id']], $targets);
    $rows = query($db, "WITH requested AS (
        SELECT p.uid,p.vid FROM JSON_TABLE(?, '$[*]' COLUMNS (
            uid VARCHAR(32) PATH '$.uid', vid VARCHAR(32) PATH '$.vid')) p
    ), ranked AS (
        SELECT r.uid,r.vid,o.region,o.observed_ms,ROW_NUMBER() OVER (
            PARTITION BY r.uid,r.vid ORDER BY o.observed_ms DESC,a.completed_at DESC,t.task_id DESC
        ) AS position_in_pair
        FROM device_tasks t JOIN device_task_attempts a ON a.task_id=t.task_id AND a.status='succeeded'
        JOIN JSON_TABLE(a.result_json,'$.observations[*]' COLUMNS (
            uid VARCHAR(32) PATH '$.uid', vid VARCHAR(32) PATH '$.vid', role_name VARCHAR(8) PATH '$.role',
            region CHAR(2) PATH '$.region', observed_ms BIGINT PATH '$.observed_ms'
        )) o ON o.role_name='target'
        JOIN requested r ON r.uid=o.uid AND r.vid=o.vid
        WHERE t.type=? AND t.status='succeeded'
    ) SELECT uid,vid,region,observed_ms FROM ranked WHERE position_in_pair=1",
        [json_encode($pairs,JSON_THROW_ON_ERROR), FRESH_GEO_TASK]);
    $byPair = [];
    foreach ($rows as $row) {
        $byPair[$row['uid'].'|'.$row['vid']] = ['region'=>$row['region'], 'observed_ms'=>(int)$row['observed_ms']];
    }
    foreach ($targets as &$target) {
        $target['geo_observation'] = $byPair[$target['author_uid'].'|'.$target['video_id']] ?? null;
    }
    return $targets;
}

function freshGeoPreview(PDO $db): array {
    $targets=freshGeoTargetObservations($db,geoPilotTargets($db));
    $controls=geoPilotControls($db);
    $members=freshGeoMembers($targets,$controls);
    $phones=query($db,"SELECT device_id,display_name FROM device_agents WHERE revoked_at IS NULL
        AND fresh_geo_seen_at>=UTC_TIMESTAMP()-INTERVAL 150 SECOND ORDER BY display_name,device_id")->fetchAll();
    $latest=query($db,"SELECT t.task_id,t.device_id,CASE
        WHEN t.status='queued' AND t.created_at<UTC_TIMESTAMP()-INTERVAL 2 MINUTE THEN 'expired'
        WHEN t.status='leased' AND COALESCE(a.deliver_until,a.lease_expires_at)<=UTC_TIMESTAMP() THEN 'expired'
        ELSE t.status END AS status,a.error_code,a.error_message,a.result_json
        FROM device_tasks t LEFT JOIN device_task_attempts a ON a.task_id=t.task_id
        WHERE t.type=? ORDER BY t.created_at DESC,t.task_id DESC LIMIT 1",[FRESH_GEO_TASK])->fetch();
    if ($latest) {
        $latest['result']=$latest['result_json']===null ? null : json_decode($latest['result_json']);
        unset($latest['result_json']);
    }
    $paused = query($db,'SELECT paused FROM production_state WHERE id=1')->fetchColumn();
    return ['prepared_at'=>gmdate('Y-m-d\TH:i:s\Z'),'targets'=>$targets,'controls'=>$controls,
        'scope_sha256'=>freshGeoDigest($members),'ready_devices'=>$phones,'paused'=>$paused===false || (bool)$paused,'last_task'=>$latest ?: null];
}

/** Only the server constructs the scope. A repeated browser request can retrieve its original receipt. */
function freshGeoSubmit(PDO $db,string $device,string $id,string $hash): array {
    if (!isUuid($device) || !isUuid($id) || !preg_match('/\A[0-9a-f]{64}\z/D',$hash)) { reject(422,'invalid_geo_intent'); }
    $db->beginTransaction();
    try {
        $phone=query($db,"SELECT revoked_at, fresh_geo_seen_at>=UTC_TIMESTAMP()-INTERVAL 150 SECOND AS ready
            FROM device_agents WHERE device_id=? FOR UPDATE",[$device])->fetch();
        if (!$phone || $phone['revoked_at']!==null) { reject(409,'Телефон недоступен.'); }
        $old=query($db,'SELECT type, device_id,payload_json,status FROM device_tasks WHERE task_id=?',[$id])->fetch();
        if ($old) {
            $payload=json_decode($old['payload_json'],true);
            if ($old['device_id']!==$device || $old['type']!==FRESH_GEO_TASK || ($payload['scope_sha256']??null)!==$hash) {
                reject(409,'Идентификатор запуска занят.');
            }
            $db->commit();
            return ['task_id'=>$id,'status'=>$old['status']];
        }
        $paused=query($db,'SELECT paused FROM production_state WHERE id=1 FOR UPDATE')->fetchColumn();
        if ($paused===false || (int)$paused!==0 || !(int)$phone['ready']) { reject(409,'Телефон с источником свежего региона недоступен либо очередь приостановлена.'); }
        query($db,"UPDATE device_tasks SET status='cancelled',updated_at=UTC_TIMESTAMP(6) WHERE type=? AND status='queued' AND created_at<UTC_TIMESTAMP()-INTERVAL 2 MINUTE",[FRESH_GEO_TASK]);
        expireTaskLeases($db,null,FRESH_GEO_TASK);
        if (query($db,"SELECT task_id FROM device_tasks WHERE type=? AND status IN ('queued','leased') LIMIT 1",
                [FRESH_GEO_TASK])->fetchColumn()) { reject(409,'Дождитесь завершения предыдущей геопроверки.'); }
        $targets=geoPilotTargets($db);
        $controls=geoPilotControls($db);
        $members=freshGeoMembers($targets,$controls);
        if (!$targets || count($controls)!==2 || array_column($controls,'reference_status')!==['historical_country_matches','historical_country_matches']
            || !hash_equals(freshGeoDigest($members),$hash)) { reject(409,'Выборка или контрольные аккаунты изменились. Обновите выборку.'); }
        $payload=json_encode(['version'=>1,'request_id'=>$id,'scope_sha256'=>$hash,'members'=>$members],JSON_THROW_ON_ERROR);
        query($db,"INSERT INTO device_tasks(task_id,device_id,type,payload_json,created_at,updated_at)
            VALUES (?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$id,$device,FRESH_GEO_TASK,$payload]);
        $db->commit();
        return ['task_id'=>$id,'status'=>'queued'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $e;
    }
}

function validFreshGeoReport(object $payload,object $r,int $leasedAt,int $receivedAt): bool {
    exactFields((array)$r,['request_id','scope_sha256','observations']);
    if ($r->request_id!==$payload->request_id || $r->scope_sha256!==$payload->scope_sha256 ||
        !is_array($r->observations) || count($r->observations)!==count($payload->members)) { return false; }
    $expected=[];
    foreach ($payload->members as $m) { $expected[$m->uid.'|'.$m->vid]=$m; }
    foreach ($r->observations as $o) {
        if (!$o instanceof stdClass) { return false; }
        exactFields((array)$o,['uid','vid','role','observed_ms','evidence_sha256','region']);
        if (!is_string($o->uid) || !is_string($o->vid) || !is_int($o->observed_ms) ||
            $o->observed_ms<$leasedAt*1000 || $o->observed_ms>min(($receivedAt+1)*1000,($leasedAt+1)*1000+EXECUTION_BUDGET_MS) ||
            !is_string($o->evidence_sha256) || !preg_match('/\A[0-9a-f]{64}\z/D',$o->evidence_sha256)) { return false; }
        $key=$o->uid.'|'.$o->vid;
        $m=$expected[$key]??null;
        if (!$m || $o->role!==$m->role || ($o->region!==null && (!is_string($o->region) ||
            !preg_match('/\A[A-Z]{2}\z/D',$o->region) || in_array($o->region,['XX','ZZ'],true))) ||
            ($m->role==='control' && $o->region!==$m->expected)) { return false; }
        unset($expected[$key]);
    }
    return !$expected;
}
