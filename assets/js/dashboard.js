document.addEventListener('DOMContentLoaded', () => {
    const userSearch = document.querySelector('[data-live-user-search]');
    if (userSearch) {
        const input = userSearch.elements.q;
        const results = userSearch.querySelector('.live-user-results');
        const status = userSearch.querySelector('[data-search-status]');
        const matches = userSearch.querySelector('[data-search-matches]');
        let timer;
        let request;
        let version = 0;

        const dismiss = () => {
            clearTimeout(timer);
            request?.abort();
            version++;
            results.hidden = true;
        };

        const search = (delay = 200) => {
            dismiss();
            const query = input.value.trim();
            matches.replaceChildren();
            if (!query) return;
            const currentVersion = version;
            results.hidden = false;
            status.textContent = 'Searching…';
            timer = setTimeout(async () => {
                request = new AbortController();
                try {
                    const url = new URL(userSearch.action);
                    url.search = new URLSearchParams({ q: query, format: 'json' });
                    const response = await fetch(url, { signal: request.signal, credentials: 'same-origin' });
                    const data = await response.json();
                    if (currentVersion !== version) return;
                    if (!response.ok) throw new Error(data.error || 'Search unavailable. Please try again.');
                    data.users.forEach((user) => {
                        const item = document.createElement('li');
                        const link = document.createElement('a');
                        link.href = `pages/profile.php?id=${encodeURIComponent(user.id)}`;
                        const avatar = document.createElement('span');
                        avatar.className = 'mini-avatar search-avatar';
                        avatar.setAttribute('aria-hidden', 'true');
                        avatar.textContent = user.username.charAt(0).toUpperCase();
                        if (user.avatar_path) {
                            try {
                                const avatarUrl = new URL(user.avatar_path, new URL('../', userSearch.action));
                                if (['http:', 'https:'].includes(avatarUrl.protocol)) {
                                    const picture = document.createElement('img');
                                    picture.alt = '';
                                    picture.src = avatarUrl.href;
                                    picture.addEventListener('error', () => {
                                        avatar.textContent = user.username.charAt(0).toUpperCase();
                                    });
                                    avatar.replaceChildren(picture);
                                }
                            } catch {
                                // Keep the initial if the saved picture address is invalid.
                            }
                        }
                        const name = document.createElement('span');
                        name.textContent = user.username;
                        link.append(avatar, name);
                        item.append(link);
                        matches.append(item);
                    });
                    status.textContent = data.users.length
                        ? (data.hasMore ? 'First 50 matches — type more to narrow your search.' : `${data.users.length} matching user${data.users.length === 1 ? '' : 's'}`)
                        : 'No users found.';
                } catch (error) {
                    if (currentVersion !== version || error.name === 'AbortError') return;
                    status.textContent = 'Search unavailable. Please try again or log in again.';
                }
            }, delay);
        };

        input.addEventListener('input', () => search());
        input.addEventListener('focus', () => { if (results.hidden) search(); });
        userSearch.addEventListener('submit', (event) => {
            event.preventDefault();
            search(0);
        });
        userSearch.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') dismiss();
            if (event.key === 'ArrowDown' && event.target === input && !results.hidden) {
                const first = matches.querySelector('a');
                if (first) { event.preventDefault(); first.focus(); }
            }
        });
        document.addEventListener('pointerdown', (event) => {
            if (!userSearch.contains(event.target)) dismiss();
        });
        userSearch.addEventListener('focusout', (event) => {
            if (!userSearch.contains(event.relatedTarget)) dismiss();
        });
    }

    const zoomWarning = document.querySelector('[data-zoom-warning]');
    const dismissZoomWarning = document.querySelector('[data-dismiss-zoom-warning]');

    if (zoomWarning && dismissZoomWarning) {
        dismissZoomWarning.addEventListener('click', () => {
            zoomWarning.hidden = true;
        });
    }

    const notificationsPanel = document.querySelector('[data-notifications]');

    if (notificationsPanel) {
        const notificationToggle = notificationsPanel.querySelector('.notifications-toggle');
        const notificationBadge = notificationsPanel.querySelector('[data-notification-badge]');
        const notificationChevron = notificationsPanel.querySelector('[data-notification-chevron]');
        const notificationList = notificationsPanel.querySelector('[data-notifications-list]');
        const readAllButton = notificationsPanel.querySelector('[data-read-all-notifications]');

        const updateUnreadCount = () => {
            const unreadCount = notificationsPanel.querySelectorAll('[data-notification-item][data-unread="true"]').length;

            if (notificationBadge) {
                notificationBadge.hidden = unreadCount === 0;
                notificationBadge.textContent = unreadCount > 9 ? '9+' : String(unreadCount);
            }
        };

        updateUnreadCount();

        if (readAllButton) {
            readAllButton.addEventListener('click', () => {
                notificationsPanel.querySelectorAll('[data-notification-item]').forEach((item) => {
                    item.dataset.unread = 'false';
                    item.classList.remove('is-unread');
                });
                updateUnreadCount();
            });
        }

        if (notificationToggle) {
            notificationToggle.addEventListener('click', () => {
                const isCollapsed = notificationsPanel.classList.toggle('is-collapsed');
                notificationToggle.setAttribute('aria-expanded', String(!isCollapsed));

                if (notificationChevron) {
                    notificationChevron.textContent = isCollapsed ? 'v' : '^';
                }
            });
        }

        if (notificationList) {
            notificationList.addEventListener('pointerover', (event) => {
                const notificationItem = event.target.closest('[data-notification-item]');

                if (!notificationItem || notificationItem.dataset.unread !== 'true') {
                    return;
                }

                notificationItem.dataset.unread = 'false';
                notificationItem.classList.remove('is-unread');
                updateUnreadCount();
            });
        }
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
