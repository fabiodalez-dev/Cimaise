/* Shared reload pill, with lightweight checks only while a photo page is visible
   and the server reported queued image jobs when it rendered the page. */
(() => {
  const config = window.cimaiseImageNotice;
  if (!config) return;
  window.cimaiseShowUpdate = (message, reload, kind = 'images') => {
    const existing = document.getElementById('pwa-update-banner');
    if (existing && kind === 'images') return;
    existing?.remove();
    const banner = document.createElement('div');
    banner.id = 'pwa-update-banner';
    banner.dataset.kind = kind;
    banner.className = 'fixed bottom-4 left-1/2 z-[9999] flex max-w-[calc(100vw-2rem)] -translate-x-1/2 items-center gap-3 rounded-full bg-black/90 px-4 py-2 text-xs text-white shadow-lg';
    banner.setAttribute('role', 'status');
    banner.setAttribute('aria-live', 'polite');
    const text = document.createElement('span');
    text.textContent = message;
    banner.appendChild(text);
    for (const [label, action] of [[config.reload, reload], [config.dismiss, () => banner.remove()]]) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'shrink-0 rounded-full bg-white/10 px-3 py-1 text-xs hover:bg-white/20';
      button.textContent = label;
      button.addEventListener('click', action);
      banner.appendChild(button);
    }
    document.body.appendChild(banner);
  };

  const ranks = { sm: 1, md: 2, lg: 3, xl: 4, xxl: 5 };
  function photoSizes(root) {
    const sizes = new Map();
    root.querySelectorAll('img, source, a[data-image-id]').forEach(element => {
      for (const name of ['src', 'srcset', 'data-src', 'data-srcset', 'href']) {
        const value = element.getAttribute(name) || '';
        for (const candidate of value.split(',')) {
          const url = candidate.trim().split(/\s+/)[0];
          if (!url) continue;
          try {
            const path = new URL(url, location.href).pathname;
            const match = path.match(/\/media\/(?:protected\/)?(\d+)_(sm|md|lg|xl|xxl)\.(?:jpg|jpeg|webp|avif|jxl)$/);
            if (match) sizes.set(match[1], Math.max(sizes.get(match[1]) || 0, ranks[match[2]]));
          } catch (_) {}
        }
      }
    });
    return sizes;
  }
  // Nothing is being generated: there is nothing to watch for.
  if (!config.pending) return;
  // Bounded: a visitor's open tab must not poll the server indefinitely.
  const WATCH_FOR_MS = 10 * 60 * 1000;
  const startedAt = Date.now();
  let revision = config.revision;
  let busy = false;
  let timer = 0;
  function stop() {
    clearInterval(timer);
    document.removeEventListener('visibilitychange', onVisible);
  }
  function onVisible() { if (!document.hidden) check(); }
  async function check() {
    if (Date.now() - startedAt > WATCH_FOR_MS) { stop(); return; }
    if (busy || document.hidden || !navigator.onLine) return;
    const shown = photoSizes(document.getElementById('main-content') || document);
    if (!shown.size) return;
    busy = true;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    try {
      const status = await fetch(config.endpoint, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
      if (!status.ok) return;
      const next = (await status.json()).revision;
      if (typeof next !== 'string' || next === revision) return;
      const response = await fetch(location.href, { credentials: 'same-origin', cache: 'no-cache', signal: controller.signal });
      if (!response.ok || response.redirected || !response.headers.get('Content-Type')?.includes('text/html')) return;
      const fresh = new DOMParser().parseFromString(await response.text(), 'text/html');
      const available = photoSizes(fresh.getElementById('main-content') || fresh);
      revision = next;
      if ([...shown].some(([id, size]) => (available.get(id) || 0) > size)) {
        window.cimaiseShowUpdate(config.message, () => location.reload());
        stop();
      }
    } catch (_) {
      // Offline/timeouts are retried on the next visible check without interrupting viewing.
    } finally {
      clearTimeout(timeout);
      busy = false;
    }
  }
  timer = setInterval(check, 30000);
  document.addEventListener('visibilitychange', onVisible);
  check();
})();
