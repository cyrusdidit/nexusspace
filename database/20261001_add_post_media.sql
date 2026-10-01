SET @post_media_path_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'posts' AND column_name = 'media_path'),
    'SELECT 1',
    'ALTER TABLE posts ADD COLUMN media_path VARCHAR(255) NULL AFTER content'
);
PREPARE post_media_path_migration FROM @post_media_path_sql;
EXECUTE post_media_path_migration;
DEALLOCATE PREPARE post_media_path_migration;

SET @post_media_type_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'posts' AND column_name = 'media_type'),
    'SELECT 1',
    'ALTER TABLE posts ADD COLUMN media_type ENUM(''image'', ''video'') NULL AFTER media_path'
);
PREPARE post_media_type_migration FROM @post_media_type_sql;
EXECUTE post_media_type_migration;
DEALLOCATE PREPARE post_media_type_migration;
