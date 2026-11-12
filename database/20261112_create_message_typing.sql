CREATE TABLE IF NOT EXISTS message_typing (
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
