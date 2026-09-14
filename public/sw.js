const CACHE_NAME = 'lensku-v1';

const STATIC_ASSETS = [
    '/',
    '/favicon_io/android-chrome-192x192.png',
    '/favicon_io/android-chrome-512x512.png'
];

self.addEventListener('install', event => {
    self.skipWaiting();

    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(STATIC_ASSETS))
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        Promise.all([
            clients.claim(),

            caches.keys().then(keys =>
                Promise.all(
                    keys
                        .filter(key => key !== CACHE_NAME)
                        .map(key => caches.delete(key))
                )
            )
        ])
    );
});

self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET') {
        return;
    }

    event.respondWith(
        fetch(event.request)
            .then(response => {
                return response;
            })
            .catch(() => caches.match(event.request))
    );
});