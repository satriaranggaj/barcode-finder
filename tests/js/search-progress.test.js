import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    PROGRESS_CAP,
    isSearchProgressActive,
    nextProgressValue,
    resetSearchProgress,
    startSearchProgress,
} from '../../resources/js/search-progress.js';

function fakeBar() {
    return { style: {} };
}

function fakeDoc() {
    const bar = fakeBar();
    const container = {
        hidden: true,
        attrs: {},
        style: {},
        querySelector: (selector) => (selector === '[data-search-progress-bar]' ? bar : null),
        setAttribute: (key, value) => {
            container.attrs[key] = value;
        },
        removeAttribute: (key) => {
            delete container.attrs[key];
        },
        getAttribute: (key) => container.attrs[key],
    };
    return {
        querySelector: (selector) => (selector === '[data-search-progress]' ? container : null),
        container,
        bar,
    };
}

beforeEach(() => {
    vi.stubGlobal('matchMedia', () => ({ matches: false }));
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('search progress bar', () => {
    it('hidden saat page load; memilih/kompresi/selection tidak memulainya', () => {
        const doc = fakeDoc();
        expect(isSearchProgressActive()).toBe(false);
        expect(doc.container.hidden).toBe(true);
        // Tidak ada auto-start: modul ini pasif sampai submit aktual.
        expect(doc.bar.style.transform).toBeUndefined();
    });

    it('start tepat sekali; start kedua no-op tanpa timer ganda', () => {
        vi.useFakeTimers();
        const doc = fakeDoc();
        expect(startSearchProgress(doc)).toBe(true);
        expect(isSearchProgressActive()).toBe(true);
        expect(doc.container.hidden).toBe(false);
        expect(doc.container.attrs['aria-valuetext']).toBe('Pencarian sedang berlangsung');
        expect(startSearchProgress(doc)).toBe(false);
        const first = doc.bar.style.transform;
        vi.advanceTimersByTime(400);
        const second = doc.bar.style.transform;
        expect(second).not.toBe(first);
        resetSearchProgress(doc);
    });

    it('progress naik melambat dan tidak pernah mencapai 100% sendiri', () => {
        let value = 0.06;
        let previous = value;
        for (let i = 0; i < 500; i++) {
            value = nextProgressValue(value);
            // Monoton naik hingga presisi float jenuh tepat di bawah cap.
            expect(value).toBeGreaterThanOrEqual(previous);
            expect(value).toBeLessThan(1);
            previous = value;
        }
        expect(value).toBeLessThanOrEqual(PROGRESS_CAP + 0.02);
        // Awal kurva benar-benar bergerak (tidak langsung datar).
        expect(nextProgressValue(0.06)).toBeGreaterThan(0.06);
    });

    it('tanpa container: start aman gagal, reset aman', () => {
        expect(startSearchProgress({ querySelector: () => null })).toBe(false);
        expect(isSearchProgressActive()).toBe(false);
        expect(() => resetSearchProgress(null)).not.toThrow();
        expect(() => resetSearchProgress({})).not.toThrow();
        expect(() => startSearchProgress(null)).not.toThrow();
    });

    it('reset membersihkan timer, menyembunyikan, dan bisa start lagi', () => {
        vi.useFakeTimers();
        const doc = fakeDoc();
        startSearchProgress(doc);
        vi.advanceTimersByTime(1000);
        resetSearchProgress(doc);
        expect(isSearchProgressActive()).toBe(false);
        expect(doc.container.hidden).toBe(true);
        expect(doc.bar.style.transform).toBe('scaleX(0)');
        expect(doc.container.attrs['aria-valuetext']).toBeUndefined();
        // Pencarian berikutnya tetap dapat dilakukan.
        expect(startSearchProgress(doc)).toBe(true);
        resetSearchProgress(doc);
    });

    it('ARIA jujur: tanpa angka palsu', () => {
        const doc = fakeDoc();
        startSearchProgress(doc);
        // Role/aria-label hidup di Blade; JS tidak pernah menulis angka
        // valuemin/valuemax/valuenow palsu.
        expect(doc.container.attrs['aria-valuemin']).toBeUndefined();
        expect(doc.container.attrs['aria-valuemax']).toBeUndefined();
        expect(doc.container.attrs['aria-valuenow']).toBeUndefined();
        expect(doc.container.attrs['aria-valuetext']).toBe('Pencarian sedang berlangsung');
        expect(doc.bar.style.transform).toContain('scaleX(');
        resetSearchProgress(doc);
    });

    it('reduced motion: statis tanpa interval', () => {
        vi.stubGlobal('matchMedia', () => ({ matches: true }));
        vi.useFakeTimers();
        const doc = fakeDoc();
        expect(startSearchProgress(doc)).toBe(true);
        expect(doc.container.hidden).toBe(false);
        const shown = doc.bar.style.transform;
        vi.advanceTimersByTime(5000);
        expect(doc.bar.style.transform).toBe(shown);
        resetSearchProgress(doc);
    });
});
