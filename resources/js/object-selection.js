/**
 * Object selection flow for search and upload forms.
 *
 * Flow: file pick -> render editor -> request auto-selection -> overlay the
 * best proposal. The user can drag, resize, pick another candidate, reset to
 * the auto proposal or switch to the full image; the selected crop is stored
 * in hidden inputs as normalized 0..1 coordinates and forwarded with the form.
 * Auto-selection failure never blocks the form — full image or a manual box
 * always work.
 */
import { ObjectSelectionEditor } from './selection/editor.js';
import { validBox } from './selection/geometry.js';
import { createState, transition, toWire } from './selection/state.js';

const SELECT_TIMEOUT_MS = 15000;

export function initializeObjectSelections() {
    document.querySelectorAll('[data-object-selection]').forEach((container) => {
        const form = container.closest('form') || document.getElementById(container.dataset.form);
        const input = form?.querySelector('input[name="images[]"], input[name="image"]');
        const existing = container.dataset.imageUrl;
        if (!input && !existing) return;
        const multiple = input?.name === 'images[]';
        const template = container.innerHTML;
        const states = new WeakMap();
        let revision = 0;
        let controller;

        const render = async (files) => {
            const current = ++revision;
            controller?.abort();
            controller = new AbortController();
            const requestController = controller;
            container.replaceChildren();
            container.hidden = !files.length;
            let selectionTasks = Promise.resolve();

            for (const [index, file] of files.entries()) {
                const panel = document.createElement('div');
                panel.innerHTML = template;
                container.append(panel);

                const result = panel.querySelector('[data-result-input]');
                const source = panel.querySelector('[data-source-input]');
                result.name = multiple ? `crop_coordinates[${index}]` : 'crop_json';
                source.name = multiple ? `selection_sources[${index}]` : 'selection_source';

                const initial = existing ? JSON.parse(container.dataset.initialCrop || 'null') : null;
                let state = states.get(file) || createState(initial, !!existing, container.dataset.initialSource || null);
                const save = () => {
                    const wire = toWire(state);
                    result.value = wire.cropJson;
                    source.value = wire.source;
                };
                const update = (next) => {
                    state = next;
                    states.set(file, state);
                    save();
                };

                const editor = new ObjectSelectionEditor(panel.querySelector('[data-preview]'), {
                    onBoxChange(box) { update(transition(state, { type: 'user-edit', box })); },
                });
                const status = panel.querySelector('[data-selection-status]');
                save();

                const loaded = editor.loadImage(file).then(() => true, () => false);
                selectionTasks = selectionTasks.then(async () => {
                    try {
                        if (!(await loaded)) throw new Error('Invalid preview');
                        if (current !== revision) return;
                        editor.setBox(state.box);
                        if (state.touched || state.auto) return;
                        if (status) status.textContent = 'Mencari area objek… Anda tetap bisa menyesuaikan kotak sendiri.';
                        const body = new FormData();
                        body.append('image', file);
                        const timeout = setTimeout(() => requestController.abort(), SELECT_TIMEOUT_MS);
                        try {
                            const response = await fetch(container.dataset.selectUrl || '/object-selection', {
                                method: 'POST',
                                body,
                                signal: requestController.signal,
                                headers: {
                                    Accept: 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                },
                            });
                            if (!response.ok) {
                                if (status) status.textContent = 'Seleksi otomatis belum tersedia. Tarik kotak pada foto atau gunakan foto penuh.';
                                return;
                            }
                            const data = await response.json();
                            if (current !== revision) return;
                            // Laravel returns candidates ({box, source, score}); keep the
                            // legacy boxes-only shape working as a fallback.
                            const candidates = (data.candidates || [])
                                .filter((candidate) => validBox(candidate?.box))
                                .concat(data.candidates ? [] : (data.boxes || []).filter(validBox).map((box) => ({ box })));
                            const first = candidates[0]?.box;
                            const reason = data.reason || 'unknown';
                            if (status) {
                                status.textContent = !first
                                    ? 'Objek belum terdeteksi. Tarik kotak pada foto atau gunakan foto penuh.'
                                    : reason === 'center_fallback'
                                        ? 'Objek belum terdeteksi — kotak tengah dipilih. Sesuaikan sebelum mencari.'
                                        : 'Kotak menyesuaikan otomatis. Geser untuk memindah, tarik sudut atau tepi untuk mengubah ukuran.';
                            }
                            if (first) {
                                update(transition(state, { type: 'auto-applied', box: first }));
                                editor.setBox(state.box);
                            }
                        } finally {
                            clearTimeout(timeout);
                        }
                    } catch {
                        if (status) status.textContent = 'Seleksi otomatis belum tersedia. Tarik kotak pada foto atau gunakan foto penuh.';
                    }
                });
            }
            await selectionTasks;
        };

        input?.addEventListener('change', () => render([...input.files]));
        if (existing) {
            fetch(existing, { headers: { Accept: 'image/*' } })
                .then((response) => {
                    if (!response.ok) throw new Error('Foto tidak dapat dibuka');
                    return response.blob();
                })
                .then((blob) => render([new File([blob], 'reference', { type: blob.type })]))
                .catch(() => {
                    container.querySelector('[data-selection-status]').textContent = 'Foto tidak dapat dibuka. Muat ulang halaman sebelum mengedit.';
                    form?.querySelector('button:not([type="button"])')?.setAttribute('disabled', '');
                });
        }
    });
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeObjectSelections);
    else initializeObjectSelections();
}

export { ObjectSelectionEditor, validBox };
