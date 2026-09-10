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
    'SELECT username, email, registration_date FROM users WHERE id = ? LIMIT 1'
);
mysqli_stmt_bind_param($statement, 'i', $userId);
mysqli_stmt_execute($statement);
$result = mysqli_stmt_get_result($statement);
$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($statement);

if (!$user) {
    http_response_code(404);
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
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($user['username'] ?? 'Profile not found', ENT_QUOTES, 'UTF-8') ?> · NexusSpace</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body>
    <main class="card">
        <?php if (!$user): ?>
            <h1>Profile not found</h1>
            <p>This user does not exist.</p>
        <?php else: ?>
        <div class="avatar-placeholder" aria-hidden="true">
            <?= htmlspecialchars(strtoupper(substr($user['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?>
        </div>
        <div class="profile-name-row">
            <h1><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></h1>
            <?php if (!$isOwnProfile): ?>
                <form method="post" action="profile.php?id=<?= $userId ?>">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['friend_request_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php if ($friendState === 'received'): ?>
                        <button type="submit" name="action" value="accept">Accept</button>
                        <button class="button-secondary" type="submit" name="action" value="decline">Decline</button>
                    <?php else: ?>
                        <button type="submit" name="action" value="befriend"<?= $friendState !== 'none' ? ' disabled' : '' ?>><?= ['none' => 'Befriend', 'sent' => 'Request sent', 'friends' => 'Friends'][$friendState] ?></button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
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
        <?php endif; ?>

        <p>
            <a class="button" href="../index.php">Home</a>
            <a class="button button-secondary" href="../logout.php">Log out</a>
        </p>
    </main>
</body>
</html>
