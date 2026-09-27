import { describe, it, expect } from 'vitest';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(import.meta.dirname, '..', '..');
const MANIFEST_PATH = path.join(ROOT, 'public', 'favicon_io', 'site.webmanifest');

function loadManifest() {
    return JSON.parse(readFileSync(MANIFEST_PATH, 'utf-8'));
}

describe('PWA manifest', () => {
    it('is valid JSON with Lensku identity', () => {
        const manifest = loadManifest();
        expect(manifest.name).toBe('Lensku — Visual SKU Search');
        expect(manifest.short_name).toBe('Lensku');
        expect(manifest.description).toBe('Pencarian SKU produk berbasis gambar.');
    });

    it('has installability fields with brand colors', () => {
        const manifest = loadManifest();
        expect(manifest.id).toBe('/');
        expect(manifest.start_url).toBe('/');
        expect(manifest.scope).toBe('/');
        expect(manifest.display).toBe('standalone');
        expect(manifest.theme_color).toBe('#543019');
        expect(manifest.background_color).toBe('#fff8d6');
    });

    it('references icons that exist on disk', () => {
        const manifest = loadManifest();
        const icons = manifest.icons.filter((icon) => icon.purpose === 'any');
        const sizes = icons.map((icon) => icon.sizes);
        expect(sizes).toContain('192x192');
        expect(sizes).toContain('512x512');
        for (const icon of icons) {
            const file = path.join(ROOT, 'public', icon.src.replace(/^\//, ''));
            expect(existsSync(file), `missing icon file: ${icon.src}`).toBe(true);
        }
    });
});
