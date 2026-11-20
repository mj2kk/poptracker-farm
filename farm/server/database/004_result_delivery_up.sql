ALTER TABLE device_task_attempts
    ADD COLUMN deliver_until DATETIME NULL,
    ADD COLUMN reported_start_ms INT UNSIGNED NULL,
    ADD COLUMN reported_finish_ms INT UNSIGNED NULL;
