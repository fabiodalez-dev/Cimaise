// Home page specific scripts
// Lenis smooth scroll is now loaded globally via smooth-scroll.js
import './home-gallery.js'
import './albums-carousel.js'
import { HomeProgressiveLoader } from './home-progressive-loader.js'

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
   * Escape HTML special characters to prevent XSS
   * @param {string} str - String to escape
   * @returns {string} Escaped string safe for HTML insertion
   */
  function escapeHtml(str) {
    if (str == null) return '';
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
  }

  /**
   * Escape string for use in HTML attributes
   * @param {string} str - String to escape
   * @returns {string} Escaped string safe for attribute values
   */
  function escapeAttr(str) {
    if (str == null) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  /**
   * Create a home-item element from API image data
   * @param {Object} img - Image data from API
   * @param {string} basePath - Base URL path
   * @param {boolean} isHorizontal - Whether the gallery uses horizontal layout
   * @returns {HTMLElement} The created cell element
   */
  function createHomeItem(img, basePath, isHorizontal) {
    const w = img.width || 1600;
    const h = img.height || 1067;

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
    cell.innerHTML = `
      <div class="home-item home-item--revealed group rounded-xl overflow-hidden shadow-sm relative transition-transform hover:scale-105 duration-300" style="aspect-ratio: ${parseInt(w, 10)} / ${parseInt(h, 10)};" data-image-id="${parseInt(img.id, 10) || 0}">
        <a href="${escapeAttr(albumUrl)}" class="block w-full h-full relative z-10" title="${escapeAttr(title)}">
          <picture class="block w-full h-full">
            ${avifSrcset ? `<source type="image/avif" srcset="${escapeAttr(avifSrcset)}" sizes="(min-width:1024px) 50vw, (min-width:640px) 70vw, 100vw">` : ''}
            ${webpSrcset ? `<source type="image/webp" srcset="${escapeAttr(webpSrcset)}" sizes="(min-width:1024px) 50vw, (min-width:640px) 70vw, 100vw">` : ''}
            ${jpgSrcset ? `<source type="image/jpeg" srcset="${escapeAttr(jpgSrcset)}" sizes="(min-width:1024px) 50vw, (min-width:640px) 70vw, 100vw">` : ''}
            <img src="${escapeAttr(imgSrc)}" alt="${escapeAttr(alt)}" width="${parseInt(w, 10)}" height="${parseInt(h, 10)}" loading="lazy" decoding="async" class="w-full h-full object-cover block">
          </picture>
          <div class="absolute inset-0 bg-black/70 text-white flex items-center justify-center transform translate-y-full group-hover:translate-y-0 transition-transform duration-300">
            <span class="px-4 text-base md:text-lg lg:text-xl font-medium tracking-tight text-center">${escapeHtml(title)}</span>
          </div>
        </a>
      </div>
    `;

    return cell;
  }

  onReady(() => {
    const gallery = document.getElementById('home-infinite-gallery');
    if (!gallery) return;

    const mobileWrap = document.querySelector('.home-mobile-wrap');
    const desktopWrap = document.querySelector('.home-desktop-wrap');
    const syncLayout = () => {
      if (!mobileWrap || !desktopWrap) return;
      if (window.innerWidth >= 768) {
        mobileWrap.style.display = 'none';
        desktopWrap.style.display = 'flex';
      } else {
        mobileWrap.style.display = 'block';
        desktopWrap.style.display = 'none';
      }
    };

    syncLayout();

    // Debounce resize with requestAnimationFrame for performance
    let resizeRaf = null;
    window.addEventListener('resize', () => {
      if (resizeRaf) return;
      resizeRaf = requestAnimationFrame(() => {
        syncLayout();
        resizeRaf = null;
      });
    });

    // Ensure the gallery is visible even if JS animations are disabled
    gallery.style.opacity = '1';

    // Get all home-item elements and reveal them immediately
    // No staggered animation - all photos visible on page load
    const items = Array.from(gallery.querySelectorAll('.home-item'));
    items.forEach((item) => item.classList.add('home-item--revealed'));

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

          // Append to mobile layout (always use home-cell class for mobile)
          if (mobileCells) {
            const mobileCell = cell.cloneNode(true);
            mobileCell.className = 'home-cell';
            mobileCells.appendChild(mobileCell);
          }

          // Append to desktop layout (distribute across columns/rows)
          if (tracks.length > 0) {
            const targetTrack = tracks[appendIndex % tracks.length];
            targetTrack.appendChild(cell);
            appendIndex++;
          }
        }
      });

      // Start loading more images immediately
      loader.startBackgroundLoading();

      // Also load when trigger element becomes visible
      const trigger = document.getElementById('home-load-trigger');
      if (trigger) {
        loader.observe(trigger);
      }
    }
  });
})();
