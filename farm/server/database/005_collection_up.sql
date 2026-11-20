CREATE TABLE collection_batches (
    batch_id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
    task_id CHAR(36) CHARACTER SET ascii NOT NULL UNIQUE,
    attempt_id CHAR(36) CHARACTER SET ascii NOT NULL UNIQUE,
    device_id CHAR(36) CHARACTER SET ascii NOT NULL,
    dataset VARCHAR(32) CHARACTER SET ascii NOT NULL,
    schema_version SMALLINT UNSIGNED NOT NULL,
    record_count SMALLINT UNSIGNED NOT NULL CHECK (record_count BETWEEN 10 AND 100),
    created_at DATETIME NOT NULL,
    received_at DATETIME(6) NOT NULL,
    validation_status ENUM('valid') NOT NULL,
    processing_status ENUM('stored') NOT NULL,
    raw_json JSON NOT NULL,
    INDEX (device_id, received_at),
    FOREIGN KEY (attempt_id) REFERENCES device_task_attempts(attempt_id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES device_tasks(task_id) ON DELETE CASCADE,
    FOREIGN KEY (device_id) REFERENCES device_agents(device_id)
) ENGINE=InnoDB;

CREATE TABLE collection_records (
    batch_id CHAR(36) CHARACTER SET ascii NOT NULL,
    external_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    author_id VARCHAR(64) CHARACTER SET ascii NOT NULL,
    published_at DATETIME NOT NULL,
    description VARCHAR(512) NOT NULL,
    views INT UNSIGNED NOT NULL,
    likes INT UNSIGNED NOT NULL,
    comments INT UNSIGNED NOT NULL,
    shares INT UNSIGNED NOT NULL,
    collected_at DATETIME NOT NULL,
    PRIMARY KEY (batch_id, external_id),
    FOREIGN KEY (batch_id) REFERENCES collection_batches(batch_id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE device_tasks MODIFY type ENUM('PING','GET_DEVICE_STATUS','EXECUTE_TEST_TASK','UI_TEST_SCENARIO','COLLECT_TEST_DATA') NOT NULL;
