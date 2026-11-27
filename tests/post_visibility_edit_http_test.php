<?php

declare(strict_types=1);

// Run against the local NexusSpace site and database: php tests/post_visibility_edit_http_test.php
require __DIR__ . '/../includes/db_connect.php';

$base = rtrim(getenv('NEXUSSPACE_TEST_URL') ?: 'http://nexusspace.test', '/');
$username = 'visibility_edit_' . bin2hex(random_bytes(5));
$email = $username . '@example.invalid';
$password = bin2hex(random_bytes(18));
$cookie = tempnam(sys_get_temp_dir(), 'nexus-post-visibility-');
$userId = 0;
$postId = 0;
$checks = 0;
$failure = null;

$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};

$request = static function (string $path, ?array $data = null) use ($base, $cookie): array {
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_PROXY => '',
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($data !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]);
    $response = curl_exec($curl);
    if ($response === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('HTTP request failed: ' . $error);
    }
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);
    return [$status, substr($response, $headerSize)];
};

try {
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $statement = mysqli_prepare($conn, 'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)');
    mysqli_stmt_bind_param($statement, 'sss', $username, $email, $passwordHash);
    mysqli_stmt_execute($statement);
    $userId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($statement);

    $statement = mysqli_prepare($conn, "INSERT INTO posts (user_id, title, content, visibility) VALUES (?, 'visibility test', 'temporary post content', 'friends')");
    mysqli_stmt_bind_param($statement, 'i', $userId);
    mysqli_stmt_execute($statement);
    $postId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($statement);

    [$status, $loginPage] = $request('/pages/login.php');
    $assert($status === 200 && preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $loginPage, $csrfMatch) === 1, 'Login page or CSRF token unavailable.');
    [$status] = $request('/pages/login.php', ['csrf_token' => $csrfMatch[1], 'identity' => $username, 'password' => $password]);
    $assert($status === 302, 'Disposable post owner could not log in.');

    [$status, $dashboard] = $request('/');
    $assert($status === 200 && preg_match('/data-post-csrf="([a-f0-9]{64})"/', $dashboard, $postCsrfMatch) === 1, 'Dashboard post token unavailable.');
    $assert(str_contains($dashboard, 'data-post-edit-visibility'), 'Post editor does not show an audience selector.');
    $csrf = $postCsrfMatch[1];
    $edit = static fn(string $visibility): array => $request('/post-actions.php', [
        'action' => 'edit_post',
        'post_id' => (string) $postId,
        'csrf_token' => $csrf,
        'context' => 'dashboard',
        'title' => 'visibility test',
        'content' => 'temporary post content',
        'visibility' => $visibility,
    ]);
    $readVisibility = static function () use ($conn, $postId): string {
        $statement = mysqli_prepare($conn, 'SELECT visibility FROM posts WHERE id = ? LIMIT 1');
        mysqli_stmt_bind_param($statement, 'i', $postId);
        mysqli_stmt_execute($statement);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
        mysqli_stmt_close($statement);
        return (string) ($row['visibility'] ?? '');
    };

    [$status, $body] = $edit('public');
    $result = json_decode($body, true);
    $assert($status === 200 && ($result['ok'] ?? false) === true && ($result['visibility'] ?? '') === 'public', 'Editing friends-only post to public did not succeed.');
    $assert($readVisibility() === 'public', 'Public visibility did not persist in the database.');

    [$status, $body] = $edit('friends');
    $result = json_decode($body, true);
    $assert($status === 200 && ($result['ok'] ?? false) === true && ($result['visibility'] ?? '') === 'friends', 'Editing public post to friends-only did not succeed.');
    $assert($readVisibility() === 'friends', 'Friends-only visibility did not persist in the database.');

    [$status] = $edit('private');
    $assert($status === 422, 'Invalid audience was accepted.');
    $assert($readVisibility() === 'friends', 'Invalid audience changed the saved visibility.');
    echo "Post visibility edit HTTP tests passed ($checks checks).\n";
} catch (Throwable $exception) {
    $failure = $exception->getMessage();
} finally {
    if ($postId > 0) {
        $statement = mysqli_prepare($conn, 'DELETE FROM posts WHERE id = ?');
        mysqli_stmt_bind_param($statement, 'i', $postId);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
    }
    if ($userId > 0) {
        $statement = mysqli_prepare($conn, 'DELETE FROM users WHERE id = ? AND username = ? AND email = ?');
        mysqli_stmt_bind_param($statement, 'iss', $userId, $username, $email);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
    }
    if (is_file($cookie)) unlink($cookie);
}

if ($failure !== null) {
    fwrite(STDERR, "Post visibility edit test failed: {$failure}\n");
    exit(1);
}
