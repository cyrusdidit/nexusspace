<?php

declare(strict_types=1);

final class SecurityRateLimitException extends RuntimeException
{
}

function consumeSecurityRateLimit(mysqli $conn, string $scope, string $subject, int $limit, int $windowSeconds): bool
{
    if (!preg_match('/^[a-z0-9_]{1,32}$/', $scope) || $subject === '' || $limit < 1 || $windowSeconds < 1) {
        throw new InvalidArgumentException('Invalid security rate limit.');
    }

    $subjectHash = hash('sha256', $scope . "\0" . $subject);
    $statement = mysqli_prepare(
        $conn,
        'INSERT INTO security_rate_limits (scope, subject_hash, window_started_at, attempts) VALUES (?, ?, UTC_TIMESTAMP(), 1) ON DUPLICATE KEY UPDATE attempts = IF(window_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND), 1, LEAST(attempts + 1, 65535)), window_started_at = IF(window_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND), UTC_TIMESTAMP(), window_started_at)'
    );
    mysqli_stmt_bind_param($statement, 'ssii', $scope, $subjectHash, $windowSeconds, $windowSeconds);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);

    $statement = mysqli_prepare($conn, 'SELECT attempts FROM security_rate_limits WHERE scope = ? AND subject_hash = ? LIMIT 1');
    mysqli_stmt_bind_param($statement, 'ss', $scope, $subjectHash);
    mysqli_stmt_execute($statement);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
    mysqli_stmt_close($statement);

    if (random_int(1, 50) === 1) {
        mysqli_query($conn, 'DELETE FROM security_rate_limits WHERE window_started_at < UTC_TIMESTAMP() - INTERVAL 1 DAY ORDER BY window_started_at ASC LIMIT 500');
    }

    return (int) ($row['attempts'] ?? ($limit + 1)) <= $limit;
}

function clearSecurityRateLimit(mysqli $conn, string $scope, string $subject): void
{
    if (!preg_match('/^[a-z0-9_]{1,32}$/', $scope) || $subject === '') return;

    $subjectHash = hash('sha256', $scope . "\0" . $subject);
    $statement = mysqli_prepare($conn, 'DELETE FROM security_rate_limits WHERE scope = ? AND subject_hash = ?');
    mysqli_stmt_bind_param($statement, 'ss', $scope, $subjectHash);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);
}

function securityRateLimitClientIp(): string
{
    $address = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '';
    return filter_var($address, FILTER_VALIDATE_IP) ? $address : 'unknown';
}
