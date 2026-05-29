export function createFetchPriorityObserver(maxHigh = 3) {
  if (!('IntersectionObserver' in window)) return null;
  let highCount = 0;

  const observer = new IntersectionObserver((entries, obs) => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      const el = entry.target;
      if (highCount < maxHigh) {
        el.setAttribute('fetchpriority', 'high');
        el.removeAttribute('loading');
        highCount += 1;
      }
      obs.unobserve(el);
    });
    // Only promote images actually entering the viewport (no 200px pre-margin),
    // so below-the-fold images don't steal priority/bandwidth from the LCP image.
  }, { rootMargin: '0px', threshold: 0.1 });

  return observer;
}
