/**
 * Cimaise Service Worker - PWA Offline Support
 *
 * Cache strategies:
 * - Images: Cache First (instant load for viewed photos)
 * - CSS/JS: Stale While Revalidate (show cached, update in background)
 * - HTML: Network First (fresh content, fallback to cache if offline)
 */

const CACHE_VERSION = 'cimaise-v1';
const CACHE_STATIC = `${CACHE_VERSION}-static`;
const CACHE_IMAGES = `${CACHE_VERSION}-images`;
const CACHE_PAGES = `${CACHE_VERSION}-pages`;

// Debug mode - set to false to suppress console logs
const DEBUG = false;
const log = (...args) => DEBUG && console.log(...args);

// Assets to pre-cache on install
const STATIC_ASSETS = [
  '/',
  '/assets/app.css',
  '/offline.html'
];

// Maximum cache sizes
const MAX_IMAGE_CACHE = 100; // Store up to 100 images
const MAX_PAGE_CACHE = 20; // Store up to 20 pages

/**
 * INSTALL - Pre-cache critical assets
 */
self.addEventListener('install', (event) => {
  log('[SW] Installing service worker...');

  event.waitUntil(
    caches.open(CACHE_STATIC)
      .then((cache) => {
        log('[SW] Pre-caching static assets');
        return cache.addAll(STATIC_ASSETS);
      })
      .then(() => self.skipWaiting()) // Activate immediately
  );
});

/**
 * ACTIVATE - Clean up old caches
 */
self.addEventListener('activate', (event) => {
  log('[SW] Activating service worker...');

  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames
          .filter((name) => name.startsWith('cimaise-') && name !== CACHE_STATIC && name !== CACHE_IMAGES && name !== CACHE_PAGES)
          .map((name) => {
            log('[SW] Deleting old cache:', name);
            return caches.delete(name);
          })
      );
    })
    .then(() => self.clients.claim()) // Take control immediately
  );
});

/**
 * FETCH - Intercept network requests with intelligent caching
 */
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Skip non-GET requests
  if (request.method !== 'GET') {
    return;
  }

  // Skip admin routes
  if (url.pathname.startsWith('/admin')) {
    return;
  }

  // Skip API routes (always fresh)
  if (url.pathname.startsWith('/api')) {
    return;
  }

  // Skip protected media (requires authentication, never cache)
  // Protected albums (password/NSFW) use /media/protected/ endpoint
  if (url.pathname.startsWith('/media/protected')) {
    return;
  }

  // Strategy 1: IMAGES - Cache First (instant load!)
  if (isImageRequest(request)) {
    event.respondWith(cacheFirstStrategy(request, CACHE_IMAGES, MAX_IMAGE_CACHE));
    return;
  }

  // Strategy 2: CSS/JS - Stale While Revalidate (show cached, update in background)
  if (isStaticAsset(request)) {
    event.respondWith(staleWhileRevalidateStrategy(request, CACHE_STATIC));
    return;
  }

  // Strategy 3: HTML - Network First (fresh content, fallback to cache)
  if (isHTMLRequest(request)) {
    event.respondWith(networkFirstStrategy(request, CACHE_PAGES, MAX_PAGE_CACHE));
    return;
  }

  // Default: Network only
  event.respondWith(fetch(request));
});

/**
 * Cache First Strategy - Best for images
 * Check cache first, fetch from network if not found, then cache it
 */
async function cacheFirstStrategy(request, cacheName, maxItems = 50) {
  try {
    // 1. Try cache first
    const cache = await caches.open(cacheName);
    const cachedResponse = await cache.match(request);

    if (cachedResponse) {
      log('[SW] Cache hit:', request.url);
      return cachedResponse;
    }

    // 2. Not in cache, fetch from network
    log('[SW] Cache miss, fetching:', request.url);
    const networkResponse = await fetch(request);

    // 3. Cache successful responses
    if (networkResponse && networkResponse.status === 200) {
      // Clone response before caching (can only read once)
      const responseToCache = networkResponse.clone();

      // Limit cache size
      limitCacheSize(cacheName, maxItems);

      cache.put(request, responseToCache);
    }

    return networkResponse;

  } catch (error) {
    console.error('[SW] Cache first failed:', error);

    // Fallback for images: return placeholder SVG
    if (isImageRequest(request)) {
      return new Response(
        '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600" viewBox="0 0 800 600"><rect fill="#f0f0f0" width="800" height="600"/><text x="50%" y="50%" text-anchor="middle" fill="#999" font-size="24">Offline</text></svg>',
        { headers: { 'Content-Type': 'image/svg+xml' } }
      );
    }

    throw error;
  }
}

/**
 * Stale While Revalidate Strategy - Best for CSS/JS
 * Return cached version immediately, update cache in background
 */
async function staleWhileRevalidateStrategy(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cachedResponse = await cache.match(request);

  // Fetch from network in background (don't await)
  const fetchPromise = fetch(request)
    .then((networkResponse) => {
      if (networkResponse && networkResponse.status === 200) {
        try {
          cache.put(request, networkResponse.clone());
        } catch (error) {
          log('[SW] Cache put failed:', error);
        }
      }
      return networkResponse;
    })
    .catch((error) => {
      log('[SW] Background fetch failed:', error);
      return null;
    });

  // Return cached response immediately if available
  if (cachedResponse) {
    return cachedResponse;
  }

  const networkResponse = await fetchPromise;
  return networkResponse || new Response('Service Unavailable', { status: 503 });
}

/**
 * Network First Strategy - Best for HTML pages
 * Try network first, fallback to cache if offline
 */
async function networkFirstStrategy(request, cacheName, maxItems = 20) {
  try {
    // 1. Try network first
    const networkResponse = await fetch(request);

    // 2. Cache successful responses
    if (networkResponse && networkResponse.status === 200) {
      const cache = await caches.open(cacheName);
      const responseToCache = networkResponse.clone();

      // Limit cache size
      limitCacheSize(cacheName, maxItems);

      cache.put(request, responseToCache);
    }

    return networkResponse;

  } catch (error) {
    // 3. Network failed, try cache
    log('[SW] Network failed, trying cache:', request.url);
    const cache = await caches.open(cacheName);
    const cachedResponse = await cache.match(request);

    if (cachedResponse) {
      return cachedResponse;
    }

    // 4. No cache available, show offline page
    const staticCache = await caches.open(CACHE_STATIC);
    const offlinePage = await staticCache.match('/offline.html');
    if (offlinePage) {
      return offlinePage;
    }

    throw error;
  }
}

/**
 * Limit cache size by removing oldest entries
 */
async function limitCacheSize(cacheName, maxItems) {
  const cache = await caches.open(cacheName);
  const keys = await cache.keys();

  if (keys.length > maxItems) {
    // Remove oldest items (FIFO)
    const itemsToDelete = keys.length - maxItems;
    for (let i = 0; i < itemsToDelete; i++) {
      await cache.delete(keys[i]);
    }
    console.log(`[SW] Cache ${cacheName} trimmed to ${maxItems} items`);
  }
}

/**
 * Check if request is for an image
 */
function isImageRequest(request) {
  const url = new URL(request.url);
  const imageExtensions = ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.avif', '.svg'];
  return imageExtensions.some((ext) => url.pathname.toLowerCase().endsWith(ext));
}

/**
 * Check if request is for static asset (CSS/JS)
 */
function isStaticAsset(request) {
  const url = new URL(request.url);
  const staticExtensions = ['.css', '.js', '.woff', '.woff2', '.ttf', '.eot', '.otf'];
  return staticExtensions.some((ext) => url.pathname.toLowerCase().endsWith(ext));
}

/**
 * Check if request is for HTML page
 */
function isHTMLRequest(request) {
  const acceptHeader = request.headers.get('Accept');
  return acceptHeader && acceptHeader.includes('text/html');
}

/**
 * Message handler for cache control from main thread
 */
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }

  if (event.data && event.data.type === 'CLEAR_CACHE') {
    event.waitUntil(
      caches.keys().then((cacheNames) => {
        return Promise.all(
          cacheNames.map((name) => caches.delete(name))
        );
      }).then(() => {
        event.ports[0].postMessage({ success: true });
      })
    );
  }
});
