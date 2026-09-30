<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

const SPOTIFY_SCOPES = 'user-read-currently-playing user-read-playback-state user-read-private';

function spotifyConfig(): array
{
    return [
        'client_id' => trim((string) (getenv('SPOTIFY_CLIENT_ID') ?: '')),
        'redirect_uri' => trim((string) (getenv('SPOTIFY_REDIRECT_URI') ?: '')),
        'app_url' => rtrim(trim((string) (getenv('NEXUSSPACE_URL') ?: '')), '/'),
        'app_key' => (string) (getenv('NEXUSSPACE_APP_KEY') ?: ''),
    ];
}

function spotifyIsConfigured(): bool
{
    $config = spotifyConfig();
    return $config['client_id'] !== ''
        && filter_var($config['redirect_uri'], FILTER_VALIDATE_URL) !== false
        && filter_var($config['app_url'], FILTER_VALIDATE_URL) !== false
        && strlen($config['app_key']) >= 32;
}

function spotifySettingsUrl(string $status): string
{
    $appUrl = spotifyConfig()['app_url'];
    $base = filter_var($appUrl, FILTER_VALIDATE_URL) !== false ? $appUrl . '/pages/settings.php' : 'settings.php';
    return $base . '?spotify=' . rawurlencode($status);
}

function spotifyBase64Url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function spotifyEncrypt(string $value): string
{
    $key = hash('sha256', spotifyConfig()['app_key'], true);
    $iv = random_bytes(12);
    $tag = '';
    $encrypted = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($encrypted === false) throw new RuntimeException('Spotify credentials could not be encrypted.');
    return base64_encode($iv . $tag . $encrypted);
}

function spotifyDecrypt(string $value): string
{
    $decoded = base64_decode($value, true);
    if ($decoded === false || strlen($decoded) < 29) throw new RuntimeException('Spotify credentials could not be decrypted.');
    $key = hash('sha256', spotifyConfig()['app_key'], true);
    $decrypted = openssl_decrypt(substr($decoded, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($decoded, 0, 12), substr($decoded, 12, 16));
    if ($decrypted === false) throw new RuntimeException('Spotify credentials could not be decrypted.');
    return $decrypted;
}

function spotifyHttpRequest(string $method, string $url, array $headers = [], ?array $form = null): array
{
    $curl = curl_init($url);
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($form !== null) {
        $options[CURLOPT_POSTFIELDS] = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($body === false) throw new RuntimeException($error !== '' ? $error : 'Spotify could not be reached.');
    $data = $body === '' ? null : json_decode($body, true);
    return ['status' => $status, 'data' => is_array($data) ? $data : null];
}

function spotifyRefreshConnection(mysqli $conn, array $connection): array
{
    $config = spotifyConfig();
    $response = spotifyHttpRequest('POST', 'https://accounts.spotify.com/api/token', ['Content-Type: application/x-www-form-urlencoded'], [
        'grant_type' => 'refresh_token',
        'refresh_token' => spotifyDecrypt($connection['refresh_token']),
        'client_id' => $config['client_id'],
    ]);
    if ($response['status'] !== 200 || empty($response['data']['access_token'])) {
        throw new RuntimeException('Spotify authorization needs to be renewed.');
    }

    $accessToken = (string) $response['data']['access_token'];
    $refreshToken = isset($response['data']['refresh_token'])
        ? (string) $response['data']['refresh_token']
        : spotifyDecrypt($connection['refresh_token']);
    $expiresIn = max(60, (int) ($response['data']['expires_in'] ?? 3600));
    $scopes = (string) ($response['data']['scope'] ?? $connection['scopes'] ?? SPOTIFY_SCOPES);
    $encryptedAccess = spotifyEncrypt($accessToken);
    $encryptedRefresh = spotifyEncrypt($refreshToken);
    $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);

    $statement = mysqli_prepare($conn, 'UPDATE spotify_connections SET access_token = ?, refresh_token = ?, token_expires_at = ?, scopes = ? WHERE user_id = ?');
    $userId = (int) $connection['user_id'];
    mysqli_stmt_bind_param($statement, 'ssssi', $encryptedAccess, $encryptedRefresh, $expiresAt, $scopes, $userId);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);

    $connection['access_token'] = $encryptedAccess;
    $connection['refresh_token'] = $encryptedRefresh;
    $connection['token_expires_at'] = $expiresAt;
    $connection['scopes'] = $scopes;
    return $connection;
}

function spotifyAccessToken(mysqli $conn, array $connection): array
{
    $expiresAt = strtotime((string) $connection['token_expires_at']) ?: 0;
    if ($expiresAt <= time() + 60) $connection = spotifyRefreshConnection($conn, $connection);
    return [$connection, spotifyDecrypt($connection['access_token'])];
}
