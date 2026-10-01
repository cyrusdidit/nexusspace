CREATE TABLE IF NOT EXISTS posts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    visibility ENUM('friends', 'public') NOT NULL DEFAULT 'friends',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX posts_feed (created_at, id),
    INDEX posts_author (user_id, created_at),
    CONSTRAINT fk_posts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @posts_visibility_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'posts' AND column_name = 'visibility'),
    'SELECT 1',
    'ALTER TABLE posts ADD COLUMN visibility ENUM(''friends'', ''public'') NOT NULL DEFAULT ''friends'' AFTER content'
);
PREPARE posts_visibility_migration FROM @posts_visibility_sql;
EXECUTE posts_visibility_migration;
DEALLOCATE PREPARE posts_visibility_migration;
