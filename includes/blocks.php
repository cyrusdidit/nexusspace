<?php

declare(strict_types=1);

function usersAreBlocked(mysqli $conn, int $firstUserId, int $secondUserId): bool
{
    if ($firstUserId === $secondUserId) return false;

    $statement = mysqli_prepare(
        $conn,
        'SELECT 1 FROM user_blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?) LIMIT 1'
    );
    mysqli_stmt_bind_param($statement, 'iiii', $firstUserId, $secondUserId, $secondUserId, $firstUserId);
    mysqli_stmt_execute($statement);
    $blocked = mysqli_num_rows(mysqli_stmt_get_result($statement)) > 0;
    mysqli_stmt_close($statement);

    return $blocked;
}
