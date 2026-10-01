SET @profile_song_name_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'profile_song_name'),
    'SELECT 1',
    'ALTER TABLE users ADD COLUMN profile_song_name VARCHAR(255) DEFAULT NULL AFTER spotify_track_id'
);
PREPARE profile_song_name_migration FROM @profile_song_name_sql;
EXECUTE profile_song_name_migration;
DEALLOCATE PREPARE profile_song_name_migration;

SET @profile_song_artist_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'profile_song_artist'),
    'SELECT 1',
    'ALTER TABLE users ADD COLUMN profile_song_artist VARCHAR(500) DEFAULT NULL AFTER profile_song_name'
);
PREPARE profile_song_artist_migration FROM @profile_song_artist_sql;
EXECUTE profile_song_artist_migration;
DEALLOCATE PREPARE profile_song_artist_migration;

SET @profile_song_image_url_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'profile_song_image_url'),
    'SELECT 1',
    'ALTER TABLE users ADD COLUMN profile_song_image_url VARCHAR(500) DEFAULT NULL AFTER profile_song_artist'
);
PREPARE profile_song_image_url_migration FROM @profile_song_image_url_sql;
EXECUTE profile_song_image_url_migration;
DEALLOCATE PREPARE profile_song_image_url_migration;

SET @profile_song_url_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'profile_song_url'),
    'SELECT 1',
    'ALTER TABLE users ADD COLUMN profile_song_url VARCHAR(500) DEFAULT NULL AFTER profile_song_image_url'
);
PREPARE profile_song_url_migration FROM @profile_song_url_sql;
EXECUTE profile_song_url_migration;
DEALLOCATE PREPARE profile_song_url_migration;
