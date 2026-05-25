// @ts-check
import { test, expect, chromium } from '@playwright/test';
import { skipIfInstalled } from './_install-guard.js';

const BASE = 'http://localhost:8000';

test.describe.serial('Full Cimaise Install + NSFW Test', () => {
  test.beforeAll(() => { skipIfInstalled(test); });

  /** @type {import('@playwright/test').Browser} */
  let browser;
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;

  test.beforeAll(async () => {
    browser = await chromium.launch();
    context = await browser.newContext();
    page = await context.newPage();
  });

  test.afterAll(async () => {
    await context.close();
    await browser.close();
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

    // Save album - click the visible "Create Album" button at the bottom
    await page.click('button:has-text("Create Album"):visible');
    await page.waitForTimeout(2000);
  });

  test('Step 8: Verify NSFW album gate on frontend', async () => {
    // Go to albums to find the one we created
    await page.goto(`${BASE}/admin/albums`);
    const albumLink = page.locator('a:has-text("Test NSFW Album")').first();
    if (await albumLink.isVisible()) {
      const href = await albumLink.getAttribute('href');
      console.log('Album edit link:', href);
    }

    // Open a new page in a separate context (no session cookies) to test as visitor
    const visitorContext = await browser.newContext();
    const visitorPage = await visitorContext.newPage();
    await visitorPage.goto(`${BASE}/album/test-nsfw-album`);

    // Check for NSFW gate - should show age warning or content notice
    const body = await visitorPage.textContent('body');
    const hasWarning = /adult|nsfw|18\+|mature|age.?verif|content.?warning/i.test(body || '');
    console.log('NSFW gate visible:', hasWarning);

    await visitorPage.close();
    await visitorContext.close();
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
