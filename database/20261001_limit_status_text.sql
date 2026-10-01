UPDATE users
SET status_text = LEFT(status_text, 40)
WHERE CHAR_LENGTH(status_text) > 40;

ALTER TABLE users
    MODIFY COLUMN status_text VARCHAR(40) NULL DEFAULT NULL;
