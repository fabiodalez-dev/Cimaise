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

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

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

    const parseDurationMs = (raw) => {
      const val = String(raw || '').trim();
      const match = val.match(/^([0-9.]+)\s*(ms|s)?$/);
      if (!match) return 60000;
      const num = parseFloat(match[1]);
      return match[2] === 'ms' ? num : num * 1000;
    };

    const getOuterSize = (el, axis) => {
      const rect = el.getBoundingClientRect();
      const styles = getComputedStyle(el);
      if (axis === 'x') {
        const margin = parseFloat(styles.marginLeft) + parseFloat(styles.marginRight);
        return rect.width + margin;
      }
      const margin = parseFloat(styles.marginTop) + parseFloat(styles.marginBottom);
      return rect.height + margin;
    };

    const virtualTracks = [];
    let virtualRaf = null;

    const rebuildTrack = (track, axis, direction, durationMs, pauseOnHover) => {
      const parent = track.closest('.home-col, .home-row');
      if (!parent) return;

      track.querySelectorAll('[data-virtual-clone="1"]').forEach((node) => node.remove());

      const originals = Array.from(track.children).filter(
        (child) => child.getAttribute('data-virtual-clone') !== '1'
      );
      if (!originals.length) return;

      let originalLength = 0;
      originals.forEach((item) => {
        originalLength += getOuterSize(item, axis);
      });

      if (!originalLength) return;

      const viewSize = axis === 'x'
        ? parent.getBoundingClientRect().width
        : parent.getBoundingClientRect().height;

      let totalLength = originalLength;
      let cloneRounds = 0;
      while (totalLength < originalLength + viewSize && cloneRounds < 4) {
        originals.forEach((item) => {
          const clone = item.cloneNode(true);
          clone.setAttribute('data-virtual-clone', '1');
          clone.querySelectorAll('.home-item').forEach((node) => {
            node.classList.remove('home-item--hidden');
            node.classList.add('home-item--revealed');
          });
          track.appendChild(clone);
        });
        totalLength += originalLength;
        cloneRounds += 1;
      }

      const state = {
        el: track,
        axis,
        direction,
        durationMs: Math.max(1000, durationMs),
        loopLength: originalLength,
        offset: 0,
        paused: false,
        lastTime: performance.now(),
      };

      track.classList.add(axis === 'x' ? 'home-track-h--virtual' : 'home-track--virtual');
      track.style.transform = 'translate3d(0, 0, 0)';

      if (pauseOnHover && parent) {
        parent.addEventListener('mouseenter', () => { state.paused = true; }, { passive: true });
        parent.addEventListener('mouseleave', () => {
          state.paused = false;
          state.lastTime = performance.now();
        }, { passive: true });
      }

      virtualTracks.push(state);
    };

    const initVirtualTracks = () => {
      if (prefersReducedMotion) return;
      virtualTracks.length = 0;
      document.querySelectorAll('.home-track').forEach((track) => {
        const parent = track.closest('.home-col');
        const direction = parent?.getAttribute('data-direction') || 'down';
        const durationMs = parseDurationMs(getComputedStyle(parent).getPropertyValue('--home-duration'));
        rebuildTrack(track, 'y', direction, durationMs, true);
      });
      document.querySelectorAll('.home-track-h').forEach((track) => {
        const parent = track.closest('.home-row');
        const direction = parent?.getAttribute('data-direction') || 'right';
        const durationMs = parseDurationMs(getComputedStyle(parent).getPropertyValue('--home-duration'));
        rebuildTrack(track, 'x', direction, durationMs, false);
      });
    };

    const tickVirtualTracks = (now) => {
      virtualTracks.forEach((state) => {
        if (state.paused || !state.loopLength) return;
        const delta = now - state.lastTime;
        state.lastTime = now;
        const speed = state.loopLength / state.durationMs;
        state.offset = (state.offset + speed * delta) % state.loopLength;
        let translate = -state.offset;
        if (state.direction === 'up' || state.direction === 'left') {
          translate = -state.loopLength + state.offset;
        }
        if (state.axis === 'x') {
          state.el.style.transform = `translate3d(${translate}px, 0, 0)`;
        } else {
          state.el.style.transform = `translate3d(0, ${translate}px, 0)`;
        }
      });
      virtualRaf = requestAnimationFrame(tickVirtualTracks);
    };

    // Debounce resize with requestAnimationFrame for performance
    let resizeRaf = null;
    window.addEventListener('resize', () => {
      if (resizeRaf) return;
      resizeRaf = requestAnimationFrame(() => {
        syncLayout();
        initVirtualTracks();
        resizeRaf = null;
      });
    });

    initVirtualTracks();
    if (!virtualRaf && virtualTracks.length) {
      virtualRaf = requestAnimationFrame(tickVirtualTracks);
    }

    // Ensure the gallery is visible even if JS animations are disabled
    gallery.style.opacity = '1';

    // Get all home-item elements
    const items = Array.from(gallery.querySelectorAll('.home-item'))
      .filter((item) => !item.closest('[data-virtual-clone="1"]'));
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
