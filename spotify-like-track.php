<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Sign in required.']);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true);
$token = is_array($input) && is_string($input['token'] ?? null) ? $input['token'] : '';
$trackId = is_array($input) && is_string($input['track_id'] ?? null) ? trim($input['track_id']) : '';
if (!isset($_SESSION['spotify_action_token']) || !hash_equals((string) $_SESSION['spotify_action_token'], $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Please refresh the page and try again.']);
    exit;
}
if (!preg_match('/^[A-Za-z0-9]{22}$/', $trackId)) {
    http_response_code(422);
    echo json_encode(['error' => 'That Spotify track is unavailable.']);
    exit;
}

require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/spotify.php';

$userId = (int) $_SESSION['user_id'];
$statement = mysqli_prepare($conn, 'SELECT * FROM spotify_connections WHERE user_id = ? LIMIT 1');
mysqli_stmt_bind_param($statement, 'i', $userId);
mysqli_stmt_execute($statement);
$connection = mysqli_fetch_assoc(mysqli_stmt_get_result($statement)) ?: null;
mysqli_stmt_close($statement);

if (!$connection) {
    http_response_code(409);
    echo json_encode(['error' => 'Connect Spotify in Settings first.']);
    exit;
}
if (!in_array('user-library-modify', preg_split('/\s+/', trim((string) $connection['scopes'])) ?: [], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Reconnect Spotify in Settings to enable Liked Songs.']);
    exit;
}

try {
    [$connection, $accessToken] = spotifyAccessToken($conn, $connection);
    $url = 'https://api.spotify.com/v1/me/library?' . http_build_query(['uris' => 'spotify:track:' . $trackId], '', '&', PHP_QUERY_RFC3986);
    $removeResponse = spotifyHttpRequest('DELETE', $url, ['Authorization: Bearer ' . $accessToken]);
    if ($removeResponse['status'] === 401) {
        $connection['token_expires_at'] = '1970-01-01 00:00:00';
        [$connection, $accessToken] = spotifyAccessToken($conn, $connection);
        $removeResponse = spotifyHttpRequest('DELETE', $url, ['Authorization: Bearer ' . $accessToken]);
    }
    if ($removeResponse['status'] !== 200) throw new RuntimeException('Spotify rejected the library update.');

    $saveResponse = spotifyHttpRequest('PUT', $url, ['Authorization: Bearer ' . $accessToken]);
    if ($saveResponse['status'] === 401) {
        $connection['token_expires_at'] = '1970-01-01 00:00:00';
        [$connection, $accessToken] = spotifyAccessToken($conn, $connection);
        $saveResponse = spotifyHttpRequest('PUT', $url, ['Authorization: Bearer ' . $accessToken]);
    }
    if ($saveResponse['status'] !== 200) throw new RuntimeException('Spotify rejected the save request.');
    echo json_encode(['saved' => true, 'moved_to_top' => true]);
} catch (Throwable $exception) {
    http_response_code(502);
    echo json_encode(['error' => 'Spotify could not save that song right now.']);
}
