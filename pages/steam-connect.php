<?php

declare(strict_types=1);

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/steam.php';

if (!steamIsConfigured()) {
    header('Location: settings.php?steam=not-configured');
    exit;
}

$state = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
$userId = (int) $_SESSION['user_id'];
$expiresAt = date('Y-m-d H:i:s', time() + 600);
mysqli_query($conn, 'DELETE FROM steam_auth_requests WHERE expires_at < NOW()');
$statement = mysqli_prepare($conn, 'INSERT INTO steam_auth_requests (state, user_id, expires_at) VALUES (?, ?, ?)');
mysqli_stmt_bind_param($statement, 'sis', $state, $userId, $expiresAt);
mysqli_stmt_execute($statement);
mysqli_stmt_close($statement);

header('Location: ' . steamOpenIdUrl($state));
exit;
