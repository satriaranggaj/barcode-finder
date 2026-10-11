/**
 * Pending photo-selection management for the admin upload form and the
 * catalog search form. The original file input stays the source of truth;
 * every remove/cancel rewrites `input.files` (DataTransfer), revokes
 * obsolete object URLs, bumps the selection revision (invalidating
 * in-flight object-selection and compression results), and dispatches a
 * `change` event so the existing preview + object-selection renderers
 * rebuild from the remaining files. Stale crop coordinates and
 * selection-source metadata live inside those re-rendered panels, so they
 * can never leak from a removed file to another one.
 *
 * Cancelled photos are protected from stale submits by the required file
 * input itself: with no files the form fails native validation, and every
 * cancel/remove bumps the selection revision first.
 */
export function formatFileSize(bytes) {
    const size = Number(bytes) || 0;
    if (size <= 0) return '0 KB';
    if (size < 1024 * 1024) return `${Math.max(1, Math.round(size / 1024))} KB`;
    return `${(size / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * Pure view-model for the selected-file list. Tested without a DOM.
 * Returns `{ count, summary, items: [{ index, name, sizeLabel }] }`.
 */
export function buildFileListModel(files) {
    const list = [...(files || [])];
    return {
        count: list.length,
        summary: list.length === 0
            ? ''
            : list.length === 1
                ? '1 foto dipilih'
                : `${list.length} foto dipilih`,
        items: list.map((file, index) => ({
            index,
            name: String(file?.name ?? `Foto ${index + 1}`),
            sizeLabel: formatFileSize(file?.size),
        })),
    };
}

/** New file array without the entry at `index`; all others preserved. */
export function removeAt(files, index) {
    return [...(files || [])].filter((_, position) => position !== Number(index));
}

export function shouldShowSelection(count) {
    return Number(count) > 0;
}

/**
 * Rewrite the target input's file list and notify existing renderers.
 * Bumps `_lenskuRevision` FIRST so any in-flight compression task or
 * object-selection response for the previous selection is discarded.
 * `deps` injection exists only so unit tests can run without a DOM.
 */
export function setTargetFiles(target, files, deps = {}) {
    if (!target) return 0;
    const Transfer = deps.Transfer
        || (typeof DataTransfer !== 'undefined' ? DataTransfer : null);
    const createEvent = deps.createEvent
        || (() => new Event('change', { bubbles: true }));
    const dispatch = deps.dispatch
        || ((element, event) => element.dispatchEvent(event));
    const revision = (target._lenskuRevision = (target._lenskuRevision || 0) + 1);
    if (Transfer) {
        const transfer = new Transfer();
        [...(files || [])].forEach((file) => {
            try {
                transfer.items.add(file);
            } catch {
                /* keep previously added files */
            }
        });
        try {
            target.files = transfer.files;
        } catch {
            /* read-only input in some test doubles: revision still guards */
        }
    }
    try {
        dispatch(target, createEvent());
    } catch {
        /* listeners are optional enhancements */
    }
    return revision;
}

/** Revoke preview object URLs tracked inside `root` to avoid leaks on remove/cancel. */
export function revokeObjectUrlsIn(root) {
    if (!root || typeof root.querySelectorAll !== 'function') return 0;
    let revoked = 0;
    root.querySelectorAll('img[data-objectUrl]').forEach((img) => {
        try {
            const url = img.dataset?.objectUrl;
            if (url && typeof URL !== 'undefined' && URL.revokeObjectURL) {
                URL.revokeObjectURL(url);
                revoked += 1;
            }
        } catch {
            /* best effort */
        }
        try {
            delete img.dataset.objectUrl;
        } catch {
            /* noop */
        }
    });
    return revoked;
}

function elementText(element, text) {
    if (element) element.textContent = text;
}

/**
 * Index of the editor panel containing `button`, counting only real
 * per-file panels (direct DIV children holding a result input). Returns -1
 * when it cannot be determined. Pure DOM walk, unit-testable with fakes.
 */
export function panelIndexOf(container, button) {
    if (!container || !button || typeof container.children === 'undefined') return -1;
    let node = button;
    while (node && node.parentElement && node.parentElement !== container) {
        node = node.parentElement;
    }
    if (!node || node.parentElement !== container) return -1;
    const panels = [...container.children].filter(
        (element) => element
            && element.tagName === 'DIV'
            && typeof element.querySelector === 'function'
            && element.querySelector('[data-result-input]')
    );
    return panels.indexOf(node);
}

function renderAdminSummary(form, target) {
    if (!form || !target) return;
    const model = buildFileListModel(target.files);
    elementText(form.querySelector('[data-selected-count]'), model.summary);
    const cancel = form.querySelector('[data-cancel-selection]');
    if (cancel) cancel.hidden = !shouldShowSelection(model.count);
}

/** Overlay one corner × button per editor panel (idempotent). */
function ensurePanelButtons(container) {
    if (!container || typeof document === 'undefined') return;
    try {
        container.classList?.add('relative');
    } catch {
        /* noop */
    }
    [...container.children].forEach((panel) => {
        if (!panel || panel.tagName !== 'DIV') return;
        if (typeof panel.querySelector !== 'function') return;
        if (!panel.querySelector('[data-result-input]')) return;
        if (panel.querySelector('[data-panel-remove]')) return;
        try {
            panel.classList?.add('relative');
        } catch {
            /* noop */
        }
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.setAttribute('data-panel-remove', '');
        const position = panelIndexOf(container, panel);
        remove.setAttribute('aria-label', position >= 0 ? `Hapus foto ${position + 1}` : 'Hapus foto ini');
        remove.className = 'absolute right-2 top-2 z-10 flex h-9 w-9 items-center justify-center rounded-full bg-black/60 text-xl font-bold leading-none text-white transition hover:bg-black/80 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white';
        remove.textContent = '×';
        panel.prepend(remove);
    });
}

function targetForContainer(container) {
    const form = container.closest?.('form')
        || (container.dataset?.form && typeof document !== 'undefined'
            ? document.getElementById(container.dataset.form)
            : null);
    const target = form?.querySelector?.('input[name="images[]"], input[name="image"]') || null;
    return { form, target };
}

function initPanelRemoval() {
    const containers = document.querySelectorAll('[data-object-selection]');
    containers.forEach((container) => {
        ensurePanelButtons(container);
        try {
            new MutationObserver(() => ensurePanelButtons(container)).observe(container, { childList: true });
        } catch {
            /* observer unavailable: static buttons still work */
        }
    });
    // One delegated listener covers admin multi-photo panels and the single
    // catalog panel, including panels rendered later by object-selection.
    document.addEventListener('click', (event) => {
        const button = event.target?.closest?.('[data-panel-remove]');
        if (!button) return;
        const container = button.closest?.('[data-object-selection]');
        if (!container) return;
        const { form, target } = targetForContainer(container);
        if (!target) return;
        const index = panelIndexOf(container, button);
        const remaining = index >= 0 ? removeAt([...(target.files || [])], index) : [];
        revokeObjectUrlsIn(form || container);
        setTargetFiles(target, remaining);
        if (form) renderAdminSummary(form, target);
        // Return focus to the picker trigger so a replacement photo can be
        // chosen immediately via keyboard.
        const picker = form?.querySelector?.('[data-photo-picker]')
            || document.querySelector('[data-photo-picker]');
        try {
            picker?.focus?.();
        } catch {
            /* noop */
        }
    });
}

function initAdminPhotoSelection() {
    document.querySelectorAll('form[data-upload-designs]').forEach((form) => {
        const target = form.querySelector('input[name="images[]"]');
        if (!target) return;
        target.addEventListener('change', () => renderAdminSummary(form, target));
        renderAdminSummary(form, target);
        form.querySelector('[data-cancel-selection]')?.addEventListener('click', () => {
            revokeObjectUrlsIn(form);
            setTargetFiles(target, []);
            renderAdminSummary(form, target);
            form.querySelector('[data-photo-picker]')?.focus?.();
        });
    });
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            initAdminPhotoSelection();
            initPanelRemoval();
        });
    } else {
        initAdminPhotoSelection();
        initPanelRemoval();
    }
}

export { initAdminPhotoSelection, initPanelRemoval };
