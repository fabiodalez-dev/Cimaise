export function createFetchPriorityObserver(maxHigh = 3) {
  if (!('IntersectionObserver' in window)) return null;
  let highCount = 0;

  const observer = new IntersectionObserver((entries, obs) => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      const img = entry.target;
      if (highCount < maxHigh) {
        img.setAttribute('fetchpriority', 'high');
        img.removeAttribute('loading');
        highCount += 1;
      }
      obs.unobserve(img);
    });
  }, { rootMargin: '200px 0px 200px 0px', threshold: 0.1 });

  return observer;
}
