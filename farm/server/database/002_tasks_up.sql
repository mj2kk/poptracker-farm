CREATE TABLE IF NOT EXISTS device_tasks (
    task_id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
    device_id CHAR(36) CHARACTER SET ascii NOT NULL,
    type ENUM('PING','GET_DEVICE_STATUS','EXECUTE_TEST_TASK') NOT NULL,
    schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    payload_json JSON NOT NULL,
    status ENUM('queued','leased','succeeded','failed','cancelled') NOT NULL DEFAULT 'queued',
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 1 CHECK (max_attempts = 1),
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    INDEX (device_id,status,created_at,task_id),
    INDEX (status,updated_at),
    FOREIGN KEY (device_id) REFERENCES device_agents(device_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS device_task_attempts (
    attempt_id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
    task_id CHAR(36) CHARACTER SET ascii NOT NULL,
    attempt_no TINYINT UNSIGNED NOT NULL DEFAULT 1,
    lease_token_hash BINARY(32) NOT NULL,
    leased_at DATETIME NOT NULL,
    lease_expires_at DATETIME NOT NULL,
    status ENUM('leased','succeeded','failed','expired') NOT NULL,
    completed_at DATETIME NULL,
    agent_completed_at DATETIME NULL,
    result_json JSON NULL,
    error_code VARCHAR(40) NULL,
    error_message VARCHAR(200) NULL,
    result_digest BINARY(32) NULL,
    replay_count INT UNSIGNED NOT NULL DEFAULT 0,
    late_results INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE (task_id,attempt_no),
    INDEX (status,lease_expires_at),
    FOREIGN KEY (task_id) REFERENCES device_tasks(task_id) ON DELETE CASCADE
) ENGINE=InnoDB;
