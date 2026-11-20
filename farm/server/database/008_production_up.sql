CREATE TABLE production_state (
    id TINYINT UNSIGNED PRIMARY KEY,
    paused BOOLEAN NOT NULL DEFAULT FALSE,
    catalog_summary JSON NULL,
    summary_at DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
INSERT INTO production_state (id) VALUES (1);

CREATE TABLE production_jobs (
    id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
    type VARCHAR(32) CHARACTER SET ascii NOT NULL,
    target VARCHAR(96) CHARACTER SET ascii NOT NULL,
    revision VARCHAR(160) NOT NULL,
    identity_key CHAR(64) CHARACTER SET ascii NOT NULL,
    status ENUM('pending','running','completed','failed','blocked','cancelled') NOT NULL DEFAULT 'pending',
    retained_key CHAR(64) CHARACTER SET ascii GENERATED ALWAYS AS
        (CASE WHEN status IN ('pending','running','completed','blocked') THEN identity_key ELSE NULL END) STORED,
    active_target VARCHAR(132) CHARACTER SET ascii GENERATED ALWAYS AS
        (CASE WHEN status IN ('pending','running') THEN CONCAT(type,'/',target) ELSE NULL END) STORED,
    priority SMALLINT NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    cursor_position INT UNSIGNED NOT NULL DEFAULT 0,
    total INT UNSIGNED NULL,
    written INT UNSIGNED NOT NULL DEFAULT 0,
    rejected INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    started_at DATETIME(6) NULL,
    finished_at DATETIME(6) NULL,
    next_run_at DATETIME(6) NOT NULL,
    result VARCHAR(512) NULL,
    error VARCHAR(512) NULL,
    UNIQUE KEY production_retained_work(retained_key),
    UNIQUE KEY production_one_active_target(active_target),
    KEY production_claim(status,next_run_at,priority),
    KEY production_history(type,created_at)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE production_feed_candidates (
    author_uid VARCHAR(32) PRIMARY KEY,
    username VARCHAR(80) NULL,
    sample_video_id VARCHAR(32) NOT NULL,
    geo_status VARCHAR(16) NOT NULL DEFAULT 'pending',
    discovered_at DATETIME(6) NOT NULL,
    KEY candidates_queue(geo_status,discovered_at)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE production_feed_videos (
    video_id VARCHAR(32) PRIMARY KEY,
    author_uid VARCHAR(32) NOT NULL,
    catalog_author_id VARCHAR(32) NULL,
    published_at DATETIME(6) NULL,
    metadata JSON NOT NULL,
    discovered_at DATETIME(6) NOT NULL,
    KEY feed_author(author_uid)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE production_feed_items (
    dataset_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `row_number` INT UNSIGNED NOT NULL,
    run_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_sha CHAR(64) NOT NULL,
    video_id VARCHAR(32) NULL,
    received_at DATETIME(6) NOT NULL,
    error_code VARCHAR(48) NULL,
    PRIMARY KEY(dataset_id,`row_number`), KEY item_video(video_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE production_geo_history (
    author_uid VARCHAR(32) PRIMARY KEY,
    status VARCHAR(16) NOT NULL,
    region CHAR(2) NULL,
    proof_sha CHAR(64) NOT NULL,
    video_id VARCHAR(32) NULL
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE production_evaluations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id CHAR(36) CHARACTER SET ascii NOT NULL UNIQUE,
    video_id VARCHAR(32) NOT NULL,
    observation_version BIGINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL,
    attention_status VARCHAR(16) NULL,
    collect_comments BOOLEAN NOT NULL,
    input_sha256 CHAR(64) NOT NULL,
    rules_version VARCHAR(128) NOT NULL,
    model_version VARCHAR(200) NULL,
    evaluated_at DATETIME(6) NOT NULL,
    decision JSON NOT NULL,
    KEY video_latest(video_id,id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE production_comment_context (
    video_id VARCHAR(32) NOT NULL,
    comment_id VARCHAR(32) NOT NULL,
    evaluation_id BIGINT UNSIGNED NOT NULL,
    source VARCHAR(100) NOT NULL,
    source_run_id VARCHAR(128) NOT NULL,
    commenter_id VARCHAR(32) NULL,
    body TEXT NOT NULL,
    published_at DATETIME(6) NULL,
    like_count BIGINT UNSIGNED NULL,
    parent_comment_id VARCHAR(32) NULL,
    observed_at DATETIME(6) NOT NULL,
    PRIMARY KEY(video_id,comment_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
