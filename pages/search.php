<?php

declare(strict_types=1);

session_start();
$isJson = ($_GET['format'] ?? '') === 'json';
if ($isJson) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
}

if (!isset($_SESSION['user_id'])) {
    if ($isJson) {
        http_response_code(401);
        echo json_encode(['error' => 'Please log in again to search.']);
        exit;
    }
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/db_connect.php';

$query = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$error = '';
$users = [];
$hasMore = false;

if (strlen($query) > 50) {
    $error = 'Use 50 characters or fewer.';
} elseif ($query !== '') {
    // Match a literal part of a username, including underscores, without SQL wildcards.
    $statement = mysqli_prepare(
        $conn,
        'SELECT id, username, avatar_path FROM users WHERE LOCATE(LOWER(?), LOWER(username)) > 0 AND id <> ? ORDER BY username, id LIMIT 51'
    );
    $currentUserId = (int) $_SESSION['user_id'];
    mysqli_stmt_bind_param($statement, 'si', $query, $currentUserId);
    mysqli_stmt_execute($statement);
    $users = mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC);
    mysqli_stmt_close($statement);
    $hasMore = count($users) > 50;
    $users = array_slice($users, 0, 50);
}
if ($isJson) {
    if ($error !== '') {
        http_response_code(422);
    }
    echo json_encode(['users' => $users, 'hasMore' => $hasMore, 'error' => $error]);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Find users · NexusSpace</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body>
    <main class="card">
        <h1>Find users</h1>
        <form class="user-search" role="search" method="get" action="search.php">
            <label class="sr-only" for="user-search">Search by username</label>
            <input id="user-search" name="q" type="search" placeholder="Search by username" value="<?= htmlspecialchars($query, ENT_QUOTES, 'UTF-8') ?>" maxlength="50" required>
            <button type="submit">Search</button>
        </form>

        <?php if ($error !== ''): ?>
            <p class="error-box" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php elseif ($query === ''): ?>
            <p>Enter all or part of a username to find someone.</p>
        <?php elseif (!$users): ?>
            <p>No users found for “<?= htmlspecialchars($query, ENT_QUOTES, 'UTF-8') ?>”. Try another username.</p>
        <?php else: ?>
            <p>Results for “<?= htmlspecialchars($query, ENT_QUOTES, 'UTF-8') ?>”:</p>
            <ul class="search-results">
                <?php foreach ($users as $user): ?>
                    <li>
                        <a href="profile.php?id=<?= (int) $user['id'] ?>">
                            <span class="mini-avatar" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr($user['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                            <span><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($hasMore): ?>
                <p>Showing the first 50 matches. Enter more of the username to narrow your search.</p>
            <?php endif; ?>
        <?php endif; ?>

        <p><a href="../index.php">Back to dashboard</a></p>
    </main>
</body>
</html>
