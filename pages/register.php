<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../includes/db_connect.php';

$errors = [];
$username = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!preg_match('/^[A-Za-z0-9_]{3,50}$/', $username)) {
        $errors[] = 'Username must be 3–50 characters and use only letters, numbers, or underscores.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$errors) {
        $checkStatement = mysqli_prepare(
            $conn,
            'SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1'
        );
        mysqli_stmt_bind_param($checkStatement, 'ss', $username, $email);
        mysqli_stmt_execute($checkStatement);
        $existingUser = mysqli_stmt_get_result($checkStatement);

        if (mysqli_num_rows($existingUser) > 0) {
            $errors[] = 'That username or email address is already in use.';
        }

        mysqli_stmt_close($checkStatement);
    }

    if (!$errors) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $insertStatement = mysqli_prepare(
            $conn,
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)'
        );
        mysqli_stmt_bind_param($insertStatement, 'sss', $username, $email, $passwordHash);

        if (mysqli_stmt_execute($insertStatement)) {
            $_SESSION['user_id'] = mysqli_insert_id($conn);
            $_SESSION['username'] = $username;
            mysqli_stmt_close($insertStatement);

            header('Location: ../index.php');
            exit;
        }

        $errors[] = 'We could not create your account. Please try again.';
        mysqli_stmt_close($insertStatement);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create an account · NexusSpace</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <main class="card">
        <h1>Join NexusSpace</h1>
        <p>Create your profile and claim your corner of the internet.</p>

        <?php if ($errors): ?>
            <div class="error-box" role="alert">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" novalidate>
            <label for="username">Username</label>
            <input id="username" name="username" type="text" value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" required>

            <label for="email">Email address</label>
            <input id="email" name="email" type="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" autocomplete="email" required>

            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required>

            <label for="confirm_password">Confirm password</label>
            <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required>

            <button type="submit">Create account</button>
        </form>

        <p><a href="../index.php">Back to home</a></p>
    </main>
</body>
</html>
