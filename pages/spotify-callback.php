<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/spotify.php';

$state = is_string($_GET['state'] ?? null) ? $_GET['state'] : '';
$code = is_string($_GET['code'] ?? null) ? $_GET['code'] : '';
$oauth = null;

if ($state !== '') {
    $statement = mysqli_prepare($conn, 'SELECT user_id, code_verifier, expires_at FROM spotify_oauth_requests WHERE state = ? LIMIT 1');
    mysqli_stmt_bind_param($statement, 's', $state);
    mysqli_stmt_execute($statement);
    $oauth = mysqli_fetch_assoc(mysqli_stmt_get_result($statement)) ?: null;
    mysqli_stmt_close($statement);

    $statement = mysqli_prepare($conn, 'DELETE FROM spotify_oauth_requests WHERE state = ?');
    mysqli_stmt_bind_param($statement, 's', $state);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);
}

if (isset($_GET['error'])) {
    header('Location: ' . spotifySettingsUrl('denied'));
    exit;
}

if (!spotifyIsConfigured() || !is_array($oauth) || strtotime((string) $oauth['expires_at']) < time() || $code === '') {
    header('Location: ' . spotifySettingsUrl('invalid'));
    exit;
}

try {
    $config = spotifyConfig();
    $tokenResponse = spotifyHttpRequest('POST', 'https://accounts.spotify.com/api/token', ['Content-Type: application/x-www-form-urlencoded'], [
        'client_id' => $config['client_id'],
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $config['redirect_uri'],
        'code_verifier' => (string) $oauth['code_verifier'],
    ]);
    if ($tokenResponse['status'] !== 200 || empty($tokenResponse['data']['access_token']) || empty($tokenResponse['data']['refresh_token'])) {
        throw new RuntimeException('Spotify did not return usable credentials.');
    }

    $accessToken = (string) $tokenResponse['data']['access_token'];
    $profileResponse = spotifyHttpRequest('GET', 'https://api.spotify.com/v1/me', ['Authorization: Bearer ' . $accessToken]);
    if ($profileResponse['status'] !== 200 || empty($profileResponse['data']['id'])) {
        throw new RuntimeException('Spotify profile information was unavailable.');
    }

    $userId = (int) $oauth['user_id'];
    $spotifyUserId = (string) $profileResponse['data']['id'];
    $displayName = trim((string) ($profileResponse['data']['display_name'] ?? '')) ?: null;
    $encryptedAccess = spotifyEncrypt($accessToken);
    $encryptedRefresh = spotifyEncrypt((string) $tokenResponse['data']['refresh_token']);
    $expiresAt = date('Y-m-d H:i:s', time() + max(60, (int) ($tokenResponse['data']['expires_in'] ?? 3600)));
    $scopes = (string) ($tokenResponse['data']['scope'] ?? SPOTIFY_SCOPES);

    $statement = mysqli_prepare($conn, 'INSERT INTO spotify_connections (user_id, spotify_user_id, display_name, access_token, refresh_token, token_expires_at, scopes) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE spotify_user_id = VALUES(spotify_user_id), display_name = VALUES(display_name), access_token = VALUES(access_token), refresh_token = VALUES(refresh_token), token_expires_at = VALUES(token_expires_at), scopes = VALUES(scopes), is_playing = 0, playback_updated_at = NULL');
    mysqli_stmt_bind_param($statement, 'issssss', $userId, $spotifyUserId, $displayName, $encryptedAccess, $encryptedRefresh, $expiresAt, $scopes);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);

    header('Location: ' . spotifySettingsUrl('connected'));
} catch (Throwable $exception) {
    header('Location: ' . spotifySettingsUrl('failed'));
}
exit;
