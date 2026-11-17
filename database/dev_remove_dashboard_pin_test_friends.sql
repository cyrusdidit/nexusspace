-- Remove only the disabled test accounts created by dev_seed_dashboard_pin_test_friends.sql.
-- Their friendship and Top 8 rows are removed by the users foreign-key cascades.
DELETE FROM users
WHERE username REGEXP '^TempUser([1-9]|1[0-9]|20)$'
  AND email REGEXP '^tempuser([1-9]|1[0-9]|20)@nexusspace[.]invalid$'
  AND password_hash = '!disabled-test-account!';
