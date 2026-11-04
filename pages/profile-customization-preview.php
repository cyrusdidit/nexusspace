<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/profile_customization.php';
require_once __DIR__ . '/../includes/post_media.php';
require_once __DIR__ . '/../includes/post_interactions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $draftTemplate = $_POST['template_html'] ?? null;
    $draftCss = $_POST['custom_css'] ?? null;
    $draftSimpleSettings = normalizeProfileSimpleSettings($_POST['simple_settings'] ?? null);
    $draftSidebarWidth = normalizeProfileSidebarWidth($_POST['sidebar_width'] ?? PROFILE_SIDEBAR_DEFAULT_WIDTH);
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
        'simple_settings' => $draftSimpleSettings,
        'sidebar_width' => $draftSidebarWidth,
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
$statement = mysqli_prepare($conn, 'SELECT username, avatar_path, bio, status_text, profile_song_name, profile_song_artist FROM users WHERE id = ? LIMIT 1');
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

$statement = mysqli_prepare($conn, 'SELECT id, user_id, title, content, media_path, media_type, visibility, created_at, edited_at FROM posts WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 3');
mysqli_stmt_bind_param($statement, 'i', $currentUserId);
mysqli_stmt_execute($statement);
$posts = mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC);
mysqli_stmt_close($statement);
attachPostMedia($posts, loadPostMedia($conn, array_column($posts, 'id')));
$postInteractions = loadPostInteractions($conn, array_column($posts, 'id'), $currentUserId);

$username = $user['username'];
$initial = mb_strtoupper(mb_substr($username, 0, 1));
$avatar = previewAvatarPath($user['avatar_path'] ?? '');

ob_start();
?>
<div class="profile-cover" data-profile-style="cover.container" aria-hidden="true"><span data-profile-style="cover.surface"><span data-profile-style="cover.image"></span></span><button type="button" data-profile-style="cover.edit-control">&#9998;</button></div>
<section class="profile-identity" data-profile-style="identity.container">
    <div class="profile-picture" data-profile-style="avatar.container" aria-hidden="true"><span><?= previewEscape($initial) ?></span><?php if ($avatar): ?><img src="<?= previewEscape($avatar) ?>" alt="" data-profile-style="avatar.image"><?php endif; ?><span class="profile-activity-dot is-online" data-profile-style="avatar.activity"></span><button type="button" data-profile-style="avatar.edit-overlay">&#9998;</button></div>
    <h1 data-profile-style="identity.display-name"><?= previewEscape($username) ?></h1>
    <p class="profile-handle-row" data-profile-style="identity.handle-row"><span class="profile-handle" data-profile-style="identity.handle">@<?= previewEscape($username) ?></span><button type="button" data-profile-style="identity.copy-control">&#128203;</button></p>
    <div class="profile-written-status-wrap" data-profile-style="status.container"><p class="profile-written-status" data-profile-style="status.text"><?= previewEscape(trim((string) ($user['status_text'] ?? '')) ?: 'Your custom status') ?></p><input value="Status editor" data-profile-style="status.editor"><button type="button" data-profile-style="status.edit-control">&#9998;</button><small data-profile-style="status.error">Status message</small></div>
    <section class="profile-sidebar-activity" data-profile-style="activity.container" aria-label="Activity preview">
        <div class="profile-sidebar-activity-row profile-sidebar-music-row" data-profile-style="activity.spotify-row"><a class="profile-sidebar-activity-main" data-profile-style="activity.spotify-link"><span class="profile-sidebar-activity-art" data-profile-style="activity.art"></span><span class="profile-sidebar-activity-copy" data-profile-style="activity.copy"><strong data-profile-style="activity.title">Playing song</strong> <small data-profile-style="activity.detail">Artist name</small></span></a><button type="button" data-profile-style="activity.spotify-add-control">+</button></div>
        <div class="profile-sidebar-activity-row" data-profile-style="activity.steam-row"><span class="profile-sidebar-activity-art" data-profile-style="activity.art"></span><span class="profile-sidebar-activity-copy" data-profile-style="activity.copy"><strong data-profile-style="activity.title">Playing game</strong> <small data-profile-style="activity.detail">(<span data-profile-style="activity.steam-duration">01:24:18</span>)</small></span></div>
        <p data-profile-style="activity.feedback">Activity feedback</p>
    </section>
    <div class="profile-social-actions" data-profile-style="social.actions"><button type="button" data-profile-style="social.message-control">Message</button><button type="button" data-profile-style="social.primary-control">Friend</button><button type="button" data-profile-style="social.secondary-control">Block</button></div>
</section>
<?php
$profileHeader = ob_get_clean();

ob_start();
?>
<section class="profile-bio-section" data-profile-style="bio.container" aria-label="Bio">
    <p class="profile-bio" data-profile-style="bio.text"><?= previewEscape(trim((string) ($user['bio'] ?? '')) ?: 'Your profile bio appears here.') ?></p><button type="button" data-profile-style="bio.edit-control">&#9998;</button><textarea data-profile-style="bio.editor">Bio editor</textarea><small data-profile-style="bio.counter">10/160</small><button type="button" data-profile-style="bio.save-control">Save</button>
</section>
<?php
$bio = ob_get_clean();

ob_start();
?>
<section class="profile-top-eight" data-profile-style="top-eight.container">
    <div class="panel-heading" data-profile-style="top-eight.header"><h2 class="friends-link" data-profile-style="top-eight.heading">My top 8 friends</h2><button type="button" data-profile-style="top-eight.reorder-control">&#8645;</button></div>
    <ol class="friends-list" data-profile-style="top-eight.list">
        <?php foreach ($topFriends as $friend): ?>
            <?php $friendAvatar = previewAvatarPath($friend['avatar_path'] ?? ''); ?>
            <li data-profile-style="top-eight.item"><span class="top-friend-link" data-profile-style="top-eight.name"><span class="post-avatar" data-profile-style="top-eight.avatar" aria-hidden="true"><span><?= previewEscape(mb_strtoupper(mb_substr($friend['username'], 0, 1))) ?></span><?php if ($friendAvatar): ?><img src="<?= previewEscape($friendAvatar) ?>" alt=""><?php endif; ?></span><span><?= previewEscape($friend['username']) ?></span></span><button type="button" data-profile-style="top-eight.remove-control">&times;</button></li>
        <?php endforeach; ?>
        <?php if (!$topFriends): ?><li data-profile-style="top-eight.item"><span class="top-friend-link" data-profile-style="top-eight.name"><span class="post-avatar" data-profile-style="top-eight.avatar">F</span><span>Friend preview</span></span><button type="button" data-profile-style="top-eight.remove-control">&times;</button></li><?php endif; ?>
    </ol>
    <aside class="top-eight-friend-picker" data-profile-style="top-eight.picker"><div data-profile-style="top-eight.picker-header"><strong>Friends</strong><button type="button" data-profile-style="top-eight.search-control">&#128269;</button></div><input placeholder="Find a friend" data-profile-style="top-eight.search-field"><ul><li data-profile-style="top-eight.pool-item">Friend preview <button type="button" data-profile-style="top-eight.add-control">&#10003;</button></li></ul></aside>
    <div data-profile-style="top-eight.save-controls"><button type="button">&times;</button><button type="button">&#10003;</button></div>
</section>
<nav class="profile-sidebar-actions" data-profile-style="sidebar-nav.container"><button type="button" data-profile-style="sidebar-nav.settings-control">&#9881;</button><button type="button" data-profile-style="sidebar-nav.dashboard-control">&larr; Dashboard</button></nav>
<?php
$topEight = ob_get_clean();

ob_start();
?>
<section class="profile-posts profile-customization-state-preview" data-profile-style="layout.content">
    <span class="profile-wallpaper-surface" data-profile-style="content.wallpaper"><span data-profile-style="content.wallpaper-image"></span></span><button type="button" data-profile-style="content.wallpaper-edit-control">&#9998;</button>
    <section class="profile-now-playing profile-song-banner" data-profile-style="profile-song.container">
        <div class="profile-now-playing-bar"><span data-profile-style="profile-song.label">Profile song: <strong data-profile-style="profile-song.title"><?= previewEscape(trim((string) ($user['profile_song_name'] ?? '')) ?: 'Song title') ?></strong> &bull; <span data-profile-style="profile-song.artist"><?= previewEscape(trim((string) ($user['profile_song_artist'] ?? '')) ?: 'Artist name') ?></span></span><span data-profile-style="profile-song.volume-control"><button type="button">&#128266;</button><input type="range" data-profile-style="profile-song.volume-slider"></span><button type="button" data-profile-style="profile-song.edit-control">&#9998;</button><button type="button" data-profile-style="profile-song.close-control">&times;</button></div>
    </section>
    <section class="profile-song-picker-panel" data-profile-style="profile-song.picker"><header data-profile-style="profile-song.picker-header"><strong data-profile-style="profile-song.picker-heading">Profile song</strong><span data-profile-style="profile-song.picker-actions"><button type="button" data-profile-style="profile-song.remove-control">Remove</button><button type="button" data-profile-style="profile-song.picker-close-control">&times;</button></span></header><form data-profile-style="profile-song.search-form"><label data-profile-style="profile-song.search-label">Find a song</label><input placeholder="Song or artist" data-profile-style="profile-song.search-field"><button type="button" data-profile-style="profile-song.search-control">Search</button></form><p data-profile-style="profile-song.search-status">Search results</p><ul data-profile-style="profile-song.search-results"><li data-profile-style="profile-song.result-item"><span data-profile-style="profile-song.result-image"></span><span data-profile-style="profile-song.result-copy"><strong data-profile-style="profile-song.result-title">Song title</strong><span data-profile-style="profile-song.result-artist">Artist</span></span><button type="button" data-profile-style="profile-song.result-select-control">Select</button></li></ul></section>
    <h2 data-profile-style="posts.heading">My posts</h2>
    <?php if (!$posts): ?><p class="post-empty" data-profile-style="posts.empty-state">Post preview</p><?php endif; ?>
    <div class="post-list" data-profile-style="posts.list">
    <?php foreach ($posts as $post): ?>
        <article class="post-card is-owned" data-profile-style="post.card">
            <header class="post-header" data-profile-style="post.header"><span class="post-author" data-profile-style="post.author"><span class="post-avatar" data-profile-style="post.avatar"><?= previewEscape($initial) ?></span><?= previewEscape($username) ?></span><time data-profile-style="post.metadata" datetime="<?= previewEscape(str_replace(' ', 'T', $post['created_at'])) ?>"><?= previewEscape(date('M j, Y \a\t H:i', strtotime($post['created_at']))) ?></time></header>
            <?php if (trim((string) $post['title']) !== ''): ?><h3 class="post-title" data-profile-style="post.title"><?= previewEscape($post['title']) ?></h3><?php endif; ?>
            <p class="post-content" data-profile-style="post.body"><?= previewEscape($post['content']) ?></p>
            <?php renderPostMedia($post, '../', true); ?>
            <?php renderPostInteractions($post, $postInteractions[(int) $post['id']] ?? [], $currentUserId, '../'); ?>
        </article>
    <?php endforeach; ?>
    <?php if (!$posts): ?>
        <article class="post-card is-owned" data-profile-style="post.card">
            <header class="post-header" data-profile-style="post.header"><span class="post-author" data-profile-style="post.author"><span class="post-avatar" data-profile-style="post.avatar"><?= previewEscape($initial) ?></span><?= previewEscape($username) ?></span><time data-profile-style="post.metadata">Today at 12:00</time></header>
            <h3 class="post-title" data-profile-style="post.title">Post title</h3><p class="post-content" data-profile-style="post.body">Post text and discussion preview.</p>
            <div class="post-media post-media-count-2" data-profile-style="post.media-grid"><figure class="post-media-item" data-profile-style="post.media-item"><span class="profile-preview-media" data-profile-style="post.image">Image</span><button type="button" data-profile-style="post.media-remove-control">&times;</button></figure><figure class="post-media-item" data-profile-style="post.media-item"><span class="profile-preview-media" data-profile-style="post.video">Video</span><label data-profile-style="post.video-speed">1x</label></figure></div>
            <div class="post-interactions" data-profile-style="post.interactions"><div class="post-owner-actions" data-profile-style="post.owner-actions"><button type="button" data-profile-style="post.edit-control">Edit</button><button type="button" data-profile-style="post.delete-control">Delete</button></div><div class="post-action-bar" data-profile-style="post.action-bar"><button type="button" data-profile-style="post.heart-control">&#9829; 4</button><button type="button" data-profile-style="post.comment-control">Comment 2</button></div>
                <form class="post-comment-form" data-profile-style="comment.composer"><textarea data-profile-style="comment.composer-field" placeholder="Write a comment..."></textarea><button type="button" data-profile-style="comment.composer-submit">Comment</button></form>
                <div class="post-comments" data-profile-style="comment.list"><article class="post-comment is-pinned" data-profile-style="comment.card"><header data-profile-style="comment.header"><span class="post-avatar" data-profile-style="comment.avatar">F</span><strong data-profile-style="comment.author">Friend</strong><time data-profile-style="comment.timestamp">Now</time><span class="post-comment-pinned" data-profile-style="comment.pinned">Pinned</span></header><p data-profile-style="comment.body">Pinned comment preview.</p><div class="post-comment-actions" data-profile-style="comment.actions"><button type="button" data-profile-style="comment.reply-control">Reply</button><button type="button" data-profile-style="comment.pin-control">Unpin</button></div><div class="post-comment-replies" data-profile-style="comment.replies-thread"><article class="post-comment is-reply" data-profile-style="comment.card"><header data-profile-style="comment.header"><strong data-profile-style="comment.author"><?= previewEscape($username) ?></strong></header><p data-profile-style="comment.body">Reply preview.</p></article></div></article></div>
            </div>
        </article>
    <?php endif; ?>
        <section class="profile-preview-editing-states" aria-label="Editing states">
            <div data-profile-style="post.media-reorder-controls"><button type="button" data-profile-style="post.media-move-control">&larr;</button><label data-profile-style="post.media-replace-control">Replace</label><button type="button" data-profile-style="post.media-move-control">&rarr;</button></div><button type="button" data-profile-style="post.media-add-control">Add media</button>
            <form data-profile-style="post.edit-form"><input value="Post title" data-profile-style="post.edit-title-field"><textarea data-profile-style="post.edit-body-field">Post editor</textarea><div data-profile-style="post.upload-progress"><progress max="100" value="45"></progress></div><div data-profile-style="post.edit-actions"><button type="button" data-profile-style="post.save-control">Save</button><button type="button" data-profile-style="post.cancel-control">Cancel</button></div></form>
            <p data-profile-style="comment.status">Comment status</p><div data-profile-style="comment.actions"><button type="button" data-profile-style="comment.edit-control">Edit</button><button type="button" data-profile-style="comment.delete-control">Delete</button><button type="button" data-profile-style="comment.purge-control">Remove</button></div>
            <form data-profile-style="comment.edit-form"><textarea data-profile-style="comment.edit-field">Edit comment</textarea><button type="button" data-profile-style="comment.save-control">Save</button><button type="button" data-profile-style="comment.cancel-control">Cancel</button></form>
            <form data-profile-style="comment.reply-form"><textarea data-profile-style="comment.reply-field">Write a reply</textarea><button type="button" data-profile-style="comment.reply-submit">Reply</button></form>
        </section>
    </div>
    <button type="button" data-profile-style="posts.load-more-control">Load more</button>
</section>
<?php
$profilePosts = ob_get_clean();

$customization = readProfileCustomization($conn, $currentUserId);
$revisionNumber = filter_var($_GET['revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($revisionNumber !== false && $revisionNumber !== null) {
    $revisionCustomization = readProfileCustomizationRevision($conn, $currentUserId, (int) $revisionNumber);
    if (!$revisionCustomization) {
        http_response_code(404);
        exit('Revision not found.');
    }
    $customization = array_replace($customization, $revisionCustomization);
}
$templateHtml = $customization['template_html'];
$customCssSource = $customization['custom_css'];
$simpleSettings = $customization['simple_settings'];
$sidebarWidth = $customization['sidebar_width'];
$draftToken = is_string($_GET['draft'] ?? null) ? $_GET['draft'] : '';
if (preg_match('/^[a-f0-9]{24}$/', $draftToken) && isset($_SESSION['profile_preview_drafts'][$draftToken])) {
    $draft = $_SESSION['profile_preview_drafts'][$draftToken];
    unset($_SESSION['profile_preview_drafts'][$draftToken]);
    $templateHtml = $draft['template_html'];
    $customCssSource = $draft['custom_css'];
    $simpleSettings = normalizeProfileSimpleSettings($draft['simple_settings'] ?? null);
    $sidebarWidth = normalizeProfileSidebarWidth($draft['sidebar_width'] ?? PROFILE_SIDEBAR_DEFAULT_WIDTH);
}

$safeTemplate = sanitizeProfileTemplate($templateHtml);
$safeTemplate = decorateProfileTemplate($safeTemplate);
$renderedTemplate = strtr($safeTemplate, [
    '{{profile_header}}' => $profileHeader,
    '{{bio}}' => $bio,
    '{{top_eight}}' => $topEight,
    '{{posts}}' => $profilePosts,
]);
$baseCss = str_replace('</style', '<\/style', file_get_contents(__DIR__ . '/../assets/css/style.css'));
$simpleCss = str_replace('</style', '<\/style', renderProfileSimpleCss($simpleSettings, $sidebarWidth));
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
    <style><?= $simpleCss ?></style>
    <style><?= $customCss ?></style>
</head>
<body class="profile-page profile-customization-preview-page" data-profile-style="profile.page"><main class="profile-sheet" data-profile-style="profile.sheet"><div class="profile-custom-content"><?= $renderedTemplate ?></div><button type="button" data-profile-style="layout.sidebar-handle">&#8596;</button><small data-profile-style="profile.copyright">&copy;2026 NexusSpace</small></main></body>
</html>
