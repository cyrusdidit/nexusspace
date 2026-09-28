ALTER TABLE user_backgrounds
    ADD COLUMN image_position_x DECIMAL(5,2) NOT NULL DEFAULT 50.00 AFTER image_blur,
    ADD COLUMN image_position_y DECIMAL(5,2) NOT NULL DEFAULT 50.00 AFTER image_position_x,
    ADD COLUMN image_zoom DECIMAL(4,2) NOT NULL DEFAULT 1.00 AFTER image_position_y;
