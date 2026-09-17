<?php

declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/db_connect.php';

$userId = isset($_GET['id'])
    ? filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : (int) $_SESSION['user_id'];
$userId = $userId === false ? 0 : $userId;
$isOwnProfile = $userId === (int) $_SESSION['user_id'];
$statement = mysqli_prepare(
    $conn,
    'SELECT username, email, registration_date, avatar_path, bio FROM users WHERE id = ? LIMIT 1'
);
mysqli_stmt_bind_param($statement, 'i', $userId);
mysqli_stmt_execute($statement);
$result = mysqli_stmt_get_result($statement);
$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($statement);

if (!$user) {
    http_response_code(404);
}
$avatarPath = trim($user['avatar_path'] ?? '');
if ($avatarPath !== '' && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $avatarPath)) {
    $avatarPath = str_starts_with($avatarPath, '/') ? $avatarPath : '../' . $avatarPath;
} else {
    $avatarPath = '';
}

$_SESSION['friend_request_token'] ??= bin2hex(random_bytes(32));
$friendState = 'none';
$friendError = '';
$currentUserId = (int) $_SESSION['user_id'];

$readFriendState = static function () use ($conn, $currentUserId, $userId): string {
    $statement = mysqli_prepare($conn, 'SELECT id FROM friends WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?) LIMIT 1');
    mysqli_stmt_bind_param($statement, 'iiii', $currentUserId, $userId, $userId, $currentUserId);
    mysqli_stmt_execute($statement);
    $isFriend = mysqli_num_rows(mysqli_stmt_get_result($statement)) > 0;
    mysqli_stmt_close($statement);
    if ($isFriend) return 'friends';

    $statement = mysqli_prepare($conn, "SELECT sender_id, status FROM friend_requests WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)) AND status IN ('pending', 'accepted') ORDER BY status = 'accepted' DESC LIMIT 1");
    mysqli_stmt_bind_param($statement, 'iiii', $currentUserId, $userId, $userId, $currentUserId);
    mysqli_stmt_execute($statement);
    $request = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
    mysqli_stmt_close($statement);
    if (!$request) return 'none';
    if ($request['status'] === 'accepted') return 'friends';
    return (int) $request['sender_id'] === $currentUserId ? 'sent' : 'received';
};

if ($user && !$isOwnProfile) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['token'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['friend_request_token'], $token)) {
            http_response_code(403);
            $friendError = 'Please refresh the page and try again.';
        } elseif (in_array($_POST['action'] ?? '', ['befriend', 'accept', 'decline'], true)) {
            $action = $_POST['action'];
            mysqli_begin_transaction($conn);
            try {
                // Lock both accounts in the same order to serialize requests between them.
                $lock = mysqli_prepare($conn, 'SELECT id FROM users WHERE id IN (?, ?) ORDER BY id FOR UPDATE');
                mysqli_stmt_bind_param($lock, 'ii', $currentUserId, $userId);
                mysqli_stmt_execute($lock);
                mysqli_stmt_store_result($lock);
                mysqli_stmt_close($lock);
                $state = $readFriendState();
                if ($action === 'befriend' && $state === 'none') {
                    $statement = mysqli_prepare($conn, "INSERT INTO friend_requests (sender_id, receiver_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE status = 'pending', created_at = CURRENT_TIMESTAMP");
                    mysqli_stmt_bind_param($statement, 'ii', $currentUserId, $userId);
                    mysqli_stmt_execute($statement);
                    mysqli_stmt_close($statement);
                }
                if (in_array($action, ['accept', 'decline'], true) && $state === 'received') {
                    $decision = $action === 'accept' ? 'accepted' : 'declined';
                    $statement = mysqli_prepare($conn, "UPDATE friend_requests SET status = ? WHERE sender_id = ? AND receiver_id = ? AND status = 'pending'");
                    mysqli_stmt_bind_param($statement, 'sii', $decision, $userId, $currentUserId);
                    mysqli_stmt_execute($statement);
                    $updated = mysqli_stmt_affected_rows($statement);
                    mysqli_stmt_close($statement);
                    if ($action === 'accept' && $updated === 1) {
                        $statement = mysqli_prepare($conn, 'INSERT INTO friends (user_id, friend_id) VALUES (?, ?), (?, ?) ON DUPLICATE KEY UPDATE friend_id = VALUES(friend_id)');
                        mysqli_stmt_bind_param($statement, 'iiii', $currentUserId, $userId, $userId, $currentUserId);
                        mysqli_stmt_execute($statement);
                        mysqli_stmt_close($statement);
                    }
                }
                mysqli_commit($conn);
                header('Location: profile.php?id=' . $userId);
                exit;
            } catch (mysqli_sql_exception $exception) {
                mysqli_rollback($conn);
                $friendError = 'Could not update the friend request. Please try again.';
            }
        }
    }
    $friendState = $readFriendState();
}
$profilePosts = [];
$topFriends = [];
if ($user) {
    $statement = mysqli_prepare($conn, "SELECT p.content, p.visibility, p.created_at FROM posts p WHERE p.user_id = ? AND (p.visibility = 'public' OR p.user_id = ? OR EXISTS (SELECT 1 FROM friends f WHERE (f.user_id = ? AND f.friend_id = p.user_id) OR (f.friend_id = ? AND f.user_id = p.user_id))) ORDER BY p.created_at DESC, p.id DESC LIMIT 50");
    mysqli_stmt_bind_param($statement, 'iiii', $userId, $currentUserId, $currentUserId, $currentUserId);
    mysqli_stmt_execute($statement);
    $profilePosts = mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC);
    mysqli_stmt_close($statement);
    $statement = mysqli_prepare($conn, 'SELECT u.id, u.username, u.avatar_path FROM friends f JOIN users u ON u.id = f.friend_id WHERE f.user_id = ? AND f.top_eight_position BETWEEN 1 AND 8 ORDER BY f.top_eight_position, u.id LIMIT 8');
    mysqli_stmt_bind_param($statement, 'i', $userId);
    mysqli_stmt_execute($statement);
    $topFriends = mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC);
    mysqli_stmt_close($statement);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($user['username'] ?? 'Profile not found', ENT_QUOTES, 'UTF-8') ?> · NexusSpace</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body class="profile-page">
    <main class="card profile-sheet">
        <?php if (!$user): ?>
            <h1>Profile not found</h1>
            <p>This user does not exist.</p>
        <?php else: ?>
        <div class="profile-layout">
        <aside class="profile-sidebar">
        <div class="profile-cover" aria-hidden="true"></div>
        <section class="profile-identity" aria-label="Profile">
            <div class="profile-picture" aria-hidden="true">
                <span><?= htmlspecialchars(mb_strtoupper(mb_substr($user['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                <?php if ($avatarPath !== ''): ?><img src="<?= htmlspecialchars($avatarPath, ENT_QUOTES, 'UTF-8') ?>" alt=""><?php endif; ?>
            </div>
            <h1><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="profile-handle">@<?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php if (!$isOwnProfile): ?>
                <form class="profile-friend-actions" method="post" action="profile.php?id=<?= $userId ?>">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['friend_request_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php if ($friendState === 'received'): ?>
                        <button type="submit" name="action" value="accept">Accept</button>
                        <button class="button-secondary" type="submit" name="action" value="decline">Decline</button>
                    <?php else: ?>
                        <button type="submit" name="action" value="befriend"<?= $friendState !== 'none' ? ' disabled' : '' ?>><?= ['none' => 'Befriend', 'sent' => 'Request sent', 'friends' => 'Friends'][$friendState] ?></button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </section>
        <div class="profile-music-placeholder" aria-label="Profile music"><span aria-hidden="true">&#9835;</span></div>
        <section class="profile-bio-section" aria-label="Bio">
            <?php if (trim($user['bio'] ?? '') !== ''): ?><p class="profile-bio"><?= htmlspecialchars($user['bio'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        </section>
        <section class="profile-top-eight" aria-labelledby="profile-top-eight-heading">
            <h2 id="profile-top-eight-heading">Top 8 Friends</h2>
            <ul class="profile-top-eight-list">
                <?php foreach ($topFriends as $friend): ?>
                    <?php
                    $friendAvatar = trim($friend['avatar_path'] ?? '');
                    if ($friendAvatar !== '' && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $friendAvatar)) {
                        $friendAvatar = str_starts_with($friendAvatar, '/') ? $friendAvatar : '../' . $friendAvatar;
                    } else { $friendAvatar = ''; }
                    ?>
                    <li><a href="profile.php?id=<?= (int) $friend['id'] ?>">
                        <span class="post-avatar" aria-hidden="true"><span><?= htmlspecialchars(mb_strtoupper(mb_substr($friend['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span><?php if ($friendAvatar): ?><img src="<?= htmlspecialchars($friendAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy"><?php endif; ?></span>
                        <span><?= htmlspecialchars($friend['username'], ENT_QUOTES, 'UTF-8') ?></span>
                    </a></li>
                <?php endforeach; ?>
            </ul>
            <?php if (!$topFriends): ?><p>No Top 8 selected yet.</p><?php endif; ?>
        </section>
        <div class="profile-account-details">
        <?php if ($friendError !== ''): ?>
            <p class="error-box" role="alert"><?= htmlspecialchars($friendError, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <dl class="profile-details">
            <?php if ($isOwnProfile): ?>
            <dt>Email</dt>
            <dd><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></dd>
            <?php endif; ?>

            <dt>Member since</dt>
            <dd><?= htmlspecialchars(date('F j, Y', strtotime($user['registration_date'])), ENT_QUOTES, 'UTF-8') ?></dd>
        </dl>
        </div>
        </aside>
        <section class="profile-posts" aria-labelledby="profile-posts-heading">
            <h2 id="profile-posts-heading">Posts</h2>
            <?php if (!$profilePosts): ?><p class="post-empty">No posts to show yet.</p><?php endif; ?>
            <?php foreach ($profilePosts as $post): ?>
                <article class="post-card">
                    <header class="post-header">
                        <span class="post-author">
                            <span class="post-avatar" aria-hidden="true"><span><?= htmlspecialchars(mb_strtoupper(mb_substr($user['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span><?php if ($avatarPath): ?><img src="<?= htmlspecialchars($avatarPath, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy"><?php endif; ?></span>
                            <span><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></span>
                        </span>
                        <div class="post-meta"><time datetime="<?= htmlspecialchars(str_replace(' ', 'T', $post['created_at']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(date('M j, Y \a\t H:i', strtotime($post['created_at'])), ENT_QUOTES, 'UTF-8') ?></time><?php if ($isOwnProfile): ?> &middot; <?= $post['visibility'] === 'public' ? 'Public' : 'Friends Only' ?><?php endif; ?></div>
                    </header>
                    <p class="post-content"><?= htmlspecialchars($post['content'], ENT_QUOTES, 'UTF-8') ?></p>
                </article>
            <?php endforeach; ?>
        </section>
        </div>
        <?php endif; ?>

        <p class="profile-footer">
            <a class="button" href="../index.php">Home</a>
            <a class="button button-secondary" href="../logout.php">Log out</a>
        </p>
    </main>
</body>
</html>
