ALTER TABLE device_tasks
    MODIFY device_id CHAR(36) CHARACTER SET ascii NULL,
    ADD assignment_mode ENUM('targeted','any') NOT NULL DEFAULT 'targeted',
    ADD CONSTRAINT chk_farm_task_owner CHECK
        (device_id IS NOT NULL OR (assignment_mode='any' AND status IN ('queued','cancelled')));

ALTER TABLE collection_batches
    ADD local_batch_id CHAR(36) CHARACTER SET ascii GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(raw_json,'$.batch_id'))) STORED,
    ADD UNIQUE KEY uq_farm_device_local_batch (device_id,local_batch_id);

ALTER TABLE device_agents
    ADD manufacturer VARCHAR(100) NULL,
    ADD model VARCHAR(100) NULL;

UPDATE device_agents d
JOIN (
    SELECT t.device_id,a.result_json,
           ROW_NUMBER() OVER (PARTITION BY t.device_id ORDER BY a.completed_at DESC,a.attempt_id DESC) AS position_no
    FROM device_tasks t JOIN device_task_attempts a USING (task_id)
    WHERE t.type='GET_DEVICE_STATUS' AND a.status='succeeded'
) s ON s.device_id=d.device_id AND s.position_no=1
SET d.manufacturer=JSON_UNQUOTE(JSON_EXTRACT(s.result_json,'$.manufacturer')),
    d.model=JSON_UNQUOTE(JSON_EXTRACT(s.result_json,'$.model'));
