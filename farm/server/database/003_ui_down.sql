-- Retain the wider ENUM while UI audit rows exist. Strict mode refuses lossy conversion.
SET SESSION sql_mode=CONCAT_WS(',',NULLIF(@@sql_mode,''),'STRICT_ALL_TABLES');
ALTER TABLE device_tasks MODIFY type ENUM('PING','GET_DEVICE_STATUS','EXECUTE_TEST_TASK') NOT NULL;
