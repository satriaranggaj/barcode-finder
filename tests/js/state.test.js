import { describe, expect, it } from 'vitest';
import { createState, toWire, transition } from '../../resources/js/selection/state.js';

const BOX = { x: 0.1, y: 0.1, width: 0.5, height: 0.5 };
const OTHER = { x: 0.2, y: 0.3, width: 0.4, height: 0.2 };

describe('createState', () => {
    it('starts with no box and source full', () => {
        expect(createState()).toEqual({ box: null, auto: null, source: 'full', touched: false });
        expect(toWire(createState())).toEqual({ cropJson: '', source: 'full' });
    });

    it('keeps a preloaded crop as manual', () => {
        const state = createState(BOX);
        expect(state.box).toEqual(BOX);
        expect(state.source).toBe('manual');
        expect(toWire(state)).toEqual({ cropJson: JSON.stringify(BOX), source: 'manual' });
    });

    it('ignores an invalid preloaded crop', () => {
        expect(createState({ x: 2, y: 0, width: 0.5, height: 0.5 }).box).toBeNull();
    });

    it('preserves a valid stored source for a preloaded crop', () => {
        const auto = createState(BOX, true, 'auto');
        expect(auto.box).toEqual(BOX);
        expect(auto.source).toBe('auto');
        expect(toWire(auto)).toEqual({ cropJson: JSON.stringify(BOX), source: 'auto' });
    });

    it('falls back to manual when the stored source is not in contract', () => {
        expect(createState(BOX, true, 'legacy').source).toBe('manual');
        expect(createState(null, true, 'auto').source).toBe('full');
    });
});

describe('auto-selection transitions', () => {
    it('applies the proposal with source auto when untouched', () => {
        const state = transition(createState(), { type: 'auto-applied', box: BOX });
        expect(state.box).toEqual(BOX);
        expect(state.auto).toEqual(BOX);
        expect(state.source).toBe('auto');
        expect(toWire(state)).toEqual({ cropJson: JSON.stringify(BOX), source: 'auto' });
    });

    it('keeps a user edit and only remembers the proposal for reset', () => {
        const edited = transition(createState(), { type: 'user-edit', box: OTHER });
        const state = transition(edited, { type: 'auto-applied', box: BOX });
        expect(state.box).toEqual(OTHER);
        expect(state.source).toBe('manual');
        expect(state.auto).toEqual(BOX);
    });
});

describe('user interactions', () => {
    it('marks manual edits as source manual', () => {
        const state = transition(transition(createState(), { type: 'auto-applied', box: BOX }), { type: 'user-edit', box: OTHER });
        expect(state.box).toEqual(OTHER);
        expect(state.source).toBe('manual');
        expect(state.touched).toBe(true);
    });

    it('clearing the box switches to full image', () => {
        const state = transition(transition(createState(), { type: 'auto-applied', box: BOX }), { type: 'user-edit', box: null });
        expect(state.box).toBeNull();
        expect(state.source).toBe('full');
        expect(toWire(state)).toEqual({ cropJson: '', source: 'full' });
    });

    it('picking a candidate sends it as a manual crop', () => {
        const state = transition(transition(createState(), { type: 'auto-applied', box: BOX }), { type: 'candidate', box: OTHER });
        expect(state.box).toEqual(OTHER);
        expect(state.source).toBe('manual');
        expect(state.touched).toBe(true);
    });

    it('reset restores the auto proposal with source auto', () => {
        const state = transition(
            transition(transition(createState(), { type: 'auto-applied', box: BOX }), { type: 'user-edit', box: OTHER }),
            { type: 'reset-auto' },
        );
        expect(state.box).toEqual(BOX);
        expect(state.source).toBe('auto');
    });

    it('reset without any auto proposal falls back to full image', () => {
        const state = transition(transition(createState(), { type: 'user-edit', box: OTHER }), { type: 'reset-auto' });
        expect(state.box).toBeNull();
        expect(state.source).toBe('full');
    });
});

describe('invalid events are ignored', () => {
    it('candidate with an out-of-contract box does not change state', () => {
        const before = transition(createState(), { type: 'auto-applied', box: BOX });
        const after = transition(before, { type: 'candidate', box: { x: 0, y: 0, width: 1.5, height: 0.5 } });
        expect(after).toEqual(before);
    });

    it('unknown event types leave the state untouched', () => {
        const state = createState();
        expect(transition(state, { type: 'nonsense' })).toBe(state);
    });
});
