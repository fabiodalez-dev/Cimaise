/**
 * Link Hover Prefetch Utility
 * Prefetches pages when user hovers over internal links for faster navigation
 */

const prefetchedUrls = new Set();
let hoverTimer = null;
const HOVER_DELAY = 65; // ms delay before prefetching (avoid accidental hovers)

/**
 * Check if prefetching should be disabled
 */
function shouldSkipPrefetch() {
  // Respect data saver mode
  if (navigator.connection) {
    if (navigator.connection.saveData) return true;
    // Skip on slow connections
    if (navigator.connection.effectiveType === 'slow-2g' ||
        navigator.connection.effectiveType === '2g') return true;
  }
  return false;
}

/**
 * Check if URL is prefetchable (same-origin, not already prefetched, etc.)
 */
function isPrefetchable(url) {
  if (!url || prefetchedUrls.has(url)) return false;

  try {
    const urlObj = new URL(url, window.location.origin);

    // Only same-origin
    if (urlObj.origin !== window.location.origin) return false;

    // Skip current page
    if (urlObj.pathname === window.location.pathname) return false;

    // Skip hash-only links
    if (urlObj.pathname === '' && urlObj.hash) return false;

    // Skip non-HTML resources (common file extensions)
    const skipExtensions = ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.avif', '.svg',
                          '.pdf', '.zip', '.mp4', '.mp3', '.css', '.js'];
    const pathname = urlObj.pathname.toLowerCase();
    if (skipExtensions.some(ext => pathname.endsWith(ext))) return false;

    // Skip admin and API routes
    if (pathname.startsWith('/admin') || pathname.startsWith('/api/')) return false;

    return true;
  } catch {
    return false;
  }
}

/**
 * Prefetch a URL using link rel="prefetch"
 */
function prefetchUrl(url) {
  if (prefetchedUrls.has(url)) return;

  const link = document.createElement('link');
  link.rel = 'prefetch';
  link.href = url;
  link.as = 'document';

  document.head.appendChild(link);
  prefetchedUrls.add(url);
}

/**
 * Handle mouse enter on a link
 */
function handleMouseEnter(e) {
  if (shouldSkipPrefetch()) return;

  const link = e.target.closest('a[href]');
  if (!link) return;

  const url = link.href;
  if (!isPrefetchable(url)) return;

  // Debounce: wait before prefetching to avoid accidental hovers
  hoverTimer = setTimeout(() => {
    prefetchUrl(url);
  }, HOVER_DELAY);
}

/**
 * Handle mouse leave from a link
 */
function handleMouseLeave() {
  if (hoverTimer) {
    clearTimeout(hoverTimer);
    hoverTimer = null;
  }
}

/**
 * Initialize link prefetch on hover
 * @param {string|Element} container - CSS selector or element to attach listeners (default: document)
 */
export function initLinkPrefetch(container = document) {
  // Check for basic support
  if (!('head' in document)) return;

  const root = typeof container === 'string' ? document.querySelector(container) : container;
  if (!root) return;

  // Use event delegation for efficiency
  root.addEventListener('mouseenter', handleMouseEnter, { capture: true, passive: true });
  root.addEventListener('mouseleave', handleMouseLeave, { capture: true, passive: true });

  // Also handle touch: prefetch on touchstart (mobile)
  root.addEventListener('touchstart', (e) => {
    if (shouldSkipPrefetch()) return;
    const link = e.target.closest('a[href]');
    if (link && isPrefetchable(link.href)) {
      prefetchUrl(link.href);
    }
  }, { capture: true, passive: true });
}

/**
 * Manually prefetch specific URLs
 * @param {string[]} urls - Array of URLs to prefetch
 */
export function prefetchUrls(urls) {
  if (shouldSkipPrefetch()) return;
  urls.forEach(url => {
    if (isPrefetchable(url)) {
      prefetchUrl(url);
    }
  });
}

/**
 * Check if a URL has been prefetched
 * @param {string} url
 * @returns {boolean}
 */
export function hasPrefetched(url) {
  return prefetchedUrls.has(url);
}
