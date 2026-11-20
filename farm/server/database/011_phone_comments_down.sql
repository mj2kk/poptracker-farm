-- Run without --force. Keep provenance even after technical attempts have been pruned.
CREATE TEMPORARY TABLE rollback_no_phone_comments (n BIGINT CHECK (n=0));
INSERT INTO rollback_no_phone_comments SELECT COUNT(*) FROM device_tasks WHERE production_job_id IS NOT NULL;
INSERT INTO rollback_no_phone_comments SELECT COUNT(*) FROM production_jobs WHERE device_id IS NOT NULL;
INSERT INTO rollback_no_phone_comments SELECT COUNT(*) FROM production_comment_context WHERE device_task_id IS NOT NULL;
ALTER TABLE device_tasks MODIFY type ENUM('PING','GET_DEVICE_STATUS','EXECUTE_TEST_TASK','UI_TEST_SCENARIO','COLLECT_TEST_DATA') NOT NULL;
ALTER TABLE production_comment_context DROP COLUMN device_id, DROP COLUMN device_task_id, DROP COLUMN production_job_id;
ALTER TABLE production_jobs DROP COLUMN device_id, DROP COLUMN device_task_id;
ALTER TABLE device_tasks DROP FOREIGN KEY device_task_production_job, DROP COLUMN production_job_id;
ALTER TABLE device_agents DROP COLUMN archive_comments_seen_at;
DROP TEMPORARY TABLE rollback_no_phone_comments;
