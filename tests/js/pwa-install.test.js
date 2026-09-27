import { describe, it, expect } from 'vitest';
import {
    isStandaloneMode,
    isIosDevice,
    installUiState,
    isUserBusy,
} from '../../resources/js/pwa.js';

describe('install UI state', () => {
    it('is hidden by default (no prompt, not standalone, not iOS)', () => {
        expect(installUiState({ standalone: false, hasPrompt: false, ios: false })).toBe('none');
    });

    it('shows the Chromium button after beforeinstallprompt', () => {
        expect(installUiState({ standalone: true, hasPrompt: true, ios: false })).toBe('none');
        expect(installUiState({ standalone: false, hasPrompt: true, ios: false })).toBe('chromium');
    });

    it('shows the iOS fallback only on iOS outside standalone', () => {
        expect(installUiState({ standalone: false, hasPrompt: false, ios: true })).toBe('ios');
        expect(installUiState({ standalone: true, hasPrompt: false, ios: true })).toBe('none');
    });

    it('prefers the browser prompt over the iOS fallback', () => {
        expect(installUiState({ standalone: false, hasPrompt: true, ios: true })).toBe('chromium');
    });
});

describe('standalone detection', () => {
    const standaloneWindow = (mode, iosStandalone) => ({
        matchMedia: (query) => ({ matches: query === '(display-mode: standalone)' && mode === 'standalone' }),
        navigator: { standalone: iosStandalone },
    });

    it('detects display-mode standalone', () => {
        expect(isStandaloneMode(standaloneWindow('standalone', false))).toBe(true);
        expect(isStandaloneMode(standaloneWindow('browser', false))).toBe(false);
    });

    it('detects the iOS Safari standalone flag', () => {
        expect(isStandaloneMode(standaloneWindow('browser', true))).toBe(true);
    });

    it('is false without a window', () => {
        expect(isStandaloneMode({})).toBe(false);
    });
});

describe('iOS detection', () => {
    it('matches iPhone and iPad user agents', () => {
        expect(isIosDevice({ userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)' })).toBe(true);
        expect(isIosDevice({ userAgent: 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)' })).toBe(true);
    });

    it('matches iPadOS reporting as Macintosh only with touch', () => {
        expect(isIosDevice({ userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15)', maxTouchPoints: 5 })).toBe(true);
        expect(isIosDevice({ userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15)', maxTouchPoints: 0 })).toBe(false);
    });

    it('does not match Android or desktop Chromium', () => {
        expect(isIosDevice({ userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8)', maxTouchPoints: 5 })).toBe(false);
        expect(isIosDevice({ userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126' })).toBe(false);
    });
});

describe('busy detection for update reloads', () => {
    const docWith = (selector) => ({
        querySelector: (query) => {
            if (query === 'form[data-uploading="true"]') {
                return selector === 'uploading' ? {} : null;
            }
            if (query === '[data-search-progress]') {
                return selector === 'searching' ? { hidden: false } : null;
            }
            if (query === 'dialog[open]') {
                return selector === 'dialog' ? {} : null;
            }
            return null;
        },
    });

    it('is busy during uploads, searches, and open dialogs', () => {
        expect(isUserBusy(docWith('uploading'))).toBe(true);
        expect(isUserBusy(docWith('searching'))).toBe(true);
        expect(isUserBusy(docWith('dialog'))).toBe(true);
    });

    it('is idle otherwise', () => {
        expect(isUserBusy(docWith('idle'))).toBe(false);
        expect(
            isUserBusy({
                querySelector: (query) =>
                    query === '[data-search-progress]' ? { hidden: true } : null,
            })
        ).toBe(false);
    });
});
