<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Sign in to play profile songs.']);
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
    echo json_encode(['error' => 'Connect Spotify in settings to hear profile songs.', 'reconnect' => true]);
    exit;
}

$requiredScopes = ['streaming', 'user-read-email', 'user-read-private', 'user-modify-playback-state'];
$scopes = preg_split('/\s+/', trim((string) $connection['scopes'])) ?: [];
if (array_diff($requiredScopes, $scopes)) {
    http_response_code(409);
    echo json_encode(['error' => 'Reconnect Spotify in settings to enable profile-song playback.', 'reconnect' => true]);
    exit;
}

try {
    [$connection, $accessToken] = spotifyAccessToken($conn, $connection);
} catch (Throwable $error) {
    http_response_code(502);
    echo json_encode(['error' => $error->getMessage()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['access_token' => $accessToken]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input) || !isset($input['token']) || !hash_equals((string) ($_SESSION['settings_token'] ?? ''), (string) $input['token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Your session expired. Refresh and try again.']);
    exit;
}

$trackId = trim((string) ($input['track_id'] ?? ''));
$deviceId = trim((string) ($input['device_id'] ?? ''));
if (!preg_match('/^[A-Za-z0-9]{22}$/', $trackId) || $deviceId === '') {
    http_response_code(422);
    echo json_encode(['error' => 'The profile song could not be played.']);
    exit;
}

$curl = curl_init('https://api.spotify.com/v1/me/player/play?' . http_build_query(['device_id' => $deviceId], '', '&', PHP_QUERY_RFC3986));
curl_setopt_array($curl, [
    CURLOPT_CUSTOMREQUEST => 'PUT',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['uris' => ['spotify:track:' . $trackId], 'position_ms' => 0]),
]);
$body = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$error = curl_error($curl);
curl_close($curl);

if ($body === false || $status < 200 || $status >= 300) {
    http_response_code($status === 403 ? 403 : 502);
    $message = $status === 403
        ? 'Spotify Premium is required to play profile songs here.'
        : ($error !== '' ? $error : 'Spotify could not start the profile song.');
    echo json_encode(['error' => $message]);
    exit;
}

echo json_encode(['playing' => true]);
