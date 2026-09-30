<?php

declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/spotify.php';
require_once __DIR__ . '/../includes/db_connect.php';

if (!spotifyIsConfigured()) {
    header('Location: settings.php?spotify=not-configured');
    exit;
}

$state = spotifyBase64Url(random_bytes(24));
$verifier = spotifyBase64Url(random_bytes(64));
$userId = (int) $_SESSION['user_id'];
$expiresAt = date('Y-m-d H:i:s', time() + 600);
mysqli_query($conn, 'DELETE FROM spotify_oauth_requests WHERE expires_at < NOW()');
$statement = mysqli_prepare($conn, 'INSERT INTO spotify_oauth_requests (state, user_id, code_verifier, expires_at) VALUES (?, ?, ?, ?)');
mysqli_stmt_bind_param($statement, 'siss', $state, $userId, $verifier, $expiresAt);
mysqli_stmt_execute($statement);
mysqli_stmt_close($statement);

$config = spotifyConfig();
$query = http_build_query([
    'client_id' => $config['client_id'],
    'response_type' => 'code',
    'redirect_uri' => $config['redirect_uri'],
    'state' => $state,
    'scope' => SPOTIFY_SCOPES,
    'code_challenge_method' => 'S256',
    'code_challenge' => spotifyBase64Url(hash('sha256', $verifier, true)),
], '', '&', PHP_QUERY_RFC3986);

header('Location: https://accounts.spotify.com/authorize?' . $query);
exit;
