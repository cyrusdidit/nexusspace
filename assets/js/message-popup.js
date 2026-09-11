document.addEventListener('DOMContentLoaded', () => {
    const popup = document.querySelector('[data-message-popup]');
    if (!popup) return;
    const openButton = popup.querySelector('[data-message-popup-open]');
    const dismissButton = popup.querySelector('[data-message-popup-dismiss]');
    const announcement = popup.querySelector('[data-message-popup-announcement]');
    const queue = [];
    let active = null;
    let timer = null;
    let remaining = 8000;
    let started = 0;
    let hovered = false;
    let focused = false;

    const pause = () => {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
            remaining = Math.max(0, remaining - (performance.now() - started));
        }
    };
    const resume = () => {
        if (!active || hovered || focused || document.hidden || timer !== null) return;
        started = performance.now();
        timer = setTimeout(dismiss, remaining);
    };
    const showNext = () => {
        if (active || !queue.length) return;
        active = queue.shift();
        remaining = 8000;
        const text = `${active.username} sent you a message`;
        openButton.textContent = text;
        openButton.setAttribute('aria-label', `${text}. Open chat`);
        announcement.textContent = text;
        popup.hidden = false;
        resume();
    };
    function dismiss() {
        pause();
        active = null;
        popup.hidden = true;
        announcement.textContent = '';
        focused = false;
        hovered = false;
        showNext();
    }
    popup.addEventListener('pointerenter', () => { hovered = true; pause(); });
    popup.addEventListener('pointerleave', () => { hovered = false; resume(); });
    popup.addEventListener('focusin', () => { focused = true; pause(); });
    popup.addEventListener('focusout', (event) => {
        if (!popup.contains(event.relatedTarget)) { focused = false; resume(); }
    });
    document.addEventListener('visibilitychange', () => { if (document.hidden) pause(); else resume(); });
    dismissButton.addEventListener('click', dismiss);
    openButton.addEventListener('click', () => {
        if (!active || !window.miniChat) return;
        const senderId = active.sender_id;
        dismiss();
        window.miniChat.open(senderId);
    });
    window.addEventListener('nexusspace:message-updates', (event) => {
        // One popup per sender; arrivals from other people wait their turn.
        for (const sender of event.detail.newSenders) {
            if (active?.sender_id === sender.sender_id) {
                pause();
                remaining = 8000;
                resume();
            } else if (!queue.some((item) => item.sender_id === sender.sender_id)) {
                queue.push(sender);
            }
        }
        showNext();
    });
});
