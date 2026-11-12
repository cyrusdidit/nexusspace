document.addEventListener('DOMContentLoaded', () => {
    const box = document.querySelector('[data-mini-chat]');
    if (!box) return;
    const list = box.querySelector('[data-mini-messages]');
    const status = box.querySelector('[data-mini-status]');
    const typingIndicator = box.querySelector('[data-mini-typing]');
    const heading = box.querySelector('h2');
    const avatar = box.querySelector('[data-mini-avatar]');
    const activity = box.querySelector('[data-mini-activity]');
    const form = box.querySelector('form');
    const input = form.elements.message;
    const send = form.querySelector('button');
    let chat = null;
    const drafts = new Map();
    const urlFor = (state) => {
        const url = new URL('pages/messages.php', location.href);
        url.search = new URLSearchParams({user: state.id, format: 'json', mini: '1'});
        return url;
    };
    const post = async (state, values) => {
        const body = new URLSearchParams({ token: state.token, ...values });
        const response = await fetch(urlFor(state), {method: 'POST', credentials: 'same-origin', body});
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'Request failed.');
    };
    const acknowledge = async (state) => {
        if (chat !== state || box.hidden || document.hidden || !state.first || !state.token) return;
        await post(state, {action: 'read', first: state.first, last: state.last});
        window.nexusspaceMessages?.refresh();
    };
    const refresh = (state) => {
        if (state.loading) return state.loading;
        state.loading = (async () => {
            const url = urlFor(state);
            if (state.last) url.searchParams.set('after', state.last);
            const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store'});
            const data = await response.json();
            if (chat !== state) return;
            if (!response.ok || data.error || !data.friend) throw new Error(data.error || 'Could not open chat.');
            state.token = data.token;
            if (typingIndicator) {
                typingIndicator.textContent = `${data.friend.username} is typing…`;
                typingIndicator.hidden = !data.typing;
            }
            send.disabled = state.sending;
            heading.textContent = data.friend.username;
            if (activity) {
                activity.dataset.state = data.friend.activity_state || 'offline';
                activity.title = data.friend.activity_state === 'online' ? 'Online' : data.friend.activity_state === 'idle' ? 'Idle' : 'Offline';
            }
            if (!state.loaded) {
                avatar.textContent = data.friend.username.charAt(0).toUpperCase();
                if (data.friend.avatar_path) {
                    try {
                        const src = new URL(data.friend.avatar_path, location.href);
                        if (['http:', 'https:'].includes(src.protocol)) {
                            const img = document.createElement('img');
                            img.alt = ''; img.src = src.href;
                            img.onerror = () => { avatar.textContent = data.friend.username.charAt(0).toUpperCase(); };
                            avatar.replaceChildren(img);
                        }
                    } catch {}
                }
                state.loaded = true;
            }
            const atBottom = list.scrollHeight - list.clientHeight - list.scrollTop < 50;
            for (const message of data.messages) {
                if (Number(message.id) <= state.last) continue;
                const bubble = document.createElement('p');
                bubble.className = 'mini-chat-bubble' + (Number(message.sender_id) === Number(data.viewerId) ? ' is-mine' : '');
                bubble.textContent = message.content;
                bubble.title = message.created_at;
                list.append(bubble);
                state.first ||= Number(message.id);
                state.last = Number(message.id);
            }
            if (atBottom) list.scrollTop = list.scrollHeight;
            status.textContent = state.last ? '' : 'No messages yet. Say hello!';
            await acknowledge(state);
        })().finally(() => {state.loading = null;});
        return state.loading;
    };
    const close = () => {
        if (chat) {
            drafts.set(chat.id, input.value);
            stopTyping(chat, true);
        }
        chat = null;
        box.hidden = true;
        const url = new URL(location.href);
        url.searchParams.delete('chat');
        history.replaceState(null, '', url);
    };
    const open = (id) => {
        id = Number(id);
        if (!Number.isSafeInteger(id) || id < 1) return;
        if (chat) {
            drafts.set(chat.id, input.value);
            stopTyping(chat, true);
        }
        const state = {id, first: 0, last: 0, token: '', loaded: false, sending: false, loading: null, typingClientKey: Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, '0')).join(''), typingAt: 0, typingTimer: null, typingActive: false};
        chat = state;
        box.hidden = false;
        list.replaceChildren(); avatar.replaceChildren();
        if (activity) activity.dataset.state = 'offline';
        heading.textContent = 'Chat'; status.textContent = 'Loading…';
        input.value = drafts.get(id) || ''; send.disabled = true;
        refresh(state).catch(() => {if (chat === state) status.textContent = 'Could not load chat. Retrying…';});
        input.focus();
    };
    const sendTypingState = (state, action, useBeacon = false) => {
        if (!state.token) return;
        const url = urlFor(state);
        const body = new URLSearchParams({token: state.token, action, typing_client_key: state.typingClientKey});
        if (useBeacon && navigator.sendBeacon) { navigator.sendBeacon(url, body); return; }
        fetch(url, {method: 'POST', credentials: 'same-origin', body, keepalive: useBeacon}).catch(() => {});
    };
    const stopTyping = (state, useBeacon = false) => {
        if (state.typingTimer) window.clearTimeout(state.typingTimer);
        state.typingTimer = null;
        if (state.typingActive) sendTypingState(state, 'stop_typing', useBeacon);
        state.typingActive = false;
    };
    const noteTyping = () => {
        const state = chat;
        if (!state || !input.value.trim()) { if (state) stopTyping(state); return; }
        const now = Date.now();
        if (!state.typingActive || now - state.typingAt >= 1800) {
            sendTypingState(state, 'typing');
            state.typingAt = now;
            state.typingActive = true;
        }
        if (state.typingTimer) window.clearTimeout(state.typingTimer);
        state.typingTimer = window.setTimeout(() => stopTyping(state), 3200);
    };
    input.addEventListener('input', noteTyping);
    input.addEventListener('blur', () => { if (chat) stopTyping(chat); });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const state = chat;
        if (!state?.token || state.sending || !input.value.trim()) return;
        const text = input.value;
        stopTyping(state);
        state.sending = true; send.disabled = true;
        try {
            await post(state, {content: text});
            if (chat !== state) { if (drafts.get(state.id) === text) drafts.delete(state.id); return; }
            if (input.value === text) input.value = '';
            if (state.loading) await state.loading.catch(() => {});
            await refresh(state);
            if (chat === state) list.scrollTop = list.scrollHeight;
        } catch {
            if (chat === state) status.textContent = 'Could not confirm sending. Check the chat before retrying.';
        } finally {
            state.sending = false;
            if (chat === state) send.disabled = false;
        }
    });
    const poll = () => {
        const state = chat;
        if (!state || document.hidden) return;
        refresh(state).catch(() => {if (chat === state) status.textContent = 'Connection interrupted. Retrying…';});
    };
    box.querySelector('[data-mini-close]').addEventListener('click', close);
    window.setInterval(poll, 3000);
    window.addEventListener('focus', poll);
    document.addEventListener('visibilitychange', () => { if (document.hidden && chat) stopTyping(chat, true); });
    window.addEventListener('pagehide', () => { if (chat) stopTyping(chat, true); });
    window.miniChat = {open, close};
    const initial = new URL(location.href).searchParams.get('chat');
    if (initial) open(initial);
});
