-- Run without --force. Keep reports and task provenance if any job exists.
CREATE TEMPORARY TABLE rollback_unused_fresh_geo (n BIGINT CHECK (n=0));
INSERT INTO rollback_unused_fresh_geo SELECT COUNT(*) FROM device_tasks WHERE type='FRESH_AUTHOR_GEO_V1';
ALTER TABLE device_tasks MODIFY type ENUM('PING','GET_DEVICE_STATUS','EXECUTE_TEST_TASK','UI_TEST_SCENARIO','COLLECT_TEST_DATA','ARCHIVE_COMMENTS_V1') NOT NULL;
ALTER TABLE device_agents DROP COLUMN fresh_geo_seen_at;
DROP TEMPORARY TABLE rollback_unused_fresh_geo;
