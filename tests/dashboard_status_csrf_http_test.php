<?php

// Run against a local NexusSpace server with the PHP cURL and mysqli extensions enabled.
// Creates one disposable account through registration and removes it in a finally block.
declare(strict_types=1);

$base = rtrim(getenv('NEXUSSPACE_TEST_URL') ?: 'http://nexusspace.test', '/');
$cookieJar = tempnam(sys_get_temp_dir(), 'nexus-status-csrf-');
$suffix = bin2hex(random_bytes(5));
$username = 'codex_tmp_' . $suffix;
$email = 'codex-test-' . $suffix . '@example.invalid';
$password = 'NexusTest!' . random_int(10000000, 99999999);
$checks = 0;
$failure = null;
$conn = null;

function requestDashboardStatusTest(string $path, ?array $data = null): array
{
    global $base, $cookieJar;

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

function checkDashboardStatusTest(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
    echo "PASS {$message}\n";
}

function csrfTokenFromForm(string $html, string $formPattern): string
{
    if (!preg_match($formPattern, $html, $form)
        || !preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $form[1], $token)) {
        throw new RuntimeException('Expected form CSRF token was missing.');
    }
    return $token[1];
}

try {
    require __DIR__ . '/../includes/db_connect.php';

    [$status, $registerHtml] = requestDashboardStatusTest('/pages/register.php');
    checkDashboardStatusTest($status === 200, 'Registration form loads.');
    $registerToken = csrfTokenFromForm($registerHtml, '/<form method="post"[^>]*>(.*?)<\/form>/s');

    [$status] = requestDashboardStatusTest('/pages/register.php', [
        'csrf_token' => $registerToken,
        'username' => $username,
        'email' => $email,
        'password' => $password,
        'confirm_password' => $password,
    ]);
    checkDashboardStatusTest($status === 302, 'Disposable account registration succeeds.');

    [$status, $dashboardHtml] = requestDashboardStatusTest('/index.php');
    checkDashboardStatusTest($status === 200, 'Authenticated dashboard loads.');
    $statusToken = csrfTokenFromForm($dashboardHtml, '/<form class="status-form"[^>]*>(.*?)<\/form>/s');

    [$status] = requestDashboardStatusTest('/index.php', [
        'action' => 'update_status',
        'status' => 'must_not_save',
    ]);
    checkDashboardStatusTest($status === 403, 'Tokenless dashboard status update is rejected.');

    $statement = mysqli_prepare($conn, 'SELECT status_text FROM users WHERE username = ? AND email = ? LIMIT 1');
    mysqli_stmt_bind_param($statement, 'ss', $username, $email);
    mysqli_stmt_execute($statement);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
    mysqli_stmt_close($statement);
    checkDashboardStatusTest(($user['status_text'] ?? null) === null, 'Rejected update leaves the status unchanged.');

    [$status] = requestDashboardStatusTest('/index.php', [
        'action' => 'update_status',
        'status' => 'csrf-guard-ok',
        'csrf_token' => $statusToken,
    ]);
    checkDashboardStatusTest($status === 302, 'Valid dashboard status update succeeds.');

    $statement = mysqli_prepare($conn, 'SELECT status_text FROM users WHERE username = ? AND email = ? LIMIT 1');
    mysqli_stmt_bind_param($statement, 'ss', $username, $email);
    mysqli_stmt_execute($statement);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
    mysqli_stmt_close($statement);
    checkDashboardStatusTest(($user['status_text'] ?? '') === 'csrf-guard-ok', 'Valid update saves the requested status.');
} catch (Throwable $exception) {
    $failure = $exception->getMessage();
} finally {
    try {
        if ($conn instanceof mysqli) {
            $statement = mysqli_prepare($conn, 'SELECT id FROM users WHERE username = ? AND email = ? LIMIT 1');
            mysqli_stmt_bind_param($statement, 'ss', $username, $email);
            mysqli_stmt_execute($statement);
            $user = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
            mysqli_stmt_close($statement);

            $userId = (int) ($user['id'] ?? 0);
            if ($userId > 0) {
                $statement = mysqli_prepare($conn, 'DELETE FROM users WHERE id = ? AND username = ? AND email = ?');
                mysqli_stmt_bind_param($statement, 'iss', $userId, $username, $email);
                mysqli_stmt_execute($statement);
                $deleted = mysqli_stmt_affected_rows($statement) === 1;
                mysqli_stmt_close($statement);
                if (!$deleted) {
                    throw new RuntimeException('Could not remove the disposable account.');
                }
            }

            echo "CLEANUP Disposable account removed.\n";
            mysqli_close($conn);
        }
    } catch (Throwable $cleanupError) {
        $failure = ($failure ? $failure . '; ' : '') . 'Cleanup failed: ' . $cleanupError->getMessage();
    }

    if (is_file($cookieJar)) {
        unlink($cookieJar);
    }
}

if ($failure !== null) {
    fwrite(STDERR, "FAIL {$failure}\n");
    exit(1);
}

echo "Dashboard status CSRF HTTP test passed ({$checks} checks).\n";
