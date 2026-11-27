<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/post_media.php';

$result = mysqli_query($conn, "SELECT p.id, p.user_id, p.media_path FROM posts p WHERE p.admin_removed_at IS NOT NULL AND p.admin_removed_at <= UTC_TIMESTAMP() - INTERVAL 30 DAY AND NOT EXISTS (SELECT 1 FROM post_removal_appeals a WHERE a.post_id = p.id AND a.status = 'pending') ORDER BY p.admin_removed_at, p.id LIMIT 100");
$posts = mysqli_fetch_all($result, MYSQLI_ASSOC);
$purged = 0;

foreach ($posts as $post) {
    $postId = (int) $post['id'];
    $ownerId = (int) $post['user_id'];
    $media = loadPostMedia($conn, [$postId])[$postId] ?? [];
    $statement = mysqli_prepare($conn, "DELETE FROM posts WHERE id = ? AND admin_removed_at IS NOT NULL AND admin_removed_at <= UTC_TIMESTAMP() - INTERVAL 30 DAY AND NOT EXISTS (SELECT 1 FROM post_removal_appeals a WHERE a.post_id = posts.id AND a.status = 'pending')");
    mysqli_stmt_bind_param($statement, 'i', $postId);
    mysqli_stmt_execute($statement);
    $deleted = mysqli_stmt_affected_rows($statement) === 1;
    mysqli_stmt_close($statement);
    if (!$deleted) continue;

    deletePostMedia($post['media_path'], $ownerId);
    foreach ($media as $item) deletePostMedia($item['media_path'] ?? null, $ownerId);
    $purged++;
}

fwrite(STDOUT, "Permanently purged {$purged} admin-removed post(s).\n");
