<?php

declare(strict_types=1);

function nexusSpaceAdminIds(): array
{
    $configuredIds = getenv('NEXUSSPACE_ADMIN_USER_IDS');
    if (!is_string($configuredIds) || trim($configuredIds) === '') return [];

    $ids = [];
    foreach (explode(',', $configuredIds) as $value) {
        $id = filter_var(trim($value), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id !== false) $ids[] = $id;
    }

    return array_values(array_unique($ids));
}

function isNexusSpaceAdmin(int $userId): bool
{
    return $userId > 0 && in_array($userId, nexusSpaceAdminIds(), true);
}
