<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Log in again to continue.']);
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!is_string($token) || !isset($_SESSION['status_visibility_csrf']) || !hash_equals($_SESSION['status_visibility_csrf'], $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Your session changed. Refresh the page and try again.']);
    exit;
}

$type = is_string($_POST['type'] ?? null) ? $_POST['type'] : '';
$audience = is_string($_POST['audience'] ?? null) ? $_POST['audience'] : '';
$columns = [
    'custom' => 'custom_status_audience',
    'spotify' => 'spotify_status_audience',
    'steam' => 'steam_status_audience',
];
if (!isset($columns[$type]) || !in_array($audience, ['friends', 'none'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Choose a valid status visibility.']);
    exit;
}

require_once __DIR__ . '/includes/db_connect.php';
$statement = mysqli_prepare($conn, 'UPDATE users SET ' . $columns[$type] . ' = ? WHERE id = ?');
$userId = (int) $_SESSION['user_id'];
mysqli_stmt_bind_param($statement, 'si', $audience, $userId);
mysqli_stmt_execute($statement);
mysqli_stmt_close($statement);

echo json_encode(['ok' => true, 'type' => $type, 'audience' => $audience]);
