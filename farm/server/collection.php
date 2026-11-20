<?php
declare(strict_types=1);

const COLLECTION_TYPE = 'COLLECT_TEST_DATA';
const COLLECTION_DATASET = 'synthetic_videos';
const COLLECTION_MAX_BYTES = 131072;

function validateCollectionParameters(object $payload): void {
    exactFields((array)$payload, ['dataset','record_count']);
    if ($payload->dataset !== COLLECTION_DATASET || !integerBetween($payload->record_count,10,100)) {
        reject(422,'invalid_collection_parameters');
    }
}

function storeCollection(PDO $db, object $batch, object $payload, string $device, string $task, string $attempt): array {
    exactFields((array)$batch,['task_id','attempt_id','device_id','batch_id','schema_version','records','created_at']);
    foreach (['task_id','attempt_id','device_id','batch_id'] as $key) {
        if (!isUuid($batch->$key)) { reject(422,'invalid_collection_id'); }
    }
    if ($batch->device_id !== $device || $batch->task_id !== $task || $batch->attempt_id !== $attempt) {
        reject(403,'collection_owner_mismatch');
    }
    if ($batch->schema_version !== 1 || !taskTimestamp($batch->created_at) || !is_array($batch->records)
        || count($batch->records) !== $payload->record_count) { reject(422,'invalid_batch'); }

    $seen = [];
    foreach ($batch->records as $record) {
        if (!$record instanceof stdClass) { reject(422,'invalid_record'); }
        exactFields((array)$record,['external_id','author_id','published_at','description','views','likes','comments','shares','collected_at']);
        if (!is_string($record->external_id) || !preg_match('/\Atest_video_[0-9]{3}\z/',$record->external_id)
            || !is_string($record->author_id) || !preg_match('/\Atest_author_[0-9]{3}\z/',$record->author_id)
            || !taskTimestamp($record->published_at) || !taskTimestamp($record->collected_at)
            || !validText($record->description,512)) { reject(422,'invalid_record'); }
        foreach (['views','likes','comments','shares'] as $key) {
            if (!integerBetween($record->$key,0,4294967295)) { reject(422,'invalid_counter'); }
        }
        if (isset($seen[$record->external_id])) { reject(422,'duplicate_external_id'); }
        $seen[$record->external_id] = true;
    }
    $sqlDate = static fn(string $s): string => str_replace(['T','Z'],[' ',''],$s);
    $store = $db->prepare("INSERT INTO collection_batches
        (batch_id,task_id,attempt_id,device_id,dataset,schema_version,record_count,created_at,received_at,validation_status,processing_status,raw_json)
        VALUES (?,?,?,?,?,1,?,?,UTC_TIMESTAMP(6),'valid','stored',?)");
    $values = [$task,$attempt,$device,$payload->dataset,count($seen),$sqlDate($batch->created_at),
        json_encode($batch,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
    $storageId = $batch->batch_id;
    try {
        $store->execute([$storageId,...$values]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) !== 1062) { throw $e; }
        // Preserve the ordinary storage ID. Only allocate a separate key on a cross-package collision.
        $occupied = query($db,'SELECT device_id,local_batch_id FROM collection_batches WHERE batch_id=?',[$storageId])->fetch();
        if (!$occupied || ($occupied['device_id']===$device && $occupied['local_batch_id']===$batch->batch_id)) {
            reject(409,'batch_conflict');
        }
        $storageId = deviceId();
        try {
            $store->execute([$storageId,...$values]);
        } catch (PDOException $collision) {
            if (($collision->errorInfo[1] ?? null)===1062) { reject(409,'batch_conflict'); }
            throw $collision;
        }
    }
    $insert = $db->prepare('INSERT INTO collection_records
        (batch_id,external_id,author_id,published_at,description,views,likes,comments,shares,collected_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach ($batch->records as $r) {
        $insert->execute([$storageId,$r->external_id,$r->author_id,$sqlDate($r->published_at),$r->description,
            $r->views,$r->likes,$r->comments,$r->shares,$sqlDate($r->collected_at)]);
    }
    return ['batch_id'=>$batch->batch_id,'record_count'=>count($seen),'status'=>'stored'];
}
