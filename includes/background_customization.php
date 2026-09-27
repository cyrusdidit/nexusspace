<?php

declare(strict_types=1);

const USER_BACKGROUND_REGIONS = ['messages', 'profile_cover', 'profile_posts', 'dashboard_feed'];
const USER_BACKGROUND_IMAGE_FITS = ['cover', 'contain', 'tile'];
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
            'image_fit' => 'cover',
            'image_blur' => 0,
            'linked_region' => null,
        ];
    }
    return $settings;
}

function readUserBackgroundSettings(mysqli $conn, int $userId): array
{
    $settings = defaultUserBackgroundSettings();
    $statement = mysqli_prepare($conn, 'SELECT region, background_type, color_value, image_path, image_fit, image_blur, linked_region FROM user_backgrounds WHERE user_id = ?');
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
            'image_fit' => in_array($row['image_fit'], USER_BACKGROUND_IMAGE_FITS, true) ? $row['image_fit'] : 'cover',
            'image_blur' => min(20, max(0, (int) $row['image_blur'])),
            'linked_region' => in_array($row['linked_region'], USER_BACKGROUND_REGIONS, true) ? $row['linked_region'] : null,
        ];
    }
    return $settings;
}

function saveUserBackgroundSetting(mysqli $conn, int $userId, string $region, string $type, ?string $color, ?string $imagePath, ?string $linkedRegion, string $imageFit = 'cover', int $imageBlur = 0): void
{
    if (!in_array($region, USER_BACKGROUND_REGIONS, true)) throw new InvalidArgumentException('Unknown background region.');
    if (!in_array($type, ['color', 'image', 'linked'], true)) throw new InvalidArgumentException('Unknown background type.');
    if ($color !== null && !preg_match('/^#[0-9a-f]{6}$/i', $color)) throw new InvalidArgumentException('Invalid background color.');
    if ($imagePath !== null && !preg_match('~^uploads/backgrounds/[a-z0-9._-]+$~i', $imagePath)) throw new InvalidArgumentException('Invalid background image path.');
    if ($type === 'color' && $color === null) throw new InvalidArgumentException('Invalid background color.');
    if ($type === 'image' && $imagePath === null) throw new InvalidArgumentException('Invalid background image path.');
    if (!in_array($imageFit, USER_BACKGROUND_IMAGE_FITS, true)) throw new InvalidArgumentException('Invalid background image fit.');
    if ($imageBlur < 0 || $imageBlur > 20) throw new InvalidArgumentException('Invalid background blur.');
    if ($type === 'linked' && (!in_array($linkedRegion, USER_BACKGROUND_REGIONS, true) || $linkedRegion === $region)) {
        throw new InvalidArgumentException('Invalid linked background region.');
    }

    $color = $color !== null ? strtolower($color) : null;
    $linkedRegion = $type === 'linked' ? $linkedRegion : null;
    $statement = mysqli_prepare($conn, 'INSERT INTO user_backgrounds (user_id, region, background_type, color_value, image_path, image_fit, image_blur, linked_region) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE background_type = VALUES(background_type), color_value = VALUES(color_value), image_path = VALUES(image_path), image_fit = VALUES(image_fit), image_blur = VALUES(image_blur), linked_region = VALUES(linked_region)');
    mysqli_stmt_bind_param($statement, 'isssssis', $userId, $region, $type, $color, $imagePath, $imageFit, $imageBlur, $linkedRegion);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);
}

function storeUserBackgroundImage(array $upload, int $userId): string
{
    $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException(match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Use an image smaller than 5 MB.',
            UPLOAD_ERR_NO_FILE => 'Choose an image to upload.',
            default => 'The image upload failed. Please try again.',
        });
    }
    if (($upload['size'] ?? 0) > 5 * 1024 * 1024) throw new InvalidArgumentException('Use an image smaller than 5 MB.');
    $temporaryPath = is_string($upload['tmp_name'] ?? null) ? $upload['tmp_name'] : '';
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) throw new InvalidArgumentException('The uploaded image could not be verified.');

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $dimensions = @getimagesize($temporaryPath);
    if (!isset($extensions[$mime]) || $dimensions === false) throw new InvalidArgumentException('Upload a JPG, PNG, WebP, or GIF image.');
    if ($dimensions[0] > 8000 || $dimensions[1] > 8000 || $dimensions[0] * $dimensions[1] > 40000000) {
        throw new InvalidArgumentException('Use an image under 40 megapixels and 8000 pixels per side.');
    }

    $uploadDirectory = __DIR__ . '/../uploads/backgrounds';
    if ((!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true)) || !is_writable($uploadDirectory)) {
        throw new RuntimeException('The background image could not be saved.');
    }
    $filename = 'background-' . $userId . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($temporaryPath, $uploadDirectory . '/' . $filename)) {
        throw new RuntimeException('The background image could not be saved.');
    }
    return 'uploads/backgrounds/' . $filename;
}

function deleteUserBackgroundImage(?string $imagePath, int $userId): void
{
    $filename = basename($imagePath ?? '');
    if (!preg_match('/^background-' . preg_quote((string) $userId, '/') . '-[a-f0-9]{16}\.(?:jpg|png|webp|gif)$/', $filename)) return;
    $absolutePath = __DIR__ . '/../uploads/backgrounds/' . $filename;
    if (is_file($absolutePath)) @unlink($absolutePath);
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
