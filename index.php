<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/db_connect.php';

session_start();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexusSpace</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <main>
        <h1>NexusSpace</h1>
        <?php if (isset($_SESSION['user_id'])): ?>
            <p>Signed in as <?= htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') ?>.</p>
            <p>
                <a class="button" href="pages/profile.php">My profile</a>
                <a class="button button-secondary" href="logout.php">Log out</a>
            </p>
        <?php else: ?>
            <p>A place to make your space your own.</p>
            <p>
                <a class="button" href="pages/register.php">Create an account</a>
                <a class="button button-secondary" href="pages/login.php">Log in</a>
            </p>
        <?php endif; ?>
    </main>
</body>
</html>
