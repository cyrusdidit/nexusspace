<?php

declare(strict_types=1);

session_start();
$isJson = ($_GET['format'] ?? '') === 'json';
header('Cache-Control: no-store');
if ($isJson) header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) {
    if ($isJson) {
        http_response_code(401);
        echo json_encode(['error' => 'Please log in again.']);
    } else {
        header('Location: login.php');
    }
    exit;
}

require_once __DIR__ . '/../includes/db_connect.php';
$currentUserId = (int) $_SESSION['user_id'];
$_SESSION['message_token'] ??= bin2hex(random_bytes(32));
$token = $_SESSION['message_token'];
session_write_close();
$selectedId = filter_var($_GET['user'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$statement = mysqli_prepare($conn, 'SELECT u.id, u.username, u.avatar_path, (SELECT COUNT(*) FROM messages m WHERE m.sender_id = u.id AND m.receiver_id = ? AND m.is_read = 0) AS unread_count FROM users u WHERE u.id <> ? AND EXISTS (SELECT 1 FROM friends f WHERE (f.user_id = ? AND f.friend_id = u.id) OR (f.friend_id = ? AND f.user_id = u.id)) ORDER BY u.username, u.id');
mysqli_stmt_bind_param($statement, 'iiii', $currentUserId, $currentUserId, $currentUserId, $currentUserId);
mysqli_stmt_execute($statement);
$friends = mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC);
mysqli_stmt_close($statement);
$selectedFriend = null;
foreach ($friends as $friend) {
    if ((int) $friend['id'] === $selectedId) $selectedFriend = $friend;
}
$error = '';
$content = '';
if (isset($_GET['user']) && !$selectedFriend) {
    http_response_code(403);
    $error = 'Choose someone from your friends list to message them.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['token'] ?? '';
    if (!$selectedFriend || !is_string($submittedToken) || !hash_equals($token, $submittedToken)) {
        http_response_code(403);
        $error = 'Could not send. Refresh the page and choose a friend.';
    } elseif (($_POST['action'] ?? '') === 'read') {
        $first = filter_var($_POST['first'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $last = filter_var($_POST['last'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$first || !$last || $last < $first) {
            http_response_code(422);
            $error = 'Invalid message range.';
        } else {
            $statement = mysqli_prepare($conn, 'UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND id BETWEEN ? AND ?');
            mysqli_stmt_bind_param($statement, 'iiii', $selectedId, $currentUserId, $first, $last);
            mysqli_stmt_execute($statement);
            mysqli_stmt_close($statement);
            if ($isJson) { echo json_encode(['ok' => true]); exit; }
        }
    } else {
        $content = is_string($_POST['content'] ?? null) ? trim($_POST['content']) : '';
        $length = preg_match_all('/./us', $content);
        if ($content === '' || $length === false || $length > 2000) {
            http_response_code(422);
            $error = 'Enter a message of 1–2000 characters.';
        } else {
            $statement = mysqli_prepare($conn, 'INSERT INTO messages (sender_id, receiver_id, content) VALUES (?, ?, ?)');
            mysqli_stmt_bind_param($statement, 'iis', $currentUserId, $selectedId, $content);
            mysqli_stmt_execute($statement);
            mysqli_stmt_close($statement);
            if ($isJson) {
                echo json_encode(['ok' => true]);
                exit;
            }
            header('Location: messages.php?user=' . $selectedId);
            exit;
        }
    }
}

$messages = [];
$before = filter_var($_GET['before'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$after = filter_var($_GET['after'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$hasOlder = false;
if ($selectedFriend && $error === '') {
    $sql = 'SELECT id, sender_id, content, created_at FROM messages WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))';
    if ($after) {
        $sql .= ' AND id > ? ORDER BY id ASC LIMIT 100';
        $boundary = $after;
    } else {
        $sql .= ' AND id < ? ORDER BY id DESC LIMIT 101';
        $boundary = $before ?: PHP_INT_MAX;
    }
    $statement = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($statement, 'iiiii', $currentUserId, $selectedId, $selectedId, $currentUserId, $boundary);
    mysqli_stmt_execute($statement);
    $messages = mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC);
    mysqli_stmt_close($statement);
    if (!$after) {
        $hasOlder = count($messages) > 100;
        $messages = array_reverse(array_slice($messages, 0, 100));
    }
    // Only acknowledge incoming messages actually included in this response.
    if ($messages && ($_GET['mini'] ?? '') !== '1') {
        $firstId = (int) $messages[0]['id'];
        $lastId = (int) $messages[count($messages) - 1]['id'];
        $statement = mysqli_prepare($conn, 'UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND id BETWEEN ? AND ?');
        mysqli_stmt_bind_param($statement, 'iiii', $selectedId, $currentUserId, $firstId, $lastId);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
    }
}
if ($isJson) {
    echo json_encode(['messages' => $messages, 'error' => $error, 'friend' => $selectedFriend, 'viewerId' => $currentUserId, 'token' => $token]);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Messages · NexusSpace</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <script src="../assets/js/messages.js?v=<?= filemtime(__DIR__ . '/../assets/js/messages.js') ?>" defer></script>
</head>
<body>
    <main class="card messages-page">
        <h1>Messages</h1>
        <nav class="messaging-nav"><a href="../index.php">Dashboard</a> · <a href="friends.php">Friends</a></nav>
        <div class="messaging-layout">
            <aside class="conversation-list" aria-label="Choose a friend">
                <?php if (!$friends): ?><p>Add a friend to start chatting.</p><?php endif; ?>
                <?php foreach ($friends as $friend): ?>
                    <a href="messages.php?user=<?= (int) $friend['id'] ?>"<?= (int) $friend['id'] === $selectedId ? ' aria-current="page"' : '' ?>>
                        <?= htmlspecialchars($friend['username'], ENT_QUOTES, 'UTF-8') ?>
                        <?php if ((int) $friend['unread_count'] > 0 && (int) $friend['id'] !== $selectedId): ?>
                            <small><?= (int) $friend['unread_count'] ?> unread</small>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </aside>
            <section class="conversation" aria-label="Conversation">
                <?php if ($selectedFriend): ?>
                    <h2><a href="profile.php?id=<?= $selectedId ?>"><?= htmlspecialchars($selectedFriend['username'], ENT_QUOTES, 'UTF-8') ?></a></h2>
                    <?php if ($hasOlder): ?><a href="messages.php?user=<?= $selectedId ?>&amp;before=<?= (int) $messages[0]['id'] ?>">Older messages</a><?php endif; ?>
                    <?php if ($before): ?><p><a href="messages.php?user=<?= $selectedId ?>">Back to latest messages</a></p><?php endif; ?>
                    <div class="conversation-messages" data-message-list data-viewer="<?= $currentUserId ?>" data-poll="<?= $before ? 'false' : 'true' ?>" role="log" aria-label="Messages" tabindex="0">
                        <?php if (!$messages): ?><p data-empty-messages>No messages yet. Say hello!</p><?php endif; ?>
                        <?php foreach ($messages as $message): ?>
                            <article class="conversation-message<?= (int) $message['sender_id'] === $currentUserId ? ' is-mine' : '' ?>" data-message-id="<?= (int) $message['id'] ?>">
                                <strong><?= (int) $message['sender_id'] === $currentUserId ? 'You' : htmlspecialchars($selectedFriend['username'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <p><?= htmlspecialchars($message['content'], ENT_QUOTES, 'UTF-8') ?></p>
                                <small><?= htmlspecialchars($message['created_at'], ENT_QUOTES, 'UTF-8') ?></small>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!$before): ?>
                    <form method="post" action="messages.php?user=<?= $selectedId ?>" data-message-form data-friend-name="<?= htmlspecialchars($selectedFriend['username'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                        <label for="message-content">Message</label>
                        <textarea id="message-content" name="content" rows="3" maxlength="2000" required><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></textarea>
                        <button type="submit">Send</button>
                    </form>
                    <?php endif; ?>
                <?php elseif (!$error): ?>
                    <p>Choose a friend to open your conversation.</p>
                <?php endif; ?>
                <p class="message-status" data-message-status role="status"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
            </section>
        </div>
    </main>
</body>
</html>
