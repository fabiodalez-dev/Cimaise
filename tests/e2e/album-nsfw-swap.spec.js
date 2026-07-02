// @ts-check
import { test, expect, chromium } from '@playwright/test';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const BASE = 'http://localhost:8000';
const SCREENSHOTS = 'test-results/screenshots-albums';

test.describe.serial('Album NSFW blur swap', () => {
  /** @type {import('@playwright/test').Browser} */
  let browser;
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;

  let regularAlbumId;
  let nsfwAlbumId;
  const ts = Date.now();
  const REGULAR_NAME = `Regular Album ${ts}`;
  const NSFW_NAME = `NSFW Album ${ts}`;

  test.beforeAll(async () => {
    browser = await chromium.launch({ headless: false, slowMo: 200 });
    context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    page = await context.newPage();
  });

  test.afterAll(async () => {
    await context.close();
    await browser.close();
  });

  test('Login to admin', async () => {
    await page.goto(`${BASE}/admin/login`);
    await page.fill('input[name="email"]', 'admin@test.com');
    await page.fill('input[name="password"]', 'TestPass123!');
    await page.click('button[type="submit"]');
    await page.waitForURL(/\/admin/, { timeout: 10000 });
    await page.screenshot({ path: `${SCREENSHOTS}/01-admin-dashboard.png`, fullPage: true });
  });

  test('Create regular album', async () => {
    await page.goto(`${BASE}/admin/albums/create`);
    await page.waitForSelector('form#album-form', { timeout: 5000 });
    await page.fill('input[name="title"]', REGULAR_NAME);
    await page.fill('textarea[name="excerpt"]', 'A normal album with photos.');

    const nsfwCheckbox = page.locator('input[name="is_nsfw"]');
    if (await nsfwCheckbox.isChecked()) await nsfwCheckbox.uncheck();

    await page.click('button[type="submit"][form="album-form"]');
    await page.waitForURL(/\/admin\/albums/, { timeout: 15000 });

    // Get album ID from list
    await page.goto(`${BASE}/admin/albums`);
    const link = page.locator(`a:has-text("${REGULAR_NAME}")`).first();
    const href = await link.getAttribute('href');
    regularAlbumId = href?.match(/\/albums\/(\d+)/)?.[1];
    console.log(`Regular album ID: ${regularAlbumId}`);
    expect(regularAlbumId).toBeTruthy();
    await page.screenshot({ path: `${SCREENSHOTS}/02-regular-created.png`, fullPage: true });
  });

  test('Upload image to regular album', async () => {
    await page.goto(`${BASE}/admin/albums/${regularAlbumId}/edit`);
    await page.waitForSelector('#uppy', { timeout: 5000 });

    const uploadResult = await page.evaluate(async ({ albumId, base }) => {
      const csrf = document.querySelector('input[name="csrf"]')?.value
                || document.querySelector('#uppy')?.dataset?.csrf || '';

      const canvas = document.createElement('canvas');
      canvas.width = 200; canvas.height = 200;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#cc3333';
      ctx.fillRect(0, 0, 200, 200);
      ctx.fillStyle = '#ffffff';
      ctx.font = '24px sans-serif';
      ctx.fillText('REGULAR', 30, 110);

      const blob = await new Promise(r => canvas.toBlob(r, 'image/jpeg', 0.9));
      const fd = new FormData();
      fd.append('file', blob, 'test-regular.jpg');

      const res = await fetch(`${base}/admin/albums/${albumId}/upload`, {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
        body: fd
      });
      return { ok: res.ok, status: res.status, data: await res.json().catch(() => null) };
    }, { albumId: regularAlbumId, base: BASE });

    console.log('Regular album upload:', JSON.stringify(uploadResult));
    expect(uploadResult.ok).toBe(true);

    if (uploadResult.data?.id) {
      const coverResult = await page.evaluate(async ({ albumId, imageId, base }) => {
        const csrf = document.querySelector('input[name="csrf"]')?.value
                  || document.querySelector('#uppy')?.dataset?.csrf || '';
        const res = await fetch(`${base}/admin/albums/${albumId}/cover/${imageId}`, {
          method: 'POST',
          headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' }
        });
        return { ok: res.ok };
      }, { albumId: regularAlbumId, imageId: uploadResult.data.id, base: BASE });
      console.log('Regular album cover set:', JSON.stringify(coverResult));
    }

    await page.reload();
    await page.waitForTimeout(1500);
    await page.screenshot({ path: `${SCREENSHOTS}/03-regular-image-uploaded.png`, fullPage: true });
  });

  test('Create NSFW album', async () => {
    await page.goto(`${BASE}/admin/albums/create`);
    await page.waitForSelector('form#album-form', { timeout: 5000 });
    await page.fill('input[name="title"]', NSFW_NAME);
    await page.fill('textarea[name="excerpt"]', 'An NSFW album for blur testing.');

    const nsfwCheckbox = page.locator('input[name="is_nsfw"]');
    if (!(await nsfwCheckbox.isChecked())) await nsfwCheckbox.check();

    await page.click('button[type="submit"][form="album-form"]');
    await page.waitForURL(/\/admin\/albums/, { timeout: 15000 });

    await page.goto(`${BASE}/admin/albums`);
    const link = page.locator(`a:has-text("${NSFW_NAME}")`).first();
    const href = await link.getAttribute('href');
    nsfwAlbumId = href?.match(/\/albums\/(\d+)/)?.[1];
    console.log(`NSFW album ID: ${nsfwAlbumId}`);
    expect(nsfwAlbumId).toBeTruthy();
    await page.screenshot({ path: `${SCREENSHOTS}/04-nsfw-created.png`, fullPage: true });
  });

  test('Upload image to NSFW album', async () => {
    await page.goto(`${BASE}/admin/albums/${nsfwAlbumId}/edit`);
    await page.waitForSelector('#uppy', { timeout: 5000 });

    const uploadResult = await page.evaluate(async ({ albumId, base }) => {
      const csrf = document.querySelector('input[name="csrf"]')?.value
                || document.querySelector('#uppy')?.dataset?.csrf || '';

      const canvas = document.createElement('canvas');
      canvas.width = 200; canvas.height = 200;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#336699';
      ctx.fillRect(0, 0, 200, 200);
      ctx.fillStyle = '#ffffff';
      ctx.font = '24px sans-serif';
      ctx.fillText('NSFW', 55, 110);

      const blob = await new Promise(r => canvas.toBlob(r, 'image/jpeg', 0.9));
      const fd = new FormData();
      fd.append('file', blob, 'test-nsfw.jpg');

      const res = await fetch(`${base}/admin/albums/${albumId}/upload`, {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
        body: fd
      });
      return { ok: res.ok, status: res.status, data: await res.json().catch(() => null) };
    }, { albumId: nsfwAlbumId, base: BASE });

    console.log('NSFW album upload:', JSON.stringify(uploadResult));
    expect(uploadResult.ok).toBe(true);

    if (uploadResult.data?.id) {
      const coverResult = await page.evaluate(async ({ albumId, imageId, base }) => {
        const csrf = document.querySelector('input[name="csrf"]')?.value
                  || document.querySelector('#uppy')?.dataset?.csrf || '';
        const res = await fetch(`${base}/admin/albums/${albumId}/cover/${imageId}`, {
          method: 'POST',
          headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' }
        });
        return { ok: res.ok };
      }, { albumId: nsfwAlbumId, imageId: uploadResult.data.id, base: BASE });
      console.log('NSFW album cover set:', JSON.stringify(coverResult));
    }

    await page.reload();
    await page.waitForTimeout(1500);
    await page.screenshot({ path: `${SCREENSHOTS}/05-nsfw-image-uploaded.png`, fullPage: true });
  });

  test('Clear all caches', async () => {
    await page.goto(`${BASE}/admin/cache`);
    const clearForm = page.locator('form[action$="/admin/cache/clear-everything"]');
    await clearForm.locator('button[type="submit"]').click();
    await page.waitForTimeout(2000);
    await page.screenshot({ path: `${SCREENSHOTS}/06-cache-cleared.png`, fullPage: true });
  });

  test('Verify frontend BEFORE swap', async () => {
    // Anonymous context — no admin session, no NSFW consent
    const anonCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const anonPage = await anonCtx.newPage();

    await anonPage.goto(`${BASE}/?template=classic`);
    await anonPage.waitForTimeout(2000);
    await anonPage.screenshot({ path: `${SCREENSHOTS}/07-anon-before-swap.png`, fullPage: true });

    // Current design: the HOME excludes NSFW albums for anonymous visitors
    // without consent (SQL-level filter in PageController::home — no carousel
    // under-fill, no leak). The blur + overlay affordance lives on the LISTING
    // pages (/galleries), so blur state is asserted there.
    const regularCard = anonPage.locator(`article.album-card[data-album-id="${regularAlbumId}"]`).first();

    // Regular Album: visible on home, NOT marked as NSFW, no blur, no overlay
    await expect(regularCard).toBeVisible({ timeout: 5000 });
    expect(await regularCard.getAttribute('data-nsfw')).toBeNull();
    expect(await regularCard.locator('img.nsfw-blur').count()).toBe(0);
    expect(await regularCard.locator('.nsfw-overlay').count()).toBe(0);
    console.log('Anon before swap: Regular Album = visible on home, NOT blurred');

    // NSFW Album: excluded from the anonymous home entirely
    expect(await anonPage.locator(`article.album-card[data-album-id="${nsfwAlbumId}"]`).count()).toBe(0);
    console.log('Anon before swap: NSFW Album = hidden from anonymous home');

    // Galleries listing: both present — regular clear, NSFW blurred + overlay
    await anonPage.goto(`${BASE}/galleries`);
    await anonPage.waitForTimeout(1500);
    await anonPage.screenshot({ path: `${SCREENSHOTS}/07b-anon-galleries-before-swap.png`, fullPage: true });

    const gallRegular = anonPage.locator(`article[data-album-id="${regularAlbumId}"]`).first();
    const gallNsfw = anonPage.locator(`article[data-album-id="${nsfwAlbumId}"]`).first();
    await expect(gallRegular).toBeVisible({ timeout: 5000 });
    await expect(gallNsfw).toBeVisible({ timeout: 5000 });
    expect(await gallRegular.locator('img.nsfw-blur').count()).toBe(0);
    expect(await gallNsfw.getAttribute('data-nsfw')).toBe('1');
    expect(await gallNsfw.locator('img.nsfw-blur').count()).toBeGreaterThan(0);
    expect(await gallNsfw.locator('.nsfw-overlay').count()).toBeGreaterThan(0);
    console.log('Anon before swap: galleries — Regular clear, NSFW BLURRED with overlay');

    // Admin view: admin sees both albums without blur
    await page.goto(`${BASE}/?template=classic`);
    await page.waitForTimeout(1500);
    await page.screenshot({ path: `${SCREENSHOTS}/08-admin-before-swap.png`, fullPage: true });

    const adminRegular = page.locator(`article.album-card[data-album-id="${regularAlbumId}"]`).first();
    const adminNsfw = page.locator(`article.album-card[data-album-id="${nsfwAlbumId}"]`).first();
    await expect(adminRegular).toBeVisible();
    await expect(adminNsfw).toBeVisible();
    // Admin sees no blur on either
    expect(await adminNsfw.getAttribute('data-nsfw')).toBeNull();
    expect(await adminNsfw.locator('img.nsfw-blur').count()).toBe(0);
    console.log('Admin before swap: both albums visible, NO blur');

    await anonCtx.close();
  });

  test('Swap: Regular album → NSFW', async () => {
    await page.goto(`${BASE}/admin/albums/${regularAlbumId}/edit`);
    await page.waitForSelector('form#album-form', { timeout: 5000 });

    const nsfwCheckbox = page.locator('input[name="is_nsfw"]');
    expect(await nsfwCheckbox.isChecked()).toBe(false);
    await nsfwCheckbox.check();
    await page.screenshot({ path: `${SCREENSHOTS}/09-regular-to-nsfw.png`, fullPage: true });

    await page.click('button[type="submit"][form="album-form"]');
    await page.waitForURL(/\/admin\/albums/, { timeout: 15000 });
  });

  test('Swap: NSFW album → Regular', async () => {
    await page.goto(`${BASE}/admin/albums/${nsfwAlbumId}/edit`);
    await page.waitForSelector('form#album-form', { timeout: 5000 });

    const nsfwCheckbox = page.locator('input[name="is_nsfw"]');
    expect(await nsfwCheckbox.isChecked()).toBe(true);
    await nsfwCheckbox.uncheck();
    await page.screenshot({ path: `${SCREENSHOTS}/10-nsfw-to-regular.png`, fullPage: true });

    await page.click('button[type="submit"][form="album-form"]');
    await page.waitForURL(/\/admin\/albums/, { timeout: 15000 });
  });

  test('Clear cache after swap', async () => {
    await page.goto(`${BASE}/admin/cache`);
    const clearForm = page.locator('form[action$="/admin/cache/clear-everything"]');
    await clearForm.locator('button[type="submit"]').click();
    await page.waitForTimeout(2000);
    await page.screenshot({ path: `${SCREENSHOTS}/11-cache-cleared-after.png`, fullPage: true });
  });

  test('Verify frontend AFTER swap: blur inverted', async () => {
    // Anonymous: after swap, "Regular Album" is now NSFW (blurred), "NSFW Album" is now regular (clear)
    const anonCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const anonPage = await anonCtx.newPage();

    await anonPage.goto(`${BASE}/?template=classic`);
    await anonPage.waitForTimeout(2000);
    await anonPage.screenshot({ path: `${SCREENSHOTS}/12-anon-after-swap.png`, fullPage: true });

    const nsfwCard = anonPage.locator(`article.album-card[data-album-id="${nsfwAlbumId}"]`).first();

    // INVERTED: "Regular Album" is now NSFW → excluded from the anonymous home
    expect(await anonPage.locator(`article.album-card[data-album-id="${regularAlbumId}"]`).count()).toBe(0);
    console.log('Anon after swap: Regular Album = hidden from home (now NSFW)');

    // INVERTED: "NSFW Album" is now regular → visible and clear on home
    await expect(nsfwCard).toBeVisible({ timeout: 5000 });
    expect(await nsfwCard.getAttribute('data-nsfw')).toBeNull();
    expect(await nsfwCard.locator('img.nsfw-blur').count()).toBe(0);
    expect(await nsfwCard.locator('.nsfw-overlay').count()).toBe(0);
    console.log('Anon after swap: NSFW Album = CLEAR on home (now regular)');

    // Galleries listing: blur inverted — "Regular Album" blurred, "NSFW Album" clear
    await anonPage.goto(`${BASE}/galleries`);
    await anonPage.waitForTimeout(1500);
    await anonPage.screenshot({ path: `${SCREENSHOTS}/12b-anon-galleries-after-swap.png`, fullPage: true });

    const gallRegular = anonPage.locator(`article[data-album-id="${regularAlbumId}"]`).first();
    const gallNsfw = anonPage.locator(`article[data-album-id="${nsfwAlbumId}"]`).first();
    await expect(gallRegular).toBeVisible({ timeout: 5000 });
    await expect(gallNsfw).toBeVisible({ timeout: 5000 });
    expect(await gallRegular.getAttribute('data-nsfw')).toBe('1');
    expect(await gallRegular.locator('img.nsfw-blur').count()).toBeGreaterThan(0);
    expect(await gallNsfw.locator('img.nsfw-blur').count()).toBe(0);
    console.log('Anon after swap: galleries — blur correctly inverted');

    // Admin still sees both without blur
    await page.goto(`${BASE}/?template=classic`);
    await page.waitForTimeout(1500);
    await page.screenshot({ path: `${SCREENSHOTS}/13-admin-after-swap.png`, fullPage: true });

    const adminRegular = page.locator(`article.album-card[data-album-id="${regularAlbumId}"]`).first();
    const adminNsfw = page.locator(`article.album-card[data-album-id="${nsfwAlbumId}"]`).first();
    await expect(adminRegular).toBeVisible();
    await expect(adminNsfw).toBeVisible();
    expect(await adminRegular.locator('img.nsfw-blur').count()).toBe(0);
    expect(await adminNsfw.locator('img.nsfw-blur').count()).toBe(0);
    console.log('Admin after swap: both albums visible, NO blur');

    await anonCtx.close();
  });

  test('Final: verify admin edit forms reflect swap', async () => {
    await page.goto(`${BASE}/admin/albums/${regularAlbumId}/edit`);
    expect(await page.locator('input[name="is_nsfw"]').isChecked()).toBe(true);
    await page.screenshot({ path: `${SCREENSHOTS}/14-regular-now-nsfw.png`, fullPage: true });

    await page.goto(`${BASE}/admin/albums/${nsfwAlbumId}/edit`);
    expect(await page.locator('input[name="is_nsfw"]').isChecked()).toBe(false);
    await page.screenshot({ path: `${SCREENSHOTS}/15-nsfw-now-regular.png`, fullPage: true });
  });
});
