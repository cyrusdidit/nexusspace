const topEightForm = document.querySelector('[data-top-eight-form]');

if (topEightForm) {
    const editButton = topEightForm.querySelector('[data-top-eight-edit]');
    const list = topEightForm.querySelector('[data-top-eight-list]');
    const picker = topEightForm.querySelector('[data-top-eight-picker]');
    const pool = topEightForm.querySelector('[data-top-eight-pool]');
    const actions = topEightForm.querySelector('[data-top-eight-actions]');
    const cancelButton = topEightForm.querySelector('[data-top-eight-cancel]');
    const inputs = topEightForm.querySelector('[data-top-eight-inputs]');
    let originalTop = [];
    let originalPool = [];
    let draggedItem = null;

    const itemsIn = (container) => Array.from(container?.querySelectorAll(':scope > [data-top-eight-item]') || []);
    const updateControls = () => {
        const full = itemsIn(list).length >= 8;
        pool?.querySelectorAll('[data-top-eight-add]').forEach((button) => { button.disabled = full; });
    };
    const positionPicker = () => {
        if (!picker || picker.hidden) return;
        const bounds = topEightForm.getBoundingClientRect();
        const width = picker.offsetWidth;
        picker.style.left = `${Math.max(8, Math.min(bounds.right + 10, window.innerWidth - width - 8))}px`;
        picker.style.top = `${Math.max(8, Math.min(bounds.top, window.innerHeight - picker.offsetHeight - 8))}px`;
    };
    const setEditing = (editing) => {
        topEightForm.classList.toggle('is-editing', editing);
        actions.hidden = !editing;
        editButton.hidden = editing;
        picker.hidden = !editing;
        topEightForm.querySelectorAll('[data-top-eight-item]').forEach((item) => { item.draggable = editing; });
        updateControls();
        if (editing) positionPicker();
    };
    const moveToTop = (item, before = null) => {
        if (item.parentElement !== list && itemsIn(list).length >= 8) return;
        list.insertBefore(item, before);
        if (!item.querySelector('[data-top-eight-remove]')) {
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'top-eight-remove';
            remove.dataset.topEightRemove = '';
            remove.setAttribute('aria-label', 'Remove from Top 8');
            remove.textContent = '×';
            item.append(remove);
        }
        item.querySelector('[data-top-eight-add]')?.remove();
        updateControls();
    };
    const moveToPool = (item) => {
        pool.append(item);
        item.querySelector('[data-top-eight-remove]')?.remove();
        if (!item.querySelector('[data-top-eight-add]')) {
            const add = document.createElement('button');
            add.type = 'button';
            add.dataset.topEightAdd = '';
            add.setAttribute('aria-label', 'Add to Top 8');
            add.textContent = '✓';
            item.append(add);
        }
        updateControls();
    };

    editButton?.addEventListener('click', () => {
        originalTop = itemsIn(list);
        originalPool = itemsIn(pool);
        setEditing(true);
    });
    cancelButton?.addEventListener('click', () => {
        originalTop.forEach((item) => list.append(item));
        originalPool.forEach((item) => pool.append(item));
        originalTop.forEach((item) => {
            item.querySelector('[data-top-eight-add]')?.remove();
            if (!item.querySelector('[data-top-eight-remove]')) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'top-eight-remove';
                button.dataset.topEightRemove = '';
                button.textContent = '×';
                item.append(button);
            }
        });
        originalPool.forEach((item) => {
            item.querySelector('[data-top-eight-remove]')?.remove();
            if (!item.querySelector('[data-top-eight-add]')) {
                const button = document.createElement('button');
                button.type = 'button';
                button.dataset.topEightAdd = '';
                button.textContent = '✓';
                item.append(button);
            }
        });
        setEditing(false);
    });

    topEightForm.addEventListener('click', (event) => {
        const item = event.target.closest('[data-top-eight-item]');
        if (!item) return;
        if (event.target.closest('[data-top-eight-remove]')) moveToPool(item);
        if (event.target.closest('[data-top-eight-add]')) moveToTop(item);
    });
    topEightForm.addEventListener('dragstart', (event) => {
        draggedItem = event.target.closest('[data-top-eight-item]');
        if (!draggedItem || !topEightForm.classList.contains('is-editing')) return;
        draggedItem.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
    });
    list.addEventListener('dragover', (event) => {
        if (!draggedItem || (draggedItem.parentElement !== list && itemsIn(list).length >= 8)) return;
        event.preventDefault();
        const target = event.target.closest('[data-top-eight-item]');
        if (!target || target === draggedItem) return;
        const bounds = target.getBoundingClientRect();
        moveToTop(draggedItem, event.clientY < bounds.top + bounds.height / 2 ? target : target.nextSibling);
    });
    list.addEventListener('drop', (event) => {
        if (!draggedItem || itemsIn(list).length >= 8 && draggedItem.parentElement !== list) return;
        event.preventDefault();
        if (!event.target.closest('[data-top-eight-item]')) moveToTop(draggedItem);
    });
    pool?.addEventListener('dragover', (event) => { if (draggedItem) event.preventDefault(); });
    pool?.addEventListener('drop', (event) => {
        if (!draggedItem) return;
        event.preventDefault();
        moveToPool(draggedItem);
    });
    topEightForm.addEventListener('dragend', () => {
        draggedItem?.classList.remove('is-dragging');
        draggedItem = null;
    });
    topEightForm.addEventListener('submit', () => {
        inputs.replaceChildren();
        itemsIn(list).slice(0, 8).forEach((item) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'top_eight_slots[]';
            input.value = item.dataset.friendId || '';
            inputs.append(input);
        });
        while (inputs.children.length < 8) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'top_eight_slots[]';
            inputs.append(input);
        }
    });
    window.addEventListener('resize', positionPicker);
}
