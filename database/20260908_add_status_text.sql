ALTER TABLE users
    ADD COLUMN status_text VARCHAR(40) NULL DEFAULT NULL AFTER spotify_track_id;
