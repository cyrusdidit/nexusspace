document.addEventListener('DOMContentLoaded', () => {
    const box = document.querySelector('[data-mini-chat]');
    const handle = document.querySelector('[data-mini-resize]');
    if (!box || !handle) return;
    let drag = null;
    const resize = (width, height) => {
        const rect = box.getBoundingClientRect();
        const maxWidth = Math.max(1, Math.min(430, rect.right - 10));
        const maxHeight = Math.max(1, Math.min(365, rect.bottom - 10));
        const minWidth = Math.min(260, maxWidth);
        const minHeight = Math.min(300, window.innerHeight * 0.55, maxHeight);
        box.style.width = `${Math.max(minWidth, Math.min(maxWidth, width))}px`;
        box.style.height = `${Math.max(minHeight, Math.min(maxHeight, height))}px`;
    };
    handle.addEventListener('pointerdown', (event) => {
        if (event.button !== 0) return;
        const rect = box.getBoundingClientRect();
        drag = {x: event.clientX, y: event.clientY, width: rect.width, height: rect.height};
        handle.setPointerCapture(event.pointerId);
        event.preventDefault();
    });
    handle.addEventListener('pointermove', (event) => {
        if (drag) resize(drag.width + drag.x - event.clientX, drag.height + drag.y - event.clientY);
    });
    const stop = () => {drag = null;};
    handle.addEventListener('pointerup', stop);
    handle.addEventListener('pointercancel', stop);
    handle.addEventListener('lostpointercapture', stop);
    const reset = () => {box.style.width = ''; box.style.height = '';};
    handle.addEventListener('dblclick', reset);
    handle.addEventListener('keydown', (event) => {
        const rect = box.getBoundingClientRect();
        if (event.key === 'Home') {event.preventDefault(); reset(); return;}
        const changes = {ArrowLeft: [20, 0], ArrowRight: [-20, 0], ArrowUp: [0, 20], ArrowDown: [0, -20]};
        const change = changes[event.key];
        if (change) {event.preventDefault(); resize(rect.width + change[0], rect.height + change[1]);}
    });
    window.addEventListener('resize', reset);
});
