/*
 * Lensku PWA install/update UX (vanilla JS, no new dependencies).
 *
 * Pure decision helpers are exported for unit tests; DOM wiring runs only
 * when a document exists, so importing this module in Node is side-effect
 * free. Install always requires an explicit user gesture — never auto-prompt.
 */

export const PWA_SW_URL = '/sw.js';
export const PWA_SW_SCOPE = '/';

/** True when the page already runs as an installed app. */
export function isStandaloneMode(win) {
    const target = win || (typeof window !== 'undefined' ? window : undefined);
    if (!target) return false;
    try {
        if (target.matchMedia && target.matchMedia('(display-mode: standalone)').matches) return true;
    } catch {
        return false;
    }
    // iOS Safari standalone flag (no beforeinstallprompt there).
    try {
        if (target.navigator && target.navigator.standalone === true) return true;
    } catch {
        return false;
    }
    return false;
}

/** True on iPhone/iPad/iPod, including iPadOS reporting as Mac. Conservative: used only for the manual-install fallback. */
export function isIosDevice(nav) {
    const target = nav || (typeof navigator !== 'undefined' ? navigator : undefined);
    if (!target) return false;
    const ua = String(target.userAgent || '');
    if (/iPad|iPhone|iPod/.test(ua)) return true;
    // iPadOS 13+ identifies as Macintosh; touch points distinguish it.
    if (/Macintosh/.test(ua) && typeof target.maxTouchPoints === 'number' && target.maxTouchPoints > 1) return true;
    return false;
}

/**
 * Which install affordance to show. Returns 'chromium' when the browser fired
 * beforeinstallprompt, 'ios' for the manual Add-to-Home-Screen fallback, else
 * 'none'. Standalone always wins (nothing to install).
 */
export function installUiState({ standalone, hasPrompt, ios }) {
    if (standalone) return 'none';
    if (hasPrompt) return 'chromium';
    if (ios) return 'ios';
    return 'none';
}

/** True while the user has unsaved in-flight work worth protecting from reload. */
export function isUserBusy(doc) {
    const target = doc || (typeof document !== 'undefined' ? document : undefined);
    if (!target || !target.querySelector) return false;
    if (target.querySelector('form[data-uploading="true"]')) return true;
    const progress = target.querySelector('[data-search-progress]');
    if (progress && !progress.hidden) return true;
    const dialog = target.querySelector('dialog[open]');
    if (dialog) return true;
    return false;
}

function refreshInstallUi(root, state) {
    const chromiumButtons = root.querySelectorAll('[data-pwa-install]');
    const iosButtons = root.querySelectorAll('[data-pwa-ios]');
    const iosHelp = root.querySelectorAll('[data-pwa-ios-help]');
    const mode = installUiState(state);
    chromiumButtons.forEach((button) => {
        button.hidden = mode !== 'chromium';
    });
    iosButtons.forEach((button) => {
        button.hidden = mode !== 'ios';
    });
    // Closing instructions when the CTA itself disappears keeps stale help
    // from lingering after installation.
    if (mode !== 'ios') {
        iosHelp.forEach((panel) => {
            panel.hidden = true;
        });
    }
}

function currentState(deferredPrompt) {
    const standalone = isStandaloneMode();
    return {
        standalone,
        hasPrompt: deferredPrompt !== null,
        ios: isIosDevice(),
    };
}

function initPwaInstall() {
    const root = document;
    let deferredPrompt = null;

    const refresh = () => refreshInstallUi(root, currentState(deferredPrompt));
    refresh();

    window.addEventListener('beforeinstallprompt', (event) => {
        // Hold the prompt for an explicit user gesture; never auto-show.
        event.preventDefault();
        deferredPrompt = event;
        refresh();
    });

    const choosePrompt = async () => {
        if (!deferredPrompt) return;
        const prompt = deferredPrompt;
        deferredPrompt = null;
        refresh();
        try {
            await prompt.prompt();
            await prompt.userChoice.catch(() => undefined);
        } catch {
            // A rejected prompt simply means "not now"; stay silent.
        }
        refresh();
    };

    root.querySelectorAll('[data-pwa-install]').forEach((button) => {
        button.addEventListener('click', choosePrompt);
    });

    root.querySelectorAll('[data-pwa-ios]').forEach((button) => {
        button.addEventListener('click', () => {
            root.querySelectorAll('[data-pwa-ios-help]').forEach((panel) => {
                panel.hidden = !panel.hidden;
                if (!panel.hidden) {
                    const heading = panel.querySelector('[data-pwa-ios-help-title]');
                    if (heading && heading.focus) heading.focus();
                }
            });
        });
    });

    root.querySelectorAll('[data-pwa-ios-help-close]').forEach((button) => {
        button.addEventListener('click', () => {
            root.querySelectorAll('[data-pwa-ios-help]').forEach((panel) => {
                panel.hidden = true;
            });
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        root.querySelectorAll('[data-pwa-ios-help]').forEach((panel) => {
            panel.hidden = true;
        });
    });

    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        refresh();
    });
}

function initPwaServiceWorker() {
    if (!('serviceWorker' in navigator)) return;
    // file:// and similar contexts cannot host a service worker scope.
    if (!/^https?:$/.test(window.location.protocol)) return;
    window.addEventListener('load', () => {
        navigator.serviceWorker
            .register(PWA_SW_URL, { scope: PWA_SW_SCOPE })
            .then((registration) => {
                watchForUpdates(registration);
            })
            .catch(() => {
                // Offline-first is progressive enhancement; stay silent.
            });
    });
}

function watchForUpdates(registration) {
    const showUpdate = () => {
        if (isUserBusy()) {
            // Retry on the next user interaction instead of risking in-flight work.
            const retry = () => {
                window.removeEventListener('click', retry);
                window.removeEventListener('keydown', retry);
                showUpdate();
            };
            window.addEventListener('click', retry);
            window.addEventListener('keydown', retry);
            return;
        }
        document.querySelectorAll('[data-pwa-update]').forEach((button) => {
            button.hidden = false;
        });
    };

    registration.addEventListener('updatefound', () => {
        const worker = registration.installing;
        if (worker) {
            worker.addEventListener('statechange', () => {
                // A fresh worker installed while this page is controlled by
                // the old one: offer a reload (user-confirmed, never forced).
                if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                    showUpdate();
                }
            });
        }
    });

    document.querySelectorAll('[data-pwa-update]').forEach((button) => {
        button.addEventListener('click', () => {
            if (isUserBusy()) return;
            window.location.reload();
        });
    });
}

if (typeof document !== 'undefined' && typeof window !== 'undefined') {
    initPwaServiceWorker();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPwaInstall);
    } else {
        initPwaInstall();
    }
}
