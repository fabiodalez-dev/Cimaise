(function() {
  const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const frames = document.querySelectorAll('.ss-frame[data-reveal]');
  if (!frames.length) {
    return;
  }

  if (prefersReduced) {
    frames.forEach((frame) => frame.classList.add('is-visible'));
    return;
  }

  const observer = new IntersectionObserver((entries, obs) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        const index = Array.prototype.indexOf.call(frames, entry.target);
        entry.target.style.transitionDelay = `${index * 35}ms`;
        entry.target.classList.add('is-visible');
        obs.unobserve(entry.target);
      }
    });
  }, { threshold: 0.2 });

  frames.forEach((frame) => observer.observe(frame));
})();
