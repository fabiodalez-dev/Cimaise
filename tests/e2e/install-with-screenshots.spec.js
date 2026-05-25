// @ts-check
import { test, expect, chromium } from '@playwright/test';
import { skipIfInstalled } from './_install-guard.js';
import { BASE } from './_helpers.js';

const SCREENSHOTS = 'test-results/screenshots';

test.describe.serial('Full Install with Screenshots', () => {
  /** @type {import('@playwright/test').Browser} */
  let browser;
  /** @type {import('@playwright/test').BrowserContext} */
  let context;
  /** @type {import('@playwright/test').Page} */
  let page;

  // Skip check + browser launch in the same beforeAll, see the comment on
  // full-install-test.spec.js for the rationale.
  test.beforeAll(async () => {
    skipIfInstalled(test);
    browser = await chromium.launch();
    context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    page = await context.newPage();
  });

  test.afterAll(async () => {
    if (context) await context.close();
    if (browser) await browser.close();
  });

  test('Step 1: Homepage redirects to installer', async () => {
    await page.goto(`${BASE}/`);
    await expect(page).toHaveURL(/installer\.php/, { timeout: 5000 });
    await expect(page.locator('text=System Requirements')).toBeVisible();
    await expect(page.locator('.check-icon.error')).toHaveCount(0);
    await page.screenshot({ path: `${SCREENSHOTS}/01-requirements.png`, fullPage: true });
    await page.click('a:has-text("Continue to Database Setup")');
    await expect(page).toHaveURL(/step=database/);
  });

  test('Step 2: Database page — verify all fields exist', async () => {
    // Verify MySQL radio exists
    await expect(page.locator('input[value="mysql"]')).toBeVisible();
    await page.click('input[value="mysql"]');
    await expect(page.locator('#mysql-config')).toBeVisible();

    // Verify ALL form fields exist and are visible
    await expect(page.locator('input[name="db_host"]')).toBeVisible();
    await expect(page.locator('input[name="db_port"]')).toBeVisible();
    await expect(page.locator('input[name="db_database"]')).toBeVisible();
    await expect(page.locator('input[name="db_username"]')).toBeVisible();
    await expect(page.locator('input[name="db_password"]')).toBeVisible();

    // Verify defaults
    const hostVal = await page.locator('input[name="db_host"]').inputValue();
    const portVal = await page.locator('input[name="db_port"]').inputValue();
    console.log(`Host default: "${hostVal}", Port default: "${portVal}"`);

    await page.screenshot({ path: `${SCREENSHOTS}/02-database-mysql-fields.png`, fullPage: true });

    // Fill credentials (env-driven; no hardcoded production secrets)
    await page.fill('input[name="db_host"]', process.env.TEST_MYSQL_HOST || '127.0.0.1');
    await page.fill('input[name="db_port"]', process.env.TEST_MYSQL_PORT || '3306');
    await page.fill('input[name="db_database"]', process.env.TEST_MYSQL_DATABASE || 'cimaise_test');
    await page.fill('input[name="db_username"]', process.env.TEST_MYSQL_USERNAME || 'root');
    await page.fill('input[name="db_password"]', process.env.TEST_MYSQL_PASSWORD || '');

    await page.screenshot({ path: `${SCREENSHOTS}/03-database-filled.png`, fullPage: true });
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(/step=admin/, { timeout: 15000 });
  });

  test('Step 3: Admin user', async () => {
    await page.fill('input[name="admin_name"]', 'Admin');
    await page.fill('input[name="admin_email"]', 'admin@test.com');
    await page.fill('input[name="admin_password"]', 'TestPass123!');
    await page.fill('input[name="admin_password_confirm"]', 'TestPass123!');
    await page.screenshot({ path: `${SCREENSHOTS}/04-admin-filled.png`, fullPage: true });
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(/step=settings/, { timeout: 10000 });
  });

  test('Step 4: Site settings — verify all fields and fill', async () => {
    // === Verify all fields exist ===

    // Site Identity
    await expect(page.locator('input[name="site_title"]')).toBeVisible();
    await expect(page.locator('textarea[name="site_description"]')).toBeVisible();
    await expect(page.locator('input[name="site_logo"]')).toBeAttached(); // file input
    await expect(page.locator('input[name="site_email"]')).toBeVisible();
    await expect(page.locator('input[name="site_copyright"]')).toBeVisible();

    // Language & Format
    await expect(page.locator('select[name="site_language"]')).toBeVisible();
    await expect(page.locator('select[name="admin_language"]')).toBeVisible();
    await expect(page.locator('select[name="date_format"]')).toBeVisible();
    await expect(page.locator('select[name="timezone"]')).toBeVisible();

    // Templates
    await expect(page.locator('select[name="home_template"]')).toBeVisible();
    await expect(page.locator('select[name="gallery_template_id"]')).toBeVisible();

    // Performance checkboxes
    await expect(page.locator('input[name="cache_enabled"]')).toBeAttached();
    await expect(page.locator('input[name="compression_enabled"]')).toBeAttached();

    await page.screenshot({ path: `${SCREENSHOTS}/05a-settings-empty.png`, fullPage: true });

    // === Fill all fields ===

    // Site Identity
    await page.fill('input[name="site_title"]', 'Test Cimaise');
    await page.fill('textarea[name="site_description"]', 'Test Photography Portfolio');
    await page.fill('input[name="site_email"]', 'contact@test-cimaise.com');
    await page.fill('input[name="site_copyright"]', '© {year} Test Cimaise');

    // Language & Format
    await page.selectOption('select[name="site_language"]', 'it');
    await page.selectOption('select[name="admin_language"]', 'it');
    await page.selectOption('select[name="date_format"]', 'd-m-Y');
    await page.selectOption('select[name="timezone"]', 'Europe/Rome');

    // Templates
    await page.selectOption('select[name="home_template"]', 'masonry');
    await page.selectOption('select[name="gallery_template_id"]', '2');

    // Performance — verify they're checked by default, then leave them on
    await expect(page.locator('input[name="cache_enabled"]')).toBeChecked();
    await expect(page.locator('input[name="compression_enabled"]')).toBeChecked();

    // Verify values are set correctly
    await expect(page.locator('select[name="site_language"]')).toHaveValue('it');
    await expect(page.locator('select[name="admin_language"]')).toHaveValue('it');
    await expect(page.locator('select[name="date_format"]')).toHaveValue('d-m-Y');
    await expect(page.locator('select[name="home_template"]')).toHaveValue('masonry');
    await expect(page.locator('select[name="gallery_template_id"]')).toHaveValue('2');

    await page.screenshot({ path: `${SCREENSHOTS}/05b-settings-filled.png`, fullPage: true });
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(/step=install/, { timeout: 10000 });
  });

  test('Step 5: Installation', async () => {
    await page.screenshot({ path: `${SCREENSHOTS}/06-ready-to-install.png`, fullPage: true });
    await page.click('button:has-text("Install")');
    await expect(page.locator('text=Installation Complete')).toBeVisible({ timeout: 30000 });
    await page.screenshot({ path: `${SCREENSHOTS}/07-install-complete.png`, fullPage: true });
  });

  test('Step 6: Login', async () => {
    await page.goto(`${BASE}/admin/login`);
    await page.fill('input[name="email"]', 'admin@test.com');
    await page.fill('input[name="password"]', 'TestPass123!');
    await page.screenshot({ path: `${SCREENSHOTS}/08-login.png`, fullPage: true });
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(/\/admin/, { timeout: 10000 });
    await expect(page.locator('text=Dashboard').first()).toBeVisible({ timeout: 5000 });
    await page.screenshot({ path: `${SCREENSHOTS}/09-dashboard.png`, fullPage: true });
  });

  test('Step 7: Homepage loads', async () => {
    const visitorCtx = await browser.newContext();
    const visitorPage = await visitorCtx.newPage();
    await visitorPage.goto(`${BASE}/`);
    await expect(visitorPage.locator('text=Test Cimaise').first()).toBeVisible({ timeout: 5000 });
    await visitorPage.screenshot({ path: `${SCREENSHOTS}/10-homepage.png`, fullPage: true });
    await visitorPage.close();
    await visitorCtx.close();
  });
});
