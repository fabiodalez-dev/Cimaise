import simpleParallax from 'simple-parallax-js';

function initHeroParallax() {
  const img = document.querySelector('.js-hero-parallax');
  if (!img) return;
  try {
    // Avoid re-initialization on SPA-like updates
    if (img.dataset.parallaxInit === '1') return;
    new simpleParallax(img, {
      // Stronger parallax feel
      scale: 1.8,
      delay: 0,               // snappier response
      overflow: true,         // allow larger movement without clipping
      orientation: 'up',      // move counter to scroll
      maxTransition: 120,     // increase pixel movement
      transition: 'cubic-bezier(0.2, 0.6, 0, 1)'
    });
    img.dataset.parallaxInit = '1';
  } catch (e) {
    // silent
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initHeroParallax);
} else {
  initHeroParallax();
}

// In case of template switches via AJAX, allow re-init by exposing a hook
window.initHeroParallax = initHeroParallax;
