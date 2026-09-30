<?php

declare(strict_types=1);

function loadLocalEnvironment(string $path): void
{
    if (!is_file($path)) return;

    $values = parse_ini_file($path, false, INI_SCANNER_RAW);
    if (!is_array($values)) return;

    foreach ($values as $name => $value) {
        if (!is_string($name) || !is_string($value) || getenv($name) !== false) continue;
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

loadLocalEnvironment(dirname(__DIR__) . '/.env');
