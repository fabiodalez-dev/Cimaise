/**
 * Centralized Progressive Image Loader for Home Templates
 *
 * Usable by: classic, modern, masonry, and custom home templates.
 * Loads images in batches via API with album diversity prioritization.
 * Enhanced with prefetching, caching, and intelligent batching.
 *
 * @example
 * const loader = new HomeProgressiveLoader({
 *   apiUrl: '/api/home/gallery',
 *   container: document.getElementById('gallery'),
 *   shownImageIds: [1, 2, 3],
 *   shownAlbumIds: [1],
 *   renderImage: (img) => appendImageToDOM(img),
 *   batchSize: 20
 * });
 * loader.startBackgroundLoading();
 * loader.observe(document.getElementById('load-trigger'));
 */
export class HomeProgressiveLoader {
  /**
   * @param {Object} options
   * @param {string} options.apiUrl - API endpoint for fetching images
   * @param {HTMLElement} options.container - Gallery container element
   * @param {Function} options.renderImage - Callback to render each image
   * @param {number[]} options.shownImageIds - IDs of images already shown (SSR)
   * @param {number[]} options.shownAlbumIds - IDs of albums already represented
   * @param {number} [options.batchSize=20] - Number of images per API request
   */
  constructor(options = {}) {
    this.apiUrl = options.apiUrl || '/api/home/gallery';
    this.container = options.container;
    this.renderImage = options.renderImage;
    this.batchSize = options.batchSize || 20;
    this.extraParams = options.extraParams || {};
    this.hasMore = true;
    this.loading = false;
    this.observer = null;

    // Performance optimizations
    this.cache = new Map(); // In-memory cache for API responses
    this.prefetchQueue = []; // Queue for prefetched batches
    this.connectionSpeed = this._detectConnectionSpeed();
    this.adaptiveBatchSize = this._calculateAdaptiveBatchSize();
    this.prefetchInProgress = false;

    // DEDUPLICATION: Use Set for O(1) lookup, filter out NaN values
    this.shownImageIds = new Set(
      (options.shownImageIds || [])
        .map(id => parseInt(id, 10))
        .filter(id => !isNaN(id) && id > 0)
    );

    // Use Set for album IDs too for O(1) lookup
    this.shownAlbumIds = new Set(
      (options.shownAlbumIds || [])
        .map(id => parseInt(id, 10))
        .filter(id => !isNaN(id) && id > 0)
    );
  }

  /**
   * Detect connection speed using Network Information API
   * @returns {string} 'slow', 'medium', or 'fast'
   * @private
   */
  _detectConnectionSpeed() {
    if ('connection' in navigator) {
      const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
      if (connection) {
        const effectiveType = connection.effectiveType;
        if (effectiveType === 'slow-2g' || effectiveType === '2g') return 'slow';
        if (effectiveType === '3g') return 'medium';
        if (effectiveType === '4g') return 'fast';
      }
    }
    return 'medium'; // Default fallback
  }

  /**
   * Calculate adaptive batch size based on connection speed
   * @returns {number}
   * @private
   */
  _calculateAdaptiveBatchSize() {
    const baseSize = this.batchSize;
    switch (this.connectionSpeed) {
      case 'slow': return Math.max(10, Math.floor(baseSize * 0.5));
      case 'fast': return Math.floor(baseSize * 1.5);
      default: return baseSize;
    }
  }

  /**
   * Generate cache key for API requests
   * @param {number} offset
   * @returns {string}
   * @private
   */
  _getCacheKey(offset) {
    return `${this.apiUrl}:${offset}:${this.adaptiveBatchSize}:${JSON.stringify(this.extraParams)}`;
  }

  /**
   * Load next batch of images from API (with caching and prefetching)
   * @returns {Promise<void>}
   */
  async loadMore() {
    if (this.loading || !this.hasMore) return;
    this.loading = true;

    try {
      // Check prefetch queue first (instant!)
      if (this.prefetchQueue.length > 0) {
        const data = this.prefetchQueue.shift();
        this._processImageData(data);
        this.loading = false;

        // Trigger next prefetch
        this._prefetchNextBatch();
        return;
      }

      // Otherwise, fetch normally
      const data = await this._fetchBatch();
      if (data) {
        this._processImageData(data);

        // Start prefetching next batch
        this._prefetchNextBatch();
      }
    } catch (error) {
      console.error('[HomeLoader] Load error:', error);
    } finally {
      this.loading = false;
    }
  }

  /**
   * Fetch a batch of images from API (with caching)
   * @returns {Promise<Object|null>}
   * @private
   */
  async _fetchBatch() {
    const cacheKey = this._getCacheKey(this.shownImageIds.size);

    // Check cache first
    if (this.cache.has(cacheKey)) {
      return this.cache.get(cacheKey);
    }

    let timeoutId;
    let controller;

    try {
      controller = new AbortController();
      timeoutId = setTimeout(() => controller.abort(), 10000);

      const url = new URL(this.apiUrl, window.location.origin);
      const params = url.searchParams;
      params.set('exclude', Array.from(this.shownImageIds).join(','));
      params.set('excludeAlbums', Array.from(this.shownAlbumIds).join(','));
      params.set('limit', String(this.adaptiveBatchSize));

      Object.entries(this.extraParams).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') return;
        params.set(key, String(value));
      });

      const res = await fetch(url.toString(), {
        signal: controller.signal,
        priority: 'high' // Fetch priority hint
      });

      if (!res.ok) {
        console.error('[HomeLoader] API error:', res.status);
        return null;
      }

      const data = await res.json();

      // Cache the response (limit cache size to prevent memory issues)
      if (this.cache.size < 5) {
        this.cache.set(cacheKey, data);
      }

      return data;

    } catch (error) {
      if (error.name !== 'AbortError') {
        console.error('[HomeLoader] Fetch error:', error);
      }
      return null;
    } finally {
      if (timeoutId) {
        clearTimeout(timeoutId);
      }
    }
  }

  /**
   * Process and render image data
   * @param {Object} data - API response data
   * @private
   */
  _processImageData(data) {
    // Append images via callback - SKIP DUPLICATES and invalid IDs
    if (Array.isArray(data.images)) {
      data.images.forEach(img => {
        const imgId = parseInt(img.id, 10);

        // Skip invalid IDs (NaN, 0, negative)
        if (isNaN(imgId) || imgId <= 0) {
          console.warn('[HomeLoader] Skipping invalid image ID:', img.id);
          return;
        }

        // DEDUPLICATION CHECK: Skip if already shown
        if (this.shownImageIds.has(imgId)) {
          return;
        }

        // Render image via callback
        if (this.renderImage) {
          this.renderImage(img);
        }

        this.shownImageIds.add(imgId);
      });
    }

    // Track new albums for diversity (using Set to prevent duplicates)
    if (Array.isArray(data.newAlbumIds)) {
      data.newAlbumIds.forEach(id => {
        const albumId = parseInt(id, 10);
        if (!isNaN(albumId) && albumId > 0) {
          this.shownAlbumIds.add(albumId);
        }
      });
    }

    // Update hasMore flag
    this.hasMore = Boolean(data.hasMore);
  }

  /**
   * Prefetch next batch in background (non-blocking)
   * @private
   */
  async _prefetchNextBatch() {
    // Don't prefetch if already prefetching, no more data, or on slow connection
    if (this.prefetchInProgress || !this.hasMore || this.connectionSpeed === 'slow') {
      return;
    }

    this.prefetchInProgress = true;

    try {
      // Use requestIdleCallback for non-blocking prefetch
      const prefetch = async () => {
        const data = await this._fetchBatch();
        if (data && data.images && data.images.length > 0) {
          this.prefetchQueue.push(data);
        }
        this.prefetchInProgress = false;
      };

      if ('requestIdleCallback' in window) {
        requestIdleCallback(() => prefetch(), { timeout: 2000 });
      } else {
        setTimeout(() => prefetch(), 100);
      }
    } catch (error) {
      this.prefetchInProgress = false;
    }
  }

  /**
   * Set up IntersectionObserver to auto-load on scroll
   * @param {HTMLElement} triggerElement - Element to observe (usually near bottom)
   */
  observe(triggerElement) {
    if (!triggerElement) {
      console.warn('[HomeLoader] Trigger element not found');
      return;
    }

    // Disconnect any existing observer
    if (this.observer) {
      this.observer.disconnect();
    }

    this.observer = new IntersectionObserver(
      (entries) => {
        if (entries.some(entry => entry.isIntersecting) && this.hasMore && !this.loading) {
          this.loadMore();
        }
      },
      { rootMargin: '200px' }
    );

    this.observer.observe(triggerElement);
  }

  /**
   * Disconnect the IntersectionObserver
   * Call this when the component is destroyed or no longer needed
   */
  disconnect() {
    if (this.observer) {
      this.observer.disconnect();
      this.observer = null;
    }
  }

  /**
   * Start loading immediately after page load
   * Call this to begin progressive loading without waiting for scroll
   */
  startBackgroundLoading() {
    // Small delay to let page render first
    requestAnimationFrame(() => {
      this.loadMore();
    });
  }

  /**
   * Check if more images are available
   * @returns {boolean}
   */
  hasMoreImages() {
    return this.hasMore;
  }

  /**
   * Check if currently loading
   * @returns {boolean}
   */
  isLoading() {
    return this.loading;
  }

  /**
   * Get count of loaded images
   * @returns {number}
   */
  getLoadedCount() {
    return this.shownImageIds.size;
  }
}
