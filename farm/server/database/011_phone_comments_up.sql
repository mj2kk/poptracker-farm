ALTER TABLE device_agents ADD archive_comments_seen_at DATETIME(6) NULL;
ALTER TABLE device_tasks
    MODIFY type ENUM('PING','GET_DEVICE_STATUS','EXECUTE_TEST_TASK','UI_TEST_SCENARIO','COLLECT_TEST_DATA','ARCHIVE_COMMENTS_V1') NOT NULL,
    ADD production_job_id CHAR(36) CHARACTER SET ascii NULL,
    ADD CONSTRAINT device_task_production_job FOREIGN KEY (production_job_id) REFERENCES production_jobs(id);
ALTER TABLE production_jobs
    ADD device_task_id CHAR(36) CHARACTER SET ascii NULL UNIQUE,
    ADD device_id CHAR(36) CHARACTER SET ascii NULL;
ALTER TABLE production_comment_context
    ADD production_job_id CHAR(36) CHARACTER SET ascii NULL,
    ADD device_task_id CHAR(36) CHARACTER SET ascii NULL,
    ADD device_id CHAR(36) CHARACTER SET ascii NULL;
