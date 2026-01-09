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
    this.shownAlbumIds = options.shownAlbumIds || [];
    this.hasMore = true;
    this.loading = false;

    // DEDUPLICATION: Use Set for O(1) lookup
    this.shownImageIds = new Set(
      (options.shownImageIds || []).map(id => parseInt(id, 10))
    );
  }

  /**
   * Load next batch of images from API
   * @returns {Promise<void>}
   */
  async loadMore() {
    if (this.loading || !this.hasMore) return;
    this.loading = true;

    try {
      const params = new URLSearchParams({
        exclude: Array.from(this.shownImageIds).join(','),
        excludeAlbums: this.shownAlbumIds.join(','),
        limit: String(this.batchSize)
      });

      const res = await fetch(`${this.apiUrl}?${params}`);

      if (!res.ok) {
        console.error('[HomeLoader] API error:', res.status);
        this.hasMore = false;
        return;
      }

      const data = await res.json();

      // Append images via callback - SKIP DUPLICATES
      if (Array.isArray(data.images)) {
        data.images.forEach(img => {
          const imgId = parseInt(img.id, 10);

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

      // Track new albums for diversity
      if (Array.isArray(data.newAlbumIds)) {
        this.shownAlbumIds.push(...data.newAlbumIds);
      }

      // Update hasMore flag
      this.hasMore = Boolean(data.hasMore);

    } catch (error) {
      console.error('[HomeLoader] Fetch error:', error);
      this.hasMore = false;
    } finally {
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

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries[0].isIntersecting && this.hasMore && !this.loading) {
          this.loadMore();
        }
      },
      { rootMargin: '200px' }
    );

    observer.observe(triggerElement);
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
