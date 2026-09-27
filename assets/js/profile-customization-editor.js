document.addEventListener('DOMContentLoaded', () => {
    const reset = document.querySelector('[data-customization-reset]');
    const templateField = document.querySelector('#profile-template-html');
    const cssField = document.querySelector('#profile-custom-css');
    const preview = document.querySelector('[data-customization-preview]');
    const htmlWarnings = document.querySelector('[data-html-warnings]');
    const cssWarnings = document.querySelector('[data-css-warnings]');
    let previewTimer;
    let previewRequest;

    reset?.addEventListener('click', (event) => {
        if (!window.confirm('Restore the default profile template and remove saved CSS?')) event.preventDefault();
    });

    if (!templateField || !cssField || !preview || !htmlWarnings || !cssWarnings) return;

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
        window.clearTimeout(previewTimer);
        previewTimer = window.setTimeout(refreshPreview, 250);
    };

    templateField?.addEventListener('input', schedulePreview);
    cssField?.addEventListener('input', schedulePreview);
    refreshPreview();
});
