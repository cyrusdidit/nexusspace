<?php

declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/profile_customization.php';
require_once __DIR__ . '/../includes/background_customization.php';
require_once __DIR__ . '/../includes/activity.php';
require_once __DIR__ . '/../includes/blocks.php';
require_once __DIR__ . '/../includes/post_interactions.php';
require_once __DIR__ . '/../includes/post_media.php';
require_once __DIR__ . '/../includes/notifications.php';

$currentUserId = (int) $_SESSION['user_id'];
$userId = isset($_GET['id'])
    ? filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : (int) $_SESSION['user_id'];
$userId = $userId === false ? 0 : $userId;
$isOwnProfile = $userId === $currentUserId;
$statement = mysqli_prepare(
    $conn,
    'SELECT username, email, registration_date, avatar_path, bio, status_text, spotify_track_id, activity_state, last_active_at, TIMESTAMPDIFF(SECOND, last_active_at, NOW()) AS activity_age_seconds FROM users WHERE id = ? LIMIT 1'
);
mysqli_stmt_bind_param($statement, 'i', $userId);
mysqli_stmt_execute($statement);
$result = mysqli_stmt_get_result($statement);
$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($statement);

if ($user && !$isOwnProfile && usersAreBlocked($conn, $currentUserId, $userId)) {
    $user = null;
}
if (!$user) {
    http_response_code(404);
}
if ($user && !$isOwnProfile && $_SERVER['REQUEST_METHOD'] === 'GET') {
    createNotification($conn, $userId, $currentUserId, 'profile_view', $currentUserId, null, 'profile_view:' . $currentUserId . ':' . $userId . ':' . date('Y-m-d'));
}
$profileNickname = '';
if ($user && !$isOwnProfile) {
    $statement = mysqli_prepare($conn, 'SELECT nickname FROM friends WHERE user_id = ? AND friend_id = ? LIMIT 1');
    mysqli_stmt_bind_param($statement, 'ii', $currentUserId, $userId);
    mysqli_stmt_execute($statement);
    $nicknameRow = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
    mysqli_stmt_close($statement);
    $profileNickname = trim($nicknameRow['nickname'] ?? '');
}
$profileDisplayName = $profileNickname !== '' ? $profileNickname : ($user['username'] ?? 'Profile not found');
$profileActivityState = resolvedActivityState($user['activity_state'] ?? null, $user['last_active_at'] ?? null, isset($user['activity_age_seconds']) ? (int) $user['activity_age_seconds'] : null);
$profileActivityLabel = match ($profileActivityState) {
    'online' => 'Online',
    'idle' => 'Idle',
    default => 'Offline',
};
$avatarPath = trim($user['avatar_path'] ?? '');
if ($avatarPath !== '' && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $avatarPath)) {
    $avatarPath = str_starts_with($avatarPath, '/') ? $avatarPath : '../' . $avatarPath;
} else {
    $avatarPath = '';
}

$profileGame = null;
$profileMusic = null;
if ($user) {
    $statement = mysqli_prepare($conn, 'SELECT current_game_id, current_game_name, current_game_started_at, TIMESTAMPDIFF(SECOND, activity_updated_at, NOW()) AS activity_age_seconds FROM steam_connections WHERE user_id = ? AND current_game_id IS NOT NULL AND current_game_name IS NOT NULL LIMIT 1');
    mysqli_stmt_bind_param($statement, 'i', $userId);
    mysqli_stmt_execute($statement);
    $gameRow = mysqli_fetch_assoc(mysqli_stmt_get_result($statement)) ?: null;
    mysqli_stmt_close($statement);
    if ($gameRow && (int) ($gameRow['activity_age_seconds'] ?? 999) <= 60) {
        $profileGameId = preg_match('/^\d+$/', (string) $gameRow['current_game_id']) ? (string) $gameRow['current_game_id'] : '';
        $profileGame = [
            'name' => (string) $gameRow['current_game_name'],
            'image' => $profileGameId !== '' ? 'https://cdn.akamai.steamstatic.com/steam/apps/' . $profileGameId . '/header.jpg' : '',
            'elapsed' => max(0, time() - (strtotime((string) $gameRow['current_game_started_at']) ?: time())),
        ];
    }

    $statement = mysqli_prepare($conn, 'SELECT current_item_id, current_item_name, current_artist_name, current_image_url, current_external_url, is_playing, TIMESTAMPDIFF(SECOND, playback_updated_at, NOW()) AS activity_age_seconds FROM spotify_connections WHERE user_id = ? LIMIT 1');
    mysqli_stmt_bind_param($statement, 'i', $userId);
    mysqli_stmt_execute($statement);
    $musicRow = mysqli_fetch_assoc(mysqli_stmt_get_result($statement)) ?: null;
    mysqli_stmt_close($statement);
    if ($musicRow && (int) $musicRow['is_playing'] === 1 && trim((string) $musicRow['current_item_name']) !== '' && (int) ($musicRow['activity_age_seconds'] ?? 999) <= 60) {
        $profileMusic = [
            'id' => trim((string) ($musicRow['current_item_id'] ?? '')),
            'name' => trim((string) $musicRow['current_item_name']),
            'artist' => trim((string) ($musicRow['current_artist_name'] ?? '')),
            'image' => filter_var($musicRow['current_image_url'] ?? null, FILTER_VALIDATE_URL) ? (string) $musicRow['current_image_url'] : '',
            'url' => filter_var($musicRow['current_external_url'] ?? null, FILTER_VALIDATE_URL) ? (string) $musicRow['current_external_url'] : '',
        ];
    }
}

$_SESSION['friend_request_token'] ??= bin2hex(random_bytes(32));
$_SESSION['top_eight_token'] ??= bin2hex(random_bytes(32));
$friendState = 'none';
$friendError = '';
$topEightError = '';
$bioError = '';
$avatarError = '';
$bioDraft = (string) ($user['bio'] ?? '');
$_SESSION['profile_edit_token'] ??= bin2hex(random_bytes(32));
$_SESSION['spotify_action_token'] ??= bin2hex(random_bytes(32));
$_SESSION['posts_csrf'] ??= bin2hex(random_bytes(32));
$profileBackgroundSettings = $user ? readUserBackgroundSettings($conn, $userId) : defaultUserBackgroundSettings();
$profilePostsOwnBackground = $profileBackgroundSettings['profile_posts'];
$profilePostsBackground = resolveUserBackground($profileBackgroundSettings, 'profile_posts');
$profilePostsBackgroundColor = $profilePostsBackground['color_value'];
$profilePostsBackgroundImagePath = $profilePostsBackground['image_path'];
$profilePostsBackgroundImageUrl = $profilePostsBackgroundImagePath ? '../' . $profilePostsBackgroundImagePath : '';
$profilePostsBackgroundType = $profilePostsBackground['background_type'] === 'image' && $profilePostsBackgroundImageUrl !== '' ? 'image' : 'color';
$profilePostsBackgroundImageCss = $profilePostsBackgroundType === 'image' ? 'url(&quot;' . htmlspecialchars($profilePostsBackgroundImageUrl, ENT_QUOTES, 'UTF-8') . '&quot;)' : 'none';
$profilePostsBackgroundFit = $profilePostsBackground['image_fit'];
$profilePostsBackgroundPositionX = $profilePostsBackground['image_position_x'];
$profilePostsBackgroundPositionY = $profilePostsBackground['image_position_y'];
$profilePostsBackgroundZoom = $profilePostsBackground['image_zoom'];
$profilePostsBackgroundBlur = $profilePostsBackground['image_blur'];
$profileCoverOwnBackground = $profileBackgroundSettings['profile_cover'];
$profileCoverBackground = resolveUserBackground($profileBackgroundSettings, 'profile_cover');
$profileCoverBackgroundColor = $profileCoverBackground['color_value'];
$profileCoverBackgroundImagePath = $profileCoverBackground['image_path'];
$profileCoverBackgroundImageUrl = $profileCoverBackgroundImagePath ? '../' . $profileCoverBackgroundImagePath : '';
$profileCoverBackgroundType = $profileCoverBackground['background_type'] === 'image' && $profileCoverBackgroundImageUrl !== '' ? 'image' : 'color';
$profileCoverBackgroundFit = $profileCoverBackground['image_fit'];
$profileCoverBackgroundBlur = $profileCoverBackground['image_blur'];
$profileCoverBackgroundPositionX = $profileCoverBackground['image_position_x'];
$profileCoverBackgroundPositionY = $profileCoverBackground['image_position_y'];
$profileCoverBackgroundZoom = $profileCoverBackground['image_zoom'];
$profileAppearanceError = '';
$profileCoverError = '';
$profileAppearancePanelOpen = false;
$profileCoverPanelOpen = false;
$backgroundRegionLabels = [
    'messages' => 'Messages',
    'profile_cover' => 'Profile cover',
    'profile_posts' => 'Profile wallpaper',
    'dashboard_feed' => 'Dashboard',
];
$wallpaperCopySources = array_diff_key($backgroundRegionLabels, ['profile_posts' => true]);
$coverCopySources = array_diff_key($backgroundRegionLabels, ['profile_cover' => true]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'copy_profile_wallpaper') {
    $token = $_POST['token'] ?? '';
    $sourceRegion = is_string($_POST['source_region'] ?? null) ? $_POST['source_region'] : '';
    $profileAppearancePanelOpen = true;
    if (!$user || !$isOwnProfile) {
        http_response_code(403);
        $profileAppearanceError = 'You can only customize your own profile.';
    } elseif (!is_string($token) || !hash_equals($_SESSION['profile_edit_token'], $token)) {
        http_response_code(403);
        $profileAppearanceError = 'Please refresh the page and try again.';
    } elseif (!isset($wallpaperCopySources[$sourceRegion])) {
        http_response_code(422);
        $profileAppearanceError = 'Choose a section to copy settings from.';
    } else {
        $sourceBackground = resolveUserBackground($profileBackgroundSettings, $sourceRegion);
        $previousImagePath = $profilePostsOwnBackground['image_path'];
        $copiedImagePath = null;
        try {
            $copiedImagePath = duplicateUserBackgroundImage($sourceBackground['image_path'], $currentUserId);
            $copiedType = $sourceBackground['background_type'] === 'image' && $copiedImagePath !== null ? 'image' : 'color';
            saveUserBackgroundSetting($conn, $currentUserId, 'profile_posts', $copiedType, $sourceBackground['color_value'], $copiedImagePath, null, $sourceBackground['image_fit'], $sourceBackground['image_blur'], $sourceBackground['image_position_x'], $sourceBackground['image_position_y'], $sourceBackground['image_zoom']);
            if ($previousImagePath !== $copiedImagePath) deleteUserBackgroundImageIfUnused($conn, $previousImagePath, $currentUserId);
            header('Location: profile.php?id=' . $currentUserId . '&appearance_copied=' . rawurlencode($sourceRegion));
            exit;
        } catch (InvalidArgumentException | RuntimeException $exception) {
            if ($copiedImagePath !== null) deleteUserBackgroundImage($copiedImagePath, $currentUserId);
            http_response_code(422);
            $profileAppearanceError = $exception->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'copy_profile_cover') {
    $token = $_POST['token'] ?? '';
    $sourceRegion = is_string($_POST['source_region'] ?? null) ? $_POST['source_region'] : '';
    $profileCoverPanelOpen = true;
    if (!$user || !$isOwnProfile) {
        http_response_code(403);
        $profileCoverError = 'You can only customize your own profile.';
    } elseif (!is_string($token) || !hash_equals($_SESSION['profile_edit_token'], $token)) {
        http_response_code(403);
        $profileCoverError = 'Please refresh the page and try again.';
    } elseif (!isset($coverCopySources[$sourceRegion])) {
        http_response_code(422);
        $profileCoverError = 'Choose a section to copy settings from.';
    } else {
        $sourceBackground = resolveUserBackground($profileBackgroundSettings, $sourceRegion);
        $previousImagePath = $profileCoverOwnBackground['image_path'];
        $copiedImagePath = null;
        try {
            $copiedImagePath = duplicateUserBackgroundImage($sourceBackground['image_path'], $currentUserId);
            $copiedType = $sourceBackground['background_type'] === 'image' && $copiedImagePath !== null ? 'image' : 'color';
            saveUserBackgroundSetting($conn, $currentUserId, 'profile_cover', $copiedType, $sourceBackground['color_value'], $copiedImagePath, null, $sourceBackground['image_fit'], $sourceBackground['image_blur'], $sourceBackground['image_position_x'], $sourceBackground['image_position_y'], $sourceBackground['image_zoom']);
            if ($previousImagePath !== $copiedImagePath) deleteUserBackgroundImageIfUnused($conn, $previousImagePath, $currentUserId);
            header('Location: profile.php?id=' . $currentUserId . '&cover_copied=' . rawurlencode($sourceRegion));
            exit;
        } catch (InvalidArgumentException | RuntimeException $exception) {
            if ($copiedImagePath !== null) deleteUserBackgroundImage($copiedImagePath, $currentUserId);
            http_response_code(422);
            $profileCoverError = $exception->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile_wallpaper') {
    $token = $_POST['token'] ?? '';
    $submittedType = is_string($_POST['background_type'] ?? null) ? $_POST['background_type'] : '';
    $submittedColor = is_string($_POST['background_color'] ?? null) ? strtolower($_POST['background_color']) : '';
    $submittedFit = is_string($_POST['image_fit'] ?? null) ? $_POST['image_fit'] : '';
    $submittedPositionX = filter_var($_POST['image_position_x'] ?? null, FILTER_VALIDATE_FLOAT);
    $submittedPositionY = filter_var($_POST['image_position_y'] ?? null, FILTER_VALIDATE_FLOAT);
    $submittedZoom = filter_var($_POST['image_zoom'] ?? null, FILTER_VALIDATE_FLOAT);
    $submittedBlur = filter_var($_POST['image_blur'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 20]]);
    $profileAppearancePanelOpen = true;
    if (!$user || !$isOwnProfile) {
        http_response_code(403);
        $profileAppearanceError = 'You can only customize your own profile.';
    } elseif (!is_string($token) || !hash_equals($_SESSION['profile_edit_token'], $token)) {
        http_response_code(403);
        $profileAppearanceError = 'Please refresh the page and try again.';
    } elseif (!in_array($submittedType, ['color', 'image'], true)) {
        http_response_code(422);
        $profileAppearanceError = 'Choose a background type.';
    } elseif (!preg_match('/^#[0-9a-f]{6}$/', $submittedColor)) {
        http_response_code(422);
        $profileAppearanceError = 'Choose a valid background color.';
    } elseif (!in_array($submittedFit, USER_BACKGROUND_IMAGE_FITS, true)) {
        http_response_code(422);
        $profileAppearanceError = 'Choose a valid image display setting.';
    } elseif ($submittedPositionX === false || $submittedPositionX < 0 || $submittedPositionX > 100 || $submittedPositionY === false || $submittedPositionY < 0 || $submittedPositionY > 100 || $submittedZoom === false || $submittedZoom < 1 || $submittedZoom > 3) {
        http_response_code(422);
        $profileAppearanceError = 'Choose valid image crop settings.';
    } elseif ($submittedBlur === false) {
        http_response_code(422);
        $profileAppearanceError = 'Choose a valid background blur.';
    } else {
        $previousImagePath = $profilePostsOwnBackground['image_path'];
        $savedImagePath = $previousImagePath;
        try {
            if ($submittedType === 'image') {
                $upload = is_array($_FILES['background_image'] ?? null) ? $_FILES['background_image'] : ['error' => UPLOAD_ERR_NO_FILE];
                if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $savedImagePath = storeUserBackgroundImage($upload, $currentUserId);
                } elseif (!$savedImagePath) {
                    throw new InvalidArgumentException('Choose an image to upload.');
                }
            }
            saveUserBackgroundSetting(
                $conn,
                $currentUserId,
                'profile_posts',
                $submittedType,
                $submittedColor,
                $savedImagePath,
                null,
                $submittedFit,
                $submittedBlur,
                (float) $submittedPositionX,
                (float) $submittedPositionY,
                (float) $submittedZoom
            );
            if ($previousImagePath && $savedImagePath !== $previousImagePath) {
                deleteUserBackgroundImageIfUnused($conn, $previousImagePath, $currentUserId);
            }
            header('Location: profile.php?id=' . $currentUserId . '&appearance_saved=1');
            exit;
        } catch (InvalidArgumentException | RuntimeException $exception) {
            if ($savedImagePath && $savedImagePath !== $previousImagePath) deleteUserBackgroundImage($savedImagePath, $currentUserId);
            http_response_code(422);
            $profileAppearanceError = $exception->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['remove_profile_wallpaper_image', 'reset_profile_wallpaper'], true)) {
    $token = $_POST['token'] ?? '';
    $profileAppearancePanelOpen = true;
    if (!$user || !$isOwnProfile) {
        http_response_code(403);
        $profileAppearanceError = 'You can only customize your own profile.';
    } elseif (!is_string($token) || !hash_equals($_SESSION['profile_edit_token'], $token)) {
        http_response_code(403);
        $profileAppearanceError = 'Please refresh the page and try again.';
    } else {
        $previousImagePath = $profilePostsOwnBackground['image_path'];
        if ($_POST['action'] === 'remove_profile_wallpaper_image') {
            saveUserBackgroundSetting(
                $conn,
                $currentUserId,
                'profile_posts',
                'color',
                $profilePostsOwnBackground['color_value'],
                null,
                null,
                $profilePostsOwnBackground['image_fit'],
                $profilePostsOwnBackground['image_blur'],
                $profilePostsOwnBackground['image_position_x'],
                $profilePostsOwnBackground['image_position_y'],
                $profilePostsOwnBackground['image_zoom']
            );
            $notice = 'appearance_image_removed=1';
        } else {
            saveUserBackgroundSetting($conn, $currentUserId, 'profile_posts', 'color', USER_BACKGROUND_DEFAULTS['profile_posts'], null, null, 'cover', 0, 50, 50, 1);
            $notice = 'appearance_reset=1';
        }
        deleteUserBackgroundImageIfUnused($conn, $previousImagePath, $currentUserId);
        header('Location: profile.php?id=' . $currentUserId . '&' . $notice);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile_cover') {
    $token = $_POST['token'] ?? '';
    $submittedType = is_string($_POST['background_type'] ?? null) ? $_POST['background_type'] : '';
    $submittedColor = is_string($_POST['background_color'] ?? null) ? strtolower($_POST['background_color']) : '';
    $submittedFit = is_string($_POST['image_fit'] ?? null) ? $_POST['image_fit'] : '';
    $submittedBlur = filter_var($_POST['image_blur'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 20]]);
    $submittedPositionX = filter_var($_POST['image_position_x'] ?? null, FILTER_VALIDATE_FLOAT);
    $submittedPositionY = filter_var($_POST['image_position_y'] ?? null, FILTER_VALIDATE_FLOAT);
    $submittedZoom = filter_var($_POST['image_zoom'] ?? null, FILTER_VALIDATE_FLOAT);
    $profileCoverPanelOpen = true;
    if (!$user || !$isOwnProfile) {
        http_response_code(403);
        $profileCoverError = 'You can only customize your own profile.';
    } elseif (!is_string($token) || !hash_equals($_SESSION['profile_edit_token'], $token)) {
        http_response_code(403);
        $profileCoverError = 'Please refresh the page and try again.';
    } elseif (!in_array($submittedType, ['color', 'image'], true) || !preg_match('/^#[0-9a-f]{6}$/', $submittedColor)) {
        http_response_code(422);
        $profileCoverError = 'Choose a valid cover type and color.';
    } elseif (!in_array($submittedFit, USER_BACKGROUND_IMAGE_FITS, true) || $submittedBlur === false) {
        http_response_code(422);
        $profileCoverError = 'Choose valid cover display settings.';
    } elseif ($submittedPositionX === false || $submittedPositionX < 0 || $submittedPositionX > 100 || $submittedPositionY === false || $submittedPositionY < 0 || $submittedPositionY > 100 || $submittedZoom === false || $submittedZoom < 1 || $submittedZoom > 3) {
        http_response_code(422);
        $profileCoverError = 'Choose valid cover crop settings.';
    } else {
        $previousImagePath = $profileCoverOwnBackground['image_path'];
        $savedImagePath = $previousImagePath;
        try {
            if ($submittedType === 'image') {
                $upload = is_array($_FILES['background_image'] ?? null) ? $_FILES['background_image'] : ['error' => UPLOAD_ERR_NO_FILE];
                if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $savedImagePath = storeUserBackgroundImage($upload, $currentUserId);
                } elseif (!$savedImagePath) {
                    throw new InvalidArgumentException('Choose a cover image to upload.');
                }
            }
            saveUserBackgroundSetting($conn, $currentUserId, 'profile_cover', $submittedType, $submittedColor, $savedImagePath, null, $submittedFit, $submittedBlur, (float) $submittedPositionX, (float) $submittedPositionY, (float) $submittedZoom);
            if ($previousImagePath && $savedImagePath !== $previousImagePath) deleteUserBackgroundImageIfUnused($conn, $previousImagePath, $currentUserId);
            header('Location: profile.php?id=' . $currentUserId . '&cover_saved=1');
            exit;
        } catch (InvalidArgumentException | RuntimeException $exception) {
            if ($savedImagePath && $savedImagePath !== $previousImagePath) deleteUserBackgroundImage($savedImagePath, $currentUserId);
            http_response_code(422);
            $profileCoverError = $exception->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['remove_profile_cover_image', 'reset_profile_cover'], true)) {
    $token = $_POST['token'] ?? '';
    $profileCoverPanelOpen = true;
    if (!$user || !$isOwnProfile) {
        http_response_code(403);
        $profileCoverError = 'You can only customize your own profile.';
    } elseif (!is_string($token) || !hash_equals($_SESSION['profile_edit_token'], $token)) {
        http_response_code(403);
        $profileCoverError = 'Please refresh the page and try again.';
    } else {
        $previousImagePath = $profileCoverOwnBackground['image_path'];
        if ($_POST['action'] === 'remove_profile_cover_image') {
            saveUserBackgroundSetting($conn, $currentUserId, 'profile_cover', 'color', $profileCoverOwnBackground['color_value'], null, null, $profileCoverOwnBackground['image_fit'], $profileCoverOwnBackground['image_blur'], $profileCoverOwnBackground['image_position_x'], $profileCoverOwnBackground['image_position_y'], $profileCoverOwnBackground['image_zoom']);
            $notice = 'cover_image_removed=1';
        } else {
            saveUserBackgroundSetting($conn, $currentUserId, 'profile_cover', 'color', USER_BACKGROUND_DEFAULTS['profile_cover'], null, null, 'cover');
            $notice = 'cover_reset=1';
        }
        deleteUserBackgroundImageIfUnused($conn, $previousImagePath, $currentUserId);
        header('Location: profile.php?id=' . $currentUserId . '&' . $notice);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_avatar') {
    $token = $_POST['token'] ?? '';
    $upload = $_FILES['avatar'] ?? null;
    if (!$user || !$isOwnProfile) {
        http_response_code(403);
        $avatarError = 'You can only change your own profile picture.';
    } elseif (!is_string($token) || !hash_equals($_SESSION['profile_edit_token'], $token)) {
        http_response_code(403);
        $avatarError = 'Please refresh the page and try again.';
    } elseif (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $avatarError = 'Choose an image to upload.';
    } elseif (($upload['size'] ?? 0) > 5 * 1024 * 1024) {
        $avatarError = 'Use an image smaller than 5 MB.';
    } else {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!isset($extensions[$mime]) || @getimagesize($upload['tmp_name']) === false) {
            $avatarError = 'Upload a JPG, PNG, WebP, or GIF image.';
        } else {
            $uploadDirectory = __DIR__ . '/../uploads/avatars';
            if ((!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true)) || !is_writable($uploadDirectory)) {
                $avatarError = 'The profile picture could not be saved.';
            } else {
                $filename = 'avatar-' . $currentUserId . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
                $destination = $uploadDirectory . '/' . $filename;
                if (!move_uploaded_file($upload['tmp_name'], $destination)) {
                    $avatarError = 'The profile picture could not be saved.';
                } else {
                    $storedPath = 'uploads/avatars/' . $filename;
                    $statement = mysqli_prepare($conn, 'UPDATE users SET avatar_path = ? WHERE id = ?');
                    mysqli_stmt_bind_param($statement, 'si', $storedPath, $currentUserId);
                    mysqli_stmt_execute($statement);
                    mysqli_stmt_close($statement);
                    header('Location: profile.php?id=' . $currentUserId);
                    exit;
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_bio') {
    $token = $_POST['token'] ?? '';
    $bioDraft = is_string($_POST['bio'] ?? null) ? trim($_POST['bio']) : '';
    $bioDraft = str_replace(["\r\n", "\r"], "\n", $bioDraft);
    if (!$user || !$isOwnProfile) {
        http_response_code(403);
        $bioError = 'You can only edit your own bio.';
    } elseif (!is_string($token) || !hash_equals($_SESSION['profile_edit_token'], $token)) {
        http_response_code(403);
        $bioError = 'Please refresh the page and try again.';
    } elseif (!mb_check_encoding($bioDraft, 'UTF-8') || mb_strlen($bioDraft, 'UTF-8') > 160) {
        $bioError = 'Use 160 characters or fewer.';
    } else {
        $statement = mysqli_prepare($conn, 'UPDATE users SET bio = ? WHERE id = ?');
        mysqli_stmt_bind_param($statement, 'si', $bioDraft, $currentUserId);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        header('Location: profile.php?id=' . $currentUserId . '&bio_saved=1');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_top_eight') {
    $token = $_POST['token'] ?? '';
    $slots = $_POST['top_eight_slots'] ?? [];
    if (!$user || !$isOwnProfile) {
        http_response_code(403);
        $topEightError = 'You can only edit your own Top 8.';
    } elseif (!is_string($token) || !hash_equals($_SESSION['top_eight_token'], $token)) {
        http_response_code(403);
        $topEightError = 'Please refresh the page and try again.';
    } elseif (!is_array($slots) || count($slots) !== 8) {
        $topEightError = 'Choose up to eight friends.';
    } else {
        $statement = mysqli_prepare($conn, 'SELECT friend_id FROM friends WHERE user_id = ?');
        mysqli_stmt_bind_param($statement, 'i', $currentUserId);
        mysqli_stmt_execute($statement);
        $allowedFriendIds = array_map('intval', array_column(mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC), 'friend_id'));
        mysqli_stmt_close($statement);
        $chosen = [];
        foreach (array_values($slots) as $position => $value) {
            if ($value === '') continue;
            $friendId = filter_var($value, FILTER_VALIDATE_INT);
            if (!$friendId || !in_array($friendId, $allowedFriendIds, true) || in_array($friendId, $chosen, true)) {
                $topEightError = 'Your Top 8 can only contain each friend once.';
                break;
            }
            $chosen[$position + 1] = $friendId;
        }
        if ($topEightError === '') {
            mysqli_begin_transaction($conn);
            try {
                $clear = mysqli_prepare($conn, 'UPDATE friends SET top_eight_position = NULL WHERE user_id = ?');
                mysqli_stmt_bind_param($clear, 'i', $currentUserId);
                mysqli_stmt_execute($clear);
                mysqli_stmt_close($clear);
                $save = mysqli_prepare($conn, 'UPDATE friends SET top_eight_position = ? WHERE user_id = ? AND friend_id = ?');
                foreach ($chosen as $position => $friendId) {
                    mysqli_stmt_bind_param($save, 'iii', $position, $currentUserId, $friendId);
                    mysqli_stmt_execute($save);
                }
                mysqli_stmt_close($save);
                mysqli_commit($conn);
                header('Location: profile.php?id=' . $currentUserId);
                exit;
            } catch (Throwable $exception) {
                mysqli_rollback($conn);
                $topEightError = 'Could not save your Top 8. Please try again.';
            }
        }
    }
}

$readFriendState = static function () use ($conn, $currentUserId, $userId): string {
    $statement = mysqli_prepare($conn, 'SELECT id FROM friends WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?) LIMIT 1');
    mysqli_stmt_bind_param($statement, 'iiii', $currentUserId, $userId, $userId, $currentUserId);
    mysqli_stmt_execute($statement);
    $isFriend = mysqli_num_rows(mysqli_stmt_get_result($statement)) > 0;
    mysqli_stmt_close($statement);
    if ($isFriend) return 'friends';

    $statement = mysqli_prepare($conn, "SELECT sender_id, status FROM friend_requests WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)) AND status IN ('pending', 'accepted') ORDER BY status = 'accepted' DESC LIMIT 1");
    mysqli_stmt_bind_param($statement, 'iiii', $currentUserId, $userId, $userId, $currentUserId);
    mysqli_stmt_execute($statement);
    $request = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
    mysqli_stmt_close($statement);
    if (!$request) return 'none';
    if ($request['status'] === 'accepted') return 'friends';
    return (int) $request['sender_id'] === $currentUserId ? 'sent' : 'received';
};

if ($user && !$isOwnProfile) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['befriend', 'accept', 'decline', 'cancel_request', 'unfriend', 'block'], true)) {
        $token = $_POST['token'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['friend_request_token'], $token)) {
            http_response_code(403);
            $friendError = 'Please refresh the page and try again.';
        } elseif (in_array($_POST['action'] ?? '', ['befriend', 'accept', 'decline', 'cancel_request', 'unfriend', 'block'], true)) {
            $action = $_POST['action'];
            mysqli_begin_transaction($conn);
            try {
                // Lock both accounts in the same order to serialize requests between them.
                $lock = mysqli_prepare($conn, 'SELECT id FROM users WHERE id IN (?, ?) ORDER BY id FOR UPDATE');
                mysqli_stmt_bind_param($lock, 'ii', $currentUserId, $userId);
                mysqli_stmt_execute($lock);
                mysqli_stmt_store_result($lock);
                mysqli_stmt_close($lock);
                $state = $readFriendState();
                if ($action === 'befriend' && $state === 'none') {
                    $statement = mysqli_prepare($conn, "INSERT INTO friend_requests (sender_id, receiver_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE status = 'pending', created_at = CURRENT_TIMESTAMP");
                    mysqli_stmt_bind_param($statement, 'ii', $currentUserId, $userId);
                    mysqli_stmt_execute($statement);
                    mysqli_stmt_close($statement);
                    $statement = mysqli_prepare($conn, 'SELECT id FROM friend_requests WHERE sender_id = ? AND receiver_id = ? LIMIT 1');
                    mysqli_stmt_bind_param($statement, 'ii', $currentUserId, $userId);
                    mysqli_stmt_execute($statement);
                    $requestId = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($statement))['id'] ?? 0);
                    mysqli_stmt_close($statement);
                    createNotification($conn, $userId, $currentUserId, 'friend_request', $requestId, null, 'friend_request:' . $requestId, true);
                }
                if (in_array($action, ['accept', 'decline'], true) && $state === 'received') {
                    $decision = $action === 'accept' ? 'accepted' : 'declined';
                    $statement = mysqli_prepare($conn, "UPDATE friend_requests SET status = ? WHERE sender_id = ? AND receiver_id = ? AND status = 'pending'");
                    mysqli_stmt_bind_param($statement, 'sii', $decision, $userId, $currentUserId);
                    mysqli_stmt_execute($statement);
                    $updated = mysqli_stmt_affected_rows($statement);
                    mysqli_stmt_close($statement);
                    if ($action === 'accept' && $updated === 1) {
                        $statement = mysqli_prepare($conn, 'INSERT INTO friends (user_id, friend_id) VALUES (?, ?), (?, ?) ON DUPLICATE KEY UPDATE friend_id = VALUES(friend_id)');
                        mysqli_stmt_bind_param($statement, 'iiii', $currentUserId, $userId, $userId, $currentUserId);
                        mysqli_stmt_execute($statement);
                        mysqli_stmt_close($statement);
                        createNotification($conn, $userId, $currentUserId, 'friend_accept', $currentUserId, null, 'friend_accept:' . $userId . ':' . $currentUserId, true);
                    }
                    $statement = mysqli_prepare($conn, "DELETE FROM notifications WHERE recipient_id = ? AND actor_id = ? AND type = 'friend_request'");
                    mysqli_stmt_bind_param($statement, 'ii', $currentUserId, $userId);
                    mysqli_stmt_execute($statement);
                    mysqli_stmt_close($statement);
                }
                if ($action === 'cancel_request' && $state === 'sent') {
                    $statement = mysqli_prepare($conn, "DELETE FROM friend_requests WHERE sender_id = ? AND receiver_id = ? AND status = 'pending'");
                    mysqli_stmt_bind_param($statement, 'ii', $currentUserId, $userId);
                    mysqli_stmt_execute($statement);
                    mysqli_stmt_close($statement);
                    $statement = mysqli_prepare($conn, "DELETE FROM notifications WHERE recipient_id = ? AND actor_id = ? AND type = 'friend_request'");
                    mysqli_stmt_bind_param($statement, 'ii', $userId, $currentUserId);
                    mysqli_stmt_execute($statement);
                    mysqli_stmt_close($statement);
                }
                if ($action === 'unfriend' && $state === 'friends') {
                    $statement = mysqli_prepare($conn, 'DELETE FROM friends WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)');
                    mysqli_stmt_bind_param($statement, 'iiii', $currentUserId, $userId, $userId, $currentUserId);
                    mysqli_stmt_execute($statement);
                    mysqli_stmt_close($statement);

                    $statement = mysqli_prepare($conn, 'DELETE FROM friend_requests WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)');
                    mysqli_stmt_bind_param($statement, 'iiii', $currentUserId, $userId, $userId, $currentUserId);
                    mysqli_stmt_execute($statement);
                    mysqli_stmt_close($statement);
                }
                if ($action === 'block') {
                    $statement = mysqli_prepare($conn, 'INSERT INTO user_blocks (blocker_id, blocked_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE created_at = created_at');
                    mysqli_stmt_bind_param($statement, 'ii', $currentUserId, $userId);
                    mysqli_stmt_execute($statement);
                    mysqli_stmt_close($statement);
                }
                mysqli_commit($conn);
                if ($action === 'block') {
                    header('Location: ../index.php?blocked=1');
                    exit;
                }
                header('Location: profile.php?id=' . $userId);
                exit;
            } catch (mysqli_sql_exception $exception) {
                mysqli_rollback($conn);
                $friendError = 'Could not update the friend request. Please try again.';
            }
        }
    }
    $friendState = $readFriendState();
}
$profilePosts = [];
$postInteractions = [];
$topFriends = [];
$allFriends = [];
$temporaryProfileUsers = array_map(
    static fn (int $number): array => ['username' => 'TempUser' . $number],
    range(1, 20)
);
$profileHasMorePosts = false;
if ($user) {
    $statement = mysqli_prepare($conn, "SELECT p.id, p.user_id, p.title, p.content, p.media_path, p.media_type, p.visibility, p.created_at, p.edited_at FROM posts p WHERE p.user_id = ? AND (p.visibility = 'public' OR p.user_id = ? OR EXISTS (SELECT 1 FROM friends f WHERE (f.user_id = ? AND f.friend_id = p.user_id) OR (f.friend_id = ? AND f.user_id = p.user_id))) ORDER BY p.created_at DESC, p.id DESC LIMIT 51");
    mysqli_stmt_bind_param($statement, 'iiii', $userId, $currentUserId, $currentUserId, $currentUserId);
    mysqli_stmt_execute($statement);
    $profilePosts = mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC);
    mysqli_stmt_close($statement);
    $profileHasMorePosts = count($profilePosts) > 50;
    if ($profileHasMorePosts) array_pop($profilePosts);
    attachPostMedia($profilePosts, loadPostMedia($conn, array_column($profilePosts, 'id')));
    $postInteractions = loadPostInteractions($conn, array_column($profilePosts, 'id'), $currentUserId);
    $statement = mysqli_prepare($conn, "SELECT u.id, u.username, u.avatar_path, f.top_eight_position, GREATEST(f.created_at, COALESCE(MAX(m.created_at), f.created_at)) AS last_interaction_at FROM friends f JOIN users u ON u.id = f.friend_id LEFT JOIN messages m ON ((m.sender_id = ? AND m.receiver_id = u.id) OR (m.receiver_id = ? AND m.sender_id = u.id)) AND m.deleted_at IS NULL WHERE f.user_id = ? AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_id = ? AND b.blocked_id = u.id) OR (b.blocker_id = u.id AND b.blocked_id = ?)) GROUP BY u.id, u.username, u.avatar_path, f.top_eight_position, f.created_at ORDER BY CASE WHEN f.top_eight_position BETWEEN 1 AND 8 THEN 0 ELSE 1 END, CASE WHEN f.top_eight_position BETWEEN 1 AND 8 THEN f.top_eight_position ELSE NULL END, last_interaction_at DESC, u.username, u.id");
    mysqli_stmt_bind_param($statement, 'iiiii', $userId, $userId, $userId, $currentUserId, $currentUserId);
    mysqli_stmt_execute($statement);
    $allFriends = mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC);
    $topFriends = array_values(array_filter($allFriends, static fn (array $friend): bool => (int) ($friend['top_eight_position'] ?? 0) >= 1 && (int) $friend['top_eight_position'] <= 8));
    mysqli_stmt_close($statement);
}
$profileCustomization = $user ? readProfileCustomization($conn, $userId) : null;
$profileCustomCss = $profileCustomization ? sanitizeAndScopeProfileCss($profileCustomization['custom_css']) : '';
$profileNotice = '';
if ($isOwnProfile) {
    $appearanceCopied = is_string($_GET['appearance_copied'] ?? null) ? $_GET['appearance_copied'] : '';
    $coverCopied = is_string($_GET['cover_copied'] ?? null) ? $_GET['cover_copied'] : '';
    if (isset($_GET['appearance_saved'])) {
        $profileNotice = 'Profile wallpaper saved.';
    } elseif (isset($_GET['appearance_image_removed'])) {
        $profileNotice = 'Wallpaper image removed. Your color was kept.';
    } elseif (isset($_GET['appearance_reset'])) {
        $profileNotice = 'Settings reset, no going back now!';
    } elseif (isset($backgroundRegionLabels[$appearanceCopied])) {
        $profileNotice = 'Wallpaper settings copied from ' . $backgroundRegionLabels[$appearanceCopied] . '.';
    } elseif (isset($_GET['cover_saved'])) {
        $profileNotice = 'Profile cover saved.';
    } elseif (isset($_GET['cover_image_removed'])) {
        $profileNotice = 'Cover image removed. Your color was kept.';
    } elseif (isset($_GET['cover_reset'])) {
        $profileNotice = 'Profile cover reset.';
    } elseif (isset($backgroundRegionLabels[$coverCopied])) {
        $profileNotice = 'Cover settings copied from ' . $backgroundRegionLabels[$coverCopied] . '.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($profileDisplayName, ENT_QUOTES, 'UTF-8') ?> · NexusSpace</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <?php if ($profileCustomCss !== ''): ?><style data-profile-custom-css><?= str_replace('</style', '<\/style', $profileCustomCss) ?></style><?php endif; ?>
    <script src="../assets/js/profile-bio.js?v=<?= filemtime(__DIR__ . '/../assets/js/profile-bio.js') ?>" defer></script>
    <script src="../assets/js/profile-avatar.js?v=<?= filemtime(__DIR__ . '/../assets/js/profile-avatar.js') ?>" defer></script>
    <script src="../assets/js/profile-friends.js?v=<?= filemtime(__DIR__ . '/../assets/js/profile-friends.js') ?>" defer></script>
    <script src="../assets/js/profile-top-eight.js?v=<?= filemtime(__DIR__ . '/../assets/js/profile-top-eight.js') ?>" defer></script>
    <script src="../assets/js/profile-social-activity.js?v=<?= filemtime(__DIR__ . '/../assets/js/profile-social-activity.js') ?>" defer></script>
    <script src="../assets/js/activity.js?v=<?= filemtime(__DIR__ . '/../assets/js/activity.js') ?>" defer></script>
    <script src="../assets/js/posts.js?v=<?= filemtime(__DIR__ . '/../assets/js/posts.js') ?>" defer></script>
    <script src="../assets/js/media.js?v=<?= filemtime(__DIR__ . '/../assets/js/media.js') ?>" defer></script>
    <?php if ($user && $isOwnProfile): ?>
        <script src="../assets/js/profile-appearance.js?v=<?= filemtime(__DIR__ . '/../assets/js/profile-appearance.js') ?>" defer></script>
        <script src="../assets/js/profile-cover-appearance.js?v=<?= filemtime(__DIR__ . '/../assets/js/profile-cover-appearance.js') ?>" defer></script>
    <?php endif; ?>
</head>
<body class="profile-page" data-activity-endpoint="../activity-ping.php" data-activity-status-endpoint="../activity-status.php" data-spotify-activity-endpoint="../spotify-activity.php" data-steam-activity-endpoint="../steam-activity.php" data-profile-social-activity-endpoint="../profile-social-activity.php" data-post-actions-endpoint="../post-actions.php" data-post-updates-endpoint="../post-updates.php" data-post-csrf="<?= htmlspecialchars($_SESSION['posts_csrf'], ENT_QUOTES, 'UTF-8') ?>" data-post-context="profile" data-current-user-id="<?= $currentUserId ?>" data-profile-user-id="<?= $userId ?>"<?= isset($_GET['bio_saved']) ? ' data-bio-saved="true"' : '' ?>>
    <?php if ($profileNotice !== ''): ?>
        <div class="profile-customization-toast" data-profile-customization-toast role="status">
            <span><?= htmlspecialchars($profileNotice, ENT_QUOTES, 'UTF-8') ?></span>
            <button type="button" data-profile-customization-toast-close aria-label="Close notification">&times;</button>
        </div>
    <?php endif; ?>
    <main class="card profile-sheet">
        <?php if (!$user): ?>
            <h1>Profile not found</h1>
            <p>This user does not exist.</p>
        <?php else: ?>
        <?php ob_start(); ?>
        <div class="profile-cover" style="background-color: <?= htmlspecialchars($profileCoverBackgroundColor, ENT_QUOTES, 'UTF-8') ?>" data-profile-cover<?= $isOwnProfile ? '' : ' aria-hidden="true"' ?>>
            <span class="profile-cover-surface" data-profile-cover-surface<?= $profileCoverBackgroundType === 'image' ? '' : ' hidden' ?> style="filter: blur(<?= $profileCoverBackgroundBlur ?>px)<?php if ($profileCoverBackgroundType === 'image' && $profileCoverBackgroundFit === 'tile'): ?>; background-image: url(&quot;<?= htmlspecialchars($profileCoverBackgroundImageUrl, ENT_QUOTES, 'UTF-8') ?>&quot;); background-size: auto; background-repeat: repeat<?php endif; ?>">
                <img src="<?= htmlspecialchars($profileCoverBackgroundImageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" data-profile-cover-preview style="object-fit: <?= $profileCoverBackgroundFit === 'contain' ? 'contain' : 'cover' ?>; object-position: <?= $profileCoverBackgroundPositionX ?>% <?= $profileCoverBackgroundPositionY ?>%; transform: scale(<?= $profileCoverBackgroundZoom ?>); transform-origin: <?= $profileCoverBackgroundPositionX ?>% <?= $profileCoverBackgroundPositionY ?>%"<?= $profileCoverBackgroundType !== 'image' || $profileCoverBackgroundFit === 'tile' ? ' hidden' : '' ?>>
            </span>
            <?php if ($isOwnProfile): ?><button class="profile-cover-edit" type="button" aria-label="Customize profile cover" title="Customize profile cover" aria-controls="profile-cover-panel" aria-expanded="false" data-profile-cover-toggle>&#9998;</button><?php endif; ?>
        </div>
        <section class="profile-identity" aria-label="Profile">
            <?php if ($isOwnProfile): ?>
                <form class="profile-picture-upload" method="post" action="profile.php?id=<?= $userId ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_avatar">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['profile_edit_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input class="profile-picture-input" id="profile-picture-input" name="avatar" type="file" accept="image/jpeg,image/png,image/webp,image/gif">
                    <label class="profile-picture" for="profile-picture-input" title="Change profile picture">
                        <span aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($user['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($avatarPath !== ''): ?><img src="<?= htmlspecialchars($avatarPath, ENT_QUOTES, 'UTF-8') ?>" alt=""><?php endif; ?>
                        <span class="profile-picture-overlay" aria-hidden="true">!</span>
                    </label>
                </form>
            <?php else: ?>
                <div class="profile-picture">
                    <span><?= htmlspecialchars(mb_strtoupper(mb_substr($user['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($avatarPath !== ''): ?><img src="<?= htmlspecialchars($avatarPath, ENT_QUOTES, 'UTF-8') ?>" alt=""><?php endif; ?>
                    <span class="profile-avatar-activity" data-profile-activity data-user-id="<?= $userId ?>" data-state="<?= htmlspecialchars($profileActivityState, ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($profileActivityLabel, ENT_QUOTES, 'UTF-8') ?>">
                        <span class="sr-only" data-activity-label><?= htmlspecialchars($profileActivityLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    </span>
                </div>
            <?php endif; ?>
            <?php if ($avatarError): ?><p class="profile-avatar-error" role="alert"><?= htmlspecialchars($avatarError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
            <h1><?= htmlspecialchars($profileDisplayName, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="profile-handle">@<?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></p>
            <p class="profile-written-status" title="<?= htmlspecialchars(trim((string) ($user['status_text'] ?? '')), ENT_QUOTES, 'UTF-8') ?>" data-profile-written-status<?= trim((string) ($user['status_text'] ?? '')) !== '' ? '' : ' hidden' ?>><?= htmlspecialchars(trim((string) ($user['status_text'] ?? '')), ENT_QUOTES, 'UTF-8') ?></p>
            <section class="profile-sidebar-activity" aria-label="Current activity" data-spotify-like-endpoint="../spotify-like-track.php" data-spotify-action-token="<?= htmlspecialchars($_SESSION['spotify_action_token'], ENT_QUOTES, 'UTF-8') ?>">
                <div class="profile-sidebar-activity-row profile-sidebar-music-row" data-profile-music-row<?= $profileMusic ? '' : ' hidden' ?>>
                    <a class="profile-sidebar-activity-main" href="<?= htmlspecialchars($profileMusic['url'] ?? '', ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" title="<?= $profileMusic ? htmlspecialchars($profileMusic['name'] . ' • ' . $profileMusic['artist'], ENT_QUOTES, 'UTF-8') : '' ?>" data-profile-music-link>
                        <img class="profile-sidebar-activity-art" src="<?= htmlspecialchars($profileMusic['image'] ?? '', ENT_QUOTES, 'UTF-8') ?>" alt="" data-profile-music-image<?= !empty($profileMusic['image']) ? '' : ' hidden' ?>>
                        <span class="profile-sidebar-activity-copy"><strong data-profile-music-name><?= $profileMusic ? htmlspecialchars($profileMusic['name'], ENT_QUOTES, 'UTF-8') : '' ?></strong><span aria-hidden="true"> &bull; </span><span data-profile-music-artist><?= $profileMusic ? htmlspecialchars($profileMusic['artist'], ENT_QUOTES, 'UTF-8') : '' ?></span></span>
                    </a>
                    <button class="profile-sidebar-music-add" type="button" aria-label="Add song to Liked Songs" title="Add to Liked Songs" data-profile-music-add data-track-id="<?= htmlspecialchars($profileMusic['id'] ?? '', ENT_QUOTES, 'UTF-8') ?>">+</button>
                </div>
                <div class="profile-sidebar-activity-row" data-profile-game-row<?= $profileGame ? '' : ' hidden' ?>>
                    <img class="profile-sidebar-activity-art" src="<?= htmlspecialchars($profileGame['image'] ?? '', ENT_QUOTES, 'UTF-8') ?>" alt="" data-profile-game-image<?= !empty($profileGame['image']) ? '' : ' hidden' ?>>
                    <span class="profile-sidebar-activity-copy"><strong data-profile-game-name><?= $profileGame ? htmlspecialchars($profileGame['name'], ENT_QUOTES, 'UTF-8') : '' ?></strong> <small>(<span data-profile-game-duration data-elapsed-seconds="<?= $profileGame ? (int) $profileGame['elapsed'] : 0 ?>"><?= $profileGame ? sprintf('%02d:%02d:%02d', intdiv((int) $profileGame['elapsed'], 3600), intdiv((int) $profileGame['elapsed'], 60) % 60, (int) $profileGame['elapsed'] % 60) : '' ?></span>)</small></span>
                </div>
                <p class="profile-sidebar-activity-feedback" role="status" data-profile-activity-feedback hidden></p>
            </section>
            <?php if (!$isOwnProfile): ?>
                <form class="profile-friend-actions" method="post" action="profile.php?id=<?= $userId ?>">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['friend_request_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php if ($friendState === 'friends'): ?>
                        <a class="button profile-message-button" href="messages.php?user=<?= $userId ?>" aria-label="Message <?= htmlspecialchars($profileDisplayName, ENT_QUOTES, 'UTF-8') ?>" title="Message <?= htmlspecialchars($profileDisplayName, ENT_QUOTES, 'UTF-8') ?>"><img src="../assets/images/message-icon.png" alt=""></a>
                    <?php endif; ?>
                    <?php if ($friendState === 'received'): ?>
                        <button type="submit" name="action" value="accept">Accept</button>
                        <button class="button-secondary" type="submit" name="action" value="decline">Decline</button>
                    <?php else: ?>
                        <?php if ($friendState === 'friends'): ?>
                            <button class="button-secondary" type="submit" name="action" value="unfriend" data-unfriend-button>Unfriend</button>
                        <?php elseif ($friendState === 'sent'): ?>
                            <button class="button-secondary" type="submit" name="action" value="cancel_request" data-cancel-request-button>Cancel request</button>
                        <?php else: ?>
                            <button type="submit" name="action" value="befriend">Befriend</button>
                        <?php endif; ?>
                    <?php endif; ?>
                    <button class="button-secondary" type="submit" name="action" value="block" data-block-button>Block</button>
                </form>
            <?php endif; ?>
        </section>
        <?php $profileHeaderFragment = ob_get_clean(); ob_start(); ?>
        <?php if ($isOwnProfile || trim($user['bio'] ?? '') !== ''): ?>
        <section class="profile-bio-section" aria-label="Bio">
            <?php if (trim($user['bio'] ?? '') !== ''): ?><p class="profile-bio"><?= htmlspecialchars($user['bio'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
            <?php if ($isOwnProfile): ?>
                <details class="profile-bio-editor"<?= $bioError ? ' open' : '' ?>>
                    <summary aria-label="Edit bio" title="Edit bio">!</summary>
                    <form method="post" action="profile.php?id=<?= $userId ?>">
                        <input type="hidden" name="action" value="update_bio">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['profile_edit_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <label class="sr-only" for="profile-bio-input">Bio</label>
                        <textarea id="profile-bio-input" name="bio" rows="3" maxlength="160" aria-describedby="bio-limit"><?= htmlspecialchars($bioDraft, ENT_QUOTES, 'UTF-8') ?></textarea>
                        <div class="profile-bio-actions"><small id="bio-limit"><?= mb_strlen($bioDraft, 'UTF-8') ?>/160</small><button type="submit" aria-label="Save bio" title="Save bio">&#10003;</button></div>
                        <?php if ($bioError): ?><p class="post-error" role="alert"><?= htmlspecialchars($bioError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                    </form>
                </details>
            <?php endif; ?>
        </section>
        <?php endif; ?>
        <?php $profileBioFragment = ob_get_clean(); ob_start(); ?>
        <form class="profile-top-eight" method="post" action="profile.php?id=<?= $userId ?>" aria-labelledby="profile-top-eight-heading" data-top-eight-form data-profile-id="<?= $userId ?>">
            <input type="hidden" name="action" value="update_top_eight">
            <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['top_eight_token'], ENT_QUOTES, 'UTF-8') ?>">
            <span data-top-eight-inputs></span>
            <div class="panel-heading">
                <h2 class="friends-link" id="profile-top-eight-heading" data-fit-top-eight-heading><?= $isOwnProfile ? 'My top 8 friends' : htmlspecialchars($profileDisplayName, ENT_QUOTES, 'UTF-8') . "'s top 8 friends" ?></h2>
                <?php if ($isOwnProfile): ?><button class="friends-reorder" type="button" aria-label="Reorder Top 8 friends" title="Reorder Top 8 friends" data-top-eight-edit><img src="../assets/images/arrows.png" alt=""></button><?php endif; ?>
            </div>
            <ol class="friends-list" data-top-eight-list>
                <?php foreach ($topFriends as $friend): ?>
                    <?php
                    $friendAvatar = trim($friend['avatar_path'] ?? '');
                    if ($friendAvatar !== '' && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $friendAvatar)) {
                        $friendAvatar = str_starts_with($friendAvatar, '/') ? $friendAvatar : '../' . $friendAvatar;
                    } else { $friendAvatar = ''; }
                    ?>
                    <li data-top-eight-item data-friend-id="<?= (int) $friend['id'] ?>"><a class="top-friend-link" href="profile.php?id=<?= (int) $friend['id'] ?>">
                        <span class="post-avatar" aria-hidden="true"><span><?= htmlspecialchars(mb_strtoupper(mb_substr($friend['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span><?php if ($friendAvatar): ?><img src="<?= htmlspecialchars($friendAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy"><?php endif; ?></span>
                        <span><?= htmlspecialchars($friend['username'], ENT_QUOTES, 'UTF-8') ?></span>
                    </a><button class="top-eight-remove" type="button" data-top-eight-remove aria-label="Remove <?= htmlspecialchars($friend['username'], ENT_QUOTES, 'UTF-8') ?> from Top 8">&times;</button></li>
                <?php endforeach; ?>
                <?php $visibleTemporaryFriends = max(0, 8 - count($topFriends)); ?>
                <?php foreach (array_slice($temporaryProfileUsers, 0, $visibleTemporaryFriends) as $temporaryFriend): ?>
                    <li class="temporary-profile-friend" data-top-eight-item data-temp-user="<?= htmlspecialchars($temporaryFriend['username'], ENT_QUOTES, 'UTF-8') ?>">
                        <span class="top-friend-link">
                            <span class="post-avatar" aria-hidden="true">T</span>
                            <span><?= htmlspecialchars($temporaryFriend['username'], ENT_QUOTES, 'UTF-8') ?></span>
                        </span>
                        <button class="top-eight-remove" type="button" data-top-eight-remove aria-label="Remove <?= htmlspecialchars($temporaryFriend['username'], ENT_QUOTES, 'UTF-8') ?> from Top 8">&times;</button>
                    </li>
                <?php endforeach; ?>
            </ol>
            <?php if ($isOwnProfile): ?>
                <aside class="top-eight-friend-picker" data-top-eight-picker hidden>
                    <div class="top-eight-picker-heading">
                        <h3>Friends</h3>
                        <button type="button" data-top-eight-search-toggle aria-label="Search friends" aria-expanded="false" title="Search friends"><span class="top-eight-search-icon" aria-hidden="true"></span></button>
                    </div>
                    <div class="top-eight-picker-search" data-top-eight-search-panel hidden>
                        <label class="sr-only" for="top-eight-friend-search">Search friends</label>
                        <input id="top-eight-friend-search" type="search" placeholder="Find a friend" autocomplete="off" data-top-eight-search>
                    </div>
                    <ul data-top-eight-pool>
                        <?php foreach (array_slice($temporaryProfileUsers, $visibleTemporaryFriends) as $temporaryFriend): ?>
                            <li class="temporary-profile-friend" data-top-eight-item data-temp-user="<?= htmlspecialchars($temporaryFriend['username'], ENT_QUOTES, 'UTF-8') ?>">
                                <span class="top-friend-link">
                                    <span class="post-avatar" aria-hidden="true">T</span>
                                    <span><?= htmlspecialchars($temporaryFriend['username'], ENT_QUOTES, 'UTF-8') ?></span>
                                </span>
                                <button type="button" data-top-eight-add aria-label="Add <?= htmlspecialchars($temporaryFriend['username'], ENT_QUOTES, 'UTF-8') ?> to Top 8">&#10003;</button>
                            </li>
                        <?php endforeach; ?>
                        <?php foreach ($allFriends as $friend): ?>
                            <?php if ((int) ($friend['top_eight_position'] ?? 0) >= 1 && (int) $friend['top_eight_position'] <= 8) continue; ?>
                            <?php
                            $friendAvatar = trim($friend['avatar_path'] ?? '');
                            if ($friendAvatar !== '' && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $friendAvatar)) {
                                $friendAvatar = str_starts_with($friendAvatar, '/') ? $friendAvatar : '../' . $friendAvatar;
                            } else {
                                $friendAvatar = '';
                            }
                            ?>
                            <li data-top-eight-item data-friend-id="<?= (int) $friend['id'] ?>" data-last-interaction="<?= (int) strtotime($friend['last_interaction_at']) ?>">
                                <a class="top-friend-link" href="profile.php?id=<?= (int) $friend['id'] ?>">
                                    <span class="post-avatar" aria-hidden="true"><span><?= htmlspecialchars(mb_strtoupper(mb_substr($friend['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span><?php if ($friendAvatar): ?><img src="<?= htmlspecialchars($friendAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy"><?php endif; ?></span>
                                    <span><?= htmlspecialchars($friend['username'], ENT_QUOTES, 'UTF-8') ?></span>
                                </a>
                                <button type="button" data-top-eight-add aria-label="Add <?= htmlspecialchars($friend['username'], ENT_QUOTES, 'UTF-8') ?> to Top 8">&#10003;</button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </aside>
            <?php endif; ?>
            <?php if ($topEightError): ?><p class="top-eight-error" role="alert"><?= htmlspecialchars($topEightError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
            <?php if ($isOwnProfile): ?>
                <div class="top-eight-edit-actions" data-top-eight-actions hidden>
                    <button type="button" data-top-eight-cancel aria-label="Cancel Top 8 changes">&times;</button>
                    <button type="submit" data-top-eight-save aria-label="Save Top 8">&#10003;</button>
                </div>
            <?php endif; ?>
        </form>
        <?php $profileTopEightFragment = ob_get_clean(); ob_start(); ?>
        <nav class="profile-sidebar-actions" aria-label="Profile actions">
            <a class="profile-icon-button" href="settings.php" aria-label="Settings" title="Settings">&#9881;</a>
            <a class="profile-dashboard-button" href="../index.php" aria-label="Back to dashboard" title="Back to dashboard"><span aria-hidden="true">&larr;</span><span>Dashboard</span></a>
        </nav>
        <?php if ($friendError !== ''): ?>
            <p class="error-box" role="alert"><?= htmlspecialchars($friendError, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php $profileControlsFragment = ob_get_clean(); ob_start(); ?>
        <section class="profile-posts" aria-labelledby="profile-posts-heading" style="background-color: <?= htmlspecialchars($profilePostsBackgroundColor, ENT_QUOTES, 'UTF-8') ?>" data-profile-wallpaper>
            <span class="profile-wallpaper-surface" data-profile-wallpaper-surface<?= $profilePostsBackgroundType === 'image' ? '' : ' hidden' ?> style="filter: blur(<?= $profilePostsBackgroundBlur ?>px)<?php if ($profilePostsBackgroundType === 'image' && $profilePostsBackgroundFit === 'tile'): ?>; background-image: <?= $profilePostsBackgroundImageCss ?><?php endif; ?>">
                <img src="<?= htmlspecialchars($profilePostsBackgroundImageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" data-profile-wallpaper-preview style="object-fit: <?= $profilePostsBackgroundFit === 'contain' ? 'contain' : 'cover' ?>; object-position: <?= $profilePostsBackgroundPositionX ?>% <?= $profilePostsBackgroundPositionY ?>%; transform: scale(<?= $profilePostsBackgroundZoom ?>); transform-origin: <?= $profilePostsBackgroundPositionX ?>% <?= $profilePostsBackgroundPositionY ?>%"<?= $profilePostsBackgroundType !== 'image' || $profilePostsBackgroundFit === 'tile' ? ' hidden' : '' ?>>
            </span>
            <?php if (!$isOwnProfile): ?>
                <div class="profile-now-playing" data-profile-now-playing<?= $profileMusic ? '' : ' hidden' ?>>
                    <div class="profile-now-playing-bar">
                        <span>Now playing: <strong data-profile-now-playing-name><?= $profileMusic ? htmlspecialchars($profileMusic['name'], ENT_QUOTES, 'UTF-8') : '' ?></strong> &bull; <span data-profile-now-playing-artist><?= $profileMusic ? htmlspecialchars($profileMusic['artist'], ENT_QUOTES, 'UTF-8') : '' ?></span></span>
                        <button type="button" aria-label="Close now playing" title="Close now playing" data-profile-now-playing-close>&times;</button>
                    </div>
                </div>
            <?php endif; ?>
            <h2 id="profile-posts-heading"><?= $isOwnProfile ? 'My posts' : 'Posts' ?></h2>
            <p class="post-empty" data-post-empty<?= $profilePosts ? ' hidden' : '' ?>>No posts to show yet.</p>
            <div class="post-list" data-post-list>
            <?php foreach ($profilePosts as $post): ?>
                <article id="post-<?= (int) $post['id'] ?>" class="post-card<?= $isOwnProfile ? ' is-owned' : '' ?>" data-post-card data-post-id="<?= (int) $post['id'] ?>" data-post-created="<?= htmlspecialchars($post['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                    <header class="post-header">
                        <span class="post-author">
                            <span class="post-avatar" aria-hidden="true"><span><?= htmlspecialchars(mb_strtoupper(mb_substr($user['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span><?php if ($avatarPath): ?><img src="<?= htmlspecialchars($avatarPath, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy"><?php endif; ?></span>
                            <span><?= htmlspecialchars($profileDisplayName, ENT_QUOTES, 'UTF-8') ?></span>
                        </span>
                        <div class="post-meta"><time datetime="<?= htmlspecialchars(str_replace(' ', 'T', $post['created_at']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(date('M j, Y \a\t H:i', strtotime($post['created_at'])), ENT_QUOTES, 'UTF-8') ?></time><?php if (!empty($post['edited_at'])): ?> &middot; <span data-post-edited>Edited</span><?php endif; ?><?php if ($isOwnProfile): ?> &middot; <?= $post['visibility'] === 'public' ? 'Public' : 'Friends Only' ?><?php endif; ?></div>
                    </header>
                    <?php if ($post['title'] !== ''): ?><h3 class="post-title" data-post-title><?= htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8') ?></h3><?php endif; ?>
                    <p class="post-content" data-post-content><?= htmlspecialchars($post['content'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php renderPostMedia($post, '../', $isOwnProfile); ?>
                    <?php if ($isOwnProfile) renderPostEditForm($post); ?>
                    <?php renderPostInteractions($post, $postInteractions[(int) $post['id']] ?? [], $currentUserId, '../'); ?>
                </article>
            <?php endforeach; ?>
            </div>
            <button class="post-load-more" type="button" data-post-load-more<?= $profileHasMorePosts ? '' : ' hidden' ?>>Load more</button>
            <?php if ($isOwnProfile): ?><button class="profile-wallpaper-edit-corner" type="button" aria-label="Customize profile wallpaper" title="Customize profile wallpaper" aria-controls="profile-appearance-panel" aria-expanded="false" data-profile-appearance-toggle>&#9998;</button><?php endif; ?>
        </section>
        <?php $profilePostsFragment = ob_get_clean(); ?>
        <?php
        $profileFragments = [
            'profile_header' => $profileHeaderFragment,
            'bio' => $profileBioFragment,
            'top_eight' => $profileTopEightFragment,
            'posts' => $profilePostsFragment,
        ];
        $safeProfileTemplate = sanitizeProfileTemplate($profileCustomization['template_html']);
        if (trim($safeProfileTemplate) === '') $safeProfileTemplate = sanitizeProfileTemplate(DEFAULT_PROFILE_TEMPLATE);
        $safeProfileTemplate = appendLockedProfileControlsPlaceholder($safeProfileTemplate);
        $renderedProfileTemplate = strtr($safeProfileTemplate, [
            '{{profile_header}}' => $profileFragments['profile_header'],
            '{{bio}}' => $profileFragments['bio'],
            '{{top_eight}}' => $profileFragments['top_eight'],
            '{{posts}}' => $profileFragments['posts'],
            '{{profile_controls}}' => $profileControlsFragment,
        ]);
        ?>
        <div class="profile-custom-content"><?= $renderedProfileTemplate ?></div>
        <small class="profile-page-copyright">&copy;2026 NexusSpace</small>
        <?php endif; ?>

    </main>
    <?php if ($user && $isOwnProfile): ?>
        <aside class="profile-appearance-panel" id="profile-appearance-panel" data-profile-appearance-panel data-open="<?= $profileAppearancePanelOpen ? 'true' : 'false' ?>" data-saved-image="<?= htmlspecialchars($profilePostsBackgroundImageUrl, ENT_QUOTES, 'UTF-8') ?>" aria-label="Profile appearance" hidden>
            <header class="profile-appearance-header">
                <h2>Appearance</h2>
                <button type="button" aria-label="Close profile appearance" title="Close" data-profile-appearance-close>&times;</button>
            </header>
            <form class="profile-appearance-content" id="profile-wallpaper-form" method="post" action="profile.php?id=<?= $currentUserId ?>" enctype="multipart/form-data">
                <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['profile_edit_token'], ENT_QUOTES, 'UTF-8') ?>">
                <section>
                    <h3>Profile wallpaper</h3>
                    <fieldset class="profile-wallpaper-modes">
                        <legend class="sr-only">Wallpaper type</legend>
                        <label><input type="radio" name="background_type" value="color"<?= $profilePostsBackgroundType === 'color' ? ' checked' : '' ?> data-profile-wallpaper-mode> Color</label>
                        <label><input type="radio" name="background_type" value="image"<?= $profilePostsBackgroundType === 'image' ? ' checked' : '' ?> data-profile-wallpaper-mode> Image</label>
                    </fieldset>
                    <div data-profile-wallpaper-color-option<?= $profilePostsBackgroundType === 'color' ? '' : ' hidden' ?>>
                        <label for="profile-wallpaper-color">Background color</label>
                        <div class="profile-wallpaper-color-row">
                            <input id="profile-wallpaper-color" type="color" name="background_color" value="<?= htmlspecialchars($profilePostsBackgroundColor, ENT_QUOTES, 'UTF-8') ?>" data-profile-wallpaper-color>
                            <output for="profile-wallpaper-color" data-profile-wallpaper-value><?= htmlspecialchars($profilePostsBackgroundColor, ENT_QUOTES, 'UTF-8') ?></output>
                        </div>
                    </div>
                    <div class="profile-wallpaper-image-option" data-profile-wallpaper-image-option<?= $profilePostsBackgroundType === 'image' ? '' : ' hidden' ?>>
                        <label for="profile-wallpaper-image"><?= $profilePostsBackgroundImageUrl !== '' ? 'Replace background image' : 'Choose background image' ?></label>
                        <?php if ($profilePostsBackgroundImageUrl !== ''): ?><img class="profile-wallpaper-thumbnail" src="<?= htmlspecialchars($profilePostsBackgroundImageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Current profile wallpaper" data-profile-wallpaper-thumbnail><?php else: ?><div class="profile-wallpaper-thumbnail is-empty" data-profile-wallpaper-thumbnail>No image selected</div><?php endif; ?>
                        <input type="hidden" name="MAX_FILE_SIZE" value="5242880">
                        <input type="hidden" name="image_position_x" value="<?= $profilePostsBackgroundPositionX ?>" data-profile-wallpaper-position-x>
                        <input type="hidden" name="image_position_y" value="<?= $profilePostsBackgroundPositionY ?>" data-profile-wallpaper-position-y>
                        <input type="hidden" name="image_zoom" value="<?= $profilePostsBackgroundZoom ?>" data-profile-wallpaper-zoom-value>
                        <input id="profile-wallpaper-image" type="file" name="background_image" accept="image/jpeg,image/png,image/webp,image/gif" data-profile-wallpaper-image>
                        <?php if ($profilePostsBackgroundImageUrl !== ''): ?><button type="submit" class="button-secondary profile-wallpaper-remove" name="action" value="remove_profile_wallpaper_image" formnovalidate>Remove image</button><?php endif; ?>
                        <fieldset class="profile-wallpaper-fit">
                            <legend>Display</legend>
                            <label><input type="radio" name="image_fit" value="cover"<?= $profilePostsBackgroundFit === 'cover' ? ' checked' : '' ?> data-profile-wallpaper-fit> Fill</label>
                            <label><input type="radio" name="image_fit" value="contain"<?= $profilePostsBackgroundFit === 'contain' ? ' checked' : '' ?> data-profile-wallpaper-fit> Fit</label>
                            <label><input type="radio" name="image_fit" value="tile"<?= $profilePostsBackgroundFit === 'tile' ? ' checked' : '' ?> data-profile-wallpaper-fit> Tile</label>
                        </fieldset>
                        <label class="profile-wallpaper-blur" for="profile-wallpaper-blur">
                            <span>Blur <output for="profile-wallpaper-blur" data-profile-wallpaper-blur-value><?= $profilePostsBackgroundBlur ?>px</output></span>
                            <input id="profile-wallpaper-blur" type="range" name="image_blur" min="0" max="20" step="1" value="<?= $profilePostsBackgroundBlur ?>" data-profile-wallpaper-blur>
                        </label>
                    </div>
                </section>
                <section class="profile-copy-settings">
                    <label for="profile-wallpaper-copy-source">Copy settings from</label>
                    <div>
                        <select id="profile-wallpaper-copy-source" name="source_region">
                            <?php foreach ($wallpaperCopySources as $region => $label): ?>
                                <option value="<?= htmlspecialchars($region, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="action" value="copy_profile_wallpaper" formnovalidate>Copy</button>
                    </div>
                </section>
                <?php if ($profileAppearanceError !== ''): ?><p class="profile-appearance-status is-error" role="alert"><?= htmlspecialchars($profileAppearanceError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                <div class="profile-appearance-actions">
                    <button type="submit" name="action" value="update_profile_wallpaper">Save</button>
                    <button type="button" class="button-secondary" data-profile-wallpaper-reset-open>Reset</button>
                </div>
            </form>
        </aside>
        <aside class="profile-cover-panel" id="profile-cover-panel" data-profile-cover-panel data-open="<?= $profileCoverPanelOpen ? 'true' : 'false' ?>" data-saved-image="<?= htmlspecialchars($profileCoverBackgroundImageUrl, ENT_QUOTES, 'UTF-8') ?>" aria-label="Profile cover customization" hidden>
            <header class="profile-appearance-header">
                <h2>Profile cover</h2>
                <button type="button" aria-label="Close profile cover customization" title="Close" data-profile-cover-close>&times;</button>
            </header>
            <form class="profile-appearance-content" id="profile-cover-form" method="post" action="profile.php?id=<?= $currentUserId ?>" enctype="multipart/form-data">
                <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['profile_edit_token'], ENT_QUOTES, 'UTF-8') ?>">
                <section>
                    <fieldset class="profile-wallpaper-modes">
                        <legend class="sr-only">Cover type</legend>
                        <label><input type="radio" name="background_type" value="color"<?= $profileCoverBackgroundType === 'color' ? ' checked' : '' ?> data-profile-cover-mode> Color</label>
                        <label><input type="radio" name="background_type" value="image"<?= $profileCoverBackgroundType === 'image' ? ' checked' : '' ?> data-profile-cover-mode> Image</label>
                    </fieldset>
                    <div data-profile-cover-color-option<?= $profileCoverBackgroundType === 'color' ? '' : ' hidden' ?>>
                        <label for="profile-cover-color">Background color</label>
                        <div class="profile-wallpaper-color-row">
                            <input id="profile-cover-color" type="color" name="background_color" value="<?= htmlspecialchars($profileCoverBackgroundColor, ENT_QUOTES, 'UTF-8') ?>" data-profile-cover-color>
                            <output for="profile-cover-color" data-profile-cover-value><?= htmlspecialchars($profileCoverBackgroundColor, ENT_QUOTES, 'UTF-8') ?></output>
                        </div>
                    </div>
                    <div class="profile-wallpaper-image-option" data-profile-cover-image-option<?= $profileCoverBackgroundType === 'image' ? '' : ' hidden' ?>>
                        <label for="profile-cover-image"><?= $profileCoverBackgroundImageUrl !== '' ? 'Replace cover image' : 'Choose cover image' ?></label>
                        <?php if ($profileCoverBackgroundImageUrl !== ''): ?><img class="profile-wallpaper-thumbnail profile-cover-thumbnail" src="<?= htmlspecialchars($profileCoverBackgroundImageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Current profile cover" data-profile-cover-thumbnail><?php else: ?><div class="profile-wallpaper-thumbnail profile-cover-thumbnail is-empty" data-profile-cover-thumbnail>No image selected</div><?php endif; ?>
                        <input type="hidden" name="MAX_FILE_SIZE" value="5242880">
                        <input type="hidden" name="image_position_x" value="<?= $profileCoverBackgroundPositionX ?>" data-profile-cover-position-x>
                        <input type="hidden" name="image_position_y" value="<?= $profileCoverBackgroundPositionY ?>" data-profile-cover-position-y>
                        <input type="hidden" name="image_zoom" value="<?= $profileCoverBackgroundZoom ?>" data-profile-cover-zoom-value>
                        <input id="profile-cover-image" type="file" name="background_image" accept="image/jpeg,image/png,image/webp,image/gif" data-profile-cover-image>
                        <?php if ($profileCoverBackgroundImageUrl !== ''): ?><button type="submit" class="button-secondary profile-wallpaper-remove" name="action" value="remove_profile_cover_image" formnovalidate>Remove image</button><?php endif; ?>
                        <fieldset class="profile-wallpaper-fit">
                            <legend>Display</legend>
                            <label><input type="radio" name="image_fit" value="cover"<?= $profileCoverBackgroundFit === 'cover' ? ' checked' : '' ?> data-profile-cover-fit> Fill</label>
                            <label><input type="radio" name="image_fit" value="contain"<?= $profileCoverBackgroundFit === 'contain' ? ' checked' : '' ?> data-profile-cover-fit> Fit</label>
                            <label><input type="radio" name="image_fit" value="tile"<?= $profileCoverBackgroundFit === 'tile' ? ' checked' : '' ?> data-profile-cover-fit> Tile</label>
                        </fieldset>
                        <label class="profile-wallpaper-blur" for="profile-cover-blur">
                            <span>Blur <output for="profile-cover-blur" data-profile-cover-blur-value><?= $profileCoverBackgroundBlur ?>px</output></span>
                            <input id="profile-cover-blur" type="range" name="image_blur" min="0" max="20" step="1" value="<?= $profileCoverBackgroundBlur ?>" data-profile-cover-blur>
                        </label>
                    </div>
                </section>
                <section class="profile-copy-settings">
                    <label for="profile-cover-copy-source">Copy settings from</label>
                    <div>
                        <select id="profile-cover-copy-source" name="source_region">
                            <?php foreach ($coverCopySources as $region => $label): ?>
                                <option value="<?= htmlspecialchars($region, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="action" value="copy_profile_cover" formnovalidate>Copy</button>
                    </div>
                </section>
                <?php if ($profileCoverError !== ''): ?><p class="profile-appearance-status is-error" role="alert"><?= htmlspecialchars($profileCoverError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                <div class="profile-appearance-actions">
                    <button type="submit" name="action" value="update_profile_cover">Save</button>
                    <button type="button" class="button-secondary" data-profile-cover-reset-open>Reset</button>
                </div>
            </form>
        </aside>
        <dialog class="messages-image-crop-dialog profile-wallpaper-crop-dialog" data-profile-wallpaper-crop-dialog>
            <div class="messages-image-crop-heading">
                <h2>Frame profile wallpaper</h2>
                <button type="button" data-profile-wallpaper-crop-cancel aria-label="Close profile wallpaper editor">&times;</button>
            </div>
            <div class="messages-image-crop-stage profile-wallpaper-crop-stage">
                <canvas width="1280" height="720" data-profile-wallpaper-crop-canvas aria-label="Profile wallpaper crop preview"></canvas>
                <p data-profile-wallpaper-crop-status role="status">Loading preview...</p>
            </div>
            <label class="messages-image-zoom-control" for="profile-wallpaper-crop-zoom">
                <span>Zoom</span>
                <input id="profile-wallpaper-crop-zoom" type="range" min="1" max="3" step="0.01" value="1" data-profile-wallpaper-crop-zoom>
            </label>
            <div class="messages-image-crop-actions">
                <button type="button" class="button-secondary" data-profile-wallpaper-crop-cancel>Cancel</button>
                <button type="button" data-profile-wallpaper-crop-save aria-label="Use framed profile wallpaper">&#10003;</button>
            </div>
        </dialog>
        <dialog class="messages-image-crop-dialog profile-cover-crop-dialog" data-profile-cover-crop-dialog>
            <div class="messages-image-crop-heading">
                <h2>Frame profile cover</h2>
                <button type="button" data-profile-cover-crop-cancel aria-label="Close profile cover editor">&times;</button>
            </div>
            <div class="messages-image-crop-stage profile-cover-crop-stage">
                <canvas width="1280" height="480" data-profile-cover-crop-canvas aria-label="Profile cover crop preview"></canvas>
                <p data-profile-cover-crop-status role="status">Loading preview...</p>
            </div>
            <label class="messages-image-zoom-control" for="profile-cover-crop-zoom">
                <span>Zoom</span>
                <input id="profile-cover-crop-zoom" type="range" min="1" max="3" step="0.01" value="1" data-profile-cover-crop-zoom>
            </label>
            <div class="messages-image-crop-actions">
                <button type="button" class="button-secondary" data-profile-cover-crop-cancel>Cancel</button>
                <button type="button" data-profile-cover-crop-save aria-label="Use framed profile cover">&#10003;</button>
            </div>
        </dialog>
        <dialog class="messages-reset-dialog" data-profile-wallpaper-reset-dialog>
            <div class="messages-reset-heading">
                <h2>Reset appearance?</h2>
                <button type="button" data-profile-wallpaper-reset-cancel aria-label="Close reset confirmation">&times;</button>
            </div>
            <p>Are you sure you want to reset your settings completely? Your settings will go into the void and never return.</p>
            <div class="messages-reset-actions">
                <button type="button" class="button-secondary" data-profile-wallpaper-reset-cancel>Cancel</button>
                <button type="submit" form="profile-wallpaper-form" name="action" value="reset_profile_wallpaper" formnovalidate>Reset everything</button>
            </div>
        </dialog>
        <dialog class="messages-reset-dialog" data-profile-cover-reset-dialog>
            <div class="messages-reset-heading">
                <h2>Reset profile cover?</h2>
                <button type="button" data-profile-cover-reset-cancel aria-label="Close cover reset confirmation">&times;</button>
            </div>
            <p>Are you sure you want to reset the profile cover completely? These settings cannot be recovered.</p>
            <div class="messages-reset-actions">
                <button type="button" class="button-secondary" data-profile-cover-reset-cancel>Cancel</button>
                <button type="submit" form="profile-cover-form" name="action" value="reset_profile_cover" formnovalidate>Reset cover</button>
            </div>
        </dialog>
        <dialog class="avatar-crop-dialog" data-avatar-crop-dialog>
            <div class="avatar-crop-heading">
                <h2>Crop profile picture</h2>
                <button type="button" data-avatar-crop-cancel aria-label="Close crop editor">&times;</button>
            </div>
            <div class="avatar-crop-stage">
                <canvas width="512" height="512" data-avatar-crop-canvas aria-label="Profile picture crop preview"></canvas>
            </div>
            <label class="avatar-zoom-control" for="avatar-crop-zoom">
                <span>Zoom</span>
                <input id="avatar-crop-zoom" type="range" min="1" max="3" step="0.01" value="1" data-avatar-crop-zoom>
            </label>
            <div class="avatar-crop-actions">
                <button type="button" class="button-secondary" data-avatar-crop-cancel>Cancel</button>
                <button type="button" data-avatar-crop-save aria-label="Use cropped profile picture">&#10003;</button>
            </div>
        </dialog>
    <?php endif; ?>
</body>
</html>
