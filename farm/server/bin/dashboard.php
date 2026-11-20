<?php
declare(strict_types=1);
require dirname(__DIR__).'/farm.php';

try {
    if (PHP_SAPI!=='cli' || $argc!==1) { reject(403,'forbidden'); }
    $input = json_decode(stream_get_contents(STDIN,4097),false,6,JSON_THROW_ON_ERROR);
    if (!$input instanceof stdClass) { reject(422,'invalid_request'); }
    if (in_array($input->action ?? null,['production','production_submit','production_control','production_cancel'],true)) {
        require_once dirname(__DIR__).'/production.php';
    }
    $db=database();
    switch ($input->action ?? null) {
        case 'snapshot':
            exactFields((array)$input,['action']);
            $tasks=query($db,"SELECT t.task_id,t.device_id,t.type,t.status,t.created_at,a.error_code
                FROM device_tasks t LEFT JOIN device_task_attempts a ON a.task_id=t.task_id AND a.attempt_no=1
                ORDER BY t.created_at DESC,t.task_id DESC LIMIT 20")->fetchAll();
            $result=['devices'=>listDevices($db),'tasks'=>$tasks,'sampled_at'=>gmdate('Y-m-d\TH:i:s\Z')];
            break;
        case 'create':
            exactFields((array)$input,['action','target','type','payload']);
            if (!is_string($input->target) || !is_string($input->type)) { reject(422,'invalid_task'); }
            $result=['task_id'=>createTask($db,$input->target,$input->type,$input->payload)];
            break;
        case 'geo_preview':
            exactFields((array)$input, ['action']);
            require_once __DIR__.'/geo-pilot.php';
            require_once dirname(__DIR__).'/fresh_geo.php';
            $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $db->beginTransaction();
            try {
                $result = freshGeoPreview($db);
                $db->commit();
            } catch (Throwable $error) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $error;
            }
            break;
        case 'fresh_geo_submit':
            exactFields((array)$input,['action','device_id','request_id','scope_sha256']);
            require_once __DIR__.'/geo-pilot.php';
            require_once dirname(__DIR__).'/fresh_geo.php';
            if (!is_string($input->device_id) || !is_string($input->request_id) || !is_string($input->scope_sha256)) { reject(422,'invalid_geo_intent'); }
            $result=freshGeoSubmit($db,$input->device_id,$input->request_id,$input->scope_sha256);
            break;
        case 'production':
            exactFields((array)$input, ['action'], ['type','status']);
            $result=prodSnapshot($db,$input->type??null,$input->status??null);
            break;
        case 'production_submit':
            exactFields((array)$input, ['action','type'], ['source','request_id','requested_count','max_charge_cents']);
            if (!is_string($input->type) || (property_exists($input, 'source') && !is_string($input->source))
                || (property_exists($input, 'request_id') && !is_string($input->request_id))
                || (property_exists($input, 'requested_count') && !is_int($input->requested_count))
                || (property_exists($input, 'max_charge_cents') && !is_int($input->max_charge_cents))) {
                reject(422, 'invalid_command');
            }
            $result=prodSubmit($db,$input->type,$input->source??null,$input->request_id??null,
                $input->requested_count??null,$input->max_charge_cents??null);
            break;
        case 'production_control':
            exactFields((array)$input, ['action','paused']);
            $result=prodControl($db,$input->paused);
            break;
        case 'production_cancel':
            exactFields((array)$input, ['action','id']);
            $result=prodCancel($db,$input->id);
            break;
        default: reject(422,'invalid_action');
    }
    echo json_encode($result,JSON_THROW_ON_ERROR);
} catch (ApiError $e) {
    echo json_encode(['error'=>$e->getMessage(),'status'=>$e->getCode()]);
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR,"Farm dashboard unavailable\n"); exit(1);
}
