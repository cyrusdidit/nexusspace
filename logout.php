<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/db_connect.php';

session_start();

if (isset($_SESSION['user_id'])) {
    $userId = (int) $_SESSION['user_id'];
    $statement = mysqli_prepare($conn, "UPDATE users SET activity_state = 'offline', last_active_at = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($statement, 'i', $userId);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $cookieParameters = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $cookieParameters['path'],
        $cookieParameters['domain'],
        $cookieParameters['secure'],
        $cookieParameters['httponly']
    );
}

session_destroy();

header('Location: index.php');
exit;
