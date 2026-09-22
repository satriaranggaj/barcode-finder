/**
 * Object selection flow for search and upload forms.
 *
 * Flow: file pick -> render preview instantly -> request auto-selection in
 * parallel -> overlay the best proposal automatically. No candidate buttons:
 * the single best box is applied silently; the user can still drag or resize
 * it directly on the preview. The selected crop is stored in hidden inputs
 * as normalized 0..1 coordinates and forwarded with the form.
 * Auto-selection failure never blocks the form — full image or a manual box
 * always work.
 *
 * Responsiveness: preview and fetch start together (fetch never waits for
 * <img> decode), each file runs independently in parallel, and an initial
 * full-image state keeps the form submittable while detection runs.
 */
import { ObjectSelectionEditor } from './selection/editor.js';
import { validBox } from './selection/geometry.js';
import { createState, transition, toWire } from './selection/state.js';

const SELECT_TIMEOUT_MS = 15000;

// Boxes smaller than this are fragments (e.g. handle without shaft/tip),
// not objects — cropping to them destroys the evidence. Mirrors
// MIN_AUTO_COVERAGE in ai-service/app/search/service.py; keep in sync.
const MIN_AUTO_COVERAGE = 0.15;

/**
 * Report auto-selection pipeline state for the readiness UI.
 * Dispatched exclusively from revision-guarded points (or synchronously
 * inside the current render), so a superseded photo's late response can
 * never rewrite the newest photo's state. Never throws.
 */
function emitSelection(container, state, note) {
    try {
        let revision;
        try {
            const form = container.closest
                ? container.closest('form')
                : null;
            const scope =
                form ||
                (container.dataset?.form && typeof document !== 'undefined'
                    ? document.getElementById(container.dataset.form)
                    : null);
            const input = scope?.querySelector?.('input[name="images[]"], input[name="image"]');
            revision = input?._lenskuRevision;
        } catch {
            revision = undefined;
        }
        container.dispatchEvent(
            new CustomEvent('lensku:selection', { bubbles: true, detail: { state, note: note || null, revision } })
        );
    } catch {
        /* observability never breaks selection */
    }
}

function normalizeCandidates(data) {
    // Laravel returns candidates ({box, source, score}); keep the
    // legacy boxes-only shape working as a fallback. Only the best
    // (first, already ranked server-side) is used — no picker UI.
    const fromCandidates = (data.candidates || []).filter((candidate) => validBox(candidate?.box));
    if (fromCandidates.length || data.candidates) return fromCandidates;
    return (data.boxes || []).filter(validBox).map((box) => ({ box }));
}

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
        let liveControllers = [];

        const render = async (files) => {
            const current = ++revision;
            liveControllers.forEach((c) => { try { c.abort(); } catch { /* noop */ } });
            liveControllers = [];
            container.replaceChildren();
            container.hidden = !files.length;

            files.forEach((file, index) => {
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

                // Preview starts instantly; the box renders as soon as the
                // image decodes, using whatever state is newest at that time.
                let imageReady = false;
                editor.loadImage(file).then(
                    () => {
                        if (current !== revision) return;
                        imageReady = true;
                        editor.setBox(state.box);
                    },
                    () => {
                        if (current !== revision) return;
                        if (status) status.textContent = 'Pratinjau gagal dimuat. Coba foto lain.';
                    },
                );

                if (state.touched && state.box) {
                    editor.setBox(state.box);
                    emitSelection(container, 'ready');
                    return;
                }
                if (status) status.textContent = 'Mencari area objek… Anda tetap bisa menyesuaikan kotak sendiri.';
                emitSelection(container, 'processing');

                // Auto-selection runs in parallel with preview decode — never
                // waiting for <img> load — so thin tools appear selected as
                // soon as the backend answers.
                const fileController = new AbortController();
                liveControllers.push(fileController);
                let selectExpired = false;
                const timeout = setTimeout(() => {
                    selectExpired = true;
                    try { fileController.abort(); } catch { /* noop */ }
                }, SELECT_TIMEOUT_MS);
                const body = new FormData();
                body.append('image', file);
                fetch(container.dataset.selectUrl || '/object-selection', {
                    method: 'POST',
                    body,
                    signal: fileController.signal,
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                }).then(async (response) => {
                    if (current !== revision) return;
                    if (!response.ok) {
                        if (status) status.textContent = 'Seleksi otomatis belum tersedia. Tarik kotak pada foto atau gunakan foto penuh.';
                        emitSelection(container, 'fallback', 'Seleksi otomatis belum tersedia.');
                        return;
                    }
                    const data = await response.json();
                    if (current !== revision) return;
                    const candidates = normalizeCandidates(data);
                    const first = candidates[0]?.box;
                    const reason = data.reason || 'unknown';
                    const usable = first && first.width * first.height >= MIN_AUTO_COVERAGE ? first : null;
                    if (status) {
                        status.textContent = !usable
                            ? 'Menampilkan foto penuh.'
                            : reason === 'center_fallback'
                                ? 'Objek belum terdeteksi — kotak tengah dipilih. Sesuaikan sebelum mencari.'
                                : 'Kotak menyesuaikan otomatis. Geser untuk memindah, tarik sudut atau tepi untuk mengubah ukuran.';
                    }
                    if (usable) {
                        update(transition(state, { type: 'auto-applied', box: usable }));
                        if (imageReady) editor.setBox(state.box);
                        emitSelection(container, 'ready');
                    } else {
                        emitSelection(container, 'fallback', 'Menampilkan foto penuh.');
                    }
                }).catch((error) => {
                    // Our own 15s timeout is a fallback (search stays allowed);
                    // only a supersede-abort stays silent.
                    if (error?.name === 'AbortError' && !selectExpired) return;
                    if (current !== revision) return;
                    if (status) status.textContent = 'Seleksi otomatis belum tersedia. Tarik kotak pada foto atau gunakan foto penuh.';
                    emitSelection(container, 'fallback', 'Seleksi otomatis belum tersedia.');
                }).finally(() => clearTimeout(timeout));
            });
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
