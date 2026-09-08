<?php

declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$features = [
    'settings' => 'Settings',
];

$featureKey = $_GET['feature'] ?? '';
$featureName = $features[$featureKey] ?? 'This feature';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($featureName, ENT_QUOTES, 'UTF-8') ?> · NexusSpace</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <main class="card">
        <h1><?= htmlspecialchars($featureName, ENT_QUOTES, 'UTF-8') ?></h1>
        <p>This area is planned for a later NexusSpace week.</p>
        <p><a class="button" href="../index.php">Back to dashboard</a></p>
    </main>
</body>
</html>
