/**
 * Behavioral tests for the real service worker file (public/sw.js).
 *
 * A minimal worker harness (event registry, Map-backed CacheStorage, stubbed
 * network) loads the actual worker source, then dispatches synthetic events.
 * This exercises the shipped fetch/install/activate logic — not a copy of it.
 */
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const SW_PATH = path.resolve(import.meta.dirname, '..', '..', 'public', 'sw.js');
const ORIGIN = 'https://lensku.test';

function createCache() {
    const entries = new Map();
    return {
        entries,
        async match(request) {
            const hit = entries.get(String(request.url));
            return hit ? new Response(hit.body, { status: 200, headers: { 'Content-Type': hit.type } }) : undefined;
        },
        async put(request, response) {
            entries.set(String(request.url), {
                body: await response.clone().text(),
                type: response.headers.get('Content-Type') || 'application/octet-stream',
            });
        },
        async add(url) {
            const absolute = new URL(String(url), ORIGIN).href;
            const response = await fetch(absolute);
            if (!response.ok) throw new Error(`precache failed: ${url}`);
            await this.put(new Request(absolute), response);
        },
        async addAll(urls) {
            await Promise.all(urls.map((url) => this.add(url)));
        },
    };
}

const harness = {
    listeners: {},
    caches: new Map(),
    network: null,
};

async function loadWorker() {
    globalThis.self = {
        location: { origin: ORIGIN },
        skipWaiting: async () => undefined,
        clients: { claim: async () => undefined },
        addEventListener: (type, handler) => {
            harness.listeners[type] = harness.listeners[type] || [];
            harness.listeners[type].push(handler);
        },
    };
    globalThis.caches = {
        async open(name) {
            if (!harness.caches.has(name)) harness.caches.set(name, createCache());
            return harness.caches.get(name);
        },
        async keys() {
            return [...harness.caches.keys()];
        },
        async delete(name) {
            return harness.caches.delete(name);
        },
        async match(request) {
            for (const cache of harness.caches.values()) {
                const hit = await cache.match(request);
                if (hit) return hit;
            }
            return undefined;
        },
    };
    globalThis.fetch = (...args) => harness.network(...args);
    await import(pathToFileURL(SW_PATH).href);
}

function syntheticEvent(request) {
    let responder = null;
    return {
        request,
        respondWith: (response) => {
            responder = response;
        },
        result: async () => await responder,
    };
}

function getRequest(url, { method = 'GET', mode = 'cors' } = {}) {
    const request = new Request(new URL(url, ORIGIN).href, { method });
    Object.defineProperty(request, 'mode', { value: mode });
    return request;
}

async function dispatchFetch(request) {
    const [handler] = harness.listeners.fetch || [];
    if (!handler) throw new Error('no fetch listener registered');
    const event = syntheticEvent(request);
    handler(event);
    return event;
}

describe('service worker registration surface', () => {
    beforeAll(async () => {
        await loadWorker();
    });

    it('registers install, activate, and fetch handlers', () => {
        expect(Object.keys(harness.listeners).sort()).toEqual(['activate', 'fetch', 'install']);
    });

    it('uses a versioned cache name', () => {
        const source = readFileSync(SW_PATH, 'utf-8');
        expect(source).toMatch(/lensku-static-v\d+/);
    });
});

describe('dynamic request safety', () => {
    beforeEach(() => {
        harness.network = async () => new Response('network', { status: 200 });
    });

    for (const path of ['/search', '/object-selection', '/search-feedback', '/login', '/logout', '/admin', '/admin/products/1']) {
        it(`never intercepts POST ${path}`, async () => {
            const event = await dispatchFetch(getRequest(path, { method: 'POST' }));
            expect(await event.result()).toBe(null);
        });
    }

    it('never caches search and admin responses', async () => {
        // Plain subresource GETs pass through untouched (browser default fetch).
        for (const path of ['/search', '/admin']) {
            const event = await dispatchFetch(getRequest(path));
            expect(await event.result()).toBe(null);
        }
        // Navigations always hit the network, never a stored copy.
        let calls = 0;
        harness.network = async () => new Response(`live-${++calls}`, { status: 200 });
        for (let i = 0; i < 2; i++) {
            const event = await dispatchFetch(getRequest('/admin', { mode: 'navigate' }));
            expect(await (await event.result()).text()).toBe(`live-${i + 1}`);
        }
        expect(calls).toBe(2);
    });

    it('passes file uploads through untouched', async () => {
        const body = new FormData();
        body.append('image', new Blob(['bytes'], { type: 'image/jpeg' }), 'photo.jpg');
        const request = new Request(new URL('/search', ORIGIN).href, { method: 'POST', body });
        const event = await dispatchFetch(request);
        expect(await event.result()).toBe(null);
    });
});

describe('static asset caching', () => {
    beforeEach(() => {
        harness.caches.clear();
        harness.network = async (input) => new Response(`live:${String(input.url || input)}`, {
            status: 200,
            headers: { 'Content-Type': 'application/javascript' },
        });
    });

    it('caches versioned build assets after the first fetch', async () => {
        const url = '/build/assets/app-abc123.js';
        const first = await dispatchFetch(getRequest(url));
        expect(await (await first.result()).text()).toBe(`live:${ORIGIN}${url}`);
        harness.network = async () => {
            throw new Error('offline');
        };
        const second = await dispatchFetch(getRequest(url));
        expect(await (await second.result()).text()).toBe(`live:${ORIGIN}${url}`);
    });

    it('caches PWA icons', async () => {
        const event = await dispatchFetch(getRequest('/favicon_io/android-chrome-192x192.png'));
        expect((await event.result()).status).toBe(200);
        const cache = harness.caches.get('lensku-static-v1');
        expect(cache.entries.has(`${ORIGIN}/favicon_io/android-chrome-192x192.png`)).toBe(true);
    });

    it('ignores cross-origin requests', async () => {
        const request = new Request('https://fonts.example.com/font.woff2');
        const event = await dispatchFetch(request);
        expect(await event.result()).toBe(null);
    });
});

describe('offline navigation fallback', () => {
    beforeEach(() => {
        harness.caches.clear();
        harness.network = async () => {
            throw new Error('offline');
        };
    });

    it('explains that visual search needs the internet', async () => {
        const event = await dispatchFetch(getRequest('/', { mode: 'navigate' }));
        const response = await event.result();
        expect(response.status).toBe(503);
        const body = await response.text();
        expect(body).toContain('koneksi internet');
        expect(body).toContain('pencarian visual');
    });

    it('never serves a stored admin page offline', async () => {
        const event = await dispatchFetch(getRequest('/admin', { mode: 'navigate' }));
        const response = await event.result();
        expect(response.status).toBe(503);
        expect(await response.text()).toContain('koneksi internet');
    });

    it('login stays reachable online inside the installed app', async () => {
        harness.network = async () => new Response('login page', { status: 200 });
        const event = await dispatchFetch(getRequest('/login', { mode: 'navigate' }));
        expect(await (await event.result()).text()).toBe('login page');
    });
});

describe('lifecycle', () => {
    beforeEach(() => {
        harness.caches.clear();
        harness.network = async () => new Response('x', { status: 200 });
    });

    it('precaches shell assets on install without failing', async () => {
        let waited = null;
        await harness.listeners.install[0]({ waitUntil: (promise) => { waited = promise; } });
        await waited;
        const cache = harness.caches.get('lensku-static-v1');
        expect(cache.entries.size).toBeGreaterThan(0);
    });

    it('removes old cache versions on activate', async () => {
        harness.caches.set('lensku-static-v0', createCache());
        harness.caches.set('lensku-static-v1', createCache());
        let waited = null;
        await harness.listeners.activate[0]({ waitUntil: (promise) => { waited = promise; } });
        await waited;
        expect(harness.caches.has('lensku-static-v0')).toBe(false);
        expect(harness.caches.has('lensku-static-v1')).toBe(true);
    });
});
