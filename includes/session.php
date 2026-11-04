<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    // Local HTTP remains usable; HTTPS sessions receive Secure cookies.
    $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function securityCsrfToken(): string
{
    return $_SESSION['security_csrf'] ??= bin2hex(random_bytes(32));
}

function requireSecurityCsrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        exit('This action requires POST.');
    }
    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !isset($_SESSION['security_csrf']) || !hash_equals($_SESSION['security_csrf'], $token)) {
        http_response_code(403);
        exit('Your session changed. Refresh the page and try again.');
    }
}
