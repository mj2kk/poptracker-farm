-- Removes only task history. Restore backups if that history is required.
DROP TABLE IF EXISTS device_task_attempts;
DROP TABLE IF EXISTS device_tasks;
