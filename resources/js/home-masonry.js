import { HomeProgressiveLoader } from './home-progressive-loader.js';

(function () {
  'use strict';

  function onReady(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  function createFetchPriorityObserver(maxHigh = 3) {
    if (!('IntersectionObserver' in window)) return null;
    let highCount = 0;

    const observer = new IntersectionObserver((entries, obs) => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        const img = entry.target;
        if (highCount < maxHigh) {
          img.setAttribute('fetchpriority', 'high');
          img.removeAttribute('loading');
          highCount += 1;
        }
        obs.unobserve(img);
      });
    }, { rootMargin: '200px 0px 200px 0px', threshold: 0.1 });

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

  onReady(() => {
    document.documentElement.classList.add('no-lenis');

    const grid = document.querySelector('.masonry-grid');
    if (!grid) return;

    const config = window.homeLoaderConfig || null;
    if (!config || !config.hasMore) {
      const observer = createFetchPriorityObserver(3);
      if (observer) {
        observer.observe(grid.querySelectorAll('img'));
      }
      return;
    }

    const trigger = document.getElementById('home-load-trigger');
    const loadingEl = document.getElementById('masonry-loading');
    const basePath = config.basePath || '';
    const maxImages = Number.parseInt(config.maxImages, 10) || 0;

    const fetchPriorityObserver = createFetchPriorityObserver(3);
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
      if (maxImages > 0 && loader.shownImageIds.size >= maxImages) {
        loader.hasMore = false;
        loader.disconnect();
        setLoading(false);
        return;
      }

      const item = document.createElement('div');
      item.className = 'masonry-item';

      const link = document.createElement('a');
      link.href = `${basePath}/album/${encodeURIComponent(img.album_slug || '')}`;

      const picture = document.createElement('picture');

      const avif = buildSrcset(img.sources?.avif);
      const webp = buildSrcset(img.sources?.webp);
      const jpg = buildSrcset(img.sources?.jpg);

      if (avif) {
        const source = document.createElement('source');
        source.type = 'image/avif';
        source.srcset = avif;
        picture.appendChild(source);
      }
      if (webp) {
        const source = document.createElement('source');
        source.type = 'image/webp';
        source.srcset = webp;
        picture.appendChild(source);
      }
      if (jpg) {
        const source = document.createElement('source');
        source.type = 'image/jpeg';
        source.srcset = jpg;
        picture.appendChild(source);
      }

      const imgEl = document.createElement('img');
      const src = img.fallback_src || img.url || '';
      imgEl.src = src;
      imgEl.alt = img.alt || img.album_title || '';
      imgEl.width = img.width || 800;
      imgEl.height = img.height || 600;
      imgEl.loading = 'lazy';
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
      await originalLoadMore();
      setLoading(loader.hasMore);
    };

    if (trigger) {
      loader.observe(trigger);
    }

    loader.startBackgroundLoading();
  });
})();
