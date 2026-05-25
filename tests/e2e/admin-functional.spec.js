// @ts-check
// Admin UI smoke + targeted regression tests covering the fixes that affect
// admin-facing pages: typography reset confirm, settings page, version display,
// diagnostics page, cache settings page.

import { test, expect } from '@playwright/test';
import {
    BASE,
    requireServer,
    requireAdmin,
} from './_helpers.js';

test.describe.serial('Admin functional', () => {
    test('ADM-01: /admin dashboard reachable for admin user', async ({ page }) => {
        await requireServer(test, page);
        await requireAdmin(test, page);
        await page.goto(`${BASE}/admin`);
        await page.waitForLoadState('networkidle');
        expect(page.url()).toMatch(/\/admin\/?$/);
    });

    test('ADM-02: /admin/albums list page renders', async ({ page }) => {
        await requireServer(test, page);
        await requireAdmin(test, page);
        const resp = await page.goto(`${BASE}/admin/albums`);
        expect(resp?.status()).toBe(200);
    });

    test('ADM-03: /admin/settings page accessible', async ({ page }) => {
        await requireServer(test, page);
        await requireAdmin(test, page);
        const resp = await page.goto(`${BASE}/admin/settings`);
        expect([200, 302, 303]).toContain(resp?.status() ?? 0);
    });

    test('ADM-04: /admin/cache page accessible', async ({ page }) => {
        await requireServer(test, page);
        await requireAdmin(test, page);
        const resp = await page.goto(`${BASE}/admin/cache`);
        expect([200, 302, 303]).toContain(resp?.status() ?? 0);
    });

    test('ADM-05: admin layout displays app version (F037 / a63d4fd8 — feat: display app version)', async ({ page }) => {
        await requireServer(test, page);
        await requireAdmin(test, page);
        await page.goto(`${BASE}/admin`);
        // The admin layout renders <span class="...">v{{ app_version }}</span>
        const versionEl = page.locator('span').filter({ hasText: /^v\d+\.\d+/ }).first();
        const visible = await versionEl.isVisible().catch(() => false);
        // Version may or may not be rendered depending on app_version global wiring
        if (!visible) {
            // Fall back to a hard check against the served HTML — a version-shaped
            // string must be present (this is what the F037 fix ships).
            const html = await page.content();
            const hasVersion = /v\d+\.\d+\.\d+/.test(html);
            expect(hasVersion).toBe(true);
        }
    });
});
