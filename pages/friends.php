<?php

declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/../includes/db_connect.php';
$userId = (int) $_SESSION['user_id'];
$statement = mysqli_prepare($conn, 'SELECT u.id, u.username FROM users u WHERE u.id <> ? AND EXISTS (SELECT 1 FROM friends f WHERE (f.user_id = ? AND f.friend_id = u.id) OR (f.friend_id = ? AND f.user_id = u.id)) ORDER BY u.username, u.id');
mysqli_stmt_bind_param($statement, 'iii', $userId, $userId, $userId);
mysqli_stmt_execute($statement);
$friends = mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC);
mysqli_stmt_close($statement);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Friends · NexusSpace</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body>
    <main class="card">
        <h1>Friends</h1>
        <?php if (!$friends): ?>
            <p>No friends yet. Find someone using the dashboard search and send a friend request.</p>
        <?php else: ?>
            <ul class="people-list">
                <?php foreach ($friends as $friend): ?>
                    <li>
                        <a class="person-link" href="profile.php?id=<?= (int) $friend['id'] ?>">
                            <span class="mini-avatar" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr($friend['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                            <span><?= htmlspecialchars($friend['username'], ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                        <a class="button" href="messages.php?user=<?= (int) $friend['id'] ?>">Message</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p><a href="messages.php">Messages</a></p>
        <p><a class="button" href="../index.php">Back to dashboard</a></p>
    </main>
</body>
</html>
