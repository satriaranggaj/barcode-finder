import { describe, expect, it, vi } from 'vitest';
import {
    finalizeGatedSubmit,
    handleSearchSubmit,
    resetSearchButtons,
    resetSearchPage,
    setSearchLoading,
} from '../../resources/js/submit-loading.js';

function fakeButton(html = 'Konfirmasi & cari →') {
    const classes = new Set();
    const attrs = {};
    return {
        innerHTML: html,
        disabled: false,
        dataset: {},
        classList: {
            add: (cls) => classes.add(cls),
            remove: (cls) => classes.delete(cls),
            contains: (cls) => classes.has(cls),
        },
        setAttribute: (key, value) => {
            attrs[key] = value;
        },
        removeAttribute: (key) => {
            delete attrs[key];
        },
        getAttribute: (key) => attrs[key],
    };
}

describe('submit-loading', () => {
    it('trigger mengubah tombol menjadi loading', () => {
        const button = fakeButton();
        setSearchLoading(button, true);
        expect(button.disabled).toBe(true);
        expect(button.getAttribute('aria-busy')).toBe('true');
        expect(button.innerHTML).toContain('Mencari…');
        expect(button.innerHTML).toContain('animate-spin');
        expect(button.classList.contains('opacity-70')).toBe(true);
    });

    it('loading idempoten dan teks asli pulih saat reset', () => {
        const button = fakeButton();
        setSearchLoading(button, true);
        setSearchLoading(button, true);
        expect(button.innerHTML).toContain('Mencari…');
        setSearchLoading(button, false);
        expect(button.innerHTML).toBe('Konfirmasi & cari →');
        expect(button.disabled).toBe(false);
        expect(button.getAttribute('aria-busy')).toBeUndefined();
        expect(button.classList.contains('opacity-70')).toBe(false);
    });

    it('aman untuk null dan tombol tanpa classList', () => {
        expect(() => setSearchLoading(null, true)).not.toThrow();
        expect(() => setSearchLoading({ dataset: {} }, false)).not.toThrow();
    });

    it('form valid -> resubmit tepat sekali', () => {
        const calls = [];
        const form = {
            dataset: {},
            checkValidity: () => true,
            reportValidity: () => {
                calls.push('report');
            },
            requestSubmit: () => {
                // Flag harus sudah terpasang SEBELUM requestSubmit agar
                // event submit re-entrant kembali lebih awal (anti double).
                calls.push(form.dataset.lenskuResubmit === 'true' ? 'submit-once' : 'submit-RACE');
            },
        };
        expect(finalizeGatedSubmit(form)).toBe('resubmitted');
        expect(calls).toEqual(['submit-once']);
    });

    it('form invalid -> tidak resubmit, state bersih untuk retry', () => {
        let reported = false;
        let submitted = false;
        const form = {
            dataset: { lenskuResubmit: 'true', searchWaiting: 'true', searchSubmitting: 'true' },
            checkValidity: () => false,
            reportValidity: () => {
                reported = true;
            },
            requestSubmit: () => {
                submitted = true;
            },
        };
        expect(finalizeGatedSubmit(form)).toBe('aborted-invalid');
        expect(submitted).toBe(false);
        expect(reported).toBe(true);
        expect(form.dataset.lenskuResubmit).toBeUndefined();
        expect(form.dataset.searchWaiting).toBeUndefined();
        expect(form.dataset.searchSubmitting).toBeUndefined();
    });

    it('tanpa form -> abort aman tanpa submit', () => {
        const requestSubmit = vi.fn();
        expect(finalizeGatedSubmit(null)).toBe('aborted-invalid');
        expect(requestSubmit).not.toHaveBeenCalled();
    });

    it('requestSubmit throw -> state bersih, bisa retry', () => {
        const form = {
            dataset: {},
            checkValidity: () => true,
            reportValidity: vi.fn(),
            requestSubmit: () => {
                throw new Error('not connected');
            },
        };
        expect(finalizeGatedSubmit(form)).toBe('aborted-invalid');
        expect(form.dataset.lenskuResubmit).toBeUndefined();
        // Retry berikutnya mulai dari state bersih.
        form.checkValidity = () => true;
        form.requestSubmit = vi.fn();
        expect(finalizeGatedSubmit(form)).toBe('resubmitted');
        expect(form.requestSubmit).toHaveBeenCalledTimes(1);
    });

    it('pageshow reset mengembalikan tombol home', () => {
        const button = fakeButton();
        setSearchLoading(button, true);
        const root = { getElementById: (id) => (id === 'home-search-submit' ? button : null) };
        expect(resetSearchButtons(root)).toBe(true);
        expect(button.innerHTML).toBe('Konfirmasi & cari →');
        expect(button.disabled).toBe(false);
        expect(resetSearchButtons(null)).toBe(false);
        expect(resetSearchButtons({})).toBe(false);
    });

    function fakeSubmitEvent() {
        return {
            defaultPrevented: false,
            preventDefault() {
                this.defaultPrevented = true;
            },
        };
    }

    it('A. direct: tidak prevent, progress sekali, submitter tak tersentuh', () => {
        const button = fakeButton();
        const form = { dataset: {}, checkValidity: () => true };
        const event = fakeSubmitEvent();
        const startProgress = vi.fn(() => true);
        expect(handleSearchSubmit(form, {}, event, { startProgress })).toBe('direct');
        // Final event: native POST dibiarkan jalan.
        expect(event.defaultPrevented).toBe(false);
        expect(startProgress).toHaveBeenCalledTimes(1);
        expect(form.dataset.searchSubmitting).toBe('true');
        // Submit button eksternal tidak dimutasi sama sekali.
        expect(button.disabled).toBe(false);
        expect(button.innerHTML).toBe('Konfirmasi & cari →');
    });

    it('B. gated: tahan, requestSubmit sekali, re-entry lolos native', async () => {
        const gatedForm = {
            dataset: {},
            checkValidity: () => true,
            reportValidity: vi.fn(),
            requestSubmit: vi.fn(),
        };
        let release;
        const gate = new Promise((resolve) => {
            release = resolve;
        });
        const first = fakeSubmitEvent();
        const startProgress = vi.fn(() => true);
        expect(handleSearchSubmit(gatedForm, { _lenskuCompress: gate }, first, { startProgress })).toBe('gated');
        expect(first.defaultPrevented).toBe(true);
        expect(startProgress).not.toHaveBeenCalled();
        expect(gatedForm.requestSubmit).not.toHaveBeenCalled();
        expect(gatedForm.dataset.searchWaiting).toBe('true');

        release(['compressed']);
        await gate;
        await Promise.resolve();
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(gatedForm.requestSubmit).toHaveBeenCalledTimes(1);

        // Re-entrant event: dikenali, TIDAK di-prevent, progress sekali.
        const second = fakeSubmitEvent();
        expect(
            handleSearchSubmit(gatedForm, { _lenskuCompress: gate }, second, { startProgress })
        ).toBe('resumed');
        expect(second.defaultPrevented).toBe(false);
        expect(startProgress).toHaveBeenCalledTimes(1);
        expect(gatedForm.requestSubmit).toHaveBeenCalledTimes(1);
        expect(gatedForm.dataset.lenskuResubmit).toBeUndefined();
    });

    it('C. double submit saat posting/waiting diblokir, sekali POST', async () => {
        const gatedForm = {
            dataset: {},
            checkValidity: () => true,
            reportValidity: vi.fn(),
            requestSubmit: vi.fn(),
        };
        let release;
        const gate = new Promise((resolve) => {
            release = resolve;
        });
        const startProgress = vi.fn(() => true);
        const deps = { startProgress };
        expect(handleSearchSubmit(gatedForm, { _lenskuCompress: gate }, fakeSubmitEvent(), deps)).toBe('gated');
        // Klik kedua saat masih menunggu kompresi: diabaikan.
        const dup = fakeSubmitEvent();
        expect(handleSearchSubmit(gatedForm, { _lenskuCompress: gate }, dup, deps)).toBe('duplicate');
        expect(dup.defaultPrevented).toBe(true);
        release(['compressed']);
        await gate;
        await Promise.resolve();
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(gatedForm.requestSubmit).toHaveBeenCalledTimes(1);
        // Re-entry intentional lolos.
        const reentry = fakeSubmitEvent();
        expect(handleSearchSubmit(gatedForm, { _lenskuCompress: gate }, reentry, deps)).toBe('resumed');
        expect(reentry.defaultPrevented).toBe(false);
        // Klik ketiga setelah posting dimulai: diblokir.
        const late = fakeSubmitEvent();
        expect(handleSearchSubmit(gatedForm, { _lenskuCompress: gate }, late, deps)).toBe('duplicate');
        expect(late.defaultPrevented).toBe(true);
        expect(gatedForm.requestSubmit).toHaveBeenCalledTimes(1);
        expect(startProgress).toHaveBeenCalledTimes(1);
    });

    it('D. invalid setelah compression: tanpa submit, state bersih, bisa retry', async () => {
        const gatedForm = {
            dataset: {},
            checkValidity: () => true,
            reportValidity: vi.fn(),
            requestSubmit: vi.fn(),
        };
        let release;
        const gate = new Promise((resolve) => {
            release = resolve;
        });
        const resetProgress = vi.fn();
        expect(
            handleSearchSubmit(gatedForm, { _lenskuCompress: gate }, fakeSubmitEvent(), { resetProgress })
        ).toBe('gated');
        gatedForm.checkValidity = () => false;
        release(['compressed']);
        await gate;
        await Promise.resolve();
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(gatedForm.requestSubmit).not.toHaveBeenCalled();
        expect(gatedForm.reportValidity).toHaveBeenCalledTimes(1);
        expect(resetProgress).toHaveBeenCalledTimes(1);
        expect(gatedForm.dataset.searchWaiting).toBeUndefined();
        expect(gatedForm.dataset.searchSubmitting).toBeUndefined();
        expect(gatedForm.dataset.lenskuResubmit).toBeUndefined();
        // Retry setelah valid kembali berjalan normal.
        gatedForm.checkValidity = () => true;
        const retry = fakeSubmitEvent();
        expect(handleSearchSubmit(gatedForm, {}, retry, {})).toBe('direct');
        expect(retry.defaultPrevented).toBe(false);
    });

    it('E. pageshow mereset semua state', () => {
        const button = fakeButton();
        setSearchLoading(button, true);
        const form = { dataset: { searchSubmitting: 'true', searchWaiting: 'true', lenskuResubmit: 'true' } };
        const root = {
            getElementById: (id) => (id === 'home-search-submit' ? button : null),
            querySelectorAll: () => [form],
        };
        resetSearchPage(root);
        expect(button.disabled).toBe(false);
        expect(button.innerHTML).toBe('Konfirmasi & cari →');
        expect(form.dataset.searchSubmitting).toBeUndefined();
        expect(form.dataset.searchWaiting).toBeUndefined();
        expect(form.dataset.lenskuResubmit).toBeUndefined();
    });

    it('production DOM: external button tidak pernah dimutasi', async () => {
        const button = fakeButton();
        const form = {
            dataset: {},
            checkValidity: () => true,
            reportValidity: vi.fn(),
            requestSubmit: vi.fn(),
        };
        let release;
        const gate = new Promise((resolve) => {
            release = resolve;
        });
        const snapshot = () => ({ disabled: button.disabled, html: button.innerHTML });
        const before = snapshot();
        handleSearchSubmit(form, { _lenskuCompress: gate }, fakeSubmitEvent(), {});
        release(['x']);
        await gate;
        await Promise.resolve();
        await new Promise((resolve) => setTimeout(resolve, 0));
        handleSearchSubmit(form, { _lenskuCompress: gate }, fakeSubmitEvent(), {});
        expect(snapshot()).toEqual(before);
    });
});
