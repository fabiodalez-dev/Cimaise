// @ts-check
// Category drag-to-reorder on /admin/categories.
//
// Reported bug: the nested SortableJS lists never initialised, so nothing was
// draggable. Root cause: the init ran inside a DOMContentLoaded handler, but the
// admin SPA re-executes inline scripts after an innerHTML swap (where that event
// is long past), and even on a normal load it failed to produce instances. The
// fix bootstraps via a readiness poll instead.
//
// CAT-01 proves the lists initialise. CAT-02 proves the persistence path a drag
// triggers (saveHierarchy -> POST /admin/categories/reorder-wordpress); we invoke
// it directly because SortableJS pointer drags are flaky to synthesise headless.

import { test, expect } from '@playwright/test';
import { BASE, requireAdmin } from './_helpers.js';

test.describe('Categories drag reorder', () => {
    test('CAT-01: nested sortable lists initialise on load', async ({ page }) => {
        await requireAdmin(test, page);
        await page.goto(`${BASE}/admin/categories`, { waitUntil: 'networkidle' });
        await page.waitForFunction(() => (window.sortableInstances || []).length > 0, null, { timeout: 8000 });
        expect(await page.evaluate(() => window.sortableInstances.length)).toBeGreaterThan(0);
    });

    test('CAT-02: the reorder save path persists the hierarchy', async ({ page }) => {
        await requireAdmin(test, page);
        await page.goto(`${BASE}/admin/categories`, { waitUntil: 'networkidle' });
        await page.waitForFunction(() => (window.sortableInstances || []).length > 0, null, { timeout: 8000 });

        const savePromise = page.waitForResponse(
            r => /\/admin\/categories\/reorder/.test(r.url()) && r.request().method() === 'POST',
            { timeout: 8000 }
        );

        // Exactly what SortableJS onEnd calls after a drag.
        await page.evaluate(() => {
            if (typeof window.saveHierarchy === 'function') return window.saveHierarchy();
        });

        const res = await savePromise;
        expect(res.status()).toBe(200);
        const body = await res.json().catch(() => ({}));
        expect(body.ok ?? body.success).toBeTruthy();
    });
});
