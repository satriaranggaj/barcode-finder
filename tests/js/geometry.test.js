import { describe, expect, it } from 'vitest';
import {
    MIN_EXTENT,
    boxAt,
    boxFromDrag,
    clientToNormalized,
    contentRect,
    expandBox,
    moveBox,
    normalizedToClient,
    resizeBox,
    validBox,
} from '../../resources/js/selection/geometry.js';

describe('contentRect (object-fit: contain letterbox)', () => {
    it('fits a landscape image into a square container', () => {
        expect(contentRect(400, 400, 800, 400)).toEqual({ left: 0, top: 100, width: 400, height: 200 });
    });

    it('fits a portrait image into a wide container with side letterboxing', () => {
        expect(contentRect(800, 400, 400, 800)).toEqual({ left: 300, top: 0, width: 200, height: 400 });
    });

    it('upscales an image smaller than the container, preserving aspect', () => {
        expect(contentRect(200, 100, 50, 50)).toEqual({ left: 50, top: 0, width: 100, height: 100 });
    });

    it('returns null for degenerate inputs', () => {
        expect(contentRect(0, 100, 50, 50)).toBeNull();
        expect(contentRect(100, 0, 50, 50)).toBeNull();
        expect(contentRect(100, 100, 0, 50)).toBeNull();
    });
});

describe('clientToNormalized / normalizedToClient', () => {
    const rect = { left: 100, top: 50, width: 400, height: 200 };

    it('maps a displayed-image point to normalized coordinates', () => {
        expect(clientToNormalized(300, 150, rect)).toEqual({ x: 0.5, y: 0.5 });
        expect(clientToNormalized(100, 50, rect)).toEqual({ x: 0, y: 0 });
        expect(clientToNormalized(500, 250, rect)).toEqual({ x: 1, y: 1 });
    });

    it('is scale-independent: same normalized point for any display size', () => {
        const small = { left: 0, top: 0, width: 40, height: 20 };
        expect(clientToNormalized(20, 10, small)).toEqual(clientToNormalized(300, 150, rect));
    });

    it('clamps points outside the displayed image', () => {
        expect(clientToNormalized(-50, 1000, rect)).toEqual({ x: 0, y: 1 });
    });

    it('returns null for a degenerate rect', () => {
        expect(clientToNormalized(10, 10, { left: 0, top: 0, width: 0, height: 100 })).toBeNull();
    });

    it('round-trips a box through client pixels', () => {
        const box = { x: 0.1, y: 0.25, width: 0.4, height: 0.5 };
        const pixel = normalizedToClient(box, rect);
        expect(pixel).toEqual({ x: 140, y: 100, width: 160, height: 100 });
        expect(clientToNormalized(pixel.x, pixel.y, rect)).toEqual({ x: 0.1, y: 0.25 });
        expect(clientToNormalized(pixel.x + pixel.width, pixel.y + pixel.height, rect)).toEqual({ x: 0.5, y: 0.75 });
    });
});

describe('moveBox bounds', () => {
    it('translates and clamps at every image edge', () => {
        const box = { x: 0.2, y: 0.2, width: 0.3, height: 0.3 };
        expect(moveBox(box, 1, 1)).toEqual({ x: 0.7, y: 0.7, width: 0.3, height: 0.3 });
        expect(moveBox(box, -1, -1)).toEqual({ x: 0, y: 0, width: 0.3, height: 0.3 });
    });

    it('never lets the box leave the image for any delta', () => {
        for (let dx = -2; dx <= 2; dx += 0.5) {
            for (let dy = -2; dy <= 2; dy += 0.5) {
                const result = moveBox({ x: 0.5, y: 0.5, width: 0.4, height: 0.2 }, dx, dy);
                expect(result.x).toBeGreaterThanOrEqual(0);
                expect(result.y).toBeGreaterThanOrEqual(0);
                expect(result.x + result.width).toBeLessThanOrEqual(1 + 1e-9);
                expect(result.y + result.height).toBeLessThanOrEqual(1 + 1e-9);
            }
        }
    });
});

describe('resizeBox handles', () => {
    const box = { x: 0.4, y: 0.4, width: 0.2, height: 0.2 };

    it('enlarges from the se corner and shrinks from the nw corner', () => {
        expect(resizeBox(box, 'se', 0.1, 0.1)).toEqual({ x: 0.4, y: 0.4, width: 0.3, height: 0.3 });
        expect(resizeBox(box, 'nw', 0.1, 0.1)).toEqual({ x: 0.5, y: 0.5, width: 0.1, height: 0.1 });
    });

    it('resizes edges only along their axis', () => {
        expect(resizeBox(box, 'e', 0.1, 0.5)).toEqual({ x: 0.4, y: 0.4, width: 0.3, height: 0.2 });
        expect(resizeBox(box, 'n', 0.5, 0.1)).toEqual({ x: 0.4, y: 0.5, width: 0.2, height: 0.1 });
    });

    it('never crosses the opposite edge past the minimum extent', () => {
        expect(resizeBox(box, 'nw', 1, 1)).toEqual({ x: 0.6 - MIN_EXTENT, y: 0.6 - MIN_EXTENT, width: MIN_EXTENT, height: MIN_EXTENT });
        expect(resizeBox(box, 'se', -1, -1)).toEqual({ x: 0.4, y: 0.4, width: MIN_EXTENT, height: MIN_EXTENT });
    });

    it('clamps every handle to the image bounds', () => {
        for (const handle of ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w']) {
            for (let dx = -3; dx <= 3; dx += 0.5) {
                for (let dy = -3; dy <= 3; dy += 0.5) {
                    const result = resizeBox(box, handle, dx, dy);
                    expect(result.x).toBeGreaterThanOrEqual(0);
                    expect(result.y).toBeGreaterThanOrEqual(0);
                    expect(result.x + result.width).toBeLessThanOrEqual(1 + 1e-9);
                    expect(result.y + result.height).toBeLessThanOrEqual(1 + 1e-9);
                    expect(result.width).toBeGreaterThanOrEqual(MIN_EXTENT - 1e-9);
                    expect(result.height).toBeGreaterThanOrEqual(MIN_EXTENT - 1e-9);
                }
            }
        }
    });
});

describe('boxFromDrag / boxAt / expandBox', () => {
    it('builds a box regardless of drag direction', () => {
        expect(boxFromDrag({ x: 0.8, y: 0.7 }, { x: 0.2, y: 0.3 })).toEqual({ x: 0.2, y: 0.3, width: 0.6, height: 0.4 });
        expect(boxFromDrag({ x: 0.2, y: 0.3 }, { x: 0.8, y: 0.7 })).toEqual({ x: 0.2, y: 0.3, width: 0.6, height: 0.4 });
    });

    it('clamps a drag that starts at the image corner', () => {
        // Editor points are pre-clamped by clientToNormalized; the box itself is clamped again.
        expect(boxFromDrag({ x: 0, y: 0 }, { x: 0.3, y: 0.3 })).toEqual({ x: 0, y: 0, width: 0.3, height: 0.3 });
        expect(boxFromDrag({ x: 1, y: 1 }, { x: 0.7, y: 0.7 })).toEqual({ x: 0.7, y: 0.7, width: 0.3, height: 0.3 });
    });

    it('centers a tap box and keeps it inside the image', () => {
        expect(boxAt({ x: 0.05, y: 0.95 }, 0.2)).toEqual({ x: 0, y: 0.8, width: 0.2, height: 0.2 });
    });

    it('expands around the center and clamps at the border', () => {
        expect(expandBox({ x: 0.4, y: 0.4, width: 0.2, height: 0.2 }, 0.1))
            .toEqual({ x: 0.35, y: 0.35, width: 0.3, height: 0.3 });
        expect(expandBox({ x: 0, y: 0, width: 0.5, height: 0.5 }, 1))
            .toEqual({ x: 0, y: 0, width: 1, height: 1 });
    });
});

describe('validBox (backend contract filter)', () => {
    it('accepts valid boxes including the tolerance slack', () => {
        expect(validBox({ x: 0, y: 0, width: 1, height: 1 })).toBe(true);
        expect(validBox({ x: 0.9, y: 0, width: 0.10005, height: 0.5 })).toBe(true);
    });

    it('rejects out-of-bounds, tiny, and non-finite boxes', () => {
        expect(validBox({ x: -0.01, y: 0, width: 0.5, height: 0.5 })).toBe(false);
        expect(validBox({ x: 0, y: 0, width: 1.01, height: 0.5 })).toBe(false);
        expect(validBox({ x: 0, y: 0, width: 0.005, height: 0.5 })).toBe(false);
        expect(validBox({ x: 0, y: 0, width: Number.NaN, height: 0.5 })).toBe(false);
        expect(validBox(null)).toBe(false);
        expect(validBox({})).toBe(false);
    });
});
