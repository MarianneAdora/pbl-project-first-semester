(function () {
    const dialog = document.getElementById('avatarCropDialog');
    const frame = document.getElementById('avatarCropFrame');
    const image = document.getElementById('avatarCropImage');
    const zoom = document.getElementById('avatarCropZoom');
    const zoomValue = document.getElementById('avatarCropZoomValue');
    const zoomOut = document.getElementById('avatarCropZoomOut');
    const zoomIn = document.getElementById('avatarCropZoomIn');
    const cancelButton = document.getElementById('avatarCropCancel');
    const applyButton = document.getElementById('avatarCropApply');
    if (!dialog || !frame || !image || !zoom || !zoomValue || !zoomOut || !zoomIn || !cancelButton || !applyButton) return;

    let resolveCrop = null;
    let rejectCrop = null;
    let sourceUrl = '';
    let frameSize = 0;
    let baseScale = 1;
    let displayScale = 1;
    let offsetX = 0;
    let offsetY = 0;
    let drag = null;

    function clampOffsets() {
        const width = image.naturalWidth * displayScale;
        const height = image.naturalHeight * displayScale;
        offsetX = Math.min(0, Math.max(frameSize - width, offsetX));
        offsetY = Math.min(0, Math.max(frameSize - height, offsetY));
    }

    function drawPreview() {
        displayScale = baseScale * Number(zoom.value);
        const width = image.naturalWidth * displayScale;
        const height = image.naturalHeight * displayScale;
        clampOffsets();
        image.style.width = width + 'px';
        image.style.height = height + 'px';
        image.style.transform = 'translate(' + offsetX + 'px, ' + offsetY + 'px)';
        zoomValue.value = Math.round(Number(zoom.value) * 100) + '%';
        zoomValue.textContent = zoomValue.value;
    }

    function fitImage() {
        frameSize = frame.clientWidth;
        if (!frameSize || !image.naturalWidth || !image.naturalHeight) return;
        baseScale = Math.max(frameSize / image.naturalWidth, frameSize / image.naturalHeight);
        zoom.value = '1';
        displayScale = baseScale;
        offsetX = (frameSize - image.naturalWidth * displayScale) / 2;
        offsetY = (frameSize - image.naturalHeight * displayScale) / 2;
        drawPreview();
    }

    function releaseImage() {
        if (sourceUrl) URL.revokeObjectURL(sourceUrl);
        sourceUrl = '';
        image.removeAttribute('src');
        image.onload = null;
        image.onerror = null;
    }

    function settle(blob, error) {
        if (!resolveCrop) return;
        const resolve = resolveCrop;
        const reject = rejectCrop;
        resolveCrop = null;
        rejectCrop = null;
        if (dialog.open) dialog.close();
        drag = null;
        frame.classList.remove('is-dragging');
        releaseImage();
        const picker = document.getElementById('avatarPick');
        if (picker) picker.focus();
        if (error) reject(error);
        else resolve(blob);
    }

    function cropToBlob() {
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = 400;
        const context = canvas.getContext('2d');
        if (!context) return Promise.reject(new Error('Could not prepare the photo crop.'));

        const sourceSize = frameSize / displayScale;
        const sourceX = -offsetX / displayScale;
        const sourceY = -offsetY / displayScale;
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(image, sourceX, sourceY, sourceSize, sourceSize, 0, 0, canvas.width, canvas.height);

        return new Promise((resolve, reject) => {
            canvas.toBlob(blob => {
                if (blob) resolve(blob);
                else reject(new Error('Could not prepare the photo crop.'));
            }, 'image/jpeg', 0.9);
        });
    }

    function zoomAroundCenter() {
        const previousScale = displayScale;
        if (!previousScale) return drawPreview();
        const sourceX = (frameSize / 2 - offsetX) / previousScale;
        const sourceY = (frameSize / 2 - offsetY) / previousScale;
        displayScale = baseScale * Number(zoom.value);
        offsetX = frameSize / 2 - sourceX * displayScale;
        offsetY = frameSize / 2 - sourceY * displayScale;
        drawPreview();
    }

    function nudgeZoom(amount) {
        zoom.value = String(Math.min(Number(zoom.max), Math.max(Number(zoom.min), Number(zoom.value) + amount)));
        zoomAroundCenter();
    }

    zoom.addEventListener('input', zoomAroundCenter);
    zoomOut.addEventListener('click', () => nudgeZoom(-0.2));
    zoomIn.addEventListener('click', () => nudgeZoom(0.2));
    cancelButton.addEventListener('click', () => settle(null));
    applyButton.addEventListener('click', async () => {
        applyButton.disabled = true;
        try {
            settle(await cropToBlob());
        } catch (error) {
            settle(null, error);
        } finally {
            applyButton.disabled = false;
        }
    });

    dialog.addEventListener('cancel', event => {
        event.preventDefault();
        settle(null);
    });
    dialog.addEventListener('close', () => {
        if (resolveCrop) settle(null);
    });
    dialog.addEventListener('click', event => {
        if (event.target === dialog) settle(null);
    });

    frame.addEventListener('pointerdown', event => {
        if (event.button !== 0 && event.pointerType === 'mouse') return;
        drag = { id: event.pointerId, x: event.clientX, y: event.clientY, offsetX, offsetY };
        frame.setPointerCapture(event.pointerId);
        frame.classList.add('is-dragging');
    });
    frame.addEventListener('pointermove', event => {
        if (!drag || drag.id !== event.pointerId) return;
        offsetX = drag.offsetX + event.clientX - drag.x;
        offsetY = drag.offsetY + event.clientY - drag.y;
        drawPreview();
    });
    function endDrag(event) {
        if (!drag || drag.id !== event.pointerId) return;
        drag = null;
        frame.classList.remove('is-dragging');
        if (frame.hasPointerCapture(event.pointerId)) frame.releasePointerCapture(event.pointerId);
    }
    frame.addEventListener('pointerup', endDrag);
    frame.addEventListener('pointercancel', endDrag);

    window.AvatarCropper = {
        open(file) {
            if (resolveCrop) return Promise.reject(new Error('A photo crop is already open.'));
            if (typeof dialog.showModal !== 'function') return Promise.reject(new Error('Photo cropping is not supported in this browser.'));

            return new Promise((resolve, reject) => {
                resolveCrop = resolve;
                rejectCrop = reject;
                try {
                    sourceUrl = URL.createObjectURL(file);
                    image.onload = () => {
                        try {
                            dialog.showModal();
                            fitImage();
                        } catch (error) {
                            settle(null, error);
                        }
                    };
                    image.onerror = () => settle(null, new Error('That file could not be read as a picture.'));
                    image.src = sourceUrl;
                } catch (error) {
                    settle(null, error);
                }
            });
        }
    };
})();
