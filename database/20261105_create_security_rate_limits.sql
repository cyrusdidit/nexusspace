CREATE TABLE IF NOT EXISTS security_rate_limits (
    scope VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_started_at DATETIME NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (scope, subject_hash),
    KEY security_rate_limits_window (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
