<?php
declare(strict_types=1);

require_once __DIR__.'/ui_scenario.php';
require_once __DIR__.'/collection.php';

const UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
const START_BUDGET_MS = 20_000;
const EXECUTION_BUDGET_MS = 120_000;
const DELIVERY_GRACE_SECONDS = 900;
const ARCHIVE_COMMENTS_TASK = 'ARCHIVE_COMMENTS_V1';
const FRESH_GEO_TASK = 'FRESH_AUTHOR_GEO_V1';

function isUuid(mixed $value): bool {
    return is_string($value) && preg_match('/\A'.UUID_PATTERN.'\z/', $value) === 1;
}

function exactFields(array $fields, array $required, array $optional = []): void {
    $keys = array_keys($fields);
    if (array_diff($required, $keys) || array_diff($keys, array_merge($required, $optional))) {
        reject(422, 'invalid_fields');
    }
}

function integerBetween(mixed $n, int $min, int $max): bool {
    return is_int($n) && $n >= $min && $n <= $max;
}

function taskTimestamp(mixed $value): bool {
    if (!is_string($value) || strlen($value) !== 20) { return false; }
    $format = 'Y-m-d\TH:i:s\Z';
    $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
    return $date && $date->format($format) === $value && substr($value, 0, 4) >= '1000';
}

function createTask(PDO $db, string $device, string $type, mixed $payload): string {
    if (($device !== 'any' && !isUuid($device)) || !$payload instanceof stdClass) { reject(422, 'invalid_task'); }
    switch ($type) {
        case COLLECTION_TYPE:
            validateCollectionParameters($payload);
            break;
        case 'UI_TEST_SCENARIO':
            exactFields((array)$payload,['scenario']);
            if ($payload->scenario!==OWN_UI_SCENARIO) { reject(422,'invalid_scenario'); }
            break;
        case 'PING':
        case 'GET_DEVICE_STATUS':
            exactFields((array)$payload, []);
            break;
        case 'EXECUTE_TEST_TASK':
            exactFields((array)$payload, ['duration_ms']);
            if (!integerBetween($payload->duration_ms, 0, 5000)) { reject(422, 'invalid_duration'); }
            break;
        default:
            reject(422, 'unsupported_task');
    }
    $id = deviceId();
    $json = json_encode($payload,JSON_THROW_ON_ERROR);
    if ($device === 'any') {
        query($db,"INSERT INTO device_tasks (task_id,device_id,assignment_mode,type,payload_json,created_at,updated_at)
            VALUES (?,NULL,'any',?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$id,$type,$json]);
        return $id;
    }
    $insert = query($db, "INSERT INTO device_tasks (task_id,device_id,type,payload_json,created_at,updated_at)
        SELECT ?,device_id,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6) FROM device_agents WHERE device_id=? AND revoked_at IS NULL",
        [$id,$type,$json,$device]);
    if ($insert->rowCount() !== 1) { reject(404, 'device_unavailable'); }
    return $id;
}

// MySQL updates both rows atomically. No automatic re-execution of an expired attempt.
function expireTaskLeases(PDO $db, ?string $device = null, ?string $type = null): void {
    $sql = "UPDATE device_tasks t JOIN device_task_attempts a ON a.task_id=t.task_id
        LEFT JOIN device_task_attempts newer ON newer.task_id=t.task_id AND newer.attempt_no>a.attempt_no
        SET t.status='failed',t.updated_at=UTC_TIMESTAMP(6),a.status='expired',a.completed_at=UTC_TIMESTAMP(),a.error_code='LEASE_EXPIRED'
        WHERE t.status='leased' AND a.status='leased' AND newer.attempt_id IS NULL
        AND COALESCE(a.deliver_until,a.lease_expires_at)<=UTC_TIMESTAMP()";
    $filters = [];
    if ($device !== null) { $sql .= ' AND t.device_id=?'; $filters[] = $device; }
    if ($type !== null) { $sql .= ' AND t.type=?'; $filters[] = $type; }
    query($db, $sql, $filters);
}

function claimTask(PDO $db, string $device, array $data, bool $online = false): ?array {
    exactFields($data, ['device_id','agent_version'], ['capabilities','result_protocol']);
    $v2 = array_key_exists('result_protocol',$data);
    if ($v2 && $data['result_protocol'] !== 2) { reject(422,'unsupported_result_protocol'); }
    $caps = array_key_exists('capabilities',$data) ? $data['capabilities'] : [];
    if (!is_array($caps) || count($caps)>4) { reject(422,'invalid_capabilities'); }
    $seen = [];
    foreach ($caps as $cap) {
        if (!in_array($cap,[OWN_UI_SCENARIO,COLLECTION_TYPE,ARCHIVE_COMMENTS_TASK,FRESH_GEO_TASK],true) || isset($seen[$cap])) { reject(422,'invalid_capabilities'); }
        $seen[$cap] = true;
    }
    if ($data['device_id'] !== $device) { reject(403, 'device_mismatch'); }
    $version = versionField($data, 'agent_version');
    $freshGeo = $online && $v2 && isset($seen[FRESH_GEO_TASK]);
    if ($freshGeo) {
        query($db,'UPDATE device_agents SET fresh_geo_seen_at=UTC_TIMESTAMP(6) WHERE device_id=?',[$device]);
    } elseif ($online && version_compare($version,'0.6.0','>=')) {
        query($db,'UPDATE device_agents SET fresh_geo_seen_at=NULL WHERE device_id=? AND fresh_geo_seen_at IS NOT NULL',[$device]);
    }
    if ($freshGeo) {
        $paused=query($db,'SELECT paused FROM production_state WHERE id=1 FOR SHARE')->fetchColumn();
        $freshGeo=$paused!==false && (int)$paused===0;
    }
    $supportsCollection = isset($seen[COLLECTION_TYPE]) && $v2;
    $archiveComments = $online && $v2 && isset($seen[ARCHIVE_COMMENTS_TASK]);
    if ($archiveComments) {
        query($db, 'UPDATE device_agents SET archive_comments_seen_at=UTC_TIMESTAMP(6) WHERE device_id=?', [$device]);
    }
    $supportsUi = isset($seen[OWN_UI_SCENARIO]) && preg_match('/^\d+\.\d+\.\d+$/D',$version)
        && version_compare($version,'0.3.0','>=');
    // dispatch already owns the authenticated device row. Claims for this device serialize.
    expireTaskLeases($db, $device);
    if (query($db, "SELECT task_id FROM device_tasks WHERE device_id=? AND status='leased' LIMIT 1 FOR UPDATE", [$device])->fetchColumn()) {
        return null;
    }
    $select = "SELECT task_id,type,payload_json,schema_version FROM device_tasks
        WHERE status='queued' AND type<>'ARCHIVE_COMMENTS_V1'
        AND (type<>'FRESH_AUTHOR_GEO_V1' OR (?=1 AND created_at>=UTC_TIMESTAMP()-INTERVAL 2 MINUTE)) AND (type<>'UI_TEST_SCENARIO' OR ?=1) AND (type<>'COLLECT_TEST_DATA' OR ?=1)";
    $order = " ORDER BY created_at,task_id LIMIT 1 FOR UPDATE SKIP LOCKED";
    $capabilities = [(int)$freshGeo,(int)$supportsUi,(int)$supportsCollection];
    $task = query($db,$select.' AND device_id=?'.$order,[...$capabilities,$device])->fetch();
    if (!$task && $online) {
        $task = query($db,$select.' AND device_id IS NULL'.$order,$capabilities)->fetch();
    }
    if (!$task && $archiveComments) {
        require_once __DIR__.'/production.php';
        $task = prodClaimPhoneComments($db, $device);
    }
    if (!$task) { return null; }
    $attempt = deviceId();
    $lease = bin2hex(random_bytes(32));
    $executionSeconds = intdiv(EXECUTION_BUDGET_MS,1000);
    query($db, "INSERT INTO device_task_attempts (attempt_id,task_id,lease_token_hash,leased_at,lease_expires_at,deliver_until,status)
        VALUES (?,?,UNHEX(?),UTC_TIMESTAMP(),UTC_TIMESTAMP()+INTERVAL ? SECOND,
        IF(?=1,UTC_TIMESTAMP()+INTERVAL ? SECOND,NULL),'leased')",
        [$attempt,$task['task_id'],hash('sha256', $lease),$executionSeconds,(int)$v2,$executionSeconds+DELIVERY_GRACE_SECONDS]);
    query($db, "UPDATE device_tasks SET status='leased',device_id=?,updated_at=UTC_TIMESTAMP(6) WHERE task_id=?", [$device,$task['task_id']]);
    $expires = query($db,"SELECT DATE_FORMAT(lease_expires_at,'%Y-%m-%dT%H:%i:%sZ') FROM device_task_attempts WHERE attempt_id=?",[$attempt])->fetchColumn();
    $reply = ['task_id'=>$task['task_id'],'attempt_id'=>$attempt,'lease_token'=>$lease,'type'=>$task['type'],
        'schema_version'=>(int)$task['schema_version'],'payload'=>json_decode($task['payload_json']),'lease_expires_at'=>$expires];
    if ($task['type'] === COLLECTION_TYPE) { $reply['device_id'] = $device; }
    if ($v2) {
        $reply += ['result_protocol'=>2,'start_budget_ms'=>START_BUDGET_MS,'execution_budget_ms'=>EXECUTION_BUDGET_MS];
    }
    return $reply;
}

function validText(mixed $s, int $max): bool {
    return is_string($s) && $s !== '' && strlen($s) <= $max && !preg_match('/[[:cntrl:]]/', $s);
}

function validateTaskOutput(string $type, object $payload, array $data, ?int $leasedAt=null): void {
    if (!taskTimestamp($data['completed_at'])) { reject(422, 'invalid_completed_at'); }
    if ($type==='UI_TEST_SCENARIO' && $data['result']!==null) {
        validateUiReport($data['result'],$data);
        return;
    }
    if ($data['outcome'] === 'failed') {
        $error = $data['error'];
        if (!$error instanceof stdClass || $data['result'] !== null) { reject(422,'invalid_error'); }
        exactFields((array)$error,['code','message']);
        if (!in_array($error->code,['UNSUPPORTED_TASK','INVALID_PAYLOAD','EXECUTION_UNKNOWN','EXECUTION_FAILED'],true)
            || !validText($error->message,200)) { reject(422,'invalid_error'); }
        return;
    }
    $r = $data['result'];
    if ($data['outcome'] !== 'succeeded' || !$r instanceof stdClass || $data['error'] !== null) { reject(422,'invalid_result'); }
    switch ($type) {
        case FRESH_GEO_TASK:
            require_once __DIR__.'/fresh_geo.php';
            $valid=$leasedAt!==null && validFreshGeoReport($payload,$r,$leasedAt,time());
            break;
        case ARCHIVE_COMMENTS_TASK:
            exactFields((array)$r, ['video_id','comments']);
            if ($r->video_id !== $payload->video_id || !is_array($r->comments) || count($r->comments) !== count($payload->comments)) {
                reject(422, 'archive_digest_mismatch');
            }
            foreach ($payload->comments as $i => $expected) {
                $actual = $r->comments[$i];
                if (!$actual instanceof stdClass) { reject(422, 'invalid_archive_result'); }
                exactFields((array)$actual, ['id','sha256']);
                if ($actual->id !== $expected->id || $actual->sha256 !== $expected->sha256) {
                    reject(422, 'archive_digest_mismatch');
                }
            }
            return;
        case COLLECTION_TYPE: return; // Batch validation and storage share the enclosing transaction.
        case 'PING':
            exactFields((array)$r,['pong','executed_at']);
            $valid = $r->pong === true && taskTimestamp($r->executed_at);
            break;
        case 'GET_DEVICE_STATUS':
            exactFields((array)$r,['manufacturer','model','android_version','api_level','agent_version'],['battery_percent','is_charging']);
            $valid = validText($r->manufacturer,100) && validText($r->model,100) && validText($r->android_version,32)
                && validText($r->agent_version,32) && integerBetween($r->api_level,1,1000)
                && (!isset($r->battery_percent) || integerBetween($r->battery_percent,0,100))
                && (!isset($r->is_charging) || is_bool($r->is_charging));
            break;
        case 'EXECUTE_TEST_TASK':
            exactFields((array)$r,['requested_duration_ms','elapsed_ms','completed']);
            $valid = $r->requested_duration_ms === $payload->duration_ms && $r->completed === true
                && integerBetween($r->elapsed_ms,$payload->duration_ms,2147483647);
            break;
        default: $valid = false;
    }
    if (!$valid) { reject(422,'invalid_result'); }
}

// Keep ordered arrays (scenario steps) while ignoring JSON object property order.
function canonicalValue(mixed $value): mixed {
    if (is_array($value)) { return array_map('canonicalValue',$value); }
    if (!$value instanceof stdClass) { return $value; }
    $fields = (array)$value;
    ksort($fields);
    return (object)array_map('canonicalValue',$fields);
}

function resultDigest(array $data): string {
    $parts = [$data['outcome'],$data['completed_at'],canonicalValue($data['result']),canonicalValue($data['error'])];
    if (array_key_exists('execution_timing',$data)) { $parts[] = canonicalValue($data['execution_timing']); }
    return hash('sha256',json_encode($parts, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
}

function taskReceipt(PDO $db, string $id, bool $replayed): array {
    $r = query($db,"SELECT task_id,attempt_id,status,result_json,error_code,error_message,
        DATE_FORMAT(completed_at,'%Y-%m-%dT%H:%i:%sZ') AS received_at FROM device_task_attempts WHERE attempt_id=?",[$id])->fetch();
    return ['task_id'=>$r['task_id'],'attempt_id'=>$id,'outcome'=>$r['status'],'received_at'=>$r['received_at'],
        'result'=>json_decode($r['result_json'] ?? 'null'),'error'=>$r['error_code'] === null ? null : ['code'=>$r['error_code'],'message'=>$r['error_message']],
        'replayed'=>$replayed];
}

function acceptTaskResult(PDO $db, string $device, string $taskId, array $data): array|ApiError {
    exactFields($data,['attempt_id','lease_token','outcome','result','error','completed_at'],['execution_timing']);
    $attempt = $data['attempt_id'];
    $token = $data['lease_token'];
    if (!isUuid($attempt) || !is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/', $token)) { reject(422,'invalid_attempt'); }
    $task = query($db,'SELECT type,payload_json,status FROM device_tasks WHERE task_id=? AND device_id=? FOR UPDATE', [$taskId,$device])->fetch();
    if (!$task) { reject(404,'task_not_found'); }
    $a = query($db,'SELECT leased_at,status,lease_token_hash,result_digest,deliver_until,lease_expires_at<=UTC_TIMESTAMP() AS expired,
        deliver_until<=UTC_TIMESTAMP() AS delivery_expired
        FROM device_task_attempts WHERE attempt_id=? AND task_id=? FOR UPDATE',[$attempt,$taskId])->fetch();
    if (!$a || !hash_equals($a['lease_token_hash'],hash('sha256',$token,true))) { reject(403,'invalid_lease'); }
    $v2 = $a['deliver_until'] !== null;
    if ($v2 !== array_key_exists('execution_timing',$data)) { reject(422,'invalid_result_protocol'); }
    $digest = resultDigest($data);
    if ($a['result_digest'] !== null) {
        if (!hash_equals($a['result_digest'],hex2bin($digest))) { reject(409,'result_conflict'); }
        query($db,'UPDATE device_task_attempts SET replay_count=replay_count+1 WHERE attempt_id=?',[$attempt]);
        return taskReceipt($db,$attempt,true);
    }
    $latest = query($db,'SELECT attempt_id FROM device_task_attempts WHERE task_id=? ORDER BY attempt_no DESC LIMIT 1 FOR UPDATE',[$taskId])->fetchColumn();
    if ($latest !== $attempt) { reject(409,'attempt_superseded'); }
    if (($v2 ? $a['delivery_expired'] : $a['expired']) || $a['status'] === 'expired') {
        expireTaskLeases($db,$device);
        query($db,'UPDATE device_task_attempts SET late_results=late_results+1 WHERE attempt_id=?',[$attempt]);
        // Return the conflict after committing the expiration and diagnostic counter.
        return new ApiError($v2 ? 'delivery_expired' : 'lease_expired',409);
    }
    if ($a['status'] !== 'leased' || $task['status'] !== 'leased') { reject(409,'attempt_closed'); }
    $payload = json_decode($task['payload_json']);
    $leasedAt=$task['type']===FRESH_GEO_TASK ? strtotime($a['leased_at'].' UTC') : null;
    validateTaskOutput($task['type'],$payload,$data,$leasedAt);
    $timing = $data['execution_timing'] ?? null;
    if ($v2) { validateExecutionTiming($timing,$data); }
    $error = $data['error'];
    $output = $data['result'];
    if ($task['type'] === COLLECTION_TYPE && $output !== null) {
        $output = storeCollection($db,$output,$payload,$device,$taskId,$attempt);
    }
    if ($task['type'] === ARCHIVE_COMMENTS_TASK) {
        require_once __DIR__.'/production.php';
        prodAcceptPhoneComments($db, $device, $taskId, $payload, $data['outcome'] === 'succeeded');
        if ($error !== null) {
            // Never retain user text that a faulty client might include in its diagnostic.
            $error = (object)['code'=>$error->code, 'message'=>'Archive verification failed'];
        }
    }
    if ($task['type'] === 'GET_DEVICE_STATUS' && $data['outcome'] === 'succeeded') {
        query($db,'UPDATE device_agents SET manufacturer=?,model=? WHERE device_id=?',[$output->manufacturer,$output->model,$device]);
    }
    $result = $output === null ? null : json_encode($output,JSON_THROW_ON_ERROR);
    query($db,"UPDATE device_task_attempts SET status=?,completed_at=UTC_TIMESTAMP(),agent_completed_at=?,
        result_json=?,error_code=?,error_message=?,result_digest=UNHEX(?),reported_start_ms=?,reported_finish_ms=?,
        late_results=late_results+? WHERE attempt_id=?",
        [$data['outcome'],str_replace(['T','Z'],[' ',''],$data['completed_at']),$result,$error->code ?? null,$error->message ?? null,
        $digest,$timing->start_ms ?? null,$timing->finish_ms ?? null,(int)($v2 && $a['expired']),$attempt]);
    query($db,'UPDATE device_tasks SET status=?,updated_at=UTC_TIMESTAMP(6) WHERE task_id=?',[$data['outcome'],$taskId]);
    return taskReceipt($db,$attempt,false);
}

function validateExecutionTiming(mixed $timing,array $data): void {
    if ($timing === null && $data['outcome']==='failed' && $data['result']===null
        && in_array($data['error']->code,['EXECUTION_UNKNOWN','EXECUTION_FAILED'],true)) { return; }
    if (!$timing instanceof stdClass) { reject(422,'invalid_timing'); }
    exactFields((array)$timing,['start_ms','finish_ms']);
    if (!is_int($timing->start_ms) || !is_int($timing->finish_ms) || $timing->start_ms<0 || $timing->finish_ms<$timing->start_ms) {
        reject(422,'invalid_timing');
    }
    if ($timing->start_ms>START_BUDGET_MS || $timing->finish_ms>EXECUTION_BUDGET_MS) { reject(409,'execution_window'); }
}

function taskCommand(PDO $db, array $argv): array {
    $id = $argv[2] ?? null;
    if ($id !== null && !isUuid($id) && !(($argv[1] ?? '')==='task:create' && $id==='any')) { reject(422,'invalid_uuid'); }
    switch ($argv[1]) {
        case 'task:create':
            if (!$id || !isset($argv[3]) || count($argv)>5) { reject(422,'expected_device_type_and_optional_json'); }
            $payload = json_decode($argv[4] ?? '{}',false,4,JSON_THROW_ON_ERROR);
            return ['task_id'=>createTask($db,$id,$argv[3],$payload)];
        case 'task:list':
            expireTaskLeases($db,$id);
            $sql='SELECT task_id,device_id,assignment_mode,type,status,created_at,updated_at FROM device_tasks';
            return query($db,$sql.($id ? ' WHERE device_id=?' : '').' ORDER BY created_at DESC LIMIT 100',$id ? [$id] : [])->fetchAll();
        case 'task:show':
            $task = query($db,'SELECT device_id FROM device_tasks WHERE task_id=?',[$id])->fetchColumn();
            if ($task === false) { reject(404,'task_not_found'); }
            if ($task !== null) { expireTaskLeases($db,$task); }
            $row = query($db,'SELECT task_id,device_id,assignment_mode,type,status,schema_version,payload_json,max_attempts,created_at,updated_at FROM device_tasks WHERE task_id=?',[$id])->fetch();
            $row['payload'] = json_decode($row['payload_json']);
            unset($row['payload_json']);
            $row['attempts'] = query($db,'SELECT attempt_id,attempt_no,status,leased_at,lease_expires_at,completed_at,agent_completed_at,
                result_json,error_code,error_message,replay_count,late_results,deliver_until,reported_start_ms,reported_finish_ms,
                CASE WHEN status="leased" AND deliver_until IS NOT NULL AND lease_expires_at<=UTC_TIMESTAMP() THEN "awaiting_result"
                     ELSE status END AS delivery_phase
                FROM device_task_attempts WHERE task_id=? ORDER BY attempt_no',[$id])->fetchAll();
            foreach ($row['attempts'] as &$a) {
                $a['result']=json_decode($a['result_json'] ?? 'null');
                unset($a['result_json']);
            }
            return $row;
        case 'task:cancel':
            $changed = query($db,"UPDATE device_tasks SET status='cancelled',updated_at=UTC_TIMESTAMP(6) WHERE task_id=? AND status='queued'",[$id]);
            if (!$changed->rowCount()) { reject(409,'not_queued'); }
            return ['task_id'=>$id,'status'=>'cancelled'];
        default: reject(422,'unknown_task_command');
    }
}
