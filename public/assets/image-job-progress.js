/* Shared across admin pages, including SPA navigation. Progress is server-reported. */
(() => {
  const panel = document.getElementById('image-job-progress');
  if (!panel || window.cimaiseImageJobProgressLoaded) return;
  window.cimaiseImageJobProgressLoaded = true;
  const text = panel.querySelector('[data-job-text]');
  const bar = panel.querySelector('progress');
  const t = (key, fallback, params = {}) => window.adminTf(key, params, fallback);
  let tracked = {};
  let finishedAt = 0;
  let timer;
  let busy = false;
  let wakeRequested = false;
  let fastUntil = 0;
  try { tracked = JSON.parse(sessionStorage.getItem('cimaise-image-jobs') || '{}'); } catch (_) {}
  async function poll() {
    let delay = 30000;
    busy = true;
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 15000);
    try {
      if (document.hidden || !navigator.onLine) return;
      const response = await fetch(`${window.basePath || ''}/admin/api/image-jobs`, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal, headers: { Accept: 'application/json' } });
      if (response.status === 401 || response.redirected) { panel.hidden = true; delay = 30000; return; }
      if (!response.ok) throw new Error('Status unavailable');
      const { jobs } = await response.json();
      window.dispatchEvent(new CustomEvent('cimaise:image-jobs', { detail: jobs }));
      const active = jobs.filter(job => job.pending && job.state !== 'complete');
      if (active.length || Date.now() < fastUntil) delay = 2000;
      if (finishedAt && active.length) { tracked = {}; finishedAt = 0; }
      for (const job of jobs) {
        if (job.pending || tracked[job.id]) tracked[job.id] = job;
      }
      const present = new Set(jobs.map(job => String(job.id)));
      for (const [id, job] of Object.entries(tracked)) {
        if (!present.has(id)) tracked[id] = { ...job, pending: false, state: 'complete', completed: job.total };
      }
      const batch = Object.values(tracked);
      if (!batch.length) { panel.hidden = true; return; }
      const total = batch.reduce((sum, job) => sum + (job.total || 0), 0);
      const completed = batch.reduce((sum, job) => sum + (job.completed || 0), 0);
      const waiting = active.filter(job => job.state === 'retrying').length;
      const current = active.find(job => job.state === 'processing');
      panel.hidden = false;
      if (active.some(job => !job.total) || !total) bar.removeAttribute('value');
      else { bar.max = total; bar.value = completed; }
      if (!active.length) {
        delay = 2000; // Keep the completion notice visible for its existing grace period.
        text.textContent = t('admin.image_jobs.complete', 'Image versions ready');
        if (!finishedAt) finishedAt = Date.now();
        if (Date.now() - finishedAt > 8000) { tracked = {}; panel.hidden = true; finishedAt = 0; delay = 30000; }
      } else {
        let message = t('admin.image_jobs.progress', 'Creating image versions: {completed}/{total} steps · {pending} images remaining', { completed, total: total || '…', pending: active.length });
        if (current?.current) message += ` · ${current.current === 'placeholder' ? t('admin.image_jobs.placeholder', 'preview') : current.current}`;
        if (waiting) message += ` · ${t('admin.image_jobs.retrying', '{count} waiting to retry automatically', { count: waiting })}`;
        text.textContent = message;
      }
      try { sessionStorage.setItem('cimaise-image-jobs', JSON.stringify(tracked)); } catch (_) {}
    } catch (_) {
      delay = 5000;
      if (!panel.hidden) text.textContent = t('admin.image_jobs.unavailable', 'Reconnecting to image generation status…');
    } finally {
      window.clearTimeout(timeout);
      busy = false;
      timer = window.setTimeout(poll, wakeRequested ? 0 : delay);
      wakeRequested = false;
    }
  }
  function wake() {
    if (busy) { wakeRequested = true; return; }
    window.clearTimeout(timer);
    poll();
  }
  window.addEventListener('cimaise:upload-activity', event => {
    fastUntil = event.detail?.uploading ? Date.now() + 120000 : 0;
    wake();
  });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) wake(); });
  window.addEventListener('online', wake);
  poll();
})();
