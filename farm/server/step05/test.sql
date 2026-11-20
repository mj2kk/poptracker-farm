-- Apply only after the Farm 001, 002 and 004 tables in the dedicated test schema.
ALTER TABLE device_tasks
    MODIFY type ENUM('GET_TEST_REGION_V1','TEST_AUTHOR_GEO_V1') NOT NULL,
    ADD assignment_mode ENUM('targeted') NOT NULL DEFAULT 'targeted';
ALTER TABLE device_agents
    ADD screen_on BOOLEAN NULL, ADD screen_reported_at DATETIME NULL;
