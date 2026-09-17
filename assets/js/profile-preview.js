document.querySelectorAll('[data-profile-preview]').forEach((trigger) => {
    const panel = trigger.querySelector('[data-profile-preview-panel]');
    document.body.append(panel);
    let timer;
    let dismissed = false;
    const close = () => { panel.hidden = true; };
    const scheduleClose = () => {
        clearTimeout(timer);
        timer = setTimeout(close, 150);
    };
    const open = () => {
        clearTimeout(timer);
        if (dismissed) return;
        panel.hidden = false;
        const anchor = trigger.getBoundingClientRect();
        const width = panel.offsetWidth;
        const height = panel.offsetHeight;
        panel.style.left = `${Math.max(8, Math.min(anchor.left, window.innerWidth - width - 8))}px`;
        const below = anchor.bottom + 6;
        panel.style.top = `${Math.max(8, below + height <= window.innerHeight - 8 ? below : anchor.top - height - 6)}px`;
    };
    trigger.addEventListener('mouseenter', () => { dismissed = false; open(); });
    trigger.addEventListener('mouseleave', scheduleClose);
    trigger.addEventListener('focusin', () => { dismissed = false; open(); });
    trigger.addEventListener('focusout', scheduleClose);
    panel.addEventListener('mouseenter', () => clearTimeout(timer));
    panel.addEventListener('mouseleave', scheduleClose);
    panel.addEventListener('focusin', () => clearTimeout(timer));
    panel.addEventListener('focusout', scheduleClose);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') { dismissed = true; close(); }
    });
    window.addEventListener('resize', close);
    document.addEventListener('scroll', (event) => {
        if (!panel.contains(event.target)) close();
    }, true);
});
