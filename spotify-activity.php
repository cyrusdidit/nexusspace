<?php

declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['active' => false, 'error' => 'Sign in required.']);
    exit;
}

require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/spotify.php';

if (!spotifyIsConfigured()) {
    echo json_encode(['active' => false, 'connected' => false]);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$statement = mysqli_prepare($conn, 'SELECT * FROM spotify_connections WHERE user_id = ? LIMIT 1');
mysqli_stmt_bind_param($statement, 'i', $userId);
mysqli_stmt_execute($statement);
$connection = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
mysqli_stmt_close($statement);

if (!$connection) {
    echo json_encode(['active' => false, 'connected' => false]);
    exit;
}

try {
    [$connection, $accessToken] = spotifyAccessToken($conn, $connection);
    $response = spotifyHttpRequest('GET', 'https://api.spotify.com/v1/me/player/currently-playing', ['Authorization: Bearer ' . $accessToken]);
    if ($response['status'] === 401) {
        $connection['token_expires_at'] = '1970-01-01 00:00:00';
        [$connection, $accessToken] = spotifyAccessToken($conn, $connection);
        $response = spotifyHttpRequest('GET', 'https://api.spotify.com/v1/me/player/currently-playing', ['Authorization: Bearer ' . $accessToken]);
    }

    $data = $response['data'];
    $active = $response['status'] === 200 && is_array($data) && ($data['is_playing'] ?? false) === true && is_array($data['item'] ?? null);
    $itemType = $active ? (($data['currently_playing_type'] ?? 'track') === 'episode' ? 'episode' : 'track') : null;
    $item = $active ? $data['item'] : [];
    $itemId = $active ? (string) ($item['id'] ?? '') : null;
    $itemName = $active ? (string) ($item['name'] ?? '') : null;
    $artistName = null;
    if ($active && $itemType === 'track') {
        $artistName = implode(', ', array_values(array_filter(array_map(static fn ($artist): string => (string) ($artist['name'] ?? ''), $item['artists'] ?? []))));
    } elseif ($active) {
        $artistName = (string) ($item['show']['name'] ?? 'Podcast');
    }
    $images = $itemType === 'track' ? ($item['album']['images'] ?? []) : ($item['images'] ?? []);
    $imageUrl = $active && isset($images[0]['url']) ? (string) $images[0]['url'] : null;
    $externalUrl = $active ? (string) ($item['external_urls']['spotify'] ?? '') : null;
    $isPlaying = $active ? 1 : 0;

    $spotifyUserId = (string) $connection['spotify_user_id'];
    $statement = mysqli_prepare($conn, 'UPDATE spotify_connections SET current_item_type = ?, current_item_id = ?, current_item_name = ?, current_artist_name = ?, current_image_url = ?, current_external_url = ?, is_playing = ?, playback_updated_at = NOW() WHERE spotify_user_id = ?');
    mysqli_stmt_bind_param($statement, 'ssssssis', $itemType, $itemId, $itemName, $artistName, $imageUrl, $externalUrl, $isPlaying, $spotifyUserId);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);

    echo json_encode([
        'active' => $active,
        'connected' => true,
        'type' => $itemType,
        'name' => $itemName,
        'artist' => $artistName,
        'image' => $imageUrl,
        'url' => $externalUrl,
    ]);
} catch (Throwable $exception) {
    http_response_code(502);
    echo json_encode(['active' => false, 'connected' => true, 'error' => 'Spotify activity is temporarily unavailable.']);
}
