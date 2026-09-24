const avatarInput = document.getElementById('profile-picture-input');
const cropDialog = document.querySelector('[data-avatar-crop-dialog]');

if (avatarInput && cropDialog) {
    const canvas = cropDialog.querySelector('[data-avatar-crop-canvas]');
    const context = canvas.getContext('2d');
    const zoomInput = cropDialog.querySelector('[data-avatar-crop-zoom]');
    const saveButton = cropDialog.querySelector('[data-avatar-crop-save]');
    const cancelButtons = cropDialog.querySelectorAll('[data-avatar-crop-cancel]');
    const image = new Image();
    let imageUrl = '';
    let baseScale = 1;
    let zoom = 1;
    let centerX = canvas.width / 2;
    let centerY = canvas.height / 2;
    let pointer = null;

    const constrainPosition = () => {
        const width = image.naturalWidth * baseScale * zoom;
        const height = image.naturalHeight * baseScale * zoom;
        const halfCanvas = canvas.width / 2;
        centerX = Math.min(width / 2, Math.max(canvas.width - width / 2, centerX));
        centerY = Math.min(height / 2, Math.max(canvas.height - height / 2, centerY));
        if (width <= canvas.width) centerX = halfCanvas;
        if (height <= canvas.height) centerY = halfCanvas;
    };

    const draw = () => {
        constrainPosition();
        const width = image.naturalWidth * baseScale * zoom;
        const height = image.naturalHeight * baseScale * zoom;
        context.clearRect(0, 0, canvas.width, canvas.height);
        context.drawImage(image, centerX - width / 2, centerY - height / 2, width, height);
    };

    const close = () => {
        cropDialog.close();
        avatarInput.value = '';
        if (imageUrl) URL.revokeObjectURL(imageUrl);
        imageUrl = '';
    };

    avatarInput.addEventListener('change', () => {
        const file = avatarInput.files[0];
        if (!file) return;
        if (!file.type.startsWith('image/')) {
            avatarInput.value = '';
            return;
        }
        if (imageUrl) URL.revokeObjectURL(imageUrl);
        imageUrl = URL.createObjectURL(file);
        image.onload = () => {
            baseScale = Math.max(canvas.width / image.naturalWidth, canvas.height / image.naturalHeight);
            zoom = 1;
            zoomInput.value = '1';
            centerX = canvas.width / 2;
            centerY = canvas.height / 2;
            draw();
            cropDialog.showModal();
        };
        image.src = imageUrl;
    });

    zoomInput.addEventListener('input', () => {
        zoom = Number(zoomInput.value);
        draw();
    });

    canvas.addEventListener('wheel', (event) => {
        event.preventDefault();
        const bounds = canvas.getBoundingClientRect();
        const canvasScale = canvas.width / bounds.width;
        const cursorX = (event.clientX - bounds.left) * canvasScale;
        const cursorY = (event.clientY - bounds.top) * canvasScale;
        const previousZoom = zoom;
        const nextZoom = Math.min(3, Math.max(1, zoom + (event.deltaY < 0 ? 0.1 : -0.1)));
        if (nextZoom === previousZoom) return;
        const ratio = nextZoom / previousZoom;
        centerX = cursorX - (cursorX - centerX) * ratio;
        centerY = cursorY - (cursorY - centerY) * ratio;
        zoom = nextZoom;
        zoomInput.value = String(zoom);
        draw();
    }, { passive: false });

    canvas.addEventListener('pointerdown', (event) => {
        pointer = { x: event.clientX, y: event.clientY, centerX, centerY };
        canvas.setPointerCapture(event.pointerId);
    });
    canvas.addEventListener('pointermove', (event) => {
        if (!pointer) return;
        const scale = canvas.width / canvas.getBoundingClientRect().width;
        centerX = pointer.centerX + (event.clientX - pointer.x) * scale;
        centerY = pointer.centerY + (event.clientY - pointer.y) * scale;
        draw();
    });
    canvas.addEventListener('pointerup', () => { pointer = null; });
    canvas.addEventListener('pointercancel', () => { pointer = null; });

    cancelButtons.forEach((button) => button.addEventListener('click', close));
    cropDialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        close();
    });
    cropDialog.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || saveButton.disabled) return;
        event.preventDefault();
        saveButton.click();
    });

    saveButton.addEventListener('click', () => {
        saveButton.disabled = true;
        canvas.toBlob((blob) => {
            if (!blob) {
                saveButton.disabled = false;
                return;
            }
            const transfer = new DataTransfer();
            transfer.items.add(new File([blob], 'profile-picture.jpg', { type: 'image/jpeg' }));
            avatarInput.files = transfer.files;
            avatarInput.form.requestSubmit();
        }, 'image/jpeg', 0.92);
    });
}
