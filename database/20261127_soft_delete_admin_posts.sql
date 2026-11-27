SET @admin_removed_at_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'posts' AND column_name = 'admin_removed_at'),
    'SELECT 1',
    'ALTER TABLE posts ADD COLUMN admin_removed_at DATETIME NULL'
);
PREPARE admin_removed_at_migration FROM @admin_removed_at_sql;
EXECUTE admin_removed_at_migration;
DEALLOCATE PREPARE admin_removed_at_migration;

SET @admin_removed_by_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'posts' AND column_name = 'admin_removed_by'),
    'SELECT 1',
    'ALTER TABLE posts ADD COLUMN admin_removed_by INT UNSIGNED NULL'
);
PREPARE admin_removed_by_migration FROM @admin_removed_by_sql;
EXECUTE admin_removed_by_migration;
DEALLOCATE PREPARE admin_removed_by_migration;

SET @admin_removal_reason_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'posts' AND column_name = 'admin_removal_reason'),
    'SELECT 1',
    'ALTER TABLE posts ADD COLUMN admin_removal_reason VARCHAR(500) NULL'
);
PREPARE admin_removal_reason_migration FROM @admin_removal_reason_sql;
EXECUTE admin_removal_reason_migration;
DEALLOCATE PREPARE admin_removal_reason_migration;

SET @posts_admin_removed_index_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'posts' AND index_name = 'posts_admin_removed'),
    'SELECT 1',
    'ALTER TABLE posts ADD KEY posts_admin_removed (admin_removed_at, id)'
);
PREPARE posts_admin_removed_index_migration FROM @posts_admin_removed_index_sql;
EXECUTE posts_admin_removed_index_migration;
DEALLOCATE PREPARE posts_admin_removed_index_migration;

CREATE TABLE IF NOT EXISTS post_removal_appeals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    post_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    reason VARCHAR(2000) NOT NULL,
    status ENUM('pending', 'upheld', 'restored') NOT NULL DEFAULT 'pending',
    admin_response VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    reviewed_by INT UNSIGNED NULL,
    UNIQUE KEY post_removal_appeal_once (post_id),
    KEY post_removal_appeals_queue (status, created_at),
    CONSTRAINT fk_post_removal_appeal_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
    CONSTRAINT fk_post_removal_appeal_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
