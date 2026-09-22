/**
 * Submit-state helpers for the native image-search form.
 *
 * The external submit button is intentionally never mutated during
 * submission (mutating the successful submitter can cancel native POST
 * /search in some browsers). Visible loading lives in the global search
 * progress bar; double-submit protection uses form-dataset flags only.
 * setSearchLoading() remains for legacy/reset paths and stays unit-testable
 * without a DOM.
 */

import { resetSearchProgress, startSearchProgress } from './search-progress';

const BUSY_CLASSES = ['opacity-70', 'cursor-wait'];

/**
 * Transient submit-state flags on the form. lenskuResubmit is the one-shot
 * token for our own intentional requestSubmit() re-entry; searchWaiting
 * marks a held first event; searchSubmitting marks an allowed native
 * submission in flight. All are cleared on abort/pageshow so a retry starts
 * fresh.
 */
export function clearSubmitState(form) {
    if (!form || !form.dataset) return;
    delete form.dataset.lenskuResubmit;
    delete form.dataset.searchWaiting;
    delete form.dataset.searchSubmitting;
}

/**
 * Finish a compression-gated native submit.
 *
 * Returns 'resubmitted' when the form is still valid (the caller proceeds to
 * the one-shot requestSubmit) or 'aborted-invalid' when validity was lost
 * while compression ran in the background — native validation bubbles are
 * shown instead, with all transient state cleared for a retry.
 */
export function finalizeGatedSubmit(form) {
    if (!form) return 'aborted-invalid';
    let valid = true;
    try {
        valid = typeof form.checkValidity === 'function' ? form.checkValidity() : true;
    } catch {
        valid = true;
    }
    if (!valid) {
        clearSubmitState(form);
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
        // requestSubmit itself failed (no submit event will follow).
        clearSubmitState(form);
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
 * Native submit handler for the image search form.
 *
 * HARD RULE: the external form-associated submit button is NEVER mutated
 * here (no disabled, no label/spinner changes). Mutating the successful
 * submitter during the submit event can cancel or alter native form
 * submission in some browsers — that was the POST /search regression.
 * Double-submit protection uses form-dataset state only:
 * - searchWaiting: first event held for compression (duplicates ignored).
 * - lenskuResubmit: one-shot token for our intentional requestSubmit().
 * - searchSubmitting: an allowed native submission is in flight.
 *
 * A native submit event fires strictly after browser validation passes.
 * Returns 'resumed' (intentional re-entry, allowed natively), 'direct' (no
 * compression pending, allowed natively), 'gated' (held for compression) or
 * 'duplicate' (accidental extra user submit, prevented).
 */
export function handleSearchSubmit(form, imageInput, event, deps = {}) {
    const {
        finalize = finalizeGatedSubmit,
        startProgress = startSearchProgress,
        resetProgress = resetSearchProgress,
    } = deps;
    const beginPosting = () => {
        // Exactly at the point of no return: validation passed and no more
        // preparation waits remain. Progress starts once; repeats are no-ops.
        form.dataset.searchSubmitting = 'true';
        startProgress();
    };
    if (form.dataset.lenskuResubmit === 'true') {
        // Intentional re-entry from our own requestSubmit(): clear the
        // one-shot token, mark posting, start progress, and RETURN WITHOUT
        // preventDefault/requestSubmit so the browser performs POST /search.
        delete form.dataset.lenskuResubmit;
        beginPosting();
        return 'resumed';
    }
    if (form.dataset.searchSubmitting === 'true' || form.dataset.searchWaiting === 'true') {
        // Accidental extra user submit while one is already held or posting.
        event.preventDefault();
        return 'duplicate';
    }
    if (!imageInput._lenskuCompress) {
        beginPosting();
        return 'direct';
    }
    form.dataset.searchWaiting = 'true';
    event.preventDefault();
    Promise.resolve(imageInput._lenskuCompress)
        .catch(() => {})
        .finally(() => {
            delete form.dataset.searchWaiting;
            const outcome = finalize(form);
            // On success the re-entrant handler starts progress and marks
            // posting; starting here too would start progress twice, so only
            // the abort path acts here (reset is a safe no-op otherwise).
            // finalize() aborts without submitting when validity was lost
            // mid-compression: never show progress for a cancelled submit.
            if (outcome !== 'resubmitted') resetProgress();
        });
    return 'gated';
}

/**
 * Full reset for pageshow/bfcache: progress off, transient submit flags
 * cleared, button restored. The next search starts completely fresh.
 */
export function resetSearchPage(root) {
    resetSearchButtons(root);
    resetSearchProgress(root);
    try {
        const doc = root || (typeof document !== 'undefined' ? document : null);
        const forms = doc && typeof doc.querySelectorAll === 'function' ? doc.querySelectorAll('form') : [];
        (forms || []).forEach((form) => {
            try {
                clearSubmitState(form);
            } catch {
                /* noop */
            }
        });
    } catch {
        /* noop */
    }
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
