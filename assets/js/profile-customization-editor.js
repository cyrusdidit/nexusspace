document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-customization-form]');
    const reset = document.querySelector('[data-customization-reset]');
    const templateField = document.querySelector('#profile-template-html');
    const cssField = document.querySelector('#profile-custom-css');
    const preview = document.querySelector('[data-customization-preview]');
    const htmlWarnings = document.querySelector('[data-html-warnings]');
    const cssWarnings = document.querySelector('[data-css-warnings]');
    const htmlHighlight = document.querySelector('[data-html-highlight]');
    const cssHighlight = document.querySelector('[data-css-highlight]');
    const settingsField = document.querySelector('[data-simple-settings]');
    const sidebarWidthField = document.querySelector('[data-sidebar-width-value]');
    const sidebarWidth = document.querySelector('[data-sidebar-width]');
    const sidebarWidthOutput = document.querySelector('[data-sidebar-width-output]');
    const targetField = document.querySelector('[data-style-target]');
    const propertyFields = [...document.querySelectorAll('[data-style-property]')];
    const draftStatus = document.querySelector('[data-draft-status]');
    let previewTimer;
    let previewRequest;
    let draftTimer;
    let draftRequest;
    let settings = {};

    try { settings = JSON.parse(settingsField?.value || '{}'); } catch { settings = {}; }

    reset?.addEventListener('click', (event) => {
        if (!window.confirm('Restore the default profile template and remove saved styles?')) event.preventDefault();
    });
    if (!form || !templateField || !cssField || !preview || !htmlWarnings || !cssWarnings || !htmlHighlight || !cssHighlight || !settingsField || !targetField) return;

    const regexRanges = (text, pattern) => {
        const ranges = [];
        for (const match of text.matchAll(pattern)) ranges.push([match.index, match.index + match[0].length]);
        return ranges;
    };
    const renderHighlight = (field, layer, ranges) => {
        const merged = ranges.filter(([start, end]) => start >= 0 && end > start).sort((a, b) => a[0] - b[0]).reduce((result, range) => {
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
        for (const match of text.matchAll(/{{\s*[^{}]+?\s*}}/g)) if (!knownPlaceholders.has(match[0])) ranges.push([match.index, match.index + match[0].length]);
        ranges.push(...regexRanges(text, /<\/?(?:base|button|embed|form|iframe|input|link|meta|object|option|script|select|style|textarea)\b[^>]*>/gi));
        ranges.push(...regexRanges(text, /\s(?:on[a-z]+|style|srcdoc|formaction)\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+)/gi));
        ranges.push(...regexRanges(text, /{{[^{}]*$/gm));
        return ranges;
    };
    const cssInvalidRanges = (text) => {
        const ranges = [
            ...regexRanges(text, /@(?!keyframes\b)[^\n{;]*/gi),
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
        if (!warnings.length) return;
        const list = document.createElement('ul');
        warnings.forEach((warning) => {
            const item = document.createElement('li');
            item.textContent = warning;
            list.append(item);
        });
        container.append(list);
    };

    const syncSettings = () => { settingsField.value = JSON.stringify(settings); };
    propertyFields.forEach((field) => { field.dataset.defaultValue = field.value; });
    const renderPropertyFields = () => {
        const values = settings[targetField.value] || {};
        propertyFields.forEach((field) => {
            const property = field.dataset.styleProperty;
            const value = values[property];
            if (field.type === 'color' || field.type === 'range') field.value = value ?? field.dataset.defaultValue;
            else field.value = value ?? (property === 'font_family' ? 'system' : property === 'animation' ? 'none' : '');
            field.closest('label')?.classList.toggle('is-customized', value !== undefined);
            const output = document.querySelector(`[data-property-output="${property}"]`);
            if (output) output.textContent = value === undefined ? 'Default' : `${value}%`;
        });
    };
    const setProperty = (property, value) => {
        const target = targetField.value;
        settings[target] ||= {};
        if (value === '' || value === 'system' || value === 'none') delete settings[target][property];
        else settings[target][property] = value;
        if (!Object.keys(settings[target]).length) delete settings[target];
        syncSettings();
    };

    const refreshPreview = async () => {
        previewRequest?.abort();
        previewRequest = new AbortController();
        const body = new FormData();
        body.set('template_html', templateField.value);
        body.set('custom_css', cssField.value);
        body.set('simple_settings', settingsField.value);
        body.set('sidebar_width', sidebarWidthField?.value || '280');
        try {
            const response = await fetch('profile-customization-preview.php', { method: 'POST', body, credentials: 'same-origin', signal: previewRequest.signal });
            if (!response.ok) return;
            const result = await response.json();
            if (!/^[a-f0-9]{24}$/.test(result.draft || '')) return;
            renderWarnings(htmlWarnings, Array.isArray(result.warnings?.html) ? result.warnings.html : []);
            renderWarnings(cssWarnings, Array.isArray(result.warnings?.css) ? result.warnings.css : []);
            preview.src = `profile-customization-preview.php?draft=${encodeURIComponent(result.draft)}`;
        } catch (error) {
            if (error.name !== 'AbortError') console.error('Could not update the profile preview.', error);
        }
    };
    const saveDraft = async () => {
        draftRequest?.abort();
        draftRequest = new AbortController();
        const body = new FormData(form);
        body.set('action', 'draft');
        draftStatus.textContent = 'Saving draft...';
        try {
            const response = await fetch(form.action, { method: 'POST', body, credentials: 'same-origin', signal: draftRequest.signal, headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('Draft could not be saved.');
            draftStatus.textContent = 'Draft saved';
        } catch (error) {
            if (error.name !== 'AbortError') draftStatus.textContent = 'Draft not saved';
        }
    };
    const scheduleChanges = () => {
        updateHighlights();
        window.clearTimeout(previewTimer);
        window.clearTimeout(draftTimer);
        previewTimer = window.setTimeout(refreshPreview, 180);
        draftTimer = window.setTimeout(saveDraft, 700);
        draftStatus.textContent = 'Unsaved draft';
    };

    propertyFields.forEach((field) => field.addEventListener('input', () => {
        const value = field.type === 'number' || field.type === 'range' ? (field.value === '' ? '' : Number(field.value)) : field.value;
        setProperty(field.dataset.styleProperty, value);
        renderPropertyFields();
        scheduleChanges();
    }));
    document.querySelectorAll('[data-clear-property]').forEach((button) => button.addEventListener('click', () => {
        setProperty(button.dataset.clearProperty, '');
        renderPropertyFields();
        scheduleChanges();
    }));
    document.querySelector('[data-reset-target]')?.addEventListener('click', () => {
        delete settings[targetField.value];
        syncSettings();
        renderPropertyFields();
        scheduleChanges();
    });
    targetField.addEventListener('change', renderPropertyFields);
    sidebarWidth?.addEventListener('input', () => {
        sidebarWidthField.value = sidebarWidth.value;
        sidebarWidthOutput.textContent = `${sidebarWidth.value}px`;
        scheduleChanges();
    });
    document.querySelectorAll('[data-editor-mode]').forEach((button) => button.addEventListener('click', () => {
        const advanced = button.dataset.editorMode === 'advanced';
        document.querySelector('[data-simple-editor]').hidden = advanced;
        document.querySelector('[data-advanced-editor]').hidden = !advanced;
        document.querySelectorAll('[data-editor-mode]').forEach((mode) => mode.setAttribute('aria-selected', String(mode === button)));
        if (advanced) updateHighlights();
    }));
    templateField.addEventListener('input', scheduleChanges);
    cssField.addEventListener('input', scheduleChanges);
    templateField.addEventListener('scroll', () => { htmlHighlight.scrollTop = templateField.scrollTop; htmlHighlight.scrollLeft = templateField.scrollLeft; });
    cssField.addEventListener('scroll', () => { cssHighlight.scrollTop = cssField.scrollTop; cssHighlight.scrollLeft = cssField.scrollLeft; });
    form.addEventListener('submit', syncSettings);
    renderPropertyFields();
    updateHighlights();
    refreshPreview();
});
