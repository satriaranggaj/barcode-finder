/**
 * Loading state for native-submit buttons (e.g. "Konfirmasi & cari").
 *
 * The search form submits natively (full page load while the AI runs), so
 * the button must visibly switch to a loading state the moment submit fires
 * and stay that way until navigation. Operates on a button element directly
 * so it is unit-testable without a DOM.
 */

const BUSY_CLASSES = ['opacity-70', 'cursor-wait'];

/**
 * Finish a compression-gated native submit.
 *
 * Returns 'resubmitted' when the form is still valid (native submission
 * proceeds; the loading state intentionally stays on until navigation) or
 * 'aborted-invalid' when validity was lost while compression ran in the
 * background — the button is fully restored and native validation bubbles
 * shown instead of leaving a stuck loading state or double-submitting.
 */
export function finalizeGatedSubmit(form, button) {
    if (!form) return 'aborted-invalid';
    let valid = true;
    try {
        valid = typeof form.checkValidity === 'function' ? form.checkValidity() : true;
    } catch {
        valid = true;
    }
    if (!valid) {
        delete form.dataset.lenskuResubmit;
        setSearchLoading(button, false);
        try {
            form.reportValidity?.();
        } catch {
            /* noop */
        }
        return 'aborted-invalid';
    }
    // Flag set BEFORE requestSubmit so the re-entrant submit event returns
    // early: exactly one native submission, never two.
    form.dataset.lenskuResubmit = 'true';
    try {
        form.requestSubmit();
    } catch {
        // requestSubmit itself failed (no submit event will follow):
        // restore the button instead of leaving it stuck loading.
        delete form.dataset.lenskuResubmit;
        setSearchLoading(button, false);
        return 'aborted-invalid';
    }
    return 'resubmitted';
}

/**
 * Restore the search button (bfcache/back navigation can show a stale
 * loading state). Returns true when a button was found and reset.
 */
export function resetSearchButtons(root) {
    if (!root || typeof root.getElementById !== 'function') return false;
    const button = root.getElementById('home-search-submit');
    if (!button) return false;
    setSearchLoading(button, false);
    return true;
}

/**
 * Native submit handler for the image search form. Loading activates only
 * here: a native submit event fires strictly after browser validation
 * passes, so an invalid form can never leave a stuck loading state.
 * Returns 'resumed' (re-entrant post-compression submit), 'direct' (no
 * compression pending, native submission continues) or 'gated' (submission
 * held until the background compression promise settles, then finalized).
 */
export function handleSearchSubmit(form, imageInput, event, deps = {}) {
    const {
        setLoading = setSearchLoading,
        finalize = finalizeGatedSubmit,
        getButton = (target) =>
            (typeof document !== 'undefined' ? document.getElementById('home-search-submit') : null) ||
            target.querySelector('[data-preview-submit]'),
    } = deps;
    if (form.dataset.lenskuResubmit === 'true') {
        delete form.dataset.lenskuResubmit;
        return 'resumed';
    }
    const button = getButton(form);
    setLoading(button, true);
    if (!imageInput._lenskuCompress) return 'direct';
    event.preventDefault();
    Promise.resolve(imageInput._lenskuCompress)
        .catch(() => {})
        .finally(() => {
            finalize(form, button);
        });
    return 'gated';
}

export function setSearchLoading(button, loading) {
    if (!button) return;
    const classList = button.classList;
    if (loading) {
        if (button.dataset && button.dataset.originalText === undefined) {
            button.dataset.originalText = button.innerHTML;
        }
        button.disabled = true;
        try {
            button.setAttribute('aria-busy', 'true');
        } catch {
            /* noop */
        }
        BUSY_CLASSES.forEach((cls) => {
            try {
                classList?.add?.(cls);
            } catch {
                /* noop */
            }
        });
        try {
            button.innerHTML =
                '<span aria-hidden="true" class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span> Mencari…';
        } catch {
            /* noop */
        }
        return;
    }
    if (button.dataset && button.dataset.originalText !== undefined) {
        try {
            button.innerHTML = button.dataset.originalText;
        } catch {
            /* noop */
        }
        delete button.dataset.originalText;
    }
    button.disabled = false;
    try {
        button.removeAttribute('aria-busy');
    } catch {
        /* noop */
    }
    BUSY_CLASSES.forEach((cls) => {
        try {
            classList?.remove?.(cls);
        } catch {
            /* noop */
        }
    });
}
