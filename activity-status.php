<?php

declare(strict_types=1);

session_start();
header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Please log in again.']);
    exit;
}

require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/activity.php';

$currentUserId = (int) $_SESSION['user_id'];
$userId = filter_var($_GET['user'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
if (!$userId) {
    http_response_code(422);
    echo json_encode(['error' => 'Choose a valid user.']);
    exit;
}

$statement = mysqli_prepare($conn, 'SELECT u.activity_state, u.last_active_at, TIMESTAMPDIFF(SECOND, u.last_active_at, NOW()) AS activity_age_seconds FROM users u WHERE u.id = ? AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_id = ? AND b.blocked_id = u.id) OR (b.blocker_id = u.id AND b.blocked_id = ?)) LIMIT 1');
mysqli_stmt_bind_param($statement, 'iii', $userId, $currentUserId, $currentUserId);
mysqli_stmt_execute($statement);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
mysqli_stmt_close($statement);

if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found.']);
    exit;
}

$state = resolvedActivityState($user['activity_state'] ?? null, $user['last_active_at'] ?? null, isset($user['activity_age_seconds']) ? (int) $user['activity_age_seconds'] : null);
echo json_encode([
    'state' => $state,
    'label' => activityStatusLabel($state, $user['last_active_at'] ?? null),
]);
