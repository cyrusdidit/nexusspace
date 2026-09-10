<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Please log in.']);
    exit;
}
$userId = (int) $_SESSION['user_id'];
session_write_close();
require_once __DIR__ . '/includes/db_connect.php';
$statement = mysqli_prepare($conn, 'SELECT r.id, r.sender_id, r.created_at, r.status, u.username FROM friend_requests r JOIN users u ON u.id = r.sender_id WHERE r.receiver_id = ? ORDER BY r.created_at DESC, r.id DESC');
mysqli_stmt_bind_param($statement, 'i', $userId);
mysqli_stmt_execute($statement);
$requests = mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC);
mysqli_stmt_close($statement);
echo json_encode(['userId' => $userId, 'requests' => $requests]);
