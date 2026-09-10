document.addEventListener('DOMContentLoaded', () => {
    const list = document.querySelector('[data-message-list]');
    const form = document.querySelector('[data-message-form]');
    if (!list || !form || list.dataset.poll !== 'true') return;
    const status = document.querySelector('[data-message-status]');
    const input = form.elements.content;
    const button = form.querySelector('button');
    let lastId = Number(list.lastElementChild?.dataset.messageId || 0);
    let refreshing = null;
    list.scrollTop = list.scrollHeight;

    const refresh = () => {
        if (refreshing) return refreshing;
        refreshing = (async () => {
            const url = new URL(form.action);
            url.searchParams.set('format', 'json');
            url.searchParams.set('after', String(lastId));
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
            const data = await response.json();
            if (!response.ok || data.error) throw new Error(data.error || 'Could not load messages.');
            const nearBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 80;
            data.messages.forEach((message) => {
                if (Number(message.id) <= lastId) return;
                list.querySelector('[data-empty-messages]')?.remove();
                const item = document.createElement('article');
                const mine = Number(message.sender_id) === Number(list.dataset.viewer);
                item.className = `conversation-message${mine ? ' is-mine' : ''}`;
                item.dataset.messageId = message.id;
                const author = document.createElement('strong');
                author.textContent = mine ? 'You' : form.dataset.friendName;
                const content = document.createElement('p');
                content.textContent = message.content;
                const time = document.createElement('small');
                time.textContent = message.created_at;
                item.append(author, content, time);
                list.append(item);
                lastId = Number(message.id);
            });
            if (nearBottom) list.scrollTop = list.scrollHeight;
        })().finally(() => { refreshing = null; });
        return refreshing;
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (button.disabled || !input.value.trim()) return;
        const submitted = input.value;
        button.disabled = true;
        status.textContent = 'Sending…';
        let sent = false;
        try {
            const url = new URL(form.action);
            url.searchParams.set('format', 'json');
            const response = await fetch(url, { method: 'POST', credentials: 'same-origin', body: new FormData(form) });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || 'Could not send your message.');
            sent = true;
            if (input.value === submitted) input.value = '';
            if (refreshing) await refreshing.catch(() => {});
            await refresh();
            list.scrollTop = list.scrollHeight;
            status.textContent = '';
        } catch (error) {
            status.textContent = sent ? 'Message sent. Reconnecting to the conversation…' : 'Could not confirm sending. Check the conversation before trying again.';
        } finally {
            button.disabled = false;
        }
    });
    const poll = () => {
        if (document.hidden) return;
        refresh().catch(() => { status.textContent = 'Connection interrupted. Retrying…'; });
    };
    window.setInterval(poll, 3000);
    window.addEventListener('focus', poll);
});
