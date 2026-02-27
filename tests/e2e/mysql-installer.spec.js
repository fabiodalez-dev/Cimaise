import { test, expect } from '@playwright/test';

// MySQL credentials from environment variables (set via CI secrets or local .env)
const MYSQL_CONFIG = {
  host: process.env.TEST_MYSQL_HOST || '127.0.0.1',
  port: process.env.TEST_MYSQL_PORT || '3306',
  database: process.env.TEST_MYSQL_DATABASE || 'cimaise',
  username: process.env.TEST_MYSQL_USERNAME || 'root',
  password: process.env.TEST_MYSQL_PASSWORD || '',
};

const ADMIN_CONFIG = {
  name: process.env.TEST_ADMIN_NAME || 'Test Admin',
  email: process.env.TEST_ADMIN_EMAIL || 'admin@test.com',
  password: process.env.TEST_ADMIN_PASSWORD || 'TestPass123!',
};

// Fail fast in CI if required env vars are missing
if (process.env.CI) {
  for (const key of ['TEST_MYSQL_HOST', 'TEST_MYSQL_DATABASE', 'TEST_MYSQL_USERNAME', 'TEST_MYSQL_PASSWORD']) {
    if (!process.env[key]) {
      throw new Error(`Missing required env var in CI: ${key}`);
    }
  }
}

test.describe('MySQL/MariaDB Installer', () => {
  test('should complete full MySQL installation flow', async ({ page }) => {
    // Step 1: Requirements
    await page.goto('/installer.php');
    await expect(page.locator('h2')).toContainText('System Requirements');

    // Click continue
    await page.click('a:has-text("Continue to Database Setup")');
    await page.waitForURL('**/installer.php?step=database');

    // Step 2: Database - select MySQL
    await expect(page.locator('h2')).toContainText('Database Configuration');
    await page.click('input[name="db_type"][value="mysql"]');
    await expect(page.locator('#mysql-config')).toBeVisible();

    // Fill MySQL credentials
    await page.fill('input[name="db_host"]', MYSQL_CONFIG.host);
    await page.fill('input[name="db_port"]', MYSQL_CONFIG.port);
    await page.fill('input[name="db_database"]', MYSQL_CONFIG.database);
    await page.fill('input[name="db_username"]', MYSQL_CONFIG.username);
    await page.fill('input[name="db_password"]', MYSQL_CONFIG.password);

    await page.screenshot({ path: 'tests/e2e/screenshots/mysql-config-filled.png' });
    await page.click('button:has-text("Test & Continue")');

    // Should redirect to admin step (no DB connection error)
    await page.waitForURL('**/installer.php?step=admin', { timeout: 10000 });
    await expect(page.locator('h2')).toContainText('Admin User Account');

    // Step 3: Admin user
    await page.fill('input[name="admin_name"]', ADMIN_CONFIG.name);
    await page.fill('input[name="admin_email"]', ADMIN_CONFIG.email);
    await page.fill('input[name="admin_password"]', ADMIN_CONFIG.password);
    await page.fill('input[name="admin_password_confirm"]', ADMIN_CONFIG.password);

    await page.click('button:has-text("Create Admin User")');

    // Should redirect to settings step
    await page.waitForURL('**/installer.php?step=settings', { timeout: 10000 });
    await expect(page.locator('h2')).toContainText('Site Settings');

    // Step 4: Settings - use defaults
    await page.click('button:has-text("Configure Site")');

    // Should redirect to install step
    await page.waitForURL('**/installer.php?step=install', { timeout: 10000 });
    await expect(page.locator('h2')).toContainText('Ready to Install');

    // Verify summary shows MySQL
    await expect(page.locator('text=Type: Mysql')).toBeVisible();
    await page.screenshot({ path: 'tests/e2e/screenshots/install-summary.png' });

    // Step 5: Run installation
    await page.click('button:has-text("Install Cimaise")');

    // Should complete successfully — wait for the success heading
    await expect(page.locator('h2')).toContainText('Installation Complete', { timeout: 30000 });
    await page.screenshot({ path: 'tests/e2e/screenshots/install-complete.png' });

    // Verify we can load the homepage after install
    await page.click('a:has-text("Visit Your Site")');
    await page.waitForLoadState('networkidle');
    expect(page.url()).not.toContain('installer.php');
    await page.screenshot({ path: 'tests/e2e/screenshots/post-install-home.png' });
  });
});
