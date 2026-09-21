/**
 * Loading state for native-submit buttons (e.g. "Konfirmasi & cari").
 *
 * The search form submits natively (full page load while the AI runs), so
 * the button must visibly switch to a loading state the moment submit fires
 * and stay that way until navigation. Operates on a button element directly
 * so it is unit-testable without a DOM.
 */

const BUSY_CLASSES = ['opacity-70', 'cursor-wait'];

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
