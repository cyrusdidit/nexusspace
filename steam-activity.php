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
require_once __DIR__ . '/includes/steam.php';

if (!steamIsConfigured()) {
    echo json_encode(['active' => false, 'connected' => false]);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$statement = mysqli_prepare($conn, 'SELECT * FROM steam_connections WHERE user_id = ? LIMIT 1');
mysqli_stmt_bind_param($statement, 'i', $userId);
mysqli_stmt_execute($statement);
$connection = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
mysqli_stmt_close($statement);

if (!$connection) {
    echo json_encode(['active' => false, 'connected' => false]);
    exit;
}

try {
    $player = steamPlayerSummary((string) $connection['steam_id']);
    if (!$player) throw new RuntimeException('Steam profile unavailable.');

    $gameId = trim((string) ($player['gameid'] ?? '')) ?: null;
    $gameName = trim((string) ($player['gameextrainfo'] ?? '')) ?: null;
    $active = $gameId !== null && $gameName !== null;
    $startedAt = null;
    if ($active) {
        $startedAt = $gameId === ($connection['current_game_id'] ?? null) && !empty($connection['current_game_started_at'])
            ? (string) $connection['current_game_started_at']
            : date('Y-m-d H:i:s');
    }

    $personaName = trim((string) ($player['personaname'] ?? '')) ?: (string) $connection['persona_name'];
    $profileUrl = trim((string) ($player['profileurl'] ?? '')) ?: (string) $connection['profile_url'];
    $avatarUrl = trim((string) ($player['avatarfull'] ?? '')) ?: null;
    $statement = mysqli_prepare($conn, 'UPDATE steam_connections SET persona_name = ?, profile_url = ?, avatar_url = ?, current_game_id = ?, current_game_name = ?, current_game_started_at = ?, activity_updated_at = NOW() WHERE user_id = ?');
    mysqli_stmt_bind_param($statement, 'ssssssi', $personaName, $profileUrl, $avatarUrl, $gameId, $gameName, $startedAt, $userId);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);

    echo json_encode([
        'active' => $active,
        'connected' => true,
        'name' => $gameName,
        'game_id' => $gameId,
        'image' => $active ? steamGameImageUrl((string) $gameId) : null,
        'elapsed_seconds' => $active ? max(0, time() - (strtotime((string) $startedAt) ?: time())) : 0,
    ]);
} catch (Throwable $exception) {
    http_response_code(502);
    echo json_encode(['active' => false, 'connected' => true, 'error' => 'Steam activity is temporarily unavailable.']);
}
