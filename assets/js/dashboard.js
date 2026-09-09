document.addEventListener('DOMContentLoaded', () => {
    const zoomWarning = document.querySelector('[data-zoom-warning]');
    const dismissZoomWarning = document.querySelector('[data-dismiss-zoom-warning]');

    if (zoomWarning && dismissZoomWarning) {
        dismissZoomWarning.addEventListener('click', () => {
            zoomWarning.hidden = true;
        });
    }

    const activityIndicator = document.querySelector('[data-activity-indicator]');

    if (activityIndicator) {
        const idleAfterMilliseconds = 5 * 60 * 1000;
        const pingEveryMilliseconds = 30 * 1000;
        let lastActivityAt = Date.now();
        let lastPingAt = 0;

        const setActivityState = (state) => {
            activityIndicator.dataset.state = state;
        };

        const sendActivity = (state) => {
            const formData = new FormData();
            formData.append('state', state);

            fetch('activity-ping.php', {
                body: formData,
                credentials: 'same-origin',
                method: 'POST',
            }).catch(() => {
                // The indicator still reflects the user's local activity if a ping fails.
            });
        };

        const markActive = () => {
            const now = Date.now();
            lastActivityAt = now;
            setActivityState('online');

            if (now - lastPingAt >= pingEveryMilliseconds) {
                lastPingAt = now;
                sendActivity('online');
            }
        };

        ['keydown', 'pointerdown', 'scroll', 'touchstart'].forEach((eventName) => {
            window.addEventListener(eventName, markActive, { passive: true });
        });

        window.addEventListener('focus', markActive);
        window.addEventListener('pageshow', markActive);

        window.setInterval(() => {
            if (Date.now() - lastActivityAt >= idleAfterMilliseconds && activityIndicator.dataset.state !== 'idle') {
                setActivityState('idle');
                sendActivity('idle');
            }
        }, 1000);

        window.addEventListener('pagehide', () => {
            const data = new FormData();
            data.append('state', 'offline');
            navigator.sendBeacon('activity-ping.php', data);
        });

    }

    const chatForm = document.querySelector('[data-chat-form]');

    if (chatForm) {
        chatForm.addEventListener('submit', (event) => {
            event.preventDefault();

            const messageInput = chatForm.elements.message;

            if (messageInput.value.trim() === '') {
                return;
            }

            // Messaging is a later feature. For now, a submitted placeholder message vanishes.
            messageInput.value = '';
        });
    }

    const statusInput = document.querySelector('[data-status-input]');

    if (statusInput) {
        statusInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                statusInput.form.submit();
            }
        });

        statusInput.addEventListener('change', () => {
            statusInput.form.submit();
        });
    }

});
