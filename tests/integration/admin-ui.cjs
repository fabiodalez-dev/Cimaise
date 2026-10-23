const { chromium } = require('playwright');
const assert = require('node:assert/strict');

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ baseURL: 'http://localhost:8080', viewport: { width: 1440, height: 1000 } });
  const login = await context.request.get('/admin/login');
  const response = await context.request.post('/admin/login', { form: { email: 'test@example.test', password: 'Test-pass-12345', csrf: login.headers()['x-csrf-token'] }, maxRedirects: 0 });
  assert.equal(response.status(), 302);
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  async function saveForm(id) {
    const form = await page.locator(id).evaluate(element => ({ action: element.action, values: Object.fromEntries(new FormData(element)) }));
    const saved = await context.request.post(form.action, { form: form.values, maxRedirects: 0 });
    assert.equal(saved.status(), 302, await saved.text());
  }
  const firstPaint = await context.newPage();
  let releaseNavigationScript;
  const navigationGate = new Promise(resolve => { releaseNavigationScript = resolve; });
  await firstPaint.route('**/assets/js/admin-navigation.js*', async route => {
    await navigationGate;
    await route.continue();
  });
  const firstPaintLoad = firstPaint.goto('/admin/albums');
  try {
    await firstPaint.locator('#admin-primary-navigation a').first().waitFor();
    assert.equal(await firstPaint.locator('#admin-primary-navigation a:visible').count(), 8);
    assert.equal(await firstPaint.locator('#admin-navigation-source').isVisible(), false);
  } finally {
    releaseNavigationScript();
    await firstPaintLoad;
    await firstPaint.close();
  }
  console.log('PASS: compact sidebar is visible before navigation JavaScript loads, without flashing the legacy menu');
  await page.goto('/admin/analytics-pro');
  assert.equal(await page.locator('#admin-section-tabs a[href$="/admin/analytics-pro"]').count(), 1);
  assert.equal(await page.locator('#admin-primary-navigation a').count(), 8);
  assert.equal(await page.locator('#admin-primary-navigation .active').innerText(), 'Analytics');
  assert.match(await page.locator('#page-content').textContent(), /Real-time statistics from collected events/);
  assert.match(await page.locator('#page-content').textContent(), /Active users \(5 min\)/);
  assert.doesNotMatch(await page.locator('#page-content').textContent(), /Statistiche in tempo|Utenti attivi/);
  await page.reload();
  assert.equal(await page.locator('#admin-section-tabs a[href$="/admin/analytics-pro"]').count(), 1);
  assert.equal(await page.locator('#admin-section-tabs [aria-current="page"]').innerText(), 'Analytics Pro');
  console.log('PASS: Analytics Pro renders English labels and exactly one tab on initial load and reload');
  async function waitForAdminPage() {
    await page.waitForFunction(() => document.getElementById('loading-indicator').classList.contains('hidden') && getComputedStyle(document.getElementById('page-content')).opacity === '1');
  }
  await page.goto('/admin/albums');
  let categoryDocumentLoads = 0;
  const countCategoryDocuments = request => {
    if (request.isNavigationRequest() && request.frame() === page.mainFrame()) categoryDocumentLoads++;
  };
  page.on('request', countCategoryDocuments);
  for (let visit = 0; visit < 3; visit++) {
    await page.locator('#admin-section-tabs a[href$="/admin/categories"]').click();
    await waitForAdminPage();
    await page.waitForFunction(() => document.getElementById('categories-container')?.dataset.categoriesReady === 'true');
    assert.equal(await page.evaluate(() => !!window.Sortable.get(document.getElementById('root-list'))), true);
    assert.equal(await page.locator('#admin-navigation-source').isVisible(), false);
    assert.equal(await page.locator('#admin-primary-navigation a:visible').count(), 8);
    await page.locator('#admin-section-tabs a[href$="/admin/albums"]').click();
    await waitForAdminPage();
  }
  page.off('request', countCategoryDocuments);
  assert.equal(categoryDocumentLoads, 0, 'Categories must initialize without full-page fallback reloads');
  console.log('PASS: repeated Album/Category transitions preserve the short menu and live drag-and-drop without document reloads');
  await page.locator('#admin-primary-navigation [data-nav-group="metadata"]').click();
  await waitForAdminPage();
  assert.match(page.url(), /\/admin\/cameras$/);
  assert.equal(await page.locator('#admin-section-title').innerText(), 'Metadata');
  assert.equal(await page.locator('#admin-section-tabs a').count(), 7);
  await page.goto('/admin/cameras?q=navigation-fixture');
  await page.locator('#admin-section-tabs a[href$="/admin/lenses"]').click();
  await waitForAdminPage();
  assert.equal(await page.locator('#admin-section-tabs [aria-current="page"]').innerText(), 'Lenses');
  assert.equal(await page.locator('#admin-section-tabs [aria-current="page"]').evaluate(link => link === document.activeElement), true);
  await page.goBack();
  await waitForAdminPage();
  assert.match(page.url(), /\/admin\/cameras\?q=navigation-fixture$/);
  assert.equal(await page.locator('#admin-section-tabs [aria-current="page"]').innerText(), 'Cameras');
  await page.reload();
  assert.equal(await page.locator('#admin-primary-navigation .active').innerText(), 'Metadata');
  await page.setViewportSize({ width: 390, height: 844 });
  await page.locator('#sidebar-toggle').click();
  await page.locator('#admin-primary-navigation [data-nav-group="system"]').click();
  await waitForAdminPage();
  assert.equal(await page.locator('#sidebar-overlay').isVisible(), false);
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
  await page.locator('#admin-section-tabs a[href$="/admin/diagnostics"]').click();
  await waitForAdminPage();
  assert.match(page.url(), /\/admin\/diagnostics$/);
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.locator('#admin-primary-navigation [data-nav-group="appearance"]').click();
  await waitForAdminPage();
  assert.equal(await page.locator('#admin-section-tabs a[href$="/admin/custom-templates"]').count(), 1);
  let logoDocumentLoads = 0;
  const countLogoDocumentLoads = request => {
    if (request.isNavigationRequest() && request.frame() === page.mainFrame()) logoDocumentLoads++;
  };
  page.on('request', countLogoDocumentLoads);
  await page.locator('#admin-section-tabs a[href$="/admin/settings#site-branding"]').click();
  await page.waitForURL('**/admin/settings#site-branding');
  await waitForAdminPage();
  await page.waitForFunction(() => Math.abs(document.getElementById('site-branding').getBoundingClientRect().top - parseFloat(getComputedStyle(document.getElementById('site-branding')).scrollMarginTop)) < 2);
  assert.equal(logoDocumentLoads, 0, 'The logo shortcut must use SPA navigation');
  await page.locator('#admin-primary-navigation [data-nav-group="appearance"].active').waitFor();
  assert.equal(await page.locator('#admin-section-tabs [aria-current="page"]').innerText(), 'Site Logo');
  assert.equal(await page.locator('#site-branding [name="logo_type"]').count(), 2);
  const savedSiteTitle = await page.locator('[name="site_title"]').inputValue();
  await page.locator('[name="site_title"]').fill('Unsaved logo navigation fixture');
  await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
  await page.locator('#admin-section-tabs a[href$="/admin/settings#site-branding"]').click();
  await page.waitForFunction(() => Math.abs(document.getElementById('site-branding').getBoundingClientRect().top - parseFloat(getComputedStyle(document.getElementById('site-branding')).scrollMarginTop)) < 2);
  assert.equal(await page.locator('[name="site_title"]').inputValue(), 'Unsaved logo navigation fixture');
  assert.equal(logoDocumentLoads, 0);
  await page.locator('[name="site_title"]').fill(savedSiteTitle);
  page.off('request', countLogoDocumentLoads);
  console.log('PASS: grouped tools support SPA tabs, browser Back, reload and mobile navigation');
  await page.goto('/admin/albums/1/edit');
  assert.equal(await page.locator('#admin-primary-navigation .active').innerText(), 'Albums');
  assert.equal(await page.locator('#admin-section-tabs [aria-current="page"]').innerText(), 'Albums');
  await page.locator('#show_equipment').uncheck();
  await saveForm('#album-form');
  await page.reload();
  assert.equal(await page.locator('#show_equipment').isChecked(), false);
  await page.locator('#show_equipment').check();
  await saveForm('#album-form');
  await page.reload();
  assert.equal(await page.locator('#show_equipment').isChecked(), true);
  console.log('PASS: equipment visibility can be saved, disabled and re-enabled');

  await page.goto('/admin/pages/home');
  await page.locator('[name=hero_enabled]').uncheck();
  await saveForm('#home-form');
  await page.reload();
  assert.equal(await page.locator('[name=hero_enabled]').isChecked(), false);
  await page.locator('[name=hero_enabled]').check();
  await saveForm('#home-form');
  console.log('PASS: Classic hero visibility persists and can be restored');

  await page.goto('/admin/media');
  const card = () => page.locator('[data-media-id]:not([data-media-id="999"])').first();
  await card().click();
  await page.locator('#media-sidebar').waitFor({ state: 'visible' });
  await page.waitForFunction(() => document.getElementById('image-versions-status').textContent.includes('9/9'));
  const links = page.locator('#image-versions-list a[href*="/variants/"]');
  assert.equal(await links.count(), 9);
  assert.equal(await page.locator('#original-image-title').textContent(), 'Original Image');
  assert.equal(await page.locator('#original-image-details a').count(), 1);
  assert.equal(await page.locator('#image-versions-list a[href$="/original"]').count(), 0);
  const firstId = await page.locator('#sidebar-image-id').inputValue();
  await page.keyboard.press('ArrowRight');
  assert.notEqual(await page.locator('#sidebar-image-id').inputValue(), firstId);
  await page.keyboard.press('ArrowLeft');
  assert.equal(await page.locator('#sidebar-image-id').inputValue(), firstId);
  await page.locator('#sidebar-alt').focus();
  await page.keyboard.press('ArrowRight');
  assert.equal(await page.locator('#sidebar-image-id').inputValue(), firstId);
  await page.waitForFunction(() => document.getElementById('image-versions-status').textContent.includes('9/9'));
  const image = await context.request.get(await page.locator('#image-versions-list a[href$=".jpg"]').first().getAttribute('href'));
  assert.equal(image.status(), 200);
  assert.match(image.headers()['content-type'], /^image\//);
  const webpLogo = await context.request.get(await page.locator('#image-versions-list a[href$=".webp"]').first().getAttribute('href'));
  assert.equal(webpLogo.status(), 200);
  await page.locator('#media-sidebar-close').click();
  assert.equal(await page.locator('#media-sidebar').isVisible(), false);
  // Re-enter through actual SPA links: old window.openSidebar closures caused this failure.
  await page.locator('#admin-primary-navigation a[href$="/admin/albums"]').click();
  await page.waitForURL('**/admin/albums');
  await page.waitForFunction(() => document.getElementById('loading-indicator').classList.contains('hidden') && getComputedStyle(document.getElementById('page-content')).opacity === '1');
  const albumStorage = page.locator('[data-album-storage="1"]');
  assert.match(await albumStorage.textContent(), /Originals:.*Versions:/s);
  assert.notEqual((await albumStorage.locator('div').first().textContent()).trim(), '0 B');
  console.log('PASS: Albums table displays storage totals with original and generated-version breakdowns');
  await page.locator('#admin-primary-navigation a[href$="/admin/media"]').click();
  await page.waitForURL('**/admin/media');
  await page.waitForFunction(() => document.getElementById('loading-indicator').classList.contains('hidden') && getComputedStyle(document.getElementById('page-content')).opacity === '1');
  await card().waitFor();
  await card().focus();
  await page.keyboard.press('Enter');
  await page.locator('#media-sidebar').waitFor({ state: 'visible' });
  await page.waitForFunction(() => document.getElementById('image-versions-status').textContent.includes('9/9'));
  await page.locator('#image-job-progress').waitFor({ state: 'visible' });
  await page.waitForFunction(() => document.querySelector('[data-job-text]').textContent.includes('retry automatically'));
  assert.equal(await page.locator('#admin-navigation-source').getByText('Custom layouts', { exact: true }).count(), 1);
  assert.equal(await page.locator('#admin-navigation-source').getByText('Advanced statistics', { exact: true }).count(), 1);
  await page.waitForFunction(() => getComputedStyle(document.getElementById('page-content')).opacity === '1');
  await page.screenshot({ path: '/tmp/cimaise-admin-gallery.png', fullPage: true });
  assert.deepEqual(errors, []);
  console.log('PASS: gallery click/keyboard, SPA re-entry, variant views and retry progress');

  await page.goto('/admin/settings');
  const csrf = await page.locator('#settings-form input[name="csrf"]').inputValue();
  const uploadedLogo = await context.request.post('/admin/settings/logo-upload', {
    headers: { 'X-CSRF-Token': csrf },
    multipart: { file: { name: 'logo.webp', mimeType: 'image/webp', buffer: await webpLogo.body() } }
  });
  assert.equal(uploadedLogo.status(), 200, await uploadedLogo.text());
  await page.reload();
  // The upload fixture uses tiny breakpoints for speed; settings require >=100.
  for (const [index, size] of ['sm', 'md', 'lg', 'xl', 'xxl'].entries()) {
    await page.locator(`[name="bp_${size}"]`).fill(String((index + 1) * 100));
  }
  const visitor = await browser.newContext({ baseURL: 'http://localhost:8080' });
  const maintenance = await visitor.newPage();
  assert.equal((await maintenance.goto('/')).status(), 200);
  await maintenance.waitForFunction(() => !!navigator.serviceWorker.controller);
  assert.equal((await maintenance.goto('/')).status(), 200);
  await page.locator('label').filter({ has: page.locator('[name="maintenance_enabled"]') }).click();
  assert.equal(await page.locator('[name="maintenance_enabled"]').isChecked(), true);
  await page.locator('[name="maintenance_show_logo"]').check();
  await page.locator('[name="maintenance_show_admin_login"]').check();
  await saveForm('#settings-form');
  await page.reload();
  assert.equal(await page.locator('[name="maintenance_enabled"]').isChecked(), true);
  const unavailable = await maintenance.goto('/');
  assert.equal(unavailable.status(), 503);
  assert.equal(unavailable.headers()['retry-after'], '3600');
  assert.match(unavailable.headers()['cache-control'], /no-store/);
  assert.equal(await maintenance.locator('.login-link').count(), 1);
  assert.equal(await maintenance.evaluate(async () => {
    const keys = await caches.keys();
    const counts = await Promise.all(keys.filter(key => key.endsWith('-pages')).map(async key => (await (await caches.open(key)).keys()).length));
    return counts.reduce((sum, count) => sum + count, 0);
  }), 0);
  const logo = maintenance.locator('.logo img');
  assert.match(await logo.getAttribute('src'), /^\/media\/logo-/);
  await logo.evaluate(element => element.decode());
  assert.equal((await visitor.request.get(await logo.getAttribute('src'))).status(), 200);
  await page.goto('/admin/settings');
  assert.equal(await page.locator('[name="maintenance_show_logo"]').isChecked(), true);
  await page.locator('[name="maintenance_show_logo"]').uncheck();
  await page.locator('[name="maintenance_show_admin_login"]').uncheck();
  await saveForm('#settings-form');
  assert.equal((await maintenance.reload()).status(), 503);
  assert.equal(await maintenance.locator('.logo img').count(), 0);
  assert.equal(await maintenance.locator('.login-link').count(), 0);
  assert.equal((await visitor.request.get('/admin/login')).status(), 200);
  await page.goto('/admin/settings');
  await page.locator('[name="maintenance_show_admin_login"]').check();
  await saveForm('#settings-form');
  assert.equal((await maintenance.reload()).status(), 503);
  assert.equal(await maintenance.locator('.login-link').count(), 1);
  await page.goto('/admin/settings');
  await page.locator('label').filter({ has: page.locator('[name="maintenance_enabled"]') }).click();
  assert.equal(await page.locator('[name="maintenance_enabled"]').isChecked(), false);
  await saveForm('#settings-form');
  assert.equal((await maintenance.reload()).status(), 200);
  await visitor.close();
  console.log('PASS: maintenance settings save, visitor 503 and logo on/off with real upload');
  console.log('PASS: maintenance replaces warmed pages, clears offline HTML and supports admin login visibility');

  await page.goto('/admin/albums/1/edit');
  await page.locator('[name="is_published"]').check();
  await page.locator('[name="allow_downloads"]').check();
  await saveForm('#album-form');
  await page.reload();
  // JPEG metadata is deliberately large: the former client compressor stripped it.
  const comment = Buffer.alloc(8192, 65);
  const originalBytes = Buffer.concat([(await image.body()).subarray(0, 2), Buffer.from([255, 254, 32, 2]), comment, (await image.body()).subarray(2)]);
  let releaseUpload;
  let processingId;
  let showProcessing = true;
  const uploadGate = new Promise(resolve => { releaseUpload = resolve; });
  await page.route('**/admin/albums/1/upload', async route => {
    const response = await route.fetch();
    processingId = (await response.json()).id;
    await uploadGate;
    await route.fulfill({ response });
  });
  await page.route('**/admin/api/image-jobs', async route => {
    const response = await route.fetch();
    const body = await response.json();
    if (showProcessing && processingId) {
      const job = body.jobs.find(job => job.id === processingId);
      if (job) Object.assign(job, { pending: true, state: 'processing', completed: 4, total: 10, current: 'md.webp' });
    }
    await route.fulfill({ response, json: body });
  });
  const uploadResponse = page.waitForResponse(response => response.url().endsWith('/admin/albums/1/upload') && response.request().method() === 'POST');
  await page.locator('#uppy input[type="file"]').setInputFiles({ name: 'preserved-original.jpg', mimeType: 'image/jpeg', buffer: originalBytes });
  await page.waitForFunction(() => document.getElementById('upload-status').textContent.includes('Creating versions: 4/10'));
  assert.equal(await page.locator('#upload-progress').isVisible(), true);
  assert.equal(await page.locator('#upload-counter').textContent(), '0 / 1');
  assert.match(await page.locator('#upload-file-list').textContent(), /md.webp/);
  showProcessing = false;
  releaseUpload();
  const uploadData = await (await uploadResponse).json();
  await page.waitForFunction(() => document.getElementById('upload-counter').textContent === '1 / 1');
  await page.unroute('**/admin/albums/1/upload');
  await page.unroute('**/admin/api/image-jobs');
  console.log('PASS: upload panel tracks correlated generation before HTTP upload completion and waits for versions');
  assert.equal(uploadData.ok, true, JSON.stringify(uploadData));
  const originalDownload = await context.request.get(`/admin/media/images/${uploadData.id}/original`);
  assert.equal(originalDownload.status(), 200);
  assert.match(originalDownload.headers()['content-disposition'], /^attachment;/);
  assert.deepEqual(await originalDownload.body(), originalBytes);
  const anonymous = await browser.newContext({ baseURL: 'http://localhost:8080' });
  const publicDownload = await anonymous.request.get(`/download/image/${uploadData.id}`);
  assert.equal(publicDownload.status(), 200);
  assert.deepEqual(await publicDownload.body(), originalBytes);
  const details = await (await context.request.get(`/admin/media/images/${uploadData.id}/variants`)).json();
  assert.equal(details.original.bytes, originalBytes.length);
  const denied = await anonymous.request.get(`/admin/media/images/${uploadData.id}/original`, { maxRedirects: 0 });
  assert.equal(denied.status(), 302);
  console.log('PASS: real browser upload preserves bytes; public/admin original downloads match and report size');

  const publicPage = await anonymous.newPage();
  publicPage.on('pageerror', error => errors.push(error.message));
  await publicPage.goto('/album/test');
  await publicPage.waitForFunction(() => !!window.__pswpLightbox);
  await publicPage.locator(`.pswp-gallery a[data-image-id="${uploadData.id}"]`).first().click();
  const downloadButton = publicPage.locator('.pswp__button--download-button');
  await downloadButton.waitFor({ state: 'visible' });
  await publicPage.route('**/download/image/*', route => route.fulfill({ status: 403, contentType: 'text/html', body: 'No permission' }));
  const deniedDialog = publicPage.waitForEvent('dialog').then(async dialog => {
    assert.match(dialog.message(), /Unable to download the original/);
    await dialog.accept();
  });
  await downloadButton.click();
  await deniedDialog;
  await publicPage.unroute('**/download/image/*');
  const downloadEvent = publicPage.waitForEvent('download');
  await downloadButton.click();
  const browserDownload = await downloadEvent;
  assert.deepEqual(await require('node:fs/promises').readFile(await browserDownload.path()), originalBytes);
  await publicPage.close();
  console.log('PASS: incognito lightbox downloads the original and displays denied responses instead of saving HTML');

  const revisionResponse = await anonymous.request.get('/api/image-generation-revision');
  assert.equal(revisionResponse.status(), 200);
  assert.equal(revisionResponse.headers()['cache-control'], 'no-store');
  assert.deepEqual(Object.keys(await revisionResponse.json()), ['revision']);
  // This fixture controls the initially available variants. A service worker
  // can bypass Playwright routes, so isolate it from the other SW/cache tests.
  const enhancementContext = await browser.newContext({ baseURL: 'http://localhost:8080', serviceWorkers: 'block' });
  const enhancedPage = await enhancementContext.newPage();
  const enhancementDiagnostics = [];
  enhancedPage.on('pageerror', error => enhancementDiagnostics.push(error.message));
  enhancedPage.on('requestfailed', request => enhancementDiagnostics.push({ failed: request.url(), error: request.failure() }));
  enhancedPage.on('response', response => {
    if (/image-version-notice|image-generation-revision|\/album\/test$/.test(response.url())) {
      enhancementDiagnostics.push({ url: response.url(), status: response.status(), type: response.headers()['content-type'], resource: response.request().resourceType() });
    }
  });
  let firstPhotoDocument = true;
  let testImageRevision = 'f'.repeat(32);
  await enhancedPage.route('**/album/test', async route => {
    const response = await route.fetch();
    let body = await response.text();
    if (route.request().resourceType() === 'document' && firstPhotoDocument) {
      firstPhotoDocument = false;
      body = body.replace(new RegExp(`${uploadData.id}_(md|lg|xl|xxl)(?=\\.)`, 'g'), `${uploadData.id}_sm`);
    }
    await route.fulfill({ response, body });
  });
  await enhancedPage.route('**/api/image-generation-revision', route => route.fulfill({ json: { revision: testImageRevision } }));
  await enhancedPage.goto('/album/test');
  const enhancedPill = enhancedPage.locator('#pwa-update-banner[data-kind="images"]');
  try {
    await enhancedPill.waitFor({ state: 'visible' });
  } catch (error) {
    console.log('Enhancement notice diagnostics:', enhancementDiagnostics, await enhancedPage.evaluate(() => ({
      hidden: document.hidden, online: navigator.onLine, config: window.cimaiseImageNotice, helper: typeof window.cimaiseShowUpdate,
      photos: [...document.querySelectorAll('#main-content img, #main-content source, #main-content a[data-image-id]')].slice(0, 10).map(el => ({ src: el.getAttribute('src'), srcset: el.getAttribute('srcset'), href: el.getAttribute('href') }))
    })));
    throw error;
  }
  assert.match(await enhancedPill.innerText(), /Enhanced image versions are ready/);
  await enhancedPill.getByRole('button', { name: 'Dismiss', exact: true }).click();
  assert.equal(await enhancedPill.count(), 0);
  firstPhotoDocument = true;
  testImageRevision = 'e'.repeat(32);
  await enhancedPage.reload();
  await enhancedPill.waitFor({ state: 'visible' });
  const enhancedReload = enhancedPage.waitForNavigation();
  await enhancedPill.getByRole('button', { name: 'Reload', exact: true }).click();
  await enhancedReload;
  assert.equal(await enhancedPage.locator(`a[data-image-id="${uploadData.id}"]`).first().getAttribute('href').then(url => /_(md|lg|xl|xxl)\./.test(url)), true);
  await enhancedPage.unrouteAll({ behavior: 'wait' });
  assert.equal(await enhancedPage.locator('#pwa-update-banner[data-kind="images"]').count(), 0);
  await enhancedPage.close();
  await enhancementContext.close();
  console.log('PASS: enhanced-image reload pill appears for a photo gaining larger variants and reloads into the higher-resolution page; revision API exposes no job details');

  await page.goto('/admin/albums/1/edit');
  await page.locator('[name="allow_downloads"]').uncheck();
  await page.locator('#album-categories').selectOption([]);
  await saveForm('#album-form');
  await page.reload();
  assert.equal(await page.locator('#album-categories option:checked').textContent(), 'None');
  assert.equal(await page.locator('#album-categories option').filter({ hasText: /^None$/ }).count(), 1);
  assert.equal((await anonymous.request.get(`/download/image/${uploadData.id}`)).status(), 403);
  assert.equal((await context.request.get(`/admin/media/images/${uploadData.id}/original`)).status(), 200);
  console.log('PASS: albums without a selected category use the None fallback');
  await page.goto('/admin/media?album=1&format=jpeg&generation=generated&q=preserved');
  assert.equal(await page.locator('#media-filters [name="album"]:checked').inputValue(), '1');
  assert.equal(await page.locator('#media-filters [name="format"]:checked').inputValue(), 'jpeg');
  assert.equal(await page.locator('#media-filter-panel').isVisible(), true);
  await page.locator('#media-filter-toggle').click();
  assert.equal(await page.locator('#media-filter-panel').isVisible(), false);
  await page.locator('#media-filter-toggle').click();
  await page.locator('#media-album-search').fill('no-matching-album');
  assert.equal(await page.locator('[data-album-title]:visible').count(), 0);
  await page.locator('#media-album-search').fill('');
  assert.ok(await page.locator('[data-album-title]:visible').count() > 0);

  assert.equal(await page.locator('[data-media-id]').count(), 0); // Search remains combined with every filter.
  await page.goto('/admin/media?album=1&format=jpeg&generation=generated');
  assert.ok(await page.locator('[data-media-id]').count() > 0);
  await page.goto('/admin/media?format=png');
  assert.equal(await page.locator('[data-media-id]').count(), 0);
  await page.goto('/admin/media?generation=retrying');
  assert.equal(await page.locator('[data-media-id="999"]').count(), 1);
  console.log('PASS: album, format, search and live retry filters combine correctly');

  await page.goto('/admin/pages/home');
  await page.locator('[name="hero_enabled"]').check();
  await page.locator('[name="hero_title"]').fill('Only this title');
  await page.locator('[name="hero_subtitle"]').fill('Only this text');
  await page.locator('[name="gallery_per_album"]').fill('2');
  await page.locator('[name="hero_show_title"]').check();
  await page.locator('[name="hero_show_text"]').uncheck();
  await saveForm('#home-form');
  let home = await anonymous.request.get('/');
  assert.match(await home.text(), /Only this title/);
  assert.doesNotMatch(await home.text(), /Only this text/);
  const oldEtag = home.headers().etag;
  assert.match(home.headers()['cache-control'], /no-cache/);
  const homeBrowser = await anonymous.newPage();
  await homeBrowser.goto('/');
  await homeBrowser.waitForFunction(() => !!navigator.serviceWorker.controller);
  assert.match(await homeBrowser.locator('body').textContent(), /Only this title/);
  await page.reload();
  assert.equal(await page.locator('[name="gallery_per_album"]').inputValue(), '2');
  await page.locator('[name="hero_show_title"]').uncheck();
  await page.locator('[name="hero_show_text"]').check();
  await saveForm('#home-form');
  home = await anonymous.request.get('/', { headers: oldEtag ? { 'If-None-Match': oldEtag } : {} });
  assert.equal(home.status(), 200);
  assert.doesNotMatch(await home.text(), /Only this title/);
  assert.match(await home.text(), /Only this text/);
  await homeBrowser.goto('/'); // Ordinary navigation through the installed service worker.
  assert.doesNotMatch(await homeBrowser.locator('body').textContent(), /Only this title/);
  assert.match(await homeBrowser.locator('body').textContent(), /Only this text/);
  await homeBrowser.reload();
  assert.match(await homeBrowser.locator('body').textContent(), /Only this text/);
  await homeBrowser.close();
  console.log('PASS: warmed browser/service-worker pages show saved title changes on normal navigation and reload');
  console.log('PASS: title/text controls update the next anonymous request without stale HTML or 304');

  await page.goto('/admin/typography');
  const fontBytes = await (await context.request.get('/fonts/inter/inter-400.woff2')).body();
  const fontCsrf = await page.locator('#font-upload-form input[name="csrf"]').inputValue();
  const fontUpload = await context.request.post('/admin/typography/upload', {
    multipart: { csrf: fontCsrf, font_name: 'Uploaded Test', font_weight: '400', font_type: 'sans',
      font_file: { name: 'test.woff2', mimeType: 'font/woff2', buffer: fontBytes } }, maxRedirects: 0
  });
  assert.equal(fontUpload.status(), 302);
  await page.reload();
  const fontOption = page.locator('select[name="body_font"] option').filter({ hasText: 'Uploaded Test' });
  assert.equal(await fontOption.count(), 1, (await page.locator('#flash-messages').textContent().catch(() => 'Font option missing after upload')) || 'Font option missing after upload');
  await page.locator('select[name="body_font"]').selectOption(await fontOption.getAttribute('value'));
  await saveForm('#typography-form');
  const fontCss = await (await anonymous.request.get('/fonts/typography.css')).text();
  const customFontUrl = fontCss.match(/url\('(\/fonts\/custom\/[^']+)'\)/)[1];
  const customFont = await anonymous.request.get(customFontUrl);
  assert.equal(customFont.status(), 200);
  assert.deepEqual(await customFont.body(), fontBytes);
  const homeWithFont = await (await anonymous.request.get('/')).text();
  assert.match(homeWithFont, /typography\.css\?v=[a-f0-9]{16}/);
  assert.match(homeWithFont, /-webkit-touch-callout: none/);
  const mobile = await browser.newContext({ baseURL: 'http://localhost:8080', isMobile: true, hasTouch: true });
  const mobilePage = await mobile.newPage();
  await mobilePage.goto('/');
  assert.equal(await mobilePage.evaluate(() => {
    const image = document.createElement('img'); document.body.appendChild(image);
    return image.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true }));
  }), false);
  await mobile.close();
  await page.goto('/admin/settings');
  await page.locator('[name="logo_type"][value="image"]').check();
  await page.locator('[name="allow_theme_toggle"]').check();
  const lightLogoPath = await page.locator('#site_logo').inputValue();
  const faviconBefore = await (await context.request.get('/favicon.ico')).body();
  const darkUploadResponse = page.waitForResponse(response => response.url().includes('/admin/settings/logo-upload?theme=dark') && response.request().method() === 'POST');
  await page.locator('#logo-uppy-dark input[type="file"]').setInputFiles({ name: 'dark-logo.jpg', mimeType: 'image/jpeg', buffer: await image.body() });
  const darkUpload = await (await darkUploadResponse).json();
  assert.equal(darkUpload.ok, true);
  await page.waitForFunction(path => document.getElementById('site_logo_dark').value === path, darkUpload.path);
  assert.equal(await page.locator('#site_logo').inputValue(), lightLogoPath);
  assert.deepEqual(await (await context.request.get('/favicon.ico')).body(), faviconBefore);
  await saveForm('#settings-form');
  await page.goto('/admin/pages/about');
  await page.locator('[name="about_menu_label"]').fill('Contact');
  await page.locator('[name="about_title"]').fill('Contact');
  await page.locator('[name="about_slug"]').fill('contact');
  await saveForm('form[action$="/admin/pages/about"]');
  await page.reload();
  assert.equal(await page.locator('[name="about_menu_label"]').inputValue(), 'Contact');
  const themedPage = await anonymous.newPage();
  assert.equal((await themedPage.goto('/contact')).status(), 200);
  assert.equal(await themedPage.locator('h1').innerText(), 'Contact');
  assert.equal(await themedPage.locator('form[action$="/contact/contact"]').count(), 1);
  const contactCsrf = await themedPage.locator('form[action$="/contact/contact"] [name="csrf"]').inputValue();
  const invalidContact = await anonymous.request.post('/contact/contact', {
    form: { csrf: contactCsrf, name: 'Test', email: 'invalid', message: 'Validation only' }, maxRedirects: 0
  });
  assert.equal(invalidContact.status(), 302);
  assert.equal(invalidContact.headers().location, '/contact?error=1');
  assert.equal((await anonymous.request.get('/about')).status(), 200);
  const legacyContact = await anonymous.request.post('/about/contact', { form: { csrf: contactCsrf }, maxRedirects: 0 });
  assert.equal(legacyContact.status(), 302);
  assert.equal(legacyContact.headers().location, '/contact?error=1');
  await page.locator('[name="about_slug"]').fill('reach-me');
  await saveForm('form[action$="/admin/pages/about"]');
  assert.equal((await anonymous.request.get('/reach-me')).status(), 200);
  assert.equal((await anonymous.request.get('/contact')).status(), 404);
  assert.equal((await anonymous.request.get('/about')).status(), 200);
  const renamedContact = await anonymous.request.post('/reach-me/contact', { form: { csrf: contactCsrf }, maxRedirects: 0 });
  assert.equal(renamedContact.status(), 302);
  assert.equal(renamedContact.headers().location, '/reach-me?error=1');
  await page.locator('[name="about_slug"]').fill('contact');
  await saveForm('form[action$="/admin/pages/about"]');
  console.log('PASS: renamed About permalink serves Contact, routes form validation back to Contact and retains legacy About URLs');
  console.log('PASS: changing the permalink again dynamically registers the new page/form URL and removes the previous custom URL');
  for (const template of ['classic', 'modern']) {
    await page.goto('/admin/pages/home');
    await page.locator(`[name="home_template"][value="${template}"]`).check({ force: true });
    await saveForm('#home-form');
    await themedPage.goto('/');
    if (template === 'classic') {
      const categoryToggle = themedPage.locator('#categories-toggle');
      const categoryMenu = themedPage.locator('#categories-mega-menu');
      await categoryToggle.hover();
      await categoryMenu.waitFor({ state: 'visible' });
      const panel = await categoryMenu.boundingBox();
      await themedPage.mouse.move(panel.x + panel.width / 2, panel.y + 20);
      await themedPage.waitForTimeout(350);
      assert.equal(await categoryToggle.getAttribute('aria-expanded'), 'true');
      await themedPage.mouse.move(1430, 980);
      // Return during the closing animation, before its delayed hide finishes.
      await themedPage.waitForTimeout(300);
      await categoryToggle.hover();
      await themedPage.waitForTimeout(350);
      assert.equal(await categoryMenu.isVisible(), true);
      assert.equal(await categoryToggle.getAttribute('aria-expanded'), 'true');
      await themedPage.mouse.move(1430, 980);
      await categoryMenu.waitFor({ state: 'hidden' });
      assert.equal(await categoryToggle.getAttribute('aria-expanded'), 'false');
      console.log('PASS: categories stay open across the panel and cancel delayed hiding on pointer re-entry');
    }
    await themedPage.evaluate(() => { localStorage.setItem('cimaise-theme', 'light'); });
    await themedPage.reload();
    const toggle = themedPage.locator('#theme-toggle, [data-theme-toggle]').filter({ visible: true }).first();
    const lightLogo = themedPage.locator('.cimaise-logo-light').filter({ visible: true }).first();
    await lightLogo.waitFor({ state: 'visible' });
    assert.equal(await lightLogo.getAttribute('src'), lightLogoPath);
    assert.equal(await themedPage.locator('a[href$="/contact"]').filter({ hasText: /^Contact$/ }).count() > 0, true);
    await toggle.click();
    const darkLogo = themedPage.locator('.cimaise-logo-dark').filter({ visible: true }).first();
    await darkLogo.waitFor({ state: 'visible' });
    assert.equal(await darkLogo.getAttribute('src'), darkUpload.path);
    await themedPage.reload();
    await themedPage.locator('.cimaise-logo-dark').filter({ visible: true }).first().waitFor({ state: 'visible' });
    await themedPage.locator('#theme-toggle, [data-theme-toggle]').filter({ visible: true }).first().click();
    await themedPage.locator('.cimaise-logo-light').filter({ visible: true }).first().waitFor({ state: 'visible' });
  }
  await page.goto('/admin/settings');
  await page.locator('#site-logo-clear-dark').click();
  await saveForm('#settings-form');
  await themedPage.reload();
  assert.equal(await themedPage.locator('.cimaise-logo-dark').count(), 0);
  assert.equal(await themedPage.locator('img[src="' + lightLogoPath + '"]').filter({ visible: true }).count() > 0, true);
  await themedPage.close();
  console.log('PASS: dark logo upload preserves standard logo/favicons, follows both themes and falls back after removal; Contact navigation name persists');
  await page.goto('/admin/cache');
  await page.locator('#cache_enabled').check();
  await page.locator('#auto_warm').check();
  await saveForm('form[action$="/admin/cache/settings"]');
  await page.goto('/admin/albums/create');
  await page.locator('[name="title"]').fill('Delete cache fixture');
  await page.locator('[name="slug"]').fill('delete-cache-fixture');
  const createForm = await page.locator('#album-form').evaluate(form => ({ action: form.action, values: Object.fromEntries(new FormData(form)) }));
  const created = await context.request.post(createForm.action, { form: createForm.values, headers: { Accept: 'application/json' }, maxRedirects: 0 });
  assert.equal(created.status(), 200);
  const deleteAlbum = await created.json();
  assert.equal(deleteAlbum.ok, true, JSON.stringify(deleteAlbum));
  const uploaded = await context.request.post(`/admin/albums/${deleteAlbum.id}/upload`, {
    headers: { 'X-CSRF-Token': created.headers()['x-csrf-token'] },
    multipart: { file: { name: 'shared-delete-fixture.jpg', mimeType: 'image/jpeg', buffer: originalBytes } },
  });
  assert.equal((await uploaded.json()).ok, true);
  const listingPage = await anonymous.newPage();
  await listingPage.goto('/galleries');
  assert.ok(await listingPage.locator('[data-album-url$="/album/delete-cache-fixture"]').count() > 0);
  const editBeforeDelete = await context.request.get(`/admin/albums/${deleteAlbum.id}/edit`);
  const deleted = await context.request.post(`/admin/albums/${deleteAlbum.id}/delete`, {
    form: { csrf: editBeforeDelete.headers()['x-csrf-token'] }, maxRedirects: 0,
  });
  assert.equal(deleted.status(), 302);
  const deletedPublicPage = await anonymous.request.get('/album/delete-cache-fixture');
  assert.equal(deletedPublicPage.status(), 404, 'Album deletion must succeed before checking its refreshed listing');
  await listingPage.reload();
  assert.equal(await listingPage.locator('[data-album-url$="/album/delete-cache-fixture"]').count(), 0);
  const survivingPreview = listingPage.locator('[data-album-url$="/album/test"] img').first();
  assert.equal(await survivingPreview.count(), 1);
  const previewResponse = await anonymous.request.get(await survivingPreview.getAttribute('src'));
  assert.equal(previewResponse.status(), 200);
  assert.match(previewResponse.headers()['content-type'], /^image\//);
  assert.equal((await anonymous.request.get('/album/delete-cache-fixture')).status(), 404);
  await listingPage.close();
  console.log('PASS: deleting an album removes its warmed listing and preserves surviving preview bytes');
  await anonymous.close();
  console.log('PASS: uploaded fonts are selectable and served unchanged with refreshed CSS URLs; mobile callouts suppressed');
  await browser.close();
})().catch(error => { console.error(error); process.exit(1); });
