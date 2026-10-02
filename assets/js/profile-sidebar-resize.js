document.addEventListener('DOMContentLoaded', () => {
    const handle = document.querySelector('[data-profile-sidebar-resize]');
    const layout = document.querySelector('[data-profile-style="layout.root"]');
    const simpleForm = document.querySelector('[data-profile-customization-inline]');
    const widthField = simpleForm?.querySelector('[data-inline-sidebar-width-value]');
    const widthControl = simpleForm?.querySelector('[data-inline-sidebar-width]');
    const widthOutput = simpleForm?.querySelector('[data-inline-sidebar-width-output]');
    const advancedWidth = document.querySelector('[data-inline-advanced-width]');
    const status = simpleForm?.querySelector('[data-inline-customization-status]');
    if (!handle || !layout || !simpleForm || !widthField) return;

    const minimum = 220;
    const maximum = 520;
    let dragging = false;
    let startX = 0;
    let startWidth = Number(widthField.value) || 280;

    const positionHandle = () => {
        const sidebar = layout.querySelector('[data-profile-style="layout.sidebar"]');
        if (sidebar) handle.style.left = `${Math.round(sidebar.getBoundingClientRect().right)}px`;
    };

    const clamp = (width) => Math.round(Math.min(maximum, Math.max(minimum, width)));
    const setWidth = (width) => {
        width = clamp(width);
        layout.style.setProperty('--profile-sidebar-width', `${width}px`);
        widthField.value = String(width);
        if (widthControl) widthControl.value = String(width);
        if (widthOutput) widthOutput.textContent = `${width}px`;
        if (advancedWidth) advancedWidth.value = String(width);
        handle.setAttribute('aria-valuenow', String(width));
        positionHandle();
        return width;
    };
    const saveWidth = async () => {
        const body = new FormData(simpleForm);
        body.set('action', 'save');
        if (status) status.textContent = 'Saving sidebar width...';
        try {
            const response = await fetch(simpleForm.action, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.saved) throw new Error(data.error || 'Sidebar width could not be saved.');
            if (status) status.textContent = 'Sidebar width saved';
        } catch (error) {
            if (status) status.textContent = error.message;
        }
    };
    const finish = () => {
        if (!dragging) return;
        dragging = false;
        document.body.classList.remove('is-resizing-profile-sidebar');
        saveWidth();
    };

    handle.addEventListener('pointerdown', (event) => {
        if (window.matchMedia('(max-width: 700px)').matches) return;
        dragging = true;
        startX = event.clientX;
        startWidth = layout.querySelector('[data-profile-style="layout.sidebar"]')?.getBoundingClientRect().width || Number(widthField.value) || 280;
        handle.setPointerCapture(event.pointerId);
        document.body.classList.add('is-resizing-profile-sidebar');
        event.preventDefault();
    });
    handle.addEventListener('pointermove', (event) => {
        if (!dragging) return;
        setWidth(startWidth + event.clientX - startX);
    });
    handle.addEventListener('pointerup', finish);
    handle.addEventListener('pointercancel', finish);
    handle.addEventListener('keydown', (event) => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const current = Number(widthField.value) || 280;
        const next = event.key === 'Home' ? minimum : event.key === 'End' ? maximum : current + (event.key === 'ArrowLeft' ? -10 : 10);
        setWidth(next);
        saveWidth();
    });
    window.addEventListener('resize', positionHandle);
    new MutationObserver(positionHandle).observe(document.body, { attributes: true, attributeFilter: ['class'] });
    positionHandle();
});
