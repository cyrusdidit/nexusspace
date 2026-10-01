SET @post_title_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'posts' AND column_name = 'title'),
    'SELECT 1',
    'ALTER TABLE posts ADD COLUMN title VARCHAR(100) NOT NULL DEFAULT '''' AFTER user_id'
);
PREPARE post_title_migration FROM @post_title_sql;
EXECUTE post_title_migration;
DEALLOCATE PREPARE post_title_migration;
