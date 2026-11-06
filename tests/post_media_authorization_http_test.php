<?php

// Run against a local NexusSpace server with cURL and mysqli enabled.
// Creates disposable users and a post media file, then removes them in finally.
declare(strict_types=1);

$base = rtrim(getenv('NEXUSSPACE_TEST_URL') ?: 'http://nexusspace.test', '/');
$suffix = bin2hex(random_bytes(5));
$names = ['codex_tmp_owner_' . $suffix, 'codex_tmp_friend_' . $suffix];
$emails = ['codex-owner-' . $suffix . '@example.invalid', 'codex-friend-' . $suffix . '@example.invalid'];
$password = 'NexusTest!' . random_int(10000000, 99999999);
$cookies = [tempnam(sys_get_temp_dir(), 'nexus-owner-'), tempnam(sys_get_temp_dir(), 'nexus-friend-'), tempnam(sys_get_temp_dir(), 'nexus-stranger-')];
$ids = [0, 0];
$postId = 0;
$filename = '';
$mediaFile = '';
$checks = 0;
$failure = null;
$conn = null;

function requestPostMediaTest(string $cookie, string $path, array $headers = []): array
{
    global $base;
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie, CURLOPT_TIMEOUT => 15, CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $headers]);
    $response = curl_exec($curl);
    if ($response === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('HTTP request failed: ' . $error);
    }
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $responseHeaders = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($curl);
    return [$status, $responseHeaders, $body];
}

function checkPostMediaTest(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
    echo "PASS {$message}\n";
}

function registerPostMediaTestAccount(mysqli $conn, string $cookie, string $username, string $email, string $password): int
{
    [$status, , $html] = requestPostMediaTest($cookie, '/pages/register.php');
    if ($status !== 200 || !preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $html, $token)) {
        throw new RuntimeException('Registration form or CSRF token unavailable.');
    }
    // POST separately because the shared request helper accepts headers, not form data.
    $curl = curl_init(rtrim(getenv('NEXUSSPACE_TEST_URL') ?: 'http://nexusspace.test', '/') . '/pages/register.php');
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie, CURLOPT_TIMEOUT => 15, CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['csrf_token' => $token[1], 'username' => $username,
            'email' => $email, 'password' => $password, 'confirm_password' => $password])]);
    $response = curl_exec($curl);
    if ($response === false) throw new RuntimeException('Registration request failed: ' . curl_error($curl));
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($status !== 302) throw new RuntimeException('Disposable account registration failed.');
    $statement = mysqli_prepare($conn, 'SELECT id FROM users WHERE username = ? AND email = ? LIMIT 1');
    mysqli_stmt_bind_param($statement, 'ss', $username, $email);
    mysqli_stmt_execute($statement);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
    mysqli_stmt_close($statement);
    return (int) ($user['id'] ?? 0);
}

try {
    require __DIR__ . '/../includes/db_connect.php';
    $ids[0] = registerPostMediaTestAccount($conn, $cookies[0], $names[0], $emails[0], $password);
    $ids[1] = registerPostMediaTestAccount($conn, $cookies[1], $names[1], $emails[1], $password);
    checkPostMediaTest($ids[0] > 0 && $ids[1] > 0, 'Disposable owner and friend accounts register.');

    $statement = mysqli_prepare($conn, 'INSERT INTO friends (user_id, friend_id) VALUES (?, ?), (?, ?)');
    mysqli_stmt_bind_param($statement, 'iiii', $ids[0], $ids[1], $ids[1], $ids[0]);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);

    $statement = mysqli_prepare($conn, "INSERT INTO posts (user_id, title, content, visibility) VALUES (?, 'media access test', 'temporary media access test', 'friends')");
    mysqli_stmt_bind_param($statement, 'i', $ids[0]);
    mysqli_stmt_execute($statement);
    $postId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($statement);

    $filename = 'post-' . $ids[0] . '-' . bin2hex(random_bytes(12)) . '.jpg';
    $relative = 'uploads/posts/' . $filename;
    $mediaFile = realpath(__DIR__ . '/../uploads/posts') . DIRECTORY_SEPARATOR . $filename;
    if (!str_starts_with(strtolower($mediaFile), strtolower(realpath(__DIR__ . '/../uploads/posts') . DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('Temporary media path escaped uploads/posts.');
    }
    $payload = 'temporary-media-payload';
    if (file_put_contents($mediaFile, $payload) !== strlen($payload)) throw new RuntimeException('Could not create temporary media file.');
    $statement = mysqli_prepare($conn, 'INSERT INTO post_media (post_id, media_path, media_type, sort_order) VALUES (?, ?, \'image\', 0)');
    mysqli_stmt_bind_param($statement, 'is', $postId, $relative);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);

    $url = '/' . $relative;
    [$status, , $body] = requestPostMediaTest($cookies[0], $url);
    checkPostMediaTest($status === 200 && $body === $payload, 'Post owner can load friends-only media through the existing URL.');
    [$status, , $body] = requestPostMediaTest($cookies[1], $url);
    checkPostMediaTest($status === 200 && $body === $payload, 'Friend can load friends-only media.');
    [$status] = requestPostMediaTest($cookies[2], $url);
    checkPostMediaTest($status === 404, 'Unauthenticated stranger cannot load friends-only media.');
    [$status, $headers, $body] = requestPostMediaTest($cookies[1], $url, ['Range: bytes=0-3']);
    checkPostMediaTest($status === 206 && $body === 'temp' && stripos($headers, 'Content-Range: bytes 0-3/' . strlen($payload)) !== false,
        'Video style byte range requests receive a bounded partial response.');

    $statement = mysqli_prepare($conn, 'INSERT INTO user_blocks (blocker_id, blocked_id) VALUES (?, ?)');
    mysqli_stmt_bind_param($statement, 'ii', $ids[1], $ids[0]);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);
    [$status] = requestPostMediaTest($cookies[1], $url);
    checkPostMediaTest($status === 404, 'Blocking either participant prevents friends-only media access.');
} catch (Throwable $exception) {
    $failure = $exception->getMessage();
} finally {
    try {
        if ($conn instanceof mysqli) {
            if ($postId > 0) {
                $statement = mysqli_prepare($conn, 'DELETE FROM posts WHERE id = ?');
                mysqli_stmt_bind_param($statement, 'i', $postId);
                mysqli_stmt_execute($statement);
                mysqli_stmt_close($statement);
            }
            foreach ($ids as $index => $id) {
                if ($id > 0) {
                    $statement = mysqli_prepare($conn, 'DELETE FROM users WHERE id = ? AND username = ? AND email = ?');
                    mysqli_stmt_bind_param($statement, 'iss', $id, $names[$index], $emails[$index]);
                    mysqli_stmt_execute($statement);
                    mysqli_stmt_close($statement);
                }
            }
            mysqli_close($conn);
        }
    } catch (Throwable $cleanupError) {
        $failure = ($failure ? $failure . '; ' : '') . 'Database cleanup failed: ' . $cleanupError->getMessage();
    }
    $uploadsDirectory = realpath(__DIR__ . '/../uploads/posts');
    if ($mediaFile !== '' && $uploadsDirectory !== false && is_file($mediaFile)
        && str_starts_with(strtolower(realpath($mediaFile) ?: ''), strtolower($uploadsDirectory . DIRECTORY_SEPARATOR))) {
        unlink($mediaFile);
    }
    foreach ($cookies as $cookie) if (is_file($cookie)) unlink($cookie);
}

if ($failure !== null) {
    fwrite(STDERR, "FAIL {$failure}\n");
    exit(1);
}

echo "Post media authorization HTTP test passed ({$checks} checks).\n";
