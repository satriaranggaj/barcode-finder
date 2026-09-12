export const imageSettings = Object.freeze({
    reference: { maxEdge: 2560, quality: 0.90 },
    query: { maxEdge: 1920, quality: 0.88 },
    type: 'image/webp', maxBytes: 10 * 1024 * 1024, maxFiles: 10,
});

export function resizedDimensions(width, height, maxEdge) {
    const scale = Math.min(1, maxEdge / Math.max(width, height));
    return [Math.max(1, Math.round(width * scale)), Math.max(1, Math.round(height * scale))];
}

const cache = new WeakMap();
async function decode(file) {
    if (typeof createImageBitmap === 'function') {
        return createImageBitmap(file, { imageOrientation: 'from-image' });
    }
    const url = URL.createObjectURL(file);
    const image = new Image();
    try {
        image.src = url;
        await image.decode();
        return image;
    } finally { URL.revokeObjectURL(url); }
}

export async function compressImage(file, mode = 'reference') {
    if (!file.size || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        throw new Error('Pilih foto JPEG, PNG, atau WebP yang valid.');
    }
    const settings = imageSettings[mode];
    if (!settings) throw new Error('Pengaturan gambar tidak valid.');
    const previous = cache.get(file)?.[mode];
    if (previous) return previous;
    // Decode failure is never treated as a compression-only failure.
    let image;
    try { image = await decode(file); }
    catch { throw new Error('Foto tidak dapat dibaca. Pilih foto lain.'); }
    let output = file;
    let canvas;
    try {
        const width = image.width || image.naturalWidth;
        const height = image.height || image.naturalHeight;
        const [w, h] = resizedDimensions(width, height, settings.maxEdge);
        if (!(w === width && h === height && file.size <= 512 * 1024)) {
            canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            const context = canvas.getContext('2d');
            if (!context) throw new Error('Canvas unavailable');
            context.drawImage(image, 0, 0, w, h);
            // PNG stays lossless, including its alpha channel.
            const type = file.type === 'image/png' ? 'image/png' : imageSettings.type;
            const blob = await new Promise((resolve, reject) => canvas.toBlob(
                (value) => value?.size ? resolve(value) : reject(new Error('Encoding failed')),
                type, settings.quality,
            ));
            if (!['image/png', 'image/jpeg', 'image/webp'].includes(blob.type)) throw new Error('Unsupported encoder');
            if (blob.size < file.size || width !== w || height !== h) {
                const extension = { 'image/png': 'png', 'image/jpeg': 'jpg', 'image/webp': 'webp' }[blob.type];
                output = new File([blob], `${file.name.replace(/\.[^.]+$/, '')}.${extension}`, { type: blob.type, lastModified: file.lastModified });
            }
        }
    } catch {
        if (file.size > imageSettings.maxBytes) throw new Error('Foto gagal diperkecil dan melebihi batas 10 MB. Pilih foto lebih kecil.');
        output = file;
    } finally {
        image.close?.();
        if (canvas) { canvas.width = 0; canvas.height = 0; }
    }
    if (output.size > imageSettings.maxBytes) throw new Error('Foto masih melebihi 10 MB. Pilih foto lebih kecil.');
    cache.set(file, { ...cache.get(file), [mode]: output });
    cache.set(output, { ...cache.get(output), [mode]: output });
    return output;
}

export function installImageCompression() {
    const inputs = [...document.querySelectorAll('input[type="file"][name="image"], input[type="file"][name="images[]"]')];
    const states = new Map();
    const forms = new Set(inputs.map(input => input.form).filter(Boolean));
    for (const form of forms) {
        const status = document.createElement('p');
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        form.append(status);
        const state = { queue: Promise.resolve(), busy: false, submitting: false, status };
        states.set(form, state);
        form.addEventListener('submit', async event => {
            if (state.submitting) { event.preventDefault(); return; }
            event.preventDefault();
            if (state.busy) return;
            state.busy = true;
            const submitter = event.submitter;
            if (submitter) submitter.disabled = true;
            status.textContent = 'Menyiapkan gambar...';
            try {
                await state.queue;
                for (const input of inputs.filter(input => input.form === form)) await process(input);
                // Bound the whole request as well as each image (PHP post_max_size is separate).
                if (!form.reportValidity()) return;
                state.submitting = true;
                status.textContent = 'Mengirim gambar...';
                // Files in the real input are already compressed; normal navigation preserves
                // Laravel redirects, CSRF, validation errors and browser history.
                HTMLFormElement.prototype.submit.call(form);
            } catch (error) {
                status.textContent = error.message;
            } finally {
                if (!state.submitting) {
                    state.busy = false;
                    if (submitter) submitter.disabled = false;
                }
            }
        });
    }
    async function process(input) {
        const files = [...input.files];
        if (files.length > imageSettings.maxFiles) throw new Error('Maksimal 10 foto sekali upload.');
        const transfer = new DataTransfer();
        for (const file of files) transfer.items.add(await compressImage(file, input.name === 'image' ? 'query' : 'reference'));
        // Do not overwrite a newer selection while compression was running.
        if (files.length !== input.files.length || files.some((file, i) => file !== input.files[i])) return process(input);
        input.files = transfer.files;
        input.dispatchEvent(new CustomEvent('image:prepared', { bubbles: true }));
    }
    for (const input of inputs) {
        input.addEventListener('change', () => {
            const state = states.get(input.form);
            state.status.textContent = 'Menyiapkan gambar...';
            state.queue = state.queue.catch(() => {}).then(() => process(input));
            state.queue.then(() => { if (!state.busy) state.status.textContent = ''; }, error => { state.status.textContent = error.message; });
        });
    }
}
