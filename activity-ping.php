<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/db_connect.php';

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$state = $_POST['state'] ?? '';
$allowedStates = ['online', 'idle', 'offline'];

if (!in_array($state, $allowedStates, true)) {
    http_response_code(422);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$isActive = ($_POST['active'] ?? '') === '1';
if ($isActive) {
    $statement = mysqli_prepare($conn, "UPDATE users SET activity_state = 'online', last_active_at = NOW() WHERE id = ?");
} else {
    $statement = mysqli_prepare($conn, "UPDATE users SET activity_state = CASE WHEN last_active_at IS NULL OR TIMESTAMPDIFF(SECOND, last_active_at, NOW()) >= 600 THEN 'offline' WHEN TIMESTAMPDIFF(SECOND, last_active_at, NOW()) >= 300 THEN 'idle' ELSE 'online' END WHERE id = ?");
}
mysqli_stmt_bind_param($statement, 'i', $userId);
mysqli_stmt_execute($statement);
mysqli_stmt_close($statement);

$statement = mysqli_prepare($conn, 'SELECT activity_state FROM users WHERE id = ? LIMIT 1');
mysqli_stmt_bind_param($statement, 'i', $userId);
mysqli_stmt_execute($statement);
$savedState = mysqli_fetch_assoc(mysqli_stmt_get_result($statement))['activity_state'] ?? 'offline';
mysqli_stmt_close($statement);

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'state' => $savedState]);
