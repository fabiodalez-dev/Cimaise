(() => {
  const items = Array.from(document.querySelectorAll('.ds-tile[data-drift-item]'));
  if (!items.length) {
    return;
  }
  const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const desktopQuery = window.matchMedia('(min-width: 1024px)');
  const rand = (index) => (Math.sin(index * 91.7) + 1) / 2;

  const setBaseOffsets = () => {
    if (!desktopQuery.matches || prefersReduced) {
      items.forEach((item) => item.style.setProperty('--ds-x', '0px'));
      return;
    }
    items.forEach((item, index) => {
      const range = 260;
      const offset = Math.round((rand(index) * 2 - 1) * range);
      item.style.setProperty('--ds-base', `${offset}px`);
    });
  };

  let ticking = false;
  const applyDrift = () => {
    ticking = false;
    if (!desktopQuery.matches || prefersReduced) {
      return;
    }
    const scrollY = window.scrollY || window.pageYOffset || 0;
    items.forEach((item, index) => {
      const amplitude = 30 + (index % 4) * 18;
      const drift = Math.sin((scrollY + index * 140) / 520) * amplitude;
      const base = parseFloat(item.style.getPropertyValue('--ds-base')) || 0;
      item.style.setProperty('--ds-x', `${base + drift}px`);
    });
  };

  const requestDrift = () => {
    if (ticking) {
      return;
    }
    ticking = true;
    window.requestAnimationFrame(applyDrift);
  };

  setBaseOffsets();
  applyDrift();
  window.addEventListener('scroll', requestDrift, { passive: true });
  window.addEventListener('resize', () => {
    setBaseOffsets();
    applyDrift();
  });
})();
