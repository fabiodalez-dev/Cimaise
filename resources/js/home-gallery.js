/**
 * Home Gallery Wall - Horizontal scroll with Lenis
 */
import Lenis from 'lenis';
import { createFetchPriorityObserver } from './utils/fetch-priority-observer.js';

(function() {
  'use strict';

  const gallerySection = document.getElementById('galleryWallSection');
  const track = document.getElementById('galleryWallTrack');
  const headerHeight = 80;

  if (!gallerySection || !track) return;

  let isMobile = window.innerWidth <= 768;
  let lenis = null;
  let rafId = null;
  // Cached measurements (recomputed only on init/resize, not per frame)
  let cachedMaxScroll = 0;
  let cachedSectionScrollHeight = 1;
  const prefersReduced = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

  function stopRaf() {
    if (rafId !== null) { cancelAnimationFrame(rafId); rafId = null; }
  }

  function setupFetchPriorityObserver() {
    if (!('IntersectionObserver' in window)) return;

    const images = Array.from(document.querySelectorAll('#galleryWallSection img, .gallery-mobile-grid img'));
    if (!images.length) return;

    const observer = createFetchPriorityObserver(3);
    if (!observer) return;
    images.forEach(img => observer.observe(img));
  }

  // Map scroll position to horizontal translate. Reads only rect.top (changes
  // with scroll); section height / max scroll are cached. Skips the write when
  // the section is fully out of view.
  function updateGallery() {
    const rect = gallerySection.getBoundingClientRect();
    if (rect.bottom <= 0 || rect.top >= window.innerHeight) return;
    const scrollInSection = Math.max(0, -rect.top);
    const progress = cachedSectionScrollHeight > 0
      ? Math.min(1, scrollInSection / cachedSectionScrollHeight)
      : 0;
    track.style.transform = 'translateX(' + (-progress * cachedMaxScroll) + 'px)';
  }

  function initDesktopGallery() {
    if (isMobile) return;

    // Cancel any previous loop / Lenis before re-init (prevents orphan rAF leak).
    stopRaf();
    if (lenis) { lenis.destroy(); lenis = null; }

    const trackWidth = track.scrollWidth;
    const windowWidth = window.innerWidth;
    const windowHeight = window.innerHeight;
    const containerHeight = windowHeight - headerHeight;

    // Calculate section height:
    // - trackWidth - windowWidth = horizontal scroll distance to show last image
    // - + containerHeight = keep sticky active until last image is fully visible
    // - + containerHeight = buffer to pause on last image before footer
    const sectionHeight = (trackWidth - windowWidth) + containerHeight + containerHeight;
    gallerySection.style.height = sectionHeight + 'px';

    cachedMaxScroll = trackWidth - windowWidth;
    cachedSectionScrollHeight = sectionHeight - windowHeight;

    if (prefersReduced) {
      // Reduced motion: no smoothing. Still map native scroll to the horizontal
      // translate so the gallery layout works, just without Lenis inertia.
      function raf() { updateGallery(); rafId = requestAnimationFrame(raf); }
      rafId = requestAnimationFrame(raf);
      updateGallery();
      return;
    }

    lenis = new Lenis({
      duration: 1.2,
      easing: function(t) { return Math.min(1, 1.001 - Math.pow(2, -10 * t)); },
      smoothWheel: true,
      smoothTouch: false,
      touchMultiplier: 2
    });

    function raf(time) {
      lenis.raf(time);
      updateGallery();
      rafId = requestAnimationFrame(raf);
    }

    rafId = requestAnimationFrame(raf);
  }

  function initMobileGallery() {
    if (!isMobile) return;

    stopRaf();
    if (lenis) { lenis.destroy(); lenis = null; }

    // Reduced motion: fall back to native scrolling (no Lenis).
    if (prefersReduced) return;

    lenis = new Lenis({
      duration: 1,
      smoothWheel: true,
      smoothTouch: false
    });

    function raf(time) {
      lenis.raf(time);
      rafId = requestAnimationFrame(raf);
    }

    rafId = requestAnimationFrame(raf);
  }

  function init() {
    isMobile = window.innerWidth <= 768;

    if (isMobile) {
      initMobileGallery();
    } else {
      initDesktopGallery();
    }
  }

  // PERFORMANCE: Defer expensive init until gallery is in/near viewport
  let hasInitialized = false;
  function deferredInit() {
    if (hasInitialized) return;
    hasInitialized = true;
    init();
  }

  // Use IntersectionObserver to defer init until section is near viewport
  if ('IntersectionObserver' in window) {
    const initObserver = new IntersectionObserver((entries) => {
      if (entries[0].isIntersecting) {
        deferredInit();
        initObserver.disconnect();
      }
    }, { rootMargin: '200px' }); // Start init 200px before section enters viewport
    initObserver.observe(gallerySection);
  } else {
    // Fallback for browsers without IntersectionObserver
    deferredInit();
  }

  // Debounce resize
  let resizeTimeout;
  window.addEventListener('resize', function() {
    if (!hasInitialized) return; // Don't resize before init
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(function() {
      const wasMobile = isMobile;
      isMobile = window.innerWidth <= 768;

      if (wasMobile !== isMobile) {
        init();
      } else if (!isMobile) {
        // Recalculate desktop dimensions
        initDesktopGallery();
      }
    }, 200);
  });

  setupFetchPriorityObserver();

  // Cleanup on page unload (for SPA)
  window.addEventListener('beforeunload', function() {
    stopRaf();
    if (lenis) { lenis.destroy(); lenis = null; }
  });
})();
