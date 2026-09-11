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
$_SESSION['top_eight_token'] ??= bin2hex(random_bytes(32));
$topError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    $slots = $_POST['slots'] ?? [];
    $allowed = array_map('intval', array_column($friends, 'id'));
    $chosen = [];
    if (!is_string($token) || !hash_equals($_SESSION['top_eight_token'], $token)) {
        http_response_code(403);
        $topError = 'Refresh the page and try again.';
    } elseif (!is_array($slots) || count($slots) !== 8) {
        $topError = 'Choose up to eight friends.';
    } else {
        foreach (array_values($slots) as $slot => $value) {
            if ($value === '') continue;
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if (!$id || !in_array($id, $allowed, true) || in_array($id, $chosen, true)) {
                $topError = 'Choose each friend only once, from your friends list.';
                break;
            }
            $chosen[$slot + 1] = $id;
        }
        if ($topError === '') {
            mysqli_begin_transaction($conn);
            try {
                $lock = mysqli_prepare($conn, 'SELECT id FROM users WHERE id = ? FOR UPDATE');
                mysqli_stmt_bind_param($lock, 'i', $userId);
                mysqli_stmt_execute($lock);
                mysqli_stmt_store_result($lock);
                mysqli_stmt_close($lock);
                $clear = mysqli_prepare($conn, 'UPDATE friends SET top_eight_position = NULL WHERE user_id = ?');
                mysqli_stmt_bind_param($clear, 'i', $userId);
                mysqli_stmt_execute($clear);
                mysqli_stmt_close($clear);
                $save = mysqli_prepare($conn, 'INSERT INTO friends (user_id, friend_id, top_eight_position) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE top_eight_position = VALUES(top_eight_position)');
                foreach ($chosen as $position => $friendId) {
                    mysqli_stmt_bind_param($save, 'iii', $userId, $friendId, $position);
                    mysqli_stmt_execute($save);
                }
                mysqli_stmt_close($save);
                mysqli_commit($conn);
                header('Location: friends.php?saved=1#top-eight');
                exit;
            } catch (Throwable $exception) {
                mysqli_rollback($conn);
                $topError = 'Could not save your Top 8. Please try again.';
            }
        }
    }
}
$statement = mysqli_prepare($conn, 'SELECT friend_id, top_eight_position FROM friends WHERE user_id = ? AND top_eight_position BETWEEN 1 AND 8');
mysqli_stmt_bind_param($statement, 'i', $userId);
mysqli_stmt_execute($statement);
$topSlots = [];
foreach (mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC) as $row) $topSlots[(int) $row['top_eight_position']] = (int) $row['friend_id'];
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
                        <a href="../index.php?chat=<?= (int) $friend['id'] ?>">Mini chat</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p><a href="messages.php">Messages</a></p>
        <section id="top-eight">
            <h2>Your Top 8</h2>
            <p>Choose the friends shown on your dashboard, in order.</p>
            <?php if ($topError): ?><p role="alert"><?= htmlspecialchars($topError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
            <?php if (isset($_GET['saved'])): ?><p role="status">Top 8 saved.</p><?php endif; ?>
            <form method="post" action="friends.php#top-eight" class="top-eight-form">
                <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['top_eight_token'], ENT_QUOTES, 'UTF-8') ?>">
                <?php for ($slot = 1; $slot <= 8; $slot++): ?>
                    <label for="top-slot-<?= $slot ?>">Slot <?= $slot ?></label>
                    <select id="top-slot-<?= $slot ?>" name="slots[]">
                        <option value="">Empty</option>
                        <?php foreach ($friends as $friend): ?>
                            <option value="<?= (int) $friend['id'] ?>"<?= ($topSlots[$slot] ?? 0) === (int) $friend['id'] ? ' selected' : '' ?>><?= htmlspecialchars($friend['username'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endfor; ?>
                <button type="submit">Save Top 8</button>
            </form>
        </section>
        <p><a class="button" href="../index.php">Back to dashboard</a></p>
    </main>
</body>
</html>
