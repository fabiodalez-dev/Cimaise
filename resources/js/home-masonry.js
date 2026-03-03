import { HomeProgressiveLoader } from './home-progressive-loader.js';
import { createFetchPriorityObserver } from './utils/fetch-priority-observer.js';

(function () {
  'use strict';

  function onReady(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  function createFetchPriorityObserverWrapper(maxHigh = 3) {
    const observer = createFetchPriorityObserver(maxHigh);
    if (!observer) return null;
    return {
      observe(images) {
        const list = Array.from(images || []);
        list.forEach(img => observer.observe(img));
      }
    };
  }

  function buildSrcset(sources) {
    if (!Array.isArray(sources) || sources.length === 0) return '';
    return sources.join(', ');
  }

  /**
   * Calculate and set the optimal grid height so that CSS column-fill: auto
   * distributes items across ALL columns (prevents empty rightmost columns).
   */
  function balanceColumns(grid) {
    const cols = parseInt(getComputedStyle(grid).columnCount, 10) || 1;
    const items = grid.querySelectorAll('.masonry-item');
    if (items.length === 0 || items.length <= cols) return;

    // Reset height so items flow naturally for measurement
    grid.style.height = '';

    const gap = parseFloat(getComputedStyle(grid).columnGap) || 0;
    const gridWidth = grid.offsetWidth;
    const colWidth = (gridWidth - gap * (cols - 1)) / cols;

    // Calculate total visual height from aspect ratios (no layout thrash)
    let totalHeight = 0;
    items.forEach(item => {
      const img = item.querySelector('img');
      const w = parseFloat(img?.getAttribute('width')) || 1;
      const h = parseFloat(img?.getAttribute('height')) || 1;
      const mbStr = getComputedStyle(item).marginBottom;
      const mb = parseFloat(mbStr) || 0;
      totalHeight += (colWidth * h / w) + mb;
    });

    // Optimal height = total / cols, with a small buffer for rounding
    const optimalHeight = Math.ceil(totalHeight / cols) + 2;
    grid.style.height = optimalHeight + 'px';
  }

  onReady(() => {
    document.documentElement.classList.add('no-lenis');

    const grid = document.querySelector('.masonry-grid');
    if (!grid) return;

    // Balance columns on load and resize
    balanceColumns(grid);
    let resizeTimer;
    window.addEventListener('resize', () => {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(() => balanceColumns(grid), 150);
    });

    const config = window.homeLoaderConfig || null;
    if (!config || !config.hasMore) {
      const observer = createFetchPriorityObserverWrapper(3);
      if (observer) {
        observer.observe(grid.querySelectorAll('img'));
      }
      return;
    }

    const trigger = document.getElementById('home-load-trigger');
    const loadingEl = document.getElementById('masonry-loading');
    const basePath = config.basePath || '';
    const maxImages = Number.parseInt(config.maxImages, 10) || 0;

    const fetchPriorityObserver = createFetchPriorityObserverWrapper(3);
    if (fetchPriorityObserver) {
      fetchPriorityObserver.observe(grid.querySelectorAll('img'));
    }

    let loader = null;

    const setLoading = (active) => {
      if (!loadingEl) return;
      loadingEl.classList.toggle('is-visible', active);
      loadingEl.setAttribute('aria-hidden', active ? 'false' : 'true');
    };

    const renderImage = (img) => {
      if (!loader) return;
      // Skip images without valid album link (prevents broken /album/ links)
      if (!img.album_slug) return;
      // Skip images without valid source (prevents broken img elements)
      const src = img.fallback_src || img.url;
      if (!src) return;

      if (maxImages > 0 && loader.shownImageIds.size >= maxImages) {
        loader.hasMore = false;
        loader.disconnect();
        setLoading(false);
        return;
      }

      const item = document.createElement('div');
      item.className = 'masonry-item';

      const link = document.createElement('a');
      link.href = `${basePath}/album/${encodeURIComponent(img.album_slug)}`;

      const picture = document.createElement('picture');

      const avif = buildSrcset(img.sources?.avif);
      const webp = buildSrcset(img.sources?.webp);
      const jpg = buildSrcset(img.sources?.jpg);

      if (avif) {
        const source = document.createElement('source');
        source.type = 'image/avif';
        source.srcset = avif;
        source.sizes = '(min-width:1024px) 33vw, (min-width:640px) 50vw, 100vw';
        picture.appendChild(source);
      }
      if (webp) {
        const source = document.createElement('source');
        source.type = 'image/webp';
        source.srcset = webp;
        source.sizes = '(min-width:1024px) 33vw, (min-width:640px) 50vw, 100vw';
        picture.appendChild(source);
      }
      if (jpg) {
        const source = document.createElement('source');
        source.type = 'image/jpeg';
        source.srcset = jpg;
        source.sizes = '(min-width:1024px) 33vw, (min-width:640px) 50vw, 100vw';
        picture.appendChild(source);
      }

      const imgEl = document.createElement('img');
      imgEl.src = src;
      imgEl.alt = img.alt || img.album_title || '';
      imgEl.width = img.width || 800;
      imgEl.height = img.height || 600;
      imgEl.loading = 'lazy';
      imgEl.decoding = 'async';
      imgEl.style.aspectRatio = `${imgEl.width} / ${imgEl.height}`;

      picture.appendChild(imgEl);
      link.appendChild(picture);
      item.appendChild(link);
      grid.appendChild(item);

      if (fetchPriorityObserver) {
        fetchPriorityObserver.observe([imgEl]);
      }
    };

    loader = new HomeProgressiveLoader({
      apiUrl: `${basePath}/api/home/gallery`,
      container: grid,
      shownImageIds: config.shownImageIds || [],
      shownAlbumIds: config.shownAlbumIds || [],
      batchSize: 24,
      extraParams: { mode: 'masonry' },
      renderImage
    });

    const originalLoadMore = loader.loadMore.bind(loader);
    loader.loadMore = async () => {
      setLoading(true);
      try {
        await originalLoadMore();
      } finally {
        // Rebalance columns after new images are added
        balanceColumns(grid);
        setLoading(false);
      }
    };

    if (trigger) {
      loader.observe(trigger);
    }

    // NOTE: Background loading disabled for masonry template
    // CSS columns reflow ALL items when new ones are added, causing jarring visual shifts.
    // Instead, we rely solely on IntersectionObserver to load more when user scrolls to bottom.
    // This provides a better UX: images only load when user explicitly scrolls down.
  });
})();
