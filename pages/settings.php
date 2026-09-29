<?php

declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/db_connect.php';

$currentUserId = (int) $_SESSION['user_id'];
$_SESSION['settings_token'] ??= bin2hex(random_bytes(32));
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unblock') {
    $token = $_POST['token'] ?? '';
    $blockedUserId = filter_var($_POST['user_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

    if (!is_string($token) || !hash_equals($_SESSION['settings_token'], $token)) {
        http_response_code(403);
        $error = 'Please refresh the page and try again.';
    } elseif (!$blockedUserId || $blockedUserId === $currentUserId) {
        http_response_code(422);
        $error = 'Choose a valid blocked user.';
    } else {
        $statement = mysqli_prepare($conn, 'DELETE FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?');
        mysqli_stmt_bind_param($statement, 'ii', $currentUserId, $blockedUserId);
        mysqli_stmt_execute($statement);
        $unblocked = mysqli_stmt_affected_rows($statement) === 1;
        mysqli_stmt_close($statement);

        if ($unblocked) {
            header('Location: settings.php?unblocked=1');
            exit;
        }

        http_response_code(404);
        $error = 'That user is not in your blocked list.';
    }
}

$statement = mysqli_prepare($conn, 'SELECT u.id, u.username, u.avatar_path, b.created_at FROM user_blocks b JOIN users u ON u.id = b.blocked_id WHERE b.blocker_id = ? ORDER BY b.created_at DESC, u.username, u.id');
mysqli_stmt_bind_param($statement, 'i', $currentUserId);
mysqli_stmt_execute($statement);
$blockedUsers = mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC);
mysqli_stmt_close($statement);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Settings &middot; NexusSpace</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <script src="../assets/js/settings.js?v=<?= filemtime(__DIR__ . '/../assets/js/settings.js') ?>" defer></script>
    <script src="../assets/js/activity.js?v=<?= filemtime(__DIR__ . '/../assets/js/activity.js') ?>" defer></script>
</head>
<body data-activity-endpoint="../activity-ping.php">
    <main class="card settings-page">
        <header class="settings-header">
            <a href="../index.php" aria-label="Back to dashboard" title="Back to dashboard">&larr;</a>
            <h1>Settings</h1>
        </header>

        <?php if (isset($_GET['unblocked'])): ?>
            <p class="settings-notice" role="status">User unblocked. Your previous friendship, requests, messages, and visible content have been restored.</p>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <p class="error-box" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <section class="blocked-users" aria-labelledby="blocked-users-heading">
            <h2 id="blocked-users-heading">Blocked users</h2>
            <?php if (!$blockedUsers): ?>
                <p>You have not blocked anyone.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($blockedUsers as $blockedUser): ?>
                        <?php
                        $avatarPath = trim($blockedUser['avatar_path'] ?? '');
                        if ($avatarPath !== '' && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $avatarPath)) {
                            $avatarPath = str_starts_with($avatarPath, '/') ? $avatarPath : '../' . $avatarPath;
                        } else {
                            $avatarPath = '';
                        }
                        ?>
                        <li>
                            <span class="blocked-user-identity">
                                <span class="mini-avatar" aria-hidden="true">
                                    <span><?= htmlspecialchars(mb_strtoupper(mb_substr($blockedUser['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if ($avatarPath !== ''): ?><img src="<?= htmlspecialchars($avatarPath, ENT_QUOTES, 'UTF-8') ?>" alt=""><?php endif; ?>
                                </span>
                                <strong><?= htmlspecialchars($blockedUser['username'], ENT_QUOTES, 'UTF-8') ?></strong>
                            </span>
                            <form method="post" data-unblock-form>
                                <input type="hidden" name="action" value="unblock">
                                <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['settings_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="user_id" value="<?= (int) $blockedUser['id'] ?>">
                                <button type="submit">Unblock</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="settings-account" aria-labelledby="settings-account-heading">
            <h2 id="settings-account-heading">Account</h2>
            <a class="settings-logout" href="../logout.php">
                <img src="../assets/images/logout-door.png" alt="" aria-hidden="true">
                <span>Log out</span>
            </a>
        </section>
    </main>
</body>
</html>
