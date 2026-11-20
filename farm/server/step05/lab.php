<?php
declare(strict_types=1);
require __DIR__.'/farm.php';
$db=database();
if (PHP_SAPI!=='cli' || $db->query('SELECT DATABASE()')->fetchColumn()!=='poptracker_farm_test_step05' || !defined('TEST_BATCH_TASK')) {
    throw new RuntimeException('Controlled STEP05 CLI only');
}
$input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
$action=$input['action']??null;
if ($action==='issue') {
    $out=issue($db,'Controlled ContentProvider 20261120');
} else {
    $id=$input['device_id']??null;
    if (!isUuid($id)) { throw new RuntimeException('Invalid test device'); }
    $device=query($db,"SELECT display_name FROM device_agents WHERE device_id=?",[$id])->fetchColumn();
    if ($device!=='Controlled ContentProvider 20261120') { throw new RuntimeException('Different test device'); }
    if ($action==='queue') {
        $numbers=$input['fixtures']??null;
        if (!is_array($numbers) || !$numbers || count($numbers)>10) { throw new RuntimeException('Invalid test fixtures'); }
        $out=[];$db->beginTransaction();
        try {
            foreach ($numbers as $n) {
                if (!is_int($n) || $n<1 || $n>10) { throw new RuntimeException('Invalid fixture'); }
                $payload=testBatchRequest(deviceId(),$n);
                $out[]=['task_id'=>createTask($db,$id,TEST_BATCH_TASK,$payload),'fixture'=>$n];
            }
            $db->commit();
        } finally { if ($db->inTransaction()) { $db->rollBack(); } }
    } elseif ($action==='summary') {
        $out=query($db,"SELECT t.task_id,t.status,t.payload_json,a.result_json,a.error_code,a.error_message,
            a.reported_finish_ms-a.reported_start_ms AS processing_ms,a.replay_count,a.completed_at
            FROM device_tasks t LEFT JOIN device_task_attempts a USING(task_id)
            WHERE t.device_id=? AND t.type=? ORDER BY t.created_at,t.task_id",[$id,TEST_BATCH_TASK])->fetchAll();
        foreach ($out as &$row) {
            $row['payload']=json_decode($row['payload_json']);$row['result']=json_decode($row['result_json']??'null');
            unset($row['payload_json'],$row['result_json']);
        }
    } elseif ($action==='revoke') {
        query($db,'UPDATE device_agents SET revoked_at=UTC_TIMESTAMP() WHERE device_id=?',[$id]);$out=['revoked'=>true];
    } else { throw new RuntimeException('Unknown test action'); }
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),"\n";
