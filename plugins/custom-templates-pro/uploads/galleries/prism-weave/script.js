// NOTE: Keep this file in sync with examples/prism-weave-gallery/script.js.
(function() {
  const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const tiles = document.querySelectorAll('.pw-tile[data-reveal]');
  if (!tiles.length) {
    return;
  }

  if (prefersReduced) {
    tiles.forEach((tile) => tile.classList.add('is-visible'));
    return;
  }

  const observer = new IntersectionObserver((entries, obs) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        const index = Array.prototype.indexOf.call(tiles, entry.target);
        entry.target.style.transitionDelay = `${index * 40}ms`;
        entry.target.classList.add('is-visible');
        obs.unobserve(entry.target);
      }
    });
  }, { threshold: 0.15 });

  tiles.forEach((tile) => observer.observe(tile));
})();
