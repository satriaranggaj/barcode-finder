import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';
import {
    IMAGE_COMPRESSION_QUALITY,
    MAX_CONCURRENT_COMPRESSIONS,
    compressImage,
    compressImages,
    formatBytes,
    isCompressible,
    savingText,
} from '../../resources/js/image-compression.js';

function jpegFile(name = 'photo.jpg', size = 1200) {
    const bytes = new Uint8Array(size);
    return new File([bytes], name, { type: 'image/jpeg' });
}

describe('image-compression (no resolution loss)', () => {
    let canvases;
    let bitmapDims;
    let blobSize;
    let bitmapCalls;

    beforeEach(() => {
        canvases = [];
        bitmapDims = { width: 4000, height: 3000 };
        blobSize = 400;
        bitmapCalls = [];
        vi.stubGlobal('createImageBitmap', async (file, options) => {
            bitmapCalls.push({ file, options });
            return { width: bitmapDims.width, height: bitmapDims.height, close: vi.fn() };
        });
        vi.stubGlobal('document', {
            createElement: (tag) => {
                if (tag !== 'canvas') throw new Error('unexpected element');
                const canvas = {
                    width: 0,
                    height: 0,
                    getContext: () => ({ drawImage: vi.fn() }),
                    toBlob: (cb, type, quality) => {
                        canvas._type = type;
                        canvas._quality = quality;
                        cb(blobSize === null ? null : new Blob([new Uint8Array(blobSize)], { type }));
                    },
                };
                canvases.push(canvas);
                return canvas;
            },
        });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('quality default konservatif 0.88 untuk retrieval', () => {
        expect(IMAGE_COMPRESSION_QUALITY).toBe(0.88);
    });

    it('JPEG besar berhasil dikompres tanpa resize', async () => {
        const out = await compressImage(jpegFile());
        expect(out.size).toBe(blobSize);
        expect(canvases).toHaveLength(1);
        expect(canvases[0].width).toBe(4000);
        expect(canvases[0].height).toBe(3000);
    });

    it('EXIF orientation dibaca sekali via from-image', async () => {
        await compressImage(jpegFile());
        expect(bitmapCalls[0].options).toEqual({ imageOrientation: 'from-image' });
    });

    it('output lebih besar → original digunakan', async () => {
        blobSize = 5000; // encode baru lebih boros
        const original = jpegFile('photo.jpg', 1200);
        const out = await compressImage(original);
        expect(out).toBe(original);
    });

    it('toBlob null → original digunakan', async () => {
        blobSize = null;
        const original = jpegFile();
        expect(await compressImage(original)).toBe(original);
    });

    it('exception → original digunakan, tidak pernah reject', async () => {
        vi.stubGlobal('createImageBitmap', async () => {
            throw new Error('OOM');
        });
        const original = jpegFile();
        await expect(compressImage(original)).resolves.toBe(original);
        await expect(compressImages([original])).resolves.toEqual([original]);
    });

    it('browser tanpa createImageBitmap → original', async () => {
        vi.unstubAllGlobals();
        const original = jpegFile();
        await expect(compressImage(original)).resolves.toBe(original);
    });

    it('non-image tidak disentuh', async () => {
        const pdf = new File([new Uint8Array(10)], 'doc.pdf', { type: 'application/pdf' });
        expect(isCompressible(pdf)).toBe(false);
        await expect(compressImage(pdf)).resolves.toBe(pdf);
    });

    it('MIME dan extension konsisten (photo.jpg → photo.webp image/webp)', async () => {
        const out = await compressImage(jpegFile('photo.jpg'));
        expect(out.name).toBe('photo.webp');
        expect(out.type).toBe('image/webp');
    });

    it('PNG transparency aman via webp alpha-preserving', async () => {
        const png = new File([new Uint8Array(1200)], 'motif.png', { type: 'image/png' });
        const out = await compressImage(png);
        expect(out.type).toBe('image/webp');
        expect(out.name).toBe('motif.webp');
        expect(canvases[0].width).toBe(4000);
        expect(canvases[0].height).toBe(3000);
    });

    it('multiple files mempertahankan urutan (index crop tidak tertukar)', async () => {
        // file0 menyusut, file1 membesar→original, file2 gagal→original
        let calls = 0;
        vi.stubGlobal('createImageBitmap', async (file) => {
            calls += 1;
            if (file.name === 'b.jpg') return { width: 100, height: 100, close: vi.fn() };
            if (file.name === 'c.jpg') throw new Error('decode gagal');
            return { width: 4000, height: 3000, close: vi.fn() };
        });
        vi.stubGlobal('document', {
            createElement: () => ({
                width: 0,
                height: 0,
                getContext: () => ({ drawImage: () => {} }),
                toBlob: (cb, type) => {
                    cb(new Blob([new Uint8Array(10)], { type }));
                },
            }),
        });
        const big = new File([new Uint8Array(5000)], 'a.jpg', { type: 'image/jpeg' });
        const small = new File([new Uint8Array(5)], 'b.jpg', { type: 'image/jpeg' });
        const broken = new File([new Uint8Array(5000)], 'c.jpg', { type: 'image/jpeg' });
        const out = await compressImages([big, small, broken]);
        expect(out).toHaveLength(3);
        expect(out[0].size).toBe(10); // terkompres
        expect(out[1]).toBe(small); // encode lebih besar → original
        expect(out[2]).toBe(broken); // gagal → original
        expect(calls).toBe(3);
    });

    it('file yang sudah dioptimalkan tidak dikompres dua kali', async () => {
        const first = await compressImage(jpegFile());
        expect(isCompressible(first)).toBe(false);
        bitmapCalls.length = 0;
        await expect(compressImage(first)).resolves.toBe(first);
        expect(bitmapCalls).toHaveLength(0);
    });

    it('concurrency dibatasi maksimal 2', async () => {
        expect(MAX_CONCURRENT_COMPRESSIONS).toBe(2);
        let active = 0;
        let peak = 0;
        vi.stubGlobal('createImageBitmap', () => {
            active += 1;
            peak = Math.max(peak, active);
            return new Promise((resolve) => {
                setTimeout(() => {
                    active -= 1;
                    resolve({ width: 10, height: 10, close: () => {} });
                }, 5);
            });
        });
        const files = Array.from({ length: 5 }, (_, i) => jpegFile(`p${i}.jpg`));
        const out = await compressImages(files);
        expect(out).toHaveLength(5);
        expect(peak).toBeLessThanOrEqual(2);
        expect(peak).toBeGreaterThan(0);
    });

    it('compressImages([]) → []', async () => {
        await expect(compressImages([])).resolves.toEqual([]);
    });

    it('metrics: savingText hanya saat benar hemat', () => {
        expect(savingText(8400000, 2100000)).toContain('hemat');
        expect(savingText(1200, 1500)).toBeNull();
        expect(formatBytes(8400000)).toContain('MB');
    });
});
