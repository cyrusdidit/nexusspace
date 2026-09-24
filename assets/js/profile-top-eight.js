const topEightForm = document.querySelector('[data-top-eight-form]');

if (topEightForm) {
    const editButton = topEightForm.querySelector('[data-top-eight-edit]');
    const list = topEightForm.querySelector('[data-top-eight-list]');
    const actions = topEightForm.querySelector('[data-top-eight-actions]');
    const cancelButton = topEightForm.querySelector('[data-top-eight-cancel]');
    const inputs = topEightForm.querySelector('[data-top-eight-inputs]');
    let originalOrder = [];
    let draggedItem = null;

    const setEditing = (editing) => {
        topEightForm.classList.toggle('is-editing', editing);
        actions.hidden = !editing;
        editButton.hidden = editing;
        list.querySelectorAll('[data-top-eight-item]').forEach((item) => {
            item.draggable = editing;
        });
    };

    editButton?.addEventListener('click', () => {
        originalOrder = Array.from(list.children);
        setEditing(true);
    });

    cancelButton?.addEventListener('click', () => {
        originalOrder.forEach((item) => list.append(item));
        setEditing(false);
    });

    list.addEventListener('dragstart', (event) => {
        draggedItem = event.target.closest('[data-top-eight-item]');
        if (!draggedItem || !topEightForm.classList.contains('is-editing')) return;
        draggedItem.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
    });

    list.addEventListener('dragover', (event) => {
        if (!draggedItem) return;
        event.preventDefault();
        const target = event.target.closest('[data-top-eight-item]');
        if (!target || target === draggedItem) return;
        const bounds = target.getBoundingClientRect();
        list.insertBefore(draggedItem, event.clientY < bounds.top + bounds.height / 2 ? target : target.nextSibling);
    });

    list.addEventListener('dragend', () => {
        draggedItem?.classList.remove('is-dragging');
        draggedItem = null;
    });

    topEightForm.addEventListener('submit', () => {
        inputs.replaceChildren();
        Array.from(list.querySelectorAll('[data-top-eight-item]')).slice(0, 8).forEach((item) => {
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
}
