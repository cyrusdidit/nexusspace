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
$bioError = '';
$avatarError = '';
$bioDraft = (string) ($user['bio'] ?? '');
$_SESSION['profile_edit_token'] ??= bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_avatar') {
    $token = $_POST['token'] ?? '';
    $upload = $_FILES['avatar'] ?? null;
    if (!$user || !$isOwnProfile) {
        http_response_code(403);
        $avatarError = 'You can only change your own profile picture.';
    } elseif (!is_string($token) || !hash_equals($_SESSION['profile_edit_token'], $token)) {
        http_response_code(403);
        $avatarError = 'Please refresh the page and try again.';
    } elseif (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $avatarError = 'Choose an image to upload.';
    } elseif (($upload['size'] ?? 0) > 5 * 1024 * 1024) {
        $avatarError = 'Use an image smaller than 5 MB.';
    } else {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!isset($extensions[$mime]) || @getimagesize($upload['tmp_name']) === false) {
            $avatarError = 'Upload a JPG, PNG, WebP, or GIF image.';
        } else {
            $uploadDirectory = __DIR__ . '/../uploads/avatars';
            if ((!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true)) || !is_writable($uploadDirectory)) {
                $avatarError = 'The profile picture could not be saved.';
            } else {
                $filename = 'avatar-' . $currentUserId . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
                $destination = $uploadDirectory . '/' . $filename;
                if (!move_uploaded_file($upload['tmp_name'], $destination)) {
                    $avatarError = 'The profile picture could not be saved.';
                } else {
                    $storedPath = 'uploads/avatars/' . $filename;
                    $statement = mysqli_prepare($conn, 'UPDATE users SET avatar_path = ? WHERE id = ?');
                    mysqli_stmt_bind_param($statement, 'si', $storedPath, $currentUserId);
                    mysqli_stmt_execute($statement);
                    mysqli_stmt_close($statement);
                    header('Location: profile.php?id=' . $currentUserId);
                    exit;
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_bio') {
    $token = $_POST['token'] ?? '';
    $bioDraft = is_string($_POST['bio'] ?? null) ? trim($_POST['bio']) : '';
    $bioDraft = str_replace(["\r\n", "\r"], "\n", $bioDraft);
    if (!$user || !$isOwnProfile) {
        http_response_code(403);
        $bioError = 'You can only edit your own bio.';
    } elseif (!is_string($token) || !hash_equals($_SESSION['profile_edit_token'], $token)) {
        http_response_code(403);
        $bioError = 'Please refresh the page and try again.';
    } elseif (!mb_check_encoding($bioDraft, 'UTF-8') || mb_strlen($bioDraft, 'UTF-8') > 160) {
        $bioError = 'Use 160 characters or fewer.';
    } else {
        $statement = mysqli_prepare($conn, 'UPDATE users SET bio = ? WHERE id = ?');
        mysqli_stmt_bind_param($statement, 'si', $bioDraft, $currentUserId);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        header('Location: profile.php?id=' . $currentUserId . '&bio_saved=1');
        exit;
    }
}

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
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'update_bio') {
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
$temporaryProfileUsers = array_map(
    static fn (int $number): array => ['username' => 'TempUser' . $number],
    range(1, 10)
);
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
    <script src="../assets/js/profile-bio.js?v=<?= filemtime(__DIR__ . '/../assets/js/profile-bio.js') ?>" defer></script>
    <script src="../assets/js/profile-avatar.js?v=<?= filemtime(__DIR__ . '/../assets/js/profile-avatar.js') ?>" defer></script>
</head>
<body class="profile-page"<?= isset($_GET['bio_saved']) ? ' data-bio-saved="true"' : '' ?>>
    <main class="card profile-sheet">
        <?php if (!$user): ?>
            <h1>Profile not found</h1>
            <p>This user does not exist.</p>
        <?php else: ?>
        <div class="profile-layout">
        <aside class="profile-sidebar">
        <div class="profile-cover" aria-hidden="true"></div>
        <section class="profile-identity" aria-label="Profile">
            <?php if ($isOwnProfile): ?>
                <form class="profile-picture-upload" method="post" action="profile.php?id=<?= $userId ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_avatar">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['profile_edit_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input class="profile-picture-input" id="profile-picture-input" name="avatar" type="file" accept="image/jpeg,image/png,image/webp,image/gif">
                    <label class="profile-picture" for="profile-picture-input" title="Change profile picture">
                        <span aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($user['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($avatarPath !== ''): ?><img src="<?= htmlspecialchars($avatarPath, ENT_QUOTES, 'UTF-8') ?>" alt=""><?php endif; ?>
                        <span class="profile-picture-overlay" aria-hidden="true">!</span>
                    </label>
                </form>
            <?php else: ?>
                <div class="profile-picture" aria-hidden="true">
                    <span><?= htmlspecialchars(mb_strtoupper(mb_substr($user['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($avatarPath !== ''): ?><img src="<?= htmlspecialchars($avatarPath, ENT_QUOTES, 'UTF-8') ?>" alt=""><?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($avatarError): ?><p class="profile-avatar-error" role="alert"><?= htmlspecialchars($avatarError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
            <h1><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="profile-handle">@<?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></p>
            <div class="profile-music-placeholder" aria-label="Profile music"><span aria-hidden="true">&#9835;</span></div>
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
        <section class="profile-bio-section" aria-label="Bio">
            <?php if (trim($user['bio'] ?? '') !== ''): ?><p class="profile-bio"><?= htmlspecialchars($user['bio'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
            <?php if ($isOwnProfile): ?>
                <details class="profile-bio-editor"<?= $bioError ? ' open' : '' ?>>
                    <summary aria-label="Edit bio" title="Edit bio">!</summary>
                    <form method="post" action="profile.php?id=<?= $userId ?>">
                        <input type="hidden" name="action" value="update_bio">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['profile_edit_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <label class="sr-only" for="profile-bio-input">Bio</label>
                        <textarea id="profile-bio-input" name="bio" rows="3" maxlength="160" aria-describedby="bio-limit"><?= htmlspecialchars($bioDraft, ENT_QUOTES, 'UTF-8') ?></textarea>
                        <div class="profile-bio-actions"><small id="bio-limit"><?= mb_strlen($bioDraft, 'UTF-8') ?>/160</small><button type="submit" aria-label="Save bio" title="Save bio">&#10003;</button></div>
                        <?php if ($bioError): ?><p class="post-error" role="alert"><?= htmlspecialchars($bioError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                    </form>
                </details>
            <?php endif; ?>
        </section>
        <section class="profile-top-eight" aria-labelledby="profile-top-eight-heading">
            <div class="panel-heading">
                <h2 class="friends-link" id="profile-top-eight-heading"><?= $isOwnProfile ? 'My top 8 friends' : htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') . "'s top 8 friends" ?></h2>
                <?php if ($isOwnProfile): ?><a class="friends-reorder" href="friends.php#top-eight" aria-label="Edit Top 8 friends"><img src="../assets/images/arrows.png" alt=""></a><?php endif; ?>
            </div>
            <ol class="friends-list">
                <?php foreach ($topFriends as $friend): ?>
                    <?php
                    $friendAvatar = trim($friend['avatar_path'] ?? '');
                    if ($friendAvatar !== '' && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $friendAvatar)) {
                        $friendAvatar = str_starts_with($friendAvatar, '/') ? $friendAvatar : '../' . $friendAvatar;
                    } else { $friendAvatar = ''; }
                    ?>
                    <li><a class="top-friend-link" href="profile.php?id=<?= (int) $friend['id'] ?>">
                        <span class="post-avatar" aria-hidden="true"><span><?= htmlspecialchars(mb_strtoupper(mb_substr($friend['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span><?php if ($friendAvatar): ?><img src="<?= htmlspecialchars($friendAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy"><?php endif; ?></span>
                        <span><?= htmlspecialchars($friend['username'], ENT_QUOTES, 'UTF-8') ?></span>
                    </a></li>
                <?php endforeach; ?>
                <?php foreach (array_slice($temporaryProfileUsers, 0, max(0, 8 - count($topFriends))) as $temporaryFriend): ?>
                    <li class="temporary-profile-friend">
                        <span class="top-friend-link">
                            <span class="post-avatar" aria-hidden="true">T</span>
                            <span><?= htmlspecialchars($temporaryFriend['username'], ENT_QUOTES, 'UTF-8') ?></span>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
        <nav class="profile-sidebar-actions" aria-label="Profile actions">
            <a class="profile-icon-button" href="../index.php" aria-label="Back to dashboard" title="Back to dashboard">&larr;</a>
            <a class="profile-icon-button" href="coming-soon.php?feature=settings" aria-label="Settings" title="Settings">&#9881;</a>
        </nav>
        <?php if ($friendError !== ''): ?>
            <p class="error-box" role="alert"><?= htmlspecialchars($friendError, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        </aside>
        <section class="profile-posts" aria-labelledby="profile-posts-heading">
            <h2 id="profile-posts-heading"><?= $isOwnProfile ? 'My posts' : 'Posts' ?></h2>
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

    </main>
    <?php if ($user && $isOwnProfile): ?>
        <dialog class="avatar-crop-dialog" data-avatar-crop-dialog>
            <div class="avatar-crop-heading">
                <h2>Crop profile picture</h2>
                <button type="button" data-avatar-crop-cancel aria-label="Close crop editor">&times;</button>
            </div>
            <div class="avatar-crop-stage">
                <canvas width="512" height="512" data-avatar-crop-canvas aria-label="Profile picture crop preview"></canvas>
            </div>
            <label class="avatar-zoom-control" for="avatar-crop-zoom">
                <span>Zoom</span>
                <input id="avatar-crop-zoom" type="range" min="1" max="3" step="0.01" value="1" data-avatar-crop-zoom>
            </label>
            <div class="avatar-crop-actions">
                <button type="button" class="button-secondary" data-avatar-crop-cancel>Cancel</button>
                <button type="button" data-avatar-crop-save aria-label="Use cropped profile picture">&#10003;</button>
            </div>
        </dialog>
    <?php endif; ?>
</body>
</html>
