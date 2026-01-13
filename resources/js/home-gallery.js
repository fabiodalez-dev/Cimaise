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

  function setupFetchPriorityObserver() {
    if (!('IntersectionObserver' in window)) return;

    const images = Array.from(document.querySelectorAll('#galleryWallSection img, .gallery-mobile-grid img'));
    if (!images.length) return;

    const observer = createFetchPriorityObserver(3);
    if (!observer) return;
    images.forEach(img => observer.observe(img));
  }

  function initDesktopGallery() {
    if (isMobile) return;

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

    const maxScroll = trackWidth - windowWidth;

    // Initialize Lenis
    if (lenis) lenis.destroy();

    lenis = new Lenis({
      duration: 1.2,
      easing: function(t) { return Math.min(1, 1.001 - Math.pow(2, -10 * t)); },
      smoothWheel: true,
      smoothTouch: false,
      touchMultiplier: 2
    });

    function updateGallery() {
      const rect = gallerySection.getBoundingClientRect();
      const sectionScrollHeight = gallerySection.offsetHeight - window.innerHeight;

      // How far we are into the section
      const scrollInSection = Math.max(0, -rect.top);
      const progress = Math.min(1, scrollInSection / sectionScrollHeight);

      const translateX = -progress * maxScroll;
      track.style.transform = 'translateX(' + translateX + 'px)';
    }

    function raf(time) {
      lenis.raf(time);
      updateGallery();
      requestAnimationFrame(raf);
    }

    requestAnimationFrame(raf);
  }

  function initMobileGallery() {
    if (!isMobile) return;

    // On mobile, simple Lenis smooth scroll
    if (lenis) lenis.destroy();

    lenis = new Lenis({
      duration: 1,
      smoothWheel: true,
      smoothTouch: false
    });

    function raf(time) {
      lenis.raf(time);
      requestAnimationFrame(raf);
    }

    requestAnimationFrame(raf);
  }

  function init() {
    isMobile = window.innerWidth <= 768;

    if (isMobile) {
      initMobileGallery();
    } else {
      initDesktopGallery();
    }
  }

  // Debounce resize
  let resizeTimeout;
  window.addEventListener('resize', function() {
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

  init();
  setupFetchPriorityObserver();

  // Cleanup on page unload (for SPA)
  window.addEventListener('beforeunload', function() {
    if (lenis) lenis.destroy();
  });
})();
