document.addEventListener('DOMContentLoaded', () => {
    const reset = document.querySelector('[data-customization-reset]');
    reset?.addEventListener('click', (event) => {
        if (!window.confirm('Restore the default profile template and remove saved CSS?')) event.preventDefault();
    });
});
