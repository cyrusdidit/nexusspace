document.addEventListener('DOMContentLoaded', () => {
    const total = document.querySelector('[data-unread-total]');
    if (!total) return;
    const badges = document.querySelectorAll('[data-friend-unread]');
    document.querySelectorAll('[data-top-friend-chat]').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (!window.miniChat) return;
            event.preventDefault();
            window.miniChat.open(Number(link.dataset.topFriendChat));
        });
    });
    const update = (data) => {
        total.hidden = data.totalUnread === 0;
        total.textContent = String(data.totalUnread);
        total.setAttribute('aria-label', `${data.totalUnread} unread messages in total`);
        const counts = new Map(data.senders.map((sender) => [Number(sender.sender_id), sender.unread_count]));
        badges.forEach((badge) => {
            const count = counts.get(Number(badge.dataset.friendUnread)) || 0;
            badge.hidden = count === 0;
            badge.textContent = count > 99 ? '99+' : String(count);
            const label = `${count} unread messages from ${badge.dataset.friendName}. Open chat`;
            badge.setAttribute('aria-label', label);
            badge.title = label;
        });
    };
    window.addEventListener('nexusspace:message-updates', (event) => update(event.detail));
    const current = window.nexusspaceMessages?.getSnapshot();
    if (current) update(current);
    badges.forEach((badge) => badge.addEventListener('click', (event) => {
        if (!window.miniChat) return;
        event.preventDefault();
        window.miniChat.open(Number(badge.dataset.friendUnread));
    }));
});
