// @ts-check
import { test, expect, chromium } from '@playwright/test';
import { fileURLToPath } from 'url';
import path from 'path';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8000';
const SCREENSHOTS = 'test-results/screenshots-complete';
const ADMIN_EMAIL = process.env.TEST_ADMIN_EMAIL || 'admin@test.com';
const ADMIN_PASSWORD = process.env.TEST_ADMIN_PASSWORD || 'TestPass123!';
const ts = Date.now();

// Unique album names to avoid collision with previous runs
const REGULAR_NAME = `Regular ${ts}`;
const NSFW_NAME = `Nsfw ${ts}`;
const PWD_NAME = `Pwd ${ts}`;
const PWD_NSFW_NAME = `PwdNsfw ${ts}`;
const ALBUM_PASSWORD = 'secret123';

test.describe.serial('NSFW & Password — complete verification', () => {
  /** @type {import('@playwright/test').Browser} */
  let browser;
  /** @type {import('@playwright/test').BrowserContext} */
  let adminCtx;
  /** @type {import('@playwright/test').Page} */
  let admin;

  let regularId, nsfwId, pwdId, pwdNsfwId;
  let regularSlug, nsfwSlug, pwdSlug, pwdNsfwSlug;

  // ─── helpers ───────────────────────────────────────────────────────

  /** Upload a canvas-based test image and set it as cover */
  async function uploadAndSetCover(page, albumId, label, color) {
    await page.goto(`${BASE}/admin/albums/${albumId}/edit`, { waitUntil: 'domcontentloaded' });
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

  /** Create an album through the admin and return {id, slug} */
  async function createAlbum(page, title, { nsfw = false, password = '' } = {}) {
    await page.goto(`${BASE}/admin/albums/create`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('form#album-form', { timeout: 5000 });
    await page.fill('input[name="title"]', title);
    await page.fill('textarea[name="excerpt"]', `Test album: ${title}`);

    if (password) {
      await page.fill('input[name="password"]', password);
    }

    const nsfwBox = page.locator('input[name="is_nsfw"]');
    await nsfwBox.scrollIntoViewIfNeeded();
    if (nsfw && !(await nsfwBox.isChecked())) {
      await nsfwBox.check({ force: true });
      await page.waitForTimeout(400); // settle: toggle triggers section re-render
    } else if (!nsfw && (await nsfwBox.isChecked())) {
      await nsfwBox.uncheck({ force: true });
      await page.waitForTimeout(400); // settle: toggle triggers section re-render
    }

    await page.click('button[type=\"submit\"][form=\"album-form\"]');
    // Poll the URL instead of waiting for a navigation event: the admin
    // CSRF-refresh handler swallows and re-dispatches the submit, and the
    // resulting navigation can slip past waitForURL's event window.
    await expect(page).toHaveURL(/\/admin\/albums\/?(\?.*)?$/, { timeout: 15000 });

    // Find the album in the list
    // The post-submit redirect already landed on /admin/albums (waitForURL above).
    // Re-navigating to the same URL races the still-loading page ("interrupted
    // by another navigation"); only navigate if we are somewhere else.
    if (!/\/admin\/albums\/?(\?.*)?$/.test(page.url())) {
      await page.goto(`${BASE}/admin/albums`, { waitUntil: 'domcontentloaded' });
    }
    const link = page.locator(`a:has-text("${title}")`).first();
    const href = await link.getAttribute('href');
    const id = href?.match(/\/albums\/(\d+)/)?.[1];
    expect(id).toBeTruthy();

    // Get slug from edit page
    await page.goto(`${BASE}/admin/albums/${id}/edit`, { waitUntil: 'domcontentloaded' });
    const slugInput = page.locator('input[name="slug"]');
    const slug = await slugInput.inputValue();

    return { id, slug };
  }

  /** Clear all caches via admin panel */
  async function clearCaches(page) {
    await page.goto(`${BASE}/admin/cache`, { waitUntil: 'domcontentloaded' });
    const clearForm = page.locator('form[action$="/admin/cache/clear-everything"]');
    await clearForm.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');
  }

  // ─── setup & teardown ─────────────────────────────────────────────

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

  // ─── setup: create albums ─────────────────────────────────────────

  test('Login to admin', async () => {
    await admin.goto(`${BASE}/admin/login`, { waitUntil: 'domcontentloaded' });
    await admin.fill('input[name="email"]', ADMIN_EMAIL);
    await admin.fill('input[name="password"]', ADMIN_PASSWORD);
    await admin.click('button[type="submit"]');
    // Poll the URL instead of waiting for a navigation event: the admin
    // CSRF-refresh handler swallows and re-dispatches the submit, and the
    // resulting navigation can slip past waitForURL's event window.
    await expect(admin).toHaveURL(/\/admin\/?(\?.*)?$/, { timeout: 10000 });
    await admin.screenshot({ path: `${SCREENSHOTS}/00-login.png`, fullPage: true });
  });

  test('Create 4 test albums with images', async () => {
    test.setTimeout(120000);
    const regular = await createAlbum(admin, REGULAR_NAME);
    regularId = regular.id; regularSlug = regular.slug;
    console.log(`Regular: id=${regularId} slug=${regularSlug}`);

    const nsfw = await createAlbum(admin, NSFW_NAME, { nsfw: true });
    nsfwId = nsfw.id; nsfwSlug = nsfw.slug;
    console.log(`NSFW: id=${nsfwId} slug=${nsfwSlug}`);

    const pwd = await createAlbum(admin, PWD_NAME, { password: ALBUM_PASSWORD });
    pwdId = pwd.id; pwdSlug = pwd.slug;
    console.log(`Pwd: id=${pwdId} slug=${pwdSlug}`);

    const pwdNsfw = await createAlbum(admin, PWD_NSFW_NAME, { nsfw: true, password: ALBUM_PASSWORD });
    pwdNsfwId = pwdNsfw.id; pwdNsfwSlug = pwdNsfw.slug;
    console.log(`PwdNsfw: id=${pwdNsfwId} slug=${pwdNsfwSlug}`);

    // Upload images
    await uploadAndSetCover(admin, regularId, 'REG', '#22aa44');
    await uploadAndSetCover(admin, nsfwId, 'NSFW', '#cc3333');
    await uploadAndSetCover(admin, pwdId, 'PWD', '#3366cc');
    await uploadAndSetCover(admin, pwdNsfwId, 'PWDNSFW', '#cc6600');

    await admin.screenshot({ path: `${SCREENSHOTS}/01-albums-created.png`, fullPage: true });
  });

  test('Clear caches', async () => {
    await clearCaches(admin);
  });

  // ═══════════════════════════════════════════════════════════════════
  // HOME PAGE — CLASSIC TEMPLATE
  // ═══════════════════════════════════════════════════════════════════

  test('Home classic: anonymous sees only the regular album (protected filtered out)', async () => {
    // The home now shows ONLY real covers — protected albums are excluded at SQL
    // level, never previewed blurred (product decision: blur/lock belong on album
    // & category pages, not the home flow).
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const pg = await ctx.newPage();
    await pg.goto(`${BASE}/?template=classic`);
    await pg.waitForTimeout(2000);
    await pg.screenshot({ path: `${SCREENSHOTS}/10-home-classic-anon.png`, fullPage: true });

    // Regular album — visible, clear, no blur, no overlay, no lock.
    const regCard = pg.locator(`article.album-card[data-album-id="${regularId}"]`).first();
    await expect(regCard).toBeVisible({ timeout: 5000 });
    expect(await regCard.getAttribute('data-nsfw')).toBeNull();
    expect(await regCard.locator('img.nsfw-blur').count()).toBe(0);
    expect(await regCard.locator('.nsfw-overlay').count()).toBe(0);
    expect(await regCard.locator('.fa-lock').count()).toBe(0);
    console.log('Home classic anon: Regular = clear, no blur, no lock');

    // NSFW (no consent), password and password+NSFW albums must be ABSENT from
    // the home for an anonymous visitor — no blurred placeholders at all.
    expect(await pg.locator(`article.album-card[data-album-id="${nsfwId}"]`).count()).toBe(0);
    expect(await pg.locator(`article.album-card[data-album-id="${pwdId}"]`).count()).toBe(0);
    expect(await pg.locator(`article.album-card[data-album-id="${pwdNsfwId}"]`).count()).toBe(0);
    console.log('Home classic anon: NSFW/Password/Pwd+NSFW correctly absent (no blurred previews)');

    await ctx.close();
  });

  test('Home classic: admin sees all clear, no blur', async () => {
    // The classic home renders album CARDS (hero + grid). Admin sees every album
    // card — including protected ones — rendered clear (no blur/overlay). The
    // anonymous SQL-level exclusion applies to the public home flow, not the
    // admin's full album listing.
    await admin.goto(`${BASE}/?template=classic`);
    await admin.waitForTimeout(1500);
    await admin.screenshot({ path: `${SCREENSHOTS}/11-home-classic-admin.png`, fullPage: true });

    for (const id of [regularId, nsfwId, pwdId, pwdNsfwId]) {
      const card = admin.locator(`article.album-card[data-album-id="${id}"]`).first();
      await expect(card).toBeVisible();
      // Admin: no data-nsfw attribute, no blur, no overlay
      expect(await card.getAttribute('data-nsfw')).toBeNull();
      expect(await card.locator('img.nsfw-blur').count()).toBe(0);
      expect(await card.locator('.nsfw-overlay').count()).toBe(0);
    }
    console.log('Home classic admin: all 4 albums visible, no blur, no overlay');
  });

  // ═══════════════════════════════════════════════════════════════════
  // HOME PAGE — MASONRY TEMPLATE
  // ═══════════════════════════════════════════════════════════════════

  test('Home masonry: anonymous sees only regular album images', async () => {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const pg = await ctx.newPage();
    await pg.goto(`${BASE}/?template=masonry`);
    await pg.waitForTimeout(2000);
    await pg.screenshot({ path: `${SCREENSHOTS}/12-home-masonry-anon.png`, fullPage: true });

    // Images link to /album/{slug} — check which album slugs appear
    const regLink = pg.locator(`a[href*="/album/${regularSlug}"]`);
    const nsfwLink = pg.locator(`a[href*="/album/${nsfwSlug}"]`);
    const pwdLink = pg.locator(`a[href*="/album/${pwdSlug}"]`);
    const pnLink = pg.locator(`a[href*="/album/${pwdNsfwSlug}"]`);

    // Regular: images should appear
    expect(await regLink.count()).toBeGreaterThan(0);
    console.log(`Home masonry anon: Regular images present (${await regLink.count()} links)`);

    // NSFW: images filtered out (no consent)
    expect(await nsfwLink.count()).toBe(0);
    console.log('Home masonry anon: NSFW images filtered out');

    // Password: images always filtered out
    expect(await pwdLink.count()).toBe(0);
    console.log('Home masonry anon: Password images filtered out');

    // Password+NSFW: images always filtered out
    expect(await pnLink.count()).toBe(0);
    console.log('Home masonry anon: Pwd+NSFW images filtered out');

    await ctx.close();
  });

  test('Home masonry: admin sees regular + NSFW images (password always excluded)', async () => {
    await admin.goto(`${BASE}/?template=masonry`);
    await admin.waitForTimeout(2000);
    await admin.screenshot({ path: `${SCREENSHOTS}/13-home-masonry-admin.png`, fullPage: true });

    // Admin with includeNsfw=true: regular + NSFW images visible
    const regLinks = admin.locator(`a[href*="/album/${regularSlug}"]`);
    expect(await regLinks.count()).toBeGreaterThan(0);
    const nsfwLinks = admin.locator(`a[href*="/album/${nsfwSlug}"]`);
    expect(await nsfwLinks.count()).toBeGreaterThan(0);
    console.log('Home masonry admin: regular + NSFW images present');

    // Password-protected album images are ALWAYS excluded at SQL level
    // (password_hash IS NULL OR password_hash = '') — regardless of user
    const pwdLinks = admin.locator(`a[href*="/album/${pwdSlug}"]`);
    expect(await pwdLinks.count()).toBe(0);
    const pnLinks = admin.locator(`a[href*="/album/${pwdNsfwSlug}"]`);
    expect(await pnLinks.count()).toBe(0);
    console.log('Home masonry admin: password album images correctly excluded');
  });

  // ═══════════════════════════════════════════════════════════════════
  // GALLERIES PAGE
  // ═══════════════════════════════════════════════════════════════════

  test('Galleries: anonymous sees blur/overlay correctly', async () => {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const pg = await ctx.newPage();
    await pg.goto(`${BASE}/galleries`);
    await pg.waitForTimeout(2000);
    await pg.screenshot({ path: `${SCREENSHOTS}/20-galleries-anon.png`, fullPage: true });

    // Galleries uses gallery-card class, data-nsfw="0"/"1" always present
    // Regular — clear, data-nsfw="0"
    const regCard = pg.locator(`article[data-album-id="${regularId}"]`).first();
    await expect(regCard).toBeVisible({ timeout: 5000 });
    expect(await regCard.getAttribute('data-nsfw')).toBe('0');
    expect(await regCard.locator('img.nsfw-blur').count()).toBe(0);
    expect(await regCard.locator('.nsfw-overlay').count()).toBe(0);
    console.log('Galleries anon: Regular = clear');

    // NSFW — blurred + overlay, data-nsfw="1"
    const nsfwCard = pg.locator(`article[data-album-id="${nsfwId}"]`).first();
    await expect(nsfwCard).toBeVisible({ timeout: 5000 });
    expect(await nsfwCard.getAttribute('data-nsfw')).toBe('1');
    expect(await nsfwCard.locator('img.nsfw-blur').count()).toBeGreaterThan(0);
    expect(await nsfwCard.locator('.nsfw-overlay').count()).toBeGreaterThan(0);
    console.log('Galleries anon: NSFW = blurred + overlay');

    // Password — locked overlay + lock badge (no blur variant for non-NSFW → gradient placeholder or CSS blur)
    const pwdCard = pg.locator(`article[data-album-id="${pwdId}"]`).first();
    await expect(pwdCard).toBeVisible({ timeout: 5000 });
    expect(await pwdCard.getAttribute('data-password-protected')).toBe('1');
    // Lock overlay shown (uses nsfw-overlay class with fa-lock icon)
    expect(await pwdCard.locator('.fa-lock').count()).toBeGreaterThan(0);
    expect(await pwdCard.locator('.nsfw-overlay').count()).toBeGreaterThan(0);
    console.log('Galleries anon: Password = locked overlay + lock badge');

    // Password+NSFW — blurred + NSFW overlay + lock
    const pnCard = pg.locator(`article[data-album-id="${pwdNsfwId}"]`).first();
    await expect(pnCard).toBeVisible({ timeout: 5000 });
    expect(await pnCard.getAttribute('data-nsfw')).toBe('1');
    expect(await pnCard.getAttribute('data-password-protected')).toBe('1');
    expect(await pnCard.locator('img.nsfw-blur').count()).toBeGreaterThan(0);
    expect(await pnCard.locator('.nsfw-overlay').count()).toBeGreaterThan(0);
    expect(await pnCard.locator('.fa-lock').count()).toBeGreaterThan(0);
    console.log('Galleries anon: Pwd+NSFW = blurred + overlay + lock');

    await ctx.close();
  });

  test('Galleries: admin sees all clear', async () => {
    await admin.goto(`${BASE}/galleries`);
    await admin.waitForTimeout(1500);
    await admin.screenshot({ path: `${SCREENSHOTS}/21-galleries-admin.png`, fullPage: true });

    for (const id of [regularId, nsfwId, pwdId, pwdNsfwId]) {
      const card = admin.locator(`article[data-album-id="${id}"]`).first();
      await expect(card).toBeVisible();
      expect(await card.getAttribute('data-nsfw')).toBe('0');
      expect(await card.locator('img.nsfw-blur').count()).toBe(0);
      expect(await card.locator('.nsfw-overlay').count()).toBe(0);
    }
    console.log('Galleries admin: all 4 albums visible, no blur');
  });

  // ═══════════════════════════════════════════════════════════════════
  // SINGLE ALBUM — NSFW GATE
  // ═══════════════════════════════════════════════════════════════════

  test('Album NSFW: anonymous sees gate', async () => {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const pg = await ctx.newPage();
    await pg.goto(`${BASE}/album/${nsfwSlug}`);
    await pg.waitForTimeout(1500);
    await pg.screenshot({ path: `${SCREENSHOTS}/30-album-nsfw-gate.png`, fullPage: true });

    // NSFW gate is a full-page template (nsfw_gate.twig) with a confirm form
    const gateForm = pg.locator('#nsfw-confirm-form');
    await expect(gateForm).toBeVisible({ timeout: 5000 });
    const nsfwCheckbox = pg.locator('#nsfw_confirmed');
    await expect(nsfwCheckbox).toBeVisible();
    console.log('Album NSFW: gate form + checkbox visible');

    // Album content should NOT be visible (gate page replaces album page)
    const albumImages = pg.locator('#album-main-content');
    expect(await albumImages.count()).toBe(0);
    console.log('Album NSFW: album content not rendered');

    await ctx.close();
  });

  test('Album NSFW: after consent, full album visible', async () => {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const pg = await ctx.newPage();
    await pg.goto(`${BASE}/album/${nsfwSlug}`);
    await pg.waitForTimeout(1500);

    // Check the NSFW confirmation checkbox and submit
    await pg.check('#nsfw_confirmed');
    await pg.click('#nsfw-confirm-form button[type="submit"]');
    await expect(pg).toHaveURL(new RegExp(`/album/${nsfwSlug}`), { timeout: 10000 });
    await pg.waitForTimeout(1500);
    await pg.screenshot({ path: `${SCREENSHOTS}/31-album-nsfw-consented.png`, fullPage: true });

    // Gate form should NOT be present anymore
    expect(await pg.locator('#nsfw-confirm-form').count()).toBe(0);
    console.log('Album NSFW: after consent, gate gone');

    // Should see images in the album
    const images = pg.locator('picture img, .gallery-item img, #album-main-content img');
    expect(await images.count()).toBeGreaterThan(0);
    console.log(`Album NSFW: ${await images.count()} images visible after consent`);

    await ctx.close();
  });

  test('Album NSFW: admin sees directly, no gate', async () => {
    await admin.goto(`${BASE}/album/${nsfwSlug}`);
    await admin.waitForTimeout(1500);
    await admin.screenshot({ path: `${SCREENSHOTS}/32-album-nsfw-admin.png`, fullPage: true });

    // No gate form
    expect(await admin.locator('#nsfw-confirm-form').count()).toBe(0);
    // Album content is visible
    const images = admin.locator('picture img, .gallery-item img, #album-main-content img');
    expect(await images.count()).toBeGreaterThan(0);
    console.log(`Album NSFW: admin sees ${await images.count()} images, no gate`);
  });

  // ═══════════════════════════════════════════════════════════════════
  // SINGLE ALBUM — PASSWORD GATE
  // ═══════════════════════════════════════════════════════════════════

  test('Album password: anonymous sees password form', async () => {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const pg = await ctx.newPage();
    await pg.goto(`${BASE}/album/${pwdSlug}`);
    await pg.waitForTimeout(1500);
    await pg.screenshot({ path: `${SCREENSHOTS}/40-album-pwd-gate.png`, fullPage: true });

    // Password form should be visible
    const pwdInput = pg.locator('input[name="password"][type="password"]');
    await expect(pwdInput).toBeVisible({ timeout: 5000 });
    console.log('Album password: password input visible');

    // No NSFW checkbox (this album is not NSFW)
    const nsfwCheckbox = pg.locator('input[name="nsfw_confirmed"]');
    expect(await nsfwCheckbox.count()).toBe(0);
    console.log('Album password: no NSFW checkbox (not NSFW)');

    await ctx.close();
  });

  test('Album password: wrong password shows error', async () => {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const pg = await ctx.newPage();
    await pg.goto(`${BASE}/album/${pwdSlug}`);
    await pg.waitForTimeout(1000);

    await pg.fill('input[name="password"]', 'wrongpassword');
    await pg.click('#album-password-form button[type="submit"]');
    await pg.waitForTimeout(1500);
    await pg.screenshot({ path: `${SCREENSHOTS}/41-album-pwd-wrong.png`, fullPage: true });

    // Should still show password form (not album content)
    const pwdInput = pg.locator('input[name="password"][type="password"]');
    await expect(pwdInput).toBeVisible({ timeout: 5000 });
    console.log('Album password: wrong password → still on gate');

    await ctx.close();
  });

  test('Album password: correct password shows album', async () => {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const pg = await ctx.newPage();
    await pg.goto(`${BASE}/album/${pwdSlug}`);
    await pg.waitForTimeout(1000);

    await pg.fill('input[name="password"]', ALBUM_PASSWORD);
    await pg.click('#album-password-form button[type="submit"]');
    await expect(pg).toHaveURL(new RegExp(`/album/${pwdSlug}`), { timeout: 10000 });
    await pg.waitForTimeout(1500);
    await pg.screenshot({ path: `${SCREENSHOTS}/42-album-pwd-unlocked.png`, fullPage: true });

    // Password form should NOT be visible anymore
    const pwdInput = pg.locator('input[name="password"][type="password"]');
    expect(await pwdInput.isVisible().catch(() => false)).toBe(false);
    console.log('Album password: correct password → album content visible');

    // Should see images
    const images = pg.locator('#album-main-content img, .gallery-item img, picture img');
    expect(await images.count()).toBeGreaterThan(0);
    console.log(`Album password: ${await images.count()} images visible after unlock`);

    await ctx.close();
  });

  test('Album password: admin sees directly, no gate', async () => {
    await admin.goto(`${BASE}/album/${pwdSlug}`);
    await admin.waitForTimeout(1500);
    await admin.screenshot({ path: `${SCREENSHOTS}/43-album-pwd-admin.png`, fullPage: true });

    const pwdInput = admin.locator('input[name="password"][type="password"]');
    expect(await pwdInput.isVisible().catch(() => false)).toBe(false);
    console.log('Album password: admin sees no gate');
  });

  // ═══════════════════════════════════════════════════════════════════
  // SINGLE ALBUM — PASSWORD + NSFW GATE
  // ═══════════════════════════════════════════════════════════════════

  test('Album password+NSFW: anonymous sees password form with NSFW checkbox', async () => {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const pg = await ctx.newPage();
    await pg.goto(`${BASE}/album/${pwdNsfwSlug}`);
    await pg.waitForTimeout(1500);
    await pg.screenshot({ path: `${SCREENSHOTS}/50-album-pwdnsfw-gate.png`, fullPage: true });

    // Password form visible
    const pwdInput = pg.locator('input[name="password"][type="password"]');
    await expect(pwdInput).toBeVisible({ timeout: 5000 });

    // NSFW confirmation checkbox should also be present
    const nsfwCheckbox = pg.locator('input[name="nsfw_confirmed"]');
    expect(await nsfwCheckbox.count()).toBeGreaterThan(0);
    console.log('Album pwd+NSFW: password input + NSFW checkbox visible');

    await ctx.close();
  });

  test('Album password+NSFW: password only (no NSFW check) redirects back', async () => {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const pg = await ctx.newPage();
    await pg.goto(`${BASE}/album/${pwdNsfwSlug}`, { waitUntil: 'domcontentloaded' });
    await pg.waitForTimeout(1500);

    await pg.fill('input[name="password"]', ALBUM_PASSWORD);
    // Do NOT check nsfw_confirmed — submit password-only
    await pg.click('#album-password-form button[type="submit"]');
    await pg.waitForTimeout(2000);
    await pg.screenshot({ path: `${SCREENSHOTS}/51-album-pwdnsfw-no-nsfw.png`, fullPage: true });

    // Should redirect back with error (URL contains ?error=nsfw or still on gate)
    const pwdInput = pg.locator('input[name="password"][type="password"]');
    const stillOnGate = await pwdInput.isVisible().catch(() => false);
    const url = pg.url();
    const hasNsfwError = url.includes('error=nsfw') || stillOnGate;
    expect(hasNsfwError).toBe(true);
    console.log('Album pwd+NSFW: password without NSFW consent → rejected');

    await ctx.close();
  });

  test('Album password+NSFW: password + NSFW consent → full access', async () => {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const pg = await ctx.newPage();
    await pg.goto(`${BASE}/album/${pwdNsfwSlug}`, { waitUntil: 'domcontentloaded' });
    await pg.waitForTimeout(1000);

    await pg.fill('input[name="password"]', ALBUM_PASSWORD);
    const nsfwCheckbox = pg.locator('input[name="nsfw_confirmed"]');
    if (await nsfwCheckbox.count() > 0) await nsfwCheckbox.check();
    await pg.click('#album-password-form button[type="submit"]');
    await expect(pg).toHaveURL(new RegExp(`/album/${pwdNsfwSlug}`), { timeout: 10000 });
    await pg.waitForTimeout(1500);
    await pg.screenshot({ path: `${SCREENSHOTS}/52-album-pwdnsfw-unlocked.png`, fullPage: true });

    // Should see album content, not gate
    const pwdInput = pg.locator('input[name="password"][type="password"]');
    expect(await pwdInput.isVisible().catch(() => false)).toBe(false);

    const images = pg.locator('#album-main-content img, .gallery-item img, picture img');
    expect(await images.count()).toBeGreaterThan(0);
    console.log(`Album pwd+NSFW: full access, ${await images.count()} images visible`);

    await ctx.close();
  });

  test('Album password+NSFW: admin sees directly', async () => {
    await admin.goto(`${BASE}/album/${pwdNsfwSlug}`);
    await admin.waitForTimeout(1500);
    await admin.screenshot({ path: `${SCREENSHOTS}/53-album-pwdnsfw-admin.png`, fullPage: true });

    // No password form and no NSFW gate
    expect(await admin.locator('input[name="password"][type="password"]').count()).toBe(0);
    expect(await admin.locator('#nsfw-confirm-form').count()).toBe(0);
    // Album content visible
    const images = admin.locator('picture img, .gallery-item img, #album-main-content img');
    expect(await images.count()).toBeGreaterThan(0);
    console.log(`Album pwd+NSFW: admin sees ${await images.count()} images, no gate`);
  });

  // ═══════════════════════════════════════════════════════════════════
  // SINGLE ALBUM — REGULAR (no gates)
  // ═══════════════════════════════════════════════════════════════════

  test('Album regular: anonymous sees directly', async () => {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const pg = await ctx.newPage();
    await pg.goto(`${BASE}/album/${regularSlug}`);
    await pg.waitForTimeout(1500);
    await pg.screenshot({ path: `${SCREENSHOTS}/60-album-regular-anon.png`, fullPage: true });

    // No gate
    const pwdInput = pg.locator('input[name="password"][type="password"]');
    expect(await pwdInput.isVisible().catch(() => false)).toBe(false);
    const gate = pg.locator('#nsfw-gate');
    expect(await gate.isVisible().catch(() => false)).toBe(false);

    // Images visible
    const images = pg.locator('#album-main-content img, .gallery-item img, picture img');
    expect(await images.count()).toBeGreaterThan(0);
    console.log(`Album regular: anonymous sees content, ${await images.count()} images`);

    await ctx.close();
  });
});
