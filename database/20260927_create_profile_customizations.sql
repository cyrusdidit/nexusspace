CREATE TABLE profile_customizations (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    template_html MEDIUMTEXT NOT NULL,
    custom_css MEDIUMTEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_profile_customizations_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
