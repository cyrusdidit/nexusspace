<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

function steamApiKey(): string
{
    return trim((string) (getenv('STEAM_WEB_API_KEY') ?: ''));
}

function steamIsConfigured(): bool
{
    return steamApiKey() !== '' && filter_var(steamAppUrl(), FILTER_VALIDATE_URL) !== false;
}

function steamAppUrl(): string
{
    return rtrim(trim((string) (getenv('NEXUSSPACE_URL') ?: '')), '/');
}

function steamSettingsUrl(string $status): string
{
    $appUrl = steamAppUrl();
    $base = filter_var($appUrl, FILTER_VALIDATE_URL) !== false ? $appUrl . '/pages/settings.php' : 'settings.php';
    return $base . '?steam=' . rawurlencode($status);
}

function steamOpenIdUrl(string $state): string
{
    $appUrl = steamAppUrl();
    $returnTo = $appUrl . '/pages/steam-callback.php?state=' . rawurlencode($state);
    return 'https://steamcommunity.com/openid/login?' . http_build_query([
        'openid.ns' => 'http://specs.openid.net/auth/2.0',
        'openid.mode' => 'checkid_setup',
        'openid.return_to' => $returnTo,
        'openid.realm' => $appUrl . '/',
        'openid.identity' => 'http://specs.openid.net/auth/2.0/identifier_select',
        'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
    ], '', '&', PHP_QUERY_RFC3986);
}

function steamRequest(string $endpoint, array $parameters): array
{
    $parameters['key'] = steamApiKey();
    $url = 'https://api.steampowered.com/' . ltrim($endpoint, '/') . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);

    if ($body === false) throw new RuntimeException($error !== '' ? $error : 'Steam could not be reached.');
    $data = json_decode($body, true);
    if ($status !== 200 || !is_array($data)) throw new RuntimeException('Steam returned an invalid response.');
    return $data;
}

function steamVerifyOpenId(array $query, string $state): string
{
    $expectedReturnTo = steamAppUrl() . '/pages/steam-callback.php?state=' . rawurlencode($state);
    if (($query['openid_mode'] ?? '') !== 'id_res'
        || ($query['openid_ns'] ?? '') !== 'http://specs.openid.net/auth/2.0'
        || ($query['openid_op_endpoint'] ?? '') !== 'https://steamcommunity.com/openid/login'
        || ($query['openid_return_to'] ?? '') !== $expectedReturnTo) {
        throw new RuntimeException('Steam returned an invalid login response.');
    }

    $signedFields = array_map('strtolower', array_map('trim', explode(',', (string) ($query['openid_signed'] ?? ''))));
    foreach (['op_endpoint', 'claimed_id', 'identity', 'return_to', 'response_nonce'] as $requiredField) {
        if (!in_array($requiredField, $signedFields, true)) {
            throw new RuntimeException('Steam did not sign the required identity fields.');
        }
    }

    $claimedId = (string) ($query['openid_claimed_id'] ?? '');
    $identity = (string) ($query['openid_identity'] ?? '');
    if ($claimedId === '' || !hash_equals($claimedId, $identity)) {
        throw new RuntimeException('Steam returned mismatched account identifiers.');
    }

    $nonce = (string) ($query['openid_response_nonce'] ?? '');
    if (!preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z).+$/D', $nonce, $nonceMatch)) {
        throw new RuntimeException('Steam returned an invalid login nonce.');
    }
    $nonceTimestamp = strtotime($nonceMatch[1]);
    if ($nonceTimestamp === false || $nonceTimestamp > time() + 60 || time() - $nonceTimestamp > 600) {
        throw new RuntimeException('Steam returned an expired login nonce.');
    }

    $parameterMap = [
        'openid_ns' => 'openid.ns',
        'openid_op_endpoint' => 'openid.op_endpoint',
        'openid_claimed_id' => 'openid.claimed_id',
        'openid_identity' => 'openid.identity',
        'openid_return_to' => 'openid.return_to',
        'openid_response_nonce' => 'openid.response_nonce',
        'openid_assoc_handle' => 'openid.assoc_handle',
        'openid_signed' => 'openid.signed',
        'openid_sig' => 'openid.sig',
    ];
    $verification = ['openid.mode' => 'check_authentication'];
    foreach ($parameterMap as $queryKey => $openidKey) {
        if (!isset($query[$queryKey]) || !is_string($query[$queryKey])) throw new RuntimeException('Steam login response was incomplete.');
        $verification[$openidKey] = $query[$queryKey];
    }

    $curl = curl_init('https://steamcommunity.com/openid/login');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($verification, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($body === false || $status !== 200 || !preg_match('/^is_valid:true$/mi', $body)) {
        throw new RuntimeException('Steam could not verify this login.');
    }

    if (!preg_match('~^https?://steamcommunity\.com/openid/id/(\d{17})$~i', $claimedId, $matches)) {
        throw new RuntimeException('Steam returned an invalid account identifier.');
    }
    return $matches[1];
}

function steamPlayerSummary(string $steamId): ?array
{
    $data = steamRequest('ISteamUser/GetPlayerSummaries/v2/', ['steamids' => $steamId]);
    $player = $data['response']['players'][0] ?? null;
    return is_array($player) ? $player : null;
}

function steamGameImageUrl(string $gameId): ?string
{
    return preg_match('/^\d+$/', $gameId) ? 'https://cdn.akamai.steamstatic.com/steam/apps/' . $gameId . '/header.jpg' : null;
}
