// @ts-check
// Live preview on /admin/filter-settings: changing the grid columns / gap must
// visibly update the preview, which renders real album covers in the configured
// grid (regression for the old version that capped at 3 fixed gray boxes).

import { test, expect } from '@playwright/test';
import { BASE, requireAdmin } from './_helpers.js';

test.describe.serial('Filter settings live preview', () => {
    let page;
    test.beforeAll(async ({ browser }) => {
        page = await browser.newPage();
        await requireAdmin(test, page);
        await page.goto(`${BASE}/admin/filter-settings`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(700); // covers fetch
    });
    test.afterAll(async () => { await page?.close(); });

    test('FSP-01: preview renders real album cover thumbnails', async () => {
        const imgs = await page.locator('#preview-grid img').count();
        expect(imgs).toBeGreaterThan(0);
    });

    test('FSP-02: changing desktop columns updates the preview grid', async () => {
        const grid = page.locator('#preview-grid');
        await page.locator('select[name="grid_columns_desktop"]').selectOption('2');
        await page.waitForTimeout(150);
        const cols2 = await grid.evaluate(el => getComputedStyle(el).gridTemplateColumns.split(' ').length);
        await page.locator('select[name="grid_columns_desktop"]').selectOption('5');
        await page.waitForTimeout(150);
        const cols5 = await grid.evaluate(el => getComputedStyle(el).gridTemplateColumns.split(' ').length);
        expect(cols2).toBe(2);
        expect(cols5).toBe(5);
    });

    test('FSP-03: gap matches the frontend (large = 2rem)', async () => {
        await page.locator('input[name="grid_gap"][value="large"]').check();
        await page.waitForTimeout(150);
        const gap = await page.locator('#preview-grid').evaluate(el => getComputedStyle(el).gap);
        expect(gap).toBe('32px'); // 2rem
    });
});
