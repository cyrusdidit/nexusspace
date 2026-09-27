document.addEventListener('DOMContentLoaded', () => {
    const reset = document.querySelector('[data-customization-reset]');
    const templateField = document.querySelector('#profile-template-html');
    const cssField = document.querySelector('#profile-custom-css');
    const preview = document.querySelector('[data-customization-preview]');
    let previewTimer;
    let previewRequest;

    reset?.addEventListener('click', (event) => {
        if (!window.confirm('Restore the default profile template and remove saved CSS?')) event.preventDefault();
    });

    if (!templateField || !cssField || !preview) return;

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
            const draftToken = (await response.text()).trim();
            if (!/^[a-f0-9]{24}$/.test(draftToken)) return;
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
});
