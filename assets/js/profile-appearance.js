document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.querySelector('[data-profile-appearance-toggle]');
    const panel = document.querySelector('[data-profile-appearance-panel]');
    const close = document.querySelector('[data-profile-appearance-close]');
    const wallpaper = document.querySelector('[data-profile-wallpaper]');
    const wallpaperSurface = document.querySelector('[data-profile-wallpaper-surface]');
    const wallpaperPreview = document.querySelector('[data-profile-wallpaper-preview]');
    const color = document.querySelector('[data-profile-wallpaper-color]');
    const colorValue = document.querySelector('[data-profile-wallpaper-value]');
    const modes = [...document.querySelectorAll('[data-profile-wallpaper-mode]')];
    const colorOption = document.querySelector('[data-profile-wallpaper-color-option]');
    const imageOption = document.querySelector('[data-profile-wallpaper-image-option]');
    const imageInput = document.querySelector('[data-profile-wallpaper-image]');
    const imageFits = [...document.querySelectorAll('[data-profile-wallpaper-fit]')];
    const cropDialog = document.querySelector('[data-profile-wallpaper-crop-dialog]');
    const cropCanvas = document.querySelector('[data-profile-wallpaper-crop-canvas]');
    const cropZoomInput = document.querySelector('[data-profile-wallpaper-crop-zoom]');
    const cropSave = document.querySelector('[data-profile-wallpaper-crop-save]');
    const cropCancelButtons = [...document.querySelectorAll('[data-profile-wallpaper-crop-cancel]')];
    const cropStatus = document.querySelector('[data-profile-wallpaper-crop-status]');
    const positionXInput = document.querySelector('[data-profile-wallpaper-position-x]');
    const positionYInput = document.querySelector('[data-profile-wallpaper-position-y]');
    const zoomValueInput = document.querySelector('[data-profile-wallpaper-zoom-value]');
    const blurInput = document.querySelector('[data-profile-wallpaper-blur]');
    const blurValue = document.querySelector('[data-profile-wallpaper-blur-value]');
    const resetOpen = document.querySelector('[data-profile-wallpaper-reset-open]');
    const wallpaperUndo = document.querySelector('[data-profile-wallpaper-undo]');
    const resetDialog = document.querySelector('[data-profile-wallpaper-reset-dialog]');
    const resetCancelButtons = [...document.querySelectorAll('[data-profile-wallpaper-reset-cancel]')];
    const toast = document.querySelector('[data-profile-customization-toast]');
    const toastClose = document.querySelector('[data-profile-customization-toast-close]');
    const appearanceTabs = [...document.querySelectorAll('[data-profile-appearance-tab]')];
    let thumbnail = document.querySelector('[data-profile-wallpaper-thumbnail]');
    let previewImageUrl = '';
    let initialWallpaperState = null;
    let hasUnsavedWallpaperPreview = false;
    if (!toggle || !panel || !close) return;

    const beginWallpaperPreview = (mode) => {
        hasUnsavedWallpaperPreview = true;
        document.body.classList.add('is-previewing-profile-wallpaper');
        if (wallpaper) wallpaper.dataset.profileWallpaperPreviewMode = mode;
        if (wallpaperUndo) wallpaperUndo.disabled = false;
    };
    const endWallpaperPreview = () => {
        document.body.classList.remove('is-previewing-profile-wallpaper');
        if (wallpaper) delete wallpaper.dataset.profileWallpaperPreviewMode;
    };
    const captureWallpaperState = () => ({
        mode: modes.find((mode) => mode.checked)?.value || 'color',
        color: color?.value || '#dff2ff',
        fit: imageFits.find((control) => control.checked)?.value || 'cover',
        blur: blurInput?.value || '0',
        positionX: positionXInput?.value || '50',
        positionY: positionYInput?.value || '50',
        zoom: zoomValueInput?.value || '1',
    });
    const setOpen = (open) => {
        if (open) {
            const coverPanel = document.querySelector('[data-profile-cover-panel]');
            const coverToggle = document.querySelector('[data-profile-cover-toggle]');
            if (coverPanel) coverPanel.hidden = true;
            if (coverToggle) coverToggle.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('is-profile-cover-open');
        }
        document.body.classList.toggle('is-profile-appearance-open', open);
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', String(open));
        if (open) close.focus();
        else endWallpaperPreview();
    };

    toggle.addEventListener('click', () => setOpen(panel.hidden));
    close.addEventListener('click', () => {
        setOpen(false);
        toggle.focus();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || panel.hidden || cropDialog?.open || resetDialog?.open) return;
        setOpen(false);
        toggle.focus();
    });

    color?.addEventListener('input', () => {
        beginWallpaperPreview('color');
        if (wallpaper) wallpaper.style.backgroundColor = color.value;
        if (colorValue) colorValue.value = color.value;
    });

    const setMode = (mode) => {
        beginWallpaperPreview(mode);
        if (colorOption) colorOption.hidden = mode !== 'color';
        if (imageOption) imageOption.hidden = mode !== 'image';
        if (!wallpaper) return;
        if (mode === 'color') {
            if (wallpaperSurface) wallpaperSurface.hidden = true;
        } else {
            applyImageLayout();
        }
    };

    modes.forEach((mode) => mode.addEventListener('change', () => {
        if (!mode.checked) return;
        setMode(mode.value);
    }));

    const selectedFit = () => imageFits.find((control) => control.checked)?.value || 'cover';
    const applyImageLayout = () => {
        if (!wallpaperSurface || !wallpaperPreview) return;
        beginWallpaperPreview('image');
        const imageUrl = previewImageUrl || panel.dataset.savedImage;
        wallpaperSurface.hidden = !imageUrl;
        if (!imageUrl) return;
        const fit = selectedFit();
        if (wallpaper) wallpaper.dataset.profileWallpaperPreviewFit = fit;
        if (fit === 'tile') {
            wallpaperPreview.hidden = true;
            wallpaperSurface.style.backgroundImage = `url("${imageUrl}")`;
            wallpaperSurface.style.backgroundRepeat = 'repeat';
            wallpaperSurface.style.backgroundSize = 'auto';
        } else {
            wallpaperSurface.style.backgroundImage = 'none';
            wallpaperPreview.hidden = false;
            wallpaperPreview.src = imageUrl;
            wallpaperPreview.style.objectFit = fit === 'contain' ? 'contain' : 'cover';
            const positionX = Number(positionXInput?.value || 50);
            const positionY = Number(positionYInput?.value || 50);
            const imageZoom = Number(zoomValueInput?.value || 1);
            wallpaperPreview.style.objectPosition = `${positionX}% ${positionY}%`;
            wallpaperPreview.style.transform = `scale(${imageZoom})`;
            wallpaperPreview.style.transformOrigin = `${positionX}% ${positionY}%`;
        }
    };

    const showImagePreview = (imageUrl) => {
        if (!imageUrl) return;
        beginWallpaperPreview('image');
        applyImageLayout();
        if (thumbnail) {
            if (thumbnail.tagName === 'IMG') {
                thumbnail.src = imageUrl;
            } else {
                const image = document.createElement('img');
                image.className = 'profile-wallpaper-thumbnail';
                image.src = imageUrl;
                image.alt = 'Selected profile wallpaper';
                image.dataset.profileWallpaperThumbnail = '';
                thumbnail.replaceWith(image);
                thumbnail = image;
            }
        }
    };

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
            const wallpaperBounds = wallpaper?.getBoundingClientRect();
            const panelWidth = window.matchMedia('(min-width: 701px)').matches ? panel.getBoundingClientRect().width : 0;
            const targetWidth = Math.max(1, (wallpaperBounds?.width || window.innerWidth) + panelWidth);
            const targetHeight = Math.max(1, wallpaperBounds?.height || window.innerHeight);
            const aspectRatio = targetWidth / targetHeight;
            const longSide = 1280;
            if (aspectRatio >= 1) {
                cropCanvas.width = longSide;
                cropCanvas.height = Math.max(1, Math.round(longSide / aspectRatio));
            } else {
                cropCanvas.height = longSide;
                cropCanvas.width = Math.max(1, Math.round(longSide * aspectRatio));
            }
            cropCanvas.style.aspectRatio = `${targetWidth} / ${targetHeight}`;
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
                transfer.items.add(new File([blob], 'profile-wallpaper.jpg', { type: 'image/jpeg' }));
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

    imageFits.forEach((control) => control.addEventListener('change', () => {
        if (!control.checked) return;
        beginWallpaperPreview('image');
        applyImageLayout();
    }));

    blurInput?.addEventListener('input', () => {
        beginWallpaperPreview('image');
        if (wallpaperSurface) wallpaperSurface.style.filter = `blur(${blurInput.value}px)`;
        if (blurValue) blurValue.value = `${blurInput.value}px`;
    });

    wallpaperUndo?.addEventListener('click', (event) => {
        if (!hasUnsavedWallpaperPreview || !initialWallpaperState) return;
        event.preventDefault();
        modes.forEach((mode) => { mode.checked = mode.value === initialWallpaperState.mode; });
        if (color) color.value = initialWallpaperState.color;
        if (colorValue) colorValue.value = initialWallpaperState.color;
        if (wallpaper) wallpaper.style.backgroundColor = initialWallpaperState.color;
        imageFits.forEach((control) => { control.checked = control.value === initialWallpaperState.fit; });
        if (blurInput) blurInput.value = initialWallpaperState.blur;
        if (blurValue) blurValue.value = `${initialWallpaperState.blur}px`;
        if (positionXInput) positionXInput.value = initialWallpaperState.positionX;
        if (positionYInput) positionYInput.value = initialWallpaperState.positionY;
        if (zoomValueInput) zoomValueInput.value = initialWallpaperState.zoom;
        if (imageInput) imageInput.value = '';
        if (previewImageUrl) URL.revokeObjectURL(previewImageUrl);
        previewImageUrl = '';
        if (wallpaperSurface) wallpaperSurface.style.filter = `blur(${initialWallpaperState.blur}px)`;
        setMode(initialWallpaperState.mode);
        if (initialWallpaperState.mode === 'image' && panel.dataset.savedImage) showImagePreview(panel.dataset.savedImage);
        if (!panel.dataset.savedImage && thumbnail?.tagName === 'IMG') {
            const emptyThumbnail = document.createElement('div');
            emptyThumbnail.className = 'profile-wallpaper-thumbnail is-empty';
            emptyThumbnail.dataset.profileWallpaperThumbnail = '';
            emptyThumbnail.textContent = 'No image selected';
            thumbnail.replaceWith(emptyThumbnail);
            thumbnail = emptyThumbnail;
        }
        hasUnsavedWallpaperPreview = false;
        endWallpaperPreview();
        wallpaperUndo.disabled = wallpaperUndo.dataset.savedUndo !== 'true';
    });

    const closeResetDialog = () => resetDialog?.close();
    resetOpen?.addEventListener('click', () => resetDialog?.showModal());
    resetCancelButtons.forEach((button) => button.addEventListener('click', closeResetDialog));
    resetDialog?.addEventListener('cancel', (event) => {
        event.preventDefault();
        closeResetDialog();
    });

    appearanceTabs.forEach((tab) => tab.addEventListener('click', () => {
        if (tab.dataset.profileAppearanceTab !== 'wallpaper') endWallpaperPreview();
    }));

    if (toast) {
        const closeToast = () => {
            if (!toast.isConnected || toast.classList.contains('is-hiding')) return;
            toast.classList.add('is-hiding');
            window.setTimeout(() => toast.remove(), 170);
        };
        toastClose?.addEventListener('click', closeToast);
        window.setTimeout(closeToast, 3000);

    }

    const cleanUrl = new URL(window.location.href);
    ['customize', 'cover_customize', 'appearance_saved', 'appearance_image_removed', 'appearance_reset', 'appearance_copied', 'cover_saved', 'cover_image_removed', 'cover_reset', 'cover_copied', 'css_wallpaper_overridden', 'css_wallpaper_restored'].forEach((parameter) => cleanUrl.searchParams.delete(parameter));
    if (cleanUrl.href !== window.location.href) window.history.replaceState({}, '', cleanUrl);

    initialWallpaperState = captureWallpaperState();
    if (panel.dataset.open === 'true') setOpen(true);
});
