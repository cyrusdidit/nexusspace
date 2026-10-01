document.addEventListener('DOMContentLoaded', () => {
    const copyButton = document.querySelector('[data-copy-username]');
    const status = document.querySelector('[data-copy-username-status]');
    if (!copyButton) return;

    const copyText = async (value) => {
        if (navigator.clipboard?.writeText) {
            try {
                await navigator.clipboard.writeText(value);
                return;
            } catch {
                // Use the selection fallback when clipboard access is unavailable.
            }
        }
        const field = document.createElement('textarea');
        field.value = value;
        field.setAttribute('readonly', '');
        field.style.position = 'fixed';
        field.style.opacity = '0';
        document.body.append(field);
        field.select();
        const copied = document.execCommand('copy');
        field.remove();
        if (!copied) throw new Error('Copy failed');
    };

    let resetTimer;
    copyButton.addEventListener('click', async () => {
        const username = copyButton.dataset.copyUsername || '';
        if (!username) return;
        copyButton.disabled = true;
        try {
            await copyText(username);
            copyButton.classList.add('is-copied');
            copyButton.title = 'Copied';
            if (status) status.textContent = `${username} copied.`;
            window.clearTimeout(resetTimer);
            resetTimer = window.setTimeout(() => {
                copyButton.classList.remove('is-copied');
                copyButton.title = 'Copy username';
                copyButton.disabled = false;
            }, 1400);
        } catch {
            copyButton.title = 'Could not copy username';
            if (status) status.textContent = 'Could not copy the username.';
            copyButton.disabled = false;
        }
    });
});
