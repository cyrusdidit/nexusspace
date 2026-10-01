<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/background_customization.php';
require_once __DIR__ . '/includes/post_interactions.php';
require_once __DIR__ . '/includes/post_media.php';

session_start();

function postTimestamp(string $value): string
{
    $date = new DateTimeImmutable($value);
    $today = new DateTimeImmutable('today');
    if ($date->format('Y-m-d') === $today->format('Y-m-d')) {
        return 'Today at ' . $date->format('H:i');
    }
    if ($date->format('Y-m-d') === $today->modify('-1 day')->format('Y-m-d')) {
        return 'Yesterday at ' . $date->format('H:i');
    }
    return $date->format('M j, Y') . ' at ' . $date->format('H:i');
}

function postAvatarPath(?string $path): string
{
    $path = trim($path ?? '');
    // Only use local image paths, as in the dashboard user search.
    if ($path === '' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $path)) {
        return '';
    }
    return htmlspecialchars($path, ENT_QUOTES, 'UTF-8');
}

function detectedDurationLabel(int $seconds): string
{
    $seconds = max(0, $seconds);
    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds, 60) % 60, $seconds % 60);
}

function renderPostAuthor(array $post): void
{
    $name = htmlspecialchars($post['username'], ENT_QUOTES, 'UTF-8');
    $initial = htmlspecialchars(mb_strtoupper(mb_substr($post['username'], 0, 1)), ENT_QUOTES, 'UTF-8');
    $avatar = postAvatarPath($post['avatar_path']);
    $background = preg_match('/^#[0-9a-f]{6}$/i', $post['profile_background_color'] ?? '') ? $post['profile_background_color'] : '#ffffff';
    $color = preg_match('/^#[0-9a-f]{6}$/i', $post['profile_text_color'] ?? '') ? $post['profile_text_color'] : '#163b5c';
    ?>
    <span class="post-author-preview" data-profile-preview>
        <a class="post-author" href="pages/profile.php?id=<?= (int) $post['user_id'] ?>">
            <span class="post-avatar" aria-hidden="true"><span><?= $initial ?></span><?php if ($avatar): ?><img src="<?= $avatar ?>" alt="" loading="lazy"><?php endif; ?></span>
            <span><?= $name ?></span>
        </a>
        <aside class="profile-preview" data-profile-preview-panel hidden aria-label="<?= $name ?> profile preview" style="--preview-background: <?= $background ?>; --preview-color: <?= $color ?>">
            <div class="profile-preview-banner"></div>
            <div class="profile-preview-body">
                <span class="post-avatar profile-preview-avatar" aria-hidden="true"><span><?= $initial ?></span><?php if ($avatar): ?><img src="<?= $avatar ?>" alt="" loading="lazy"><?php endif; ?></span>
                <a class="profile-preview-name" href="pages/profile.php?id=<?= (int) $post['user_id'] ?>"><?= $name ?></a>
                <?php if (trim($post['bio'] ?? '') !== ''): ?><p><?= htmlspecialchars(mb_substr($post['bio'], 0, 300), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                <small>Member since <?= htmlspecialchars(date('F Y', strtotime($post['registration_date'])), ENT_QUOTES, 'UTF-8') ?></small>
            </div>
        </aside>
    </span>
    <?php
}

$basicStatus = '';
$statusError = '';
$postError = '';
$postContent = '';
$postTitle = '';
$postVisibility = 'friends';
$posts = [];
$hasMorePosts = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
    http_response_code(413);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'The upload is larger than the server accepts. Choose fewer or smaller files.']);
    exit;
}
$postInteractions = [];
$currentGame = null;
$currentMusic = null;
$dashboardAppearanceError = '';
$_SESSION['posts_csrf'] ??= bin2hex(random_bytes(32));
$_SESSION['notifications_csrf'] ??= bin2hex(random_bytes(32));
$_SESSION['dashboard_appearance_csrf'] ??= bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_dashboard_background') {
    $dashboardAppearanceJson = ($_POST['response_format'] ?? '') === 'json'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    if (!isset($_SESSION['user_id'])) {
        if ($dashboardAppearanceJson) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Log in again to save your background.']);
            exit;
        }
        header('Location: pages/login.php');
        exit;
    }

    $token = $_POST['csrf_token'] ?? '';
    $submittedType = is_string($_POST['background_type'] ?? null) ? $_POST['background_type'] : '';
    $submittedColor = is_string($_POST['background_color'] ?? null) ? strtolower($_POST['background_color']) : '';
    $submittedFit = is_string($_POST['image_fit'] ?? null) ? $_POST['image_fit'] : '';
    $submittedBlur = filter_var($_POST['image_blur'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 20]]);
    $submittedPositionX = is_numeric($_POST['image_position_x'] ?? null) ? (float) $_POST['image_position_x'] : -1;
    $submittedPositionY = is_numeric($_POST['image_position_y'] ?? null) ? (float) $_POST['image_position_y'] : -1;
    $submittedZoom = is_numeric($_POST['image_zoom'] ?? null) ? (float) $_POST['image_zoom'] : -1;
    if (!is_string($token) || !hash_equals($_SESSION['dashboard_appearance_csrf'], $token)) {
        $dashboardAppearanceError = 'Your session changed. Please refresh the page and try again.';
    } elseif (!in_array($submittedType, ['color', 'image'], true)) {
        $dashboardAppearanceError = 'Choose a background type.';
    } elseif (!preg_match('/^#[0-9a-f]{6}$/', $submittedColor)) {
        $dashboardAppearanceError = 'Choose a valid background color.';
    } elseif (!in_array($submittedFit, USER_BACKGROUND_IMAGE_FITS, true) || $submittedBlur === false) {
        $dashboardAppearanceError = 'Choose valid image display settings.';
    } elseif ($submittedPositionX < 0 || $submittedPositionX > 100 || $submittedPositionY < 0 || $submittedPositionY > 100 || $submittedZoom < 1 || $submittedZoom > 3) {
        $dashboardAppearanceError = 'Choose valid image crop settings.';
    } else {
        $appearanceUserId = (int) $_SESSION['user_id'];
        $appearanceSettings = readUserBackgroundSettings($conn, $appearanceUserId);
        $currentDashboardSetting = $appearanceSettings['dashboard_feed'];
        $previousImagePath = $currentDashboardSetting['image_path'];
        $savedImagePath = $previousImagePath;
        try {
            if ($submittedType === 'image') {
                $upload = is_array($_FILES['background_image'] ?? null) ? $_FILES['background_image'] : ['error' => UPLOAD_ERR_NO_FILE];
                if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $savedImagePath = storeUserBackgroundImage($upload, $appearanceUserId);
                } elseif (!$savedImagePath) {
                    throw new InvalidArgumentException('Choose an image to upload.');
                }
            }
            saveUserBackgroundSetting(
                $conn,
                $appearanceUserId,
                'dashboard_feed',
                $submittedType,
                $submittedColor,
                $savedImagePath,
                null,
                $submittedFit,
                $submittedBlur,
                $submittedPositionX,
                $submittedPositionY,
                $submittedZoom
            );
            if ($previousImagePath && $savedImagePath !== $previousImagePath) {
                deleteUserBackgroundImageIfUnused($conn, $previousImagePath, $appearanceUserId);
            }
            if ($dashboardAppearanceJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'backgroundType' => $submittedType,
                    'backgroundColor' => $submittedColor,
                    'imageUrl' => $savedImagePath ?? '',
                    'imageFit' => $submittedFit,
                    'imageBlur' => $submittedBlur,
                    'positionX' => $submittedPositionX,
                    'positionY' => $submittedPositionY,
                    'zoom' => $submittedZoom,
                ]);
                exit;
            }
            header('Location: index.php');
            exit;
        } catch (InvalidArgumentException | RuntimeException $exception) {
            if ($savedImagePath && $savedImagePath !== $previousImagePath) {
                deleteUserBackgroundImage($savedImagePath, $appearanceUserId);
            }
            $dashboardAppearanceError = $exception->getMessage();
        }
    }
    if ($dashboardAppearanceJson && $dashboardAppearanceError !== '') {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $dashboardAppearanceError]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['remove_dashboard_background_image', 'reset_dashboard_background'], true)) {
    $dashboardAppearanceJson = ($_POST['response_format'] ?? '') === 'json'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    if (!isset($_SESSION['user_id'])) {
        if ($dashboardAppearanceJson) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Log in again to update your background.']);
            exit;
        }
        header('Location: pages/login.php');
        exit;
    }

    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['dashboard_appearance_csrf'], $token)) {
        if ($dashboardAppearanceJson) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Your session changed. Please refresh the page and try again.']);
            exit;
        }
        $dashboardAppearanceError = 'Your session changed. Please refresh the page and try again.';
    } else {
        $appearanceUserId = (int) $_SESSION['user_id'];
        $appearanceSettings = readUserBackgroundSettings($conn, $appearanceUserId);
        $currentDashboardSetting = $appearanceSettings['dashboard_feed'];
        $previousImagePath = $currentDashboardSetting['image_path'];
        $isReset = $_POST['action'] === 'reset_dashboard_background';
        $nextColor = $isReset ? USER_BACKGROUND_DEFAULTS['dashboard_feed'] : $currentDashboardSetting['color_value'];
        $nextFit = $isReset ? 'cover' : $currentDashboardSetting['image_fit'];
        $nextBlur = $isReset ? 0 : $currentDashboardSetting['image_blur'];
        $nextPositionX = $isReset ? 50.0 : $currentDashboardSetting['image_position_x'];
        $nextPositionY = $isReset ? 50.0 : $currentDashboardSetting['image_position_y'];
        $nextZoom = $isReset ? 1.0 : $currentDashboardSetting['image_zoom'];
        saveUserBackgroundSetting($conn, $appearanceUserId, 'dashboard_feed', 'color', $nextColor, null, null, $nextFit, $nextBlur, $nextPositionX, $nextPositionY, $nextZoom);
        deleteUserBackgroundImageIfUnused($conn, $previousImagePath, $appearanceUserId);

        if ($dashboardAppearanceJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'backgroundType' => 'color',
                'backgroundColor' => $nextColor,
                'imageUrl' => '',
                'imageFit' => $nextFit,
                'imageBlur' => $nextBlur,
                'positionX' => $nextPositionX,
                'positionY' => $nextPositionY,
                'zoom' => $nextZoom,
                'notice' => $isReset ? 'Settings reset, no going back now!' : 'Background image removed. Your color was kept.',
            ]);
            exit;
        }
        header('Location: index.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'copy_dashboard_background') {
    $dashboardAppearanceJson = ($_POST['response_format'] ?? '') === 'json'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    $copySources = [
        'messages' => 'Messages',
        'profile_posts' => 'Profile wallpaper',
        'profile_cover' => 'Profile cover',
    ];
    if (!isset($_SESSION['user_id'])) {
        if ($dashboardAppearanceJson) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Log in again to copy appearance settings.']);
            exit;
        }
        header('Location: pages/login.php');
        exit;
    }

    $token = $_POST['csrf_token'] ?? '';
    $sourceRegion = is_string($_POST['source_region'] ?? null) ? $_POST['source_region'] : '';
    if (!is_string($token) || !hash_equals($_SESSION['dashboard_appearance_csrf'], $token)) {
        $dashboardAppearanceError = 'Your session changed. Please refresh the page and try again.';
    } elseif (!isset($copySources[$sourceRegion])) {
        $dashboardAppearanceError = 'Choose a section to copy settings from.';
    } else {
        $appearanceUserId = (int) $_SESSION['user_id'];
        $appearanceSettings = readUserBackgroundSettings($conn, $appearanceUserId);
        $sourceBackground = resolveUserBackground($appearanceSettings, $sourceRegion);
        $previousImagePath = $appearanceSettings['dashboard_feed']['image_path'];
        $copiedImagePath = null;
        try {
            $copiedImagePath = duplicateUserBackgroundImage($sourceBackground['image_path'], $appearanceUserId);
            $copiedType = $sourceBackground['background_type'] === 'image' && $copiedImagePath !== null ? 'image' : 'color';
            saveUserBackgroundSetting(
                $conn,
                $appearanceUserId,
                'dashboard_feed',
                $copiedType,
                $sourceBackground['color_value'],
                $copiedImagePath,
                null,
                $sourceBackground['image_fit'],
                $sourceBackground['image_blur'],
                $sourceBackground['image_position_x'],
                $sourceBackground['image_position_y'],
                $sourceBackground['image_zoom']
            );
            if ($previousImagePath !== $copiedImagePath) {
                deleteUserBackgroundImageIfUnused($conn, $previousImagePath, $appearanceUserId);
            }
            if ($dashboardAppearanceJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'backgroundType' => $copiedType,
                    'backgroundColor' => $sourceBackground['color_value'],
                    'imageUrl' => $copiedImagePath ?? '',
                    'imageFit' => $sourceBackground['image_fit'],
                    'imageBlur' => $sourceBackground['image_blur'],
                    'positionX' => $sourceBackground['image_position_x'],
                    'positionY' => $sourceBackground['image_position_y'],
                    'zoom' => $sourceBackground['image_zoom'],
                    'notice' => 'Settings copied from ' . $copySources[$sourceRegion] . '.',
                ]);
                exit;
            }
            header('Location: index.php');
            exit;
        } catch (InvalidArgumentException | RuntimeException $exception) {
            if ($copiedImagePath !== null) deleteUserBackgroundImage($copiedImagePath, $appearanceUserId);
            $dashboardAppearanceError = $exception->getMessage();
        }
    }

    if ($dashboardAppearanceJson && $dashboardAppearanceError !== '') {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $dashboardAppearanceError]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['create_post', 'delete_post'], true)) {
    $postJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    if (!isset($_SESSION['user_id'])) {
        if ($postJson) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Log in again to publish this post.']);
            exit;
        }
        header('Location: pages/login.php');
        exit;
    }
    $postContent = is_string($_POST['content'] ?? null) ? trim($_POST['content']) : '';
    $postTitle = is_string($_POST['title'] ?? null) ? trim($_POST['title']) : '';
    $postVisibility = is_string($_POST['visibility'] ?? null) ? $_POST['visibility'] : 'friends';
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['posts_csrf'], $token)) {
        $postError = 'Your session changed. Please try again.';
    } elseif ($_POST['action'] === 'create_post') {
        $postUpload = is_array($_FILES['post_media'] ?? null) ? $_FILES['post_media'] : ['error' => UPLOAD_ERR_NO_FILE];
        $postMediaCount = postMediaUploadCount($postUpload);
        if (mb_strlen($postTitle, 'UTF-8') > 100) {
            $postError = 'Keep the post title under 100 characters.';
        } elseif (!in_array($postVisibility, ['friends', 'public'], true)) {
            $postError = 'Choose Friends Only or Public.';
        } elseif ($postMediaCount > POST_MEDIA_MAX_FILES) {
            $postError = 'Attach up to five media files per post.';
        } elseif (mb_strlen($postContent, 'UTF-8') > 2500 || ($postTitle === '' && $postContent === '' && $postMediaCount === 0)) {
            $postError = 'Add a title, write something, or attach media, using no more than 2,500 characters.';
        } else {
            $authorId = (int) $_SESSION['user_id'];
            $storedMedia = [];
            try {
                $storedMedia = storePostMediaBatch($postUpload, $authorId);
                mysqli_begin_transaction($conn);
                $statement = mysqli_prepare($conn, 'INSERT INTO posts (user_id, title, content, visibility) VALUES (?, ?, ?, ?)');
                mysqli_stmt_bind_param($statement, 'isss', $authorId, $postTitle, $postContent, $postVisibility);
                mysqli_stmt_execute($statement);
                $newPostId = (int) mysqli_insert_id($conn);
                mysqli_stmt_close($statement);
                foreach ($storedMedia as $sortOrder => $media) {
                    $statement = mysqli_prepare($conn, 'INSERT INTO post_media (post_id, media_path, media_type, sort_order) VALUES (?, ?, ?, ?)');
                    mysqli_stmt_bind_param($statement, 'issi', $newPostId, $media['path'], $media['type'], $sortOrder);
                    mysqli_stmt_execute($statement);
                    mysqli_stmt_close($statement);
                }
                mysqli_commit($conn);
                if ($postJson) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => true, 'postId' => $newPostId]);
                    exit;
                }
                header('Location: index.php');
                exit;
            } catch (Throwable $exception) {
                @mysqli_rollback($conn);
                foreach ($storedMedia as $media) deletePostMedia($media['path'], $authorId);
                $postError = $exception instanceof InvalidArgumentException || $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'The post could not be published. Please try again.';
            }
        }
    } else {
        $postId = filter_var($_POST['post_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$postId) {
            $postError = 'Choose a valid post.';
        } else {
            $authorId = (int) $_SESSION['user_id'];
            $deletedMedia = loadPostMedia($conn, [$postId])[$postId] ?? [];
            $statement = mysqli_prepare($conn, 'SELECT media_path FROM posts WHERE id = ? AND user_id = ? LIMIT 1');
            mysqli_stmt_bind_param($statement, 'ii', $postId, $authorId);
            mysqli_stmt_execute($statement);
            $deletedPost = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
            mysqli_stmt_close($statement);
            $statement = mysqli_prepare($conn, 'DELETE FROM posts WHERE id = ? AND user_id = ?');
            mysqli_stmt_bind_param($statement, 'ii', $postId, $authorId);
            mysqli_stmt_execute($statement);
            mysqli_stmt_close($statement);
            if ($deletedPost) deletePostMedia($deletedPost['media_path'], $authorId);
            foreach ($deletedMedia as $media) deletePostMedia($media['media_path'], $authorId);
            header('Location: index.php');
            exit;
        }
    }
    if ($postJson && $postError !== '') {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $postError]);
        exit;
    }
}

if (isset($_SESSION['user_id'])) {
    $userId = (int) $_SESSION['user_id'];
    $dashboardBackgroundSettings = readUserBackgroundSettings($conn, $userId);
    $dashboardBackground = resolveUserBackground($dashboardBackgroundSettings, 'dashboard_feed');
    $dashboardBackgroundColor = $dashboardBackground['color_value'];
    $dashboardBackgroundType = $dashboardBackground['background_type'] === 'image' ? 'image' : 'color';
    $dashboardBackgroundImageUrl = $dashboardBackground['image_path'] ?? '';
    $dashboardBackgroundFit = $dashboardBackground['image_fit'];
    $dashboardBackgroundBlur = $dashboardBackground['image_blur'];
    $dashboardBackgroundPositionX = $dashboardBackground['image_position_x'];
    $dashboardBackgroundPositionY = $dashboardBackground['image_position_y'];
    $dashboardBackgroundZoom = $dashboardBackground['image_zoom'];
    if (isset($submittedType) && in_array($submittedType, ['color', 'image'], true)) {
        $dashboardBackgroundType = $submittedType;
    }
    if (isset($submittedColor) && preg_match('/^#[0-9a-f]{6}$/', $submittedColor)) {
        $dashboardBackgroundColor = $submittedColor;
    }
    $feedStatement = mysqli_prepare($conn, "SELECT p.id, p.user_id, p.title, p.content, p.media_path, p.media_type, p.visibility, p.created_at, p.edited_at, u.username, u.avatar_path, u.bio, u.profile_background_color, u.profile_text_color, u.registration_date FROM posts p JOIN users u ON u.id = p.user_id WHERE (p.visibility = 'public' OR p.user_id = ? OR EXISTS (SELECT 1 FROM friends f WHERE (f.user_id = ? AND f.friend_id = p.user_id) OR (f.friend_id = ? AND f.user_id = p.user_id))) AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_id = ? AND b.blocked_id = p.user_id) OR (b.blocker_id = p.user_id AND b.blocked_id = ?)) ORDER BY p.created_at DESC, p.id DESC LIMIT 51");
    mysqli_stmt_bind_param($feedStatement, 'iiiii', $userId, $userId, $userId, $userId, $userId);
    mysqli_stmt_execute($feedStatement);
    $posts = mysqli_fetch_all(mysqli_stmt_get_result($feedStatement), MYSQLI_ASSOC);
    mysqli_stmt_close($feedStatement);
    $hasMorePosts = count($posts) > 50;
    if ($hasMorePosts) array_pop($posts);
    attachPostMedia($posts, loadPostMedia($conn, array_column($posts, 'id')));
    $postInteractions = loadPostInteractions($conn, array_column($posts, 'id'), $userId);
    $topStatement = mysqli_prepare($conn, 'SELECT u.id, u.username, u.avatar_path, u.bio, u.profile_background_color, u.profile_text_color, u.registration_date FROM friends f JOIN users u ON u.id = f.friend_id WHERE f.user_id = ? AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_id = ? AND b.blocked_id = u.id) OR (b.blocker_id = u.id AND b.blocked_id = ?)) ORDER BY CASE WHEN f.top_eight_position BETWEEN 1 AND 8 THEN 0 ELSE 1 END, CASE WHEN f.top_eight_position BETWEEN 1 AND 8 THEN f.top_eight_position ELSE NULL END, u.username, u.id');
    mysqli_stmt_bind_param($topStatement, 'iii', $userId, $userId, $userId);
    mysqli_stmt_execute($topStatement);
    $topFriends = mysqli_fetch_all(mysqli_stmt_get_result($topStatement), MYSQLI_ASSOC);
    mysqli_stmt_close($topStatement);

    $activityStatement = mysqli_prepare($conn, "UPDATE users SET activity_state = 'online', last_active_at = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($activityStatement, 'i', $userId);
    mysqli_stmt_execute($activityStatement);
    mysqli_stmt_close($activityStatement);

    $statement = mysqli_prepare($conn, 'SELECT status_text, avatar_path FROM users WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($statement, 'i', $userId);
    mysqli_stmt_execute($statement);
    $result = mysqli_stmt_get_result($statement);
    $user = mysqli_fetch_assoc($result);
    mysqli_stmt_close($statement);

    if ($user) {
        $basicStatus = (string) ($user['status_text'] ?? '');
        $dashboardAvatar = postAvatarPath($user['avatar_path'] ?? null);
    }

    $statement = mysqli_prepare($conn, 'SELECT current_game_id, current_game_name, current_game_started_at FROM steam_connections WHERE user_id = ? AND current_game_id IS NOT NULL AND current_game_name IS NOT NULL LIMIT 1');
    mysqli_stmt_bind_param($statement, 'i', $userId);
    mysqli_stmt_execute($statement);
    $currentGame = mysqli_fetch_assoc(mysqli_stmt_get_result($statement)) ?: null;
    mysqli_stmt_close($statement);

    if ($currentGame) {
        $currentGameId = preg_match('/^\d+$/', (string) $currentGame['current_game_id']) ? (string) $currentGame['current_game_id'] : '';
        $currentGameImage = $currentGameId !== '' ? 'https://cdn.akamai.steamstatic.com/steam/apps/' . $currentGameId . '/header.jpg' : '';
        $currentGameElapsed = max(0, time() - (strtotime((string) $currentGame['current_game_started_at']) ?: time()));
    }

    $statement = mysqli_prepare($conn, 'SELECT current_item_name, current_artist_name, current_image_url FROM spotify_connections WHERE user_id = ? AND is_playing = 1 AND current_item_name IS NOT NULL AND TIMESTAMPDIFF(SECOND, playback_updated_at, NOW()) <= 60 LIMIT 1');
    mysqli_stmt_bind_param($statement, 'i', $userId);
    mysqli_stmt_execute($statement);
    $currentMusic = mysqli_fetch_assoc(mysqli_stmt_get_result($statement)) ?: null;
    mysqli_stmt_close($statement);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
        $basicStatus = trim($_POST['status'] ?? '');

        if (strlen($basicStatus) > 160) {
            $statusError = 'Use 160 characters or fewer.';
        } else {
            $updateStatement = mysqli_prepare($conn, 'UPDATE users SET status_text = ? WHERE id = ?');
            mysqli_stmt_bind_param($updateStatement, 'si', $basicStatus, $userId);
            mysqli_stmt_execute($updateStatement);
            mysqli_stmt_close($updateStatement);

            header('Location: index.php');
            exit;
        }
    }
} else {
    $publicResult = mysqli_query($conn, "SELECT p.id, p.user_id, p.title, p.content, p.media_path, p.media_type, p.created_at, u.username, u.avatar_path, u.bio, u.profile_background_color, u.profile_text_color, u.registration_date FROM posts p JOIN users u ON u.id = p.user_id WHERE p.visibility = 'public' ORDER BY p.created_at DESC, p.id DESC LIMIT 50");
    $posts = mysqli_fetch_all($publicResult, MYSQLI_ASSOC);
    attachPostMedia($posts, loadPostMedia($conn, array_column($posts, 'id')));
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexusSpace</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <script src="assets/js/activity.js?v=<?= filemtime(__DIR__ . '/assets/js/activity.js') ?>" defer></script>
    <script src="assets/js/steam-status.js?v=<?= filemtime(__DIR__ . '/assets/js/steam-status.js') ?>" defer></script>
    <script src="assets/js/dashboard.js?v=<?= filemtime(__DIR__ . '/assets/js/dashboard.js') ?>" defer></script>
    <script src="assets/js/media.js?v=<?= filemtime(__DIR__ . '/assets/js/media.js') ?>" defer></script>
    <script src="assets/js/profile-preview.js?v=<?= filemtime(__DIR__ . '/assets/js/profile-preview.js') ?>" defer></script>
    <script src="assets/js/message-updates.js?v=<?= filemtime(__DIR__ . '/assets/js/message-updates.js') ?>" defer></script>
    <script src="assets/js/mini-chat.js?v=<?= filemtime(__DIR__ . '/assets/js/mini-chat.js') ?>" defer></script>
    <script src="assets/js/mini-chat-resize.js?v=<?= filemtime(__DIR__ . '/assets/js/mini-chat-resize.js') ?>" defer></script>
    <script src="assets/js/message-popup.js?v=<?= filemtime(__DIR__ . '/assets/js/message-popup.js') ?>" defer></script>
    <script src="assets/js/friend-badges.js?v=<?= filemtime(__DIR__ . '/assets/js/friend-badges.js') ?>" defer></script>
    <?php if (isset($_SESSION['user_id'])): ?><script src="assets/js/posts.js?v=<?= filemtime(__DIR__ . '/assets/js/posts.js') ?>" defer></script><?php endif; ?>
</head>
<body<?= isset($_SESSION['user_id']) ? ' class="dashboard-page" data-activity-endpoint="activity-ping.php" data-spotify-activity-endpoint="spotify-activity.php" data-steam-activity-endpoint="steam-activity.php" data-post-actions-endpoint="post-actions.php" data-post-updates-endpoint="post-updates.php" data-post-csrf="' . htmlspecialchars($_SESSION['posts_csrf'], ENT_QUOTES, 'UTF-8') . '" data-post-context="dashboard" data-notifications-endpoint="notifications.php" data-notifications-csrf="' . htmlspecialchars($_SESSION['notifications_csrf'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
    <?php if (isset($_SESSION['user_id'])): ?>
        <?php
        $username = htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8');
        $initial = htmlspecialchars(strtoupper(substr($_SESSION['username'], 0, 1)), ENT_QUOTES, 'UTF-8');
        $dashboardAvatar ??= '';
        ?>
        <main class="dashboard" aria-label="NexusSpace dashboard">
            <span class="dashboard-main-background" style="background-color: <?= htmlspecialchars($dashboardBackgroundColor, ENT_QUOTES, 'UTF-8') ?>" data-dashboard-background aria-hidden="true">
                <span class="dashboard-background-surface" data-dashboard-background-surface<?= $dashboardBackgroundType === 'image' && $dashboardBackgroundImageUrl !== '' ? '' : ' hidden' ?> style="filter: blur(<?= $dashboardBackgroundBlur ?>px)<?php if ($dashboardBackgroundType === 'image' && $dashboardBackgroundFit === 'tile' && $dashboardBackgroundImageUrl !== ''): ?>; background-image: url(&quot;<?= htmlspecialchars($dashboardBackgroundImageUrl, ENT_QUOTES, 'UTF-8') ?>&quot;); background-repeat: repeat; background-size: auto<?php endif; ?>">
                    <img src="<?= htmlspecialchars($dashboardBackgroundImageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" data-dashboard-background-preview style="object-fit: <?= $dashboardBackgroundFit === 'contain' ? 'contain' : 'cover' ?>; object-position: <?= $dashboardBackgroundPositionX ?>% <?= $dashboardBackgroundPositionY ?>%; transform: scale(<?= $dashboardBackgroundZoom ?>); transform-origin: <?= $dashboardBackgroundPositionX ?>% <?= $dashboardBackgroundPositionY ?>%"<?= $dashboardBackgroundFit === 'tile' ? ' hidden' : '' ?>>
                </span>
            </span>
            <aside class="dashboard-sidebar">
                <div class="dashboard-user-row">
                    <a class="dashboard-user" href="pages/profile.php">
                        <span class="dashboard-avatar" aria-hidden="true">
                            <span class="mini-avatar"><span><?= $initial ?></span><?php if ($dashboardAvatar !== ''): ?><img src="<?= $dashboardAvatar ?>" alt=""><?php endif; ?></span>
                            <span class="activity-diamond" data-activity-indicator data-state="online"></span>
                        </span>
                        <span>@<?= $username ?></span>
                    </a>
                    <section class="notifications-panel is-collapsed" data-notifications>
                        <div class="notifications-heading">
                            <button class="notifications-toggle" type="button" aria-expanded="false" aria-controls="notifications-list" aria-label="Notifications">
                                <img class="notification-bell-icon" src="assets/images/notification-bell.png" data-notification-bell data-default-src="assets/images/notification-bell.png" data-unread-src="assets/images/notification-bell-unread.png" alt="" aria-hidden="true">
                                <span class="notification-badge" data-notification-badge hidden>0</span>
                            </button>
                        </div>
                        <div class="notifications-popover">
                            <div class="notifications-toolbar">
                                <label for="notification-filter">Filter:</label>
                                <select id="notification-filter" data-notification-filter aria-label="Filter notifications">
                                    <option value="all">All</option>
                                    <option value="likes">Likes</option>
                                    <option value="comments">Comments</option>
                                    <option value="replies">Replies</option>
                                    <option value="pins">Pins</option>
                                    <option value="messages">Messages</option>
                                    <option value="friends">Friends</option>
                                    <option value="profile">Profile</option>
                                </select>
                                <span class="notifications-unread-count" data-notification-count aria-label="0 unread notifications" hidden>0</span>
                            </div>
                            <div class="notifications-list" id="notifications-list" data-notifications-list tabindex="0" role="region" aria-label="Notifications">
                                <div data-notifications-feed></div>
                            </div>
                            <button class="notifications-read-all" type="button" data-read-all-notifications>Read all</button>
                            <div class="message-popup" data-message-popup hidden>
                                <button type="button" data-message-popup-open aria-label="Open message"></button>
                                <button type="button" data-message-popup-dismiss aria-label="Dismiss message popup">&times;</button>
                                <span class="sr-only" data-message-popup-announcement role="status" aria-live="polite"></span>
                            </div>
                        </div>
                    </section>
                    <button class="dashboard-search-toggle" type="button" aria-label="Search for users" title="Search for users" aria-expanded="false" aria-controls="dashboard-user-search" data-dashboard-search-toggle>
                        <img class="dashboard-search-icon-placeholder" src="assets/images/user-search-icon.png" alt="" aria-hidden="true">
                    </button>
                    <form class="user-search dashboard-user-search-popover" id="dashboard-user-search" role="search" action="pages/search.php" method="get" data-live-user-search hidden>
                        <label class="sr-only" for="user-search">Search for users</label>
                        <input id="user-search" name="q" type="search" placeholder="Find users" maxlength="50" autocomplete="off" aria-controls="live-user-results" required>
                        <button type="submit">Search</button>
                        <div class="live-user-results" id="live-user-results" hidden>
                            <p data-search-status role="status" aria-live="polite"></p>
                            <ul class="search-results" data-search-matches></ul>
                        </div>
                    </form>
                </div>

                <form class="status-form" method="post">
                    <input type="hidden" name="action" value="update_status">
                    <section class="status-panel" aria-label="Status information">
                        <div class="status-item">
                            <label class="sr-only" for="basic-status">Basic status</label>
                            <input id="basic-status" name="status" type="text" value="<?= htmlspecialchars($basicStatus, ENT_QUOTES, 'UTF-8') ?>" maxlength="160" autocomplete="off" placeholder="your status???" data-status-input>
                        </div>
                        <div class="status-item" data-current-music title="<?= $currentMusic ? htmlspecialchars($currentMusic['current_item_name'] . ($currentMusic['current_artist_name'] ? ' • ' . $currentMusic['current_artist_name'] : ''), ENT_QUOTES, 'UTF-8') : '' ?>"<?= $currentMusic ? '' : ' hidden' ?>>
                            <img src="<?= htmlspecialchars($currentMusic['current_image_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>" alt="" data-current-music-image<?= !empty($currentMusic['current_image_url']) ? '' : ' hidden' ?>>
                            <p><?= $currentMusic ? htmlspecialchars($currentMusic['current_item_name'] . ($currentMusic['current_artist_name'] ? ' • ' . $currentMusic['current_artist_name'] : ''), ENT_QUOTES, 'UTF-8') : '' ?></p>
                        </div>
                        <div class="status-item" data-current-game data-elapsed-seconds="<?= $currentGame ? (int) $currentGameElapsed : 0 ?>" title="<?= $currentGame ? htmlspecialchars($currentGame['current_game_name'], ENT_QUOTES, 'UTF-8') : '' ?>"<?= $currentGame ? '' : ' hidden' ?>>
                            <img src="<?= htmlspecialchars($currentGameImage ?? '', ENT_QUOTES, 'UTF-8') ?>" alt="" data-current-game-image<?= !empty($currentGameImage) ? '' : ' hidden' ?>>
                            <span>
                                <p data-current-game-name><?= $currentGame ? htmlspecialchars($currentGame['current_game_name'], ENT_QUOTES, 'UTF-8') : '' ?></p>
                                <small data-current-game-duration><?= $currentGame ? htmlspecialchars(detectedDurationLabel($currentGameElapsed), ENT_QUOTES, 'UTF-8') : '' ?></small>
                            </span>
                        </div>
                    </section>
                    <?php if ($statusError): ?>
                        <p class="status-editor-error" role="alert"><?= htmlspecialchars($statusError, ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                </form>

                <section class="friends-panel" aria-labelledby="friends-heading">
                    <div class="panel-heading">
                        <a class="friends-link" id="friends-heading" href="pages/messages.php">Friends <span class="friends-unread-total" data-unread-total hidden></span></a>
                    </div>
                    <ul class="friends-list">
                        <?php foreach ($topFriends as $friend): ?>
                            <?php
                            $friendAvatar = postAvatarPath($friend['avatar_path'] ?? null);
                            $friendName = htmlspecialchars($friend['username'], ENT_QUOTES, 'UTF-8');
                            $friendInitial = htmlspecialchars(mb_strtoupper(mb_substr($friend['username'], 0, 1)), ENT_QUOTES, 'UTF-8');
                            $friendPreviewBackground = preg_match('/^#[0-9a-f]{6}$/i', $friend['profile_background_color'] ?? '') ? $friend['profile_background_color'] : '#ffffff';
                            $friendPreviewColor = preg_match('/^#[0-9a-f]{6}$/i', $friend['profile_text_color'] ?? '') ? $friend['profile_text_color'] : '#163b5c';
                            ?>
                            <li>
                                <div class="top-friend-link">
                                    <button class="mini-avatar dashboard-friend-chat" type="button" data-top-friend-chat="<?= (int) $friend['id'] ?>" aria-label="Message <?= $friendName ?>" title="Message <?= $friendName ?>"><span><?= $friendInitial ?></span><?php if ($friendAvatar !== ''): ?><img src="<?= $friendAvatar ?>" alt=""><?php endif; ?></button>
                                    <div class="dashboard-friend-preview" data-profile-preview data-profile-preview-delay="1000" data-profile-preview-placement="right">
                                        <a class="dashboard-friend-name" data-top-friend-chat="<?= (int) $friend['id'] ?>" href="index.php?chat=<?= (int) $friend['id'] ?>"><?= $friendName ?></a>
                                        <aside class="profile-preview" data-profile-preview-panel hidden aria-label="<?= $friendName ?> profile preview" style="--preview-background: <?= htmlspecialchars($friendPreviewBackground, ENT_QUOTES, 'UTF-8') ?>; --preview-color: <?= htmlspecialchars($friendPreviewColor, ENT_QUOTES, 'UTF-8') ?>">
                                            <div class="profile-preview-banner"></div>
                                            <div class="profile-preview-body">
                                                <span class="post-avatar profile-preview-avatar" aria-hidden="true"><span><?= $friendInitial ?></span><?php if ($friendAvatar !== ''): ?><img src="<?= $friendAvatar ?>" alt="" loading="lazy"><?php endif; ?></span>
                                                <a class="profile-preview-name" href="pages/profile.php?id=<?= (int) $friend['id'] ?>"><?= $friendName ?></a>
                                                <?php if (trim($friend['bio'] ?? '') !== ''): ?><p><?= htmlspecialchars(mb_substr($friend['bio'], 0, 300), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                                                <small>Member since <?= htmlspecialchars(date('F Y', strtotime($friend['registration_date'])), ENT_QUOTES, 'UTF-8') ?></small>
                                            </div>
                                        </aside>
                                    </div>
                                </div>
                                <a class="friend-unread-diamond" data-friend-unread="<?= (int) $friend['id'] ?>" data-friend-name="<?= $friendName ?>" href="index.php?chat=<?= (int) $friend['id'] ?>" hidden></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (!$topFriends): ?><p class="top-eight-empty"><a href="pages/profile.php#profile-top-eight-heading">Choose your Top 8</a></p><?php endif; ?>
                </section>

                <nav class="sidebar-actions" aria-label="Account actions">
                    <a class="settings-link" href="pages/settings.php" aria-label="Settings" title="Settings"><span class="sidebar-action-icon" aria-hidden="true">&#9881;</span></a>
                    <button type="button" data-dashboard-appearance-toggle aria-controls="dashboard-appearance-panel" aria-expanded="false" aria-label="Appearance" title="Appearance"><span class="sidebar-action-icon" aria-hidden="true">&#9998;</span></button>
                </nav>
            </aside>

            <section class="dashboard-feed" aria-label="Post feed">
                <form class="post-composer" method="post" enctype="multipart/form-data" data-post-composer>
                    <input type="hidden" name="action" value="create_post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['posts_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                    <div class="post-composer-box<?= $postContent !== '' || $postError !== '' ? ' is-expanded' : '' ?>" data-post-composer-box>
                        <div class="post-composer-header"><label class="sr-only" for="post-title">Post title</label><input id="post-title" name="title" maxlength="100" placeholder="Post title" value="<?= htmlspecialchars($postTitle, ENT_QUOTES, 'UTF-8') ?>" data-post-title-input><div class="post-composer-header-actions"><label class="sr-only" for="post-visibility">Audience</label><span class="post-audience-select"><select id="post-visibility" name="visibility" aria-label="Post audience" data-post-audience-select><option value="friends"<?= $postVisibility === 'friends' ? ' selected' : '' ?>>Friends Only</option><option value="public"<?= $postVisibility === 'public' ? ' selected' : '' ?>>Public</option></select><span class="post-audience-value" aria-hidden="true" data-post-audience-value><?= $postVisibility === 'public' ? 'Public' : "Friends<br>Only" ?></span></span><button class="post-composer-close" type="button" aria-label="Close post composer" title="Close" data-post-composer-close>&times;</button></div></div>
                        <label class="sr-only" for="post-content">Write a post</label>
                        <textarea id="post-content" name="content" rows="4" maxlength="2500" placeholder="What's on your mind?" data-post-content><?= htmlspecialchars($postContent, ENT_QUOTES, 'UTF-8') ?></textarea>
                        <button class="post-composer-expand" type="button" aria-label="Create a post" title="Create a post" data-post-composer-expand>+</button>
                        <div class="post-composer-footer"><span class="post-character-count" data-post-character-count>0/2500</span><div class="post-composer-actions"><label class="post-media-picker" for="post-media" title="Attach media" aria-label="Attach media">+</label><input id="post-media" class="sr-only" type="file" name="post_media[]" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm" data-post-media-input multiple><button class="post-submit-button" type="submit">Post</button></div></div>
                        <div class="post-media-preview" data-post-media-preview hidden><div data-post-media-preview-content></div></div>
                    </div>
                    <div class="post-upload-state" data-post-upload-state hidden><progress max="100" value="0" data-post-upload-progress></progress><span data-post-upload-status role="status"></span></div>
                    <?php if ($postError): ?><p class="post-error" role="alert"><?= htmlspecialchars($postError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                </form>
                <p class="post-empty" data-post-empty<?= $posts ? ' hidden' : '' ?>>No posts yet.</p>
                <div class="post-list" data-post-list>
                <?php foreach ($posts as $post): ?>
                    <article id="post-<?= (int) $post['id'] ?>" class="post-card<?= (int) $post['user_id'] === $userId ? ' is-owned' : '' ?>" data-post-card data-post-id="<?= (int) $post['id'] ?>" data-post-created="<?= htmlspecialchars($post['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                        <header class="post-header">
                            <?php renderPostAuthor($post); ?>
                            <div class="post-meta"><time datetime="<?= htmlspecialchars(str_replace(' ', 'T', $post['created_at']), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($post['created_at'], ENT_QUOTES, 'UTF-8') ?>"><?= postTimestamp($post['created_at']) ?></time><?php if (!empty($post['edited_at'])): ?> &middot; <span data-post-edited>Edited</span><?php endif; ?><?php if ((int) $post['user_id'] === $userId): ?> &middot; <?= $post['visibility'] === 'public' ? 'Public' : 'Friends Only' ?><?php endif; ?></div>
                        </header>
                        <?php if ($post['title'] !== ''): ?><h3 class="post-title" data-post-title><?= htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8') ?></h3><?php endif; ?>
                        <p class="post-content" data-post-content><?= htmlspecialchars($post['content'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php renderPostMedia($post, '', (int) $post['user_id'] === $userId); ?>
                        <?php if ((int) $post['user_id'] === $userId) renderPostEditForm($post); ?>
                        <?php renderPostInteractions($post, $postInteractions[(int) $post['id']] ?? [], $userId); ?>
                    </article>
                <?php endforeach; ?>
                </div>
                <button class="post-load-more" type="button" data-post-load-more<?= $hasMorePosts ? '' : ' hidden' ?>>Load more</button>
            </section>

            <aside class="dashboard-right-rail">
                <small class="copyright dashboard-copyright">&copy;2026 NexusSpace</small>
                <section class="chat-preview mini-chat" aria-labelledby="chat-heading" data-mini-chat hidden>
                    <div class="chat-heading">
                        <button type="button" class="mini-chat-resize" data-mini-resize aria-label="Resize chat" title="Drag to resize. Arrow keys resize; double-click resets.">⤢</button>
                        <span class="mini-chat-avatar-wrap" aria-hidden="true">
                            <span class="mini-avatar" data-mini-avatar></span>
                            <span class="mini-chat-activity" data-mini-activity data-state="offline"></span>
                        </span>
                        <h2 id="chat-heading">Chat</h2>
                        <button type="button" data-mini-close aria-label="Close chat">×</button>
                    </div>
                    <div class="chat-messages" data-mini-messages role="log" aria-label="Conversation" tabindex="0"></div>
                    <p class="mini-chat-status" data-mini-status role="status"></p>
                    <form class="chat-input-placeholder" data-chat-form>
                        <label class="sr-only" for="chat-message">Type a chat message</label>
                        <input id="chat-message" name="message" type="text" placeholder="Type a message" autocomplete="off" maxlength="2000" required>
                        <button type="submit" aria-label="Send message">&gt;</button>
                    </form>
                </section>

                <aside class="dashboard-appearance-panel" id="dashboard-appearance-panel" data-dashboard-appearance-panel data-open="<?= $dashboardAppearanceError !== '' ? 'true' : 'false' ?>" data-saved-image="<?= htmlspecialchars($dashboardBackgroundImageUrl, ENT_QUOTES, 'UTF-8') ?>" aria-label="Dashboard appearance" hidden>
                    <header class="dashboard-appearance-header">
                        <h2>Appearance</h2>
                        <button type="button" aria-label="Close dashboard appearance" title="Close" data-dashboard-appearance-close>&times;</button>
                    </header>
                    <form class="dashboard-appearance-form" method="post" action="index.php" enctype="multipart/form-data" data-dashboard-appearance-form>
                        <input type="hidden" name="action" value="update_dashboard_background">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['dashboard_appearance_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                        <section>
                            <h3>Dashboard background</h3>
                            <fieldset class="dashboard-background-modes">
                                <legend class="sr-only">Background type</legend>
                                <label><input type="radio" name="background_type" value="color"<?= $dashboardBackgroundType === 'color' ? ' checked' : '' ?> data-dashboard-background-mode> Color</label>
                                <label><input type="radio" name="background_type" value="image"<?= $dashboardBackgroundType === 'image' ? ' checked' : '' ?> data-dashboard-background-mode> Image</label>
                            </fieldset>
                            <div class="dashboard-background-option" data-dashboard-color-option<?= $dashboardBackgroundType === 'color' ? '' : ' hidden' ?>>
                                <label for="dashboard-background-color">Background color</label>
                                <div class="dashboard-background-color-row">
                                    <input id="dashboard-background-color" type="color" name="background_color" value="<?= htmlspecialchars($dashboardBackgroundColor, ENT_QUOTES, 'UTF-8') ?>" data-dashboard-background-color>
                                    <output for="dashboard-background-color" data-dashboard-background-value><?= htmlspecialchars($dashboardBackgroundColor, ENT_QUOTES, 'UTF-8') ?></output>
                                </div>
                            </div>
                            <div class="dashboard-background-option" data-dashboard-image-option<?= $dashboardBackgroundType === 'image' ? '' : ' hidden' ?>>
                                <label for="dashboard-background-image"><?= $dashboardBackgroundImageUrl !== '' ? 'Replace background image' : 'Choose background image' ?></label>
                                <?php if ($dashboardBackgroundImageUrl !== ''): ?><img class="dashboard-background-thumbnail" src="<?= htmlspecialchars($dashboardBackgroundImageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Current dashboard background" data-dashboard-background-thumbnail><?php else: ?><div class="dashboard-background-thumbnail is-empty" data-dashboard-background-thumbnail>No image selected</div><?php endif; ?>
                                <input type="hidden" name="MAX_FILE_SIZE" value="5242880">
                                <input type="hidden" name="image_position_x" value="<?= $dashboardBackgroundPositionX ?>" data-dashboard-position-x>
                                <input type="hidden" name="image_position_y" value="<?= $dashboardBackgroundPositionY ?>" data-dashboard-position-y>
                                <input type="hidden" name="image_zoom" value="<?= $dashboardBackgroundZoom ?>" data-dashboard-image-zoom-value>
                                <input id="dashboard-background-image" type="file" name="background_image" accept="image/jpeg,image/png,image/webp,image/gif" data-dashboard-background-image>
                                <button type="button" class="button-secondary dashboard-image-remove" data-dashboard-image-remove<?= $dashboardBackgroundImageUrl === '' ? ' hidden' : '' ?>>Remove image</button>
                                <fieldset class="dashboard-image-fit">
                                    <legend>Display</legend>
                                    <label><input type="radio" name="image_fit" value="cover"<?= $dashboardBackgroundFit === 'cover' ? ' checked' : '' ?> data-dashboard-image-fit> Fill</label>
                                    <label><input type="radio" name="image_fit" value="contain"<?= $dashboardBackgroundFit === 'contain' ? ' checked' : '' ?> data-dashboard-image-fit> Fit</label>
                                    <label><input type="radio" name="image_fit" value="tile"<?= $dashboardBackgroundFit === 'tile' ? ' checked' : '' ?> data-dashboard-image-fit> Tile</label>
                                </fieldset>
                                <label class="dashboard-image-blur" for="dashboard-image-blur">
                                    <span>Blur <output for="dashboard-image-blur" data-dashboard-image-blur-value><?= $dashboardBackgroundBlur ?>px</output></span>
                                    <input id="dashboard-image-blur" type="range" name="image_blur" min="0" max="20" step="1" value="<?= $dashboardBackgroundBlur ?>" data-dashboard-image-blur>
                                </label>
                            </div>
                        </section>
                        <section class="dashboard-copy-settings">
                            <label for="dashboard-copy-source">Copy settings from</label>
                            <div>
                                <select id="dashboard-copy-source" name="source_region">
                                    <option value="messages">Messages</option>
                                    <option value="profile_posts">Profile wallpaper</option>
                                    <option value="profile_cover">Profile cover</option>
                                </select>
                                <button type="button" data-dashboard-copy-settings>Copy</button>
                            </div>
                        </section>
                        <div class="dashboard-appearance-actions">
                            <button type="submit">Save</button>
                            <button type="button" class="button-secondary" data-dashboard-reset-open>Reset</button>
                        </div>
                        <p class="dashboard-appearance-status<?= $dashboardAppearanceError !== '' ? ' is-error' : '' ?>" data-dashboard-appearance-status role="status"><?= htmlspecialchars($dashboardAppearanceError, ENT_QUOTES, 'UTF-8') ?></p>
                    </form>
                </aside>
            </aside>
        </main>
        <div class="dashboard-customization-toast" data-dashboard-toast role="status" hidden>
            <span data-dashboard-toast-message></span>
            <button type="button" data-dashboard-toast-close aria-label="Close notification">&times;</button>
        </div>
        <dialog class="messages-image-crop-dialog dashboard-image-crop-dialog" data-dashboard-crop-dialog>
            <div class="messages-image-crop-heading">
                <h2>Frame dashboard background</h2>
                <button type="button" data-dashboard-crop-cancel aria-label="Close dashboard background editor">&times;</button>
            </div>
            <div class="messages-image-crop-stage dashboard-image-crop-stage">
                <canvas width="1280" height="720" data-dashboard-crop-canvas aria-label="Dashboard background crop preview"></canvas>
                <p data-dashboard-crop-status role="status">Loading preview...</p>
            </div>
            <label class="messages-image-zoom-control" for="dashboard-crop-zoom">
                <span>Zoom</span>
                <input id="dashboard-crop-zoom" type="range" min="1" max="3" step="0.01" value="1" data-dashboard-crop-zoom>
            </label>
            <div class="messages-image-crop-actions">
                <button type="button" class="button-secondary" data-dashboard-crop-cancel>Cancel</button>
                <button type="button" data-dashboard-crop-save aria-label="Use framed dashboard background">&#10003;</button>
            </div>
        </dialog>
        <dialog class="messages-reset-dialog" data-dashboard-reset-dialog>
            <div class="messages-reset-heading">
                <h2>Reset dashboard appearance?</h2>
                <button type="button" data-dashboard-reset-cancel aria-label="Close reset confirmation">&times;</button>
            </div>
            <p>Are you sure you want to reset your dashboard settings completely? Your settings will go into the void and never return.</p>
            <div class="messages-reset-actions">
                <button type="button" class="button-secondary" data-dashboard-reset-cancel>Cancel</button>
                <button type="button" data-dashboard-reset-confirm>Reset everything</button>
            </div>
        </dialog>
        <details class="zoom-layout-warning" role="status" data-zoom-warning open>
            <summary class="button-secondary" data-dismiss-zoom-warning>Ignore</summary>
            <span data-zoom-warning-message>This layout works best at 175% zoom or lower.</span>
        </details>
    <?php else: ?>
        <main>
            <h1>NexusSpace</h1>
            <p>A place to make your space your own.</p>
            <p>
                <a class="button" href="pages/register.php">Create an account</a>
                <a class="button button-secondary" href="pages/login.php">Log in</a>
            </p>
            <h2>Public Posts</h2>
            <?php if (!$posts): ?><p>No public posts yet.</p><?php endif; ?>
            <?php foreach ($posts as $post): ?>
                <article class="post-card">
                    <header class="post-header">
                        <?php renderPostAuthor($post); ?>
                        <div class="post-meta"><time datetime="<?= htmlspecialchars(str_replace(' ', 'T', $post['created_at']), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($post['created_at'], ENT_QUOTES, 'UTF-8') ?>"><?= postTimestamp($post['created_at']) ?></time></div>
                    </header>
                    <?php if ($post['title'] !== ''): ?><h3 class="post-title"><?= htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8') ?></h3><?php endif; ?>
                    <p class="post-content"><?= htmlspecialchars($post['content'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php renderPostMedia($post); ?>
                </article>
            <?php endforeach; ?>
        </main>
    <?php endif; ?>
</body>
</html>
