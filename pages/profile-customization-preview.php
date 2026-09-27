<?php

declare(strict_types=1);

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/profile_customization.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $draftTemplate = $_POST['template_html'] ?? null;
    $draftCss = $_POST['custom_css'] ?? null;
    if (!is_string($draftTemplate) || !is_string($draftCss) || !mb_check_encoding($draftTemplate, 'UTF-8') || !mb_check_encoding($draftCss, 'UTF-8')) {
        http_response_code(422);
        exit('Invalid preview content.');
    }
    if (strlen($draftTemplate) > 50000 || strlen($draftCss) > 30000) {
        http_response_code(413);
        exit('Preview content is too large.');
    }

    $draftToken = bin2hex(random_bytes(12));
    $_SESSION['profile_preview_drafts'] ??= [];
    $_SESSION['profile_preview_drafts'][$draftToken] = [
        'template_html' => $draftTemplate,
        'custom_css' => $draftCss,
    ];
    $_SESSION['profile_preview_drafts'] = array_slice($_SESSION['profile_preview_drafts'], -5, null, true);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'draft' => $draftToken,
        'warnings' => [
            'html' => validateProfileTemplate($draftTemplate),
            'css' => validateProfileCss($draftCss),
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

function previewEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function previewAvatarPath(?string $value): string
{
    $path = trim($value ?? '');
    if ($path === '' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $path)) return '';
    return str_starts_with($path, '/') ? $path : '../' . $path;
}

$currentUserId = (int) $_SESSION['user_id'];
$statement = mysqli_prepare($conn, 'SELECT username, avatar_path, bio FROM users WHERE id = ? LIMIT 1');
mysqli_stmt_bind_param($statement, 'i', $currentUserId);
mysqli_stmt_execute($statement);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
mysqli_stmt_close($statement);
if (!$user) {
    http_response_code(404);
    exit;
}

$statement = mysqli_prepare($conn, 'SELECT u.id, u.username, u.avatar_path FROM friends f JOIN users u ON u.id = f.friend_id WHERE f.user_id = ? AND f.top_eight_position BETWEEN 1 AND 8 ORDER BY f.top_eight_position, u.id LIMIT 8');
mysqli_stmt_bind_param($statement, 'i', $currentUserId);
mysqli_stmt_execute($statement);
$topFriends = mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC);
mysqli_stmt_close($statement);

$statement = mysqli_prepare($conn, 'SELECT content, created_at FROM posts WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 20');
mysqli_stmt_bind_param($statement, 'i', $currentUserId);
mysqli_stmt_execute($statement);
$posts = mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC);
mysqli_stmt_close($statement);

$username = $user['username'];
$initial = mb_strtoupper(mb_substr($username, 0, 1));
$avatar = previewAvatarPath($user['avatar_path'] ?? '');

ob_start();
?>
<div class="profile-cover" aria-hidden="true"></div>
<section class="profile-identity">
    <div class="profile-picture" aria-hidden="true"><span><?= previewEscape($initial) ?></span><?php if ($avatar): ?><img src="<?= previewEscape($avatar) ?>" alt=""><?php endif; ?></div>
    <h1><?= previewEscape($username) ?></h1>
    <p class="profile-handle">@<?= previewEscape($username) ?></p>
</section>
<?php
$profileHeader = ob_get_clean();

ob_start();
?>
<section class="profile-bio-section" aria-label="Bio">
    <?php if (trim($user['bio'] ?? '') !== ''): ?><p class="profile-bio"><?= previewEscape($user['bio']) ?></p><?php endif; ?>
</section>
<?php
$bio = ob_get_clean();

ob_start();
?>
<section class="profile-top-eight">
    <div class="panel-heading"><h2 class="friends-link">My top 8 friends</h2></div>
    <ol class="friends-list">
        <?php foreach ($topFriends as $friend): ?>
            <?php $friendAvatar = previewAvatarPath($friend['avatar_path'] ?? ''); ?>
            <li><span class="top-friend-link"><span class="post-avatar" aria-hidden="true"><span><?= previewEscape(mb_strtoupper(mb_substr($friend['username'], 0, 1))) ?></span><?php if ($friendAvatar): ?><img src="<?= previewEscape($friendAvatar) ?>" alt=""><?php endif; ?></span><span><?= previewEscape($friend['username']) ?></span></span></li>
        <?php endforeach; ?>
    </ol>
</section>
<?php
$topEight = ob_get_clean();

ob_start();
?>
<section class="profile-posts">
    <h2>My posts</h2>
    <?php if (!$posts): ?><p class="post-empty">No posts to show yet.</p><?php endif; ?>
    <?php foreach ($posts as $post): ?>
        <article class="post-card">
            <header class="post-header"><span class="post-author"><?= previewEscape($username) ?></span><time datetime="<?= previewEscape(str_replace(' ', 'T', $post['created_at'])) ?>"><?= previewEscape(date('M j, Y \a\t H:i', strtotime($post['created_at']))) ?></time></header>
            <p class="post-content"><?= previewEscape($post['content']) ?></p>
        </article>
    <?php endforeach; ?>
</section>
<?php
$profilePosts = ob_get_clean();

$customization = readProfileCustomization($conn, $currentUserId);
$templateHtml = $customization['template_html'];
$customCssSource = $customization['custom_css'];
$draftToken = is_string($_GET['draft'] ?? null) ? $_GET['draft'] : '';
if (preg_match('/^[a-f0-9]{24}$/', $draftToken) && isset($_SESSION['profile_preview_drafts'][$draftToken])) {
    $draft = $_SESSION['profile_preview_drafts'][$draftToken];
    unset($_SESSION['profile_preview_drafts'][$draftToken]);
    $templateHtml = $draft['template_html'];
    $customCssSource = $draft['custom_css'];
}

$safeTemplate = sanitizeProfileTemplate($templateHtml);
$renderedTemplate = strtr($safeTemplate, [
    '{{profile_header}}' => $profileHeader,
    '{{bio}}' => $bio,
    '{{top_eight}}' => $topEight,
    '{{posts}}' => $profilePosts,
]);
$baseCss = str_replace('</style', '<\/style', file_get_contents(__DIR__ . '/../assets/css/style.css'));
$customCss = sanitizeAndScopeProfileCss($customCssSource);

header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'");
header('Referrer-Policy: no-referrer');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style><?= $baseCss ?></style>
    <style><?= $customCss ?></style>
</head>
<body class="profile-page"><div class="profile-custom-content"><?= $renderedTemplate ?></div></body>
</html>
