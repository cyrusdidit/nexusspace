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
$statement = mysqli_prepare($conn, 'UPDATE users SET activity_state = ?, last_active_at = NOW() WHERE id = ?');
mysqli_stmt_bind_param($statement, 'si', $state, $userId);
mysqli_stmt_execute($statement);
mysqli_stmt_close($statement);

header('Content-Type: application/json');
echo json_encode(['ok' => true]);
