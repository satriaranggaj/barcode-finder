/* Lensku service worker — installability + static-asset resilience only.
 *
 * Versioned cache: bump LENSKU_CACHE on every change to this file so updated
 * clients never stay pinned to old JS/CSS. Old caches are deleted on activate.
 *
 * Safety rules (never relax these):
 * - Only same-origin GET requests are ever handled. POST/PUT/PATCH/DELETE,
 *   cross-origin requests, and Blob:/data: URLs always bypass the worker.
 * - Dynamic Lensku routes (/search, /object-selection, /search-feedback,
 *   /login, /logout, /admin...) are NEVER cached. Navigations use
 *   network-first and fall back to an offline notice when unreachable —
 *   visual search always needs the server, so no cached result is ever
 *   presented as a fresh search.
 * - Only versioned static assets (/build/..., /favicon_io/...) are cached.
 * - No user photos, uploads, or authenticated response bodies enter the cache.
 */
'use strict';

const LENSKU_CACHE = 'lensku-static-v1';

const PRECACHE_URLS = [
    '/favicon.ico',
    '/favicon_io/site.webmanifest',
    '/favicon_io/android-chrome-192x192.png',
    '/favicon_io/android-chrome-512x512.png',
];

// Document paths whose responses must never be stored. Checked before any
// caching decision; navigations to them still get network-first + offline
// fallback (never a stored copy).
const NEVER_CACHE_PREFIXES = [
    '/search',
    '/object-selection',
    '/search-feedback',
    '/login',
    '/logout',
    '/admin',
];

const STATIC_PREFIXES = ['/build/', '/favicon_io/', '/favicon.ico'];

function isNeverCachePath(pathname) {
    return NEVER_CACHE_PREFIXES.some(
        (prefix) => pathname === prefix || pathname.startsWith(prefix + '/')
    );
}

function isStaticAsset(pathname) {
    if (pathname === '/favicon.ico') return true;
    return STATIC_PREFIXES.some(
        (prefix) => prefix !== '/favicon.ico' && pathname.startsWith(prefix)
    );
}

function offlineFallbackResponse() {
    const html = '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">' +
        '<meta name="viewport" content="width=device-width, initial-scale=1">' +
        '<meta name="theme-color" content="#543019">' +
        '<title>Lensku luring</title></head>' +
        '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;' +
        'background:#fff8d6;color:#543019;font-family:system-ui,sans-serif;padding:24px;box-sizing:border-box;">' +
        '<main style="max-width:420px;text-align:center;">' +
        '<p style="font-size:13px;font-weight:700;letter-spacing:.18em;text-transform:uppercase;color:#8b7355;">Lensku</p>' +
        '<h1 style="font-size:28px;margin:12px 0;">Tidak ada koneksi internet</h1>' +
        '<p style="font-size:15px;line-height:1.6;">Lensku membutuhkan koneksi internet ' +
        'untuk melakukan pencarian visual. Periksa koneksi lalu coba lagi.</p>' +
        '</main></body></html>';
    return new Response(html, {
        status: 503,
        headers: { 'Content-Type': 'text/html; charset=utf-8' },
    });
}

async function cacheFirstStatic(request) {
    const cache = await caches.open(LENSKU_CACHE);
    const cached = await cache.match(request);
    if (cached) return cached;
    const response = await fetch(request);
    if (response && response.ok) {
        await cache.put(request, response.clone());
    }
    return response;
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            const cache = await caches.open(LENSKU_CACHE);
            // Resilient precache: one missing icon must not fail installation.
            await Promise.all(
                PRECACHE_URLS.map((url) =>
                    cache.add(url).catch(() => undefined)
                )
            );
            // Activate promptly so fresh navigations pick up the new worker;
            // already-open pages keep running until their next reload.
            await self.skipWaiting();
        })()
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const names = await caches.keys();
            await Promise.all(
                names
                    .filter((name) => name !== LENSKU_CACHE)
                    .map((name) => caches.delete(name))
            );
            await self.clients.claim();
        })()
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') return;

    let url;
    try {
        url = new URL(request.url);
    } catch {
        return;
    }
    if (url.origin !== self.location.origin) return;
    if (isNeverCachePath(url.pathname)) {
        // Dynamic/authenticated routes: network only. Navigations still get
        // the offline fallback instead of an error page when unreachable.
        if (request.mode === 'navigate') {
            event.respondWith(
                fetch(request).catch(() => offlineFallbackResponse())
            );
        }
        return;
    }
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => offlineFallbackResponse())
        );
        return;
    }
    if (!isStaticAsset(url.pathname)) return;
    event.respondWith(
        cacheFirstStatic(request).catch(() => caches.match(request))
    );
});
