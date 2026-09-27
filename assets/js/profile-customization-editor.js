document.addEventListener('DOMContentLoaded', () => {
    const reset = document.querySelector('[data-customization-reset]');
    const templateField = document.querySelector('#profile-template-html');
    const cssField = document.querySelector('#profile-custom-css');
    const preview = document.querySelector('[data-customization-preview]');
    const htmlWarnings = document.querySelector('[data-html-warnings]');
    const cssWarnings = document.querySelector('[data-css-warnings]');
    const htmlHighlight = document.querySelector('[data-html-highlight]');
    const cssHighlight = document.querySelector('[data-css-highlight]');
    let previewTimer;
    let previewRequest;

    reset?.addEventListener('click', (event) => {
        if (!window.confirm('Restore the default profile template and remove saved CSS?')) event.preventDefault();
    });

    if (!templateField || !cssField || !preview || !htmlWarnings || !cssWarnings || !htmlHighlight || !cssHighlight) return;

    const regexRanges = (text, pattern) => {
        const ranges = [];
        for (const match of text.matchAll(pattern)) ranges.push([match.index, match.index + match[0].length]);
        return ranges;
    };

    const renderHighlight = (field, layer, ranges) => {
        const merged = ranges
            .filter(([start, end]) => start >= 0 && end > start)
            .sort((a, b) => a[0] - b[0])
            .reduce((result, range) => {
                const previous = result.at(-1);
                if (previous && range[0] <= previous[1]) previous[1] = Math.max(previous[1], range[1]);
                else result.push([...range]);
                return result;
            }, []);
        const fragment = document.createDocumentFragment();
        let cursor = 0;
        merged.forEach(([start, end]) => {
            fragment.append(document.createTextNode(field.value.slice(cursor, start)));
            const mark = document.createElement('mark');
            mark.textContent = field.value.slice(start, end);
            fragment.append(mark);
            cursor = end;
        });
        fragment.append(document.createTextNode(field.value.slice(cursor) + '\n'));
        layer.replaceChildren(fragment);
        layer.scrollTop = field.scrollTop;
        layer.scrollLeft = field.scrollLeft;
    };

    const htmlInvalidRanges = (text) => {
        const ranges = [];
        const knownPlaceholders = new Set(['{{profile_header}}', '{{bio}}', '{{top_eight}}', '{{posts}}']);
        for (const match of text.matchAll(/{{\s*[^{}]+?\s*}}/g)) {
            if (!knownPlaceholders.has(match[0])) ranges.push([match.index, match.index + match[0].length]);
        }
        ranges.push(...regexRanges(text, /<\/?(?:base|button|embed|form|iframe|input|link|meta|object|option|script|select|style|textarea)\b[^>]*>/gi));
        ranges.push(...regexRanges(text, /\s(?:on[a-z]+|style|srcdoc|formaction)\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+)/gi));
        ranges.push(...regexRanges(text, /{{[^{}]*$/gm));

        ['div', 'aside', 'section', 'article', 'header', 'footer', 'nav', 'main', 'span'].forEach((tag) => {
            const tags = regexRanges(text, new RegExp(`<\\/?${tag}\\b[^>]*>`, 'gi'));
            const openingCount = (text.match(new RegExp(`<${tag}\\b(?![^>]*?\\/\\s*>)[^>]*>`, 'gi')) || []).length;
            const closingCount = (text.match(new RegExp(`</${tag}\\s*>`, 'gi')) || []).length;
            if (openingCount !== closingCount) ranges.push(...tags);
        });
        return ranges;
    };

    const cssInvalidRanges = (text) => {
        const ranges = [
            ...regexRanges(text, /@[^\n{;]*/g),
            ...regexRanges(text, /url\s*\([^)]*\)/gi),
            ...regexRanges(text, /position\s*:\s*fixed\b/gi),
            ...regexRanges(text, /!important\b/gi),
            ...regexRanges(text, /(?:expression\s*\([^)]*\)|javascript\s*:|vbscript\s*:|<\/?style|behavior\s*:|-moz-binding\s*:)/gi),
            ...regexRanges(text, /\b(?:html|body)\b|:root\b/gi),
            ...regexRanges(text, /\.(?:profile-sidebar-actions|profile-icon-button|avatar-crop-dialog)\b/gi),
        ];
        if ((text.match(/{/g) || []).length !== (text.match(/}/g) || []).length) ranges.push(...regexRanges(text, /[{}]/g));
        return ranges;
    };

    const updateHighlights = () => {
        renderHighlight(templateField, htmlHighlight, htmlInvalidRanges(templateField.value));
        renderHighlight(cssField, cssHighlight, cssInvalidRanges(cssField.value));
    };

    const renderWarnings = (container, warnings) => {
        container.replaceChildren();
        container.hidden = warnings.length === 0;
        if (warnings.length === 0) return;

        const list = document.createElement('ul');
        warnings.forEach((warning) => {
            const item = document.createElement('li');
            item.textContent = warning;
            list.append(item);
        });
        container.append(list);
    };

    const refreshPreview = async () => {
        previewRequest?.abort();
        previewRequest = new AbortController();
        const body = new FormData();
        body.set('template_html', templateField.value);
        body.set('custom_css', cssField.value);

        try {
            const response = await fetch('profile-customization-preview.php', {
                method: 'POST',
                body,
                credentials: 'same-origin',
                signal: previewRequest.signal,
            });
            if (!response.ok) return;
            const result = await response.json();
            const draftToken = typeof result.draft === 'string' ? result.draft : '';
            if (!/^[a-f0-9]{24}$/.test(draftToken)) return;
            renderWarnings(htmlWarnings, Array.isArray(result.warnings?.html) ? result.warnings.html : []);
            renderWarnings(cssWarnings, Array.isArray(result.warnings?.css) ? result.warnings.css : []);
            preview.src = `profile-customization-preview.php?draft=${encodeURIComponent(draftToken)}`;
        } catch (error) {
            if (error.name !== 'AbortError') console.error('Could not update the profile preview.', error);
        }
    };

    const schedulePreview = () => {
        updateHighlights();
        window.clearTimeout(previewTimer);
        previewTimer = window.setTimeout(refreshPreview, 250);
    };

    templateField?.addEventListener('input', schedulePreview);
    cssField?.addEventListener('input', schedulePreview);
    templateField.addEventListener('scroll', () => {
        htmlHighlight.scrollTop = templateField.scrollTop;
        htmlHighlight.scrollLeft = templateField.scrollLeft;
    });
    cssField.addEventListener('scroll', () => {
        cssHighlight.scrollTop = cssField.scrollTop;
        cssHighlight.scrollLeft = cssField.scrollLeft;
    });
    updateHighlights();
    refreshPreview();
});
