import { describe, expect, it, vi } from 'vitest';
import {
    finalizeGatedSubmit,
    handleSearchSubmit,
    resetSearchButtons,
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

    it('form valid -> resubmit tepat sekali, loading tetap aktif', () => {
        const button = fakeButton();
        setSearchLoading(button, true);
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
        expect(finalizeGatedSubmit(form, button)).toBe('resubmitted');
        expect(calls).toEqual(['submit-once']);
        expect(button.disabled).toBe(true);
        expect(button.innerHTML).toContain('Mencari…');
    });

    it('form invalid -> tidak resubmit, tombol pulih total', () => {
        const button = fakeButton();
        setSearchLoading(button, true);
        let reported = false;
        let submitted = false;
        const form = {
            dataset: {},
            checkValidity: () => false,
            reportValidity: () => {
                reported = true;
            },
            requestSubmit: () => {
                submitted = true;
            },
        };
        expect(finalizeGatedSubmit(form, button)).toBe('aborted-invalid');
        expect(submitted).toBe(false);
        expect(reported).toBe(true);
        expect(button.disabled).toBe(false);
        expect(button.innerHTML).toBe('Konfirmasi & cari →');
        expect(button.getAttribute('aria-busy')).toBeUndefined();
        expect(button.classList.contains('cursor-wait')).toBe(false);
    });

    it('tanpa form -> abort aman tanpa submit', () => {
        const requestSubmit = vi.fn();
        expect(finalizeGatedSubmit(null, fakeButton())).toBe('aborted-invalid');
        expect(requestSubmit).not.toHaveBeenCalled();
    });

    it('requestSubmit throw -> tombol pulih, tidak stuck', () => {
        const button = fakeButton();
        setSearchLoading(button, true);
        const form = {
            dataset: {},
            checkValidity: () => true,
            reportValidity: vi.fn(),
            requestSubmit: () => {
                throw new Error('not connected');
            },
        };
        expect(finalizeGatedSubmit(form, button)).toBe('aborted-invalid');
        expect(button.disabled).toBe(false);
        expect(button.innerHTML).toBe('Konfirmasi & cari →');
        expect(form.dataset.lenskuResubmit).toBeUndefined();
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

    it('handleSearchSubmit: direct tanpa compression, gated menunggu lalu sekali', async () => {
        const button = fakeButton();
        const directForm = { dataset: {}, checkValidity: () => true };
        const directEvent = { preventDefault: vi.fn() };
        expect(
            handleSearchSubmit(directForm, {}, directEvent, {
                setLoading: (element, active) => setSearchLoading(element, active),
                getButton: () => button,
            })
        ).toBe('direct');
        expect(directEvent.preventDefault).not.toHaveBeenCalled();
        expect(button.disabled).toBe(true);

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
        const gatedEvent = { preventDefault: vi.fn() };
        expect(
            handleSearchSubmit(gatedForm, { _lenskuCompress: gate }, gatedEvent, { getButton: () => button })
        ).toBe('gated');
        expect(gatedEvent.preventDefault).toHaveBeenCalledTimes(1);
        expect(gatedForm.requestSubmit).not.toHaveBeenCalled();
        release(['compressed']);
        await gate;
        await Promise.resolve();
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(gatedForm.requestSubmit).toHaveBeenCalledTimes(1);

        // Event re-entrant pasca-requestSubmit kembali lebih awal.
        expect(handleSearchSubmit(gatedForm, { _lenskuCompress: gate }, { preventDefault: vi.fn() })).toBe(
            'resumed'
        );
        expect(gatedForm.requestSubmit).toHaveBeenCalledTimes(1);
    });
});
