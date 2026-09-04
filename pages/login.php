<?php

declare(strict_types=1);

session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../includes/db_connect.php';

$error = '';
$identity = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identity = trim($_POST['identity'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($identity === '' || $password === '') {
        $error = 'Enter your username or email address and password.';
    } else {
        $statement = mysqli_prepare(
            $conn,
            'SELECT id, username, password_hash FROM users WHERE username = ? OR email = ? LIMIT 1'
        );
        mysqli_stmt_bind_param($statement, 'ss', $identity, $identity);
        mysqli_stmt_execute($statement);
        $result = mysqli_stmt_get_result($statement);
        $user = mysqli_fetch_assoc($result);
        mysqli_stmt_close($statement);

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['username'] = $user['username'];

            header('Location: ../index.php');
            exit;
        }

        $error = 'Incorrect username, email address, or password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in · NexusSpace</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <main class="card">
        <h1>Log in</h1>
        <p>Welcome back to NexusSpace.</p>

        <?php if ($error): ?>
            <div class="error-box" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post">
            <label for="identity">Username or email address</label>
            <input id="identity" name="identity" type="text" value="<?= htmlspecialchars($identity, ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" required>

            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>

            <button type="submit">Log in</button>
        </form>

        <p>New here? <a href="register.php">Create an account</a>.</p>
        <p><a href="../index.php">Back to home</a></p>
    </main>
</body>
</html>
