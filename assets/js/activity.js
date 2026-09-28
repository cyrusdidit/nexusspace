const activityRoot = document.body;
const activityEndpoint = activityRoot.dataset.activityEndpoint;
const profileActivity = document.querySelector('[data-profile-activity]');
const activityStatusEndpoint = activityRoot.dataset.activityStatusEndpoint;

if (activityEndpoint) {
    const idleAfterMilliseconds = 5 * 60 * 1000;
    const offlineAfterMilliseconds = 10 * 60 * 1000;
    const pingEveryMilliseconds = 15 * 1000;
    const activePingThrottleMilliseconds = 5 * 1000;
    const activityRecordThrottleMilliseconds = 1000;
    const activityStorageKey = 'nexusspace-last-activity-at';
    let lastActivityAt = Date.now();
    let currentState = 'online';
    let lastPingAt = 0;

    const rememberActivity = (timestamp) => {
        lastActivityAt = timestamp;
        try {
            localStorage.setItem(activityStorageKey, String(timestamp));
        } catch (error) {}
    };

    const sharedLastActivity = () => {
        try {
            return Math.max(lastActivityAt, Number(localStorage.getItem(activityStorageKey)) || 0);
        } catch (error) {
            return lastActivityAt;
        }
    };

    const sendActivity = (state, active = false) => {
        lastPingAt = Date.now();
        const formData = new FormData();
        formData.append('state', state);
        if (active) formData.append('active', '1');
        fetch(activityEndpoint, {
            body: formData,
            credentials: 'same-origin',
            method: 'POST',
        })
            .then((response) => response.ok ? response.json() : null)
            .then((data) => {
                if (!data?.state) return;
                currentState = data.state;
                showOwnState(currentState);
            })
            .catch(() => {});
    };

    const showOwnState = (state) => {
        document.querySelectorAll('[data-activity-indicator]').forEach((indicator) => {
            indicator.dataset.state = state;
        });
        if (profileActivity?.dataset.userId === activityRoot.dataset.currentUserId) {
            profileActivity.dataset.state = state;
            profileActivity.querySelector('[data-activity-label]').textContent = state === 'online' ? 'Online' : state === 'idle' ? 'Idle' : 'Offline';
        }
    };

    const markActive = () => {
        const now = Date.now();
        const becameActive = currentState !== 'online';
        if (!becameActive && now - lastActivityAt < activityRecordThrottleMilliseconds) return;
        rememberActivity(now);
        currentState = 'online';
        showOwnState(currentState);
        if (becameActive || now - lastPingAt >= activePingThrottleMilliseconds) sendActivity(currentState, true);
    };

    ['keydown', 'pointerdown', 'mousemove', 'scroll', 'touchstart'].forEach((eventName) => {
        window.addEventListener(eventName, markActive, { passive: true });
    });
    window.addEventListener('focus', markActive);
    window.addEventListener('pageshow', markActive);
    window.addEventListener('storage', (event) => {
        if (event.key !== activityStorageKey) return;
        lastActivityAt = Math.max(lastActivityAt, Number(event.newValue) || 0);
    });

    const heartbeat = () => {
        const inactiveFor = Date.now() - sharedLastActivity();
        currentState = inactiveFor >= offlineAfterMilliseconds ? 'offline' : inactiveFor >= idleAfterMilliseconds ? 'idle' : 'online';
        showOwnState(currentState);
        sendActivity(currentState);
    };

    rememberActivity(lastActivityAt);
    showOwnState(currentState);
    sendActivity(currentState, true);
    window.setInterval(heartbeat, pingEveryMilliseconds);
}

if (profileActivity && activityStatusEndpoint) {
    const refreshProfileActivity = async () => {
        if (document.hidden) return;
        try {
            const url = new URL(activityStatusEndpoint, window.location.href);
            url.searchParams.set('user', profileActivity.dataset.userId);
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
            const data = await response.json();
            if (!response.ok || data.error) return;
            profileActivity.dataset.state = data.state;
            profileActivity.querySelector('[data-activity-label]').textContent = data.label;
        } catch (error) {
            // Keep the last known status during a temporary connection failure.
        }
    };

    window.setInterval(refreshProfileActivity, 3000);
    window.addEventListener('focus', refreshProfileActivity);
    refreshProfileActivity();
}
