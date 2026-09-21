import { describe, expect, it } from 'vitest';
import { setSearchLoading } from '../../resources/js/submit-loading.js';

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
});
