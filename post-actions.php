<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/post_interactions.php';
require_once __DIR__ . '/includes/post_media.php';
require_once __DIR__ . '/includes/notifications.php';

function postActionResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') postActionResponse(['ok' => false, 'error' => 'Method not allowed.'], 405);
if (!isset($_SESSION['user_id'])) postActionResponse(['ok' => false, 'error' => 'Log in again to continue.'], 401);

$token = $_POST['csrf_token'] ?? '';
if (!is_string($token) || !isset($_SESSION['posts_csrf']) || !hash_equals($_SESSION['posts_csrf'], $token)) {
    postActionResponse(['ok' => false, 'error' => 'Your session changed. Refresh the page and try again.'], 403);
}

$viewerId = (int) $_SESSION['user_id'];
$postId = filter_var($_POST['post_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$allowedActions = ['edit_post', 'delete_post', 'remove_media', 'toggle_like', 'add_comment', 'edit_comment', 'delete_comment', 'purge_comment', 'toggle_pin'];
if (!$postId || !in_array($action, $allowedActions, true)) postActionResponse(['ok' => false, 'error' => 'Invalid post action.'], 422);

$statement = mysqli_prepare($conn, "SELECT p.id, p.user_id, p.content, p.media_path, p.media_type, p.visibility, p.created_at, p.edited_at FROM posts p WHERE p.id = ? AND (p.visibility = 'public' OR p.user_id = ? OR EXISTS (SELECT 1 FROM friends f WHERE (f.user_id = ? AND f.friend_id = p.user_id) OR (f.friend_id = ? AND f.user_id = p.user_id))) AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_id = ? AND b.blocked_id = p.user_id) OR (b.blocker_id = p.user_id AND b.blocked_id = ?)) LIMIT 1");
mysqli_stmt_bind_param($statement, 'iiiiii', $postId, $viewerId, $viewerId, $viewerId, $viewerId, $viewerId);
mysqli_stmt_execute($statement);
$post = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
mysqli_stmt_close($statement);
if (!$post) postActionResponse(['ok' => false, 'error' => 'This post is unavailable.'], 404);
$post['media'] = loadPostMedia($conn, [$postId])[$postId] ?? [];

$postOwnerId = (int) $post['user_id'];
$content = is_string($_POST['content'] ?? null) ? trim($_POST['content']) : '';
$commentId = filter_var($_POST['comment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

try {
    if ($action === 'edit_post') {
        if ($postOwnerId !== $viewerId) postActionResponse(['ok' => false, 'error' => 'Only the post creator can edit this post.'], 403);
        if (mb_strlen($content, 'UTF-8') > 2500 || ($content === '' && !$post['media'])) postActionResponse(['ok' => false, 'error' => 'Write something or keep the attached media, using no more than 2,500 characters.'], 422);
        $statement = mysqli_prepare($conn, 'UPDATE posts SET content = ?, edited_at = NOW() WHERE id = ? AND user_id = ?');
        mysqli_stmt_bind_param($statement, 'sii', $content, $postId, $viewerId);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        $post['content'] = $content;
        $post['edited_at'] = date('Y-m-d H:i:s');
    } elseif ($action === 'delete_post') {
        if ($postOwnerId !== $viewerId) postActionResponse(['ok' => false, 'error' => 'Only the post creator can delete this post.'], 403);
        $statement = mysqli_prepare($conn, 'DELETE FROM posts WHERE id = ? AND user_id = ?');
        mysqli_stmt_bind_param($statement, 'ii', $postId, $viewerId);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        deletePostMedia($post['media_path'], $viewerId);
        foreach ($post['media'] as $media) deletePostMedia($media['media_path'], $viewerId);
        postActionResponse(['ok' => true, 'deleted' => true]);
    } elseif ($action === 'remove_media') {
        if ($postOwnerId !== $viewerId) postActionResponse(['ok' => false, 'error' => 'Only the post creator can remove its media.'], 403);
        $mediaId = filter_var($_POST['media_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $mediaIndex = false;
        foreach ($post['media'] as $index => $media) {
            if ((int) $media['id'] === $mediaId) { $mediaIndex = $index; break; }
        }
        if ($mediaIndex === false) postActionResponse(['ok' => false, 'error' => 'That media is no longer attached.'], 404);
        if ($post['content'] === '' && count($post['media']) === 1) postActionResponse(['ok' => false, 'error' => 'Add text before removing the only content from this post.'], 422);
        $mediaPath = $post['media'][$mediaIndex]['media_path'];
        $statement = mysqli_prepare($conn, 'DELETE FROM post_media WHERE id = ? AND post_id = ?');
        mysqli_stmt_bind_param($statement, 'ii', $mediaId, $postId);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        if ($post['media_path'] === $mediaPath) {
            $statement = mysqli_prepare($conn, 'UPDATE posts SET media_path = NULL, media_type = NULL WHERE id = ? AND user_id = ?');
            mysqli_stmt_bind_param($statement, 'ii', $postId, $viewerId);
            mysqli_stmt_execute($statement);
            mysqli_stmt_close($statement);
            $post['media_path'] = null;
            $post['media_type'] = null;
        }
        array_splice($post['media'], $mediaIndex, 1);
        deletePostMedia($mediaPath, $viewerId);
    } elseif ($action === 'toggle_like') {
        mysqli_begin_transaction($conn);
        $statement = mysqli_prepare($conn, 'SELECT 1 FROM post_likes WHERE post_id = ? AND user_id = ? FOR UPDATE');
        mysqli_stmt_bind_param($statement, 'ii', $postId, $viewerId);
        mysqli_stmt_execute($statement);
        $liked = mysqli_num_rows(mysqli_stmt_get_result($statement)) > 0;
        mysqli_stmt_close($statement);
        $statement = mysqli_prepare($conn, $liked ? 'DELETE FROM post_likes WHERE post_id = ? AND user_id = ?' : 'INSERT INTO post_likes (post_id, user_id) VALUES (?, ?)');
        mysqli_stmt_bind_param($statement, 'ii', $postId, $viewerId);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        if ($liked) removeNotification($conn, $postOwnerId, 'post_like:' . $postId . ':' . $viewerId);
        else createNotification($conn, $postOwnerId, $viewerId, 'post_like', (int) $postId, (int) $postId, 'post_like:' . $postId . ':' . $viewerId);
        mysqli_commit($conn);
    } elseif ($action === 'add_comment') {
        if ($content === '' || mb_strlen($content, 'UTF-8') > 1000) postActionResponse(['ok' => false, 'error' => 'Use 1 to 1,000 characters.'], 422);
        $parentId = filter_var($_POST['parent_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($parentId) {
            $statement = mysqli_prepare($conn, 'SELECT id, user_id FROM post_comments WHERE id = ? AND post_id = ? AND parent_id IS NULL AND deleted_at IS NULL LIMIT 1');
            mysqli_stmt_bind_param($statement, 'ii', $parentId, $postId);
            mysqli_stmt_execute($statement);
            $parentComment = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
            $validParent = $parentComment !== null;
            mysqli_stmt_close($statement);
            if (!$validParent) postActionResponse(['ok' => false, 'error' => 'That comment cannot be replied to.'], 422);
            $statement = mysqli_prepare($conn, 'INSERT INTO post_comments (post_id, user_id, parent_id, content) VALUES (?, ?, ?, ?)');
            mysqli_stmt_bind_param($statement, 'iiis', $postId, $viewerId, $parentId, $content);
        } else {
            $statement = mysqli_prepare($conn, 'INSERT INTO post_comments (post_id, user_id, content) VALUES (?, ?, ?)');
            mysqli_stmt_bind_param($statement, 'iis', $postId, $viewerId, $content);
        }
        mysqli_stmt_execute($statement);
        $newCommentId = (int) mysqli_insert_id($conn);
        mysqli_stmt_close($statement);
        if ($parentId) createNotification($conn, (int) $parentComment['user_id'], $viewerId, 'comment_reply', $newCommentId, (int) $postId, 'comment_reply:' . $newCommentId);
        else createNotification($conn, $postOwnerId, $viewerId, 'post_comment', $newCommentId, (int) $postId, 'post_comment:' . $newCommentId);
    } elseif (in_array($action, ['edit_comment', 'delete_comment'], true)) {
        if (!$commentId) postActionResponse(['ok' => false, 'error' => 'Choose a valid comment.'], 422);
        if ($action === 'edit_comment') {
            if ($content === '' || mb_strlen($content, 'UTF-8') > 1000) postActionResponse(['ok' => false, 'error' => 'Use 1 to 1,000 characters.'], 422);
            $statement = mysqli_prepare($conn, 'UPDATE post_comments SET content = ?, edited_at = NOW() WHERE id = ? AND post_id = ? AND user_id = ? AND deleted_at IS NULL');
            mysqli_stmt_bind_param($statement, 'siii', $content, $commentId, $postId, $viewerId);
        } else {
            $statement = mysqli_prepare($conn, 'UPDATE post_comments SET content = ?, deleted_at = NOW(), pinned_at = NULL WHERE id = ? AND post_id = ? AND user_id = ? AND deleted_at IS NULL');
            $deletedContent = '';
            mysqli_stmt_bind_param($statement, 'siii', $deletedContent, $commentId, $postId, $viewerId);
        }
        mysqli_stmt_execute($statement);
        $changed = mysqli_stmt_affected_rows($statement);
        mysqli_stmt_close($statement);
        if ($changed < 1) postActionResponse(['ok' => false, 'error' => 'You cannot change that comment.'], 403);
        if ($action === 'delete_comment') {
            $statement = mysqli_prepare($conn, "DELETE FROM notifications WHERE entity_id = ? AND type IN ('post_comment', 'comment_reply', 'comment_pin')");
            mysqli_stmt_bind_param($statement, 'i', $commentId);
            mysqli_stmt_execute($statement);
            mysqli_stmt_close($statement);
        }
    } elseif ($action === 'purge_comment') {
        if ($postOwnerId !== $viewerId) postActionResponse(['ok' => false, 'error' => 'Only the post creator can remove deleted comments.'], 403);
        if (!$commentId) postActionResponse(['ok' => false, 'error' => 'Choose a valid comment.'], 422);
        mysqli_begin_transaction($conn);
        $statement = mysqli_prepare($conn, 'SELECT id, parent_id FROM post_comments WHERE id = ? AND post_id = ? AND deleted_at IS NOT NULL LIMIT 1 FOR UPDATE');
        mysqli_stmt_bind_param($statement, 'ii', $commentId, $postId);
        mysqli_stmt_execute($statement);
        $deletedComment = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
        mysqli_stmt_close($statement);
        if (!$deletedComment) {
            mysqli_rollback($conn);
            postActionResponse(['ok' => false, 'error' => 'That deleted comment is no longer available.'], 404);
        }
        if ($deletedComment['parent_id'] === null) {
            $statement = mysqli_prepare($conn, 'UPDATE post_comments SET parent_id = NULL WHERE parent_id = ?');
            mysqli_stmt_bind_param($statement, 'i', $commentId);
            mysqli_stmt_execute($statement);
            mysqli_stmt_close($statement);
        }
        $statement = mysqli_prepare($conn, 'DELETE FROM post_comments WHERE id = ? AND post_id = ? AND deleted_at IS NOT NULL');
        mysqli_stmt_bind_param($statement, 'ii', $commentId, $postId);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        mysqli_commit($conn);
    } elseif ($action === 'toggle_pin') {
        if ($postOwnerId !== $viewerId) postActionResponse(['ok' => false, 'error' => 'Only the post creator can pin comments.'], 403);
        if (!$commentId) postActionResponse(['ok' => false, 'error' => 'Choose a valid comment.'], 422);
        mysqli_begin_transaction($conn);
        $statement = mysqli_prepare($conn, 'SELECT id FROM posts WHERE id = ? FOR UPDATE');
        mysqli_stmt_bind_param($statement, 'i', $postId);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        $statement = mysqli_prepare($conn, 'SELECT pinned_at, user_id FROM post_comments WHERE id = ? AND post_id = ? AND parent_id IS NULL AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
        mysqli_stmt_bind_param($statement, 'ii', $commentId, $postId);
        mysqli_stmt_execute($statement);
        $comment = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
        mysqli_stmt_close($statement);
        if (!$comment) {
            mysqli_rollback($conn);
            postActionResponse(['ok' => false, 'error' => 'That comment cannot be pinned.'], 422);
        }
        if ($comment['pinned_at']) {
            $statement = mysqli_prepare($conn, 'UPDATE post_comments SET pinned_at = NULL WHERE id = ?');
        } else {
            $statement = mysqli_prepare($conn, 'SELECT COUNT(*) AS total FROM post_comments WHERE post_id = ? AND pinned_at IS NOT NULL AND deleted_at IS NULL');
            mysqli_stmt_bind_param($statement, 'i', $postId);
            mysqli_stmt_execute($statement);
            $total = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($statement))['total'];
            mysqli_stmt_close($statement);
            if ($total >= 5) {
                mysqli_rollback($conn);
                postActionResponse(['ok' => false, 'error' => 'A post can have up to five pinned comments. Unpin one first.'], 422);
            }
            $statement = mysqli_prepare($conn, 'UPDATE post_comments SET pinned_at = NOW(6) WHERE id = ?');
        }
        mysqli_stmt_bind_param($statement, 'i', $commentId);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        if ($comment['pinned_at']) removeNotification($conn, (int) $comment['user_id'], 'comment_pin:' . $commentId);
        else createNotification($conn, (int) $comment['user_id'], $viewerId, 'comment_pin', (int) $commentId, (int) $postId, 'comment_pin:' . $commentId, true);
        mysqli_commit($conn);
    }
} catch (Throwable $exception) {
    if (mysqli_errno($conn)) @mysqli_rollback($conn);
    postActionResponse(['ok' => false, 'error' => 'The post could not be updated. Please try again.'], 500);
}

$interactions = loadPostInteractions($conn, [$postId], $viewerId);
$assetPrefix = ($_POST['context'] ?? '') === 'profile' ? '../' : '';
ob_start();
renderPostInteractions($post, $interactions[$postId] ?? [], $viewerId, $assetPrefix);
$html = ob_get_clean();
ob_start();
renderPostMedia($post, $assetPrefix, $postOwnerId === $viewerId);
$mediaHtml = ob_get_clean();
postActionResponse([
    'ok' => true,
    'content' => $post['content'],
    'edited' => $post['edited_at'] !== null,
    'mediaKey' => implode('|', array_map(static fn(array $item): string => (string) $item['id'] . ':' . $item['media_path'], $post['media'])),
    'mediaHtml' => $mediaHtml,
    'interactionsHtml' => $html,
]);
