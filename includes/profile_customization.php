<?php

declare(strict_types=1);

const DEFAULT_PROFILE_TEMPLATE = <<<'HTML'
<div class="profile-layout" data-profile-style="layout.root">
    <aside class="profile-sidebar" data-profile-style="layout.sidebar">
        {{profile_header}}
        {{bio}}
        {{top_eight}}
    </aside>
    {{posts}}
</div>
HTML;

const DEFAULT_PROFILE_CSS = '';
const PROFILE_CUSTOMIZATION_SCHEMA_VERSION = 2;
const PROFILE_SIDEBAR_DEFAULT_WIDTH = 280;
const PROFILE_SIDEBAR_MIN_WIDTH = 220;
const PROFILE_SIDEBAR_MAX_WIDTH = 520;
const PROFILE_CUSTOMIZATION_REVISION_LIMIT = 20;

function defaultProfileSimpleSettings(): array
{
    return [];
}

function normalizeProfileSimpleSettings(mixed $settings): array
{
    if (is_string($settings)) {
        try {
            $settings = json_decode($settings, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return defaultProfileSimpleSettings();
        }
    }
    return is_array($settings) ? $settings : defaultProfileSimpleSettings();
}

function encodeProfileSimpleSettings(array $settings): string
{
    return json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function normalizeProfileSidebarWidth(mixed $width): int
{
    return min(PROFILE_SIDEBAR_MAX_WIDTH, max(PROFILE_SIDEBAR_MIN_WIDTH, (int) $width));
}

function defaultProfileCustomization(): array
{
    return [
        'schema_version' => PROFILE_CUSTOMIZATION_SCHEMA_VERSION,
        'simple_settings' => defaultProfileSimpleSettings(),
        'sidebar_width' => PROFILE_SIDEBAR_DEFAULT_WIDTH,
        'template_html' => DEFAULT_PROFILE_TEMPLATE,
        'custom_css' => DEFAULT_PROFILE_CSS,
        'published_revision' => 0,
        'updated_at' => null,
    ];
}

function readProfileCustomization(mysqli $conn, int $userId): array
{
    $statement = mysqli_prepare($conn, 'SELECT schema_version, simple_settings, sidebar_width, template_html, custom_css, published_revision, updated_at FROM profile_customizations WHERE user_id = ? LIMIT 1');
    mysqli_stmt_bind_param($statement, 'i', $userId);
    mysqli_stmt_execute($statement);
    $customization = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
    mysqli_stmt_close($statement);

    if (!$customization) return defaultProfileCustomization();
    $customization['schema_version'] = max(1, (int) $customization['schema_version']);
    $customization['simple_settings'] = normalizeProfileSimpleSettings($customization['simple_settings']);
    $customization['sidebar_width'] = normalizeProfileSidebarWidth($customization['sidebar_width']);
    $customization['published_revision'] = max(0, (int) $customization['published_revision']);
    return $customization;
}

function saveProfileCustomization(
    mysqli $conn,
    int $userId,
    string $templateHtml,
    string $customCss,
    ?array $simpleSettings = null,
    ?int $sidebarWidth = null
): int
{
    mysqli_begin_transaction($conn);
    try {
        $statement = mysqli_prepare($conn, 'SELECT simple_settings, sidebar_width, published_revision FROM profile_customizations WHERE user_id = ? LIMIT 1 FOR UPDATE');
        mysqli_stmt_bind_param($statement, 'i', $userId);
        mysqli_stmt_execute($statement);
        $current = mysqli_fetch_assoc(mysqli_stmt_get_result($statement)) ?: null;
        mysqli_stmt_close($statement);

        $settings = $simpleSettings ?? normalizeProfileSimpleSettings($current['simple_settings'] ?? null);
        $width = normalizeProfileSidebarWidth($sidebarWidth ?? $current['sidebar_width'] ?? PROFILE_SIDEBAR_DEFAULT_WIDTH);
        $revision = max(0, (int) ($current['published_revision'] ?? 0)) + 1;
        $settingsJson = encodeProfileSimpleSettings($settings);
        $schemaVersion = PROFILE_CUSTOMIZATION_SCHEMA_VERSION;

        $statement = mysqli_prepare($conn, 'INSERT INTO profile_customizations (user_id, schema_version, simple_settings, sidebar_width, template_html, custom_css, published_revision) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE schema_version = VALUES(schema_version), simple_settings = VALUES(simple_settings), sidebar_width = VALUES(sidebar_width), template_html = VALUES(template_html), custom_css = VALUES(custom_css), published_revision = VALUES(published_revision)');
        mysqli_stmt_bind_param($statement, 'iisissi', $userId, $schemaVersion, $settingsJson, $width, $templateHtml, $customCss, $revision);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);

        $statement = mysqli_prepare($conn, 'INSERT INTO profile_customization_revisions (user_id, revision_number, schema_version, simple_settings, sidebar_width, template_html, custom_css) VALUES (?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($statement, 'iiisiss', $userId, $revision, $schemaVersion, $settingsJson, $width, $templateHtml, $customCss);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);

        $oldestRevision = max(1, $revision - PROFILE_CUSTOMIZATION_REVISION_LIMIT + 1);
        $statement = mysqli_prepare($conn, 'DELETE FROM profile_customization_revisions WHERE user_id = ? AND revision_number < ?');
        mysqli_stmt_bind_param($statement, 'ii', $userId, $oldestRevision);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);

        $statement = mysqli_prepare($conn, 'DELETE FROM profile_customization_drafts WHERE user_id = ?');
        mysqli_stmt_bind_param($statement, 'i', $userId);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        mysqli_commit($conn);
        return $revision;
    } catch (Throwable $error) {
        mysqli_rollback($conn);
        throw $error;
    }
}

function resetProfileCustomization(mysqli $conn, int $userId): void
{
    saveProfileCustomization(
        $conn,
        $userId,
        DEFAULT_PROFILE_TEMPLATE,
        DEFAULT_PROFILE_CSS,
        defaultProfileSimpleSettings(),
        PROFILE_SIDEBAR_DEFAULT_WIDTH
    );
}

function readProfileCustomizationDraft(mysqli $conn, int $userId): ?array
{
    $statement = mysqli_prepare($conn, 'SELECT schema_version, simple_settings, sidebar_width, template_html, custom_css, updated_at FROM profile_customization_drafts WHERE user_id = ? LIMIT 1');
    mysqli_stmt_bind_param($statement, 'i', $userId);
    mysqli_stmt_execute($statement);
    $draft = mysqli_fetch_assoc(mysqli_stmt_get_result($statement)) ?: null;
    mysqli_stmt_close($statement);
    if (!$draft) return null;
    $draft['schema_version'] = max(1, (int) $draft['schema_version']);
    $draft['simple_settings'] = normalizeProfileSimpleSettings($draft['simple_settings']);
    $draft['sidebar_width'] = normalizeProfileSidebarWidth($draft['sidebar_width']);
    return $draft;
}

function saveProfileCustomizationDraft(mysqli $conn, int $userId, array $customization): void
{
    $settingsJson = encodeProfileSimpleSettings(normalizeProfileSimpleSettings($customization['simple_settings'] ?? null));
    $width = normalizeProfileSidebarWidth($customization['sidebar_width'] ?? PROFILE_SIDEBAR_DEFAULT_WIDTH);
    $templateHtml = (string) ($customization['template_html'] ?? DEFAULT_PROFILE_TEMPLATE);
    $customCss = (string) ($customization['custom_css'] ?? DEFAULT_PROFILE_CSS);
    $schemaVersion = PROFILE_CUSTOMIZATION_SCHEMA_VERSION;
    $statement = mysqli_prepare($conn, 'INSERT INTO profile_customization_drafts (user_id, schema_version, simple_settings, sidebar_width, template_html, custom_css) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE schema_version = VALUES(schema_version), simple_settings = VALUES(simple_settings), sidebar_width = VALUES(sidebar_width), template_html = VALUES(template_html), custom_css = VALUES(custom_css)');
    mysqli_stmt_bind_param($statement, 'iisiss', $userId, $schemaVersion, $settingsJson, $width, $templateHtml, $customCss);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);
}

function readProfileCustomizationRevisions(mysqli $conn, int $userId, int $limit = PROFILE_CUSTOMIZATION_REVISION_LIMIT): array
{
    $limit = min(PROFILE_CUSTOMIZATION_REVISION_LIMIT, max(1, $limit));
    $statement = mysqli_prepare($conn, 'SELECT revision_number, schema_version, sidebar_width, created_at FROM profile_customization_revisions WHERE user_id = ? ORDER BY revision_number DESC LIMIT ?');
    mysqli_stmt_bind_param($statement, 'ii', $userId, $limit);
    mysqli_stmt_execute($statement);
    $revisions = mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC);
    mysqli_stmt_close($statement);
    return array_map(static function (array $revision): array {
        $revision['revision_number'] = (int) $revision['revision_number'];
        $revision['schema_version'] = (int) $revision['schema_version'];
        $revision['sidebar_width'] = normalizeProfileSidebarWidth($revision['sidebar_width']);
        return $revision;
    }, $revisions);
}

function restoreProfileCustomizationRevision(mysqli $conn, int $userId, int $revisionNumber): int
{
    $statement = mysqli_prepare($conn, 'SELECT simple_settings, sidebar_width, template_html, custom_css FROM profile_customization_revisions WHERE user_id = ? AND revision_number = ? LIMIT 1');
    mysqli_stmt_bind_param($statement, 'ii', $userId, $revisionNumber);
    mysqli_stmt_execute($statement);
    $revision = mysqli_fetch_assoc(mysqli_stmt_get_result($statement)) ?: null;
    mysqli_stmt_close($statement);
    if (!$revision) throw new InvalidArgumentException('That profile revision no longer exists.');
    return saveProfileCustomization(
        $conn,
        $userId,
        (string) $revision['template_html'],
        (string) $revision['custom_css'],
        normalizeProfileSimpleSettings($revision['simple_settings']),
        normalizeProfileSidebarWidth($revision['sidebar_width'])
    );
}

function sanitizeProfileTemplate(string $templateHtml): string
{
    $allowedPlaceholders = ['{{profile_header}}', '{{bio}}', '{{top_eight}}', '{{posts}}'];
    $templateHtml = preg_replace_callback(
        '/{{\s*[^{}]+?\s*}}/',
        static fn (array $match): string => in_array($match[0], $allowedPlaceholders, true) ? $match[0] : '',
        $templateHtml
    ) ?? '';

    $document = new DOMDocument('1.0', 'UTF-8');
    $previousErrors = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"><div id="profile-template-root">' . $templateHtml . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);

    $blockedElements = ['base', 'button', 'embed', 'form', 'iframe', 'input', 'link', 'meta', 'object', 'option', 'script', 'select', 'style', 'textarea'];
    $elements = [];
    foreach ($document->getElementsByTagName('*') as $element) $elements[] = $element;
    foreach ($elements as $element) {
        if ($element->getAttribute('id') !== 'profile-template-root' && in_array(strtolower($element->tagName), $blockedElements, true)) {
            $element->parentNode?->removeChild($element);
            continue;
        }
        $attributes = [];
        foreach ($element->attributes as $attribute) $attributes[] = $attribute->name;
        foreach ($attributes as $attributeName) {
            $lowerName = strtolower($attributeName);
            $value = $element->getAttribute($attributeName);
            if (str_starts_with($lowerName, 'on') || in_array($lowerName, ['data-profile-style', 'formaction', 'srcdoc', 'style'], true) || str_contains($value, '{{')) {
                $element->removeAttribute($attributeName);
                continue;
            }
            if (in_array($lowerName, ['href', 'src'], true) && preg_match('~^\s*(?:javascript|vbscript):~i', $value)) {
                $element->removeAttribute($attributeName);
            }
        }
        if (strtolower($element->tagName) === 'a') {
            $element->setAttribute('target', '_blank');
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    $root = (new DOMXPath($document))->query('//*[@id="profile-template-root"]')->item(0);
    if (!$root) return '';
    $safeHtml = '';
    foreach ($root->childNodes as $child) $safeHtml .= $document->saveHTML($child);
    return $safeHtml;
}

function decorateProfileTemplate(string $safeTemplate): string
{
    $document = new DOMDocument('1.0', 'UTF-8');
    $previousErrors = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"><div id="profile-template-root">' . $safeTemplate . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);

    $xpath = new DOMXPath($document);
    $targets = [
        'profile-layout' => 'layout.root',
        'profile-sidebar' => 'layout.sidebar',
    ];
    foreach ($targets as $className => $target) {
        $elements = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " ' . $className . ' ")]');
        foreach ($elements ?: [] as $element) $element->setAttribute('data-profile-style', $target);
    }

    $root = $xpath->query('//*[@id="profile-template-root"]')->item(0);
    if (!$root) return $safeTemplate;
    $decorated = '';
    foreach ($root->childNodes as $child) $decorated .= $document->saveHTML($child);
    return $decorated;
}

function appendLockedProfileControlsPlaceholder(string $safeTemplate): string
{
    $safeTemplate = str_replace('{{profile_controls}}', '', $safeTemplate);
    $document = new DOMDocument('1.0', 'UTF-8');
    $previousErrors = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"><div id="profile-template-root">' . $safeTemplate . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);

    $xpath = new DOMXPath($document);
    $root = $xpath->query('//*[@id="profile-template-root"]')->item(0);
    if (!$root) return '{{profile_controls}}';
    $sidebar = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " profile-sidebar ")]')->item(0);
    ($sidebar ?: $root)->appendChild($document->createTextNode('{{profile_controls}}'));

    $templateWithControls = '';
    foreach ($root->childNodes as $child) $templateWithControls .= $document->saveHTML($child);
    return $templateWithControls;
}

function sanitizeAndScopeProfileCss(string $customCss, string $scope = '.profile-custom-content'): string
{
    $css = preg_replace('~/\*.*?\*/~s', '', $customCss) ?? '';
    if (trim($css) === '') return '';

    $blockedSyntax = '~(?:@|url\s*\(|expression\s*\(|javascript\s*:|vbscript\s*:|</?style|behavior\s*:|-moz-binding\s*:|position\s*:\s*fixed\b)~i';
    if (preg_match($blockedSyntax, $css) || substr_count($css, '{') !== substr_count($css, '}')) return '';

    preg_match_all('~([^{}]+)\{([^{}]*)\}~s', $css, $matches, PREG_SET_ORDER);
    $unparsed = preg_replace('~[^{}]+\{[^{}]*\}~s', '', $css) ?? '';
    if (trim($unparsed) !== '') return '';

    $safeRules = [];
    foreach ($matches as $match) {
        $declarations = trim(preg_replace('~\s*!important\b~i', '', $match[2]) ?? '');
        if ($declarations === '') continue;

        $safeSelectors = [];
        foreach (explode(',', $match[1]) as $selector) {
            $selector = trim($selector);
            if ($selector === '') continue;
            if (preg_match('#(?:^|[\s>+\x7e])(?:html|body|:root)(?=$|[\s.\x23:\[>+\x7e])|\.profile-sidebar-actions\b|\.profile-icon-button\b|\.avatar-crop-dialog\b#i', $selector)) continue;
            $safeSelectors[] = $scope . ' ' . $selector;
        }
        if ($safeSelectors) $safeRules[] = implode(', ', $safeSelectors) . " {\n    " . $declarations . "\n}";
    }

    return implode("\n", $safeRules);
}

function validateProfileTemplate(string $templateHtml): array
{
    $warnings = [];
    $knownPlaceholders = ['profile_header', 'bio', 'top_eight', 'posts'];
    preg_match_all('/{{\s*([^{}]+?)\s*}}/', $templateHtml, $placeholderMatches);
    $placeholders = array_count_values($placeholderMatches[1] ?? []);

    foreach ($placeholderMatches[0] ?? [] as $index => $placeholderMarkup) {
        $placeholder = $placeholderMatches[1][$index];
        if (in_array($placeholder, $knownPlaceholders, true) && $placeholderMarkup !== '{{' . $placeholder . '}}') {
            $warnings[] = $placeholderMarkup . ' must not contain spaces and will be removed.';
        }
    }

    foreach ($placeholders as $placeholder => $count) {
        if (!in_array($placeholder, $knownPlaceholders, true)) {
            $warnings[] = 'Unknown placeholder {{' . $placeholder . '}} will remain as text.';
        } elseif ($count > 1) {
            $warnings[] = '{{' . $placeholder . '}} is used more than once and may duplicate controls or content.';
        }
    }
    foreach ($knownPlaceholders as $placeholder) {
        if (!isset($placeholders[$placeholder])) $warnings[] = 'Missing {{' . $placeholder . '}}; that profile module will not appear.';
    }

    if (preg_match('~<(?:base|button|embed|form|iframe|input|link|meta|object|option|script|select|style|textarea)\b~i', $templateHtml)) {
        $warnings[] = 'Interactive, script, style, or embedded elements are removed from custom HTML.';
    }
    if (preg_match('~\s(?:on[a-z]+|style|srcdoc|formaction)\s*=~i', $templateHtml)) {
        $warnings[] = 'Inline styles and event attributes are removed from custom HTML.';
    }

    foreach (['div', 'aside', 'section', 'article', 'header', 'footer', 'nav', 'main', 'span'] as $tag) {
        preg_match_all('~<' . $tag . '\b(?![^>]*?/\s*>)[^>]*>~i', $templateHtml, $openingTags);
        preg_match_all('~</' . $tag . '\s*>~i', $templateHtml, $closingTags);
        if (count($openingTags[0]) !== count($closingTags[0])) $warnings[] = 'The <' . $tag . '> tags appear to be unbalanced.';
    }

    return array_values(array_unique($warnings));
}

function validateProfileCss(string $customCss): array
{
    if (trim($customCss) === '') return [];
    $warnings = [];
    $css = preg_replace('~/\*.*?\*/~s', '', $customCss) ?? '';

    if (substr_count($css, '{') !== substr_count($css, '}')) $warnings[] = 'CSS braces are unbalanced.';
    if (preg_match('/@/', $css)) $warnings[] = '@ rules are not supported yet and will be ignored.';
    if (preg_match('~url\s*\(~i', $css)) $warnings[] = 'External images, fonts, and other url() resources are blocked.';
    if (preg_match('~position\s*:\s*fixed\b~i', $css)) $warnings[] = 'Fixed positioning is blocked to keep customization inside the profile.';
    if (preg_match('~!important\b~i', $css)) $warnings[] = '!important is removed from custom CSS.';
    if (preg_match('~(?:expression\s*\(|javascript\s*:|vbscript\s*:|</?style|behavior\s*:|-moz-binding\s*:)~i', $css)) {
        $warnings[] = 'Unsafe CSS syntax is blocked.';
    }
    if (preg_match('#(?:^|[,{]\s*)(?:html|body|:root)(?=$|[\s.\x23:\[>+\x7e,{])#im', $css)) {
        $warnings[] = 'Global html, body, and :root selectors are ignored.';
    }
    if (preg_match('~\.(?:profile-sidebar-actions|profile-icon-button|avatar-crop-dialog)\b~i', $css)) {
        $warnings[] = 'Protected profile controls cannot be customized.';
    }
    if (sanitizeAndScopeProfileCss($customCss) === '' && !$warnings) $warnings[] = 'CSS could not be parsed and will not appear in the preview.';

    return array_values(array_unique($warnings));
}
