/**
 * Service Worker - Skualizer Mobile PWA
 * Cache-first per assets statici, Network-first per API
 */

const CACHE_NAME = 'skualizer-mobile-v1';
const STATIC_ASSETS = [
    '/modules/mobile/assets/mobile.css',
    '/modules/mobile/assets/mobile.js',
    '/modules/mobile/assets/icons.svg'
];

// Install: cache assets statici
self.addEventListener('install', (event) => {
    console.log('[SW] Installing...');
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            console.log('[SW] Caching static assets');
            return cache.addAll(STATIC_ASSETS);
        })
    );
    self.skipWaiting();
});

// Activate: pulisci vecchie cache
self.addEventListener('activate', (event) => {
    console.log('[SW] Activating...');
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => {
                    if (cacheName !== CACHE_NAME) {
                        console.log('[SW] Deleting old cache:', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
    self.clients.claim();
});

// Fetch: strategia cache
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Solo intercetta richieste allo stesso origin
    if (url.origin !== location.origin) {
        return;
    }

    // Strategia Cache-First per assets statici (CSS, JS, SVG)
    if (request.url.match(/\.(css|js|svg)$/)) {
        event.respondWith(
            caches.match(request).then((cachedResponse) => {
                return cachedResponse || fetch(request).then((response) => {
                    return caches.open(CACHE_NAME).then((cache) => {
                        cache.put(request, response.clone());
                        return response;
                    });
                });
            }).catch(() => {
                console.error('[SW] Fetch failed for:', request.url);
            })
        );
        return;
    }

    // Strategia Network-First per pagine PHP e API
    if (request.url.match(/\.php/)) {
        event.respondWith(
            fetch(request).catch(() => {
                // Fallback a cache se network fallisce (offline)
                return caches.match(request).then((cachedResponse) => {
                    return cachedResponse || new Response(
                        '<h1>Offline</h1><p>Connessione non disponibile.</p>',
                        { headers: { 'Content-Type': 'text/html' } }
                    );
                });
            })
        );
        return;
    }

    // Default: fetch normale
    event.respondWith(fetch(request));
});

