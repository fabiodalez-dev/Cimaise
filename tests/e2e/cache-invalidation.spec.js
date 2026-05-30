// @ts-check
// Content mutations must drop the page cache. The home / galleries / album pages
// are page-cached (DB), so a stale cache would keep showing the old content.
// Here we prove the gallery "form" path: changing the grid columns in the filter
// settings must be reflected on the (cached) /galleries page immediately.

import { test, expect } from '@playwright/test';
import { BASE, requireAdmin, adminCsrf } from './_helpers.js';

test.describe.serial('Cache invalidation on content changes', () => {
    let page;
    let csrf = null;
    let original = '3';

    const base = {
        enabled: '1', animation_enabled: '1', animation_duration: '0.6',
        show_categories: '1', show_tags: '1', show_cameras: '1', show_lenses: '1',
        show_films: '1', show_developers: '0', show_labs: '0', show_locations: '1',
        show_year: '1', grid_columns_tablet: '2', grid_columns_mobile: '1', grid_gap: 'normal',
    };

    const saveColumns = (cols) =>
        page.request.post(`${BASE}/admin/filter-settings`, {
            form: { ...base, grid_columns_desktop: String(cols), csrf },
            failOnStatusCode: false,
        });

    const galleriesDesktopCols = async () => {
        const html = await (await page.request.get(`${BASE}/galleries`)).text();
        const m = html.match(/data-desktop-cols="(\d+)"/);
        return m ? m[1] : null;
    };

    test.beforeAll(async ({ browser }) => {
        page = await browser.newPage();
        await requireAdmin(test, page);
        csrf = await adminCsrf(page, `${BASE}/admin/filter-settings`);
        original = (await galleriesDesktopCols()) || '3';
    });

    test.afterAll(async () => {
        if (csrf) await saveColumns(original); // restore
        await page?.close();
    });

    test('CI-01: changing the gallery grid columns busts the /galleries cache', async () => {
        test.skip(!csrf, 'no CSRF');
        // Warm the galleries cache.
        await galleriesDesktopCols();

        const target = original === '5' ? '4' : '5';
        const res = await saveColumns(target);
        expect(res.status()).toBeLessThan(400);

        // The cached /galleries must now reflect the new column count.
        expect(await galleriesDesktopCols()).toBe(target);
    });
});
