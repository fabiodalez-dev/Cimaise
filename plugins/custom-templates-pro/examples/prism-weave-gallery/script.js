(function() {
  const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const gallery = document.querySelector('.pw-gallery');
  const tiles = document.querySelectorAll('.pw-sparse-item[data-reveal]');
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

  const applyOffsets = () => {
    const isDesktop = window.matchMedia('(min-width: 1024px)').matches;
    tiles.forEach((tile, index) => {
      if (!isDesktop) {
        tile.style.setProperty('--pw-x', '0px');
        return;
      }
      const rand = (Math.sin(index * 97.1) + 1) / 2;
      const offset = Math.round(rand * 360 - 180);
      tile.style.setProperty('--pw-x', `${offset}px`);
    });
  };
  applyOffsets();
  window.addEventListener('resize', applyOffsets);

  const observer = new IntersectionObserver((entries, obs) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        const index = tileIndices.get(entry.target);
        const delay = Math.min(index * 40, 800);
        entry.target.style.transitionDelay = `${delay}ms`;
        entry.target.classList.add('is-visible');
        obs.unobserve(entry.target);
      }
    });
  }, { threshold: 0.15 });

  tiles.forEach((tile) => observer.observe(tile));
})();
