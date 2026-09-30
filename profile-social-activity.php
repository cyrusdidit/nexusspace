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
require_once __DIR__ . '/includes/steam.php';

$currentUserId = (int) $_SESSION['user_id'];
$profileUserId = filter_var($_GET['user'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
if (!$profileUserId) {
    http_response_code(422);
    echo json_encode(['error' => 'Choose a valid profile.']);
    exit;
}

if (steamIsConfigured()) {
    $statement = mysqli_prepare($conn, 'SELECT steam_id, current_game_id, current_game_started_at, TIMESTAMPDIFF(SECOND, activity_updated_at, NOW()) AS activity_age_seconds FROM steam_connections WHERE user_id = ? LIMIT 1');
    mysqli_stmt_bind_param($statement, 'i', $profileUserId);
    mysqli_stmt_execute($statement);
    $steamConnection = mysqli_fetch_assoc(mysqli_stmt_get_result($statement)) ?: null;
    mysqli_stmt_close($statement);

    if ($steamConnection && (int) ($steamConnection['activity_age_seconds'] ?? 999) >= 10) {
        try {
            $player = steamPlayerSummary((string) $steamConnection['steam_id']);
            if ($player) {
                $gameId = trim((string) ($player['gameid'] ?? '')) ?: null;
                $gameName = trim((string) ($player['gameextrainfo'] ?? '')) ?: null;
                $startedAt = null;
                if ($gameId !== null && $gameName !== null) {
                    $startedAt = $gameId === ($steamConnection['current_game_id'] ?? null) && !empty($steamConnection['current_game_started_at'])
                        ? (string) $steamConnection['current_game_started_at']
                        : date('Y-m-d H:i:s');
                }
                $statement = mysqli_prepare($conn, 'UPDATE steam_connections SET current_game_id = ?, current_game_name = ?, current_game_started_at = ?, activity_updated_at = NOW() WHERE user_id = ?');
                mysqli_stmt_bind_param($statement, 'sssi', $gameId, $gameName, $startedAt, $profileUserId);
                mysqli_stmt_execute($statement);
                mysqli_stmt_close($statement);
            }
        } catch (Throwable $exception) {
            // Keep the last known activity if Steam is temporarily unavailable.
        }
    }
}

$statement = mysqli_prepare($conn, 'SELECT u.username, s.current_game_id, s.current_game_name, s.current_game_started_at, TIMESTAMPDIFF(SECOND, s.activity_updated_at, NOW()) AS game_activity_age_seconds, p.current_item_id, p.current_item_name, p.current_artist_name, p.current_image_url, p.current_external_url, p.is_playing, TIMESTAMPDIFF(SECOND, p.playback_updated_at, NOW()) AS music_activity_age_seconds FROM users u LEFT JOIN steam_connections s ON s.user_id = u.id LEFT JOIN spotify_connections p ON p.user_id = u.id WHERE u.id = ? AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_id = ? AND b.blocked_id = u.id) OR (b.blocker_id = u.id AND b.blocked_id = ?)) LIMIT 1');
mysqli_stmt_bind_param($statement, 'iii', $profileUserId, $currentUserId, $currentUserId);
mysqli_stmt_execute($statement);
$activity = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
mysqli_stmt_close($statement);

if (!$activity) {
    http_response_code(404);
    echo json_encode(['error' => 'Profile not found.']);
    exit;
}

$gameId = trim((string) ($activity['current_game_id'] ?? ''));
$gameName = trim((string) ($activity['current_game_name'] ?? ''));
$gameActive = $gameId !== '' && $gameName !== '' && (int) ($activity['game_activity_age_seconds'] ?? 999) <= 60;
$songName = trim((string) ($activity['current_item_name'] ?? ''));
$artistName = trim((string) ($activity['current_artist_name'] ?? ''));
$musicActive = (int) ($activity['is_playing'] ?? 0) === 1
    && $songName !== ''
    && (int) ($activity['music_activity_age_seconds'] ?? 999) <= 60;
echo json_encode([
    'music' => $musicActive ? [
        'id' => (string) ($activity['current_item_id'] ?? ''),
        'name' => $songName,
        'artist' => $artistName,
        'image' => filter_var($activity['current_image_url'] ?? null, FILTER_VALIDATE_URL) ? (string) $activity['current_image_url'] : null,
        'url' => filter_var($activity['current_external_url'] ?? null, FILTER_VALIDATE_URL) ? (string) $activity['current_external_url'] : null,
    ] : null,
    'game' => $gameActive ? [
        'name' => $gameName,
        'image' => preg_match('/^\d+$/', $gameId) ? 'https://cdn.akamai.steamstatic.com/steam/apps/' . $gameId . '/header.jpg' : null,
        'elapsed_seconds' => max(0, time() - (strtotime((string) $activity['current_game_started_at']) ?: time())),
    ] : null,
]);
