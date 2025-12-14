/**
 * Margynomic Mobile - Service Worker
 * Versione: 2.0.0 - Guest + Authenticated Pages
 */

const CACHE_NAME = 'margynomic-mobile-v2';
const ASSETS_TO_CACHE = [
    '/modules/mobile/assets/mobile.css',
    '/modules/mobile/assets/mobile.js',
    '/modules/margynomic/uploads/img/MARGYNOMIC.PNG',
    '/modules/mobile/login/login.php',
    '/modules/mobile/Profilo.php'
];

// Install event - cache assets
self.addEventListener('install', (event) => {
    console.log('[ServiceWorker] Installing...');
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                console.log('[ServiceWorker] Caching assets');
                return cache.addAll(ASSETS_TO_CACHE).catch((err) => {
                    console.error('[ServiceWorker] Cache failed:', err);
                });
            })
            .then(() => {
                console.log('[ServiceWorker] Installed');
                return self.skipWaiting();
            })
    );
});

// Activate event - clean old caches
self.addEventListener('activate', (event) => {
    console.log('[ServiceWorker] Activating...');
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => {
                    if (cacheName !== CACHE_NAME) {
                        console.log('[ServiceWorker] Deleting old cache:', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        }).then(() => {
            console.log('[ServiceWorker] Activated');
            return self.clients.claim();
        })
    );
});

// Fetch event - network first with cache fallback
self.addEventListener('fetch', (event) => {
    const { request } = event;
    
    // Skip cross-origin requests
    if (!request.url.startsWith(self.location.origin)) {
        return;
    }
    
    // Skip non-GET requests
    if (request.method !== 'GET') {
        return;
    }
    
    // Skip API calls and dynamic content
    if (request.url.includes('/api/') || 
        request.url.includes('?action=') ||
        request.url.includes('Controller.php')) {
        return;
    }
    
    event.respondWith(
        fetch(request)
            .then((response) => {
                // Don't cache failed responses
                if (!response || response.status !== 200 || response.type === 'error') {
                    return response;
                }
                
                // Clone response before caching
                const responseToCache = response.clone();
                
                // Cache successful responses (only GET)
                if (request.method === 'GET') {
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(request, responseToCache);
                    });
                }
                
                return response;
            })
            .catch((error) => {
                console.log('[ServiceWorker] Fetch failed, trying cache:', request.url);
                
                // Network failed, try cache
                return caches.match(request).then((cachedResponse) => {
                    if (cachedResponse) {
                        return cachedResponse;
                    }
                    
                    // If offline and no cache, show offline page (optional)
                    if (request.destination === 'document') {
                        return caches.match('/modules/mobile/login/login.php');
                    }
                    
                    throw error;
                });
            })
    );
});

// Message event - comunicazione con client
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
    
    if (event.data && event.data.type === 'CLEAR_CACHE') {
        event.waitUntil(
            caches.keys().then((cacheNames) => {
                return Promise.all(
                    cacheNames.map((cacheName) => caches.delete(cacheName))
                );
            }).then(() => {
                event.ports[0].postMessage({ success: true });
            })
        );
    }
});

