document.addEventListener('DOMContentLoaded', () => {
    if (!document.querySelector('.dashboard-page')) return;

    let snapshot = null;
    let inFlight = null;
    let stopped = false;
    const endpoint = new URL('message-updates.php', window.location.href);

    const refresh = () => {
        if (stopped) return Promise.resolve();
        if (inFlight) return inFlight;
        inFlight = (async () => {
            const response = await fetch(endpoint, {
                credentials: 'same-origin',
                cache: 'no-store',
                signal: AbortSignal.timeout(10000),
            });
            if (response.status === 401) {
                stopped = true;
                snapshot = null;
                return;
            }
            if (!response.ok) throw new Error('Message check failed.');
            const next = await response.json();
            // Existing unread messages populate counts; only arrivals after the
            // initial check should trigger the upcoming popup UI.
            const newSenders = snapshot && snapshot.userId === next.userId
                ? next.senders.filter((sender) => sender.latest_unread_id > snapshot.latestIncomingId)
                : [];
            snapshot = next;
            window.dispatchEvent(new CustomEvent('nexusspace:message-updates', {
                detail: { ...next, newSenders },
            }));
        })().catch(() => {
            // Preserve counts on transient failures and retry on the next check.
        }).finally(() => { inFlight = null; });
        return inFlight;
    };

    // Shared with the upcoming popup, Friends badges and mini conversation.
    window.nexusspaceMessages = {
        refresh,
        getSnapshot: () => snapshot ? structuredClone(snapshot) : null,
    };
    refresh();
    window.setInterval(() => { if (!document.hidden) refresh(); }, 3000);
    window.addEventListener('focus', refresh);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
});
