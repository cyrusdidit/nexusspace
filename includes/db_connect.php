<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

$dbHost = getenv('DB_HOST');
$dbPortValue = getenv('DB_PORT');
$dbName = getenv('DB_NAME');
$dbUser = getenv('DB_USER');
$dbPassword = getenv('DB_PASSWORD');
$dbPort = is_string($dbPortValue) && ctype_digit($dbPortValue) ? (int) $dbPortValue : 3306;

if (!is_string($dbHost) || $dbHost === '' || !is_string($dbName) || $dbName === '' || !is_string($dbUser) || $dbUser === '' || $dbPassword === false || $dbPort < 1 || $dbPort > 65535) {
    error_log('NexusSpace database configuration is incomplete.');
    http_response_code(500);
    exit('The application is temporarily unavailable.');
}

try {
    $conn = mysqli_connect($dbHost, $dbUser, $dbPassword, $dbName, $dbPort);
} catch (mysqli_sql_exception $exception) {
    error_log('NexusSpace database connection failed: ' . $exception->getMessage());
    $conn = false;
}

if (!$conn) {
    http_response_code(500);
    exit('The application is temporarily unavailable.');
}

mysqli_set_charset($conn, 'utf8mb4');
