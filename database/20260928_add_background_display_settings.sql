ALTER TABLE user_backgrounds
    ADD COLUMN image_fit ENUM('cover', 'contain', 'tile') NOT NULL DEFAULT 'cover' AFTER image_path,
    ADD COLUMN image_blur TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER image_fit;
