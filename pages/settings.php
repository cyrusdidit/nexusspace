<?php

declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/spotify.php';
require_once __DIR__ . '/../includes/steam.php';

$currentUserId = (int) $_SESSION['user_id'];
$_SESSION['settings_token'] ??= bin2hex(random_bytes(32));
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'disconnect_steam') {
    $token = $_POST['token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['settings_token'], $token)) {
        http_response_code(403);
        $error = 'Please refresh the page and try again.';
    } else {
        $statement = mysqli_prepare($conn, 'DELETE FROM steam_connections WHERE user_id = ?');
        mysqli_stmt_bind_param($statement, 'i', $currentUserId);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        header('Location: settings.php?steam=disconnected');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'disconnect_spotify') {
    $token = $_POST['token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['settings_token'], $token)) {
        http_response_code(403);
        $error = 'Please refresh the page and try again.';
    } else {
        $statement = mysqli_prepare($conn, 'DELETE FROM spotify_connections WHERE user_id = ?');
        mysqli_stmt_bind_param($statement, 'i', $currentUserId);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        header('Location: settings.php?spotify=disconnected');
        exit;
    }
}

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

$statement = mysqli_prepare($conn, 'SELECT spotify_user_id, display_name, scopes, is_playing, playback_updated_at FROM spotify_connections WHERE user_id = ? LIMIT 1');
mysqli_stmt_bind_param($statement, 'i', $currentUserId);
mysqli_stmt_execute($statement);
$spotifyConnection = mysqli_fetch_assoc(mysqli_stmt_get_result($statement)) ?: null;
mysqli_stmt_close($statement);
$spotifyNeedsReconnect = $spotifyConnection && !in_array('user-library-modify', preg_split('/\s+/', trim((string) $spotifyConnection['scopes'])) ?: [], true);

$spotifyNotices = [
    'connected' => 'Spotify connected. Your current track can now appear on your dashboard.',
    'disconnected' => 'Spotify disconnected.',
    'denied' => 'Spotify connection was cancelled.',
    'invalid' => 'That Spotify connection attempt expired. Please try again.',
    'failed' => 'Spotify could not be connected. Please try again.',
    'not-configured' => 'Spotify needs to be configured before it can be connected.',
];
$spotifyNotice = $spotifyNotices[$_GET['spotify'] ?? ''] ?? '';

$statement = mysqli_prepare($conn, 'SELECT steam_id, persona_name, profile_url FROM steam_connections WHERE user_id = ? LIMIT 1');
mysqli_stmt_bind_param($statement, 'i', $currentUserId);
mysqli_stmt_execute($statement);
$steamConnection = mysqli_fetch_assoc(mysqli_stmt_get_result($statement)) ?: null;
mysqli_stmt_close($statement);

$steamNotices = [
    'connected' => 'Steam connected. Open games can now appear on your dashboard.',
    'disconnected' => 'Steam disconnected.',
    'cancelled' => 'Steam sign-in was cancelled.',
    'invalid' => 'That Steam sign-in attempt expired. Please try again.',
    'failed' => 'Steam could not verify that account. Please try again.',
    'in-use' => 'That Steam account is already connected to another NexusSpace account.',
    'not-configured' => 'Steam needs to be configured before accounts can be connected.',
];
$steamNotice = $steamNotices[$_GET['steam'] ?? ''] ?? '';
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
        <?php if ($spotifyNotice !== ''): ?>
            <p class="settings-notice" role="status"><?= htmlspecialchars($spotifyNotice, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if ($steamNotice !== ''): ?>
            <p class="settings-notice" role="status"><?= htmlspecialchars($steamNotice, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <p class="error-box" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <section class="settings-integration" aria-labelledby="spotify-heading">
            <h2 id="spotify-heading">Spotify</h2>
            <div class="settings-integration-row">
                <div class="settings-integration-copy">
                    <?php if ($spotifyConnection): ?>
                        <strong><?= htmlspecialchars($spotifyConnection['display_name'] ?: $spotifyConnection['spotify_user_id'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <span><?= $spotifyNeedsReconnect ? 'Reconnect to enable adding songs to Liked Songs.' : 'Connected' ?></span>
                    <?php elseif (spotifyIsConfigured()): ?>
                        <strong>Share what you are listening to</strong>
                        <span>Your current track appears while Spotify is playing.</span>
                    <?php else: ?>
                        <strong>Spotify setup required</strong>
                        <span>Add your Spotify app credentials to the local environment file.</span>
                    <?php endif; ?>
                </div>
                <?php if ($spotifyConnection): ?>
                    <div class="settings-integration-actions">
                        <?php if ($spotifyNeedsReconnect): ?><a class="button spotify-connect-button" href="spotify-connect.php">Reconnect Spotify</a><?php endif; ?>
                        <form method="post">
                            <input type="hidden" name="action" value="disconnect_spotify">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['settings_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit">Disconnect</button>
                        </form>
                    </div>
                <?php elseif (spotifyIsConfigured()): ?>
                    <a class="button spotify-connect-button" href="spotify-connect.php">Connect Spotify</a>
                <?php endif; ?>
            </div>
        </section>

        <section class="settings-integration" aria-labelledby="steam-heading">
            <h2 id="steam-heading">Steam</h2>
            <?php if ($steamConnection): ?>
                <div class="settings-integration-row">
                    <div class="settings-integration-copy">
                        <strong><?= htmlspecialchars($steamConnection['persona_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <a href="<?= htmlspecialchars($steamConnection['profile_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">View Steam profile</a>
                    </div>
                    <form method="post">
                        <input type="hidden" name="action" value="disconnect_steam">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['settings_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit">Disconnect</button>
                    </form>
                </div>
            <?php elseif (steamIsConfigured()): ?>
                <div class="settings-integration-row">
                    <div class="settings-integration-copy">
                        <strong>Verify your Steam account</strong>
                        <span>Steam signs you in and returns only your verified SteamID.</span>
                    </div>
                    <a class="button spotify-connect-button" href="steam-connect.php">Sign in with Steam</a>
                </div>
            <?php else: ?>
                <div class="settings-integration-copy">
                    <strong>Steam setup required</strong>
                    <span>Add the server's Steam Web API key to the local environment file.</span>
                </div>
            <?php endif; ?>
        </section>

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
