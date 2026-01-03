(function() {
  const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const gallery = document.querySelector('.pw-gallery');
  const tiles = document.querySelectorAll('.pw-tile[data-reveal]');
  if (!tiles.length) {
    return;
  }
  if (gallery && !prefersReduced) {
    gallery.classList.add('pw-animate');
  }

  if (prefersReduced) {
    tiles.forEach((tile) => tile.classList.add('is-visible'));
    return;
  }

  const tileIndices = new WeakMap();
  tiles.forEach((tile, index) => tileIndices.set(tile, index));

  const observer = new IntersectionObserver((entries, obs) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        const index = tileIndices.get(entry.target);
        entry.target.style.transitionDelay = `${index * 40}ms`;
        entry.target.classList.add('is-visible');
        obs.unobserve(entry.target);
      }
    });
  }, { threshold: 0.15 });

  tiles.forEach((tile) => observer.observe(tile));
})();
