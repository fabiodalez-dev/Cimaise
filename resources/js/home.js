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

    // Remove inline opacity:0 from container to show the gallery
    gallery.style.opacity = '1';

    // Get all home-item elements
    const items = gallery.querySelectorAll('.home-item');
    if (!items.length) return;

    // Reveal items with animation
    const revealItem = (item) => {
      item.classList.add('home-item--revealed');
    };

    // Check for reduced motion preference
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (prefersReducedMotion) {
      // Immediately reveal all items without animation
      items.forEach(revealItem);
      return;
    }

    // Use IntersectionObserver for viewport-based reveal
    if ('IntersectionObserver' in window) {
      const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            revealItem(entry.target);
            observer.unobserve(entry.target);
          }
        });
      }, {
        rootMargin: '100px',
        threshold: 0.01
      });

      items.forEach(item => observer.observe(item));
    } else {
      // Fallback: reveal all items immediately
      items.forEach(revealItem);
    }
  });
})();
