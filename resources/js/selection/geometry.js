/**
 * Pure geometry helpers for object selection. Boxes are normalized 0..1
 * fractions of the orientation-normalized image (EXIF orientation applied),
 * matching the ai-service contract in app/preprocessing/selection.py and the
 * Laravel CropCoordinates service. The DOM editor converts client pixels to
 * normalized coordinates through these functions, so CSS scaling and
 * object-fit/letterboxing never affect the stored coordinates.
 */

export const MIN_EXTENT = 0.01;
export const TOLERANCE = 1e-4;

export function clamp(value, low = 0, high = 1) {
    return Math.min(high, Math.max(low, value));
}

/**
 * Keep a box inside the image bounds and above the minimum extent. Values are
 * rounded to 6 decimals so drag math stays deterministic and the wire payload
 * is stable (the 1e-4 backend tolerance absorbs the rounding).
 */
export function normalizeBox(box, min = MIN_EXTENT) {
    const round = (value) => Math.round(value * 1e6) / 1e6;
    const width = clamp(round(box.width), min, 1);
    const height = clamp(round(box.height), min, 1);
    return {
        x: clamp(round(box.x), 0, 1 - width),
        y: clamp(round(box.y), 0, 1 - height),
        width,
        height,
    };
}

/** Translate a box by normalized deltas; the result never leaves the image. */
export function moveBox(box, dx, dy, min = MIN_EXTENT) {
    return normalizeBox({ x: box.x + dx, y: box.y + dy, width: box.width, height: box.height }, min);
}

/**
 * Resize from one handle ('nw','n','ne','e','se','s','sw','w') by normalized
 * deltas from the drag start. Opposite edges stay pinned; edges never cross
 * their opposite side past `min` and never leave the image.
 */
export function resizeBox(box, handle, dx, dy, min = MIN_EXTENT) {
    let left = box.x;
    let top = box.y;
    let right = box.x + box.width;
    let bottom = box.y + box.height;
    if (handle.includes('w')) left = clamp(left + dx, 0, right - min);
    if (handle.includes('e')) right = clamp(right + dx, left + min, 1);
    if (handle.includes('n')) top = clamp(top + dy, 0, bottom - min);
    if (handle.includes('s')) bottom = clamp(bottom + dy, top + min, 1);
    return normalizeBox({ x: left, y: top, width: right - left, height: bottom - top }, min);
}

/** Box spanned by two normalized points, regardless of drag direction. */
export function boxFromDrag(start, end, min = MIN_EXTENT) {
    return normalizeBox({
        x: Math.min(start.x, end.x),
        y: Math.min(start.y, end.y),
        width: Math.abs(end.x - start.x),
        height: Math.abs(end.y - start.y),
    }, min);
}

/** Expand (or shrink) a box by `total` normalized units, split across sides. */
export function expandBox(box, total, min = MIN_EXTENT) {
    const half = total / 2;
    return normalizeBox({
        x: box.x - half,
        y: box.y - half,
        width: box.width + total,
        height: box.height + total,
    }, min);
}

/** Default-size box centered on a normalized point (tap-to-create). */
export function boxAt(point, size = 0.2, min = MIN_EXTENT) {
    const side = clamp(size, min, 1);
    return normalizeBox({ x: point.x - side / 2, y: point.y - side / 2, width: side, height: side }, min);
}

/**
 * Object-fit: contain letterbox. When an image keeps its aspect ratio inside
 * a container, the visible content rectangle is inset from the container
 * edges. Inputs and outputs share one unit (CSS pixels).
 */
export function contentRect(containerWidth, containerHeight, imageWidth, imageHeight) {
    if (containerWidth <= 0 || containerHeight <= 0 || imageWidth <= 0 || imageHeight <= 0) {
        return null;
    }
    const scale = Math.min(containerWidth / imageWidth, containerHeight / imageHeight);
    const width = imageWidth * scale;
    const height = imageHeight * scale;
    return { left: (containerWidth - width) / 2, top: (containerHeight - height) / 2, width, height };
}

/**
 * Client pixel point -> normalized image coordinates, clamped to the image.
 * `rect` is the displayed image rectangle ({left, top, width, height}); it may
 * be scaled or letterboxed — the mapping is scale-independent.
 */
export function clientToNormalized(clientX, clientY, rect) {
    if (!rect || rect.width <= 0 || rect.height <= 0) return null;
    return {
        x: clamp((clientX - rect.left) / rect.width),
        y: clamp((clientY - rect.top) / rect.height),
    };
}

/** Inverse of clientToNormalized: normalized box -> client pixel box. */
export function normalizedToClient(box, rect) {
    if (!rect) return null;
    return {
        x: rect.left + box.x * rect.width,
        y: rect.top + box.y * rect.height,
        width: box.width * rect.width,
        height: box.height * rect.height,
    };
}

/** Backend candidate filter: finite coordinates within the 0..1 contract. */
export function validBox(box) {
    return box !== null && typeof box === 'object'
        && ['x', 'y', 'width', 'height'].every((key) => Number.isFinite(box[key]))
        && box.x >= 0 && box.y >= 0
        && box.width >= MIN_EXTENT && box.height >= MIN_EXTENT
        && box.x + box.width <= 1 + TOLERANCE
        && box.y + box.height <= 1 + TOLERANCE;
}
