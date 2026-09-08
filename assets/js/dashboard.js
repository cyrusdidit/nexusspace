document.addEventListener('DOMContentLoaded', () => {
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
