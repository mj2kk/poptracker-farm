CREATE TABLE IF NOT EXISTS enrollment_codes (
    device_id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
    display_name VARCHAR(80) NOT NULL,
    code_hash BINARY(32) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS device_agents (
    device_id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
    display_name VARCHAR(80) NOT NULL,
    token_hash BINARY(32) NOT NULL UNIQUE,
    agent_version VARCHAR(32) NOT NULL,
    android_version VARCHAR(32) NOT NULL,
    created_at DATETIME NOT NULL,
    last_seen_at DATETIME NULL,
    revoked_at DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS device_heartbeats (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id CHAR(36) CHARACTER SET ascii NOT NULL,
    received_at DATETIME NOT NULL,
    battery_percent TINYINT UNSIGNED NULL,
    INDEX (device_id, received_at),
    INDEX (received_at),
    FOREIGN KEY (device_id) REFERENCES device_agents(device_id)
) ENGINE=InnoDB;
