CREATE TABLE IF NOT EXISTS steam_connections (
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
    UNIQUE KEY uq_steam_connections_steam_id (steam_id),
    CONSTRAINT fk_steam_connections_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS steam_auth_requests (
    state CHAR(43) NOT NULL PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_steam_auth_expiry (expires_at),
    CONSTRAINT fk_steam_auth_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
