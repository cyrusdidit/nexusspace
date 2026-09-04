<?php

declare(strict_types=1);

$dbHost = '127.0.0.1';
$dbName = 'nexusspace';
$dbUser = 'root';
$dbPassword = '';

$conn = mysqli_connect($dbHost, $dbUser, $dbPassword, $dbName);

if (!$conn) {
    http_response_code(500);
    exit('Database connection failed. Check that MySQL is running in Laragon.');
}

mysqli_set_charset($conn, 'utf8mb4');
