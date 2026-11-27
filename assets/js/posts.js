document.addEventListener('DOMContentLoaded', () => {
    const endpoint = document.body.dataset.postActionsEndpoint;
    const updatesEndpoint = document.body.dataset.postUpdatesEndpoint;
    const csrfToken = document.body.dataset.postCsrf;
    const context = document.body.dataset.postContext || 'dashboard';
    const loadMoreButton = document.querySelector('[data-post-load-more]');
    const liveChannel = 'BroadcastChannel' in window ? new BroadcastChannel('nexusspace-posts') : null;
    if (!endpoint || !csrfToken) return;
    let postDeleteDialog = null;

    const confirmPostDeletion = (card) => new Promise((resolve) => {
        if (!postDeleteDialog) {
            const dialog = document.createElement('dialog');
            const heading = document.createElement('h2');
            const message = document.createElement('p');
            const actions = document.createElement('div');
            const cancel = document.createElement('button');
            const confirm = document.createElement('button');
            dialog.className = 'post-delete-dialog';
            dialog.setAttribute('aria-labelledby', 'post-delete-heading');
            dialog.setAttribute('aria-describedby', 'post-delete-description');
            heading.id = 'post-delete-heading';
            heading.textContent = 'Delete this post?';
            message.id = 'post-delete-description';
            message.dataset.postDeleteMessage = '';
            actions.className = 'post-delete-actions';
            cancel.type = 'button';
            cancel.textContent = 'Cancel';
            confirm.type = 'button';
            confirm.className = 'post-delete-confirm';
            confirm.textContent = 'Delete post';
            actions.append(cancel, confirm);
            dialog.append(heading, message, actions);
            document.body.append(dialog);
            cancel.addEventListener('click', () => dialog.close('cancel'));
            confirm.addEventListener('click', () => dialog.close('delete'));
            dialog.addEventListener('click', (event) => {
                if (event.target === dialog) dialog.close('cancel');
            });
            postDeleteDialog = dialog;
        }

        const title = card.querySelector('[data-post-title]')?.textContent.trim();
        const message = postDeleteDialog.querySelector('[data-post-delete-message]');
        message.textContent = title
            ? `Delete "${title}" and all its comments? This cannot be undone.`
            : 'Delete this post and all its comments? This cannot be undone.';
        postDeleteDialog.returnValue = '';
        postDeleteDialog.addEventListener('close', () => resolve(postDeleteDialog.returnValue === 'delete'), { once: true });
        postDeleteDialog.showModal();
        postDeleteDialog.querySelector('button')?.focus();
    });

    const updatePostEditLocks = (action) => {
        const locked = Boolean(action);
        document.querySelectorAll('[data-post-edit]').forEach((button) => {
            button.disabled = locked;
            button.title = locked ? (action === 'compose' ? 'Finish or discard the current post draft first.' : 'Finish or cancel the current post edit first.') : '';
        });
        document.querySelectorAll('[data-post-action-lock-status]').forEach((status) => {
            status.textContent = action === 'compose'
                ? 'Finish or discard this post draft before editing another post.'
                : 'Finish or cancel the current post edit before starting another post.';
            status.hidden = !locked;
        });
    };
    document.addEventListener('nexus:post-action-change', (event) => updatePostEditLocks(event.detail?.action || ''));
    const announcePostAction = (action, card = null) => {
        if (action) document.body.dataset.postActionActive = action;
        else delete document.body.dataset.postActionActive;
        document.dispatchEvent(new CustomEvent('nexus:post-action-change', { detail: { action, card } }));
    };
    updatePostEditLocks(document.body.dataset.postActionActive || '');

    const request = async (card, action, values = {}) => {
        const formData = new FormData();
        formData.set('action', action);
        formData.set('post_id', card.dataset.postId);
        formData.set('csrf_token', csrfToken);
        formData.set('context', context);
        Object.entries(values).forEach(([key, value]) => formData.set(key, value));
        const response = await fetch(endpoint, { method: 'POST', body: formData, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const data = await response.json().catch(() => ({ ok: false, error: 'The server returned an invalid response.' }));
        if (!response.ok || !data.ok) {
            const error = new Error(data.error || 'The post could not be updated.');
            error.status = response.status;
            throw error;
        }
        return data;
    };
    const uploadRequest = (card, action, files, values = {}, onProgress = () => {}) => new Promise((resolve, reject) => {
        const formData = new FormData();
        formData.set('action', action);
        formData.set('post_id', card.dataset.postId);
        formData.set('csrf_token', csrfToken);
        formData.set('context', context);
        Object.entries(values).forEach(([key, value]) => formData.set(key, value));
        files.forEach((file) => formData.append(action === 'add_media' ? 'media[]' : 'media', file));
        const xhr = new XMLHttpRequest();
        xhr.open('POST', endpoint);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) onProgress(Math.round((event.loaded / event.total) * 100));
        });
        xhr.addEventListener('load', () => {
            let data;
            try { data = JSON.parse(xhr.responseText); } catch { data = { ok: false, error: 'The server returned an invalid upload response.' }; }
            if (xhr.status < 200 || xhr.status >= 300 || !data.ok) reject(new Error(data.error || 'The media upload failed.'));
            else resolve(data);
        });
        xhr.addEventListener('error', () => reject(new Error('The upload connection failed. Please try again.')));
        xhr.send(formData);
    });

    const showError = (card, error) => {
        const status = card.querySelector('[data-post-interaction-status]');
        if (!status) return;
        status.textContent = error.message || 'The post could not be updated.';
        status.hidden = false;
    };
    const updateCommentCharacterCount = (textarea) => {
        const counter = textarea.form?.querySelector('[data-comment-character-count]');
        if (!counter) return;
        const limit = Number(textarea.maxLength) || 1000;
        const length = textarea.value.length;
        counter.textContent = `${length}/${limit}`;
        counter.setAttribute('aria-label', `${length} of ${limit} characters`);
        counter.classList.toggle('is-near-limit', length >= Math.ceil(limit * 0.9));
        counter.classList.toggle('is-at-limit', length >= limit);
    };
    const updatePostEditCharacterCount = (field) => {
        const titleField = field.matches('[data-post-edit-title]');
        const selector = titleField ? '[data-post-edit-title-count]' : '[data-post-edit-content-count]';
        const counter = field.form?.querySelector(selector);
        if (!counter) return;
        const limit = Number(field.maxLength) || (titleField ? 100 : 2500);
        const length = field.value.length;
        counter.textContent = `${length}/${limit}`;
        counter.setAttribute('aria-label', `${length} of ${limit} characters`);
        counter.classList.toggle('is-near-limit', length >= Math.ceil(limit * 0.9));
        counter.classList.toggle('is-at-limit', length >= limit);
    };
    document.querySelectorAll('[data-post-edit-title], [data-post-edit-content]').forEach(updatePostEditCharacterCount);
    document.querySelectorAll('[data-comment-character-count]').forEach((counter) => {
        const textarea = counter.closest('form')?.querySelector('textarea[maxlength]');
        if (textarea) updateCommentCharacterCount(textarea);
    });
    const updateInteractions = (card, data, preserveDrafts = false) => {
        if (!data.interactionsHtml) return;
        const drafts = [];
        if (preserveDrafts) {
            card.querySelectorAll('[data-post-interactions] textarea').forEach((textarea) => {
                const form = textarea.form;
                const comment = textarea.closest('[data-comment-id]');
                let type = 'comment';
                if (form?.matches('[data-comment-reply-form]')) type = 'reply';
                if (form?.matches('[data-comment-edit-form]')) type = 'edit';
                if (textarea.value === '' && document.activeElement !== textarea && (type === 'comment' || form?.hidden)) return;
                drafts.push({
                    type,
                    commentId: comment?.dataset.commentId || '',
                    value: textarea.value,
                    open: form ? !form.hidden : true,
                    focused: document.activeElement === textarea,
                    selectionStart: textarea.selectionStart,
                    selectionEnd: textarea.selectionEnd,
                });
            });
        }
        const template = document.createElement('template');
        template.innerHTML = data.interactionsHtml.trim();
        const currentInteractions = card.querySelector('[data-post-interactions]');
        const nextInteractions = template.content.firstElementChild;
        if (preserveDrafts && currentInteractions?.dataset.postVersion === nextInteractions?.dataset.postVersion) return;
        currentInteractions?.replaceWith(nextInteractions);
        drafts.forEach((draft) => {
            let textarea;
            if (draft.type === 'comment') textarea = card.querySelector('[data-post-comment-form] textarea');
            if (draft.type === 'reply') textarea = card.querySelector(`[data-comment-id="${draft.commentId}"] [data-comment-reply-form] textarea`);
            if (draft.type === 'edit') textarea = card.querySelector(`[data-comment-id="${draft.commentId}"] [data-comment-edit-form] textarea`);
            if (!textarea) return;
            textarea.value = draft.value;
            updateCommentCharacterCount(textarea);
            if (draft.open && draft.type !== 'comment') {
                textarea.form.hidden = false;
                if (draft.type === 'edit') {
                    const comment = textarea.closest('[data-comment-id]');
                    comment.querySelector('[data-comment-content]').hidden = true;
                    comment.querySelector('.post-comment-actions').hidden = true;
                }
            }
            if (draft.focused) {
                textarea.focus({ preventScroll: true });
                textarea.setSelectionRange(draft.selectionStart, draft.selectionEnd);
            }
        });
    };
    const updateMedia = (card, data) => {
        if (!Object.prototype.hasOwnProperty.call(data, 'mediaHtml')) return;
        const current = card.querySelector('[data-post-media]');
        if (!data.mediaHtml.trim()) {
            current?.remove();
            return;
        }
        if (current?.dataset.mediaKey === String(data.mediaKey || '')) return;
        const template = document.createElement('template');
        template.innerHTML = data.mediaHtml.trim();
        const next = template.content.firstElementChild;
        if (!next) return;
        if (current) current.replaceWith(next);
        else card.querySelector('[data-post-content]')?.insertAdjacentElement('afterend', next);
    };
    const updateTitle = (card, title) => {
        const value = String(title || '');
        const current = card.querySelector('[data-post-title]');
        if (!value) {
            current?.remove();
            return;
        }
        if (current) {
            current.textContent = value;
            current.hidden = false;
            return;
        }
        const heading = document.createElement('h3');
        heading.className = 'post-title';
        heading.dataset.profileStyle = 'post.title';
        heading.dataset.postTitle = '';
        heading.textContent = value;
        card.querySelector('.post-header')?.insertAdjacentElement('afterend', heading);
    };
    const updatePinState = (card, data) => {
        if (typeof data.pinned !== 'boolean') return;
        card.dataset.postPinned = String(data.pinned);
        const control = card.querySelector('[data-post-pin-toggle]');
        if (control) {
            control.textContent = data.pinned ? 'Unpin' : 'Pin';
            control.setAttribute('aria-pressed', String(data.pinned));
            control.disabled = !data.pinned && Number(data.pinnedCount) >= 3;
            control.title = control.disabled ? 'You can pin up to three posts.' : '';
        }
    };

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.shiftKey || event.isComposing) return;
        if (!event.target.matches('[data-post-comment-form] textarea, [data-comment-reply-form] textarea')) return;
        event.preventDefault();
        event.target.form?.requestSubmit();
    });
    document.addEventListener('input', (event) => {
        const postEditField = event.target.closest?.('[data-post-edit-title], [data-post-edit-content]');
        if (postEditField) updatePostEditCharacterCount(postEditField);
        const textarea = event.target.closest?.('[data-post-comment-form] textarea, [data-comment-reply-form] textarea, [data-comment-edit-form] textarea');
        if (textarea) updateCommentCharacterCount(textarea);
    });

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('button');
        const card = button?.closest('[data-post-card]');
        if (!button || !card) return;
        if (button.matches('[data-post-pin-toggle]')) {
            button.disabled = true;
            try {
                const data = await request(card, 'toggle_post_pin');
                updateInteractions(card, data);
                updatePinState(card, data);
                liveChannel?.postMessage({ postId: card.dataset.postId });
                await refreshPosts();
                const status = card.querySelector('[data-post-interaction-status]');
                if (status) {
                    status.textContent = data.pinned ? 'Post pinned to your profile.' : 'Post unpinned.';
                    status.hidden = false;
                }
            } catch (error) {
                const currentButton = card.querySelector('[data-post-pin-toggle]');
                if (currentButton) currentButton.disabled = false;
                showError(card, error);
            }
            return;
        }
        if (button.matches('[data-comment-focus]')) { card.querySelector('[data-post-comment-form] textarea')?.focus(); return; }
        if (button.matches('[data-post-edit]')) {
            if (document.body.dataset.postActionActive) return;
            announcePostAction('edit', card);
            card.classList.add('is-editing');
            const title = card.querySelector('[data-post-title]');
            if (title) title.hidden = true;
            card.querySelector('[data-post-content]').hidden = true;
            const form = card.querySelector('[data-post-edit-form]');
            form.hidden = false;
            form.querySelector('textarea')?.focus();
            return;
        }
        if (button.matches('[data-post-edit-cancel]')) {
            card.classList.remove('is-editing');
            card.querySelector('[data-post-edit-form]').hidden = true;
            const title = card.querySelector('[data-post-title]');
            if (title) title.hidden = false;
            card.querySelector('[data-post-content]').hidden = false;
            announcePostAction('');
            return;
        }
        if (button.matches('[data-comment-reply]')) {
            const form = button.closest('[data-comment-id]').querySelector('[data-comment-reply-form]');
            form.hidden = !form.hidden;
            if (!form.hidden) form.querySelector('textarea')?.focus();
            return;
        }
        if (button.matches('[data-comment-edit]')) {
            const comment = button.closest('[data-comment-id]');
            comment.querySelector('[data-comment-content]').hidden = true;
            comment.querySelector('.post-comment-actions').hidden = true;
            const form = comment.querySelector('[data-comment-edit-form]');
            form.hidden = false;
            form.querySelector('textarea')?.focus();
            return;
        }
        if (button.matches('[data-comment-edit-cancel]')) {
            const comment = button.closest('[data-comment-id]');
            comment.querySelector('[data-comment-content]').hidden = false;
            comment.querySelector('.post-comment-actions').hidden = false;
            comment.querySelector('[data-comment-edit-form]').hidden = true;
            return;
        }

        let action = '';
        const values = {};
        if (button.matches('[data-comment-pin]')) { action = 'toggle_pin'; values.comment_id = button.closest('[data-comment-id]').dataset.commentId; }
        if (button.matches('[data-comment-delete]')) {
            if (!window.confirm('Delete this comment?')) return;
            action = 'delete_comment'; values.comment_id = button.closest('[data-comment-id]').dataset.commentId;
        }
        if (button.matches('[data-comment-purge]')) {
            if (!window.confirm('Remove this deleted comment from your view?')) return;
            action = 'purge_comment'; values.comment_id = button.closest('[data-comment-id]').dataset.commentId;
        }
        if (button.matches('[data-post-delete]')) {
            if (!await confirmPostDeletion(card)) return;
            action = 'delete_post';
        }
        if (button.matches('[data-post-media-remove]')) {
            if (!window.confirm('Remove this media from the post?')) return;
            action = 'remove_media';
            values.media_id = button.dataset.mediaId;
        }
        if (button.matches('[data-post-media-move]')) {
            action = 'reorder_media';
            values.media_id = button.dataset.mediaId;
            values.direction = button.dataset.postMediaMove;
        }
        if (!action) return;
        button.disabled = true;
        try {
            const data = await request(card, action, values);
            if (data.deleted) card.remove();
            else {
                updateMedia(card, data);
                updateInteractions(card, data);
            }
            liveChannel?.postMessage({ postId: card.dataset.postId });
        } catch (error) { button.disabled = false; showError(card, error); }
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        const card = form.closest?.('[data-post-card]');
        if (!card) return;
        let action = '';
        const values = {};
        if (form.matches('[data-post-edit-form]')) {
            action = 'edit_post';
            values.title = form.querySelector('[data-post-edit-title]').value;
            values.content = form.querySelector('textarea').value;
            values.visibility = form.querySelector('[data-post-edit-visibility]').value;
        }
        else if (form.matches('[data-post-comment-form]')) { action = 'add_comment'; values.content = form.elements.content.value; }
        else if (form.matches('[data-comment-reply-form]')) { action = 'add_comment'; values.content = form.elements.content.value; values.parent_id = form.elements.parent_id.value; }
        else if (form.matches('[data-comment-edit-form]')) { action = 'edit_comment'; values.content = form.querySelector('textarea').value; values.comment_id = form.closest('[data-comment-id]').dataset.commentId; }
        if (!action) return;
        event.preventDefault();
        const submit = form.querySelector('button[type="submit"]');
        if (submit) submit.disabled = true;
        try {
            const data = await request(card, action, values);
            if (action === 'edit_post') {
                updateTitle(card, data.title);
                const content = card.querySelector('[data-post-content]');
                content.textContent = data.content;
                const audience = card.querySelector('[data-post-visibility-label]');
                if (audience) audience.textContent = data.visibility === 'public' ? 'Public' : 'Friends Only';
                content.hidden = false;
                form.hidden = true;
                card.classList.remove('is-editing');
                announcePostAction('');
                if (!card.querySelector('[data-post-edited]')) card.querySelector('.post-meta').insertAdjacentHTML('beforeend', ' &middot; <span data-post-edited>Edited</span>');
            }
            updateInteractions(card, data);
            liveChannel?.postMessage({ postId: card.dataset.postId });
        } catch (error) { if (submit) submit.disabled = false; showError(card, error); }
    });

    document.addEventListener('change', async (event) => {
        const input = event.target.closest?.('[data-post-media-add], [data-post-media-replace]');
        if (!input) return;
        const card = input.closest('[data-post-card]');
        const files = [...(input.files || [])];
        if (!card || !files.length) return;
        const action = input.matches('[data-post-media-add]') ? 'add_media' : 'replace_media';
        const form = card.querySelector('[data-post-edit-form]');
        const progress = form?.querySelector('[data-post-edit-upload-progress]');
        const status = form?.querySelector('[data-post-edit-upload-status]');
        const invalidFile = files.find((file) => {
            const image = file.type.startsWith('image/');
            const video = file.type === 'video/mp4' || file.type === 'video/webm';
            return (!image && !video) || file.size > (image ? 10 * 1024 * 1024 : 120 * 1024 * 1024);
        });
        if (invalidFile) {
            if (status) status.textContent = 'Use images up to 10MB or MP4/WebM videos up to 120MB.';
            input.value = '';
            return;
        }
        const values = action === 'replace_media' ? { media_id: input.dataset.mediaId } : {};
        if (progress) { progress.value = 0; progress.hidden = false; }
        if (status) status.textContent = 'Uploading...';
        input.disabled = true;
        try {
            const data = await uploadRequest(card, action, files, values, (percent) => {
                if (progress) progress.value = percent;
                if (status) status.textContent = `Uploading ${percent}%`;
            });
            updateMedia(card, data);
            updateInteractions(card, data);
            if (status) status.textContent = action === 'add_media' ? 'Media added.' : 'Media replaced.';
            liveChannel?.postMessage({ postId: card.dataset.postId });
        } catch (error) {
            if (status) status.textContent = error.message;
            showError(card, error);
        } finally {
            input.value = '';
            input.disabled = false;
            if (progress) progress.hidden = true;
        }
    });

    let updateRequestRunning = false;
    const refreshPosts = async () => {
        if (!updatesEndpoint || updateRequestRunning || document.hidden) return;
        const postList = document.querySelector('[data-post-list]');
        if (!postList) return;
        const cards = [...document.querySelectorAll('[data-post-card]')];
        updateRequestRunning = true;
        const formData = new FormData();
        formData.set('post_ids', JSON.stringify(cards.map((card) => Number(card.dataset.postId))));
        formData.set('csrf_token', csrfToken);
        formData.set('context', context);
        if (document.body.dataset.profileUserId) formData.set('profile_user_id', document.body.dataset.profileUserId);
        try {
            const response = await fetch(updatesEndpoint, { method: 'POST', body: formData, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (!response.ok || !data.ok) return;
            const available = new Set(data.availableIds.map(String));
            cards.forEach((card) => { if (!available.has(card.dataset.postId)) card.remove(); });
            data.posts.forEach((post, index) => {
                let card = document.querySelector(`[data-post-card][data-post-id="${post.id}"]`);
                const isNew = !card;
                if (isNew) {
                    const template = document.createElement('template');
                    template.innerHTML = post.cardHtml.trim();
                    card = template.content.firstElementChild;
                }
                const expectedCard = postList.children[index] || null;
                if (expectedCard !== card) postList.insertBefore(card, expectedCard);
                if (isNew) return;
                updateTitle(card, post.title);
                const titleEditor = card.querySelector('[data-post-edit-title]');
                if (titleEditor && titleEditor.form.hidden) {
                    titleEditor.value = post.title;
                    updatePostEditCharacterCount(titleEditor);
                }
                const content = card.querySelector('[data-post-content]');
                if (content && content.textContent !== post.content) {
                    content.textContent = post.content;
                    const editor = card.querySelector('[data-post-edit-form] textarea');
                    if (editor && editor.form.hidden) {
                        editor.value = post.content;
                        updatePostEditCharacterCount(editor);
                    }
                }
                if (post.edited && !card.querySelector('[data-post-edited]')) {
                    card.querySelector('.post-meta')?.insertAdjacentHTML('beforeend', ' &middot; <span data-post-edited>Edited</span>');
                }
                updateMedia(card, post);
                updateInteractions(card, post, true);
                updatePinState(card, post);
            });
            const empty = document.querySelector('[data-post-empty]');
            if (empty) empty.hidden = data.posts.length > 0;
        } catch {
            // The next polling pass retries quietly.
        } finally {
            updateRequestRunning = false;
        }
    };

    let loadMoreRunning = false;
    const loadMorePosts = async () => {
        if (!updatesEndpoint || loadMoreRunning) return;
        const postList = document.querySelector('[data-post-list]');
        const lastCard = postList?.querySelector('[data-post-card]:last-child');
        if (!postList || !lastCard?.dataset.postCreated) return;
        loadMoreRunning = true;
        if (loadMoreButton) { loadMoreButton.disabled = true; loadMoreButton.textContent = 'Loading...'; }
        const formData = new FormData();
        formData.set('csrf_token', csrfToken);
        formData.set('context', context);
        formData.set('mode', 'older');
        formData.set('before_created_at', lastCard.dataset.postCreated);
        formData.set('before_id', lastCard.dataset.postId);
        if (document.body.dataset.profileUserId) formData.set('profile_user_id', document.body.dataset.profileUserId);
        try {
            const response = await fetch(updatesEndpoint, { method: 'POST', body: formData, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || 'Older posts could not be loaded.');
            data.posts.forEach((post) => {
                if (document.querySelector(`[data-post-card][data-post-id="${post.id}"]`)) return;
                const template = document.createElement('template');
                template.innerHTML = post.cardHtml.trim();
                if (template.content.firstElementChild) postList.append(template.content.firstElementChild);
            });
            if (loadMoreButton) loadMoreButton.hidden = !data.hasMore;
        } catch (error) {
            if (loadMoreButton) loadMoreButton.title = error.message;
        } finally {
            loadMoreRunning = false;
            if (loadMoreButton) { loadMoreButton.disabled = false; loadMoreButton.textContent = 'Load more'; }
        }
    };

    liveChannel?.addEventListener('message', refreshPosts);
    loadMoreButton?.addEventListener('click', loadMorePosts);
    window.addEventListener('nexusspace:posts-refresh', refreshPosts);
    window.setTimeout(refreshPosts, 300);
    window.setInterval(refreshPosts, 1500);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshPosts(); });
});
