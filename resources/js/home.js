// Home page specific scripts
// Lenis smooth scroll is now loaded globally via smooth-scroll.js
import './home-gallery.js'
import './albums-carousel.js'

/**
 * Home Infinite Gallery - Entry animation reveal
 * Handles the fade-in animation for .home-item elements in the classic home template
 */
(function () {
  'use strict';

  function onReady(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
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
  });
})();
