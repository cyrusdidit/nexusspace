document.addEventListener('DOMContentLoaded', () => {
    const chatForm = document.querySelector('[data-chat-form]');

    if (!chatForm) {
        return;
    }

    chatForm.addEventListener('submit', (event) => {
        event.preventDefault();

        const messageInput = chatForm.elements.message;

        if (messageInput.value.trim() === '') {
            return;
        }

        // Messaging is a later feature. For now, a submitted placeholder message vanishes.
        messageInput.value = '';
    });
});
