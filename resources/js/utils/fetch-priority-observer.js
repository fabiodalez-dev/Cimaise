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
  }, { rootMargin: '200px 0px 200px 0px', threshold: 0.1 });

  return observer;
}
