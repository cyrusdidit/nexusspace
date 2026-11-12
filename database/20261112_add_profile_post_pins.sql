ALTER TABLE posts
    ADD COLUMN pinned_at DATETIME(6) NULL AFTER edited_at,
    ADD INDEX posts_profile_pins (user_id, pinned_at, created_at, id);
