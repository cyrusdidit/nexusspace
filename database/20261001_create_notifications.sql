CREATE TABLE IF NOT EXISTS notifications (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO notifications (recipient_id, actor_id, type, entity_id, dedupe_key, created_at)
SELECT receiver_id, sender_id, 'friend_request', id, CONCAT('friend_request:', id), created_at
FROM friend_requests WHERE status = 'pending' AND sender_id <> receiver_id;

INSERT IGNORE INTO notifications (recipient_id, actor_id, type, entity_id, dedupe_key, created_at)
SELECT receiver_id, sender_id, 'message', id, CONCAT('message:', id), created_at
FROM messages WHERE is_read = 0 AND deleted_at IS NULL AND sender_id <> receiver_id;

INSERT IGNORE INTO notifications (recipient_id, actor_id, type, entity_id, post_id, dedupe_key, created_at)
SELECT p.user_id, l.user_id, 'post_like', p.id, p.id, CONCAT('post_like:', p.id, ':', l.user_id), l.created_at
FROM post_likes l JOIN posts p ON p.id = l.post_id WHERE p.user_id <> l.user_id;

INSERT IGNORE INTO notifications (recipient_id, actor_id, type, entity_id, post_id, dedupe_key, created_at)
SELECT p.user_id, c.user_id, 'post_comment', c.id, c.post_id, CONCAT('post_comment:', c.id), c.created_at
FROM post_comments c JOIN posts p ON p.id = c.post_id
WHERE c.parent_id IS NULL AND c.deleted_at IS NULL AND p.user_id <> c.user_id;

INSERT IGNORE INTO notifications (recipient_id, actor_id, type, entity_id, post_id, dedupe_key, created_at)
SELECT parent.user_id, c.user_id, 'comment_reply', c.id, c.post_id, CONCAT('comment_reply:', c.id), c.created_at
FROM post_comments c JOIN post_comments parent ON parent.id = c.parent_id
WHERE c.deleted_at IS NULL AND parent.user_id <> c.user_id;

INSERT IGNORE INTO notifications (recipient_id, actor_id, type, entity_id, post_id, dedupe_key, created_at)
SELECT c.user_id, p.user_id, 'comment_pin', c.id, c.post_id, CONCAT('comment_pin:', c.id), c.pinned_at
FROM post_comments c JOIN posts p ON p.id = c.post_id
WHERE c.pinned_at IS NOT NULL AND c.deleted_at IS NULL AND c.user_id <> p.user_id;
