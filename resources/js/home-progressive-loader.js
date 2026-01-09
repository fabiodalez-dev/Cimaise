/**
 * Centralized Progressive Image Loader for Home Templates
 *
 * Usable by: classic, modern, masonry, and custom home templates.
 * Loads images in batches via API with album diversity prioritization.
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
    this.hasMore = true;
    this.loading = false;
    this.observer = null;

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
   * Load next batch of images from API
   * @returns {Promise<void>}
   */
  async loadMore() {
    if (this.loading || !this.hasMore) return;
    this.loading = true;
    let timeoutId;
    let controller;

    try {
      controller = new AbortController();
      timeoutId = setTimeout(() => controller.abort(), 10000);
      const params = new URLSearchParams({
        exclude: Array.from(this.shownImageIds).join(','),
        excludeAlbums: Array.from(this.shownAlbumIds).join(','),
        limit: String(this.batchSize)
      });

      const res = await fetch(`${this.apiUrl}?${params}`, { signal: controller.signal });

      if (!res.ok) {
        console.error('[HomeLoader] API error:', res.status);
        this.hasMore = false;
        return;
      }

      const data = await res.json();

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
            console.warn(`[HomeLoader] Skipping duplicate image ID: ${imgId}`);
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

    } catch (error) {
      console.error('[HomeLoader] Fetch error:', error);
      this.hasMore = false;
    } finally {
      if (timeoutId) {
        clearTimeout(timeoutId);
      }
      this.loading = false;
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
