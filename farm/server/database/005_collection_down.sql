-- Stop on the first error. Refuse to remove a live collection history.
CREATE TEMPORARY TABLE rollback_no_collections (n BIGINT CHECK (n = 0));
INSERT INTO rollback_no_collections SELECT COUNT(*) FROM device_tasks WHERE type='COLLECT_TEST_DATA';
ALTER TABLE device_tasks MODIFY type ENUM('PING','GET_DEVICE_STATUS','EXECUTE_TEST_TASK','UI_TEST_SCENARIO') NOT NULL;
DROP TABLE collection_records, collection_batches;
DROP TEMPORARY TABLE rollback_no_collections;
