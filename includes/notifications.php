<?php

declare(strict_types=1);

function createNotification(mysqli $conn, int $recipientId, int $actorId, string $type, ?int $entityId, ?int $postId, string $dedupeKey, bool $refresh = false): void
{
    if ($recipientId < 1 || $actorId < 1 || $recipientId === $actorId) return;
    $sql = $refresh
        ? 'INSERT INTO notifications (recipient_id, actor_id, type, entity_id, post_id, dedupe_key) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE is_read = 0, read_at = NULL, created_at = CURRENT_TIMESTAMP'
        : 'INSERT IGNORE INTO notifications (recipient_id, actor_id, type, entity_id, post_id, dedupe_key) VALUES (?, ?, ?, ?, ?, ?)';
    $statement = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($statement, 'iisiis', $recipientId, $actorId, $type, $entityId, $postId, $dedupeKey);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);
}

function removeNotification(mysqli $conn, int $recipientId, string $dedupeKey): void
{
    $statement = mysqli_prepare($conn, 'DELETE FROM notifications WHERE recipient_id = ? AND dedupe_key = ?');
    mysqli_stmt_bind_param($statement, 'is', $recipientId, $dedupeKey);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);
}

function markMessageNotificationsRead(mysqli $conn, int $recipientId, int $senderId, int $firstId, int $lastId): void
{
    $statement = mysqli_prepare($conn, "UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE recipient_id = ? AND actor_id = ? AND type = 'message' AND entity_id BETWEEN ? AND ?");
    mysqli_stmt_bind_param($statement, 'iiii', $recipientId, $senderId, $firstId, $lastId);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);
}
