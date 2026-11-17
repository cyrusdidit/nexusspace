-- Development-only seed for testing dashboard friend pins.
-- The accounts cannot log in; the password_hash value is deliberately invalid.
-- Change this username if your local account has a different username.
SET @nexusspace_test_owner_username = 'neXdidIT';
SET @nexusspace_test_owner_id = (
    SELECT id FROM users WHERE username = @nexusspace_test_owner_username LIMIT 1
);

INSERT IGNORE INTO users (username, email, password_hash) VALUES
    ('TempUser1', 'tempuser1@nexusspace.invalid', '!disabled-test-account!'),
    ('TempUser2', 'tempuser2@nexusspace.invalid', '!disabled-test-account!'),
    ('TempUser3', 'tempuser3@nexusspace.invalid', '!disabled-test-account!'),
    ('TempUser4', 'tempuser4@nexusspace.invalid', '!disabled-test-account!'),
    ('TempUser5', 'tempuser5@nexusspace.invalid', '!disabled-test-account!'),
    ('TempUser6', 'tempuser6@nexusspace.invalid', '!disabled-test-account!'),
    ('TempUser7', 'tempuser7@nexusspace.invalid', '!disabled-test-account!'),
    ('TempUser8', 'tempuser8@nexusspace.invalid', '!disabled-test-account!'),
    ('TempUser9', 'tempuser9@nexusspace.invalid', '!disabled-test-account!'),
    ('TempUser10', 'tempuser10@nexusspace.invalid', '!disabled-test-account!'),
    ('TempUser11', 'tempuser11@nexusspace.invalid', '!disabled-test-account!'),
    ('TempUser12', 'tempuser12@nexusspace.invalid', '!disabled-test-account!'),
    ('TempUser13', 'tempuser13@nexusspace.invalid', '!disabled-test-account!'),
    ('TempUser14', 'tempuser14@nexusspace.invalid', '!disabled-test-account!'),
    ('TempUser15', 'tempuser15@nexusspace.invalid', '!disabled-test-account!'),
    ('TempUser16', 'tempuser16@nexusspace.invalid', '!disabled-test-account!'),
    ('TempUser17', 'tempuser17@nexusspace.invalid', '!disabled-test-account!'),
    ('TempUser18', 'tempuser18@nexusspace.invalid', '!disabled-test-account!'),
    ('TempUser19', 'tempuser19@nexusspace.invalid', '!disabled-test-account!'),
    ('TempUser20', 'tempuser20@nexusspace.invalid', '!disabled-test-account!');

-- First eight seed users start in Top 8; all twenty are normal dashboard friends.
INSERT IGNORE INTO friends (user_id, friend_id, top_eight_position)
SELECT @nexusspace_test_owner_id,
       u.id,
       CASE
           WHEN CAST(SUBSTRING(u.username, 9) AS UNSIGNED) BETWEEN 1 AND 8
               THEN CAST(SUBSTRING(u.username, 9) AS UNSIGNED)
           ELSE NULL
       END
FROM users u
WHERE @nexusspace_test_owner_id IS NOT NULL
  AND u.username REGEXP '^TempUser([1-9]|1[0-9]|20)$';

-- Keep friendship records reciprocal, matching the app's existing data model.
INSERT IGNORE INTO friends (user_id, friend_id)
SELECT u.id, @nexusspace_test_owner_id
FROM users u
WHERE @nexusspace_test_owner_id IS NOT NULL
  AND u.username REGEXP '^TempUser([1-9]|1[0-9]|20)$';

SELECT IF(
    @nexusspace_test_owner_id IS NULL,
    'Owner username was not found; update @nexusspace_test_owner_username and run again.',
    CONCAT(
        'Seeded ',
        (SELECT COUNT(*)
         FROM friends f
         JOIN users u ON u.id = f.friend_id
         WHERE f.user_id = @nexusspace_test_owner_id
           AND u.username REGEXP '^TempUser([1-9]|1[0-9]|20)$'),
        ' temporary friends for ',
        @nexusspace_test_owner_username,
        '.'
    )
) AS result;
