<?php

declare(strict_types=1);

const DEFAULT_PROFILE_TEMPLATE = <<<'HTML'
<div class="profile-layout">
    <aside class="profile-sidebar">
        {{profile_header}}
        {{bio}}
        {{top_eight}}
    </aside>
    {{posts}}
</div>
HTML;

const DEFAULT_PROFILE_CSS = '';

function readProfileCustomization(mysqli $conn, int $userId): array
{
    $statement = mysqli_prepare($conn, 'SELECT template_html, custom_css, updated_at FROM profile_customizations WHERE user_id = ? LIMIT 1');
    mysqli_stmt_bind_param($statement, 'i', $userId);
    mysqli_stmt_execute($statement);
    $customization = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
    mysqli_stmt_close($statement);

    return $customization ?: [
        'template_html' => DEFAULT_PROFILE_TEMPLATE,
        'custom_css' => DEFAULT_PROFILE_CSS,
        'updated_at' => null,
    ];
}

function saveProfileCustomization(mysqli $conn, int $userId, string $templateHtml, string $customCss): void
{
    $statement = mysqli_prepare($conn, 'INSERT INTO profile_customizations (user_id, template_html, custom_css) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE template_html = VALUES(template_html), custom_css = VALUES(custom_css)');
    mysqli_stmt_bind_param($statement, 'iss', $userId, $templateHtml, $customCss);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);
}

function resetProfileCustomization(mysqli $conn, int $userId): void
{
    $statement = mysqli_prepare($conn, 'DELETE FROM profile_customizations WHERE user_id = ?');
    mysqli_stmt_bind_param($statement, 'i', $userId);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);
}

function sanitizeProfileTemplate(string $templateHtml): string
{
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
            if (str_starts_with($lowerName, 'on') || in_array($lowerName, ['formaction', 'srcdoc', 'style'], true) || str_contains($value, '{{')) {
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
