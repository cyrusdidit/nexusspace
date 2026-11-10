<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

if (session_status() === PHP_SESSION_NONE) {
    // Set NEXUSSPACE_FORCE_SECURE_COOKIE=1 when TLS terminates at a trusted proxy.
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || filter_var(getenv('NEXUSSPACE_FORCE_SECURE_COOKIE') ?: '0', FILTER_VALIDATE_BOOLEAN);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '43200');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    if (isset($_SESSION['user_id'])) {
        $now = time();
        $authenticatedAt = filter_var($_SESSION['authenticated_at'] ?? null, FILTER_VALIDATE_INT) ?: $now;
        $lastActiveAt = filter_var($_SESSION['authenticated_last_active_at'] ?? null, FILTER_VALIDATE_INT) ?: $authenticatedAt;
        if ($now - $lastActiveAt >= 1800 || $now - $authenticatedAt >= 43200) {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['auth_expired'] = true;
        } else {
            $_SESSION['authenticated_at'] = $authenticatedAt;
            $_SESSION['authenticated_last_active_at'] = $lastActiveAt;
        }
    }
}

function markAuthenticatedSessionActive(): void
{
    if (isset($_SESSION['user_id'])) {
        $_SESSION['authenticated_last_active_at'] = time();
    }
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

function rememberProviderAuthState(string $provider, string $state, int $userId, int $expiresAt): void
{
    if (!in_array($provider, ['spotify', 'steam'], true) || $state === '' || $userId < 1) {
        throw new InvalidArgumentException('Invalid provider authorization state.');
    }

    $_SESSION['provider_auth_states'] ??= [];
    foreach ($_SESSION['provider_auth_states'] as $savedProvider => $states) {
        if (!is_array($states)) {
            unset($_SESSION['provider_auth_states'][$savedProvider]);
            continue;
        }
        foreach ($states as $savedState => $details) {
            if (!is_array($details) || (int) ($details['expires_at'] ?? 0) < time()) {
                unset($_SESSION['provider_auth_states'][$savedProvider][$savedState]);
            }
        }
    }

    $_SESSION['provider_auth_states'][$provider][$state] = [
        'user_id' => $userId,
        'expires_at' => $expiresAt,
    ];
}

function consumeProviderAuthState(string $provider, string $state): ?int
{
    if (!in_array($provider, ['spotify', 'steam'], true) || $state === '') {
        return null;
    }

    $details = $_SESSION['provider_auth_states'][$provider][$state] ?? null;
    unset($_SESSION['provider_auth_states'][$provider][$state]);
    if (empty($_SESSION['provider_auth_states'][$provider])) {
        unset($_SESSION['provider_auth_states'][$provider]);
    }
    if (empty($_SESSION['provider_auth_states'])) {
        unset($_SESSION['provider_auth_states']);
    }

    if (!is_array($details)
        || (int) ($details['expires_at'] ?? 0) < time()
        || !isset($_SESSION['user_id'])
        || (int) $details['user_id'] !== (int) $_SESSION['user_id']) {
        return null;
    }

    return (int) $details['user_id'];
}
