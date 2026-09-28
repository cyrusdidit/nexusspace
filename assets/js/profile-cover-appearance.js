document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.querySelector('[data-profile-cover-toggle]');
    const panel = document.querySelector('[data-profile-cover-panel]');
    const close = document.querySelector('[data-profile-cover-close]');
    const cover = document.querySelector('[data-profile-cover]');
    const surface = document.querySelector('[data-profile-cover-surface]');
    const preview = document.querySelector('[data-profile-cover-preview]');
    const color = document.querySelector('[data-profile-cover-color]');
    const colorValue = document.querySelector('[data-profile-cover-value]');
    const modes = [...document.querySelectorAll('[data-profile-cover-mode]')];
    const colorOption = document.querySelector('[data-profile-cover-color-option]');
    const imageOption = document.querySelector('[data-profile-cover-image-option]');
    const imageInput = document.querySelector('[data-profile-cover-image]');
    const imageFits = [...document.querySelectorAll('[data-profile-cover-fit]')];
    const positionXInput = document.querySelector('[data-profile-cover-position-x]');
    const positionYInput = document.querySelector('[data-profile-cover-position-y]');
    const zoomValueInput = document.querySelector('[data-profile-cover-zoom-value]');
    const blurInput = document.querySelector('[data-profile-cover-blur]');
    const blurValue = document.querySelector('[data-profile-cover-blur-value]');
    const cropDialog = document.querySelector('[data-profile-cover-crop-dialog]');
    const cropCanvas = document.querySelector('[data-profile-cover-crop-canvas]');
    const cropZoomInput = document.querySelector('[data-profile-cover-crop-zoom]');
    const cropSave = document.querySelector('[data-profile-cover-crop-save]');
    const cropCancelButtons = [...document.querySelectorAll('[data-profile-cover-crop-cancel]')];
    const cropStatus = document.querySelector('[data-profile-cover-crop-status]');
    const resetOpen = document.querySelector('[data-profile-cover-reset-open]');
    const resetDialog = document.querySelector('[data-profile-cover-reset-dialog]');
    const resetCancelButtons = [...document.querySelectorAll('[data-profile-cover-reset-cancel]')];
    let thumbnail = document.querySelector('[data-profile-cover-thumbnail]');
    let previewImageUrl = '';
    if (!toggle || !panel || !close || !cover) return;

    const closeWallpaperPanel = () => {
        const wallpaperPanel = document.querySelector('[data-profile-appearance-panel]');
        const wallpaperToggle = document.querySelector('[data-profile-appearance-toggle]');
        if (wallpaperPanel) wallpaperPanel.hidden = true;
        if (wallpaperToggle) wallpaperToggle.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('is-profile-appearance-open');
    };
    const setOpen = (open) => {
        if (open) closeWallpaperPanel();
        document.body.classList.toggle('is-profile-cover-open', open);
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', String(open));
        if (open) close.focus();
    };

    toggle.addEventListener('click', () => setOpen(panel.hidden));
    close.addEventListener('click', () => {
        setOpen(false);
        toggle.focus();
    });

    const selectedFit = () => imageFits.find((control) => control.checked)?.value || 'cover';
    const applyImageLayout = () => {
        if (!surface || !preview) return;
        const imageUrl = previewImageUrl || panel.dataset.savedImage;
        surface.hidden = !imageUrl;
        if (!imageUrl) return;
        const fit = selectedFit();
        if (fit === 'tile') {
            preview.hidden = true;
            surface.style.backgroundImage = `url("${imageUrl}")`;
            surface.style.backgroundRepeat = 'repeat';
            surface.style.backgroundSize = 'auto';
        } else {
            surface.style.backgroundImage = 'none';
            preview.hidden = false;
            preview.src = imageUrl;
            preview.style.objectFit = fit === 'contain' ? 'contain' : 'cover';
            const positionX = Number(positionXInput?.value || 50);
            const positionY = Number(positionYInput?.value || 50);
            const zoom = Number(zoomValueInput?.value || 1);
            preview.style.objectPosition = `${positionX}% ${positionY}%`;
            preview.style.transform = `scale(${zoom})`;
            preview.style.transformOrigin = `${positionX}% ${positionY}%`;
        }
    };
    const setMode = (mode) => {
        if (colorOption) colorOption.hidden = mode !== 'color';
        if (imageOption) imageOption.hidden = mode !== 'image';
        if (mode === 'color') {
            if (surface) surface.hidden = true;
        } else {
            applyImageLayout();
        }
    };
    const showImagePreview = (imageUrl) => {
        if (!imageUrl) return;
        applyImageLayout();
        if (thumbnail?.tagName === 'IMG') {
            thumbnail.src = imageUrl;
        } else if (thumbnail) {
            const image = document.createElement('img');
            image.className = 'profile-wallpaper-thumbnail profile-cover-thumbnail';
            image.src = imageUrl;
            image.alt = 'Selected profile cover';
            image.dataset.profileCoverThumbnail = '';
            thumbnail.replaceWith(image);
            thumbnail = image;
        }
    };

    color?.addEventListener('input', () => {
        cover.style.backgroundColor = color.value;
        if (colorValue) colorValue.value = color.value;
    });
    modes.forEach((mode) => mode.addEventListener('change', () => {
        if (mode.checked) setMode(mode.value);
    }));
    imageFits.forEach((control) => control.addEventListener('change', () => {
        if (control.checked) applyImageLayout();
    }));
    blurInput?.addEventListener('input', () => {
        if (surface) surface.style.filter = `blur(${blurInput.value}px)`;
        if (blurValue) blurValue.value = `${blurInput.value}px`;
    });

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
            const bounds = cover.getBoundingClientRect();
            const aspectRatio = Math.max(1, bounds.width) / Math.max(1, bounds.height);
            cropCanvas.width = 1280;
            cropCanvas.height = Math.max(1, Math.round(1280 / aspectRatio));
            cropCanvas.style.aspectRatio = `${Math.max(1, bounds.width)} / ${Math.max(1, bounds.height)}`;
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
            showImagePreview(previewImageUrl || panel.dataset.savedImage);
        };

        imageInput.addEventListener('change', () => {
            const file = imageInput.files?.[0];
            if (!file) return;
            if (!file.type.startsWith('image/')) {
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
                if (zoomValueInput) zoomValueInput.value = zoom.toFixed(2);
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
                transfer.items.add(new File([blob], 'profile-cover.jpg', { type: 'image/jpeg' }));
                imageInput.files = transfer.files;
                if (positionXInput) positionXInput.value = '50';
                if (positionYInput) positionYInput.value = '50';
                if (zoomValueInput) zoomValueInput.value = '1';
                if (previewImageUrl) URL.revokeObjectURL(previewImageUrl);
                previewImageUrl = URL.createObjectURL(blob);
                showImagePreview(previewImageUrl);
                cropDialog.close();
                releaseSource();
                cropSave.disabled = false;
            }, 'image/jpeg', 0.92);
        });
    }

    const closeResetDialog = () => resetDialog?.close();
    resetOpen?.addEventListener('click', () => resetDialog?.showModal());
    resetCancelButtons.forEach((button) => button.addEventListener('click', closeResetDialog));
    resetDialog?.addEventListener('cancel', (event) => {
        event.preventDefault();
        closeResetDialog();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || panel.hidden || cropDialog?.open || resetDialog?.open) return;
        setOpen(false);
        toggle.focus();
    });

    if (panel.dataset.open === 'true') setOpen(true);
});
