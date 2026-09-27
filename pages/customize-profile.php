<?php

declare(strict_types=1);

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/profile_customization.php';

$currentUserId = (int) $_SESSION['user_id'];
$_SESSION['profile_customization_token'] ??= bin2hex(random_bytes(32));
$token = $_SESSION['profile_customization_token'];
$error = '';
$customization = readProfileCustomization($conn, $currentUserId);
$templateHtml = $customization['template_html'];
$customCss = $customization['custom_css'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['token'] ?? '';
    $action = $_POST['action'] ?? 'save';
    if (!is_string($submittedToken) || !hash_equals($token, $submittedToken)) {
        http_response_code(403);
        $error = 'Please refresh the page and try again.';
    } elseif ($action === 'reset') {
        resetProfileCustomization($conn, $currentUserId);
        header('Location: customize-profile.php?reset=1');
        exit;
    } else {
        $templateHtml = is_string($_POST['template_html'] ?? null) ? $_POST['template_html'] : '';
        $customCss = is_string($_POST['custom_css'] ?? null) ? $_POST['custom_css'] : '';
        if (!mb_check_encoding($templateHtml, 'UTF-8') || !mb_check_encoding($customCss, 'UTF-8')) {
            $error = 'Use valid UTF-8 text.';
        } elseif (strlen($templateHtml) > 50000 || strlen($customCss) > 30000) {
            $error = 'HTML must be under 50 KB and CSS must be under 30 KB.';
        } elseif (trim($customCss) !== '' && sanitizeAndScopeProfileCss($customCss) === '') {
            $error = 'CSS must use profile selectors without external URLs, @ rules, or fixed positioning.';
        } else {
            saveProfileCustomization($conn, $currentUserId, $templateHtml, $customCss);
            header('Location: customize-profile.php?saved=1');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Customize Profile &middot; NexusSpace</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <script src="../assets/js/profile-customization-editor.js?v=<?= filemtime(__DIR__ . '/../assets/js/profile-customization-editor.js') ?>" defer></script>
</head>
<body class="profile-customization-page">
    <main class="profile-customization-editor">
        <form method="post" action="customize-profile.php" data-customization-form>
            <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
            <header class="profile-customization-header">
                <a href="profile.php?id=<?= $currentUserId ?>" aria-label="Back to profile" title="Back to profile">&larr;</a>
                <h1>Customize Profile</h1>
                <div>
                    <button class="button-secondary" type="submit" name="action" value="reset" formnovalidate data-customization-reset>Reset</button>
                    <button type="submit" name="action" value="save">Save</button>
                </div>
            </header>
            <?php if ($error !== ''): ?><p class="profile-customization-notice is-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
            <?php if (isset($_GET['saved'])): ?><p class="profile-customization-notice" role="status">Customization saved.</p><?php endif; ?>
            <?php if (isset($_GET['reset'])): ?><p class="profile-customization-notice" role="status">Default template restored.</p><?php endif; ?>
            <div class="profile-customization-fields">
                <section>
                    <label for="profile-template-html">HTML Template</label>
                    <textarea id="profile-template-html" name="template_html" spellcheck="false" maxlength="50000"><?= htmlspecialchars($templateHtml, ENT_QUOTES, 'UTF-8') ?></textarea>
                </section>
                <section>
                    <label for="profile-custom-css">CSS</label>
                    <textarea id="profile-custom-css" name="custom_css" spellcheck="false" maxlength="30000"><?= htmlspecialchars($customCss, ENT_QUOTES, 'UTF-8') ?></textarea>
                </section>
                <section class="profile-customization-preview">
                    <h2>Live Preview</h2>
                    <iframe src="profile-customization-preview.php" title="Live profile customization preview" sandbox data-customization-preview></iframe>
                </section>
            </div>
        </form>
    </main>
</body>
</html>
