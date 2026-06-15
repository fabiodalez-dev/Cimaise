// Home page specific scripts
// Lenis smooth scroll is now loaded globally via smooth-scroll.js
import './home-gallery.js'
import './albums-carousel.js'
import { HomeProgressiveLoader } from './home-progressive-loader.js'
import { createFetchPriorityObserver } from './utils/fetch-priority-observer.js'
import { getBasePath, normalizeBasePath } from './utils/base-path.js'

/**
 * Home Infinite Gallery - Entry animation reveal + Progressive Loading
 * Handles the fade-in animation for .home-item elements in the classic home template
 * and progressive loading of additional images via API
 */
(function () {
  'use strict';

  function onReady(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  /**
   * Create a home-item element from API image data
   * @param {Object} img - Image data from API
   * @param {string} basePath - Base URL path
   * @param {boolean} isHorizontal - Whether the gallery uses horizontal layout
   * @returns {HTMLElement} The created cell element
   */
  function createHomeItem(img, basePath, isHorizontal) {
    const w = Number.parseInt(img.width, 10);
    const h = Number.parseInt(img.height, 10);
    const safeW = Number.isFinite(w) && w > 0 ? w : 1600;
    const safeH = Number.isFinite(h) && h > 0 ? h : 1067;

    // Build srcset strings
    const buildSrcset = (sources) => {
      if (!sources || !sources.length) return '';
      return sources.map(s => {
        const parts = s.split(' ');
        const url = parts[0].startsWith('/') ? basePath + parts[0] : parts[0];
        return `${url} ${parts[1] || '1x'}`;
      }).join(', ');
    };

    const avifSrcset = buildSrcset(img.sources?.avif);
    const webpSrcset = buildSrcset(img.sources?.webp);
    const jpgSrcset = buildSrcset(img.sources?.jpg);

    const fallbackSrc = img.fallback_src || img.url || '';
    const imgSrc = fallbackSrc && fallbackSrc.startsWith('/') ? basePath + fallbackSrc : fallbackSrc;
    const albumUrl = `${basePath}/album/${encodeURIComponent(img.album_slug || '')}`;
    const alt = img.alt || img.album_title || '';
    const title = img.album_title || '';

    // Create picture element with responsive sources
    const cell = document.createElement('div');
    cell.className = isHorizontal ? 'home-cell-h' : 'home-cell';

    const item = document.createElement('div');
    item.className = 'home-item group rounded-xl overflow-hidden shadow-sm relative transition-transform hover:scale-105 duration-300';
    item.style.aspectRatio = `${safeW} / ${safeH}`;
    item.dataset.imageId = String(parseInt(img.id, 10) || 0);

    const link = document.createElement('a');
    link.href = albumUrl;
    link.className = 'block w-full h-full relative z-10';
    link.title = title;

    const picture = document.createElement('picture');
    picture.className = 'block w-full h-full';

    const addSource = (type, srcset) => {
      if (!srcset) return;
      const source = document.createElement('source');
      source.type = type;
      source.srcset = srcset;
      source.sizes = '(min-width:1024px) 50vw, (min-width:640px) 70vw, 100vw';
      picture.appendChild(source);
    };

    addSource('image/avif', avifSrcset);
    addSource('image/webp', webpSrcset);
    addSource('image/jpeg', jpgSrcset);

    const imgEl = document.createElement('img');
    imgEl.src = imgSrc;
    imgEl.alt = alt;
    imgEl.width = safeW;
    imgEl.height = safeH;
    imgEl.loading = 'lazy';
    imgEl.decoding = 'async';
    imgEl.className = 'w-full h-full object-cover block';

    picture.appendChild(imgEl);

    const overlay = document.createElement('div');
    overlay.className = 'absolute inset-0 bg-black/70 text-white flex items-center justify-center transform translate-y-full group-hover:translate-y-0 transition-transform duration-300';

    const caption = document.createElement('span');
    caption.className = 'px-4 text-base md:text-lg lg:text-xl font-medium tracking-tight text-center';
    caption.textContent = title;
    overlay.appendChild(caption);

    link.appendChild(picture);
    link.appendChild(overlay);
    item.appendChild(link);
    cell.appendChild(item);

    return cell;
  }

  /**
   * Parse the inert JSON payload (#home-gallery-data) that carries the gallery
   * image data for the desktop marquee. Returns null if absent/malformed.
   * @returns {Object|null}
   */
  function readGalleryData() {
    const el = document.getElementById('home-gallery-data');
    if (!el) return null;
    try {
      return JSON.parse(el.textContent || 'null');
    } catch (e) {
      console.error('[HomeGallery] Bad gallery data payload:', e);
      return null;
    }
  }

  /**
   * Rebuild the 3 column/row orderings exactly as the server template does
   * (rotations by o2/o3) so the JS-built marquee matches the server seed.
   * @param {Array} images
   * @param {number} o2
   * @param {number} o3
   * @returns {Array<Array>}
   */
  function computeColumns(images, o2, o3) {
    const rot = (n) => images.slice(n).concat(images.slice(0, n));
    return [images, rot(o2), rot(o3)];
  }

  /**
   * Fill the desktop marquee tracks from the image data. The -50% scroll
   * animation needs an EVEN number of identical copies for a seamless loop.
   * Column 0 already has `seed` server-rendered cells, so its first copy starts
   * after the seed; the remaining copies are full.
   * @param {HTMLElement} gallery
   * @param {Object} data - parsed #home-gallery-data
   * @param {string} basePath
   * @param {IntersectionObserver|null} observer
   */
  function buildDesktopMarquee(gallery, data, basePath, observer) {
    if (gallery.dataset.marqueeBuilt === '1') return;
    const images = Array.isArray(data.images) ? data.images : [];
    if (!images.length) return;

    const isHorizontal = Boolean(data.isHorizontal);
    const seed = Math.max(0, parseInt(data.seed, 10) || 0);
    // Even copy count >= requested repeat (and >= 2) for a seamless -50% loop.
    let copies = Math.max(2, parseInt(data.repeat, 10) || 2);
    if (copies % 2 !== 0) copies += 1;

    const columns = computeColumns(images, parseInt(data.o2, 10) || 0, parseInt(data.o3, 10) || 0);
    const trackSelector = isHorizontal ? '.home-track-h' : '.home-track';
    const tracks = Array.from(gallery.querySelectorAll(trackSelector));

    tracks.forEach((track, colIdx) => {
      const colImages = columns[colIdx] || images;
      const hasSeed = colIdx === 0 && track.querySelector('[data-seed]') !== null;
      const frag = document.createDocumentFragment();
      for (let c = 0; c < copies; c++) {
        const start = (c === 0 && hasSeed) ? seed : 0;
        for (let i = start; i < colImages.length; i++) {
          frag.appendChild(createHomeItem(colImages[i], basePath, isHorizontal));
        }
      }
      track.appendChild(frag);
    });

    if (observer) {
      gallery.querySelectorAll(`${trackSelector} img`).forEach(img => observer.observe(img));
    }
    gallery.dataset.marqueeBuilt = '1';
  }

  /**
   * Decide WHEN to build the desktop marquee.  <<< TUNABLE POLICY >>>
   * Trade-off: build the instant desktop matches (marquee ready sooner, more
   * main-thread work up front) vs. defer to idle (smoother first paint, marquee
   * appears a touch later); plus whether to react to a later mobile->desktop
   * resize/rotate. Default: build now if already on desktop, and on the first
   * breakpoint crossing thereafter.
   * @param {MediaQueryList} mql - matchMedia('(min-width:768px)')
   * @param {() => void} build - idempotent; safe to call more than once
   */
  function scheduleDesktopBuild(mql, build) {
    if (mql.matches) build();
    const onChange = (e) => { if (e.matches) build(); };
    if (typeof mql.addEventListener === 'function') {
      mql.addEventListener('change', onChange);
    } else if (typeof mql.addListener === 'function') {
      mql.addListener(onChange); // Safari < 14 fallback
    }
  }

  onReady(() => {
    const gallery = document.getElementById('home-infinite-gallery');
    if (!gallery) return;

    // Ensure the gallery is visible even if JS animations are disabled
    gallery.style.opacity = '1';

    // Show all items immediately without entry animations

    // Single source of truth for the base path (normalized, subfolder-safe)
    const basePath = (window.homeLoaderConfig && window.homeLoaderConfig.basePath)
      ? normalizeBasePath(window.homeLoaderConfig.basePath)
      : getBasePath();

    const observer = createFetchPriorityObserver(3);
    if (observer) {
      gallery.querySelectorAll('img').forEach(img => observer.observe(img));
    }

    // Build the desktop marquee from the JSON payload, but only on the desktop
    // breakpoint — so mobile never carries the heavy duplicated variant in the
    // DOM. The mobile list + a tiny server-rendered seed cover small viewports.
    const galleryData = readGalleryData();
    if (galleryData) {
      const mql = window.matchMedia('(min-width:768px)');
      scheduleDesktopBuild(mql, () => buildDesktopMarquee(gallery, galleryData, basePath, observer));
    }

    // Progressive Loading: Load more images via API
    const config = window.homeLoaderConfig;
    if (config && config.hasMore) {
      // Get containers for appending new images
      const isHorizontal = gallery.closest('[data-scroll-direction="horizontal"]') !== null;
      const mobileCells = gallery.querySelector('.home-mobile');
      const tracks = isHorizontal
        ? Array.from(gallery.querySelectorAll('.home-track-h'))
        : Array.from(gallery.querySelectorAll('.home-track'));

      // Track which column/row to append to (round-robin)
      let appendIndex = 0;

      const loader = new HomeProgressiveLoader({
        apiUrl: `${basePath}/api/home/gallery`,
        container: gallery,
        shownImageIds: config.shownImageIds,
        shownAlbumIds: config.shownAlbumIds,
        batchSize: 20,
        renderImage: (img) => {
          const cell = createHomeItem(img, basePath, isHorizontal);

          // Append to mobile layout (create separate item to preserve event listeners)
          if (mobileCells) {
            const mobileCell = createHomeItem(img, basePath, false);
            mobileCell.className = 'home-cell';
            mobileCells.appendChild(mobileCell);
            if (observer) {
              mobileCell.querySelectorAll('img').forEach(imgEl => observer.observe(imgEl));
            }
          }

          // Append to desktop layout (distribute across columns/rows)
          if (tracks.length > 0) {
            const targetTrack = tracks[appendIndex % tracks.length];
            targetTrack.appendChild(cell);
            appendIndex++;
            if (observer) {
              cell.querySelectorAll('img').forEach(imgEl => observer.observe(imgEl));
            }
          }
        }
      });

      // NOTE: Background loading disabled for classic infinite gallery template
      // The CSS animation-based infinite scroll already duplicates images for seamless looping.
      // Loading more images would only add to bandwidth without visual benefit.
      // Instead, rely solely on IntersectionObserver - loads more only when user scrolls to trigger.

      // Load more when trigger element becomes visible (user scrolled to bottom)
      const trigger = document.getElementById('home-load-trigger');
      if (trigger) {
        loader.observe(trigger);
      }
    }
  });
})();
