-- Apply without --force. A rollback must preserve assignment and batch identities.
CREATE TEMPORARY TABLE rollback_no_orchestration (n BIGINT CHECK (n=0));
INSERT INTO rollback_no_orchestration SELECT COUNT(*) FROM device_tasks WHERE assignment_mode='any';
INSERT INTO rollback_no_orchestration SELECT COUNT(*) FROM collection_batches WHERE batch_id<>local_batch_id;
ALTER TABLE device_tasks DROP CHECK chk_farm_task_owner, DROP COLUMN assignment_mode,
    MODIFY device_id CHAR(36) CHARACTER SET ascii NOT NULL;
ALTER TABLE collection_batches DROP INDEX uq_farm_device_local_batch, DROP COLUMN local_batch_id;
ALTER TABLE device_agents DROP COLUMN manufacturer, DROP COLUMN model;
DROP TEMPORARY TABLE rollback_no_orchestration;
