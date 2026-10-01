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

$spotifyRequest = static function (string $url) use ($conn, &$connection): array {
    [$connection, $accessToken] = spotifyAccessToken($conn, $connection);
    $response = spotifyHttpRequest('GET', $url, ['Authorization: Bearer ' . $accessToken]);
    if ($response['status'] === 401) {
        $connection['token_expires_at'] = '1970-01-01 00:00:00';
        [$connection, $accessToken] = spotifyAccessToken($conn, $connection);
        $response = spotifyHttpRequest('GET', $url, ['Authorization: Bearer ' . $accessToken]);
    }
    return $response;
};

$trackPayload = static function (array $track): array {
    $artists = implode(', ', array_values(array_filter(array_map(
        static fn (array $artist): string => trim((string) ($artist['name'] ?? '')),
        is_array($track['artists'] ?? null) ? $track['artists'] : []
    ))));
    $images = is_array($track['album']['images'] ?? null) ? $track['album']['images'] : [];
    $image = isset($images[0]['url']) && filter_var($images[0]['url'], FILTER_VALIDATE_URL)
        ? (string) $images[0]['url']
        : null;
    return [
        'id' => (string) ($track['id'] ?? ''),
        'name' => trim((string) ($track['name'] ?? '')),
        'artist' => $artists,
        'image' => $image,
        'url' => filter_var($track['external_urls']['spotify'] ?? null, FILTER_VALIDATE_URL)
            ? (string) $track['external_urls']['spotify']
            : null,
    ];
};

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $query = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
        if (mb_strlen($query, 'UTF-8') < 2 || mb_strlen($query, 'UTF-8') > 100) {
            http_response_code(422);
            echo json_encode(['error' => 'Search using 2 to 100 characters.']);
            exit;
        }
        $url = 'https://api.spotify.com/v1/search?' . http_build_query([
            'q' => $query,
            'type' => 'track',
            'limit' => 8,
        ], '', '&', PHP_QUERY_RFC3986);
        $response = $spotifyRequest($url);
        if ($response['status'] !== 200 || !is_array($response['data']['tracks']['items'] ?? null)) {
            throw new RuntimeException('Spotify search failed.');
        }
        $tracks = array_values(array_filter(array_map($trackPayload, $response['data']['tracks']['items']),
            static fn (array $track): bool => preg_match('/^[A-Za-z0-9]{22}$/', $track['id']) === 1 && $track['name'] !== ''
        ));
        echo json_encode(['tracks' => $tracks]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed.']);
        exit;
    }

    $input = json_decode((string) file_get_contents('php://input'), true);
    $token = is_array($input) && is_string($input['token'] ?? null) ? $input['token'] : '';
    if (!isset($_SESSION['settings_token']) || !hash_equals((string) $_SESSION['settings_token'], $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Please refresh the page and try again.']);
        exit;
    }

    $action = is_array($input) && is_string($input['action'] ?? null) ? $input['action'] : '';
    if ($action === 'remove') {
        $statement = mysqli_prepare($conn, 'UPDATE users SET spotify_track_id = NULL, profile_song_name = NULL, profile_song_artist = NULL, profile_song_image_url = NULL, profile_song_url = NULL WHERE id = ?');
        mysqli_stmt_bind_param($statement, 'i', $userId);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        echo json_encode(['saved' => true, 'track' => null]);
        exit;
    }

    $trackId = is_array($input) && is_string($input['track_id'] ?? null) ? trim($input['track_id']) : '';
    if ($action !== 'select' || !preg_match('/^[A-Za-z0-9]{22}$/', $trackId)) {
        http_response_code(422);
        echo json_encode(['error' => 'Choose a valid Spotify track.']);
        exit;
    }
    $response = $spotifyRequest('https://api.spotify.com/v1/tracks/' . rawurlencode($trackId));
    if ($response['status'] !== 200 || !is_array($response['data'])) {
        throw new RuntimeException('Spotify track lookup failed.');
    }
    $track = $trackPayload($response['data']);
    if ($track['id'] !== $trackId || $track['name'] === '') {
        throw new RuntimeException('Spotify returned an invalid track.');
    }
    $trackName = $track['name'];
    $trackArtist = $track['artist'];
    $trackImage = $track['image'];
    $trackUrl = $track['url'];
    $statement = mysqli_prepare($conn, 'UPDATE users SET spotify_track_id = ?, profile_song_name = ?, profile_song_artist = ?, profile_song_image_url = ?, profile_song_url = ? WHERE id = ?');
    mysqli_stmt_bind_param($statement, 'sssssi', $trackId, $trackName, $trackArtist, $trackImage, $trackUrl, $userId);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);
    echo json_encode(['saved' => true, 'track' => $track]);
} catch (Throwable $exception) {
    http_response_code(502);
    echo json_encode(['error' => 'Spotify could not complete that request right now.']);
}
