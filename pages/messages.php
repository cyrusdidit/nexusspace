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
require_once __DIR__ . '/../includes/background_customization.php';
$currentUserId = (int) $_SESSION['user_id'];
$_SESSION['message_token'] ??= bin2hex(random_bytes(32));
$token = $_SESSION['message_token'];
session_write_close();
$backgroundSettings = readUserBackgroundSettings($conn, $currentUserId);
$messagesOwnBackground = $backgroundSettings['messages'];
$messagesBackground = resolveUserBackground($backgroundSettings, 'messages');
$messagesBackgroundColor = $messagesBackground['color_value'];
$messagesBackgroundImagePath = $messagesBackground['image_path'];
$messagesBackgroundImageUrl = $messagesBackgroundImagePath ? '../' . $messagesBackgroundImagePath : '';
$messagesBackgroundType = $messagesBackground['background_type'] === 'image' && $messagesBackgroundImageUrl !== '' ? 'image' : 'color';
$messagesBackgroundImageCss = $messagesBackgroundType === 'image' ? 'url("' . $messagesBackgroundImageUrl . '")' : 'none';
$messagesSavedBackgroundType = $messagesBackgroundType;

function conversationListTimestamp(?string $value): string
{
    if (!$value) return '';
    $date = new DateTimeImmutable($value);
    $today = new DateTimeImmutable('today');
    if ($date->format('Y-m-d') === $today->format('Y-m-d')) return $date->format('H:i');
    if ($date->format('Y') === $today->format('Y')) return $date->format('M j');
    return $date->format('M j, Y');
}

function conversationMessageTime(string $value): string
{
    return (new DateTimeImmutable($value))->format('g:i A');
}

function conversationDateLabel(string $value): string
{
    $date = new DateTimeImmutable($value);
    $today = new DateTimeImmutable('today');
    if ($date->format('Y-m-d') === $today->format('Y-m-d')) return 'Today';
    if ($date->format('Y-m-d') === $today->modify('-1 day')->format('Y-m-d')) return 'Yesterday';
    return $date->format($date->format('Y') === $today->format('Y') ? 'F j' : 'F j, Y');
}

$statement = mysqli_prepare($conn, 'SELECT username, avatar_path FROM users WHERE id = ? LIMIT 1');
mysqli_stmt_bind_param($statement, 'i', $currentUserId);
mysqli_stmt_execute($statement);
$viewer = mysqli_fetch_assoc(mysqli_stmt_get_result($statement)) ?: ['username' => 'You', 'avatar_path' => ''];
mysqli_stmt_close($statement);

$selectedId = filter_var($_GET['user'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$statement = mysqli_prepare($conn, 'SELECT u.id, u.username, u.avatar_path, u.status_text, u.activity_state, u.last_active_at, (SELECT f.nickname FROM friends f WHERE f.user_id = ? AND f.friend_id = u.id LIMIT 1) AS nickname, latest_message.sender_id AS last_sender_id, latest_message.content AS last_message, latest_message.created_at AS last_message_at, (SELECT COUNT(*) FROM messages unread WHERE unread.sender_id = u.id AND unread.receiver_id = ? AND unread.is_read = 0) AS unread_count FROM users u LEFT JOIN messages latest_message ON latest_message.id = (SELECT m.id FROM messages m WHERE (m.sender_id = ? AND m.receiver_id = u.id) OR (m.receiver_id = ? AND m.sender_id = u.id) ORDER BY m.id DESC LIMIT 1) WHERE u.id <> ? AND EXISTS (SELECT 1 FROM friends f WHERE (f.user_id = ? AND f.friend_id = u.id) OR (f.friend_id = ? AND f.user_id = u.id)) ORDER BY latest_message.created_at IS NULL, latest_message.created_at DESC, u.username, u.id');
mysqli_stmt_bind_param($statement, 'iiiiiii', $currentUserId, $currentUserId, $currentUserId, $currentUserId, $currentUserId, $currentUserId, $currentUserId);
mysqli_stmt_execute($statement);
$friends = mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC);
mysqli_stmt_close($statement);
$selectedFriend = null;
foreach ($friends as $friend) {
    if ((int) $friend['id'] === $selectedId) $selectedFriend = $friend;
}
$error = '';
$appearanceError = '';
$appearancePanelOpen = isset($_GET['customize']);
$content = '';
if (isset($_GET['user']) && !$selectedFriend) {
    http_response_code(403);
    $error = 'Choose someone from your friends list to message them.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['token'] ?? '';
    $action = $_POST['action'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($token, $submittedToken)) {
        http_response_code(403);
        if (in_array($action, ['update_messages_background', 'reset_messages_background'], true)) {
            $appearancePanelOpen = true;
            $appearanceError = 'Refresh the page and try again.';
        } else {
            $error = 'Could not send. Refresh the page and choose a friend.';
        }
    } elseif ($action === 'update_messages_background') {
        $appearancePanelOpen = true;
        $submittedType = is_string($_POST['background_type'] ?? null) ? $_POST['background_type'] : '';
        $submittedColor = is_string($_POST['background_color'] ?? null) ? strtolower($_POST['background_color']) : '';
        $messagesBackgroundType = $submittedType;
        if (!in_array($submittedType, ['color', 'image'], true)) {
            http_response_code(422);
            $appearanceError = 'Choose a background type.';
        } elseif ($submittedType === 'color' && !preg_match('/^#[0-9a-f]{6}$/', $submittedColor)) {
            http_response_code(422);
            $appearanceError = 'Choose a valid background color.';
        } else {
            $previousImagePath = $messagesOwnBackground['image_path'];
            $savedImagePath = $previousImagePath;
            $savedColor = $messagesOwnBackground['color_value'];
            try {
                if ($submittedType === 'image') {
                    $upload = is_array($_FILES['background_image'] ?? null) ? $_FILES['background_image'] : ['error' => UPLOAD_ERR_NO_FILE];
                    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $savedImagePath = storeUserBackgroundImage($upload, $currentUserId);
                    } elseif (!$savedImagePath) {
                        throw new InvalidArgumentException('Choose an image to upload.');
                    }
                    saveUserBackgroundSetting($conn, $currentUserId, 'messages', 'image', $savedColor, $savedImagePath, null);
                } else {
                    saveUserBackgroundSetting($conn, $currentUserId, 'messages', 'color', $submittedColor, $savedImagePath, null);
                }
                if ($previousImagePath && $savedImagePath !== $previousImagePath) {
                    deleteUserBackgroundImage($previousImagePath, $currentUserId);
                }
                $redirect = 'messages.php?customize=1&appearance_saved=1';
                if ($selectedId) $redirect .= '&user=' . $selectedId;
                header('Location: ' . $redirect);
                exit;
            } catch (InvalidArgumentException | RuntimeException $exception) {
                if ($savedImagePath && $savedImagePath !== $previousImagePath) deleteUserBackgroundImage($savedImagePath, $currentUserId);
                http_response_code(422);
                $appearanceError = $exception->getMessage();
            }
        }
    } elseif ($action === 'reset_messages_background') {
        $previousImagePath = $messagesOwnBackground['image_path'];
        saveUserBackgroundSetting($conn, $currentUserId, 'messages', 'color', USER_BACKGROUND_DEFAULTS['messages'], null, null);
        deleteUserBackgroundImage($previousImagePath, $currentUserId);
        $redirect = 'messages.php?customize=1&appearance_reset=1';
        if ($selectedId) $redirect .= '&user=' . $selectedId;
        header('Location: ' . $redirect);
        exit;
    } elseif (!$selectedFriend) {
        http_response_code(403);
        $error = 'Could not send. Refresh the page and choose a friend.';
    } elseif ($action === 'update_nickname') {
        $nickname = is_string($_POST['nickname'] ?? null) ? trim($_POST['nickname']) : '';
        $nickname = preg_replace('/\s+/u', ' ', $nickname) ?? '';
        if (!mb_check_encoding($nickname, 'UTF-8') || mb_strlen($nickname, 'UTF-8') > 50) {
            http_response_code(422);
            $error = 'Use 50 characters or fewer.';
        } else {
            $statement = mysqli_prepare($conn, "UPDATE friends SET nickname = NULLIF(?, '') WHERE user_id = ? AND friend_id = ?");
            mysqli_stmt_bind_param($statement, 'sii', $nickname, $currentUserId, $selectedId);
            mysqli_stmt_execute($statement);
            mysqli_stmt_close($statement);
            $displayName = $nickname !== '' ? $nickname : $selectedFriend['username'];
            if ($isJson) {
                echo json_encode(['ok' => true, 'nickname' => $nickname, 'displayName' => $displayName, 'username' => $selectedFriend['username']]);
                exit;
            }
            header('Location: messages.php?user=' . $selectedId);
            exit;
        }
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
$lastReadOutgoingId = 0;
$before = filter_var($_GET['before'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$after = filter_var($_GET['after'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$hasOlder = false;
if ($selectedFriend && $error === '') {
    $sql = 'SELECT id, sender_id, content, created_at, is_read FROM messages WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))';
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

    $statement = mysqli_prepare($conn, 'SELECT COALESCE(MAX(id), 0) AS last_read_id FROM messages WHERE sender_id = ? AND receiver_id = ? AND is_read = 1');
    mysqli_stmt_bind_param($statement, 'ii', $currentUserId, $selectedId);
    mysqli_stmt_execute($statement);
    $lastReadOutgoingId = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($statement))['last_read_id'] ?? 0);
    mysqli_stmt_close($statement);
}
if ($isJson) {
    echo json_encode(['messages' => $messages, 'error' => $error, 'friend' => $selectedFriend, 'viewerId' => $currentUserId, 'lastReadOutgoingId' => $lastReadOutgoingId, 'token' => $token]);
    exit;
}
$sharedLinks = [];
$mutualFriends = [];
if ($selectedFriend) {
    $statement = mysqli_prepare($conn, "SELECT content FROM messages WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)) AND (content LIKE '%http://%' OR content LIKE '%https://%') ORDER BY id DESC");
    mysqli_stmt_bind_param($statement, 'iiii', $currentUserId, $selectedId, $selectedId, $currentUserId);
    mysqli_stmt_execute($statement);
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC) as $linkMessage) {
        if (!preg_match_all('~https?://[^\s<>\x22\x27]+~iu', $linkMessage['content'], $matches)) continue;
        foreach ($matches[0] as $url) {
            $url = rtrim($url, '.,!?;:)]}');
            if (filter_var($url, FILTER_VALIDATE_URL)) $sharedLinks[$url] = $url;
        }
    }
    mysqli_stmt_close($statement);
    $sharedLinks = array_values($sharedLinks);

    $statement = mysqli_prepare($conn, 'SELECT u.id, u.username, u.avatar_path FROM friends viewer_friend JOIN friends selected_friend ON selected_friend.friend_id = viewer_friend.friend_id AND selected_friend.user_id = ? JOIN users u ON u.id = viewer_friend.friend_id WHERE viewer_friend.user_id = ? AND viewer_friend.friend_id NOT IN (?, ?) ORDER BY u.username, u.id');
    mysqli_stmt_bind_param($statement, 'iiii', $selectedId, $currentUserId, $currentUserId, $selectedId);
    mysqli_stmt_execute($statement);
    $mutualFriends = mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC);
    mysqli_stmt_close($statement);
}
$selectedAvatar = '';
$selectedInitial = '';
$selectedDisplayName = '';
$activityLabel = '';
$viewerAvatar = trim($viewer['avatar_path'] ?? '');
if ($viewerAvatar !== '' && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $viewerAvatar)) {
    $viewerAvatar = str_starts_with($viewerAvatar, '/') ? $viewerAvatar : '../' . $viewerAvatar;
} else { $viewerAvatar = ''; }
$viewerInitial = mb_strtoupper(mb_substr($viewer['username'], 0, 1));
if ($selectedFriend) {
    $selectedDisplayName = trim($selectedFriend['nickname'] ?? '') ?: $selectedFriend['username'];
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
                    $friendDisplayName = trim($friend['nickname'] ?? '') ?: $friend['username'];
                    $hasConversation = $friend['last_message_at'] !== null;
                    ?>
                    <a class="conversation-list-item" href="messages.php?user=<?= (int) $friend['id'] ?>" data-conversation-friend data-friend-name="<?= htmlspecialchars($friendDisplayName . ' ' . $friend['username'], ENT_QUOTES, 'UTF-8') ?>"<?= (int) $friend['id'] === $selectedId ? ' aria-current="page"' : '' ?>>
                        <span class="conversation-list-avatar" aria-hidden="true"><span><?= htmlspecialchars($friendInitial, ENT_QUOTES, 'UTF-8') ?></span><?php if ($friendAvatar): ?><img src="<?= htmlspecialchars($friendAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy"><?php endif; ?></span>
                        <span class="conversation-list-details">
                            <span class="conversation-list-heading">
                                <strong<?= (int) $friend['id'] === $selectedId ? ' data-selected-friend-name' : '' ?>><?= htmlspecialchars($friendDisplayName, ENT_QUOTES, 'UTF-8') ?></strong>
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
                    <h2 class="sr-only">Conversation with <?= htmlspecialchars($selectedDisplayName, ENT_QUOTES, 'UTF-8') ?></h2>
                    <a class="conversation-header-person" href="profile.php?id=<?= $selectedId ?>">
                        <span class="conversation-header-avatar" aria-hidden="true"><span><?= htmlspecialchars($selectedInitial, ENT_QUOTES, 'UTF-8') ?></span><?php if ($selectedAvatar): ?><img src="<?= htmlspecialchars($selectedAvatar, ENT_QUOTES, 'UTF-8') ?>" alt=""><?php endif; ?></span>
                        <span class="conversation-header-copy">
                            <strong data-selected-friend-name><?= htmlspecialchars($selectedDisplayName, ENT_QUOTES, 'UTF-8') ?></strong>
                            <small data-conversation-activity><?= htmlspecialchars($activityLabel, ENT_QUOTES, 'UTF-8') ?></small>
                        </span>
                    </a>
                    <button class="conversation-message-search-toggle" type="button" aria-label="Search messages" title="Search messages" aria-expanded="false" data-message-search-toggle><span class="conversation-message-search-icon" aria-hidden="true"></span></button>
                    <button class="conversation-profile-toggle" type="button" aria-label="Open friend profile" title="Open friend profile" aria-controls="conversation-profile" aria-expanded="false" data-conversation-profile-toggle>:</button>
                <?php else: ?>
                    <h2>Conversation</h2>
                <?php endif; ?>
                <button class="conversation-customize-toggle" type="button" aria-label="Customize Messages background" title="Customize Messages background" aria-controls="messages-customization" aria-expanded="false" data-message-customization-toggle>&#9681;</button>
            </header>
            <?php if ($selectedFriend): ?>
                <div class="conversation-content">
                    <?php if ($hasOlder): ?><a href="messages.php?user=<?= $selectedId ?>&amp;before=<?= (int) $messages[0]['id'] ?>">Older messages</a><?php endif; ?>
                    <?php if ($before): ?><p><a href="messages.php?user=<?= $selectedId ?>">Back to latest messages</a></p><?php endif; ?>
                    <div class="conversation-messages" style="background-color: <?= htmlspecialchars($messagesBackgroundColor, ENT_QUOTES, 'UTF-8') ?>; background-image: <?= htmlspecialchars($messagesBackgroundImageCss, ENT_QUOTES, 'UTF-8') ?>" data-message-list data-viewer="<?= $currentUserId ?>" data-viewer-avatar="<?= htmlspecialchars($viewerAvatar, ENT_QUOTES, 'UTF-8') ?>" data-viewer-initial="<?= htmlspecialchars($viewerInitial, ENT_QUOTES, 'UTF-8') ?>" data-viewer-profile="profile.php?id=<?= $currentUserId ?>" data-viewer-name="<?= htmlspecialchars($viewer['username'], ENT_QUOTES, 'UTF-8') ?>" data-poll="<?= $before ? 'false' : 'true' ?>" data-friend-avatar="<?= htmlspecialchars($selectedAvatar, ENT_QUOTES, 'UTF-8') ?>" data-friend-initial="<?= htmlspecialchars($selectedInitial, ENT_QUOTES, 'UTF-8') ?>" data-friend-profile="profile.php?id=<?= $selectedId ?>" data-friend-name="<?= htmlspecialchars($selectedDisplayName, ENT_QUOTES, 'UTF-8') ?>" role="log" aria-label="Messages" tabindex="0">
                        <?php if (!$messages): ?><p data-empty-messages>No messages yet. Say hello!</p><?php endif; ?>
                        <?php foreach ($messages as $messageIndex => $message): ?>
                            <?php
                            $messageIsMine = (int) $message['sender_id'] === $currentUserId;
                            $messageDate = substr($message['created_at'], 0, 10);
                            $previousMessage = $messages[$messageIndex - 1] ?? null;
                            $nextMessage = $messages[$messageIndex + 1] ?? null;
                            $nextStartsNewDay = $nextMessage && substr($nextMessage['created_at'], 0, 10) !== $messageDate;
                            $showsFriendAvatar = !$messageIsMine && (!$nextMessage || $nextStartsNewDay || (int) $nextMessage['sender_id'] !== (int) $message['sender_id']);
                            $showsViewerAvatar = $messageIsMine && (!$nextMessage || $nextStartsNewDay || (int) $nextMessage['sender_id'] !== (int) $message['sender_id']);
                            ?>
                            <?php if (!$previousMessage || substr($previousMessage['created_at'], 0, 10) !== $messageDate): ?><div class="conversation-date-separator" data-message-date-separator="<?= htmlspecialchars($messageDate, ENT_QUOTES, 'UTF-8') ?>"><span><?= htmlspecialchars(conversationDateLabel($message['created_at']), ENT_QUOTES, 'UTF-8') ?></span></div><?php endif; ?>
                            <article class="conversation-message<?= $messageIsMine ? ' is-mine' : ' is-incoming' ?><?= ($showsFriendAvatar || $showsViewerAvatar) ? ' has-avatar' : '' ?>" data-message-id="<?= (int) $message['id'] ?>" data-message-date="<?= htmlspecialchars($messageDate, ENT_QUOTES, 'UTF-8') ?>">
                                <?php if ($showsFriendAvatar): ?><a class="conversation-message-avatar" href="profile.php?id=<?= $selectedId ?>" aria-label="View <?= htmlspecialchars($selectedFriend['username'], ENT_QUOTES, 'UTF-8') ?>'s profile"><span><?= htmlspecialchars($selectedInitial, ENT_QUOTES, 'UTF-8') ?></span><?php if ($selectedAvatar): ?><img src="<?= htmlspecialchars($selectedAvatar, ENT_QUOTES, 'UTF-8') ?>" alt=""><?php endif; ?></a><?php endif; ?>
                                <?php if ($showsViewerAvatar): ?><a class="conversation-message-avatar is-viewer" href="profile.php?id=<?= $currentUserId ?>" aria-label="View your profile"><span><?= htmlspecialchars($viewerInitial, ENT_QUOTES, 'UTF-8') ?></span><?php if ($viewerAvatar): ?><img src="<?= htmlspecialchars($viewerAvatar, ENT_QUOTES, 'UTF-8') ?>" alt=""><?php endif; ?></a><?php endif; ?>
                                <strong><?= $messageIsMine ? 'You' : htmlspecialchars($selectedDisplayName, ENT_QUOTES, 'UTF-8') ?></strong>
                                <p><?= htmlspecialchars($message['content'], ENT_QUOTES, 'UTF-8') ?></p>
                                <small><time datetime="<?= htmlspecialchars(str_replace(' ', 'T', $message['created_at']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(conversationMessageTime($message['created_at']), ENT_QUOTES, 'UTF-8') ?></time><?php if ($messageIsMine): ?><span class="message-read-receipt<?= (int) $message['is_read'] === 1 ? ' is-read' : '' ?>" data-read-receipt aria-label="<?= (int) $message['is_read'] === 1 ? 'Read' : 'Sent' ?>" title="<?= (int) $message['is_read'] === 1 ? 'Read' : 'Sent' ?>" aria-hidden="false"><?= (int) $message['is_read'] === 1 ? '&#10003;&#10003;' : '&#10003;' ?></span><?php endif; ?></small>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!$before): ?>
                    <form method="post" action="messages.php?user=<?= $selectedId ?>" data-message-form data-friend-name="<?= htmlspecialchars($selectedDisplayName, ENT_QUOTES, 'UTF-8') ?>">
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
        <aside class="messages-customization-panel" id="messages-customization" data-message-customization data-open="<?= $appearancePanelOpen ? 'true' : 'false' ?>" data-saved-type="<?= htmlspecialchars($messagesSavedBackgroundType, ENT_QUOTES, 'UTF-8') ?>" data-saved-color="<?= htmlspecialchars($messagesBackgroundColor, ENT_QUOTES, 'UTF-8') ?>" data-saved-image="<?= htmlspecialchars($messagesBackgroundImageUrl, ENT_QUOTES, 'UTF-8') ?>" aria-label="Messages customization" hidden>
            <header class="messages-customization-header">
                <h2>Appearance</h2>
                <button type="button" aria-label="Close Messages customization" title="Close" data-message-customization-close>&times;</button>
            </header>
            <form class="messages-customization-form" method="post" action="messages.php?customize=1<?= $selectedId ? '&amp;user=' . $selectedId : '' ?>" enctype="multipart/form-data">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                <fieldset class="messages-background-modes">
                    <legend>Conversation background</legend>
                    <label><input type="radio" name="background_type" value="color"<?= $messagesBackgroundType === 'color' ? ' checked' : '' ?> data-message-background-mode> Color</label>
                    <label><input type="radio" name="background_type" value="image"<?= $messagesBackgroundType === 'image' ? ' checked' : '' ?> data-message-background-mode> Image</label>
                </fieldset>
                <div class="messages-background-option" data-message-color-option<?= $messagesBackgroundType === 'color' ? '' : ' hidden' ?>>
                    <label for="messages-background-color">Background color</label>
                    <div class="messages-background-color-row">
                        <input id="messages-background-color" type="color" name="background_color" value="<?= htmlspecialchars($messagesBackgroundColor, ENT_QUOTES, 'UTF-8') ?>" data-message-background-color>
                        <output for="messages-background-color" data-message-background-value><?= htmlspecialchars($messagesBackgroundColor, ENT_QUOTES, 'UTF-8') ?></output>
                    </div>
                </div>
                <div class="messages-background-option" data-message-image-option<?= $messagesBackgroundType === 'image' ? '' : ' hidden' ?>>
                    <label for="messages-background-image">Background image</label>
                    <?php if ($messagesBackgroundImageUrl !== ''): ?><img class="messages-background-thumbnail" src="<?= htmlspecialchars($messagesBackgroundImageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Current Messages background" data-message-background-thumbnail><?php else: ?><div class="messages-background-thumbnail is-empty" data-message-background-thumbnail>No image selected</div><?php endif; ?>
                    <input type="hidden" name="MAX_FILE_SIZE" value="5242880">
                    <input id="messages-background-image" type="file" name="background_image" accept="image/jpeg,image/png,image/webp,image/gif" data-message-background-image>
                </div>
                <?php if ($appearanceError !== ''): ?><p class="messages-customization-status is-error" role="alert"><?= htmlspecialchars($appearanceError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                <?php if (isset($_GET['appearance_saved'])): ?><p class="messages-customization-status" role="status">Background saved.</p><?php endif; ?>
                <?php if (isset($_GET['appearance_reset'])): ?><p class="messages-customization-status" role="status">Default background restored.</p><?php endif; ?>
                <div class="messages-customization-actions">
                    <button type="submit" name="action" value="update_messages_background">Save</button>
                    <button type="submit" class="button-secondary" name="action" value="reset_messages_background" formnovalidate>Reset</button>
                </div>
            </form>
        </aside>
        <aside class="conversation-profile" id="conversation-profile" data-conversation-profile aria-label="Conversation profile" hidden>
            <?php if ($selectedFriend): ?>
                <header class="conversation-profile-header">
                    <h2>Profile</h2>
                    <button type="button" aria-label="Close friend profile" title="Close friend profile" data-conversation-profile-close>&times;</button>
                </header>
                <div class="conversation-profile-identity">
                    <span class="conversation-profile-avatar" aria-hidden="true"><span><?= htmlspecialchars($selectedInitial, ENT_QUOTES, 'UTF-8') ?></span><?php if ($selectedAvatar): ?><img src="<?= htmlspecialchars($selectedAvatar, ENT_QUOTES, 'UTF-8') ?>" alt=""><?php endif; ?></span>
                    <div class="conversation-profile-name-row" data-nickname-display>
                        <a href="profile.php?id=<?= $selectedId ?>" data-selected-friend-name><?= htmlspecialchars($selectedDisplayName, ENT_QUOTES, 'UTF-8') ?></a>
                        <button type="button" data-nickname-edit aria-label="Edit nickname" title="Edit nickname">&#9998;</button>
                    </div>
                    <form class="conversation-nickname-form" method="post" action="messages.php?user=<?= $selectedId ?>" data-nickname-form data-nickname="<?= htmlspecialchars($selectedFriend['nickname'] ?? '', ENT_QUOTES, 'UTF-8') ?>" hidden>
                        <input type="hidden" name="action" value="update_nickname">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                        <label class="sr-only" for="friend-nickname">Nickname</label>
                        <input id="friend-nickname" name="nickname" type="text" maxlength="50" value="<?= htmlspecialchars($selectedDisplayName, ENT_QUOTES, 'UTF-8') ?>" placeholder="Friend's nickname" autocomplete="off">
                        <button type="submit" aria-label="Save nickname" title="Save nickname">&#10003;</button>
                        <button type="button" data-nickname-cancel aria-label="Cancel nickname editing" title="Cancel">&times;</button>
                    </form>
                    <p class="conversation-nickname-status" data-nickname-status role="status" aria-live="polite"></p>
                    <span class="conversation-profile-username" data-selected-friend-username<?= $selectedDisplayName === $selectedFriend['username'] ? ' hidden' : '' ?>>@<?= htmlspecialchars($selectedFriend['username'], ENT_QUOTES, 'UTF-8') ?></span>
                    <small data-conversation-activity><?= htmlspecialchars($activityLabel, ENT_QUOTES, 'UTF-8') ?></small>
                </div>
                <?php if (trim($selectedFriend['status_text'] ?? '') !== ''): ?><p class="conversation-profile-status"><?= htmlspecialchars($selectedFriend['status_text'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                <div class="conversation-profile-sections">
                    <div class="conversation-profile-section-row" aria-disabled="true">
                        <span>Media</span>
                        <strong>0</strong>
                    </div>
                    <details class="conversation-profile-section">
                        <summary><span>Shared links</span><strong><?= count($sharedLinks) ?></strong><span class="conversation-profile-chevron" aria-hidden="true">&gt;</span></summary>
                        <div class="conversation-profile-section-content">
                            <?php if (!$sharedLinks): ?>
                                <p>No links shared yet.</p>
                            <?php else: ?>
                                <ul class="conversation-shared-links">
                                    <?php foreach ($sharedLinks as $url): ?><li><a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?></a></li><?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </details>
                    <details class="conversation-profile-section">
                        <summary><span>Mutual friends</span><strong><?= count($mutualFriends) ?></strong><span class="conversation-profile-chevron" aria-hidden="true">&gt;</span></summary>
                        <div class="conversation-profile-section-content">
                            <?php if (!$mutualFriends): ?>
                                <p>No mutual friends.</p>
                            <?php else: ?>
                                <ul class="conversation-mutual-friends">
                                    <?php foreach ($mutualFriends as $mutualFriend): ?>
                                        <?php
                                        $mutualAvatar = trim($mutualFriend['avatar_path'] ?? '');
                                        if ($mutualAvatar !== '' && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $mutualAvatar)) {
                                            $mutualAvatar = str_starts_with($mutualAvatar, '/') ? $mutualAvatar : '../' . $mutualAvatar;
                                        } else { $mutualAvatar = ''; }
                                        $mutualInitial = mb_strtoupper(mb_substr($mutualFriend['username'], 0, 1));
                                        ?>
                                        <li><a href="profile.php?id=<?= (int) $mutualFriend['id'] ?>"><span class="conversation-mutual-avatar" aria-hidden="true"><span><?= htmlspecialchars($mutualInitial, ENT_QUOTES, 'UTF-8') ?></span><?php if ($mutualAvatar): ?><img src="<?= htmlspecialchars($mutualAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy"><?php endif; ?></span><span><?= htmlspecialchars($mutualFriend['username'], ENT_QUOTES, 'UTF-8') ?></span></a></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </details>
                </div>
                <div class="conversation-profile-actions">
                    <button type="button" class="conversation-profile-share" data-share-profile data-profile-url="profile.php?id=<?= $selectedId ?>">Share profile</button>
                    <p data-share-profile-status role="status" aria-live="polite"></p>
                </div>
            <?php endif; ?>
        </aside>
    </main>
</body>
</html>
