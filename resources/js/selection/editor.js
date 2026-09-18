/**
 * ObjectSelectionEditor — DOM overlay editor for bounding boxes on an <img>.
 *
 * The image is shown as-is (browser applies EXIF orientation, same convention
 * as ai-service decode_image); an absolutely positioned overlay matches the
 * displayed image rect exactly, so the client->normalized mapping is
 * scale-independent. Pointer Events cover mouse and touch; pointer capture
 * keeps gestures alive when the finger leaves the overlay.
 */
import {
    boxAt,
    boxFromDrag,
    clientToNormalized,
    expandBox,
    MIN_EXTENT,
    moveBox,
    normalizedToClient,
    resizeBox,
    validBox,
} from './geometry.js';

const CURSORS = {
    nw: 'nwse-resize', n: 'ns-resize', ne: 'nesw-resize', e: 'ew-resize',
    se: 'nwse-resize', s: 'ns-resize', sw: 'nesw-resize', w: 'ew-resize',
};
const HANDLE_HIT_RADIUS = 20; // client px — generous invisible grab zones at edges/corners
const TAP_CREATE_SIZE = 0.2; // normalized side for tap-to-create

export class ObjectSelectionEditor {
    constructor(container, options = {}) {
        this.container = container;
        this.onBoxChange = options.onBoxChange || (() => {});
        this.minExtent = options.minExtent || MIN_EXTENT;
        this.padding = options.padding || 0.08;
        this.box = null;
        this.drag = null;
        this.imageUrl = null;
        this.build();
    }

    build() {
        this.container.classList.add('object-selection-editor');
        this.stage = document.createElement('div');
        this.stage.className = 'object-selection-stage';

        this.image = new Image();
        this.image.alt = '';
        this.image.draggable = false;
        this.image.className = 'object-selection-image';
        this.image.addEventListener('load', () => this.render());
        this.stage.append(this.image);

        this.overlay = document.createElement('div');
        this.overlay.className = 'object-selection-overlay';
        this.overlay.style.touchAction = 'none';
        this.overlay.style.cursor = 'crosshair';
        this.stage.append(this.overlay);

        this.boxEl = document.createElement('div');
        this.boxEl.className = 'object-selection-box';
        this.boxEl.hidden = true;
        for (const corner of ['nw', 'ne', 'sw', 'se']) {
            const bracket = document.createElement('div');
            bracket.className = `object-selection-bracket object-selection-bracket-${corner}`;
            this.boxEl.append(bracket);
        }
        this.label = document.createElement('div');
        this.label.className = 'object-selection-label';
        this.label.hidden = true;
        this.boxEl.append(this.label);
        this.overlay.append(this.boxEl);
        this.container.append(this.stage);

        this.overlay.addEventListener('pointerdown', (event) => this.onPointerDown(event));
        this.overlay.addEventListener('pointermove', (event) => this.onPointerMove(event));
        this.overlay.addEventListener('pointerup', (event) => this.onPointerUp(event));
        this.overlay.addEventListener('pointercancel', (event) => this.onPointerUp(event));
        this.observer = new ResizeObserver(() => this.render());
        this.observer.observe(this.stage);
    }

    loadImage(source) {
        return new Promise((resolve, reject) => {
            if (this.imageUrl) {
                URL.revokeObjectURL(this.imageUrl);
                this.imageUrl = null;
            }
            const url = typeof source === 'string' ? source : URL.createObjectURL(source);
            const cleanup = () => {
                if (this.imageUrl) {
                    URL.revokeObjectURL(this.imageUrl);
                    this.imageUrl = null;
                }
            };
            this.image.onload = () => { cleanup(); resolve(true); };
            this.image.onerror = () => { cleanup(); reject(new Error('Invalid image')); };
            if (typeof source !== 'string') this.imageUrl = url;
            this.image.src = url;
        });
    }

    setBox(box, notify = false) {
        this.box = validBox(box) ? { ...box } : null;
        this.render();
        if (notify) this.onBoxChange(this.box);
    }

    getBox() {
        return this.box;
    }

    reset() {
        this.setBox(null, true);
    }

    applyPadding(padding = this.padding) {
        if (!this.box) return;
        this.setBox(expandBox(this.box, padding * 2, this.minExtent), true);
    }

    imageRect() {
        return this.overlay.getBoundingClientRect();
    }

    clientToNormalized(clientX, clientY) {
        const rect = this.imageRect();
        if (rect.width <= 0 || rect.height <= 0) return null;
        return clientToNormalized(clientX, clientY, { left: rect.left, top: rect.top, width: rect.width, height: rect.height });
    }

    handlePoints() {
        const rect = this.imageRect();
        if (!this.box || rect.width <= 0) return [];
        const point = (nx, ny) => ({ x: rect.left + nx * rect.width, y: rect.top + ny * rect.height });
        return [
            ['nw', point(this.box.x, this.box.y)],
            ['n', point(this.box.x + this.box.width / 2, this.box.y)],
            ['ne', point(this.box.x + this.box.width, this.box.y)],
            ['e', point(this.box.x + this.box.width, this.box.y + this.box.height / 2)],
            ['se', point(this.box.x + this.box.width, this.box.y + this.box.height)],
            ['s', point(this.box.x + this.box.width / 2, this.box.y + this.box.height)],
            ['sw', point(this.box.x, this.box.y + this.box.height)],
            ['w', point(this.box.x, this.box.y + this.box.height / 2)],
        ];
    }

    hitHandle(clientX, clientY) {
        for (const [handle, point] of this.handlePoints()) {
            if (Math.hypot(clientX - point.x, clientY - point.y) <= HANDLE_HIT_RADIUS) return handle;
        }
        return null;
    }

    insideBox(point) {
        return this.box
            && point.x >= this.box.x && point.x <= this.box.x + this.box.width
            && point.y >= this.box.y && point.y <= this.box.y + this.box.height;
    }

    onPointerDown(event) {
        if (event.pointerType === 'mouse' && event.button !== 0) return;
        event.preventDefault();
        const point = this.clientToNormalized(event.clientX, event.clientY);
        if (!point) return;
        // Synthetic/automation events have no active pointer; capture is optional.
        try { this.overlay.setPointerCapture(event.pointerId); } catch { /* no active pointer */ }
        const handle = this.hitHandle(event.clientX, event.clientY);
        if (handle) {
            this.drag = { mode: 'resize', handle, start: point, box: { ...this.box } };
            return;
        }
        if (this.insideBox(point)) {
            this.drag = { mode: 'move', start: point, box: { ...this.box } };
            return;
        }
        this.drag = { mode: 'create', start: point, moved: false };
    }

    onPointerMove(event) {
        const point = this.clientToNormalized(event.clientX, event.clientY);
        if (!point) return;
        if (this.drag) {
            if (this.drag.mode === 'create') {
                this.drag.moved = true;
                this.setBox(boxFromDrag(this.drag.start, point, this.minExtent), true);
            } else if (this.drag.mode === 'move') {
                this.setBox(moveBox(this.drag.box, point.x - this.drag.start.x, point.y - this.drag.start.y, this.minExtent), true);
            } else {
                this.setBox(resizeBox(this.drag.box, this.drag.handle, point.x - this.drag.start.x, point.y - this.drag.start.y, this.minExtent), true);
            }
            return;
        }
        const handle = this.hitHandle(event.clientX, event.clientY);
        this.overlay.style.cursor = handle
            ? CURSORS[handle]
            : (this.insideBox(point) ? 'move' : 'crosshair');
    }

    onPointerUp(event) {
        if (this.drag?.mode === 'create' && !this.drag.moved) {
            const point = this.clientToNormalized(event.clientX, event.clientY);
            if (point) this.setBox(boxAt(point, TAP_CREATE_SIZE, this.minExtent), true);
        }
        this.drag = null;
    }

    render() {
        const rect = this.imageRect();
        if (!this.box || rect.width <= 0 || rect.height <= 0) {
            this.boxEl.hidden = true;
            return;
        }
        const pixel = normalizedToClient(this.box, { left: rect.left, top: rect.top, width: rect.width, height: rect.height });
        this.boxEl.hidden = false;
        this.boxEl.style.left = `${pixel.x - rect.left}px`;
        this.boxEl.style.top = `${pixel.y - rect.top}px`;
        this.boxEl.style.width = `${pixel.width}px`;
        this.boxEl.style.height = `${pixel.height}px`;
        this.label.hidden = false;
        this.label.textContent = `${Math.round(this.box.width * 100)}% × ${Math.round(this.box.height * 100)}%`;
    }
}
