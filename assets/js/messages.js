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
    const customizationPanel = document.querySelector('[data-message-customization]');
    const customizationToggle = document.querySelector('[data-message-customization-toggle]');
    const customizationClose = document.querySelector('[data-message-customization-close]');
    const backgroundColor = document.querySelector('[data-message-background-color]');
    const backgroundValue = document.querySelector('[data-message-background-value]');
    const backgroundModes = Array.from(document.querySelectorAll('[data-message-background-mode]'));
    const colorOption = document.querySelector('[data-message-color-option]');
    const imageOption = document.querySelector('[data-message-image-option]');
    const backgroundImage = document.querySelector('[data-message-background-image]');
    const backgroundSurface = document.querySelector('[data-message-list]');
    let backgroundThumbnail = document.querySelector('[data-message-background-thumbnail]');
    let previewImageUrl = '';
    let setCustomizationOpen = () => {};
    const setProfileOpen = (open) => {
        if (!page || !profilePanel || !profileToggle) return;
        if (open) setCustomizationOpen(false);
        page.classList.toggle('is-profile-open', open);
        profilePanel.hidden = !open;
        profileToggle.setAttribute('aria-expanded', String(open));
        if (open) profileClose?.focus();
    };
    const applyBackground = (type, color, imageUrl = '') => {
        const escapedImageUrl = imageUrl.replace(/["\\]/g, '\\$&');
        if (backgroundSurface) {
            backgroundSurface.style.backgroundColor = color;
            backgroundSurface.style.backgroundImage = type === 'image' && imageUrl ? `url("${escapedImageUrl}")` : 'none';
        }
        if (backgroundValue) backgroundValue.value = color.toUpperCase();
    };
    const showBackgroundThumbnail = (imageUrl) => {
        if (!backgroundThumbnail) return;
        if (imageUrl) {
            if (!(backgroundThumbnail instanceof HTMLImageElement)) {
                const image = document.createElement('img');
                image.className = 'messages-background-thumbnail';
                image.dataset.messageBackgroundThumbnail = '';
                backgroundThumbnail.replaceWith(image);
                backgroundThumbnail = image;
            }
            backgroundThumbnail.src = imageUrl;
            backgroundThumbnail.alt = 'Selected Messages background';
        } else {
            if (backgroundThumbnail instanceof HTMLImageElement) {
                const empty = document.createElement('div');
                empty.className = 'messages-background-thumbnail is-empty';
                empty.dataset.messageBackgroundThumbnail = '';
                backgroundThumbnail.replaceWith(empty);
                backgroundThumbnail = empty;
            }
            backgroundThumbnail.textContent = 'No image selected';
        }
    };
    const selectedBackgroundMode = () => backgroundModes.find((control) => control.checked)?.value || 'color';
    const setBackgroundMode = (type) => {
        backgroundModes.forEach((control) => { control.checked = control.value === type; });
        if (colorOption) colorOption.hidden = type !== 'color';
        if (imageOption) imageOption.hidden = type !== 'image';
        const imageUrl = previewImageUrl || customizationPanel?.dataset.savedImage || '';
        applyBackground(type, backgroundColor?.value || customizationPanel?.dataset.savedColor || '#f6fcff', imageUrl);
    };
    const resetBackgroundControls = () => {
        if (!customizationPanel) return;
        if (previewImageUrl) URL.revokeObjectURL(previewImageUrl);
        previewImageUrl = '';
        if (backgroundImage) backgroundImage.value = '';
        if (backgroundColor) backgroundColor.value = customizationPanel.dataset.savedColor;
        showBackgroundThumbnail(customizationPanel.dataset.savedImage);
        setBackgroundMode(customizationPanel.dataset.savedType);
    };
    setCustomizationOpen = (open, restoreBackground = true) => {
        if (!page || !customizationPanel || !customizationToggle) return;
        if (open) setProfileOpen(false);
        page.classList.toggle('is-customization-open', open);
        customizationPanel.hidden = !open;
        customizationToggle.setAttribute('aria-expanded', String(open));
        if (!open && restoreBackground) resetBackgroundControls();
        if (open) customizationClose?.focus();
    };
    profileToggle?.addEventListener('click', () => setProfileOpen(profilePanel.hidden));
    profileClose?.addEventListener('click', () => {
        setProfileOpen(false);
        profileToggle.focus();
    });
    customizationToggle?.addEventListener('click', () => setCustomizationOpen(customizationPanel.hidden));
    customizationClose?.addEventListener('click', () => {
        setCustomizationOpen(false);
        customizationToggle.focus();
    });
    backgroundModes.forEach((control) => control.addEventListener('change', () => setBackgroundMode(selectedBackgroundMode())));
    backgroundColor?.addEventListener('input', () => applyBackground('color', backgroundColor.value));
    backgroundImage?.addEventListener('change', () => {
        if (previewImageUrl) URL.revokeObjectURL(previewImageUrl);
        const file = backgroundImage.files?.[0];
        previewImageUrl = file ? URL.createObjectURL(file) : '';
        const imageUrl = previewImageUrl || customizationPanel?.dataset.savedImage || '';
        showBackgroundThumbnail(imageUrl);
        applyBackground('image', backgroundColor?.value || '#f6fcff', imageUrl);
    });
    if (customizationPanel?.dataset.open === 'true') setCustomizationOpen(true, false);
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (customizationPanel && !customizationPanel.hidden) {
            setCustomizationOpen(false);
            customizationToggle?.focus();
        } else if (profilePanel && !profilePanel.hidden) {
            setProfileOpen(false);
            profileToggle?.focus();
        }
    });

    const shareProfile = document.querySelector('[data-share-profile]');
    const shareProfileStatus = document.querySelector('[data-share-profile-status]');
    const fallbackCopy = (value) => {
        const field = document.createElement('textarea');
        field.value = value;
        field.setAttribute('readonly', '');
        field.style.position = 'fixed';
        field.style.opacity = '0';
        document.body.append(field);
        field.select();
        const copied = document.execCommand('copy');
        field.remove();
        if (!copied) throw new Error('Copy failed');
    };
    shareProfile?.addEventListener('click', async () => {
        const profileUrl = new URL(shareProfile.dataset.profileUrl, window.location.href).href;
        shareProfile.disabled = true;
        try {
            if (navigator.clipboard?.writeText) {
                try {
                    await navigator.clipboard.writeText(profileUrl);
                } catch {
                    fallbackCopy(profileUrl);
                }
            } else {
                fallbackCopy(profileUrl);
            }
            shareProfile.textContent = 'Copied';
            if (shareProfileStatus) shareProfileStatus.textContent = 'Profile link copied.';
        } catch {
            if (shareProfileStatus) shareProfileStatus.textContent = 'Could not copy the profile link.';
        } finally {
            window.setTimeout(() => {
                shareProfile.textContent = 'Share profile';
                shareProfile.disabled = false;
                if (shareProfileStatus) shareProfileStatus.textContent = '';
            }, 1800);
        }
    });

    const nicknameForm = document.querySelector('[data-nickname-form]');
    const nicknameStatus = document.querySelector('[data-nickname-status]');
    const nicknameDisplay = document.querySelector('[data-nickname-display]');
    const nicknameEdit = document.querySelector('[data-nickname-edit]');
    const nicknameCancel = document.querySelector('[data-nickname-cancel]');
    const nicknameInput = nicknameForm?.elements.nickname;
    const setNicknameEditing = (editing) => {
        if (!nicknameForm || !nicknameDisplay) return;
        nicknameDisplay.hidden = editing;
        nicknameForm.hidden = !editing;
        if (nicknameStatus) nicknameStatus.textContent = '';
        if (editing) {
            nicknameInput.focus();
            nicknameInput.select();
        }
    };
    nicknameEdit?.addEventListener('click', () => setNicknameEditing(true));
    nicknameCancel?.addEventListener('click', () => {
        nicknameInput.value = nicknameForm.dataset.nickname || document.querySelector('[data-selected-friend-name]')?.textContent || '';
        setNicknameEditing(false);
        nicknameEdit.focus();
    });
    nicknameForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submitButton = nicknameForm.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        if (nicknameStatus) nicknameStatus.textContent = 'Saving...';
        try {
            const url = new URL(nicknameForm.getAttribute('action'), window.location.href);
            url.searchParams.set('format', 'json');
            const response = await fetch(url, { method: 'POST', credentials: 'same-origin', body: new FormData(nicknameForm) });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || 'Could not save nickname.');
            document.querySelectorAll('[data-selected-friend-name]').forEach((element) => { element.textContent = data.displayName; });
            const selectedFriendItem = document.querySelector('[data-conversation-friend][aria-current="page"]');
            if (selectedFriendItem) selectedFriendItem.dataset.friendName = `${data.displayName} ${data.username}`;
            const messageList = document.querySelector('[data-message-list]');
            if (messageList) messageList.dataset.friendName = data.displayName;
            const messageForm = document.querySelector('[data-message-form]');
            if (messageForm) messageForm.dataset.friendName = data.displayName;
            const username = document.querySelector('[data-selected-friend-username]');
            if (username) username.hidden = data.nickname === '';
            nicknameForm.dataset.nickname = data.nickname;
            nicknameInput.value = data.displayName;
            setNicknameEditing(false);
            nicknameEdit.focus();
        } catch (error) {
            if (nicknameStatus) nicknameStatus.textContent = error.message;
        } finally {
            submitButton.disabled = false;
        }
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
    const messageDateKey = (value) => value.slice(0, 10);
    const messageTimestamp = (value) => new Date(value.replace(' ', 'T')).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    const localDateKey = (date) => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };
    const messageDateLabel = (dateKey) => {
        const date = new Date(`${dateKey}T00:00:00`);
        const today = new Date();
        const yesterday = new Date(today);
        yesterday.setDate(today.getDate() - 1);
        if (dateKey === localDateKey(today)) return 'Today';
        if (dateKey === localDateKey(yesterday)) return 'Yesterday';
        return date.toLocaleDateString(undefined, {
            month: 'long',
            day: 'numeric',
            ...(date.getFullYear() === today.getFullYear() ? {} : { year: 'numeric' }),
        });
    };
    const createDateSeparator = (dateKey) => {
        const separator = document.createElement('div');
        separator.className = 'conversation-date-separator';
        separator.dataset.messageDateSeparator = dateKey;
        const label = document.createElement('span');
        label.textContent = messageDateLabel(dateKey);
        separator.append(label);
        return separator;
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
    const createFriendAvatar = () => {
        const avatar = document.createElement('a');
        avatar.className = 'conversation-message-avatar';
        avatar.href = list.dataset.friendProfile;
        avatar.setAttribute('aria-label', `View ${list.dataset.friendName}'s profile`);
        const initial = document.createElement('span');
        initial.textContent = list.dataset.friendInitial;
        avatar.append(initial);
        if (list.dataset.friendAvatar) {
            const image = document.createElement('img');
            image.src = list.dataset.friendAvatar;
            image.alt = '';
            avatar.append(image);
        }
        return avatar;
    };
    const createViewerAvatar = () => {
        const avatar = document.createElement('a');
        avatar.className = 'conversation-message-avatar is-viewer';
        avatar.href = list.dataset.viewerProfile;
        avatar.setAttribute('aria-label', 'View your profile');
        const initial = document.createElement('span');
        initial.textContent = list.dataset.viewerInitial;
        avatar.append(initial);
        if (list.dataset.viewerAvatar) {
            const image = document.createElement('img');
            image.src = list.dataset.viewerAvatar;
            image.alt = '';
            avatar.append(image);
        }
        return avatar;
    };
    const createReadReceipt = (isRead) => {
        const receipt = document.createElement('span');
        receipt.className = `message-read-receipt${isRead ? ' is-read' : ''}`;
        receipt.dataset.readReceipt = '';
        receipt.textContent = isRead ? '\u2713\u2713' : '\u2713';
        receipt.setAttribute('aria-label', isRead ? 'Read' : 'Sent');
        receipt.title = isRead ? 'Read' : 'Sent';
        return receipt;
    };
    const updateReadReceipts = (lastReadOutgoingId) => {
        const boundary = Number(lastReadOutgoingId || 0);
        list.querySelectorAll('.conversation-message.is-mine[data-message-id]').forEach((message) => {
            if (Number(message.dataset.messageId) > boundary) return;
            const receipt = message.querySelector('[data-read-receipt]');
            if (!receipt || receipt.classList.contains('is-read')) return;
            receipt.classList.add('is-read');
            receipt.textContent = '\u2713\u2713';
            receipt.setAttribute('aria-label', 'Read');
            receipt.title = 'Read';
        });
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
                const dateKey = messageDateKey(message.created_at);
                const previousMessage = Array.from(list.querySelectorAll('.conversation-message')).at(-1);
                const startsNewDay = previousMessage?.dataset.messageDate !== dateKey;
                updateSidebarPreview(message, mine);
                item.className = `conversation-message ${mine ? 'is-mine' : 'is-incoming'} has-avatar`;
                item.dataset.messageId = message.id;
                item.dataset.messageDate = dateKey;
                const author = document.createElement('strong');
                author.textContent = mine ? 'You' : form.dataset.friendName;
                const content = document.createElement('p');
                content.textContent = message.content;
                const time = document.createElement('small');
                const timeValue = document.createElement('time');
                timeValue.dateTime = message.created_at.replace(' ', 'T');
                timeValue.textContent = messageTimestamp(message.created_at);
                time.append(timeValue);
                if (mine) time.append(createReadReceipt(Number(message.is_read) === 1 || Number(message.id) <= Number(data.lastReadOutgoingId)));
                if (mine) {
                    if (!startsNewDay && previousMessage?.classList.contains('is-mine')) {
                        previousMessage.classList.remove('has-avatar');
                        previousMessage.querySelector('.conversation-message-avatar')?.remove();
                    }
                    item.append(createViewerAvatar());
                } else {
                    if (!startsNewDay && previousMessage?.classList.contains('is-incoming')) {
                        previousMessage.classList.remove('has-avatar');
                        previousMessage.querySelector('.conversation-message-avatar')?.remove();
                    }
                    item.append(createFriendAvatar());
                }
                item.append(author, content, time);
                if (startsNewDay) list.append(createDateSeparator(dateKey));
                list.append(item);
                lastId = Number(message.id);
            });
            updateReadReceipts(data.lastReadOutgoingId);
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
