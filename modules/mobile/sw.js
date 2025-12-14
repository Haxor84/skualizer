/**
 * Skualizer Mobile - Service Worker
 * Versione: 1.0.0
 */

const CACHE_NAME = 'skualizer-mobile-v1';
const ASSETS_TO_CACHE = [
    '/modules/mobile/assets/mobile.css',
    '/modules/mobile/assets/mobile.js',
    '/modules/mobile/assets/icons.svg'
];

// Install event - cache assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(ASSETS_TO_CACHE))
            .then(() => self.skipWaiting())
    );
});

// Activate event - clean old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => {
                    if (cacheName !== CACHE_NAME) {
                        return caches.delete(cacheName);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

// Fetch event - network first, cache fallback
self.addEventListener('fetch', (event) => {
    // Skip cross-origin requests and non-GET
    if (event.request.method !== 'GET' || !event.request.url.startsWith(self.location.origin)) {
        return;
    }
    
    event.respondWith(
        fetch(event.request)
            .then((response) => {
                // Clone response before caching
                const responseToCache = response.clone();
                
                // Cache only successful responses
                if (response.status === 200) {
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(event.request, responseToCache);
                    });
                }
                
                return response;
            })
            .catch(() => {
                // Network failed, try cache
                return caches.match(event.request);
            })
    );
});

