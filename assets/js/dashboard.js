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
        const friendNotifications = notificationsPanel.querySelector('[data-friend-notifications]');
        const readRequests = new Set();
        const markRead = (item) => {
            item.dataset.unread = 'false';
            item.classList.remove('is-unread');
            if (item.dataset.requestKey) {
                readRequests.add(item.dataset.requestKey);
                try { sessionStorage.setItem(item.dataset.requestKey, 'read'); } catch {}
            }
        };

        const updateUnreadCount = () => {
            const unreadCount = notificationsPanel.querySelectorAll('[data-notification-item][data-unread="true"]').length;

            if (notificationBadge) {
                notificationBadge.hidden = unreadCount === 0;
                notificationBadge.textContent = unreadCount > 9 ? '9+' : String(unreadCount);
            }
        };

        updateUnreadCount();

        if (friendNotifications) {
            let fetching = false;
            const refreshRequests = async () => {
                if (fetching) return;
                fetching = true;
                try {
                    const response = await fetch('friend-notifications.php', { credentials: 'same-origin', cache: 'no-store' });
                    if (!response.ok) return;
                    const data = await response.json();
                    const activeKeys = new Set();
                    data.requests.forEach((request) => {
                        const key = `friend-request:${data.userId}:${request.id}:${request.created_at}`;
                        activeKeys.add(key);
                        let item = Array.from(friendNotifications.children).find((child) => child.dataset.requestKey === key);
                        if (item) {
                            if (request.status !== 'pending') markRead(item);
                            return;
                        }
                        item = document.createElement('p');
                        item.className = 'notification-item is-unread';
                        item.dataset.notificationItem = '';
                        item.dataset.requestKey = key;
                        item.dataset.unread = 'true';
                        const link = document.createElement('a');
                        link.href = `pages/profile.php?id=${encodeURIComponent(request.sender_id)}`;
                        link.textContent = `${request.username} sent you a friend request!`;
                        item.append(link);
                        let alreadyRead = request.status !== 'pending' || readRequests.has(key);
                        try { alreadyRead ||= sessionStorage.getItem(key) === 'read'; } catch {}
                        if (alreadyRead) markRead(item);
                        friendNotifications.append(item);
                    });
                    Array.from(friendNotifications.children).forEach((item) => {
                        if (!activeKeys.has(item.dataset.requestKey)) item.remove();
                    });
                    updateUnreadCount();
                } catch {
                    // Retain existing notifications during a temporary connection failure.
                } finally {
                    fetching = false;
                }
            };
            refreshRequests();
            window.setInterval(refreshRequests, 5000);
            window.addEventListener('focus', refreshRequests);
        }

        if (readAllButton) {
            readAllButton.addEventListener('click', () => {
                notificationsPanel.querySelectorAll('[data-notification-item]').forEach((item) => {
                    markRead(item);
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

                markRead(notificationItem);
                updateUnreadCount();
            });
            notificationList.addEventListener('click', (event) => {
                const item = event.target.closest('[data-notification-item]');
                if (item) { markRead(item); updateUnreadCount(); }
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

    const appearanceToggle = document.querySelector('[data-dashboard-appearance-toggle]');
    const appearancePanel = document.querySelector('[data-dashboard-appearance-panel]');
    const appearanceClose = document.querySelector('[data-dashboard-appearance-close]');
    const appearanceForm = document.querySelector('[data-dashboard-appearance-form]');
    const appearanceStatus = document.querySelector('[data-dashboard-appearance-status]');
    const dashboardBackground = document.querySelector('[data-dashboard-background]');
    const backgroundSurface = document.querySelector('[data-dashboard-background-surface]');
    const backgroundPreview = document.querySelector('[data-dashboard-background-preview]');
    const backgroundColor = document.querySelector('[data-dashboard-background-color]');
    const backgroundValue = document.querySelector('[data-dashboard-background-value]');
    const backgroundModes = [...document.querySelectorAll('[data-dashboard-background-mode]')];
    const colorOption = document.querySelector('[data-dashboard-color-option]');
    const imageOption = document.querySelector('[data-dashboard-image-option]');
    const imageInput = document.querySelector('[data-dashboard-background-image]');
    const imageFits = [...document.querySelectorAll('[data-dashboard-image-fit]')];
    const imageBlur = document.querySelector('[data-dashboard-image-blur]');
    const imageBlurValue = document.querySelector('[data-dashboard-image-blur-value]');
    const imageRemove = document.querySelector('[data-dashboard-image-remove]');
    const resetOpen = document.querySelector('[data-dashboard-reset-open]');
    const resetDialog = document.querySelector('[data-dashboard-reset-dialog]');
    const resetConfirm = document.querySelector('[data-dashboard-reset-confirm]');
    const resetCancelButtons = [...document.querySelectorAll('[data-dashboard-reset-cancel]')];
    const copySettings = document.querySelector('[data-dashboard-copy-settings]');
    const appearanceToast = document.querySelector('[data-dashboard-toast]');
    const appearanceToastMessage = document.querySelector('[data-dashboard-toast-message]');
    const appearanceToastClose = document.querySelector('[data-dashboard-toast-close]');
    const positionXInput = document.querySelector('[data-dashboard-position-x]');
    const positionYInput = document.querySelector('[data-dashboard-position-y]');
    const imageZoomValue = document.querySelector('[data-dashboard-image-zoom-value]');
    const cropDialog = document.querySelector('[data-dashboard-crop-dialog]');
    const cropCanvas = document.querySelector('[data-dashboard-crop-canvas]');
    const cropZoomInput = document.querySelector('[data-dashboard-crop-zoom]');
    const cropSave = document.querySelector('[data-dashboard-crop-save]');
    const cropCancelButtons = [...document.querySelectorAll('[data-dashboard-crop-cancel]')];
    const cropStatus = document.querySelector('[data-dashboard-crop-status]');
    let backgroundThumbnail = document.querySelector('[data-dashboard-background-thumbnail]');
    let previewImageUrl = '';
    let toastTimer;

    const hideToast = () => {
        if (!appearanceToast || appearanceToast.hidden) return;
        window.clearTimeout(toastTimer);
        appearanceToast.classList.add('is-hiding');
        window.setTimeout(() => {
            appearanceToast.hidden = true;
            appearanceToast.classList.remove('is-hiding');
        }, 170);
    };

    const showToast = (message) => {
        if (!appearanceToast || !appearanceToastMessage) return;
        window.clearTimeout(toastTimer);
        appearanceToast.classList.remove('is-hiding');
        appearanceToastMessage.textContent = message;
        appearanceToast.hidden = false;
        toastTimer = window.setTimeout(hideToast, 3000);
    };

    appearanceToastClose?.addEventListener('click', hideToast);

    if (appearanceToggle && appearancePanel) {
        const setAppearanceOpen = (open) => {
            appearancePanel.hidden = !open;
            appearanceToggle.setAttribute('aria-expanded', String(open));
            if (open) backgroundColor?.focus();
        };

        setAppearanceOpen(false);
        appearanceToggle.addEventListener('click', () => setAppearanceOpen(appearancePanel.hidden));
        appearanceClose?.addEventListener('click', () => setAppearanceOpen(false));
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !appearancePanel.hidden && !cropDialog?.open && !resetDialog?.open) {
                setAppearanceOpen(false);
                appearanceToggle.focus();
            }
        });

        setAppearanceOpen(appearancePanel.dataset.open === 'true');
    }

    if (backgroundColor && dashboardBackground) {
        backgroundColor.addEventListener('input', () => {
            dashboardBackground.style.backgroundColor = backgroundColor.value;
            if (backgroundValue) backgroundValue.value = backgroundColor.value.toUpperCase();
        });
    }

    const applyBackgroundImage = () => {
        if (!appearancePanel || !backgroundSurface || !backgroundPreview) return;
        const imageUrl = previewImageUrl || appearancePanel.dataset.savedImage;
        backgroundSurface.hidden = !imageUrl;
        if (!imageUrl) return;
        const imageFit = imageFits.find((control) => control.checked)?.value || 'cover';
        const positionX = Number(positionXInput?.value || 50);
        const positionY = Number(positionYInput?.value || 50);
        const zoom = Number(imageZoomValue?.value || 1);
        if (imageFit === 'tile') {
            backgroundPreview.hidden = true;
            backgroundSurface.style.backgroundImage = `url("${imageUrl}")`;
            backgroundSurface.style.backgroundRepeat = 'repeat';
            backgroundSurface.style.backgroundSize = 'auto';
        } else {
            backgroundSurface.style.backgroundImage = 'none';
            backgroundPreview.hidden = false;
            backgroundPreview.src = imageUrl;
            backgroundPreview.style.objectFit = imageFit === 'contain' ? 'contain' : 'cover';
            backgroundPreview.style.objectPosition = `${positionX}% ${positionY}%`;
            backgroundPreview.style.transform = `scale(${zoom})`;
            backgroundPreview.style.transformOrigin = `${positionX}% ${positionY}%`;
        }
        backgroundSurface.style.filter = `blur(${imageBlur?.value || 0}px)`;
    };

    const setBackgroundMode = (mode) => {
        if (colorOption) colorOption.hidden = mode !== 'color';
        if (imageOption) imageOption.hidden = mode !== 'image';
        if (mode === 'color') {
            if (backgroundSurface) backgroundSurface.hidden = true;
        } else {
            applyBackgroundImage();
        }
    };

    backgroundModes.forEach((control) => control.addEventListener('change', () => {
        if (control.checked) setBackgroundMode(control.value);
    }));

    imageFits.forEach((control) => control.addEventListener('change', () => {
        if (control.checked) applyBackgroundImage();
    }));

    imageBlur?.addEventListener('input', () => {
        if (backgroundSurface) backgroundSurface.style.filter = `blur(${imageBlur.value}px)`;
        if (imageBlurValue) imageBlurValue.value = `${imageBlur.value}px`;
    });

    const showImagePreview = (imageUrl) => {
        if (!imageUrl) return;
        applyBackgroundImage();
        if (!backgroundThumbnail) return;
        if (backgroundThumbnail.tagName === 'IMG') {
            backgroundThumbnail.src = imageUrl;
            return;
        }
        const image = document.createElement('img');
        image.className = 'dashboard-background-thumbnail';
        image.src = imageUrl;
        image.alt = 'Selected dashboard background';
        image.dataset.dashboardBackgroundThumbnail = '';
        backgroundThumbnail.replaceWith(image);
        backgroundThumbnail = image;
    };

    if (imageInput && cropDialog && cropCanvas && cropZoomInput && cropSave) {
        const context = cropCanvas.getContext('2d');
        const cropImage = new Image();
        let sourceUrl = '';
        let baseScale = 1;
        let zoom = 1;
        let centerX = cropCanvas.width / 2;
        let centerY = cropCanvas.height / 2;
        let pointer = null;
        let cropIsGif = false;

        const configureCropFrame = () => {
            const bounds = dashboardBackground?.getBoundingClientRect();
            const targetWidth = Math.max(1, bounds?.width || window.innerWidth);
            const targetHeight = Math.max(1, bounds?.height || window.innerHeight);
            const aspectRatio = targetWidth / targetHeight;
            const longSide = 1280;
            if (aspectRatio >= 1) {
                cropCanvas.width = longSide;
                cropCanvas.height = Math.max(1, Math.round(longSide / aspectRatio));
            } else {
                cropCanvas.height = longSide;
                cropCanvas.width = Math.max(1, Math.round(longSide * aspectRatio));
            }
            cropCanvas.style.aspectRatio = `${targetWidth} / ${targetHeight}`;
            centerX = cropCanvas.width / 2;
            centerY = cropCanvas.height / 2;
        };

        const constrainCrop = () => {
            const width = cropImage.naturalWidth * baseScale * zoom;
            const height = cropImage.naturalHeight * baseScale * zoom;
            centerX = Math.min(width / 2, Math.max(cropCanvas.width - width / 2, centerX));
            centerY = Math.min(height / 2, Math.max(cropCanvas.height - height / 2, centerY));
            if (width <= cropCanvas.width) centerX = cropCanvas.width / 2;
            if (height <= cropCanvas.height) centerY = cropCanvas.height / 2;
        };

        const drawCrop = () => {
            constrainCrop();
            const width = cropImage.naturalWidth * baseScale * zoom;
            const height = cropImage.naturalHeight * baseScale * zoom;
            context.clearRect(0, 0, cropCanvas.width, cropCanvas.height);
            context.drawImage(cropImage, centerX - width / 2, centerY - height / 2, width, height);
        };

        const releaseSource = () => {
            if (sourceUrl) URL.revokeObjectURL(sourceUrl);
            sourceUrl = '';
            cropImage.removeAttribute('src');
        };

        const cancelCrop = () => {
            cropDialog.close();
            imageInput.value = '';
            cropSave.disabled = false;
            releaseSource();
            showImagePreview(previewImageUrl || appearancePanel?.dataset.savedImage);
        };

        imageInput.addEventListener('change', () => {
            const file = imageInput.files?.[0];
            if (!file) return;
            if (!['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type)) {
                imageInput.value = '';
                return;
            }
            cropIsGif = file.type === 'image/gif';
            configureCropFrame();
            releaseSource();
            sourceUrl = URL.createObjectURL(file);
            cropSave.disabled = true;
            if (cropStatus) {
                cropStatus.textContent = 'Loading preview...';
                cropStatus.hidden = false;
            }
            if (!cropDialog.open) cropDialog.showModal();
            cropImage.onload = () => {
                baseScale = Math.max(cropCanvas.width / cropImage.naturalWidth, cropCanvas.height / cropImage.naturalHeight);
                zoom = 1;
                cropZoomInput.value = '1';
                centerX = cropCanvas.width / 2;
                centerY = cropCanvas.height / 2;
                drawCrop();
                if (cropStatus) cropStatus.hidden = true;
                cropSave.disabled = false;
            };
            cropImage.onerror = () => {
                if (cropStatus) cropStatus.textContent = 'This image could not be opened. Choose a JPG, PNG, WebP, or GIF image.';
            };
            cropImage.src = sourceUrl;
        });

        cropZoomInput.addEventListener('input', () => {
            zoom = Number(cropZoomInput.value);
            drawCrop();
        });

        cropCanvas.addEventListener('wheel', (event) => {
            event.preventDefault();
            const bounds = cropCanvas.getBoundingClientRect();
            const scale = cropCanvas.width / bounds.width;
            const cursorX = (event.clientX - bounds.left) * scale;
            const cursorY = (event.clientY - bounds.top) * scale;
            const previousZoom = zoom;
            const nextZoom = Math.min(3, Math.max(1, zoom + (event.deltaY < 0 ? 0.1 : -0.1)));
            if (nextZoom === previousZoom) return;
            const ratio = nextZoom / previousZoom;
            centerX = cursorX - (cursorX - centerX) * ratio;
            centerY = cursorY - (cursorY - centerY) * ratio;
            zoom = nextZoom;
            cropZoomInput.value = String(zoom);
            drawCrop();
        }, { passive: false });

        cropCanvas.addEventListener('pointerdown', (event) => {
            pointer = { x: event.clientX, y: event.clientY, centerX, centerY };
            cropCanvas.setPointerCapture(event.pointerId);
        });
        cropCanvas.addEventListener('pointermove', (event) => {
            if (!pointer) return;
            const scale = cropCanvas.width / cropCanvas.getBoundingClientRect().width;
            centerX = pointer.centerX + (event.clientX - pointer.x) * scale;
            centerY = pointer.centerY + (event.clientY - pointer.y) * scale;
            drawCrop();
        });
        cropCanvas.addEventListener('pointerup', () => { pointer = null; });
        cropCanvas.addEventListener('pointercancel', () => { pointer = null; });
        cropCancelButtons.forEach((button) => button.addEventListener('click', cancelCrop));
        cropDialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            cancelCrop();
        });
        cropDialog.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' || cropSave.disabled) return;
            event.preventDefault();
            cropSave.click();
        });
        cropSave.addEventListener('click', () => {
            cropSave.disabled = true;
            if (cropIsGif) {
                const width = cropImage.naturalWidth * baseScale * zoom;
                const height = cropImage.naturalHeight * baseScale * zoom;
                const positionX = Math.min(100, Math.max(0, ((cropCanvas.width / 2 - (centerX - width / 2)) / width) * 100));
                const positionY = Math.min(100, Math.max(0, ((cropCanvas.height / 2 - (centerY - height / 2)) / height) * 100));
                if (positionXInput) positionXInput.value = positionX.toFixed(2);
                if (positionYInput) positionYInput.value = positionY.toFixed(2);
                if (imageZoomValue) imageZoomValue.value = zoom.toFixed(2);
                if (previewImageUrl) URL.revokeObjectURL(previewImageUrl);
                previewImageUrl = URL.createObjectURL(imageInput.files[0]);
                showImagePreview(previewImageUrl);
                cropDialog.close();
                releaseSource();
                cropSave.disabled = false;
                return;
            }
            cropCanvas.toBlob((blob) => {
                if (!blob) {
                    cropSave.disabled = false;
                    return;
                }
                const transfer = new DataTransfer();
                transfer.items.add(new File([blob], 'dashboard-background.jpg', { type: 'image/jpeg' }));
                imageInput.files = transfer.files;
                if (positionXInput) positionXInput.value = '50';
                if (positionYInput) positionYInput.value = '50';
                if (imageZoomValue) imageZoomValue.value = '1';
                if (previewImageUrl) URL.revokeObjectURL(previewImageUrl);
                previewImageUrl = URL.createObjectURL(blob);
                showImagePreview(previewImageUrl);
                cropDialog.close();
                releaseSource();
                cropSave.disabled = false;
            }, 'image/jpeg', 0.92);
        });
    }

    const readAppearanceResponse = async (response) => {
        const responseText = await response.text();
        try {
            return JSON.parse(responseText);
        } catch {
            throw new Error(response.ok
                ? 'The background may have saved, but the server returned an invalid response. Refresh once to check it.'
                : `The background could not be saved (server error ${response.status}).`);
        }
    };

    const applySavedAppearance = (data) => {
        appearancePanel.dataset.savedImage = data.imageUrl || '';
        if (backgroundColor) backgroundColor.value = data.backgroundColor;
        if (backgroundValue) backgroundValue.value = data.backgroundColor.toUpperCase();
        if (dashboardBackground) dashboardBackground.style.backgroundColor = data.backgroundColor;
        if (positionXInput) positionXInput.value = String(data.positionX);
        if (positionYInput) positionYInput.value = String(data.positionY);
        if (imageZoomValue) imageZoomValue.value = String(data.zoom);
        const savedFit = imageFits.find((control) => control.value === data.imageFit);
        if (savedFit) savedFit.checked = true;
        if (imageBlur) imageBlur.value = String(data.imageBlur);
        if (imageBlurValue) imageBlurValue.value = `${data.imageBlur}px`;
        if (previewImageUrl) {
            URL.revokeObjectURL(previewImageUrl);
            previewImageUrl = '';
        }
        if (backgroundPreview) backgroundPreview.src = data.imageUrl || '';
        if (imageInput) imageInput.value = '';
        if (data.imageUrl) {
            showImagePreview(data.imageUrl);
        } else {
            if (backgroundSurface) {
                backgroundSurface.hidden = true;
                backgroundSurface.style.backgroundImage = 'none';
                backgroundSurface.style.filter = 'none';
            }
            if (backgroundThumbnail?.tagName === 'IMG') {
                const emptyThumbnail = document.createElement('div');
                emptyThumbnail.className = 'dashboard-background-thumbnail is-empty';
                emptyThumbnail.textContent = 'No image selected';
                emptyThumbnail.dataset.dashboardBackgroundThumbnail = '';
                backgroundThumbnail.replaceWith(emptyThumbnail);
                backgroundThumbnail = emptyThumbnail;
            }
        }
        if (imageRemove) imageRemove.hidden = !data.imageUrl;
        const savedMode = backgroundModes.find((control) => control.value === data.backgroundType);
        if (savedMode) savedMode.checked = true;
        setBackgroundMode(data.backgroundType);
    };

    const postAppearanceAction = async (action) => {
        const formData = new FormData(appearanceForm);
        formData.set('action', action);
        formData.set('response_format', 'json');
        formData.delete('background_image');
        const response = await fetch(appearanceForm.getAttribute('action') || window.location.href, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        const data = await readAppearanceResponse(response);
        if (!response.ok || !data.ok) throw new Error(data.error || 'The background could not be updated.');
        applySavedAppearance(data);
        if (appearanceStatus) appearanceStatus.textContent = '';
        showToast(data.notice || 'Background updated.');
    };

    imageRemove?.addEventListener('click', async () => {
        imageRemove.disabled = true;
        try {
            await postAppearanceAction('remove_dashboard_background_image');
        } catch (error) {
            if (appearanceStatus) {
                appearanceStatus.textContent = error.message || 'The background image could not be removed.';
                appearanceStatus.classList.add('is-error');
            }
        } finally {
            imageRemove.disabled = false;
        }
    });

    const closeResetDialog = () => resetDialog?.close();
    resetOpen?.addEventListener('click', () => resetDialog?.showModal());
    resetCancelButtons.forEach((button) => button.addEventListener('click', closeResetDialog));
    resetDialog?.addEventListener('cancel', (event) => {
        event.preventDefault();
        closeResetDialog();
    });
    resetConfirm?.addEventListener('click', async () => {
        resetConfirm.disabled = true;
        try {
            await postAppearanceAction('reset_dashboard_background');
            closeResetDialog();
        } catch (error) {
            closeResetDialog();
            if (appearanceStatus) {
                appearanceStatus.textContent = error.message || 'The dashboard settings could not be reset.';
                appearanceStatus.classList.add('is-error');
            }
        } finally {
            resetConfirm.disabled = false;
        }
    });

    copySettings?.addEventListener('click', async () => {
        copySettings.disabled = true;
        if (appearanceStatus) {
            appearanceStatus.textContent = 'Copying settings...';
            appearanceStatus.classList.remove('is-error');
        }
        try {
            await postAppearanceAction('copy_dashboard_background');
        } catch (error) {
            if (appearanceStatus) {
                appearanceStatus.textContent = error.message || 'The appearance settings could not be copied.';
                appearanceStatus.classList.add('is-error');
            }
        } finally {
            copySettings.disabled = false;
        }
    });

    if (appearanceForm && appearancePanel) {
        appearanceForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const submitButton = appearanceForm.querySelector('button[type="submit"]');
            if (submitButton) submitButton.disabled = true;
            if (appearanceStatus) {
                appearanceStatus.textContent = 'Saving...';
                appearanceStatus.classList.remove('is-error');
            }

            try {
                const formData = new FormData(appearanceForm);
                formData.set('response_format', 'json');
                const response = await fetch(appearanceForm.getAttribute('action') || window.location.href, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                });
                const data = await readAppearanceResponse(response);
                if (!response.ok || !data.ok) throw new Error(data.error || 'The background could not be saved.');
                applySavedAppearance(data);
                if (appearanceStatus) appearanceStatus.textContent = '';
                showToast('Background saved.');
            } catch (error) {
                if (appearanceStatus) {
                    appearanceStatus.textContent = error.message || 'The background could not be saved.';
                    appearanceStatus.classList.add('is-error');
                }
            } finally {
                if (submitButton) submitButton.disabled = false;
            }
        });
    }

});
