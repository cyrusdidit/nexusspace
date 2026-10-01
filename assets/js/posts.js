document.addEventListener('DOMContentLoaded', () => {
    const endpoint = document.body.dataset.postActionsEndpoint;
    const updatesEndpoint = document.body.dataset.postUpdatesEndpoint;
    const csrfToken = document.body.dataset.postCsrf;
    const context = document.body.dataset.postContext || 'dashboard';
    const liveChannel = 'BroadcastChannel' in window ? new BroadcastChannel('nexusspace-posts') : null;
    if (!endpoint || !csrfToken) return;

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

    const showError = (card, error) => {
        const status = card.querySelector('[data-post-interaction-status]');
        if (!status) return;
        status.textContent = error.message || 'The post could not be updated.';
        status.hidden = false;
    };
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

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.shiftKey || event.isComposing) return;
        if (!event.target.matches('[data-post-comment-form] textarea, [data-comment-reply-form] textarea')) return;
        event.preventDefault();
        event.target.form?.requestSubmit();
    });

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('button');
        const card = button?.closest('[data-post-card]');
        if (!button || !card) return;
        if (button.matches('[data-comment-focus]')) { card.querySelector('[data-post-comment-form] textarea')?.focus(); return; }
        if (button.matches('[data-post-edit]')) {
            card.querySelector('[data-post-content]').hidden = true;
            const form = card.querySelector('[data-post-edit-form]');
            form.hidden = false;
            form.querySelector('textarea')?.focus();
            return;
        }
        if (button.matches('[data-post-edit-cancel]')) {
            card.querySelector('[data-post-edit-form]').hidden = true;
            card.querySelector('[data-post-content]').hidden = false;
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
        if (button.matches('[data-post-like]')) action = 'toggle_like';
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
            if (!window.confirm('Delete this post and all of its comments?')) return;
            action = 'delete_post';
        }
        if (button.matches('[data-post-media-remove]')) {
            if (!window.confirm('Remove this media from the post?')) return;
            action = 'remove_media';
            values.media_id = button.dataset.mediaId;
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
        if (form.matches('[data-post-edit-form]')) { action = 'edit_post'; values.content = form.querySelector('textarea').value; }
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
                const content = card.querySelector('[data-post-content]');
                content.textContent = data.content;
                content.hidden = false;
                form.hidden = true;
                if (!card.querySelector('[data-post-edited]')) card.querySelector('.post-meta').insertAdjacentHTML('beforeend', ' &middot; <span data-post-edited>Edited</span>');
            }
            updateInteractions(card, data);
            liveChannel?.postMessage({ postId: card.dataset.postId });
        } catch (error) { if (submit) submit.disabled = false; showError(card, error); }
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
                const content = card.querySelector('[data-post-content]');
                if (content && content.textContent !== post.content) {
                    content.textContent = post.content;
                    const editor = card.querySelector('[data-post-edit-form] textarea');
                    if (editor && editor.form.hidden) editor.value = post.content;
                }
                if (post.edited && !card.querySelector('[data-post-edited]')) {
                    card.querySelector('.post-meta')?.insertAdjacentHTML('beforeend', ' &middot; <span data-post-edited>Edited</span>');
                }
                updateMedia(card, post);
                updateInteractions(card, post, true);
            });
            const empty = document.querySelector('[data-post-empty]');
            if (empty) empty.hidden = data.posts.length > 0;
        } catch {
            // The next polling pass retries quietly.
        } finally {
            updateRequestRunning = false;
        }
    };

    liveChannel?.addEventListener('message', refreshPosts);
    window.setTimeout(refreshPosts, 300);
    window.setInterval(refreshPosts, 1500);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshPosts(); });
});
