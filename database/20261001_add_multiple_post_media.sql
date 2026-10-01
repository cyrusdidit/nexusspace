CREATE TABLE IF NOT EXISTS post_media (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    post_id INT UNSIGNED NOT NULL,
    media_path VARCHAR(255) NOT NULL,
    media_type ENUM('image', 'video') NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_post_media_order (post_id, sort_order),
    CONSTRAINT fk_post_media_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO post_media (post_id, media_path, media_type, sort_order)
SELECT p.id, p.media_path, p.media_type, 0
FROM posts p
WHERE p.media_path IS NOT NULL
  AND p.media_type IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM post_media pm WHERE pm.post_id = p.id);
