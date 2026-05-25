// @ts-check
import { test, expect, chromium } from '@playwright/test';
import { skipIfInstalled } from './_install-guard.js';
import { BASE } from './_helpers.js';

test.describe.serial('Full Cimaise Install + NSFW Test', () => {
  /** @type {import('@playwright/test').Browser} */
  let browser;
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;

  // Skip check + browser launch must happen in the SAME beforeAll: when
  // skipIfInstalled() calls test.skip(true, ...), Playwright marks
  // subsequent hooks as skipped — but the browser/context/page locals
  // would remain undefined and any test that still runs (under
  // PLAYWRIGHT_RESET_INSTALL=1) would TypeError on .newContext().
  test.beforeAll(async () => {
    skipIfInstalled(test);
    browser = await chromium.launch();
    context = await browser.newContext();
    page = await context.newPage();
  });

  test.afterAll(async () => {
    if (context) await context.close();
    if (browser) await browser.close();
  });

  test('Step 1: Installer requirements page', async () => {
    // Start from root URL like a real user — must redirect to installer.php
    await page.goto(`${BASE}/`);
    await expect(page).toHaveURL(/installer\.php/, { timeout: 5000 });
    await expect(page.locator('text=System Requirements')).toBeVisible();
    // All checks should be green
    await expect(page.locator('.check-icon.error')).toHaveCount(0);
    // Click continue
    await page.click('a:has-text("Continue to Database Setup")');
    await expect(page).toHaveURL(/step=database/);
  });

  test('Step 2: Database configuration (MySQL)', async () => {
    // Select MySQL (SQLite is default)
    await page.click('input[value="mysql"]');
    // Wait for MySQL fields to become visible
    await expect(page.locator('#mysql-config')).toBeVisible();
    // Fill MySQL fields
    await page.fill('input[name="db_host"]', process.env.TEST_MYSQL_HOST || '127.0.0.1');
    await page.fill('input[name="db_port"]', process.env.TEST_MYSQL_PORT || '3306');
    await page.fill('input[name="db_database"]', process.env.TEST_MYSQL_DATABASE || 'cimaise_test');
    await page.fill('input[name="db_username"]', process.env.TEST_MYSQL_USERNAME || 'root');
    await page.fill('input[name="db_password"]', process.env.TEST_MYSQL_PASSWORD || '');
    // Submit
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(/step=admin/, { timeout: 15000 });
  });

  test('Step 3: Admin user setup', async () => {
    await page.fill('input[name="admin_name"]', 'Admin');
    await page.fill('input[name="admin_email"]', 'admin@test.com');
    await page.fill('input[name="admin_password"]', 'TestPass123!');
    await page.fill('input[name="admin_password_confirm"]', 'TestPass123!');
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(/step=settings/, { timeout: 10000 });
  });

  test('Step 4: Site settings', async () => {
    await page.fill('input[name="site_title"]', 'Test Cimaise');
    await page.fill('textarea[name="site_description"]', 'Test Photography Portfolio');
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(/step=install/, { timeout: 10000 });
  });

  test('Step 5: Run installation', async () => {
    // Click install button
    await page.click('button:has-text("Install")');
    // Wait for completion page
    await expect(page.locator('text=Installation Complete')).toBeVisible({ timeout: 30000 });
  });

  test('Step 6: Login to admin', async () => {
    // New context for post-install tests (installer session destroyed)
    await page.goto(`${BASE}/admin/login`);
    await page.fill('input[name="email"]', 'admin@test.com');
    await page.fill('input[name="password"]', 'TestPass123!');
    await page.click('button[type="submit"]');
    // Should redirect to admin dashboard
    await expect(page).toHaveURL(/\/admin/, { timeout: 10000 });
    await expect(page.locator('text=Dashboard').first()).toBeVisible({ timeout: 5000 });
  });

  test('Step 7: Create a test album', async () => {
    // Go to albums page
    await page.goto(`${BASE}/admin/albums`);
    await expect(page).toHaveURL(/\/admin\/albums/);

    // Click create album
    await page.click('a:has-text("New Album"), a:has-text("Create Album"), a:has-text("Add Album")');
    await expect(page).toHaveURL(/\/admin\/albums\/create/, { timeout: 5000 });

    // Fill album form
    await page.fill('input[name="title"]', 'Test NSFW Album');

    // Select a category if dropdown exists
    const catSelect = page.locator('select[name="category_id"]');
    if (await catSelect.isVisible()) {
      const options = await catSelect.locator('option').all();
      if (options.length > 1) {
        await catSelect.selectOption({ index: 1 });
      }
    }

    // Enable NSFW
    const nsfwCheckbox = page.locator('input[name="is_nsfw"]');
    if (await nsfwCheckbox.isVisible()) {
      await nsfwCheckbox.check();
    }

    // Save album — wait for the real redirect (controller sends the user
    // back to /admin/albums or to /admin/albums/{id}/edit on success) so
    // the listing is guaranteed to be populated before Step 8 looks the
    // album up. waitForTimeout(2000) here would race the slugifier.
    await Promise.all([
      page.waitForURL(/\/admin\/albums(\/\d+\/edit)?(?:\?.*)?$/, { timeout: 15000 }),
      page.click('button:has-text("Create Album"):visible'),
    ]);
  });

  test('Step 8: Verify NSFW album gate on frontend', async () => {
    // Discover the actual slug from the admin albums list — relying on the
    // raw title-derived slug ("test-nsfw-album") would be wrong if the
    // slugifier inserts a numeric suffix to avoid duplicates.
    await page.goto(`${BASE}/admin/albums`);
    const albumLink = page.locator('a:has-text("Test NSFW Album")').first();
    await expect(albumLink).toBeVisible({ timeout: 10000 });
    const href = await albumLink.getAttribute('href');
    const idMatch = href ? href.match(/\/admin\/albums\/(\d+)/) : null;
    expect(idMatch).not.toBeNull();
    await page.goto(`${BASE}/admin/albums/${idMatch[1]}/edit`);
    const slug = await page.locator('input[name="slug"]').inputValue();
    expect(slug).toBeTruthy();

    // Open a new page in a separate context (no session cookies) to test as visitor
    const visitorContext = await browser.newContext();
    const visitorPage = await visitorContext.newPage();
    await visitorPage.goto(`${BASE}/album/${slug}`);

    // Check for NSFW gate - should show age warning or content notice.
    // Hard-assert so a missing gate fails the test (was previously a silent log).
    const body = await visitorPage.textContent('body');
    const hasWarning = /adult|nsfw|18\+|mature|age.?verif|content.?warning/i.test(body || '');
    try {
        expect(hasWarning).toBe(true);
    } finally {
        await visitorPage.close();
        await visitorContext.close();
    }
  });

  test('Step 9: Verify homepage loads', async () => {
    // Use a clean visitor page
    const visitorContext = await browser.newContext();
    const visitorPage = await visitorContext.newPage();
    await visitorPage.goto(`${BASE}/`);
    // Should load without errors
    await expect(visitorPage).toHaveURL(`${BASE}/`);
    const status = await visitorPage.evaluate(() => document.readyState);
    expect(status).toBe('complete');
    // Should have the site title somewhere
    await expect(visitorPage.locator('text=Test Cimaise').first()).toBeVisible({ timeout: 5000 });
    await visitorPage.close();
    await visitorContext.close();
  });
});
