SET @custom_status_audience_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'custom_status_audience'),
    'SELECT 1',
    'ALTER TABLE users ADD COLUMN custom_status_audience ENUM(''public'', ''friends'', ''none'') NOT NULL DEFAULT ''friends'' AFTER status_text'
);
PREPARE custom_status_audience_migration FROM @custom_status_audience_sql;
EXECUTE custom_status_audience_migration;
DEALLOCATE PREPARE custom_status_audience_migration;

SET @spotify_status_audience_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'spotify_status_audience'),
    'SELECT 1',
    'ALTER TABLE users ADD COLUMN spotify_status_audience ENUM(''public'', ''friends'', ''none'') NOT NULL DEFAULT ''friends'' AFTER custom_status_audience'
);
PREPARE spotify_status_audience_migration FROM @spotify_status_audience_sql;
EXECUTE spotify_status_audience_migration;
DEALLOCATE PREPARE spotify_status_audience_migration;

SET @steam_status_audience_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'steam_status_audience'),
    'SELECT 1',
    'ALTER TABLE users ADD COLUMN steam_status_audience ENUM(''public'', ''friends'', ''none'') NOT NULL DEFAULT ''friends'' AFTER spotify_status_audience'
);
PREPARE steam_status_audience_migration FROM @steam_status_audience_sql;
EXECUTE steam_status_audience_migration;
DEALLOCATE PREPARE steam_status_audience_migration;
