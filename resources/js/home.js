// Home page specific scripts
// Lenis smooth scroll is now loaded globally via smooth-scroll.js
import './home-gallery.js'
import './albums-carousel.js'

/**
 * Home Infinite Gallery - Entry animation reveal
 * Handles the fade-in animation for .home-item elements in the classic home template
 */
(function() {
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

    // Get all home-item elements
    const items = Array.from(gallery.querySelectorAll('.home-item'));
    if (!items.length) return;

    // Too many nodes: reveal immediately to avoid blank gallery on load
    // With 600+ items, staggered setTimeout animations would take too long
    // and risk showing a blank gallery. Skip animation for performance.
    if (items.length > 600) {
      items.forEach((item) => item.classList.add('home-item--revealed'));
      return;
    }

    // Set initial hidden state only when JS is active
    items.forEach((item) => item.classList.add('home-item--hidden'));

    // Reveal items with animation
    const revealItem = (item) => {
      item.classList.remove('home-item--hidden');
      item.classList.add('home-item--revealed');
    };

    // Check for reduced motion preference
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (prefersReducedMotion) {
      // Immediately reveal all items without animation
      items.forEach(revealItem);
      return;
    }

    // Reveal in random order and complete within target duration
    //
    // Note: We use setTimeout-based random reveal instead of IntersectionObserver.
    // IntersectionObserver caused issues with:
    // - bfcache (back-forward cache): observers can fire unexpectedly on restore
    // - Rapid scroll: multiple intersection events caused flickering
    // - Mobile: inconsistent behavior with CSS column layouts
    //
    // The timeout approach ensures predictable, smooth entry animation without
    // these edge cases. If reverting to IntersectionObserver, test thoroughly:
    // 1. Navigate away and press back button (bfcache restore)
    // 2. Rapid scroll through gallery on mobile
    // 3. Resize window during animation
    const totalMs = 6000;
    const step = Math.max(8, Math.floor(totalMs / items.length));
    const shuffled = items
      .map((item) => ({ item, key: Math.random() }))
      .sort((a, b) => a.key - b.key)
      .map(({ item }) => item);

    shuffled.forEach((item, index) => {
      setTimeout(() => revealItem(item), step * index);
    });
  });
})();
