<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/steam.php';

$state = is_string($_GET['state'] ?? null) ? $_GET['state'] : '';
$authRequest = null;
if ($state !== '') {
    $statement = mysqli_prepare($conn, 'SELECT user_id, expires_at FROM steam_auth_requests WHERE state = ? LIMIT 1');
    mysqli_stmt_bind_param($statement, 's', $state);
    mysqli_stmt_execute($statement);
    $authRequest = mysqli_fetch_assoc(mysqli_stmt_get_result($statement)) ?: null;
    mysqli_stmt_close($statement);

    $statement = mysqli_prepare($conn, 'DELETE FROM steam_auth_requests WHERE state = ?');
    mysqli_stmt_bind_param($statement, 's', $state);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);
}

if (($_GET['openid_mode'] ?? '') === 'cancel') {
    header('Location: ' . steamSettingsUrl('cancelled'));
    exit;
}
if (!steamIsConfigured() || !$authRequest || strtotime((string) $authRequest['expires_at']) < time()) {
    header('Location: ' . steamSettingsUrl('invalid'));
    exit;
}

try {
    $steamId = steamVerifyOpenId($_GET, $state);
    $player = steamPlayerSummary($steamId);
    if (!$player) throw new RuntimeException('Steam profile unavailable.');
    $userId = (int) $authRequest['user_id'];

    $statement = mysqli_prepare($conn, 'SELECT user_id FROM steam_connections WHERE steam_id = ? AND user_id <> ? LIMIT 1');
    mysqli_stmt_bind_param($statement, 'si', $steamId, $userId);
    mysqli_stmt_execute($statement);
    $steamOwner = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
    mysqli_stmt_close($statement);
    if ($steamOwner) {
        header('Location: ' . steamSettingsUrl('in-use'));
        exit;
    }

    $personaName = trim((string) ($player['personaname'] ?? '')) ?: $steamId;
    $profileUrl = trim((string) ($player['profileurl'] ?? '')) ?: 'https://steamcommunity.com/profiles/' . $steamId . '/';
    $avatarUrl = trim((string) ($player['avatarfull'] ?? '')) ?: null;
    $statement = mysqli_prepare($conn, 'SELECT user_id FROM steam_connections WHERE user_id = ? LIMIT 1');
    mysqli_stmt_bind_param($statement, 'i', $userId);
    mysqli_stmt_execute($statement);
    $hasConnection = mysqli_fetch_assoc(mysqli_stmt_get_result($statement)) !== null;
    mysqli_stmt_close($statement);

    if ($hasConnection) {
        $statement = mysqli_prepare($conn, 'UPDATE steam_connections SET steam_id = ?, persona_name = ?, profile_url = ?, avatar_url = ?, current_game_id = NULL, current_game_name = NULL, current_game_started_at = NULL WHERE user_id = ?');
        mysqli_stmt_bind_param($statement, 'ssssi', $steamId, $personaName, $profileUrl, $avatarUrl, $userId);
    } else {
        $statement = mysqli_prepare($conn, 'INSERT INTO steam_connections (user_id, steam_id, persona_name, profile_url, avatar_url) VALUES (?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($statement, 'issss', $userId, $steamId, $personaName, $profileUrl, $avatarUrl);
    }
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);
    header('Location: ' . steamSettingsUrl('connected'));
} catch (Throwable $exception) {
    header('Location: ' . steamSettingsUrl('failed'));
}
exit;
