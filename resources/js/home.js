// Home page specific scripts
// Lenis smooth scroll is now loaded globally via smooth-scroll.js
import './home-gallery.js'
import './albums-carousel.js'
import { HomeProgressiveLoader } from './home-progressive-loader.js'
import { createFetchPriorityObserver } from './utils/fetch-priority-observer.js'

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

    const fallbackSrc = img.fallback_src || img.url;
    const imgSrc = fallbackSrc.startsWith('/') ? basePath + fallbackSrc : fallbackSrc;
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

  onReady(() => {
    const gallery = document.getElementById('home-infinite-gallery');
    if (!gallery) return;

    // Ensure the gallery is visible even if JS animations are disabled
    gallery.style.opacity = '1';

    // Show all items immediately without entry animations

    const observer = createFetchPriorityObserver(3);
    if (observer) {
      gallery.querySelectorAll('img').forEach(img => observer.observe(img));
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
        apiUrl: `${config.basePath}/api/home/gallery`,
        container: gallery,
        shownImageIds: config.shownImageIds,
        shownAlbumIds: config.shownAlbumIds,
        batchSize: 20,
        renderImage: (img) => {
          const cell = createHomeItem(img, config.basePath, isHorizontal);

          // Append to mobile layout (create separate item to preserve event listeners)
          if (mobileCells) {
            const mobileCell = createHomeItem(img, config.basePath, false);
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
