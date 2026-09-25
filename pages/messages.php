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

function conversationListTimestamp(?string $value): string
{
    if (!$value) return '';
    $date = new DateTimeImmutable($value);
    $today = new DateTimeImmutable('today');
    if ($date->format('Y-m-d') === $today->format('Y-m-d')) return $date->format('H:i');
    if ($date->format('Y') === $today->format('Y')) return $date->format('M j');
    return $date->format('M j, Y');
}

$selectedId = filter_var($_GET['user'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$statement = mysqli_prepare($conn, 'SELECT u.id, u.username, u.avatar_path, u.status_text, u.activity_state, u.last_active_at, latest_message.sender_id AS last_sender_id, latest_message.content AS last_message, latest_message.created_at AS last_message_at, (SELECT COUNT(*) FROM messages unread WHERE unread.sender_id = u.id AND unread.receiver_id = ? AND unread.is_read = 0) AS unread_count FROM users u LEFT JOIN messages latest_message ON latest_message.id = (SELECT m.id FROM messages m WHERE (m.sender_id = ? AND m.receiver_id = u.id) OR (m.receiver_id = ? AND m.sender_id = u.id) ORDER BY m.id DESC LIMIT 1) WHERE u.id <> ? AND EXISTS (SELECT 1 FROM friends f WHERE (f.user_id = ? AND f.friend_id = u.id) OR (f.friend_id = ? AND f.user_id = u.id)) ORDER BY latest_message.created_at IS NULL, latest_message.created_at DESC, u.username, u.id');
mysqli_stmt_bind_param($statement, 'iiiiii', $currentUserId, $currentUserId, $currentUserId, $currentUserId, $currentUserId, $currentUserId);
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
$selectedAvatar = '';
$selectedInitial = '';
$activityLabel = '';
if ($selectedFriend) {
    $selectedAvatar = trim($selectedFriend['avatar_path'] ?? '');
    if ($selectedAvatar !== '' && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $selectedAvatar)) {
        $selectedAvatar = str_starts_with($selectedAvatar, '/') ? $selectedAvatar : '../' . $selectedAvatar;
    } else { $selectedAvatar = ''; }
    $selectedInitial = mb_strtoupper(mb_substr($selectedFriend['username'], 0, 1));
    $activityLabel = match ($selectedFriend['activity_state'] ?? 'offline') {
        'online' => 'Online',
        'idle' => 'Idle',
        default => $selectedFriend['last_active_at'] ? 'Last seen ' . conversationListTimestamp($selectedFriend['last_active_at']) : 'Offline',
    };
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Messages &middot; NexusSpace</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <script src="../assets/js/messages.js?v=<?= filemtime(__DIR__ . '/../assets/js/messages.js') ?>" defer></script>
</head>
<body class="messages-body">
    <main class="messages-page<?= $selectedFriend ? ' has-selected-conversation' : '' ?>" data-messages-page>
        <section class="messages-sidebar" aria-label="Messages navigation">
            <header class="messages-sidebar-header">
                <a class="messages-dashboard-link" href="../index.php" aria-label="Back to dashboard" title="Back to dashboard">&larr;</a>
                <h1>Messages</h1>
            </header>
            <div class="conversation-search">
                <label class="sr-only" for="conversation-search-input">Search friends</label>
                <input id="conversation-search-input" type="search" placeholder="Search friends" autocomplete="off" data-conversation-search>
            </div>
            <aside class="conversation-list" aria-label="Choose a friend">
                <?php if (!$friends): ?><p data-conversation-list-empty>Add a friend to start chatting.</p><?php endif; ?>
                <?php foreach ($friends as $friend): ?>
                    <?php
                    $friendAvatar = trim($friend['avatar_path'] ?? '');
                    if ($friendAvatar !== '' && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $friendAvatar)) {
                        $friendAvatar = str_starts_with($friendAvatar, '/') ? $friendAvatar : '../' . $friendAvatar;
                    } else { $friendAvatar = ''; }
                    $friendInitial = mb_strtoupper(mb_substr($friend['username'], 0, 1));
                    $hasConversation = $friend['last_message_at'] !== null;
                    ?>
                    <a class="conversation-list-item" href="messages.php?user=<?= (int) $friend['id'] ?>" data-conversation-friend data-friend-name="<?= htmlspecialchars($friend['username'], ENT_QUOTES, 'UTF-8') ?>"<?= (int) $friend['id'] === $selectedId ? ' aria-current="page"' : '' ?>>
                        <span class="conversation-list-avatar" aria-hidden="true"><span><?= htmlspecialchars($friendInitial, ENT_QUOTES, 'UTF-8') ?></span><?php if ($friendAvatar): ?><img src="<?= htmlspecialchars($friendAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy"><?php endif; ?></span>
                        <span class="conversation-list-details">
                            <span class="conversation-list-heading">
                                <strong><?= htmlspecialchars($friend['username'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if ($hasConversation): ?><time datetime="<?= htmlspecialchars(str_replace(' ', 'T', $friend['last_message_at']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(conversationListTimestamp($friend['last_message_at']), ENT_QUOTES, 'UTF-8') ?></time><?php endif; ?>
                            </span>
                            <span class="conversation-list-preview<?= $hasConversation ? '' : ' is-empty' ?>"><?php if ($hasConversation && (int) $friend['last_sender_id'] === $currentUserId): ?><span>You: </span><?php endif; ?><?= htmlspecialchars($hasConversation ? $friend['last_message'] : 'Start a conversation!', ENT_QUOTES, 'UTF-8') ?></span>
                        </span>
                        <?php if ((int) $friend['unread_count'] > 0 && (int) $friend['id'] !== $selectedId): ?><span class="conversation-unread" aria-label="<?= (int) $friend['unread_count'] ?> unread messages"><?= (int) $friend['unread_count'] ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
                <?php if ($friends): ?><p class="conversation-search-empty" data-conversation-search-empty hidden>No friends found.</p><?php endif; ?>
            </aside>
        </section>
        <section class="conversation" aria-label="Conversation">
            <header class="conversation-header">
                <?php if ($selectedFriend): ?>
                    <a class="conversation-mobile-back" href="messages.php" aria-label="Back to friends">&larr;</a>
                    <h2 class="sr-only">Conversation with <?= htmlspecialchars($selectedFriend['username'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <a class="conversation-header-person" href="profile.php?id=<?= $selectedId ?>">
                        <span class="conversation-header-avatar" aria-hidden="true"><span><?= htmlspecialchars($selectedInitial, ENT_QUOTES, 'UTF-8') ?></span><?php if ($selectedAvatar): ?><img src="<?= htmlspecialchars($selectedAvatar, ENT_QUOTES, 'UTF-8') ?>" alt=""><?php endif; ?></span>
                        <span class="conversation-header-copy">
                            <strong><?= htmlspecialchars($selectedFriend['username'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <small data-conversation-activity><?= htmlspecialchars($activityLabel, ENT_QUOTES, 'UTF-8') ?></small>
                        </span>
                    </a>
                    <button class="conversation-message-search-toggle" type="button" aria-label="Search messages" title="Search messages" aria-expanded="false" data-message-search-toggle><span class="conversation-message-search-icon" aria-hidden="true"></span></button>
                    <button class="conversation-profile-toggle" type="button" aria-label="Open friend profile" title="Open friend profile" aria-controls="conversation-profile" aria-expanded="false" data-conversation-profile-toggle>:</button>
                <?php else: ?>
                    <h2>Conversation</h2>
                <?php endif; ?>
            </header>
            <?php if ($selectedFriend): ?>
                <div class="conversation-content">
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
                        <label class="sr-only" for="message-content">Message</label>
                        <textarea id="message-content" name="content" rows="3" maxlength="2000" required><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></textarea>
                        <button type="submit">Send</button>
                    </form>
                    <?php endif; ?>
                    <p class="message-status" data-message-status role="status"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            <?php else: ?>
                <div class="conversation-empty">
                    <?php if (!$error): ?><p>Choose a friend to open your conversation.</p><?php endif; ?>
                    <p class="message-status" data-message-status role="status"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            <?php endif; ?>
        </section>
        <aside class="conversation-profile" id="conversation-profile" data-conversation-profile aria-label="Conversation profile" hidden>
            <?php if ($selectedFriend): ?>
                <header class="conversation-profile-header">
                    <h2>Profile</h2>
                    <button type="button" aria-label="Close friend profile" title="Close friend profile" data-conversation-profile-close>&times;</button>
                </header>
                <div class="conversation-profile-identity">
                    <span class="conversation-profile-avatar" aria-hidden="true"><span><?= htmlspecialchars($selectedInitial, ENT_QUOTES, 'UTF-8') ?></span><?php if ($selectedAvatar): ?><img src="<?= htmlspecialchars($selectedAvatar, ENT_QUOTES, 'UTF-8') ?>" alt=""><?php endif; ?></span>
                    <a href="profile.php?id=<?= $selectedId ?>"><?= htmlspecialchars($selectedFriend['username'], ENT_QUOTES, 'UTF-8') ?></a>
                    <small data-conversation-activity><?= htmlspecialchars($activityLabel, ENT_QUOTES, 'UTF-8') ?></small>
                </div>
                <?php if (trim($selectedFriend['status_text'] ?? '') !== ''): ?><p class="conversation-profile-status"><?= htmlspecialchars($selectedFriend['status_text'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
            <?php endif; ?>
        </aside>
    </main>
</body>
</html>
