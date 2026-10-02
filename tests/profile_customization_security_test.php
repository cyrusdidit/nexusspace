<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/profile_customization.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$unsafeTemplate = <<<'HTML'
<div id="collision" data-post-delete data-profile-style="post.card" onclick="alert(1)" style="position:fixed">
    <script>alert(1)</script>
    <a href="javascript:alert(1)" data-profile-appearance-toggle>Bad link</a>
    {{profile_header}}{{profile_header}}{{bio}}{{top_eight}}{{posts}}
</div>
HTML;
$safeTemplate = sanitizeProfileTemplate($unsafeTemplate);
$assert(!str_contains(strtolower($safeTemplate), '<script'), 'Script elements must be removed.');
$assert(!preg_match('/\s(?:id|data-[a-z0-9_-]+|onclick|style)\s*=/i', $safeTemplate), 'IDs, data hooks, event handlers, and inline styles must be removed.');
$assert(!str_contains(strtolower($safeTemplate), 'javascript:'), 'JavaScript links must be removed.');
$assert(substr_count($safeTemplate, '{{profile_header}}') === 1, 'Functional placeholders must not be duplicated.');
$assert(str_contains(decorateProfileTemplate('<div class="profile-layout"><aside class="profile-sidebar"></aside></div>'), 'data-profile-style="layout.root"'), 'Approved layout targets must be restored by the application.');

$validAnimation = <<<'CSS'
@keyframes sparkle {
    from { opacity: 0; transform: scale(.95); }
    50% { opacity: 1; }
    to { opacity: .5; }
}
.profile-identity h1 { animation: sparkle 2s infinite; color: #123456; }
CSS;
$safeCss = sanitizeAndScopeProfileCss($validAnimation);
$assert(str_contains($safeCss, '@keyframes nexus-custom-'), 'Custom keyframes must receive an isolated name.');
$assert((bool) preg_match('/animation:\s*nexus-custom-[a-f0-9]{10}-sparkle/', $safeCss), 'Animation references must use the isolated keyframe name.');
$assert(str_contains($safeCss, '.profile-custom-content .profile-identity h1'), 'Selectors must be scoped to profile content.');
$assert(sanitizeAndScopeProfileCss('@import "https://example.com/x.css"; .x { color: red; }') === '', '@import must be blocked.');
$assert(sanitizeAndScopeProfileCss('.x { position: fixed; inset: 0; }') === '', 'Fixed positioning must be blocked.');
$assert(sanitizeAndScopeProfileCss('@keyframes bad { sideways { opacity: 0; } } .x { animation: bad 1s; }') === '', 'Invalid keyframe selectors must be blocked.');
$assert(!str_contains(sanitizeAndScopeProfileCss('body { color: red; } .safe { color: blue; }'), '.profile-custom-content body'), 'Global body selectors must be ignored.');

$settings = normalizeProfileSimpleSettings([
    'post.title' => ['font_size' => 999, 'text_color' => '#ABCDEF', 'animation' => 'wiggle'],
    'unknown.target' => ['font_size' => 20],
]);
$assert(($settings['post.title']['font_size'] ?? null) === 72, 'Simple numeric settings must be clamped.');
$assert(($settings['post.title']['text_color'] ?? null) === '#abcdef', 'Simple colors must be normalized.');
$assert(!isset($settings['unknown.target']), 'Unknown style targets must be discarded.');
$assert(str_contains(renderProfileSimpleCss($settings, 999), '--profile-sidebar-width: 520px'), 'Sidebar width must be clamped in rendered CSS.');

if ($failures) {
    fwrite(STDERR, "Profile customization security tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Profile customization security tests passed (" . 16 . " assertions).\n";
