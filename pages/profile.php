<?php

declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/db_connect.php';

$userId = (int) $_SESSION['user_id'];
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
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?> · NexusSpace</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <main class="card">
        <div class="avatar-placeholder" aria-hidden="true">
            <?= htmlspecialchars(strtoupper(substr($user['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?>
        </div>
        <h1><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></h1>

        <dl class="profile-details">
            <dt>Email</dt>
            <dd><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></dd>

            <dt>Member since</dt>
            <dd><?= htmlspecialchars(date('F j, Y', strtotime($user['registration_date'])), ENT_QUOTES, 'UTF-8') ?></dd>
        </dl>

        <p>
            <a class="button" href="../index.php">Home</a>
            <a class="button button-secondary" href="../logout.php">Log out</a>
        </p>
    </main>
</body>
</html>
