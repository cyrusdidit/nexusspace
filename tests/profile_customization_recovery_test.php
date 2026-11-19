<?php

declare(strict_types=1);

// Run against the configured local database: php tests/profile_customization_recovery_test.php
require __DIR__ . '/../includes/db_connect.php';
require __DIR__ . '/../includes/profile_customization.php';

$userId = 0;
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};

try {
    $username = 'custom_recovery_' . bin2hex(random_bytes(5));
    $email = $username . '@example.invalid';
    $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $statement = mysqli_prepare($conn, 'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)');
    mysqli_stmt_bind_param($statement, 'sss', $username, $email, $passwordHash);
    mysqli_stmt_execute($statement);
    $userId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($statement);

    $malformedHtml = '<main>{{profile_header}}{{bio}}{{top_eight}}{{posts}}<section>Recover this content<script>window.bad=true</script></main>';
    $malformedCss = '.recovery { color: #123abc;';
    saveProfileCustomizationDraft($conn, $userId, [
        'template_html' => $malformedHtml,
        'custom_css' => $malformedCss,
        'simple_settings' => [],
        'sidebar_width' => 300,
    ]);

    $fresh = mysqli_connect(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASSWORD'), getenv('DB_NAME'), (int) (getenv('DB_PORT') ?: 3306));
    if (!$fresh) throw new RuntimeException('Could not establish a fresh database connection.');
    mysqli_set_charset($fresh, 'utf8mb4');
    $draft = readProfileCustomizationDraft($fresh, $userId);
    $assert($draft !== null, 'Malformed draft was lost after reconnect.');
    $assert($draft['template_html'] === $malformedHtml, 'Malformed HTML was rewritten instead of kept recoverable.');
    $assert($draft['custom_css'] === $malformedCss, 'Malformed CSS was rewritten instead of kept recoverable.');

    $htmlWarnings = validateProfileTemplate($draft['template_html']);
    $cssWarnings = validateProfileCss($draft['custom_css']);
    $assert((bool) array_filter($htmlWarnings, static fn(string $warning): bool => str_contains($warning, '<section> tags appear to be unbalanced')), 'Malformed HTML did not produce a repair warning.');
    $assert(in_array('CSS braces are unbalanced.', $cssWarnings, true), 'Malformed CSS did not produce an unbalanced-braces warning.');

    $safeHtml = sanitizeProfileTemplate($draft['template_html']);
    $assert(str_contains($safeHtml, 'Recover this content'), 'HTML preview discarded recoverable surrounding content.');
    $assert(!str_contains(strtolower($safeHtml), '<script'), 'Unsafe script markup survived preview sanitization.');
    $assert(sanitizeAndScopeProfileCss($draft['custom_css']) === '', 'Malformed CSS was applied in the preview.');
    $assert(readProfileCustomizationDraft($fresh, $userId)['template_html'] === $malformedHtml, 'Preview sanitization altered the stored recoverable draft.');

    $repairedHtml = '<main>{{profile_header}}{{bio}}{{top_eight}}{{posts}}<section>Recovered profile</section></main>';
    $repairedCss = '.recovery { color: #123abc; }';
    saveProfileCustomizationDraft($fresh, $userId, [
        'template_html' => $repairedHtml,
        'custom_css' => $repairedCss,
        'simple_settings' => [],
        'sidebar_width' => 300,
    ]);
    $recovered = readProfileCustomizationDraft($fresh, $userId);
    $assert($recovered !== null && $recovered['template_html'] === $repairedHtml && $recovered['custom_css'] === $repairedCss, 'Corrected content did not replace the malformed draft.');
    $assert(validateProfileTemplate($recovered['template_html']) === [], 'Corrected HTML still reports warnings.');
    $assert(validateProfileCss($recovered['custom_css']) === [], 'Corrected CSS still reports warnings.');
    $assert(str_contains(sanitizeProfileTemplate($recovered['template_html']), 'Recovered profile'), 'Corrected HTML does not render safely.');
    $assert(str_contains(sanitizeAndScopeProfileCss($recovered['custom_css']), '#123abc'), 'Corrected CSS does not render safely.');
    mysqli_close($fresh);

    echo "Profile customization malformed-content recovery tests passed ($checks checks).\n";
} finally {
    if ($userId > 0) {
        $statement = mysqli_prepare($conn, 'DELETE FROM users WHERE id = ?');
        mysqli_stmt_bind_param($statement, 'i', $userId);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
    }
}
