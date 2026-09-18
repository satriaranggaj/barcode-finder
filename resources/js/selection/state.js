/**
 * Selection state machine for the search/upload form. Pure transitions so the
 * crop wiring is testable without a DOM.
 *
 * box    : normalized crop, or null for full image
 * auto   : last box proposed by auto-selection (reset target)
 * source : wire value of selection_source — 'auto' | 'manual' | 'full'
 * touched: the user edited the box after auto-selection applied
 */
import { validBox } from './geometry.js';

const SOURCES = ['auto', 'manual', 'full'];

export function createState(initialBox = null, touched = false, initialSource = null) {
    const box = validBox(initialBox) ? { ...initialBox } : null;
    const source = box && SOURCES.includes(initialSource) ? initialSource : box ? 'manual' : 'full';
    return { box, auto: box, source, touched: !!touched };
}

export function transition(state, event) {
    switch (event.type) {
        case 'user-edit': {
            // Manual drag/resize/clear: manual crop, or full image when cleared.
            const box = validBox(event.box) ? { ...event.box } : null;
            return { ...state, box, source: box ? 'manual' : 'full', touched: true };
        }
        case 'candidate': {
            // User picked a specific candidate proposal -> wire as manual crop.
            const box = validBox(event.box) ? { ...event.box } : null;
            return box ? { ...state, box, source: 'manual', touched: true } : state;
        }
        case 'reset-auto': {
            // Restore the last auto proposal (or full image when none arrived).
            return { ...state, box: state.auto, source: state.auto ? 'auto' : 'full', touched: true };
        }
        case 'auto-applied': {
            // Auto-selection result: only applies when the user has not edited.
            const box = validBox(event.box) ? { ...event.box } : null;
            if (box === null) return state;
            if (state.touched) return { ...state, auto: box };
            return { ...state, box, auto: box, source: 'auto', touched: false };
        }
        default:
            return state;
    }
}

/** Serialize to the hidden form inputs. */
export function toWire(state) {
    return { cropJson: state.box ? JSON.stringify(state.box) : '', source: state.source };
}
