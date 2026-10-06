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

function profileCustomizationTargetGroups(): array
{
    return [
        'Page and layout' => ['profile.page', 'profile.sheet', 'profile.copyright', 'layout.root', 'layout.sidebar', 'layout.sidebar-handle', 'layout.content'],
        'Cover and avatar' => ['cover.container', 'cover.surface', 'cover.image', 'cover.edit-control', 'avatar.container', 'avatar.image', 'avatar.activity', 'avatar.edit-overlay'],
        'Identity and status' => ['identity.container', 'identity.display-name', 'identity.handle-row', 'identity.handle', 'identity.copy-control', 'status.container', 'status.text', 'status.editor', 'status.edit-control', 'status.error'],
        'Current activity' => ['activity.container', 'activity.spotify-row', 'activity.spotify-link', 'activity.steam-row', 'activity.art', 'activity.copy', 'activity.title', 'activity.detail', 'activity.spotify-add-control', 'activity.steam-duration', 'activity.feedback'],
        'Social and bio' => ['social.actions', 'social.message-control', 'social.primary-control', 'social.secondary-control', 'bio.container', 'bio.text', 'bio.editor', 'bio.edit-control', 'bio.counter', 'bio.save-control'],
        'Top 8' => ['top-eight.container', 'top-eight.header', 'top-eight.heading', 'top-eight.reorder-control', 'top-eight.list', 'top-eight.item', 'top-eight.avatar', 'top-eight.name', 'top-eight.remove-control', 'top-eight.picker', 'top-eight.picker-header', 'top-eight.search-control', 'top-eight.search-field', 'top-eight.pool-item', 'top-eight.add-control', 'top-eight.save-controls'],
        'Sidebar navigation' => ['sidebar-nav.container', 'sidebar-nav.settings-control', 'sidebar-nav.dashboard-control'],
        'Wallpaper and profile song' => ['content.wallpaper', 'content.wallpaper-image', 'content.wallpaper-edit-control', 'profile-song.container', 'profile-song.label', 'profile-song.title', 'profile-song.artist', 'profile-song.volume-control', 'profile-song.volume-slider', 'profile-song.edit-control', 'profile-song.close-control', 'profile-song.picker', 'profile-song.picker-header', 'profile-song.picker-heading', 'profile-song.picker-actions', 'profile-song.picker-close-control', 'profile-song.remove-control', 'profile-song.search-form', 'profile-song.search-label', 'profile-song.search-field', 'profile-song.search-control', 'profile-song.search-status', 'profile-song.search-results', 'profile-song.result-item', 'profile-song.result-image', 'profile-song.result-copy', 'profile-song.result-title', 'profile-song.result-artist', 'profile-song.result-select-control'],
        'Posts' => ['posts.heading', 'posts.empty-state', 'posts.list', 'posts.load-more-control', 'post.card', 'post.header', 'post.author', 'post.avatar', 'post.metadata', 'post.title', 'post.body', 'post.interactions', 'post.owner-actions', 'post.edit-control', 'post.delete-control', 'post.action-bar', 'post.heart-control', 'post.comment-control'],
        'Post media and editing' => ['post.media-grid', 'post.media-item', 'post.image', 'post.video', 'post.video-speed', 'post.media-remove-control', 'post.media-reorder-controls', 'post.media-move-control', 'post.media-replace-control', 'post.media-add-control', 'post.edit-form', 'post.edit-title-field', 'post.edit-body-field', 'post.edit-actions', 'post.save-control', 'post.cancel-control', 'post.upload-progress'],
        'Comments and replies' => ['comment.composer', 'comment.composer-field', 'comment.composer-submit', 'comment.status', 'comment.list', 'comment.card', 'comment.header', 'comment.avatar', 'comment.author', 'comment.timestamp', 'comment.pinned', 'comment.body', 'comment.actions', 'comment.reply-control', 'comment.edit-control', 'comment.delete-control', 'comment.pin-control', 'comment.purge-control', 'comment.edit-form', 'comment.edit-field', 'comment.reply-form', 'comment.reply-field', 'comment.save-control', 'comment.cancel-control', 'comment.reply-submit', 'comment.replies-thread'],
    ];
}

function profileCustomizationTargetKeys(): array
{
    return array_values(array_unique(array_merge(...array_values(profileCustomizationTargetGroups()))));
}

function profileCustomizationTargetLabel(string $target): string
{
    $label = str_replace(['-', '.'], [' ', ' / '], $target);
    return ucwords($label);
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
    if (!is_array($settings)) return defaultProfileSimpleSettings();

    $allowedTargets = array_fill_keys(profileCustomizationTargetKeys(), true);
    $allowedFonts = ['system', 'Arial', 'Georgia', 'Times New Roman', 'Trebuchet MS', 'Verdana', 'Courier New'];
    $allowedWeights = ['normal', '500', '600', '700', '800'];
    $allowedStyles = ['normal', 'italic'];
    $allowedAlignments = ['left', 'center', 'right'];
    $allowedBorderStyles = ['solid', 'dashed', 'dotted', 'double'];
    $allowedAnimations = ['none', 'pulse', 'float', 'wiggle'];
    $normalized = [];

    foreach ($settings as $target => $values) {
        if (!is_string($target) || !isset($allowedTargets[$target]) || !is_array($values)) continue;
        $targetValues = [];
        $enumProperties = [
            'font_family' => $allowedFonts,
            'font_weight' => $allowedWeights,
            'font_style' => $allowedStyles,
            'text_align' => $allowedAlignments,
            'border_style' => $allowedBorderStyles,
            'animation' => $allowedAnimations,
        ];
        foreach ($enumProperties as $property => $allowedValues) {
            $value = $values[$property] ?? null;
            if (is_string($value) && in_array($value, $allowedValues, true) && !in_array($value, ['system', 'none'], true)) $targetValues[$property] = $value;
        }
        foreach (['text_color', 'text_gradient_from', 'text_gradient_to', 'background_color', 'border_color'] as $property) {
            $value = $values[$property] ?? null;
            if (is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value)) $targetValues[$property] = strtolower($value);
        }
        $ranges = [
            'font_size' => [8, 72],
            'text_gradient_angle' => [0, 360],
            'border_width' => [0, 12],
            'border_radius' => [0, 48],
            'padding' => [0, 64],
            'margin' => [0, 64],
            'opacity' => [10, 100],
        ];
        foreach ($ranges as $property => [$minimum, $maximum]) {
            if (!isset($values[$property]) || $values[$property] === '') continue;
            $targetValues[$property] = min($maximum, max($minimum, (int) $values[$property]));
        }
        if ($targetValues) $normalized[$target] = $targetValues;
    }
    return $normalized;
}

function encodeProfileSimpleSettings(array $settings): string
{
    return json_encode((object) $settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function normalizeProfileSidebarWidth(mixed $width): int
{
    return min(PROFILE_SIDEBAR_MAX_WIDTH, max(PROFILE_SIDEBAR_MIN_WIDTH, (int) $width));
}

function renderProfileSimpleCss(mixed $settings, mixed $sidebarWidth = PROFILE_SIDEBAR_DEFAULT_WIDTH): string
{
    $settings = normalizeProfileSimpleSettings($settings);
    $fontFamilies = [
        'Arial' => 'Arial, sans-serif',
        'Georgia' => 'Georgia, serif',
        'Times New Roman' => '"Times New Roman", serif',
        'Trebuchet MS' => '"Trebuchet MS", sans-serif',
        'Verdana' => 'Verdana, sans-serif',
        'Courier New' => '"Courier New", monospace',
    ];
    $rules = [];
    foreach ($settings as $target => $values) {
        $declarations = [];
        if (isset($fontFamilies[$values['font_family'] ?? ''])) $declarations[] = 'font-family: ' . $fontFamilies[$values['font_family']];
        if (isset($values['font_size'])) $declarations[] = 'font-size: ' . $values['font_size'] . 'px';
        if (isset($values['font_weight'])) $declarations[] = 'font-weight: ' . $values['font_weight'];
        if (isset($values['font_style'])) $declarations[] = 'font-style: ' . $values['font_style'];
        if (isset($values['text_align'])) $declarations[] = 'text-align: ' . $values['text_align'];
        if (isset($values['text_color'])) $declarations[] = 'color: ' . $values['text_color'];
        if (isset($values['text_gradient_from'], $values['text_gradient_to'])) {
            $angle = $values['text_gradient_angle'] ?? 90;
            $declarations[] = 'background-image: linear-gradient(' . $angle . 'deg, ' . $values['text_gradient_from'] . ', ' . $values['text_gradient_to'] . ')';
            $declarations[] = 'background-clip: text';
            $declarations[] = '-webkit-background-clip: text';
            $declarations[] = 'color: transparent';
        }
        if (isset($values['background_color'])) $declarations[] = 'background-color: ' . $values['background_color'];
        if (isset($values['border_color'])) $declarations[] = 'border-color: ' . $values['border_color'];
        if (isset($values['border_width'])) $declarations[] = 'border-width: ' . $values['border_width'] . 'px';
        if (isset($values['border_style'])) $declarations[] = 'border-style: ' . $values['border_style'];
        if (isset($values['border_radius'])) $declarations[] = 'border-radius: ' . $values['border_radius'] . 'px';
        if (isset($values['padding'])) $declarations[] = 'padding: ' . $values['padding'] . 'px';
        if (isset($values['margin'])) $declarations[] = 'margin: ' . $values['margin'] . 'px';
        if (isset($values['opacity'])) $declarations[] = 'opacity: ' . ($values['opacity'] / 100);
        if (isset($values['animation'])) $declarations[] = 'animation: nexus-profile-' . $values['animation'] . ' 1.8s ease-in-out infinite';
        if ($declarations) {
            $selector = $target === 'profile.page'
                ? '.profile-page[data-profile-style="profile.page"]'
                : '.profile-page [data-profile-style="' . $target . '"]';
            $rules[] = $selector . " {\n    " . implode(";\n    ", $declarations) . ";\n}";
        }
    }
    $width = normalizeProfileSidebarWidth($sidebarWidth);
    $rules[] = '.profile-page [data-profile-style="layout.root"] { --profile-sidebar-width: ' . $width . 'px; }';
    return implode("\n", $rules);
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

function readProfileCustomizationRevision(mysqli $conn, int $userId, int $revisionNumber): ?array
{
    $statement = mysqli_prepare($conn, 'SELECT revision_number, schema_version, simple_settings, sidebar_width, template_html, custom_css, created_at FROM profile_customization_revisions WHERE user_id = ? AND revision_number = ? LIMIT 1');
    mysqli_stmt_bind_param($statement, 'ii', $userId, $revisionNumber);
    mysqli_stmt_execute($statement);
    $revision = mysqli_fetch_assoc(mysqli_stmt_get_result($statement)) ?: null;
    mysqli_stmt_close($statement);
    if (!$revision) return null;
    $revision['revision_number'] = (int) $revision['revision_number'];
    $revision['schema_version'] = (int) $revision['schema_version'];
    $revision['simple_settings'] = normalizeProfileSimpleSettings($revision['simple_settings']);
    $revision['sidebar_width'] = normalizeProfileSidebarWidth($revision['sidebar_width']);
    return $revision;
}

function restoreProfileCustomizationRevision(mysqli $conn, int $userId, int $revisionNumber): int
{
    $revision = readProfileCustomizationRevision($conn, $userId, $revisionNumber);
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
    $seenPlaceholders = [];
    $templateHtml = preg_replace_callback(
        '/{{\s*[^{}]+?\s*}}/',
        static function (array $match) use ($allowedPlaceholders, &$seenPlaceholders): string {
            if (!in_array($match[0], $allowedPlaceholders, true) || isset($seenPlaceholders[$match[0]])) return '';
            $seenPlaceholders[$match[0]] = true;
            return $match[0];
        },
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
            if ($lowerName === 'id' && $value === 'profile-template-root') continue;
            if (str_starts_with($lowerName, 'on') || str_starts_with($lowerName, 'data-') || in_array($lowerName, ['id', 'formaction', 'srcdoc', 'style'], true) || str_contains($value, '{{')) {
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

function extractSafeProfileKeyframes(string $css, array &$nameMap): ?array
{
    $remaining = '';
    $keyframes = [];
    $cursor = 0;
    $prefix = 'nexus-custom-' . substr(hash('sha256', $css), 0, 10) . '-';

    while (preg_match('/@keyframes\s+([a-z_][a-z0-9_-]*)\s*\{/i', $css, $match, PREG_OFFSET_CAPTURE, $cursor)) {
        $start = $match[0][1];
        $openingBrace = $start + strlen($match[0][0]) - 1;
        $remaining .= substr($css, $cursor, $start - $cursor);
        $depth = 1;
        $end = $openingBrace + 1;
        $length = strlen($css);
        while ($end < $length && $depth > 0) {
            if ($css[$end] === '{') $depth++;
            elseif ($css[$end] === '}') $depth--;
            $end++;
        }
        if ($depth !== 0) return null;

        $body = substr($css, $openingBrace + 1, $end - $openingBrace - 2);
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $body, $steps, PREG_SET_ORDER);
        $unparsed = preg_replace('/[^{}]+\{[^{}]*\}/s', '', $body) ?? '';
        if (!$steps || trim($unparsed) !== '') return null;

        $safeSteps = [];
        foreach ($steps as $step) {
            $selectors = array_map('trim', explode(',', $step[1]));
            foreach ($selectors as $selector) {
                if (!preg_match('/^(?:from|to|(?:100|\d{1,2})(?:\.\d+)?%)$/i', $selector)) return null;
            }
            $declarations = trim(preg_replace('/\s*!important\b/i', '', $step[2]) ?? '');
            if ($declarations === '' || preg_match('~(?:@|url\s*\(|expression\s*\(|javascript\s*:|vbscript\s*:|</?style|behavior\s*:|-moz-binding\s*:|position\s*:\s*fixed\b)~i', $declarations)) return null;
            $safeSteps[] = implode(', ', $selectors) . " {\n        " . $declarations . "\n    }";
        }

        $originalName = $match[1][0];
        $safeName = $prefix . strtolower($originalName);
        $nameMap[$originalName] = $safeName;
        $keyframes[] = '@keyframes ' . $safeName . " {\n    " . implode("\n    ", $safeSteps) . "\n}";
        $cursor = $end;
    }
    $remaining .= substr($css, $cursor);
    if (str_contains($remaining, '@')) return null;
    return [$remaining, $keyframes];
}

function sanitizeAndScopeProfileCss(string $customCss, string $scope = '.profile-custom-content'): string
{
    $css = preg_replace('~/\*.*?\*/~s', '', $customCss) ?? '';
    if (trim($css) === '') return '';

    $blockedSyntax = '~(?:url\s*\(|expression\s*\(|javascript\s*:|vbscript\s*:|</?style|behavior\s*:|-moz-binding\s*:|position\s*:\s*fixed\b)~i';
    if (preg_match($blockedSyntax, $css) || substr_count($css, '{') !== substr_count($css, '}')) return '';

    $nameMap = [];
    $extracted = extractSafeProfileKeyframes($css, $nameMap);
    if ($extracted === null) return '';
    [$css, $safeKeyframes] = $extracted;

    preg_match_all('~([^{}]+)\{([^{}]*)\}~s', $css, $matches, PREG_SET_ORDER);
    $unparsed = preg_replace('~[^{}]+\{[^{}]*\}~s', '', $css) ?? '';
    if (trim($unparsed) !== '') return '';

    $safeRules = [];
    foreach ($matches as $match) {
        $declarations = trim(preg_replace('~\s*!important\b~i', '', $match[2]) ?? '');
        if ($declarations === '') continue;
        foreach ($nameMap as $originalName => $safeName) {
            $declarations = preg_replace('/\b' . preg_quote($originalName, '/') . '\b/', $safeName, $declarations) ?? $declarations;
        }

        $safeSelectors = [];
        foreach (explode(',', $match[1]) as $selector) {
            $selector = trim($selector);
            if ($selector === '') continue;
            $selector = preg_replace('/^\.profile-page\s+/', '', $selector) ?? $selector;
            if (in_array($selector, ['[data-profile-style="profile.page"]', '[data-profile-style="profile.sheet"]', $scope], true)) {
                $safeSelectors[] = $scope;
                continue;
            }
            if (preg_match('#(?:^|[\s>+\x7e])(?:html|body|:root)(?=$|[\s.\x23:\[>+\x7e])|\.profile-sidebar-actions\b|\.profile-icon-button\b|\.avatar-crop-dialog\b#i', $selector)) continue;
            $safeSelectors[] = $scope . ' ' . $selector;
        }
        if ($safeSelectors) $safeRules[] = implode(', ', $safeSelectors) . " {\n    " . $declarations . "\n}";
    }

    return implode("\n", array_merge($safeKeyframes, $safeRules));
}

function removeProfileWallpaperCssOverrides(string $customCss): string
{
    $wallpaperTargets = ['layout.content', 'content.wallpaper', 'content.wallpaper-image'];
    $wallpaperProperties = [
        'background', 'display', 'visibility', 'opacity', 'filter', 'box-shadow',
        'content', 'isolation', 'overflow', 'animation', 'position', 'z-index',
        'inset', 'top', 'right', 'bottom', 'left', 'width', 'height',
        'min-width', 'min-height', 'max-width', 'max-height', 'pointer-events',
    ];

    $isWallpaperSelector = static function (string $selector) use ($wallpaperTargets): bool {
        $selector = trim($selector);
        if (str_starts_with($selector, '.profile-custom-content') && !preg_match('/\s/', $selector)) return true;
        foreach ($wallpaperTargets as $target) {
            if (str_contains($selector, '[data-profile-style="' . $target . '"]')) return true;
        }
        return false;
    };
    $cleanDeclarations = static function (string $declarations) use ($wallpaperProperties): string {
        $kept = [];
        foreach (explode(';', $declarations) as $declaration) {
            $declaration = trim($declaration);
            if ($declaration === '' || !str_contains($declaration, ':')) continue;
            [$property] = explode(':', $declaration, 2);
            $property = strtolower(trim($property));
            $blocked = in_array($property, $wallpaperProperties, true)
                || str_starts_with($property, 'background-')
                || str_starts_with($property, 'animation-');
            if (!$blocked) $kept[] = $declaration;
        }
        return implode(";\n    ", $kept);
    };

    $cleaned = preg_replace_callback('/([^{}]+)\{([^{}]*)\}/s', static function (array $match) use ($isWallpaperSelector, $cleanDeclarations): string {
        $selectors = array_values(array_filter(array_map('trim', explode(',', $match[1]))));
        $wallpaperSelectors = array_values(array_filter($selectors, $isWallpaperSelector));
        if (!$wallpaperSelectors) return $match[0];

        $otherSelectors = array_values(array_diff($selectors, $wallpaperSelectors));
        $rules = [];
        if ($otherSelectors) $rules[] = implode(', ', $otherSelectors) . ' {' . $match[2] . '}';

        $wallpaperSelectors = array_values(array_filter($wallpaperSelectors, static fn(string $selector): bool => !preg_match('/::(?:before|after)\b/i', $selector)));
        $remainingDeclarations = $cleanDeclarations($match[2]);
        if ($wallpaperSelectors && $remainingDeclarations !== '') {
            $rules[] = implode(', ', $wallpaperSelectors) . " {\n    " . $remainingDeclarations . ";\n}";
        }
        return implode("\n", $rules);
    }, $customCss);

    return trim($cleaned ?? $customCss);
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
    if (preg_match('~\s(?:id|data-[a-z0-9_-]+)\s*=~i', $templateHtml)) {
        $warnings[] = 'Custom IDs and data attributes are removed to protect profile controls.';
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
    if (preg_match('/@(?!keyframes\b)/i', $css)) $warnings[] = 'Only @keyframes animation rules are supported.';
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
