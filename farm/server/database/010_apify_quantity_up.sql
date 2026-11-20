ALTER TABLE production_apify_feed_runs
    ADD requested_count SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    ADD batch_number TINYINT UNSIGNED NOT NULL DEFAULT 1,
    ADD import_cursor SMALLINT UNSIGNED NOT NULL DEFAULT 0;

CREATE TABLE production_apify_feed_batches (
    job_id CHAR(36) CHARACTER SET ascii NOT NULL,
    batch_number TINYINT UNSIGNED NOT NULL,
    requested_count SMALLINT UNSIGNED NOT NULL,
    max_charge_usd DECIMAL(12,8) NOT NULL,
    run_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    dataset_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    source_sha CHAR(64) CHARACTER SET ascii NULL,
    provider_cost_usd DECIMAL(12,8) NULL,
    row_count SMALLINT UNSIGNED NULL,
    started_at DATETIME(6) NULL,
    finished_at DATETIME(6) NULL,
    PRIMARY KEY (job_id,batch_number),
    CONSTRAINT apify_quantity_job FOREIGN KEY (job_id) REFERENCES production_apify_feed_runs(job_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO production_apify_feed_batches
    (job_id,batch_number,requested_count,max_charge_usd,run_id,dataset_id,source_sha,provider_cost_usd,row_count,started_at,finished_at)
SELECT job_id,1,100,max_charge_usd,run_id,dataset_id,source_sha,provider_cost_usd,JSON_LENGTH(projected_rows),started_at,finished_at
FROM production_apify_feed_runs WHERE run_id IS NOT NULL AND dataset_id IS NOT NULL;
