<?php
declare(strict_types=1);

require dirname(__DIR__).'/production_worker.php';

function checkPhoneComments(bool $ok, string $case): void
{
    if (!$ok) {
        throw new RuntimeException($case);
    }
    echo "PASS $case\n";
}

function expectPhoneError(callable $operation, array $statuses, string $case): void
{
    try {
        $operation();
    } catch (ApiError $e) {
        checkPhoneComments(in_array($e->getCode(), $statuses, true), $case);
        return;
    }
    throw new RuntimeException($case);
}

$db = database();
$cat = prodCatalogName();
if (!str_starts_with((string)$db->query('SELECT DATABASE()')->fetchColumn(), 'poptracker_farm_test_')
    || !str_starts_with($cat, 'dataroom_test_')) {
    throw new RuntimeException('Disposable farm and catalog databases required');
}
$video = '70002';
query($db, "INSERT INTO $cat.poptracker_authors(id,username,last_seen_at) VALUES('70001','fixture',UTC_TIMESTAMP(6))");
query($db, "INSERT INTO $cat.poptracker_videos(id,author_id,url,published_at,first_seen_at,last_seen_at)
    VALUES(?,'70001','https://example.test/video/70002',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))", [$video]);
query($db, "INSERT INTO $cat.poptracker_scans(id,source,external_run_id,finished_at,request_market,payload_sha256,imported_at,row_count,new_video_count)
    VALUES(70003,'fixture','phone-comments',UTC_TIMESTAMP(6),'ZA',REPEAT('a',64),UTC_TIMESTAMP(6),1,0)");
query($db, "INSERT INTO $cat.poptracker_observations(id,scan_id,video_id,observed_at,source_row_number,view_count)
    VALUES(70004,70003,?,UTC_TIMESTAMP(6),1,42)", [$video]);
$addComment = function (int $id) use ($db, $cat, $video): void {
    query($db, "INSERT INTO $cat.poptracker_comments(id,video_id,body,source,source_run_id,observed_at,country_status)
        VALUES(?,?,?,'fixture','test-run',UTC_TIMESTAMP(6),'unknown')", [$id, $video, " Тест $id 👋\nCafe\u{0301}\u{00a0} "]);
};
foreach (range(71001, 71021) as $id) {
    $addComment($id);
}
$decision = function (bool $allow) use ($db, $video): void {
    query($db, "INSERT INTO production_evaluations(job_id,video_id,observation_version,status,attention_status,
        collect_comments,input_sha256,rules_version,model_version,evaluated_at,decision)
        VALUES(?,?,70004,'evaluated','high',?,REPEAT('a',64),?,'fixture',UTC_TIMESTAMP(6),JSON_OBJECT())",
        [deviceId(), $video, (int)$allow, PRODUCTION_TREND_RULES]);
};
$enroll = function (string $version) use ($db): array {
    $enrollment = issue($db, 'Archive fixture '.$version);
    return dispatch($db, 'enroll', ['code'=>$enrollment['code'],'agent_version'=>$version,'android_version'=>'14'], '')
        + ['version'=>$version];
};
$claim = function (array $agent, array $caps, bool $v2 = true) use ($db): ?array {
    $data = ['device_id'=>$agent['device_id'],'agent_version'=>$agent['version'],'capabilities'=>$caps];
    if ($v2) {
        $data['result_protocol'] = 2;
    }
    return dispatch($db, 'tasks/claim', $data, 'Bearer '.$agent['token']);
};
$beat = function (array $agent) use ($db): void {
    dispatch($db, 'heartbeat', ['device_id'=>$agent['device_id'],'agent_version'=>$agent['version'],
        'android_version'=>'14','sent_at'=>gmdate('Y-m-d\TH:i:s\Z')], 'Bearer '.$agent['token']);
};
$reply = function (array $job): array {
    $comments = array_map(static fn(object $c) => ['id'=>$c->id, 'sha256'=>hash('sha256', $c->text)], $job['payload']->comments);
    return ['attempt_id'=>$job['attempt_id'],'lease_token'=>$job['lease_token'],'outcome'=>'succeeded','error'=>null,
        'completed_at'=>gmdate('Y-m-d\TH:i:s\Z'),'execution_timing'=>['start_ms'=>1,'finish_ms'=>2],
        'result'=>['video_id'=>$job['payload']->video_id,'comments'=>$comments]];
};
$submit = function (array $agent, array $job, array $body) use ($db): array {
    $wire = (array) json_decode(json_encode($body, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
    return dispatch($db, 'tasks/'.$job['task_id'].'/result', $wire, 'Bearer '.$agent['token']);
};
$lastJob = fn() => query($db, 'SELECT * FROM production_jobs WHERE target=? ORDER BY created_at DESC,id DESC LIMIT 1', [$video])->fetch();
$stored = fn() => (int) query($db, 'SELECT COUNT(*) FROM production_comment_context WHERE video_id=?',[$video])->fetchColumn();
$cap = ['ARCHIVE_COMMENTS_V1'];
$new = $enroll('0.5.0');
$other = $enroll('0.5.0');
$old = $enroll('0.4.0');
$beat($old);
expectPhoneError(fn() => createTask($db,'any',$cap[0],(object)[]), [422], 'archive data tasks are created only by Production');
expectPhoneError(fn() => prodSubmit($db, 'COLLECT_COMMENTS'), [409], 'unapproved videos cannot enter the phone pipeline');
$decision(true);
checkPhoneComments(prodSubmit($db, 'COLLECT_COMMENTS')['queued'] === 1, 'approved video enters the usual Production queue');
checkPhoneComments($claim($old, []) === null && $claim($new, $cap) === null, 'old agent and a phone without a heartbeat cannot claim archive data');
$beat($new);
checkPhoneComments($claim($new, $cap, false) === null, 'archive payload requires delivery protocol v2');
prodControl($db, true);
checkPhoneComments($claim($new, $cap) === null && !prodWorkerTick($db), 'pause prevents archive dispatch');
prodControl($db, false);
checkPhoneComments(!prodWorkerTick($db), 'server gives a recently capable phone time to claim');
$job = $claim($new, $cap);
checkPhoneComments($job !== null && $job['type'] === $cap[0] && $job['payload']->video_id === $video
    && count($job['payload']->comments) === 20 && strlen(json_encode($job['payload'], PHONE_COMMENTS_JSON)) <= 32768,
    'first phone receives 20 of 21 comments within the byte limit');
$beat($other);
checkPhoneComments($claim($other, $cap) === null, 'another capable phone cannot take over a live lease');
$technical = taskCommand($db,['farm','task:show',$job['task_id']]);
checkPhoneComments(isset($technical['payload']->comments[0]->sha256) && !isset($technical['payload']->comments[0]->text)
    && !str_contains(query($db,'SELECT payload_json FROM device_tasks WHERE task_id=?',[$job['task_id']])->fetchColumn(), 'Тест'),
    'technical history keeps digests instead of source text');
foreach ($job['payload']->comments as $comment) {
    $source = query($db, "SELECT body FROM $cat.poptracker_comments WHERE id=? AND video_id=?", [$comment->id,$video])->fetchColumn();
    if ($source !== $comment->text) {
        throw new RuntimeException('source UTF-8 bytes changed on dispatch');
    }
}
checkPhoneComments(!prodWorkerTick($db) && $stored() === 0, 'timer leaves a live phone lease in progress');
$good = $reply($job);
$bad = $good;
$bad['result']['comments'][0]['sha256'] = str_repeat('0',64);
expectPhoneError(fn() => $submit($new, $job, $bad), [409,422], 'wrong digest rejected');
$bad = $good;
array_pop($bad['result']['comments']);
expectPhoneError(fn() => $submit($new, $job, $bad), [409,422], 'partial reply rejected');
expectPhoneError(fn() => $submit($old, $job, $good), [403,404], 'another device cannot submit this lease');
checkPhoneComments($stored() === 0, 'rejected receipts leave storage untouched');
$ack = $submit($new, $job, $good);
$again = $submit($new, $job, $good);
checkPhoneComments(!$ack['replayed'] && $again['replayed'] && $stored() === 20 && (int)$lastJob()['written'] === 20,
    'duplicate delivery recovers the receipt without saving the first batch twice');
expectPhoneError(fn() => $submit($new, $job, $bad), [409], 'conflicting replay rejected');
$last = $claim($other, $cap);
checkPhoneComments($last !== null && count($last['payload']->comments) === 1 && $last['task_id'] !== $job['task_id'],
    'remaining comment gets its own bounded lease');
$submit($other, $last, $reply($last));
$credited = query($db, 'SELECT device_id,COUNT(*) FROM production_comment_context GROUP BY device_id')->fetchAll(PDO::FETCH_KEY_PAIR);
checkPhoneComments((int)($credited[$new['device_id']] ?? 0) === 20 && (int)($credited[$other['device_id']] ?? 0) === 1
    && $claim($other, $cap) === null, 'both phones receive attribution for their own batch and completed work is not reissued');
checkPhoneComments($stored() === 21 && $lastJob()['status'] === 'completed' && $lastJob()['device_id'] === $other['device_id']
    && (int)query($db,"SELECT COUNT(*) FROM production_comment_context p JOIN $cat.poptracker_comments c ON c.id=p.comment_id
        WHERE p.video_id=? AND BINARY p.body = BINARY c.body",[$video])->fetchColumn() === 21,
    'completed job keeps exact source bytes and the executing phone ID');

$addComment(71022);
prodSubmit($db, 'COLLECT_COMMENTS');
$stale = $claim($new, $cap);
query($db, "UPDATE $cat.poptracker_comments SET body='edited in fixture' WHERE id=71022");
$submit($new, $stale, $reply($stale));
checkPhoneComments($stored() === 21 && $lastJob()['status'] === 'blocked', 'source edited during a lease cannot enter the archive');
$decision(true);
prodSubmit($db, 'COLLECT_COMMENTS');
$stale = $claim($new, $cap);
$decision(false);
$submit($new, $stale, $reply($stale));
checkPhoneComments($stored() === 21 && $lastJob()['status'] === 'blocked', 'revoking approval during a lease blocks the write');

$decision(true);
prodSubmit($db, 'COLLECT_COMMENTS');
$parent = $lastJob()['id'];
query($db,'UPDATE production_jobs SET next_run_at=UTC_TIMESTAMP(6)-INTERVAL 40 SECOND WHERE id=?',[$parent]);
checkPhoneComments(prodWorkerTick($db) && $stored() === 22 && $lastJob()['status'] === 'completed',
    'server fallback finishes an unclaimed job after the phone reservation');
$addComment(71024);
query($db, "UPDATE $cat.poptracker_comments SET body=REPEAT('x',33000) WHERE id=71024");
prodSubmit($db, 'COLLECT_COMMENTS');
prodCancel($db,$lastJob()['id']);
checkPhoneComments($claim($new, $cap) === null && $stored() === 22, 'cancelled production work cannot be assigned');
prodSubmit($db, 'COLLECT_COMMENTS');
checkPhoneComments($claim($new, $cap) === null && $lastJob()['status'] === 'pending',
    'an oversized comment stays in the queue for the server');
query($db,'UPDATE production_jobs SET next_run_at=UTC_TIMESTAMP(6)-INTERVAL 40 SECOND WHERE id=?',[$lastJob()['id']]);
checkPhoneComments(prodWorkerTick($db) && $stored() === 23 && $lastJob()['status'] === 'completed',
    'the server archives the oversized comment without dropping it');
$addComment(71023);
prodSubmit($db, 'COLLECT_COMMENTS');
$abandoned = $claim($new, $cap);
query($db, 'UPDATE device_task_attempts SET lease_expires_at=UTC_TIMESTAMP()-INTERVAL 30 SECOND,
    deliver_until=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE attempt_id=?', [$abandoned['attempt_id']]);
prodWorkerTick($db);
checkPhoneComments($lastJob()['status'] === 'blocked' && $stored() === 23, 'expired phone delivery cannot silently replay the original assignment');
