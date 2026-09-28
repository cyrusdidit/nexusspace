document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.querySelector('[data-profile-appearance-toggle]');
    const panel = document.querySelector('[data-profile-appearance-panel]');
    const close = document.querySelector('[data-profile-appearance-close]');
    const wallpaper = document.querySelector('[data-profile-wallpaper]');
    const color = document.querySelector('[data-profile-wallpaper-color]');
    const colorValue = document.querySelector('[data-profile-wallpaper-value]');
    const modes = [...document.querySelectorAll('[data-profile-wallpaper-mode]')];
    const colorOption = document.querySelector('[data-profile-wallpaper-color-option]');
    const imageOption = document.querySelector('[data-profile-wallpaper-image-option]');
    const imageInput = document.querySelector('[data-profile-wallpaper-image]');
    let thumbnail = document.querySelector('[data-profile-wallpaper-thumbnail]');
    let previewImageUrl = '';
    if (!toggle || !panel || !close) return;

    const setOpen = (open) => {
        document.body.classList.toggle('is-profile-appearance-open', open);
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', String(open));
        if (open) close.focus();
    };

    toggle.addEventListener('click', () => setOpen(panel.hidden));
    close.addEventListener('click', () => {
        setOpen(false);
        toggle.focus();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || panel.hidden) return;
        setOpen(false);
        toggle.focus();
    });

    color?.addEventListener('input', () => {
        if (wallpaper) wallpaper.style.backgroundColor = color.value;
        if (colorValue) colorValue.value = color.value;
    });

    const setMode = (mode) => {
        if (colorOption) colorOption.hidden = mode !== 'color';
        if (imageOption) imageOption.hidden = mode !== 'image';
        if (!wallpaper) return;
        if (mode === 'color') {
            wallpaper.style.backgroundImage = 'none';
        } else {
            const imageUrl = previewImageUrl || panel.dataset.savedImage;
            wallpaper.style.backgroundImage = imageUrl ? `url("${imageUrl}")` : 'none';
        }
    };

    modes.forEach((mode) => mode.addEventListener('change', () => {
        if (mode.checked) setMode(mode.value);
    }));

    imageInput?.addEventListener('change', () => {
        const file = imageInput.files?.[0];
        if (!file || !file.type.startsWith('image/')) return;
        if (previewImageUrl) URL.revokeObjectURL(previewImageUrl);
        previewImageUrl = URL.createObjectURL(file);
        if (wallpaper) wallpaper.style.backgroundImage = `url("${previewImageUrl}")`;
        if (thumbnail) {
            if (thumbnail.tagName === 'IMG') {
                thumbnail.src = previewImageUrl;
            } else {
                const image = document.createElement('img');
                image.className = 'profile-wallpaper-thumbnail';
                image.src = previewImageUrl;
                image.alt = 'Selected profile wallpaper';
                image.dataset.profileWallpaperThumbnail = '';
                thumbnail.replaceWith(image);
                thumbnail = image;
            }
        }
    });

    if (panel.dataset.open === 'true') setOpen(true);
});
