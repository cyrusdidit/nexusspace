<?php

declare(strict_types=1);

const USER_BACKGROUND_REGIONS = ['messages', 'profile_cover', 'profile_posts', 'dashboard_feed'];
const USER_BACKGROUND_DEFAULTS = [
    'messages' => '#f6fcff',
    'profile_cover' => '#238bc5',
    'profile_posts' => '#dff2ff',
    'dashboard_feed' => '#dff2ff',
];

function defaultUserBackgroundSettings(): array
{
    $settings = [];
    foreach (USER_BACKGROUND_REGIONS as $region) {
        $settings[$region] = [
            'region' => $region,
            'background_type' => 'color',
            'color_value' => USER_BACKGROUND_DEFAULTS[$region],
            'image_path' => null,
            'linked_region' => null,
        ];
    }
    return $settings;
}

function readUserBackgroundSettings(mysqli $conn, int $userId): array
{
    $settings = defaultUserBackgroundSettings();
    $statement = mysqli_prepare($conn, 'SELECT region, background_type, color_value, image_path, linked_region FROM user_backgrounds WHERE user_id = ?');
    mysqli_stmt_bind_param($statement, 'i', $userId);
    mysqli_stmt_execute($statement);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC);
    mysqli_stmt_close($statement);

    foreach ($rows as $row) {
        $region = $row['region'];
        if (!in_array($region, USER_BACKGROUND_REGIONS, true)) continue;
        $settings[$region] = [
            'region' => $region,
            'background_type' => in_array($row['background_type'], ['color', 'image', 'linked'], true) ? $row['background_type'] : 'color',
            'color_value' => preg_match('/^#[0-9a-f]{6}$/i', $row['color_value'] ?? '') ? strtolower($row['color_value']) : USER_BACKGROUND_DEFAULTS[$region],
            'image_path' => is_string($row['image_path']) && preg_match('~^uploads/backgrounds/[a-z0-9._-]+$~i', $row['image_path']) ? $row['image_path'] : null,
            'linked_region' => in_array($row['linked_region'], USER_BACKGROUND_REGIONS, true) ? $row['linked_region'] : null,
        ];
    }
    return $settings;
}

function saveUserBackgroundSetting(mysqli $conn, int $userId, string $region, string $type, ?string $color, ?string $imagePath, ?string $linkedRegion): void
{
    if (!in_array($region, USER_BACKGROUND_REGIONS, true)) throw new InvalidArgumentException('Unknown background region.');
    if (!in_array($type, ['color', 'image', 'linked'], true)) throw new InvalidArgumentException('Unknown background type.');
    if ($type === 'color' && !preg_match('/^#[0-9a-f]{6}$/i', $color ?? '')) throw new InvalidArgumentException('Invalid background color.');
    if ($type === 'image' && !preg_match('~^uploads/backgrounds/[a-z0-9._-]+$~i', $imagePath ?? '')) throw new InvalidArgumentException('Invalid background image path.');
    if ($type === 'linked' && (!in_array($linkedRegion, USER_BACKGROUND_REGIONS, true) || $linkedRegion === $region)) {
        throw new InvalidArgumentException('Invalid linked background region.');
    }

    $color = $type === 'color' ? strtolower((string) $color) : null;
    $imagePath = $type === 'image' ? $imagePath : null;
    $linkedRegion = $type === 'linked' ? $linkedRegion : null;
    $statement = mysqli_prepare($conn, 'INSERT INTO user_backgrounds (user_id, region, background_type, color_value, image_path, linked_region) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE background_type = VALUES(background_type), color_value = VALUES(color_value), image_path = VALUES(image_path), linked_region = VALUES(linked_region)');
    mysqli_stmt_bind_param($statement, 'isssss', $userId, $region, $type, $color, $imagePath, $linkedRegion);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);
}

function resolveUserBackground(array $settings, string $region): array
{
    if (!in_array($region, USER_BACKGROUND_REGIONS, true)) throw new InvalidArgumentException('Unknown background region.');
    $visited = [];
    $currentRegion = $region;

    while (!isset($visited[$currentRegion])) {
        $visited[$currentRegion] = true;
        $setting = $settings[$currentRegion] ?? defaultUserBackgroundSettings()[$currentRegion];
        if ($setting['background_type'] !== 'linked' || !in_array($setting['linked_region'], USER_BACKGROUND_REGIONS, true)) {
            return $setting;
        }
        $currentRegion = $setting['linked_region'];
    }

    return defaultUserBackgroundSettings()[$region];
}
