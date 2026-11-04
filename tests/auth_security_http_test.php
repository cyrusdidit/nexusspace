<?php
// Run against a local NexusSpace server: php tests/auth_security_http_test.php
// Uses anonymous sessions only; creates no accounts or provider connections.
$base = rtrim(getenv('NEXUSSPACE_TEST_URL') ?: 'http://nexusspace.test', '/');
$jar = tempnam(sys_get_temp_dir(), 'nexus-security-');
$checks = 0;
function requestSecurityTest(string $path, ?array $data = null): array {
    global $base, $jar;
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 10,
        CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false]);
    if ($data !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]);
    $body = curl_exec($curl);
    if ($body === false) throw new RuntimeException(curl_error($curl));
    return [curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $body];
}
function checkSecurityTest(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
try {
    [$status, $login] = requestSecurityTest('/pages/login.php');
    checkSecurityTest($status === 200, 'Login must load.');
    checkSecurityTest(str_contains(strtolower($login), 'httponly') && str_contains(strtolower($login), 'samesite=lax'), 'Session cookie flags missing.');
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $login, $match);
    checkSecurityTest(isset($match[1]), 'Login token missing.');
    foreach (['/pages/login.php', '/pages/register.php', '/logout.php', '/pages/steam-connect.php', '/pages/spotify-connect.php'] as $path) {
        checkSecurityTest(requestSecurityTest($path, ['csrf_token' => 'forged'])[0] === 403, 'Forged token accepted: ' . $path);
        checkSecurityTest(requestSecurityTest($path, [])[0] === 403, 'Missing token accepted: ' . $path);
    }
    foreach (['/logout.php', '/pages/steam-connect.php', '/pages/spotify-connect.php'] as $path) {
        checkSecurityTest(requestSecurityTest($path)[0] === 405, 'State-changing GET accepted: ' . $path);
    }
    [$status, $body] = requestSecurityTest('/pages/login.php', ['csrf_token' => $match[1], 'identity' => '', 'password' => '']);
    checkSecurityTest($status === 200 && str_contains($body, 'Enter your username'), 'Valid login token rejected.');
    [$status, $body] = requestSecurityTest('/pages/register.php', ['csrf_token' => $match[1]]);
    checkSecurityTest($status === 200 && str_contains($body, 'Username must'), 'Valid registration token rejected.');
    checkSecurityTest(requestSecurityTest('/logout.php', ['csrf_token' => $match[1]])[0] === 302, 'Valid logout rejected.');
    echo "Authentication security HTTP tests passed ($checks checks).\n";
} finally {
    unlink($jar);
}
