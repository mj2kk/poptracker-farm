CREATE TABLE production_apify_feed_runs (
    job_id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
    request_id CHAR(36) CHARACTER SET ascii NOT NULL UNIQUE,
    actor_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    build_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    requested_input JSON NOT NULL,
    max_charge_usd DECIMAL(12,8) NOT NULL,
    phase ENUM('queued','starting','polling','importing','done','failed','uncertain') NOT NULL DEFAULT 'queued',
    provider_slot TINYINT GENERATED ALWAYS AS (
        CASE WHEN phase IN ('starting','polling','importing','uncertain') THEN 1 ELSE NULL END
    ) STORED,
    owner_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    run_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
    dataset_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
    start_intent_at DATETIME(6) NULL,
    poll_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    network_errors TINYINT UNSIGNED NOT NULL DEFAULT 0,
    terminal_fingerprint CHAR(64) CHARACTER SET ascii NULL,
    source_sha CHAR(64) CHARACTER SET ascii NULL,
    provider_cost_usd DECIMAL(12,8) NULL,
    started_at DATETIME(6) NULL,
    finished_at DATETIME(6) NULL,
    received_at DATETIME(6) NULL,
    projected_rows JSON NULL,
    new_candidates SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    duplicate_videos SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY apify_one_unsettled_run (provider_slot),
    CONSTRAINT apify_feed_job FOREIGN KEY (job_id) REFERENCES production_jobs(id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
