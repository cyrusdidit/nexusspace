CREATE TABLE IF NOT EXISTS user_backgrounds (
    user_id INT UNSIGNED NOT NULL,
    region ENUM('messages', 'profile_cover', 'profile_posts', 'dashboard_feed') NOT NULL,
    background_type ENUM('color', 'image', 'linked') NOT NULL DEFAULT 'color',
    color_value CHAR(7) DEFAULT NULL,
    image_path VARCHAR(255) DEFAULT NULL,
    linked_region ENUM('messages', 'profile_cover', 'profile_posts', 'dashboard_feed') DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, region),
    CONSTRAINT fk_user_backgrounds_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
