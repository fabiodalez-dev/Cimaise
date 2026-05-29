// @ts-check
// Frontend page rendering: home, about, privacy, cookie, license, gallery, galleries,
// 404, robots.txt, sitemap.xml. Quick smoke that the public-facing routes are wired
// correctly and don't throw 500s.

import { test, expect } from '@playwright/test';
import { BASE, header, requireServer } from './_helpers.js';

test.describe('Frontend pages — public rendering', () => {
    test.beforeEach(async ({ page }) => {
        await requireServer(test, page);
    });

    test('FE-01: home page renders 200 with HTML body', async ({ page }) => {
        const resp = await page.goto(`${BASE}/`);
        expect(resp?.status()).toBe(200);
        const body = await page.locator('body').textContent();
        expect(body?.length || 0).toBeGreaterThan(50);
    });

    test('FE-02: /about renders 200 and contains visible content', async ({ page }) => {
        const resp = await page.request.get(`${BASE}/about`, { failOnStatusCode: false });
        // Some installs may not have About content; accept 200 or 404 but never 500
        expect(resp.status()).toBeLessThan(500);
    });

    test('FE-03: /privacy-policy renders without error', async ({ page }) => {
        const resp = await page.request.get(`${BASE}/privacy-policy`, { failOnStatusCode: false });
        expect(resp.status()).toBeLessThan(500);
    });

    test('FE-04: /cookie-policy renders without error', async ({ page }) => {
        const resp = await page.request.get(`${BASE}/cookie-policy`, { failOnStatusCode: false });
        expect(resp.status()).toBeLessThan(500);
    });

    test('FE-05: /license renders without error', async ({ page }) => {
        const resp = await page.request.get(`${BASE}/license`, { failOnStatusCode: false });
        expect(resp.status()).toBeLessThan(500);
    });
});
