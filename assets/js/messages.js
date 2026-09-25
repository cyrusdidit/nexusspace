document.addEventListener('DOMContentLoaded', () => {
    const friendSearch = document.querySelector('[data-conversation-search]');
    const friendItems = Array.from(document.querySelectorAll('[data-conversation-friend]'));
    const searchEmpty = document.querySelector('[data-conversation-search-empty]');
    friendSearch?.addEventListener('input', () => {
        const query = friendSearch.value.trim().toLocaleLowerCase();
        let visibleFriends = 0;
        friendItems.forEach((item) => {
            item.hidden = !item.dataset.friendName.toLocaleLowerCase().includes(query);
            if (!item.hidden) visibleFriends++;
        });
        if (searchEmpty) searchEmpty.hidden = visibleFriends !== 0;
    });

    const page = document.querySelector('[data-messages-page]');
    const profilePanel = document.querySelector('[data-conversation-profile]');
    const profileToggle = document.querySelector('[data-conversation-profile-toggle]');
    const profileClose = document.querySelector('[data-conversation-profile-close]');
    const setProfileOpen = (open) => {
        if (!page || !profilePanel || !profileToggle) return;
        page.classList.toggle('is-profile-open', open);
        profilePanel.hidden = !open;
        profileToggle.setAttribute('aria-expanded', String(open));
        if (open) profileClose?.focus();
    };
    profileToggle?.addEventListener('click', () => setProfileOpen(profilePanel.hidden));
    profileClose?.addEventListener('click', () => {
        setProfileOpen(false);
        profileToggle.focus();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && profilePanel && !profilePanel.hidden) setProfileOpen(false);
    });

    const list = document.querySelector('[data-message-list]');
    const form = document.querySelector('[data-message-form]');
    if (!list || !form || list.dataset.poll !== 'true') return;
    const status = document.querySelector('[data-message-status]');
    const input = form.elements.content;
    const button = form.querySelector('button');
    const conversationList = document.querySelector('.conversation-list');
    const selectedFriendItem = document.querySelector('[data-conversation-friend][aria-current="page"]');
    let lastId = Number(list.lastElementChild?.dataset.messageId || 0);
    let refreshing = null;
    list.scrollTop = list.scrollHeight;

    const sidebarTimestamp = (value) => {
        const date = new Date(value.replace(' ', 'T'));
        const today = new Date();
        if (date.toDateString() === today.toDateString()) return value.slice(11, 16);
        if (date.getFullYear() === today.getFullYear()) return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
        return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    };
    const updateActivity = (friend) => {
        if (!friend) return;
        let label = 'Offline';
        if (friend.activity_state === 'online') label = 'Online';
        else if (friend.activity_state === 'idle') label = 'Idle';
        else if (friend.last_active_at) label = `Last seen ${sidebarTimestamp(friend.last_active_at)}`;
        document.querySelectorAll('[data-conversation-activity]').forEach((element) => { element.textContent = label; });
    };
    const updateSidebarPreview = (message, mine) => {
        if (!selectedFriendItem || !conversationList) return;
        const preview = selectedFriendItem.querySelector('.conversation-list-preview');
        const heading = selectedFriendItem.querySelector('.conversation-list-heading');
        if (preview) {
            preview.textContent = `${mine ? 'You: ' : ''}${message.content}`;
            preview.classList.remove('is-empty');
        }
        let time = heading?.querySelector('time');
        if (heading && !time) {
            time = document.createElement('time');
            heading.append(time);
        }
        if (time) {
            time.dateTime = message.created_at.replace(' ', 'T');
            time.textContent = sidebarTimestamp(message.created_at);
        }
        selectedFriendItem.querySelector('.conversation-unread')?.remove();
        conversationList.prepend(selectedFriendItem);
    };

    const refresh = () => {
        if (refreshing) return refreshing;
        refreshing = (async () => {
            const url = new URL(form.action);
            url.searchParams.set('format', 'json');
            url.searchParams.set('after', String(lastId));
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
            const data = await response.json();
            if (!response.ok || data.error) throw new Error(data.error || 'Could not load messages.');
            updateActivity(data.friend);
            const nearBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 80;
            data.messages.forEach((message) => {
                if (Number(message.id) <= lastId) return;
                list.querySelector('[data-empty-messages]')?.remove();
                const item = document.createElement('article');
                const mine = Number(message.sender_id) === Number(list.dataset.viewer);
                updateSidebarPreview(message, mine);
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

    input.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.shiftKey || event.isComposing) return;
        event.preventDefault();
        form.requestSubmit(button);
    });

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
