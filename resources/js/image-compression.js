/**
 * Client-side image compression WITHOUT resolution loss.
 *
 * Single shared implementation for product uploads (admin) and search-by-image.
 * Rules:
 * - Pixel dimensions are NEVER changed: canvas is always bitmap.width x bitmap.height.
 * - Only file size is reduced via re-encoding (WebP, quality 0.88).
 * - WebP preserves alpha, so PNG transparency survives; if encoding fails or
 *   the result is not smaller, the ORIGINAL file is used.
 * - EXIF orientation is applied exactly once via createImageBitmap
 *   { imageOrientation: 'from-image' }; never rotate again afterwards.
 * - Any failure (unsupported browser, OOM, oversize canvas, encode error)
 *   falls back to the original file — compression never blocks upload.
 */

export const IMAGE_COMPRESSION_QUALITY = 0.88;

export const MAX_CONCURRENT_COMPRESSIONS = 2;

const OUTPUT_TYPE = 'image/webp';
const OUTPUT_EXTENSION = 'webp';

const COMPRESSIBLE_TYPES = new Set(['image/jpeg', 'image/jpg', 'image/png', 'image/webp']);

// Marks files that already went through this module so a second pass
// (e.g. source handler + preview handler both firing) never applies
// double lossy compression.
const ALREADY_OPTIMIZED = Symbol.for('lensku.optimized');

export function isCompressible(file) {
    if (!file || typeof file.type !== 'string') return false;
    if (file[ALREADY_OPTIMIZED]) return false;
    return COMPRESSIBLE_TYPES.has(file.type.toLowerCase());
}

function markOptimized(file) {
    try {
        Object.defineProperty(file, ALREADY_OPTIMIZED, { value: true, enumerable: false });
    } catch {
        // Non-extensible File polyfill in tests: fall back to expando.
        try {
            file[ALREADY_OPTIMIZED] = true;
        } catch {
            /* noop */
        }
    }
    return file;
}

function outputName(file) {
    const base = (file?.name || 'photo').split(/[\\/]/).pop().replace(/\.[a-z0-9]+$/i, '') || 'photo';
    return `${base}.${OUTPUT_EXTENSION}`;
}

function toBlob(canvas, type, quality) {
    return new Promise((resolve) => {
        try {
            canvas.toBlob((blob) => resolve(blob || null), type, quality);
        } catch {
            resolve(null);
        }
    });
}

/**
 * Compress one image file without changing its pixel dimensions.
 * Always resolves to a File: the optimized version when it is strictly
 * smaller, otherwise the original. Never rejects.
 */
export async function compressImage(file, options = {}) {
    const quality = options.quality ?? IMAGE_COMPRESSION_QUALITY;
    if (!isCompressible(file)) return file;
    try {
        if (typeof createImageBitmap !== 'function') return file;
        if (typeof document === 'undefined' || typeof document.createElement !== 'function') return file;
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        try {
            const width = bitmap?.width;
            const height = bitmap?.height;
            if (!Number.isInteger(width) || !Number.isInteger(height) || width < 1 || height < 1) return file;
            const canvas = document.createElement('canvas');
            // WAJIB: dimensi asli dipertahankan — tidak ada resize/downscale.
            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext('2d');
            if (!ctx) return file;
            ctx.drawImage(bitmap, 0, 0, width, height);
            const blob = await toBlob(canvas, OUTPUT_TYPE, quality);
            // Canvas tetap direferensikan sampai toBlob selesai; setelah ini
            // GC boleh mereklamasi (tidak ada object URL yang dibuat di sini).
            if (!blob || !(blob.size > 0) || blob.size >= file.size) return file;
            const optimized = new File([blob], outputName(file), {
                type: OUTPUT_TYPE,
                lastModified: Date.now(),
            });
            return markOptimized(optimized);
        } finally {
            try {
                bitmap?.close?.();
            } catch {
                /* noop */
            }
        }
    } catch {
        return file;
    }
}

/**
 * Compress many files with bounded concurrency (max 2 at a time so ten
 * 12MP photos cannot spike mobile RAM). Order of the input is preserved,
 * so crop_coordinates[i] / selection_sources[i] never get mismatched.
 * Never rejects: per-file failures resolve to the original file.
 */
export async function compressImages(files, options = {}) {
    const list = [...(files || [])];
    if (!list.length) return [];
    const limit = Math.max(1, Math.min(options.concurrency ?? MAX_CONCURRENT_COMPRESSIONS, list.length));
    const output = new Array(list.length);
    let next = 0;
    const worker = async () => {
        while (next < list.length) {
            const index = next;
            next += 1;
            try {
                output[index] = await compressImage(list[index], options);
            } catch {
                output[index] = list[index];
            }
        }
    };
    await Promise.all(Array.from({ length: limit }, worker));
    return output;
}

export function formatBytes(bytes) {
    const value = Number(bytes);
    if (!Number.isFinite(value) || value < 0) return '0 B';
    if (value < 1024) return `${value} B`;
    const units = ['KB', 'MB', 'GB'];
    let size = value / 1024;
    let unit = units[0];
    for (const candidate of units) {
        unit = candidate;
        if (size < 1024 || candidate === 'GB') break;
        size /= 1024;
    }
    return `${size >= 10 ? Math.round(size * 10) / 10 : Math.round(size * 100) / 100} ${unit}`;
}

export function savingText(originalBytes, optimizedBytes) {
    const from = Number(originalBytes);
    const to = Number(optimizedBytes);
    if (!Number.isFinite(from) || !Number.isFinite(to) || from <= 0 || to >= from) return null;
    const pct = Math.round((1 - to / from) * 1000) / 10;
    return `${formatBytes(from)} → ${formatBytes(to)} (${pct}% hemat)`;
}
