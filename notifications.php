<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Please log in.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
require_once __DIR__ . '/includes/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !isset($_SESSION['notifications_csrf']) || !hash_equals($_SESSION['notifications_csrf'], $token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Refresh the page and try again.']);
        exit;
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'read_all') {
        $statement = mysqli_prepare($conn, 'UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE recipient_id = ? AND is_read = 0');
        mysqli_stmt_bind_param($statement, 'i', $userId);
    } elseif ($action === 'read') {
        $notificationId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$notificationId) { http_response_code(422); echo json_encode(['ok' => false, 'error' => 'Invalid notification.']); exit; }
        $statement = mysqli_prepare($conn, 'UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE id = ? AND recipient_id = ?');
        mysqli_stmt_bind_param($statement, 'ii', $notificationId, $userId);
    } else {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid notification action.']);
        exit;
    }
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);
    echo json_encode(['ok' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'Method not allowed.']); exit; }
$statement = mysqli_prepare($conn, "SELECT n.id, n.type, n.entity_id, n.post_id, n.is_read, n.created_at, n.actor_id, u.username FROM notifications n JOIN users u ON u.id = n.actor_id WHERE n.recipient_id = ? AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_id = ? AND b.blocked_id = n.actor_id) OR (b.blocker_id = n.actor_id AND b.blocked_id = ?)) ORDER BY n.created_at DESC, n.id DESC LIMIT 50");
mysqli_stmt_bind_param($statement, 'iii', $userId, $userId, $userId);
mysqli_stmt_execute($statement);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC);
mysqli_stmt_close($statement);
$labels = [
    'friend_request' => ' sent you a friend request', 'friend_accept' => ' accepted your friend request',
    'post_like' => ' liked your post', 'post_comment' => ' commented on your post',
    'comment_reply' => ' replied to your comment', 'comment_pin' => ' pinned your comment',
    'message' => ' sent you a message', 'profile_view' => ' viewed your profile',
];
foreach ($rows as &$row) {
    $row['id'] = (int) $row['id']; $row['actor_id'] = (int) $row['actor_id']; $row['post_id'] = $row['post_id'] === null ? null : (int) $row['post_id']; $row['is_read'] = (bool) $row['is_read'];
    $row['text'] = $row['username'] . ($labels[$row['type']] ?? ' interacted with you');
    $row['url'] = match ($row['type']) {
        'message' => 'pages/messages.php?user=' . $row['actor_id'],
        'post_like', 'post_comment', 'comment_reply', 'comment_pin' => 'index.php#post-' . $row['post_id'],
        default => 'pages/profile.php?id=' . $row['actor_id'],
    };
}
unset($row);
$statement = mysqli_prepare($conn, 'SELECT COUNT(*) AS total FROM notifications n WHERE n.recipient_id = ? AND n.is_read = 0 AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_id = ? AND b.blocked_id = n.actor_id) OR (b.blocker_id = n.actor_id AND b.blocked_id = ?))');
mysqli_stmt_bind_param($statement, 'iii', $userId, $userId, $userId); mysqli_stmt_execute($statement);
$unreadTotal = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($statement))['total']; mysqli_stmt_close($statement);
echo json_encode(['ok' => true, 'notifications' => $rows, 'unreadTotal' => $unreadTotal]);
