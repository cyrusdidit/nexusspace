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
$draftCustomization = readProfileCustomizationDraft($conn, $currentUserId);
if ($draftCustomization) $customization = array_replace($customization, $draftCustomization);
$templateHtml = $customization['template_html'];
$customCss = $customization['custom_css'];
$simpleSettings = $customization['simple_settings'];
$sidebarWidth = $customization['sidebar_width'];

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
    } elseif ($action === 'restore_revision') {
        $revisionNumber = filter_var($_POST['revision_number'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($revisionNumber === false || $revisionNumber === null) {
            http_response_code(422);
            $error = 'Choose a valid revision.';
        } else {
            try {
                $newRevision = restoreProfileCustomizationRevision($conn, $currentUserId, (int) $revisionNumber);
                if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['saved' => true, 'revision' => $newRevision], JSON_UNESCAPED_SLASHES);
                    exit;
                }
                header('Location: customize-profile.php?restored=1');
                exit;
            } catch (InvalidArgumentException $exception) {
                http_response_code(404);
                $error = $exception->getMessage();
            }
        }
    } else {
        $templateHtml = is_string($_POST['template_html'] ?? null) ? $_POST['template_html'] : '';
        $customCss = is_string($_POST['custom_css'] ?? null) ? $_POST['custom_css'] : '';
        $simpleSettings = normalizeProfileSimpleSettings($_POST['simple_settings'] ?? null);
        $sidebarWidth = normalizeProfileSidebarWidth($_POST['sidebar_width'] ?? PROFILE_SIDEBAR_DEFAULT_WIDTH);
        if (!mb_check_encoding($templateHtml, 'UTF-8') || !mb_check_encoding($customCss, 'UTF-8')) {
            $error = 'Use valid UTF-8 text.';
        } elseif (strlen($templateHtml) > 50000 || strlen($customCss) > 30000) {
            $error = 'HTML must be under 50 KB and CSS must be under 30 KB.';
        } elseif ($action === 'draft') {
            saveProfileCustomizationDraft($conn, $currentUserId, [
                'simple_settings' => $simpleSettings,
                'sidebar_width' => $sidebarWidth,
                'template_html' => $templateHtml,
                'custom_css' => $customCss,
            ]);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'saved' => true,
                'simple_css' => renderProfileSimpleCss($simpleSettings, $sidebarWidth),
                'custom_css' => sanitizeAndScopeProfileCss($customCss),
                'warnings' => ['html' => validateProfileTemplate($templateHtml), 'css' => validateProfileCss($customCss)],
            ], JSON_UNESCAPED_SLASHES);
            exit;
        } else {
            saveProfileCustomization($conn, $currentUserId, $templateHtml, $customCss, $simpleSettings, $sidebarWidth);
            if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'saved' => true,
                    'simple_css' => renderProfileSimpleCss($simpleSettings, $sidebarWidth),
                    'custom_css' => sanitizeAndScopeProfileCss($customCss),
                    'warnings' => ['html' => validateProfileTemplate($templateHtml), 'css' => validateProfileCss($customCss)],
                ], JSON_UNESCAPED_SLASHES);
                exit;
            }
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
    <script src="../assets/js/activity.js?v=<?= filemtime(__DIR__ . '/../assets/js/activity.js') ?>" defer></script>
    <script src="../assets/js/profile-customization-editor.js?v=<?= filemtime(__DIR__ . '/../assets/js/profile-customization-editor.js') ?>" defer></script>
</head>
<body class="profile-customization-page" data-activity-endpoint="../activity-ping.php">
    <main class="profile-customization-editor">
        <form method="post" action="customize-profile.php" data-customization-form>
            <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="simple_settings" value="<?= htmlspecialchars(encodeProfileSimpleSettings($simpleSettings), ENT_QUOTES, 'UTF-8') ?>" data-simple-settings>
            <input type="hidden" name="sidebar_width" value="<?= (int) $sidebarWidth ?>" data-sidebar-width-value>
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
            <div class="profile-customization-workspace">
                <section class="profile-customization-tools" aria-label="Customization controls">
                    <div class="profile-customization-tabs" role="tablist" aria-label="Editor mode">
                        <button type="button" role="tab" aria-selected="true" aria-controls="profile-simple-editor" data-editor-mode="simple">Simple</button>
                        <button type="button" role="tab" aria-selected="false" aria-controls="profile-advanced-editor" data-editor-mode="advanced">Advanced</button>
                    </div>
                    <div class="profile-simple-editor" id="profile-simple-editor" role="tabpanel" data-simple-editor>
                        <div class="profile-target-picker">
                            <label for="profile-style-target">Edit part</label>
                            <select id="profile-style-target" data-style-target>
                                <?php foreach (profileCustomizationTargetGroups() as $group => $targets): ?>
                                    <optgroup label="<?= htmlspecialchars($group, ENT_QUOTES, 'UTF-8') ?>">
                                        <?php foreach ($targets as $target): ?><option value="<?= htmlspecialchars($target, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(profileCustomizationTargetLabel($target), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <button class="button-secondary" type="button" data-reset-target>Reset this part</button>
                        </div>
                        <fieldset>
                            <legend>Typography</legend>
                            <label>Font<select data-style-property="font_family"><option value="system">Default</option><option>Arial</option><option>Georgia</option><option>Times New Roman</option><option>Trebuchet MS</option><option>Verdana</option><option>Courier New</option></select></label>
                            <label>Size <span><input type="number" min="8" max="72" placeholder="Default" data-style-property="font_size"><small>px</small></span></label>
                            <label>Weight<select data-style-property="font_weight"><option value="">Default</option><option value="normal">Normal</option><option value="500">Medium</option><option value="600">Semi-bold</option><option value="700">Bold</option><option value="800">Extra bold</option></select></label>
                            <label>Style<select data-style-property="font_style"><option value="">Default</option><option value="normal">Normal</option><option value="italic">Italic</option></select></label>
                            <label>Alignment<select data-style-property="text_align"><option value="">Default</option><option value="left">Left</option><option value="center">Center</option><option value="right">Right</option></select></label>
                        </fieldset>
                        <fieldset>
                            <legend>Text paint</legend>
                            <label>Solid color <span class="profile-color-control"><input type="color" value="#163b5c" data-style-property="text_color"><button type="button" aria-label="Clear text color" title="Use default" data-clear-property="text_color">&times;</button></span></label>
                            <label>Gradient start <span class="profile-color-control"><input type="color" value="#238bc5" data-style-property="text_gradient_from"><button type="button" aria-label="Clear gradient start" title="Use default" data-clear-property="text_gradient_from">&times;</button></span></label>
                            <label>Gradient end <span class="profile-color-control"><input type="color" value="#b51d6b" data-style-property="text_gradient_to"><button type="button" aria-label="Clear gradient end" title="Use default" data-clear-property="text_gradient_to">&times;</button></span></label>
                            <label>Gradient angle <span><input type="number" min="0" max="360" value="90" data-style-property="text_gradient_angle"><small>deg</small></span></label>
                        </fieldset>
                        <fieldset>
                            <legend>Surface and border</legend>
                            <label>Background <span class="profile-color-control"><input type="color" value="#ffffff" data-style-property="background_color"><button type="button" aria-label="Clear background color" title="Use default" data-clear-property="background_color">&times;</button></span></label>
                            <label>Border color <span class="profile-color-control"><input type="color" value="#79b9dd" data-style-property="border_color"><button type="button" aria-label="Clear border color" title="Use default" data-clear-property="border_color">&times;</button></span></label>
                            <label>Border width <span><input type="number" min="0" max="12" placeholder="Default" data-style-property="border_width"><small>px</small></span></label>
                            <label>Border style<select data-style-property="border_style"><option value="">Default</option><option value="solid">Solid</option><option value="dashed">Dashed</option><option value="dotted">Dotted</option><option value="double">Double</option></select></label>
                            <label>Corner radius <span><input type="number" min="0" max="48" placeholder="Default" data-style-property="border_radius"><small>px</small></span></label>
                        </fieldset>
                        <fieldset>
                            <legend>Spacing and effects</legend>
                            <label>Padding <span><input type="number" min="0" max="64" placeholder="Default" data-style-property="padding"><small>px</small></span></label>
                            <label>Margin <span><input type="number" min="0" max="64" placeholder="Default" data-style-property="margin"><small>px</small></span></label>
                            <label>Opacity <span><input type="range" min="10" max="100" value="100" data-style-property="opacity"><output data-property-output="opacity">Default</output></span></label>
                            <label>Animation<select data-style-property="animation"><option value="none">None</option><option value="pulse">Pulse</option><option value="float">Float</option><option value="wiggle">Wiggle</option></select></label>
                        </fieldset>
                        <fieldset>
                            <legend>Layout</legend>
                            <label>Sidebar width <span><input type="range" min="220" max="520" value="<?= (int) $sidebarWidth ?>" data-sidebar-width><output data-sidebar-width-output><?= (int) $sidebarWidth ?>px</output></span></label>
                        </fieldset>
                    </div>
                    <div class="profile-advanced-editor" id="profile-advanced-editor" role="tabpanel" data-advanced-editor hidden>
                        <section>
                            <label for="profile-template-html">HTML Template</label>
                            <div class="profile-customization-warnings" data-html-warnings role="status" hidden></div>
                            <div class="profile-code-editor"><pre aria-hidden="true" data-html-highlight></pre><textarea id="profile-template-html" name="template_html" spellcheck="false" wrap="off" maxlength="50000"><?= htmlspecialchars($templateHtml, ENT_QUOTES, 'UTF-8') ?></textarea></div>
                        </section>
                        <section>
                            <label for="profile-custom-css">CSS</label>
                            <div class="profile-customization-warnings" data-css-warnings role="status" hidden></div>
                            <div class="profile-code-editor"><pre aria-hidden="true" data-css-highlight></pre><textarea id="profile-custom-css" name="custom_css" spellcheck="false" wrap="off" maxlength="30000"><?= htmlspecialchars($customCss, ENT_QUOTES, 'UTF-8') ?></textarea></div>
                        </section>
                    </div>
                </section>
                <section class="profile-customization-preview">
                    <header><h2>Live Preview</h2><span data-draft-status>Draft ready</span></header>
                    <iframe src="profile-customization-preview.php" title="Live profile customization preview" sandbox data-customization-preview></iframe>
                </section>
            </div>
        </form>
    </main>
</body>
</html>
