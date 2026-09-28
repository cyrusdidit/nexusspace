<?php

declare(strict_types=1);

function resolvedActivityState(?string $state, ?string $lastActiveAt, ?int $ageSeconds = null, int $idleAfterSeconds = 300, int $offlineAfterSeconds = 600): string
{
    if ($state === 'offline' || !$lastActiveAt) return 'offline';

    if ($ageSeconds !== null) {
        if ($ageSeconds >= $offlineAfterSeconds) return 'offline';
        if ($ageSeconds >= $idleAfterSeconds) return 'idle';
    } else {
        $lastActiveTimestamp = strtotime($lastActiveAt);
        if ($lastActiveTimestamp === false) return 'offline';
        $ageSeconds = time() - $lastActiveTimestamp;
        if ($ageSeconds >= $offlineAfterSeconds) return 'offline';
        if ($ageSeconds >= $idleAfterSeconds) return 'idle';
    }

    return $state === 'idle' ? 'idle' : 'online';
}

function activityStatusLabel(string $state, ?string $lastActiveAt): string
{
    if ($state === 'online') return 'Online';
    if ($state === 'idle') return 'Idle';
    if (!$lastActiveAt) return 'Offline';

    $date = new DateTimeImmutable($lastActiveAt);
    $today = new DateTimeImmutable('today');
    if ($date->format('Y-m-d') === $today->format('Y-m-d')) return 'Last seen ' . $date->format('H:i');
    if ($date->format('Y') === $today->format('Y')) return 'Last seen ' . $date->format('M j');
    return 'Last seen ' . $date->format('M j, Y');
}
