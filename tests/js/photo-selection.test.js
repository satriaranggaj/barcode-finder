import { describe, it, expect } from 'vitest';
import {
    formatFileSize,
    buildFileListModel,
    removeAt,
    shouldShowSelection,
    setTargetFiles,
    revokeObjectUrlsIn,
    panelIndexOf,
} from '../../resources/js/photo-selection.js';

const file = (name, size) => ({ name, size });

describe('formatFileSize', () => {
    it('formats bytes, kilobytes, and megabytes', () => {
        expect(formatFileSize(0)).toBe('0 KB');
        expect(formatFileSize(500)).toBe('1 KB');
        expect(formatFileSize(204800)).toBe('200 KB');
        expect(formatFileSize(1572864)).toBe('1.5 MB');
    });
});

describe('buildFileListModel', () => {
    it('describes an empty selection', () => {
        expect(buildFileListModel([])).toEqual({ count: 0, summary: '', items: [] });
    });

    it('labels single and multiple selections', () => {
        expect(buildFileListModel([file('a.jpg', 100)]).summary).toBe('1 foto dipilih');
        const model = buildFileListModel([file('a.jpg', 100), file('b.png', 204800)]);
        expect(model.summary).toBe('2 foto dipilih');
        expect(model.items).toEqual([
            { index: 0, name: 'a.jpg', sizeLabel: '1 KB' },
            { index: 1, name: 'b.png', sizeLabel: '200 KB' },
        ]);
    });
});

describe('removeAt', () => {
    it('removes only the indexed file and preserves the rest', () => {
        const files = [file('a.jpg', 1), file('b.jpg', 2), file('c.jpg', 3)];
        expect(removeAt(files, 1).map((entry) => entry.name)).toEqual(['a.jpg', 'c.jpg']);
        expect(removeAt(files, 0)).toHaveLength(2);
        expect(removeAt(files, 5)).toHaveLength(3);
        // Original array untouched.
        expect(files).toHaveLength(3);
    });
});

describe('shouldShowSelection', () => {
    it('hides empty selections', () => {
        expect(shouldShowSelection(0)).toBe(false);
        expect(shouldShowSelection(2)).toBe(true);
    });
});

describe('setTargetFiles', () => {
    const fakes = () => {
        const added = [];
        return {
            added,
            Transfer: class {
                constructor() {
                    this.items = { add: (file) => added.push(file) };
                    this.files = added;
                }
            },
        };
    };

    it('replaces files, bumps revision, and notifies renderers', () => {
        const dispatched = [];
        const target = { files: [file('old.jpg', 1)], _lenskuRevision: 4 };
        const { Transfer, added } = fakes();
        const next = [file('a.jpg', 1), file('b.jpg', 2)];

        const revision = setTargetFiles(target, next, {
            Transfer,
            createEvent: () => ({ type: 'change' }),
            dispatch: (element, event) => dispatched.push([element, event]),
        });

        expect(revision).toBe(5);
        expect(target._lenskuRevision).toBe(5);
        expect(added).toEqual(next);
        expect(target.files).toBe(added);
        expect(dispatched).toHaveLength(1);
        expect(dispatched[0][1]).toEqual({ type: 'change' });
    });

    it('clears to an empty list on cancel', () => {
        const target = { files: [file('a.jpg', 1)] };
        const { Transfer, added } = fakes();
        let events = 0;

        setTargetFiles(target, [], {
            Transfer,
            createEvent: () => ({}),
            dispatch: () => { events += 1; },
        });

        expect(added).toEqual([]);
        expect(events).toBe(1);
    });

    it('is a no-op without a target', () => {
        expect(setTargetFiles(null, [file('a.jpg', 1)])).toBe(0);
    });
});

describe('revokeObjectUrlsIn', () => {
    it('revokes tracked preview URLs and clears markers', () => {
        const revoked = [];
        const images = [
            { dataset: { objectUrl: 'blob:a' } },
            { dataset: {} },
        ];
        const root = { querySelectorAll: () => images };
        const original = globalThis.URL;

        globalThis.URL = { revokeObjectURL: (url) => revoked.push(url) };
        try {
            expect(revokeObjectUrlsIn(root)).toBe(1);
        } finally {
            globalThis.URL = original;
        }
        expect(revoked).toEqual(['blob:a']);
        expect(images[0].dataset.objectUrl).toBeUndefined();
    });

    it('tolerates missing roots', () => {
        expect(revokeObjectUrlsIn(null)).toBe(0);
        expect(revokeObjectUrlsIn({})).toBe(0);
    });
});

describe('panelIndexOf', () => {
    const panel = (withInput) => ({
        tagName: 'DIV',
        parentElement: null,
        querySelector: (selector) => (withInput && selector === '[data-result-input]' ? {} : null),
    });

    it('maps a corner button to its panel position', () => {
        const first = panel(true);
        const second = panel(true);
        const container = {
            children: [first, second],
            querySelector: () => null,
        };
        first.parentElement = container;
        second.parentElement = container;
        const inner = { parentElement: second };
        const deepButton = { parentElement: inner };

        expect(panelIndexOf(container, first)).toBe(0);
        expect(panelIndexOf(container, deepButton)).toBe(1);
    });

    it('ignores non-panel children', () => {
        const title = { tagName: 'P', parentElement: null, querySelector: () => null };
        const only = panel(true);
        const container = { children: [title, only] };
        title.parentElement = container;
        only.parentElement = container;

        expect(panelIndexOf(container, only)).toBe(0);
        expect(panelIndexOf(container, title)).toBe(-1);
    });

    it('returns -1 without a container or button', () => {
        expect(panelIndexOf(null, {})).toBe(-1);
        expect(panelIndexOf({ children: [] }, null)).toBe(-1);
    });
});
