import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';
import {
    applyCompressionEvent,
    applySelectionEvent,
    bindSearchForm,
    createReadinessState,
    emitCompression,
    isSearchReady,
    readinessView,
} from '../../resources/js/search-readiness.js';
import { handleSearchSubmit, setSearchLoading } from '../../resources/js/submit-loading.js';

// --- Minimal fake DOM: just enough for the readiness wiring. ---

function matchSelector(element, selector) {
    const parts = selector.split(',').map((part) => part.trim());
    return parts.some((part) => {
        const tagMatch = part.match(/^([a-zA-Z][a-zA-Z0-9]*)/);
        const tag = tagMatch ? tagMatch[1].toLowerCase() : null;
        if (tag && element.tag !== tag) return false;
        const idMatch = part.match(/#([A-Za-z0-9_-]+)/);
        if (idMatch && element.attrs.id !== idMatch[1]) return false;
        const attrPattern = /\[([A-Za-z0-9_-]+)(?:="([^"]*)")?\]/g;
        let found = true;
        let match;
        // eslint-disable-next-line no-cond-assign
        while ((match = attrPattern.exec(part)) !== null) {
            const [, name, value] = match;
            const has =
                (element.dataset && name in element.dataset) ||
                (element.attrs && name in element.attrs) ||
                (name === 'name' && element.attrs.name !== undefined);
            const actual =
                element.dataset?.[name] ?? element.attrs?.[name] ?? (name === 'name' ? element.attrs.name : undefined);
            if (!has) {
                found = false;
                break;
            }
            if (value !== undefined && String(actual) !== value) {
                found = false;
                break;
            }
        }
        return found;
    });
}

function fakeElement(tag = 'div', attrs = {}) {
    const listeners = {};
    const element = {
        tag,
        children: [],
        dataset: { ...(attrs.dataset || {}) },
        attrs: { ...(attrs.attrs || {}) },
        hidden: !!attrs.hidden,
        disabled: false,
        innerHTML: attrs.html || '',
        textContent: '',
        style: {},
        parent: null,
        files: attrs.files || [],
        classList: (() => {
            const set = new Set();
            return {
                add: (cls) => set.add(cls),
                remove: (cls) => set.delete(cls),
                contains: (cls) => set.has(cls),
            };
        })(),
        setAttribute(key, value) {
            element.attrs[key] = value;
        },
        getAttribute(key) {
            return element.attrs[key];
        },
        removeAttribute(key) {
            delete element.attrs[key];
        },
        appendChild(child) {
            element.children.push(child);
            child.parent = element;
            return child;
        },
        insertBefore(child, ref) {
            const index = ref ? element.children.indexOf(ref) : -1;
            if (index >= 0) element.children.splice(index, 0, child);
            else element.children.push(child);
            child.parent = element;
            return child;
        },
        removeChild(child) {
            const index = element.children.indexOf(child);
            if (index >= 0) element.children.splice(index, 1);
            return child;
        },
        remove() {
            if (element.parent) element.parent.removeChild(element);
        },
        addEventListener(type, fn) {
            (listeners[type] = listeners[type] || []).push(fn);
        },
        dispatchEvent(event) {
            event.target = event.target || element;
            (listeners[event.type] || []).slice().forEach((fn) => fn(event));
            if (event.bubbles && element.parent) element.parent.dispatchEvent(event);
            return true;
        },
        querySelector(selector) {
            return element.querySelectorAll(selector)[0] || null;
        },
        querySelectorAll(selector) {
            const found = [];
            const walk = (node) => {
                node.children.forEach((child) => {
                    if (matchSelector(child, selector)) found.push(child);
                    walk(child);
                });
            };
            walk(element);
            return found;
        },
        closest(selector) {
            const tag = String(selector).toLowerCase();
            let node = element.parent;
            while (node) {
                if (node.tag === tag) return node;
                node = node.parent;
            }
            return null;
        },
    };
    return element;
}

function fakeDocument() {
    const byId = {};
    const roots = [];
    const doc = {
        createElement: (tag) => fakeElement(tag),
        getElementById: (id) => byId[id] || null,
        querySelectorAll: (selector) => {
            const found = [];
            roots.forEach((root) => {
                root.querySelectorAll(selector).forEach((node) => found.push(node));
            });
            return found;
        },
        __register: (id, element) => {
            byId[id] = element;
        },
        __mount: (element) => {
            roots.push(element);
        },
    };
    return doc;
}

class FakeCustomEvent {
    constructor(type, init = {}) {
        this.type = type;
        this.bubbles = !!init.bubbles;
        this.detail = init.detail;
        this.target = null;
    }
}

function bigFile(name = 'foto.jpg') {
    return new File([new Uint8Array(2 * 1024 * 1024)], name, { type: 'image/jpeg' });
}

function tinyFile(name = 'kecil.jpg') {
    return new File([new Uint8Array(10)], name, { type: 'image/jpeg' });
}

function searchFormFixture({ withSelection = true, files = [] } = {}) {
    const doc = fakeDocument();
    const form = fakeElement('form');
    const input = fakeElement('input', {
        dataset: {},
        attrs: { name: 'image' },
    });
    input.files = files;
    input.form = form;
    const button = fakeElement('button', { html: 'Konfirmasi & cari →' });
    doc.__register('home-search-submit', button);
    form.appendChild(input);
    let container = null;
    if (withSelection) {
        container = fakeElement('div', { dataset: {} });
        container.attrs['data-object-selection'] = '';
        form.appendChild(container);
    }
    form.appendChild(button);
    doc.__mount(form);
    return { doc, form, input, button, container };
}

beforeEach(() => {
    vi.stubGlobal('CustomEvent', FakeCustomEvent);
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('readiness state machine (pure)', () => {
    it('compression fallback tetap memenuhi mandatory readiness', () => {
        const state = createReadinessState();
        expect(isSearchReady(state)).toBe(false);
        expect(applyCompressionEvent(state, { state: 'ready' }, undefined)).toBe(true);
        expect(isSearchReady(state)).toBe(false);
        expect(applySelectionEvent(state, { state: 'fallback' }, undefined)).toBe(true);
        expect(isSearchReady(state)).toBe(true);
    });

    it('event basi (revision mismatch) diabaikan', () => {
        const state = createReadinessState();
        expect(applyCompressionEvent(state, { state: 'ready', revision: 1 }, 2)).toBe(false);
        expect(state.compression).toBe('idle');
        expect(applyCompressionEvent(state, { state: 'ready', revision: 2 }, 2)).toBe(true);
        expect(applySelectionEvent(state, { state: 'ready', revision: 9 }, 2)).toBe(false);
        expect(state.selection).toBe('idle');
    });

    it('event invalid diabaikan', () => {
        const state = createReadinessState();
        expect(applyCompressionEvent(state, null, 1)).toBe(false);
        expect(applyCompressionEvent(state, { state: 'resized' }, 1)).toBe(false);
        expect(applySelectionEvent(state, {}, 1)).toBe(false);
        expect(applyCompressionEvent(null, { state: 'ready' }, 1)).toBe(false);
    });

    it('view model: label dan marker tiap stage', () => {
        let view = readinessView(createReadinessState());
        expect(view.ready).toBe(false);
        expect(view.title).toBe('Menyiapkan foto…');
        view = readinessView({ compression: 'ready', selection: 'ready' });
        expect(view.title).toBe('Foto siap');
        expect(view.stages[0]).toMatchObject({ marker: '✓', label: 'Gambar siap' });
        expect(view.stages[1]).toMatchObject({ marker: '✓', label: 'Area barang siap' });
        expect(view.note).toContain('Sesuaikan');
        view = readinessView({ compression: 'processing', selection: 'idle' });
        expect(view.stages[0]).toMatchObject({ marker: '●', label: 'Mengoptimalkan gambar…' });
        view = readinessView({ compression: 'ready', selection: 'fallback' });
        expect(view.stages[1].marker).toBe('!');
        expect(view.note).toContain('foto penuh');
    });
});

describe('readiness controller (bound form)', () => {
    it('foto besar: processing + button disabled; selesai: enabled', () => {
        const { doc, form, input, button } = searchFormFixture({ files: [bigFile()] });
        const controller = bindSearchForm(doc, form, input);
        input.dispatchEvent({ type: 'change', bubbles: false });
        expect(controller.panel.hidden).toBe(false);
        expect(button.disabled).toBe(true);
        expect(button.innerHTML).toContain('Menyiapkan foto…');
        expect(controller.state.compression).toBe('processing');

        input._lenskuRevision = 7;
        input.dispatchEvent({ type: 'change', bubbles: false });
        controller.state.compression = 'processing';
        emitCompression(input, 'ready', 7);
        expect(controller.state.compression).toBe('ready');
        expect(button.disabled).toBe(true); // selection masih idle
        emitSelection(input, 'ready', 7);
        expect(button.disabled).toBe(false);
        expect(button.innerHTML).toBe('Konfirmasi & cari →');
    });

    function emitSelection(input, state, revision) {
        input.dispatchEvent(
            new FakeCustomEvent('lensku:selection', { bubbles: true, detail: { state, revision } })
        );
    }

    it('compression gagal: fallback, button tetap enabled', () => {
        const { doc, form, input, button } = searchFormFixture({ files: [bigFile()] });
        const controller = bindSearchForm(doc, form, input);
        input.dispatchEvent({ type: 'change', bubbles: false });
        input._lenskuRevision = 3;
        emitCompression(input, 'fallback', 3);
        emitSelection(input, 'ready', 3);
        expect(button.disabled).toBe(false);
        expect(button.innerHTML).toBe('Konfirmasi & cari →');
        expect(controller.state.compression).toBe('fallback');
    });

    it('file kecil: compression langsung ready, tidak stuck', () => {
        const { doc, form, input, button } = searchFormFixture({ files: [tinyFile()] });
        const controller = bindSearchForm(doc, form, input);
        input.dispatchEvent({ type: 'change', bubbles: false });
        expect(controller.state.compression).toBe('ready');
        expect(button.disabled).toBe(true); // menunggu selection, bukan compression
        input._lenskuRevision = 1;
        emitSelection(input, 'ready', 1);
        expect(button.disabled).toBe(false);
    });

    it('selection timeout: fallback + note, search tetap bisa', () => {
        const { doc, form, input, button } = searchFormFixture({ files: [bigFile()] });
        const controller = bindSearchForm(doc, form, input);
        input.dispatchEvent({ type: 'change', bubbles: false });
        input._lenskuRevision = 5;
        emitCompression(input, 'ready', 5);
        emitSelection(input, 'processing', 5);
        expect(controller.panel.hidden).toBe(false);
        emitSelection(input, 'fallback', 5);
        expect(button.disabled).toBe(false);
        const view = readinessView(controller.state);
        expect(view.note).toContain('foto penuh');
    });

    it('completion foto A tidak boleh mengaktifkan state foto B', () => {
        const { doc, form, input, button } = searchFormFixture({ files: [bigFile('a.jpg')] });
        const controller = bindSearchForm(doc, form, input);
        input.dispatchEvent({ type: 'change', bubbles: false });
        input._lenskuRevision = 1;
        // Pengguna mengganti ke foto B: begin baru + revisi naik.
        input.files = [bigFile('b.jpg')];
        input._lenskuRevision = 2;
        input.dispatchEvent({ type: 'change', bubbles: false });
        // Terlambat: completion A (revision 1) tiba setelah state B.
        emitCompression(input, 'ready', 1);
        expect(controller.state.compression).toBe('processing');
        expect(button.disabled).toBe(true);
        emitSelection(input, 'ready', 1);
        expect(controller.state.selection).toBe('idle');
        // Completion B yang sah tetap diterapkan.
        emitCompression(input, 'ready', 2);
        emitSelection(input, 'ready', 2);
        expect(button.disabled).toBe(false);
    });

    it('tanpa selection container: fallback, tidak stuck', () => {
        const { doc, form, input, button } = searchFormFixture({ withSelection: false, files: [tinyFile()] });
        bindSearchForm(doc, form, input);
        input.dispatchEvent({ type: 'change', bubbles: false });
        expect(button.disabled).toBe(false);
    });

    it('input dikosongkan: reset + panel sembunyi', () => {
        const { doc, form, input, button } = searchFormFixture({ files: [bigFile()] });
        const controller = bindSearchForm(doc, form, input);
        input.dispatchEvent({ type: 'change', bubbles: false });
        expect(controller.panel.hidden).toBe(false);
        input.files = [];
        input.dispatchEvent({ type: 'change', bubbles: false });
        expect(controller.panel.hidden).toBe(true);
        expect(button.innerHTML).toBe('Konfirmasi & cari →');
    });
});

describe('production structure: container + button outside form', () => {
    function externalFixture({ files = [], formId = 'home-image-search-form' } = {}) {
        const doc = fakeDocument();
        const wrap = fakeElement('div');
        const container = fakeElement('div', { dataset: { form: formId } });
        container.attrs['data-object-selection'] = '';
        const button = fakeElement('button', { html: 'Konfirmasi & cari →' });
        doc.__register('home-search-submit', button);
        const form = fakeElement('form', { attrs: { id: formId } });
        const input = fakeElement('input', {
            dataset: {},
            attrs: { name: 'image', 'data-image-preview': '' },
        });
        input.files = files;
        input.form = form;
        form.appendChild(input);
        wrap.appendChild(container);
        wrap.appendChild(button);
        wrap.appendChild(form);
        doc.__mount(wrap);
        return { doc, form, input, button, container };
    }

    function selectionEvent(container, state, revision) {
        container.dispatchEvent(
            new FakeCustomEvent('lensku:selection', { bubbles: true, detail: { state, revision } })
        );
    }

    it('1. big image: processing + button disabled', () => {
        const { doc, form, input, button } = externalFixture({ files: [bigFile()] });
        bindSearchForm(doc, form, input);
        input.dispatchEvent({ type: 'change', bubbles: false });
        expect(button.disabled).toBe(true);
        expect(button.innerHTML).toContain('Menyiapkan foto…');
    });

    it('2. compression ready, selection processing: belum ready', () => {
        const { doc, form, input, button } = externalFixture({ files: [bigFile()] });
        bindSearchForm(doc, form, input);
        input.dispatchEvent({ type: 'change', bubbles: false });
        input._lenskuRevision = 1;
        emitCompression(input, 'ready', 1);
        selectionEvent(input, 'processing', 1);
        expect(button.disabled).toBe(true);
    });

    it('3. selection ready dari external container: enabled', () => {
        const { doc, form, input, button, container } = externalFixture({ files: [bigFile()] });
        bindSearchForm(doc, form, input);
        input.dispatchEvent({ type: 'change', bubbles: false });
        input._lenskuRevision = 1;
        emitCompression(input, 'ready', 1);
        selectionEvent(container, 'ready', 1);
        expect(button.disabled).toBe(false);
        expect(button.innerHTML).toBe('Konfirmasi & cari →');
    });

    it('4-5. external fallback/timeout: enabled, tidak stuck', () => {
        const { doc, form, input, button, container } = externalFixture({ files: [bigFile()] });
        bindSearchForm(doc, form, input);
        input.dispatchEvent({ type: 'change', bubbles: false });
        input._lenskuRevision = 1;
        emitCompression(input, 'ready', 1);
        selectionEvent(container, 'fallback', 1);
        expect(button.disabled).toBe(false);
        expect(button.innerHTML).toBe('Konfirmasi & cari →');
    });

    it('6. stale event A dari external container diabaikan setelah ganti B', () => {
        const { doc, form, input, button, container } = externalFixture({ files: [bigFile('a.jpg')] });
        const controller = bindSearchForm(doc, form, input);
        input.dispatchEvent({ type: 'change', bubbles: false });
        input.files = [bigFile('b.jpg')];
        input._lenskuRevision = 2;
        input.dispatchEvent({ type: 'change', bubbles: false });
        selectionEvent(container, 'ready', 1);
        expect(controller.state.selection).toBe('idle');
        expect(button.disabled).toBe(true);
        selectionEvent(container, 'ready', 2);
        emitCompression(input, 'ready', 2);
        expect(button.disabled).toBe(false);
    });

    it('7. dua search form: event container A tidak mengubah B', () => {
        const doc = fakeDocument();
        const build = (formId, file, label) => {
            const wrap = fakeElement('div');
            const container = fakeElement('div', { dataset: { form: formId } });
            container.attrs['data-object-selection'] = '';
            const form = fakeElement('form', { attrs: { id: formId } });
            const input = fakeElement('input', { attrs: { name: 'image', 'data-image-preview': '' } });
            input.files = [file];
            input.form = form;
            const button = fakeElement('button', { html: label });
            button.attrs['data-preview-submit'] = '';
            form.appendChild(input);
            form.appendChild(button);
            wrap.appendChild(container);
            wrap.appendChild(form);
            doc.__mount(wrap);
            return { form, input, button, container };
        };
        const a = build('form-a', bigFile('a.jpg'), 'Cari A');
        const b = build('form-b', bigFile('b.jpg'), 'Cari B');
        const controllerB = bindSearchForm(doc, b.form, b.input);
        bindSearchForm(doc, a.form, a.input);
        a.input.dispatchEvent({ type: 'change', bubbles: false });
        b.input.dispatchEvent({ type: 'change', bubbles: false });
        a.input._lenskuRevision = 1;
        b.input._lenskuRevision = 1;
        a.container.dispatchEvent(
            new FakeCustomEvent('lensku:selection', { bubbles: true, detail: { state: 'ready', revision: 1 } })
        );
        expect(controllerB.state.selection).toBe('idle');
        expect(b.button.disabled).toBe(true);
        // Button B tetap pada preparing miliknya sendiri, bukan ready dari A.
        expect(b.button.innerHTML).toContain('Menyiapkan foto…');
        expect(b.button.innerHTML).not.toContain('Mencari…');
        expect(a.button.disabled).toBe(true); // compression A belum ready
    });

    it('8-9-10. preparing, ready, submitting labels', () => {
        const { doc, form, input, button } = externalFixture({ files: [bigFile()] });
        bindSearchForm(doc, form, input);
        input.dispatchEvent({ type: 'change', bubbles: false });
        expect(button.innerHTML).toContain('Menyiapkan foto…');
        input._lenskuRevision = 1;
        emitCompression(input, 'ready', 1);
        selectionEvent(input, 'ready', 1);
        expect(button.innerHTML).toBe('Konfirmasi & cari →');
        const submitForm = {
            dataset: {},
            checkValidity: () => true,
            reportValidity: vi.fn(),
            requestSubmit: vi.fn(),
        };
        handleSearchSubmit(submitForm, {}, { preventDefault: vi.fn() }, { getButton: () => button });
        expect(button.innerHTML).toContain('Mencari…');
    });
});

describe('readiness × submit interplay', () => {
    it('siap lalu submit: "Mencari…" (direct, sekali)', () => {
        const { doc, form, input, button } = searchFormFixture({ files: [tinyFile()] });
        bindSearchForm(doc, form, input);
        input.dispatchEvent({ type: 'change', bubbles: false });
        input._lenskuRevision = 4;
        emitCompression(input, 'ready', 4);
        input.dispatchEvent(
            new FakeCustomEvent('lensku:selection', { bubbles: true, detail: { state: 'ready', revision: 4 } })
        );
        expect(button.disabled).toBe(false);
        const submitForm = {
            dataset: {},
            checkValidity: () => true,
            reportValidity: vi.fn(),
            requestSubmit: vi.fn(),
        };
        const outcome = handleSearchSubmit(submitForm, {}, { preventDefault: vi.fn() }, { getButton: () => button });
        expect(outcome).toBe('direct');
        expect(button.innerHTML).toContain('Mencari…');
        expect(button.innerHTML).not.toContain('Menyiapkan foto…');
        expect(submitForm.requestSubmit).not.toHaveBeenCalled();
    });

    it('preparing vs submitting memakai label berbeda', () => {
        const { doc, form, input, button } = searchFormFixture({ files: [bigFile()] });
        bindSearchForm(doc, form, input);
        input.dispatchEvent({ type: 'change', bubbles: false });
        expect(button.innerHTML).toContain('Menyiapkan foto…');
        expect(button.innerHTML).not.toContain('Mencari…');
        setSearchLoading(button, true);
        expect(button.innerHTML).toContain('Mencari…');
        expect(button.innerHTML).not.toContain('Menyiapkan foto…');
    });
});
