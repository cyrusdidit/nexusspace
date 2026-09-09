ALTER TABLE users
    ADD COLUMN activity_state ENUM('online', 'idle', 'offline') NOT NULL DEFAULT 'offline' AFTER status_text,
    ADD COLUMN last_active_at DATETIME NULL DEFAULT NULL AFTER activity_state;
