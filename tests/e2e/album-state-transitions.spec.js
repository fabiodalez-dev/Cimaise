// @ts-check
import { test, expect, chromium } from '@playwright/test';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8000';
const SCREENSHOTS = path.join('test-results', 'screenshots-transitions');
const ADMIN_EMAIL = process.env.TEST_ADMIN_EMAIL || 'admin@test.com';
const ADMIN_PASSWORD = process.env.TEST_ADMIN_PASSWORD || 'TestPass123!';
const ts = Date.now();
const ALBUM_NAME = `StateTransition ${ts}`;
const ALBUM_PASSWORD = 'secret123';

test.describe.serial('Album state transitions — NSFW & Password on same album', () => {
  /** @type {import('@playwright/test').Browser} */
  let browser;
  /** @type {import('@playwright/test').BrowserContext} */
  let adminCtx;
  /** @type {import('@playwright/test').Page} */
  let admin;

  let albumId, albumSlug;

  // ─── helpers ───────────────────────────────────────────────────────

  /** Upload a canvas-based test image and set it as cover */
  async function uploadAndSetCover(page, albumId, label, color) {
    await page.goto(`${BASE}/admin/albums/${albumId}/edit`);
    await page.waitForSelector('#uppy', { timeout: 5000 });

    const result = await page.evaluate(async ({ albumId, base, label, color }) => {
      const csrf = document.querySelector('input[name="csrf"]')?.value
                || document.querySelector('#uppy')?.dataset?.csrf || '';
      const canvas = document.createElement('canvas');
      canvas.width = 200; canvas.height = 200;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = color;
      ctx.fillRect(0, 0, 200, 200);
      ctx.fillStyle = '#fff';
      ctx.font = '20px sans-serif';
      ctx.fillText(label, 20, 110);
      const blob = await new Promise(r => canvas.toBlob(r, 'image/jpeg', 0.9));
      const fd = new FormData();
      fd.append('file', blob, `test-${label}.jpg`);
      const res = await fetch(`${base}/admin/albums/${albumId}/upload`, {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
        body: fd
      });
      const data = await res.json().catch(() => null);
      if (res.ok && data?.id) {
        await fetch(`${base}/admin/albums/${albumId}/cover/${data.id}`, {
          method: 'POST',
          headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' }
        });
      }
      return { ok: res.ok, imageId: data?.id };
    }, { albumId, base: BASE, label, color });

    expect(result.ok).toBe(true);
    return result.imageId;
  }

  /** Clear all caches via admin panel */
  async function clearCaches(page) {
    await page.goto(`${BASE}/admin/cache`);
    const clearForm = page.locator('form[action$="/admin/cache/clear-everything"]');
    await clearForm.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');
  }

  /** Toggle NSFW flag on an album via the edit form */
  async function toggleNsfw(page, albumId, enable) {
    await page.goto(`${BASE}/admin/albums/${albumId}/edit`);
    await page.waitForSelector('form#album-form', { timeout: 5000 });

    const nsfwBox = page.locator('input[name="is_nsfw"]');
    await nsfwBox.scrollIntoViewIfNeeded();
    if (enable && !(await nsfwBox.isChecked())) {
      await nsfwBox.check({ force: true });
    } else if (!enable && (await nsfwBox.isChecked())) {
      await nsfwBox.uncheck({ force: true });
    }

    await page.click('button[type="submit"][form="album-form"]');
    await page.waitForURL(/\/admin\/albums/, { timeout: 15000 });
  }

  /** Add a password to an album via the edit form (album must have no password) */
  async function setPassword(page, albumId, password) {
    await page.goto(`${BASE}/admin/albums/${albumId}/edit`);
    await page.waitForSelector('form#album-form', { timeout: 5000 });

    // Click the "Add Password" button to reveal the form
    const addBtn = page.locator('#add-password-btn');
    await addBtn.scrollIntoViewIfNeeded();
    await addBtn.click();

    // Fill the password field
    const passwordInput = page.locator('#new-password-input');
    await expect(passwordInput).toBeVisible({ timeout: 3000 });
    await passwordInput.fill(password);

    // Click save — triggers handleSavePassword() which calls form.submit()
    await page.click('#save-add-btn');
    await page.waitForURL(/\/admin\/albums/, { timeout: 15000 });
  }

  /** Remove password from an album via the edit form (album must have a password) */
  async function removePassword(page, albumId) {
    await page.goto(`${BASE}/admin/albums/${albumId}/edit`);
    await page.waitForSelector('form#album-form', { timeout: 5000 });

    // Handle the confirm() dialog that removePassword triggers
    page.once('dialog', async dialog => {
      await dialog.accept();
    });

    const removeBtn = page.locator('#remove-password-btn');
    await removeBtn.scrollIntoViewIfNeeded();
    await removeBtn.click();

    await page.waitForURL(/\/admin\/albums/, { timeout: 15000 });
  }

  /** Set NSFW + add password in a single form submission */
  async function setNsfwAndPassword(page, albumId, password) {
    await page.goto(`${BASE}/admin/albums/${albumId}/edit`);
    await page.waitForSelector('form#album-form', { timeout: 5000 });

    // Check NSFW first
    const nsfwBox = page.locator('input[name="is_nsfw"]');
    await nsfwBox.scrollIntoViewIfNeeded();
    if (!(await nsfwBox.isChecked())) {
      await nsfwBox.check({ force: true });
    }

    // Add password — the save-add-btn triggers form.submit() which includes the NSFW checkbox
    const addBtn = page.locator('#add-password-btn');
    await addBtn.scrollIntoViewIfNeeded();
    await addBtn.click();

    const passwordInput = page.locator('#new-password-input');
    await expect(passwordInput).toBeVisible({ timeout: 3000 });
    await passwordInput.fill(password);

    await page.click('#save-add-btn');
    await page.waitForURL(/\/admin\/albums/, { timeout: 15000 });
  }

  /** Remove password while keeping NSFW (remove-password auto-submits entire form) */
  async function removePasswordKeepNsfw(page, albumId) {
    await page.goto(`${BASE}/admin/albums/${albumId}/edit`);
    await page.waitForSelector('form#album-form', { timeout: 5000 });

    // Verify NSFW is currently checked (we want to keep it)
    const nsfwBox = page.locator('input[name="is_nsfw"]');
    expect(await nsfwBox.isChecked()).toBe(true);

    // Handle the confirm() dialog
    page.once('dialog', async dialog => {
      await dialog.accept();
    });

    const removeBtn = page.locator('#remove-password-btn');
    await removeBtn.scrollIntoViewIfNeeded();
    await removeBtn.click();

    await page.waitForURL(/\/admin\/albums/, { timeout: 15000 });
  }

  /**
   * Verify album card visual state on a listing page.
   * @param {import('@playwright/test').Page} page
   * @param {string} cardSelector - 'article.album-card' (home classic) or 'article' (galleries)
   * @param {string} id - album ID
   * @param {{blur: boolean, overlay: boolean, lock: boolean, dataNsfw: string|null, dataPasswordProtected?: string}} expected
   */
  async function verifyCardState(page, cardSelector, id, expected) {
    const card = page.locator(`${cardSelector}[data-album-id="${id}"]`).first();
    await expect(card).toBeVisible({ timeout: 5000 });

    // Blur
    if (expected.blur) {
      expect(await card.locator('img.nsfw-blur').count()).toBeGreaterThan(0);
    } else {
      expect(await card.locator('img.nsfw-blur').count()).toBe(0);
    }

    // Overlay (.nsfw-overlay is used for both NSFW overlay and lock overlay on galleries)
    if (expected.overlay) {
      expect(await card.locator('.nsfw-overlay').count()).toBeGreaterThan(0);
    } else {
      expect(await card.locator('.nsfw-overlay').count()).toBe(0);
    }

    // Lock icon (.fa-lock badge — may appear in badge and/or overlay)
    if (expected.lock) {
      expect(await card.locator('.fa-lock').count()).toBeGreaterThan(0);
    } else {
      expect(await card.locator('.fa-lock').count()).toBe(0);
    }

    // data-nsfw attribute
    if (expected.dataNsfw === null) {
      expect(await card.getAttribute('data-nsfw')).toBeNull();
    } else {
      expect(await card.getAttribute('data-nsfw')).toBe(expected.dataNsfw);
    }

    // data-password-protected attribute (galleries only)
    if (expected.dataPasswordProtected !== undefined) {
      expect(await card.getAttribute('data-password-protected')).toBe(expected.dataPasswordProtected);
    }
  }

  /**
   * Verify single album gate state for anonymous user.
   * @param {import('@playwright/test').Browser} brs
   * @param {string} slug
   * @param {'none'|'nsfw'|'password'|'password+nsfw'} expectedGate
   * @param {string} label - screenshot label
   */
  async function verifyAlbumGate(brs, slug, expectedGate, label) {
    const ctx = await brs.newContext({ viewport: { width: 1280, height: 900 } });
    try {
      const pg = await ctx.newPage();
      await pg.goto(`${BASE}/album/${slug}`);
      await pg.waitForLoadState('load');
      await pg.screenshot({ path: path.join(SCREENSHOTS, `${label}-gate-${expectedGate}.png`), fullPage: true });

      switch (expectedGate) {
        case 'none':
          expect(await pg.locator('#nsfw-confirm-form').count()).toBe(0);
          expect(await pg.locator('#album-password-form').count()).toBe(0);
          // Content visible
          const content = pg.locator('#album-main-content img, .gallery-item img, picture img');
          expect(await content.count()).toBeGreaterThan(0);
          break;

        case 'nsfw':
          await expect(pg.locator('#nsfw-confirm-form')).toBeVisible({ timeout: 5000 });
          expect(await pg.locator('#album-password-form').count()).toBe(0);
          // Album content not rendered (gate is a separate page)
          expect(await pg.locator('#album-main-content').count()).toBe(0);
          break;

        case 'password':
          await expect(pg.locator('#album-password-form')).toBeVisible({ timeout: 5000 });
          // No NSFW checkbox (album is not NSFW)
          expect(await pg.locator('#album-password-form input[name="nsfw_confirmed"]').count()).toBe(0);
          expect(await pg.locator('#nsfw-confirm-form').count()).toBe(0);
          break;

        case 'password+nsfw':
          await expect(pg.locator('#album-password-form')).toBeVisible({ timeout: 5000 });
          // NSFW checkbox also present in the password form
          expect(await pg.locator('#album-password-form input[name="nsfw_confirmed"]').count()).toBeGreaterThan(0);
          // No separate NSFW gate (password gate takes precedence)
          expect(await pg.locator('#nsfw-confirm-form').count()).toBe(0);
          break;
      }
    } finally {
      await ctx.close();
    }
  }

  /** Verify admin sees album directly — no gates, content visible */
  async function verifyAdminNoGate(page, slug) {
    await page.goto(`${BASE}/album/${slug}`);
    await page.waitForLoadState('load');
    expect(await page.locator('#nsfw-confirm-form').count()).toBe(0);
    expect(await page.locator('#album-password-form').count()).toBe(0);
    const images = page.locator('#album-main-content img, .gallery-item img, picture img');
    expect(await images.count()).toBeGreaterThan(0);
  }

  // ─── setup & teardown ─────────────────────────────────────────────

  // .serial() is required because all 7 transitions share and mutate a single album's state
  test.beforeAll(async () => {
    const isCI = !!process.env.CI;
    browser = await chromium.launch({ headless: isCI, slowMo: isCI ? 0 : 150 });
    adminCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    admin = await adminCtx.newPage();
  });

  test.afterAll(async () => {
    await adminCtx.close();
    await browser.close();
  });

  // ─── initial setup ────────────────────────────────────────────────

  test('Setup: Login, create album with cover image, clear caches', async () => {
    test.setTimeout(120000);

    // Login
    await admin.goto(`${BASE}/admin/login`);
    await admin.fill('input[name="email"]', ADMIN_EMAIL);
    await admin.fill('input[name="password"]', ADMIN_PASSWORD);
    await admin.click('button[type="submit"]');
    await admin.waitForURL(/\/admin/, { timeout: 10000 });
    await admin.screenshot({ path: path.join(SCREENSHOTS, '00-login.png'), fullPage: true });

    // Create album (regular, published, no NSFW, no password)
    await admin.goto(`${BASE}/admin/albums/create`);
    await admin.waitForSelector('form#album-form', { timeout: 5000 });
    await admin.fill('input[name="title"]', ALBUM_NAME);
    await admin.fill('textarea[name="excerpt"]', `State transition test album: ${ALBUM_NAME}`);

    const nsfwBox = admin.locator('input[name="is_nsfw"]');
    await nsfwBox.scrollIntoViewIfNeeded();
    if (await nsfwBox.isChecked()) await nsfwBox.uncheck({ force: true });

    await admin.click('button[type="submit"][form="album-form"]');
    await admin.waitForURL(/\/admin\/albums/, { timeout: 15000 });

    // Get album ID from list
    await admin.goto(`${BASE}/admin/albums`);
    const link = admin.locator(`a:has-text("${ALBUM_NAME}")`).first();
    const href = await link.getAttribute('href');
    albumId = href?.match(/\/albums\/(\d+)/)?.[1];
    expect(albumId).toBeTruthy();

    // Get slug from edit page
    await admin.goto(`${BASE}/admin/albums/${albumId}/edit`);
    albumSlug = await admin.locator('input[name="slug"]').inputValue();
    console.log(`Test album created: id=${albumId} slug=${albumSlug}`);

    // Upload cover image
    await uploadAndSetCover(admin, albumId, 'TRANSITION', '#4488cc');

    // Clear caches
    await clearCaches(admin);

    await admin.screenshot({ path: path.join(SCREENSHOTS, '01-setup-complete.png'), fullPage: true });
  });

  // ═══════════════════════════════════════════════════════════════════
  // TRANSITION 1: Regular → NSFW
  // ═══════════════════════════════════════════════════════════════════

  test('T1: Regular → NSFW', async () => {
    test.setTimeout(90000);

    await toggleNsfw(admin, albumId, true);
    await clearCaches(admin);
    console.log('T1: Applied NSFW flag');

    // ── Anon: Home classic — blur + overlay, no lock ──
    const anonCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const anonPage = await anonCtx.newPage();
    await anonPage.goto(`${BASE}/?template=classic`);
    await anonPage.waitForLoadState('load');
    await anonPage.screenshot({ path: path.join(SCREENSHOTS, 'T1-home-classic-anon.png'), fullPage: true });

    await verifyCardState(anonPage, 'article.album-card', albumId, {
      blur: true, overlay: true, lock: false, dataNsfw: '1'
    });
    console.log('T1: Home classic anon — blur + overlay, no lock ✓');
    await anonCtx.close();

    // ── Anon: Galleries — blur + overlay, no lock ──
    const gallCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const gallPage = await gallCtx.newPage();
    await gallPage.goto(`${BASE}/galleries`);
    await gallPage.waitForLoadState('load');
    await gallPage.screenshot({ path: path.join(SCREENSHOTS, 'T1-galleries-anon.png'), fullPage: true });

    await verifyCardState(gallPage, 'article', albumId, {
      blur: true, overlay: true, lock: false, dataNsfw: '1', dataPasswordProtected: '0'
    });
    console.log('T1: Galleries anon — blur + overlay, no lock ✓');
    await gallCtx.close();

    // ── Anon: Single album — NSFW gate ──
    await verifyAlbumGate(browser, albumSlug, 'nsfw', 'T1');
    console.log('T1: Single album anon — NSFW gate ✓');

    // ── Admin: Single album — no gate ──
    await verifyAdminNoGate(admin, albumSlug);
    await admin.screenshot({ path: path.join(SCREENSHOTS, 'T1-admin-album.png'), fullPage: true });
    console.log('T1: Admin album — no gate ✓');

    // ── Admin: Home classic — no blur ──
    await admin.goto(`${BASE}/?template=classic`);
    await admin.waitForLoadState('load');
    await verifyCardState(admin, 'article.album-card', albumId, {
      blur: false, overlay: false, lock: false, dataNsfw: null
    });
    console.log('T1: Admin home classic — no blur ✓');
  });

  // ═══════════════════════════════════════════════════════════════════
  // TRANSITION 2: NSFW → Regular
  // ═══════════════════════════════════════════════════════════════════

  test('T2: NSFW → Regular', async () => {
    test.setTimeout(90000);

    await toggleNsfw(admin, albumId, false);
    await clearCaches(admin);
    console.log('T2: Removed NSFW flag');

    // ── Anon: Home classic — clean ──
    const anonCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const anonPage = await anonCtx.newPage();
    await anonPage.goto(`${BASE}/?template=classic`);
    await anonPage.waitForLoadState('load');
    await anonPage.screenshot({ path: path.join(SCREENSHOTS, 'T2-home-classic-anon.png'), fullPage: true });

    await verifyCardState(anonPage, 'article.album-card', albumId, {
      blur: false, overlay: false, lock: false, dataNsfw: null
    });
    console.log('T2: Home classic anon — clean ✓');
    await anonCtx.close();

    // ── Anon: Galleries — clean ──
    const gallCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const gallPage = await gallCtx.newPage();
    await gallPage.goto(`${BASE}/galleries`);
    await gallPage.waitForLoadState('load');
    await gallPage.screenshot({ path: path.join(SCREENSHOTS, 'T2-galleries-anon.png'), fullPage: true });

    await verifyCardState(gallPage, 'article', albumId, {
      blur: false, overlay: false, lock: false, dataNsfw: '0', dataPasswordProtected: '0'
    });
    console.log('T2: Galleries anon — clean ✓');
    await gallCtx.close();

    // ── Anon: Single album — no gate, content visible ──
    await verifyAlbumGate(browser, albumSlug, 'none', 'T2');
    console.log('T2: Single album anon — no gate ✓');
  });

  // ═══════════════════════════════════════════════════════════════════
  // TRANSITION 3: Regular → Password
  // ═══════════════════════════════════════════════════════════════════

  test('T3: Regular → Password', async () => {
    test.setTimeout(90000);

    await setPassword(admin, albumId, ALBUM_PASSWORD);
    await clearCaches(admin);
    console.log('T3: Added password');

    // ── Anon: Home classic — blur + lock, no NSFW overlay ──
    const anonCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const anonPage = await anonCtx.newPage();
    await anonPage.goto(`${BASE}/?template=classic`);
    await anonPage.waitForLoadState('load');
    await anonPage.screenshot({ path: path.join(SCREENSHOTS, 'T3-home-classic-anon.png'), fullPage: true });

    await verifyCardState(anonPage, 'article.album-card', albumId, {
      blur: true, overlay: false, lock: true, dataNsfw: null
    });
    console.log('T3: Home classic anon — blur + lock, no NSFW overlay ✓');
    await anonCtx.close();

    // ── Anon: Galleries — lock overlay (nsfw-overlay class) + lock badge ──
    const gallCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const gallPage = await gallCtx.newPage();
    await gallPage.goto(`${BASE}/galleries`);
    await gallPage.waitForLoadState('load');
    await gallPage.screenshot({ path: path.join(SCREENSHOTS, 'T3-galleries-anon.png'), fullPage: true });

    await verifyCardState(gallPage, 'article', albumId, {
      blur: true, overlay: true, lock: true, dataNsfw: '0', dataPasswordProtected: '1'
    });
    console.log('T3: Galleries anon — blur + lock overlay ✓');
    await gallCtx.close();

    // ── Anon: Single album — password gate ──
    await verifyAlbumGate(browser, albumSlug, 'password', 'T3');
    console.log('T3: Single album anon — password gate ✓');

    // ── Admin: no gate ──
    await verifyAdminNoGate(admin, albumSlug);
    await admin.screenshot({ path: path.join(SCREENSHOTS, 'T3-admin-album.png'), fullPage: true });
    console.log('T3: Admin album — no gate ✓');
  });

  // ═══════════════════════════════════════════════════════════════════
  // TRANSITION 4: Password → Regular
  // ═══════════════════════════════════════════════════════════════════

  test('T4: Password → Regular', async () => {
    test.setTimeout(90000);

    await removePassword(admin, albumId);
    await clearCaches(admin);
    console.log('T4: Removed password');

    // ── Anon: Home classic — clean ──
    const anonCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const anonPage = await anonCtx.newPage();
    await anonPage.goto(`${BASE}/?template=classic`);
    await anonPage.waitForLoadState('load');
    await anonPage.screenshot({ path: path.join(SCREENSHOTS, 'T4-home-classic-anon.png'), fullPage: true });

    await verifyCardState(anonPage, 'article.album-card', albumId, {
      blur: false, overlay: false, lock: false, dataNsfw: null
    });
    console.log('T4: Home classic anon — clean ✓');
    await anonCtx.close();

    // ── Anon: Galleries — clean ──
    const gallCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const gallPage = await gallCtx.newPage();
    await gallPage.goto(`${BASE}/galleries`);
    await gallPage.waitForLoadState('load');
    await gallPage.screenshot({ path: path.join(SCREENSHOTS, 'T4-galleries-anon.png'), fullPage: true });

    await verifyCardState(gallPage, 'article', albumId, {
      blur: false, overlay: false, lock: false, dataNsfw: '0', dataPasswordProtected: '0'
    });
    console.log('T4: Galleries anon — clean ✓');
    await gallCtx.close();

    // ── Anon: Single album — no gate ──
    await verifyAlbumGate(browser, albumSlug, 'none', 'T4');
    console.log('T4: Single album anon — no gate ✓');
  });

  // ═══════════════════════════════════════════════════════════════════
  // TRANSITION 5: Regular → NSFW + Password
  // ═══════════════════════════════════════════════════════════════════

  test('T5: Regular → NSFW + Password', async () => {
    test.setTimeout(90000);

    await setNsfwAndPassword(admin, albumId, ALBUM_PASSWORD);
    await clearCaches(admin);
    console.log('T5: Applied NSFW + password');

    // ── Anon: Home classic — blur + overlay + lock ──
    const anonCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const anonPage = await anonCtx.newPage();
    await anonPage.goto(`${BASE}/?template=classic`);
    await anonPage.waitForLoadState('load');
    await anonPage.screenshot({ path: path.join(SCREENSHOTS, 'T5-home-classic-anon.png'), fullPage: true });

    await verifyCardState(anonPage, 'article.album-card', albumId, {
      blur: true, overlay: true, lock: true, dataNsfw: '1'
    });
    console.log('T5: Home classic anon — blur + overlay + lock ✓');
    await anonCtx.close();

    // ── Anon: Galleries — blur + NSFW overlay + lock badge ──
    const gallCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const gallPage = await gallCtx.newPage();
    await gallPage.goto(`${BASE}/galleries`);
    await gallPage.waitForLoadState('load');
    await gallPage.screenshot({ path: path.join(SCREENSHOTS, 'T5-galleries-anon.png'), fullPage: true });

    await verifyCardState(gallPage, 'article', albumId, {
      blur: true, overlay: true, lock: true, dataNsfw: '1', dataPasswordProtected: '1'
    });
    console.log('T5: Galleries anon — blur + overlay + lock ✓');
    await gallCtx.close();

    // ── Anon: Single album — password gate with NSFW checkbox ──
    await verifyAlbumGate(browser, albumSlug, 'password+nsfw', 'T5');
    console.log('T5: Single album anon — password+nsfw gate ✓');

    // ── Admin: no gate ──
    await verifyAdminNoGate(admin, albumSlug);
    await admin.screenshot({ path: path.join(SCREENSHOTS, 'T5-admin-album.png'), fullPage: true });
    console.log('T5: Admin album — no gate ✓');
  });

  // ═══════════════════════════════════════════════════════════════════
  // TRANSITION 6: NSFW + Password → NSFW only
  // ═══════════════════════════════════════════════════════════════════

  test('T6: NSFW+Password → NSFW only', async () => {
    test.setTimeout(90000);

    await removePasswordKeepNsfw(admin, albumId);
    await clearCaches(admin);
    console.log('T6: Removed password, kept NSFW');

    // ── Anon: Home classic — blur + overlay, no lock ──
    const anonCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const anonPage = await anonCtx.newPage();
    await anonPage.goto(`${BASE}/?template=classic`);
    await anonPage.waitForLoadState('load');
    await anonPage.screenshot({ path: path.join(SCREENSHOTS, 'T6-home-classic-anon.png'), fullPage: true });

    await verifyCardState(anonPage, 'article.album-card', albumId, {
      blur: true, overlay: true, lock: false, dataNsfw: '1'
    });
    console.log('T6: Home classic anon — blur + overlay, no lock ✓');
    await anonCtx.close();

    // ── Anon: Galleries — blur + overlay, no lock ──
    const gallCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const gallPage = await gallCtx.newPage();
    await gallPage.goto(`${BASE}/galleries`);
    await gallPage.waitForLoadState('load');
    await gallPage.screenshot({ path: path.join(SCREENSHOTS, 'T6-galleries-anon.png'), fullPage: true });

    await verifyCardState(gallPage, 'article', albumId, {
      blur: true, overlay: true, lock: false, dataNsfw: '1', dataPasswordProtected: '0'
    });
    console.log('T6: Galleries anon — blur + overlay, no lock ✓');
    await gallCtx.close();

    // ── Anon: Single album — NSFW gate (not password) ──
    await verifyAlbumGate(browser, albumSlug, 'nsfw', 'T6');
    console.log('T6: Single album anon — NSFW gate ✓');

    // ── Admin: no gate ──
    await verifyAdminNoGate(admin, albumSlug);
    await admin.screenshot({ path: path.join(SCREENSHOTS, 'T6-admin-album.png'), fullPage: true });
    console.log('T6: Admin album — no gate ✓');
  });

  // ═══════════════════════════════════════════════════════════════════
  // TRANSITION 7: NSFW → Regular (final)
  // ═══════════════════════════════════════════════════════════════════

  test('T7: NSFW → Regular (final)', async () => {
    test.setTimeout(90000);

    await toggleNsfw(admin, albumId, false);
    await clearCaches(admin);
    console.log('T7: Removed NSFW flag (back to regular)');

    // ── Anon: Home classic — clean ──
    const anonCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const anonPage = await anonCtx.newPage();
    await anonPage.goto(`${BASE}/?template=classic`);
    await anonPage.waitForLoadState('load');
    await anonPage.screenshot({ path: path.join(SCREENSHOTS, 'T7-home-classic-anon.png'), fullPage: true });

    await verifyCardState(anonPage, 'article.album-card', albumId, {
      blur: false, overlay: false, lock: false, dataNsfw: null
    });
    console.log('T7: Home classic anon — clean ✓');
    await anonCtx.close();

    // ── Anon: Galleries — clean ──
    const gallCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const gallPage = await gallCtx.newPage();
    await gallPage.goto(`${BASE}/galleries`);
    await gallPage.waitForLoadState('load');
    await gallPage.screenshot({ path: path.join(SCREENSHOTS, 'T7-galleries-anon.png'), fullPage: true });

    await verifyCardState(gallPage, 'article', albumId, {
      blur: false, overlay: false, lock: false, dataNsfw: '0', dataPasswordProtected: '0'
    });
    console.log('T7: Galleries anon — clean ✓');
    await gallCtx.close();

    // ── Anon: Single album — no gate, content visible ──
    await verifyAlbumGate(browser, albumSlug, 'none', 'T7');
    console.log('T7: Single album anon — no gate ✓');
  });
});
