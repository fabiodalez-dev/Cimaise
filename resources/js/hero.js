import simpleParallax from 'simple-parallax-js';

function initHeroParallax() {
  const img = document.querySelector('.js-hero-parallax');
  if (!img) return;
  try {
    // Avoid re-initialization on SPA-like updates
    if (img.dataset.parallaxInit === '1') return;
    new simpleParallax(img, {
      scale: 1.2,
      delay: 0.2,
      transition: 'cubic-bezier(0,0,0,1)'
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

