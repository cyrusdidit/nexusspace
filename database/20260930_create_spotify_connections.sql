CREATE TABLE IF NOT EXISTS spotify_connections (
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
);

CREATE TABLE IF NOT EXISTS spotify_oauth_requests (
    state CHAR(43) NOT NULL PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    code_verifier VARCHAR(128) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_spotify_oauth_expiry (expires_at),
    CONSTRAINT fk_spotify_oauth_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
