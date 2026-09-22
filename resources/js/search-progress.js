/**
 * Global search progress bar below the navbar.
 *
 * Started EXACTLY when a visual search POST is really about to be sent —
 * never during photo pick, preview, compression, selection, or readiness.
 * Simulated easing toward (never reaching) 90%: the backend exposes no
 * realtime AI progress, so the bar creeps instead of faking completion.
 * Navigation to the result page unloads this page, which removes the bar.
 */

export const PROGRESS_START = 0.06;
export const PROGRESS_CAP = 0.9;
export const PROGRESS_EASE = 0.18;
export const PROGRESS_TICK_MS = 200;

let active = false;
let progress = 0;
let timer = null;

/**
 * Pure easing step, exported for tests: strictly increasing, capped below 1.
 */
export function nextProgressValue(current) {
    const next = current + (PROGRESS_CAP - current) * PROGRESS_EASE;
    return next >= 1 ? 0.99 : next;
}

export function isSearchProgressActive() {
    return active;
}

function findBar(root) {
    try {
        const doc = root || (typeof document !== 'undefined' ? document : null);
        if (!doc) return null;
        const container =
            typeof doc.querySelector === 'function' ? doc.querySelector('[data-search-progress]') : null;
        if (!container) return null;
        const bar =
            typeof container.querySelector === 'function'
                ? container.querySelector('[data-search-progress-bar]')
                : null;
        return bar ? { container, bar } : null;
    } catch {
        return null;
    }
}

function paint(nodes) {
    if (!nodes) return;
    try {
        nodes.bar.style.transform = `scaleX(${progress})`;
    } catch {
        /* noop */
    }
}

function reducedMotion() {
    try {
        return (
            typeof matchMedia === 'function' &&
            matchMedia('(prefers-reduced-motion: reduce)').matches
        );
    } catch {
        return false;
    }
}

/**
 * Show the bar and begin easing. Returns true on a fresh start, false when
 * already active (second start never creates a second timer/request).
 */
export function startSearchProgress(root) {
    if (active) return false;
    const nodes = findBar(root);
    if (!nodes) return false;
    active = true;
    try {
        nodes.container.hidden = false;
        nodes.container.setAttribute('aria-valuetext', 'Pencarian sedang berlangsung');
    } catch {
        /* noop */
    }
    if (reducedMotion()) {
        // No decorative animation: a static visible bar still signals activity.
        progress = 0.3;
        paint(nodes);
        return true;
    }
    progress = PROGRESS_START;
    paint(nodes);
    try {
        timer = setInterval(() => {
            progress = nextProgressValue(progress);
            paint(findBar(root));
        }, PROGRESS_TICK_MS);
    } catch {
        timer = null;
    }
    return true;
}

/**
 * Hide the bar and clean the timer. Safe to call when never started.
 * Also used on pageshow/bfcache so a restored page never shows stale state.
 */
export function resetSearchProgress(root) {
    try {
        if (timer) {
            clearInterval(timer);
        }
    } catch {
        /* noop */
    }
    timer = null;
    active = false;
    progress = 0;
    try {
        const nodes = findBar(root);
        if (nodes) {
            nodes.container.hidden = true;
            nodes.bar.style.transform = 'scaleX(0)';
            nodes.container.removeAttribute('aria-valuetext');
        }
    } catch {
        /* noop */
    }
}
