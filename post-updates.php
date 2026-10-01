<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/post_interactions.php';

function postUpdatesResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') postUpdatesResponse(['ok' => false, 'error' => 'Method not allowed.'], 405);
if (!isset($_SESSION['user_id'])) postUpdatesResponse(['ok' => false, 'error' => 'Log in again to continue.'], 401);

$token = $_POST['csrf_token'] ?? '';
if (!is_string($token) || !isset($_SESSION['posts_csrf']) || !hash_equals($_SESSION['posts_csrf'], $token)) {
    postUpdatesResponse(['ok' => false, 'error' => 'Your session changed. Refresh the page and try again.'], 403);
}

$viewerId = (int) $_SESSION['user_id'];
$context = ($_POST['context'] ?? '') === 'profile' ? 'profile' : 'dashboard';
$profileUserId = filter_var($_POST['profile_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$mode = ($_POST['mode'] ?? '') === 'older' ? 'older' : 'refresh';
$requestedIds = json_decode(is_string($_POST['post_ids'] ?? null) ? $_POST['post_ids'] : '[]', true);
$requestedCount = is_array($requestedIds) ? min(200, count($requestedIds)) : 0;
$authorFilter = $context === 'profile' ? 'p.user_id = ' . ($profileUserId ?: 0) . ' AND ' : '';
$cursorFilter = '';
if ($mode === 'older') {
    $beforeCreated = is_string($_POST['before_created_at'] ?? null) ? $_POST['before_created_at'] : '';
    $beforeId = filter_var($_POST['before_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($beforeCreated === '' || !$beforeId || DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $beforeCreated) === false) {
        postUpdatesResponse(['ok' => false, 'error' => 'The post cursor is invalid.'], 422);
    }
    $escapedCreated = mysqli_real_escape_string($conn, $beforeCreated);
    $cursorFilter = "(p.created_at < '{$escapedCreated}' OR (p.created_at = '{$escapedCreated}' AND p.id < {$beforeId})) AND ";
}
$limit = $mode === 'older' ? 26 : min(250, max(50, $requestedCount + 50));
$query = "SELECT p.id, p.user_id, p.title, p.content, p.media_path, p.media_type, p.visibility, p.created_at, p.edited_at, u.username, u.avatar_path FROM posts p JOIN users u ON u.id = p.user_id WHERE {$authorFilter}{$cursorFilter}(p.visibility = 'public' OR p.user_id = {$viewerId} OR EXISTS (SELECT 1 FROM friends f WHERE (f.user_id = {$viewerId} AND f.friend_id = p.user_id) OR (f.friend_id = {$viewerId} AND f.user_id = p.user_id))) AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_id = {$viewerId} AND b.blocked_id = p.user_id) OR (b.blocker_id = p.user_id AND b.blocked_id = {$viewerId})) ORDER BY p.created_at DESC, p.id DESC LIMIT {$limit}";
$result = mysqli_query($conn, $query);
$posts = [];
while ($post = mysqli_fetch_assoc($result)) $posts[(int) $post['id']] = $post;
$hasMore = $mode === 'older' && count($posts) > 25;
if ($hasMore) array_pop($posts);
$mediaByPost = loadPostMedia($conn, array_keys($posts));
foreach ($posts as $postId => &$post) $post['media'] = $mediaByPost[$postId] ?? [];
unset($post);
$interactions = loadPostInteractions($conn, array_keys($posts), $viewerId);
$assetPrefix = $context === 'profile' ? '../' : '';
$payload = [];

foreach ($posts as $postId => $post) {
    ob_start();
    renderPostInteractions($post, $interactions[$postId] ?? [], $viewerId, $assetPrefix);
    $interactionsHtml = ob_get_clean();
    ob_start();
    renderPostMedia($post, $assetPrefix, (int) $post['user_id'] === $viewerId);
    $mediaHtml = ob_get_clean();
    ob_start();
    renderLivePostCard($post, $interactions[$postId] ?? [], $viewerId, $context);
    $payload[] = [
        'id' => $postId,
        'createdAt' => $post['created_at'],
        'title' => $post['title'],
        'content' => $post['content'],
        'edited' => $post['edited_at'] !== null,
        'mediaKey' => implode('|', array_map(static fn(array $item): string => (string) $item['id'] . ':' . $item['media_path'], $post['media'])),
        'mediaHtml' => $mediaHtml,
        'interactionsHtml' => $interactionsHtml,
        'cardHtml' => ob_get_clean(),
    ];
}

postUpdatesResponse(['ok' => true, 'posts' => $payload, 'availableIds' => array_keys($posts), 'hasMore' => $hasMore]);
