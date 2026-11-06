<?php

// Run against a local NexusSpace server with the PHP cURL and mysqli extensions enabled.
// Creates two disposable accounts and removes them in a finally block.
declare(strict_types=1);

$base = rtrim(getenv('NEXUSSPACE_TEST_URL') ?: 'http://nexusspace.test', '/');
$suffix = bin2hex(random_bytes(5));
$viewerName = 'codex_tmp_v_' . $suffix;
$targetName = 'codex_tmp_t_' . $suffix;
$viewerEmail = 'codex-viewer-' . $suffix . '@example.invalid';
$targetEmail = 'codex-target-' . $suffix . '@example.invalid';
$viewerPassword = 'NexusTest!' . random_int(10000000, 99999999);
$targetPassword = 'NexusTest!' . random_int(10000000, 99999999);
$viewerCookie = tempnam(sys_get_temp_dir(), 'nexus-viewer-');
$targetCookie = tempnam(sys_get_temp_dir(), 'nexus-target-');
$viewerId = 0;
$targetId = 0;
$checks = 0;
$failure = null;
$conn = null;

function requestMessageVisibilityTest(string $cookieJar, string $path, ?array $data = null): array
{
    global $base;

    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_PROXY => '',
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($data !== null) {
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]);
    }

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
}

function checkMessageVisibilityTest(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
    echo "PASS {$message}\n";
}

function registerMessageVisibilityTestAccount(mysqli $conn, string $cookieJar, string $username, string $email, string $password): int
{
    [$status, $html] = requestMessageVisibilityTest($cookieJar, '/pages/register.php');
    if ($status !== 200 || !preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $html, $token)) {
        throw new RuntimeException('Registration form or token unavailable.');
    }

    [$status] = requestMessageVisibilityTest($cookieJar, '/pages/register.php', [
        'csrf_token' => $token[1],
        'username' => $username,
        'email' => $email,
        'password' => $password,
        'confirm_password' => $password,
    ]);
    if ($status !== 302) {
        throw new RuntimeException('Disposable account registration failed.');
    }

    $statement = mysqli_prepare($conn, 'SELECT id FROM users WHERE username = ? AND email = ? LIMIT 1');
    mysqli_stmt_bind_param($statement, 'ss', $username, $email);
    mysqli_stmt_execute($statement);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
    mysqli_stmt_close($statement);
    return (int) ($user['id'] ?? 0);
}

try {
    require __DIR__ . '/../includes/db_connect.php';
    $viewerId = registerMessageVisibilityTestAccount($conn, $viewerCookie, $viewerName, $viewerEmail, $viewerPassword);
    $targetId = registerMessageVisibilityTestAccount($conn, $targetCookie, $targetName, $targetEmail, $targetPassword);
    checkMessageVisibilityTest($viewerId > 0 && $targetId > 0, 'Two disposable accounts register.');

    $statement = mysqli_prepare($conn, 'INSERT INTO friends (user_id, friend_id) VALUES (?, ?), (?, ?)');
    mysqli_stmt_bind_param($statement, 'iiii', $viewerId, $targetId, $targetId, $viewerId);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);

    $privateStatus = 'private-status-' . $suffix;
    $statement = mysqli_prepare($conn, "UPDATE users SET status_text = ?, custom_status_audience = 'none' WHERE id = ?");
    mysqli_stmt_bind_param($statement, 'si', $privateStatus, $targetId);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);

    [$status, $html] = requestMessageVisibilityTest($viewerCookie, '/pages/messages.php?user=' . $targetId);
    checkMessageVisibilityTest($status === 200, 'Friend conversation loads.');
    checkMessageVisibilityTest(!str_contains($html, $privateStatus), 'Messages hides a custom status set to No one.');

    $statement = mysqli_prepare($conn, "UPDATE users SET custom_status_audience = 'friends' WHERE id = ?");
    mysqli_stmt_bind_param($statement, 'i', $targetId);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);

    [$status, $html] = requestMessageVisibilityTest($viewerCookie, '/pages/messages.php?user=' . $targetId);
    checkMessageVisibilityTest($status === 200 && str_contains($html, $privateStatus), 'Messages shows a custom status shared with friends.');
} catch (Throwable $exception) {
    $failure = $exception->getMessage();
} finally {
    try {
        if ($conn instanceof mysqli) {
            if ($viewerId > 0 && $targetId > 0) {
                $statement = mysqli_prepare($conn, 'DELETE FROM users WHERE id IN (?, ?) AND username IN (?, ?)');
                mysqli_stmt_bind_param($statement, 'iiss', $viewerId, $targetId, $viewerName, $targetName);
                mysqli_stmt_execute($statement);
                mysqli_stmt_close($statement);
            } else {
                foreach ([[$viewerName, $viewerEmail], [$targetName, $targetEmail]] as [$username, $email]) {
                    $statement = mysqli_prepare($conn, 'DELETE FROM users WHERE username = ? AND email = ?');
                    mysqli_stmt_bind_param($statement, 'ss', $username, $email);
                    mysqli_stmt_execute($statement);
                    mysqli_stmt_close($statement);
                }
            }
            echo "CLEANUP Disposable accounts and friendship rows removed.\n";
            mysqli_close($conn);
        }
    } catch (Throwable $cleanupError) {
        $failure = ($failure ? $failure . '; ' : '') . 'Cleanup failed: ' . $cleanupError->getMessage();
    }

    foreach ([$viewerCookie, $targetCookie] as $cookieJar) {
        if (is_file($cookieJar)) {
            unlink($cookieJar);
        }
    }
}

if ($failure !== null) {
    fwrite(STDERR, "FAIL {$failure}\n");
    exit(1);
}

echo "Message status visibility HTTP test passed ({$checks} checks).\n";
