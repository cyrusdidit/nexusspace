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
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Use GET.']);
    exit;
}
$userId = (int) $_SESSION['user_id'];
session_write_close();
require_once __DIR__ . '/includes/db_connect.php';

// Use a single snapshot so counts and the incoming-message cursor agree.
mysqli_begin_transaction($conn, MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
try {
    $statement = mysqli_prepare($conn, 'SELECT COALESCE(MAX(id), 0) AS latest_id FROM messages WHERE receiver_id = ?');
    mysqli_stmt_bind_param($statement, 'i', $userId);
    mysqli_stmt_execute($statement);
    $latestId = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($statement))['latest_id'];
    mysqli_stmt_close($statement);

    $statement = mysqli_prepare($conn, 'SELECT u.id AS sender_id, u.username, u.avatar_path, COUNT(*) AS unread_count, MAX(m.id) AS latest_unread_id, (SELECT f.top_eight_position FROM friends f WHERE f.user_id = ? AND f.friend_id = u.id) AS top_eight_position FROM messages m JOIN users u ON u.id = m.sender_id WHERE m.receiver_id = ? AND m.is_read = 0 GROUP BY u.id, u.username, u.avatar_path ORDER BY latest_unread_id DESC');
    mysqli_stmt_bind_param($statement, 'ii', $userId, $userId);
    mysqli_stmt_execute($statement);
    $senders = mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC);
    mysqli_stmt_close($statement);
    mysqli_commit($conn);
} catch (Throwable $error) {
    mysqli_rollback($conn);
    throw $error;
}
$totalUnread = 0;
foreach ($senders as &$sender) {
    $sender['sender_id'] = (int) $sender['sender_id'];
    $sender['unread_count'] = (int) $sender['unread_count'];
    $sender['latest_unread_id'] = (int) $sender['latest_unread_id'];
    $sender['top_eight_position'] = $sender['top_eight_position'] === null ? null : (int) $sender['top_eight_position'];
    $totalUnread += $sender['unread_count'];
}
unset($sender);
echo json_encode(['userId' => $userId, 'latestIncomingId' => $latestId, 'totalUnread' => $totalUnread, 'senders' => $senders]);
