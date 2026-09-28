document.querySelectorAll('[data-unblock-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm('Unblock this person and restore your previous connection and content?')) {
            event.preventDefault();
        }
    });
});
