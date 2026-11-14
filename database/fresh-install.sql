-- NexusSpace: complete schema for a NEW, EMPTY database.
-- Includes migrations through 20261112. Do not run those migrations again.
-- No DROP statements; existing tables cause an error rather than being replaced.
CREATE DATABASE IF NOT EXISTS nexusspace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE nexusspace;
SET NAMES utf8mb4;

CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(35) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    registration_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    avatar_path VARCHAR(255) NULL,
    bio TEXT NULL,
    profile_background_color CHAR(7) NOT NULL DEFAULT '#ffffff',
    profile_text_color CHAR(7) NOT NULL DEFAULT '#163b5c',
    profile_font_size INT UNSIGNED NOT NULL DEFAULT 16,
    spotify_track_id VARCHAR(128) NULL,
    profile_song_name VARCHAR(255) NULL,
    profile_song_artist VARCHAR(500) NULL,
    profile_song_image_url VARCHAR(500) NULL,
    profile_song_url VARCHAR(500) NULL,
    status_text VARCHAR(40) NULL,
    custom_status_audience ENUM('public','friends','none') NOT NULL DEFAULT 'friends',
    spotify_status_audience ENUM('public','friends','none') NOT NULL DEFAULT 'friends',
    steam_status_audience ENUM('public','friends','none') NOT NULL DEFAULT 'friends',
    activity_state ENUM('online','idle','offline') NOT NULL DEFAULT 'offline',
    last_active_at DATETIME NULL,
    UNIQUE KEY users_username (username),
    UNIQUE KEY users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE friends (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    friend_id INT UNSIGNED NOT NULL,
    top_eight_position TINYINT UNSIGNED NULL,
    nickname VARCHAR(50) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY friends_pair (user_id, friend_id),
    KEY friends_other_user (friend_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (friend_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE friend_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sender_id INT UNSIGNED NOT NULL,
    receiver_id INT UNSIGNED NOT NULL,
    status ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY friend_requests_pair (sender_id, receiver_id),
    KEY friend_requests_inbox (receiver_id, status, created_at),
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sender_id INT UNSIGNED NOT NULL,
    receiver_id INT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    edited_at DATETIME(6) NULL,
    deleted_at DATETIME(6) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    KEY messages_conversation (sender_id, receiver_id, id),
    KEY messages_unread (receiver_id, is_read, sender_id),
    KEY messages_changes (sender_id, receiver_id, updated_at),
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_typing (
    sender_id INT UNSIGNED NOT NULL,
    receiver_id INT UNSIGNED NOT NULL,
    client_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (sender_id, receiver_id, client_key),
    KEY message_typing_receiver (receiver_id, updated_at),
    KEY message_typing_expiry (updated_at),
    CONSTRAINT fk_message_typing_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_message_typing_receiver FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE posts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(100) NOT NULL DEFAULT '',
    content TEXT NOT NULL,
    media_path VARCHAR(255) NULL,
    media_type ENUM('image','video') NULL,
    visibility ENUM('friends', 'public') NOT NULL DEFAULT 'friends',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    edited_at DATETIME NULL,
    pinned_at DATETIME(6) NULL,
    INDEX posts_feed (created_at, id),
    INDEX posts_author (user_id, created_at),
    INDEX posts_profile_pins (user_id, pinned_at, created_at, id),
    CONSTRAINT fk_posts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE profile_customizations (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 2,
    simple_settings JSON NULL,
    sidebar_width SMALLINT UNSIGNED NOT NULL DEFAULT 280,
    published_revision INT UNSIGNED NOT NULL DEFAULT 0,
    template_html MEDIUMTEXT NOT NULL,
    custom_css MEDIUMTEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_profile_customizations_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_backgrounds (
    user_id INT UNSIGNED NOT NULL,
    region ENUM('messages', 'profile_cover', 'profile_posts', 'dashboard_feed') NOT NULL,
    background_type ENUM('color', 'image', 'linked') NOT NULL DEFAULT 'color',
    color_value CHAR(7) DEFAULT NULL,
    image_path VARCHAR(255) DEFAULT NULL,
    image_fit ENUM('cover','contain','tile') NOT NULL DEFAULT 'cover',
    image_blur TINYINT UNSIGNED NOT NULL DEFAULT 0,
    image_position_x DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    image_position_y DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    image_zoom DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    linked_region ENUM('messages', 'profile_cover', 'profile_posts', 'dashboard_feed') DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, region),
    CONSTRAINT fk_user_backgrounds_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_blocks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    blocker_id INT UNSIGNED NOT NULL,
    blocked_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_user_block (blocker_id, blocked_id),
    KEY fk_user_blocks_blocked (blocked_id),
    CONSTRAINT fk_user_blocks_blocker FOREIGN KEY (blocker_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_blocks_blocked FOREIGN KEY (blocked_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_user_blocks_distinct CHECK (blocker_id <> blocked_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE spotify_connections (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    spotify_user_id VARCHAR(64) NOT NULL,
    display_name VARCHAR(255) DEFAULT NULL,
    access_token TEXT NOT NULL,
    refresh_token TEXT NOT NULL,
    token_expires_at DATETIME NOT NULL,
    scopes VARCHAR(512) NOT NULL DEFAULT '',
    current_item_type ENUM('track', 'episode') DEFAULT NULL,
    current_item_id VARCHAR(128) DEFAULT NULL,
    current_item_name VARCHAR(255) DEFAULT NULL,
    current_artist_name VARCHAR(255) DEFAULT NULL,
    current_image_url VARCHAR(500) DEFAULT NULL,
    current_external_url VARCHAR(500) DEFAULT NULL,
    is_playing TINYINT(1) NOT NULL DEFAULT 0,
    playback_updated_at DATETIME DEFAULT NULL,
    connected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_spotify_connections_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE spotify_oauth_requests (
    state CHAR(43) NOT NULL PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    code_verifier VARCHAR(128) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_spotify_oauth_expiry (expires_at),
    CONSTRAINT fk_spotify_oauth_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE steam_connections (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    steam_id CHAR(17) NOT NULL,
    persona_name VARCHAR(255) NOT NULL,
    profile_url VARCHAR(500) NOT NULL,
    avatar_url VARCHAR(500) DEFAULT NULL,
    current_game_id VARCHAR(32) DEFAULT NULL,
    current_game_name VARCHAR(255) DEFAULT NULL,
    current_game_started_at DATETIME DEFAULT NULL,
    activity_updated_at DATETIME DEFAULT NULL,
    connected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_steam_connections_steam_id (steam_id),
    CONSTRAINT fk_steam_connections_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE steam_auth_requests (
    state CHAR(43) NOT NULL PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_steam_auth_expiry (expires_at),
    CONSTRAINT fk_steam_auth_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE post_media (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    post_id INT UNSIGNED NOT NULL,
    media_path VARCHAR(255) NOT NULL,
    media_type ENUM('image', 'video') NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_post_media_order (post_id, sort_order),
    CONSTRAINT fk_post_media_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE post_comments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    post_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    content VARCHAR(1000) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    edited_at DATETIME NULL,
    deleted_at DATETIME NULL,
    pinned_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    KEY post_comments_post (post_id, created_at),
    KEY post_comments_parent (parent_id),
    KEY post_comments_user (user_id),
    KEY post_comments_pinned (post_id, pinned_at),
    CONSTRAINT fk_post_comments_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
    CONSTRAINT fk_post_comments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_post_comments_parent FOREIGN KEY (parent_id) REFERENCES post_comments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipient_id INT UNSIGNED NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    type VARCHAR(32) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    post_id INT UNSIGNED NULL,
    dedupe_key VARCHAR(191) NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY notifications_dedupe (recipient_id, dedupe_key),
    KEY notifications_recipient (recipient_id, is_read, created_at),
    KEY notifications_actor (actor_id),
    KEY notifications_post (post_id),
    CONSTRAINT fk_notifications_recipient FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE profile_customization_drafts (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 2,
    simple_settings JSON NOT NULL,
    sidebar_width SMALLINT UNSIGNED NOT NULL DEFAULT 280,
    template_html MEDIUMTEXT NOT NULL,
    custom_css MEDIUMTEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_profile_customization_drafts_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE profile_customization_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    revision_number INT UNSIGNED NOT NULL,
    schema_version SMALLINT UNSIGNED NOT NULL,
    simple_settings JSON NOT NULL,
    sidebar_width SMALLINT UNSIGNED NOT NULL,
    template_html MEDIUMTEXT NOT NULL,
    custom_css MEDIUMTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY profile_customization_revision (user_id, revision_number),
    KEY profile_customization_revision_created (user_id, created_at),
    CONSTRAINT fk_profile_customization_revisions_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE security_rate_limits (
    scope VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_started_at DATETIME NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (scope, subject_hash),
    KEY security_rate_limits_window (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
