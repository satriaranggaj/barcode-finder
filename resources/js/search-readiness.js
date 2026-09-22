/**
 * Explicit readiness UI for visual search preparation.
 *
 * Parallelism is preserved: preview, object selection and background
 * compression all start immediately when a photo is chosen. This module only
 * OBSERVES their terminal states and gates the "Konfirmasi & cari" button:
 *
 * - compression: idle | processing | ready | fallback
 * - selection:   idle | processing | ready | fallback
 *
 * Button is enabled only when compression AND selection are each ready or
 * fallback. Fallback (original file kept, auto-selection unavailable) never
 * blocks search. Text states are derived, never stored, in the DOM.
 *
 * Stale protection: every state event carries the sender's revision and is
 * accepted only when it matches the input's live `_lenskuRevision`. Senders
 * dispatch exclusively from revision-guarded points, so stale completions
 * cannot enable the button or rewrite another photo's state.
 */
import { compressionNeeded } from './image-compression';

export const COMPRESSION_STATES = ['idle', 'processing', 'ready', 'fallback'];
export const SELECTION_STATES = ['idle', 'processing', 'ready', 'fallback'];

const DONE_STATES = ['ready', 'fallback'];

export function createReadinessState() {
    return { compression: 'idle', selection: 'idle' };
}

function applyEvent(state, key, states, detail, liveRevision) {
    if (!state || !detail || typeof detail.state !== 'string') return false;
    if (!states.includes(detail.state)) return false;
    if (
        detail.revision !== undefined &&
        liveRevision !== undefined &&
        detail.revision !== liveRevision
    ) {
        return false;
    }
    state[key] = detail.state;
    return true;
}

export function applyCompressionEvent(state, detail, liveRevision) {
    return applyEvent(state, 'compression', COMPRESSION_STATES, detail, liveRevision);
}

export function applySelectionEvent(state, detail, liveRevision) {
    return applyEvent(state, 'selection', SELECTION_STATES, detail, liveRevision);
}

export function isSearchReady(state) {
    return (
        !!state &&
        DONE_STATES.includes(state.compression) &&
        DONE_STATES.includes(state.selection)
    );
}

function stageMarker(status) {
    if (status === 'processing') return '●';
    if (status === 'ready') return '✓';
    if (status === 'fallback') return '!';
    return '○';
}

/**
 * Pure view-model for the readiness panel + button. Keeps text decisions in
 * one testable place; the DOM layer below only writes these values.
 */
export function readinessView(state) {
    const compression = state?.compression || 'idle';
    const selection = state?.selection || 'idle';
    const ready = isSearchReady(state);
    return {
        ready,
        title: ready ? 'Foto siap' : 'Menyiapkan foto…',
        stages: [
            {
                key: 'compression',
                marker: stageMarker(compression),
                label: compression === 'processing' ? 'Mengoptimalkan gambar…' : 'Gambar siap',
            },
            {
                key: 'selection',
                marker: stageMarker(selection),
                label:
                    selection === 'processing'
                        ? 'Mendeteksi area barang…'
                        : selection === 'ready'
                          ? 'Area barang siap'
                          : selection === 'fallback'
                            ? 'Area otomatis tidak ditemukan'
                            : 'Mendeteksi area barang',
            },
        ],
        note:
            selection === 'fallback'
                ? 'Gunakan foto penuh atau atur area barang secara manual.'
                : ready
                  ? 'Sesuaikan area barang jika diperlukan, lalu cari.'
                  : null,
    };
}

/**
 * Dispatch a compression state event on the file input (bubbles to the
 * form-level readiness controller). No-op where CustomEvent is unavailable.
 */
export function emitCompression(input, state, revision) {
    try {
        if (!input || typeof CustomEvent === 'undefined') return;
        input.dispatchEvent(
            new CustomEvent('lensku:compression', { bubbles: true, detail: { state, revision } })
        );
    } catch {
        /* observability never breaks compression */
    }
}

function lenskuBrand(style, extra) {
    try {
        style.cssText = extra;
    } catch {
        /* noop */
    }
}

function buildPanel(doc) {
    const panel = doc.createElement('div');
    panel.setAttribute('data-readiness-panel', 'true');
    panel.setAttribute('role', 'status');
    panel.setAttribute('aria-live', 'polite');
    lenskuBrand(
        panel.style,
        'background:#fff8d6;border:1px solid #ead9b8;border-radius:12px;' +
            'padding:10px 12px;margin:0 0 8px 0;color:#543019;'
    );
    const title = doc.createElement('p');
    title.setAttribute('data-readiness-title', 'true');
    lenskuBrand(title.style, 'margin:0 0 6px 0;font-size:13px;font-weight:800;');
    const list = doc.createElement('ul');
    lenskuBrand(list.style, 'margin:0;padding:0;list-style:none;font-size:12px;line-height:1.9;');
    const note = doc.createElement('p');
    note.setAttribute('data-readiness-note', 'true');
    lenskuBrand(note.style, 'margin:6px 0 0 0;font-size:12px;color:#765c47;');
    panel.appendChild(title);
    panel.appendChild(list);
    panel.appendChild(note);
    return { panel, title, list, note };
}

/**
 * Bind one visual-search form (identified by its single-image input).
 * Returns a controller handle (or null when the form is not a search form).
 * Pure state transitions above stay unit-testable; this is the thin DOM glue.
 */
export function bindSearchForm(doc, form, input) {
    const button =
        (typeof doc.getElementById === 'function' && doc.getElementById('home-search-submit')) ||
        form.querySelector('[data-preview-submit]') ||
        null;
    const built = buildPanel(doc);
    const panel = built.panel;
    panel.hidden = true;
    if (button && button.parentNode) {
        button.parentNode.insertBefore(panel, button);
    } else {
        form.appendChild(panel);
    }

    const state = createReadinessState();
    let active = false;
    let savedButtonHtml = null;

    function saveButton() {
        if (savedButtonHtml !== null || !button) return;
        try {
            const current = button.innerHTML;
            if (typeof current === 'string' && !current.includes('Menyiapkan foto…') && !current.includes('Mencari…')) {
                savedButtonHtml = current;
            }
        } catch {
            /* noop */
        }
    }

    function render() {
        const view = readinessView(state);
        try {
            built.title.textContent = view.title;
            while (built.list.firstChild) built.list.removeChild(built.list.firstChild);
            view.stages.forEach((stage) => {
                const item = doc.createElement('li');
                item.setAttribute('data-readiness-stage', stage.key);
                item.textContent = `${stage.marker} ${stage.label}`;
                built.list.appendChild(item);
            });
            if (view.note) {
                built.note.textContent = view.note;
                built.note.hidden = false;
            } else {
                built.note.textContent = '';
                built.note.hidden = true;
            }
        } catch {
            /* noop */
        }
        if (!button) return;
        if (!active) {
            if (savedButtonHtml !== null) {
                try {
                    button.innerHTML = savedButtonHtml;
                } catch {
                    /* noop */
                }
            }
            return;
        }
        if (view.ready) {
            if (savedButtonHtml !== null) {
                try {
                    button.innerHTML = savedButtonHtml;
                } catch {
                    /* noop */
                }
            }
            button.disabled = false;
            try {
                button.removeAttribute('aria-disabled');
            } catch {
                /* noop */
            }
        } else {
            saveButton();
            try {
                button.innerHTML =
                    '<span aria-hidden="true" class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span> Menyiapkan foto…';
            } catch {
                /* noop */
            }
            button.disabled = true;
            try {
                button.setAttribute('aria-disabled', 'true');
            } catch {
                /* noop */
            }
        }
    }

    function hasSelectionContainer() {
        try {
            return !!form.querySelector('[data-object-selection]');
        } catch {
            return false;
        }
    }

    function begin() {
        const files = [...(input.files || [])];
        if (!files.length) {
            reset();
            return;
        }
        active = true;
        state.compression = 'idle';
        state.selection = 'idle';
        // Small files skip encoding: mandatory step instantly satisfied,
        // never a spinner, never a delay.
        state.compression = compressionNeeded(files) ? 'processing' : 'ready';
        // Without a selection container nothing will report selection:
        // treat as fallback (search stays allowed) instead of stuck.
        if (!hasSelectionContainer()) state.selection = 'fallback';
        try {
            panel.hidden = false;
        } catch {
            /* noop */
        }
        render();
    }

    function reset() {
        active = false;
        state.compression = 'idle';
        state.selection = 'idle';
        try {
            panel.hidden = true;
        } catch {
            /* noop */
        }
        render();
    }

    function onCompression(event) {
        if (event.target !== input) return;
        if (applyCompressionEvent(state, event.detail, input._lenskuRevision)) render();
    }

    function onSelection(event) {
        if (applySelectionEvent(state, event.detail, input._lenskuRevision)) render();
    }

    function onInputChange() {
        begin();
    }

    try {
        input.addEventListener('change', onInputChange);
        form.addEventListener('lensku:compression', onCompression);
        form.addEventListener('lensku:selection', onSelection);
    } catch {
        /* noop */
    }

    return { state, begin, reset, render, panel, button };
}

/**
 * Initialize readiness for every visual-search form under root.
 * Scoped strictly to single-image search inputs; admin multi-upload and
 * other forms are untouched.
 */
export function initSearchReadiness(root) {
    const doc = root || (typeof document !== 'undefined' ? document : null);
    const controllers = [];
    try {
        if (!doc || typeof doc.querySelectorAll !== 'function') return controllers;
        doc.querySelectorAll('form').forEach((form) => {
            let input = null;
            try {
                input = form.querySelector('input[data-image-preview][name="image"]');
            } catch {
                input = null;
            }
            if (!input) return;
            controllers.push(bindSearchForm(doc, form, input));
        });
    } catch {
        /* noop */
    }
    return controllers;
}
