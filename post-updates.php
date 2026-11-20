<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
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
$filters = [];
$bindTypes = '';
$bindValues = [];
if ($context === 'profile') {
    $filters[] = 'p.user_id = ?';
    $bindTypes .= 'i';
    $bindValues[] = (int) ($profileUserId ?: 0);
}
if ($mode === 'older') {
    $beforeCreated = is_string($_POST['before_created_at'] ?? null) ? $_POST['before_created_at'] : '';
    $beforeId = filter_var($_POST['before_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($beforeCreated === '' || !$beforeId || DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $beforeCreated) === false) {
        postUpdatesResponse(['ok' => false, 'error' => 'The post cursor is invalid.'], 422);
    }
    if ($context === 'profile') $filters[] = 'p.pinned_at IS NULL';
    $filters[] = '(p.created_at < ? OR (p.created_at = ? AND p.id < ?))';
    $bindTypes .= 'ssi';
    array_push($bindValues, $beforeCreated, $beforeCreated, (int) $beforeId);
}
$limit = $mode === 'older' ? 26 : min(250, max(50, $requestedCount + 50));
$pinFields = $context === 'profile'
    ? ', p.pinned_at, (SELECT COUNT(*) FROM posts pinned WHERE pinned.user_id = p.user_id AND pinned.pinned_at IS NOT NULL) AS pinned_post_count'
    : ', NULL AS pinned_at, 0 AS pinned_post_count';
$orderBy = $context === 'profile'
    ? 'p.pinned_at IS NULL ASC, p.pinned_at ASC, p.created_at DESC, p.id DESC'
    : 'p.created_at DESC, p.id DESC';
$filters[] = "(p.visibility = 'public' OR p.user_id = ? OR EXISTS (SELECT 1 FROM friends f WHERE (f.user_id = ? AND f.friend_id = p.user_id) OR (f.friend_id = ? AND f.user_id = p.user_id)))";
$filters[] = 'NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_id = ? AND b.blocked_id = p.user_id) OR (b.blocker_id = p.user_id AND b.blocked_id = ?))';
$bindTypes .= 'iiiii';
array_push($bindValues, $viewerId, $viewerId, $viewerId, $viewerId, $viewerId);
$query = "SELECT p.id, p.user_id, p.title, p.content, p.media_path, p.media_type, p.visibility, p.created_at, p.edited_at{$pinFields}, u.username, u.avatar_path FROM posts p JOIN users u ON u.id = p.user_id WHERE " . implode(' AND ', $filters) . " ORDER BY {$orderBy} LIMIT {$limit}";
$statement = mysqli_prepare($conn, $query);
$bindParameters = [$statement, $bindTypes];
foreach ($bindValues as $index => &$value) $bindParameters[] = &$value;
unset($value);
call_user_func_array('mysqli_stmt_bind_param', $bindParameters);
mysqli_stmt_execute($statement);
$result = mysqli_stmt_get_result($statement);
$posts = [];
while ($post = mysqli_fetch_assoc($result)) $posts[(int) $post['id']] = $post;
mysqli_stmt_close($statement);
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
    renderPostInteractions($post, $interactions[$postId] ?? [], $viewerId, $assetPrefix, $context === 'profile' && (int) $post['user_id'] === $viewerId);
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
        'pinned' => !empty($post['pinned_at']),
        'pinnedAt' => $post['pinned_at'],
        'pinnedCount' => (int) $post['pinned_post_count'],
        'mediaKey' => implode('|', array_map(static fn(array $item): string => (string) $item['id'] . ':' . $item['media_path'], $post['media'])),
        'mediaHtml' => $mediaHtml,
        'interactionsHtml' => $interactionsHtml,
        'cardHtml' => ob_get_clean(),
    ];
}

postUpdatesResponse(['ok' => true, 'posts' => $payload, 'availableIds' => array_keys($posts), 'hasMore' => $hasMore]);
