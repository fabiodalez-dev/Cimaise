// @ts-check
// LCP preload (#4): the home page must emit a single responsive image preload
// in <head> for the first gallery image, so its HD download starts during head
// parse instead of waiting for the LQIP/JS swap.

import { test, expect } from '@playwright/test';
import { BASE, requireServer } from './_helpers.js';

test.describe('Home LCP preload', () => {
    test('LCP-01: home head carries one high-priority image preload', async ({ page }) => {
        await requireServer(test, page);
        await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' });

        const preload = page.locator('head link[rel="preload"][as="image"]');
        const count = await preload.count();
        if (count === 0) {
            test.skip(true, 'No gallery images on the home page to preload');
        }
        expect(count).toBe(1);

        await expect(preload).toHaveAttribute('fetchpriority', 'high');

        // Must reference a real media asset, via responsive imagesrcset or href.
        const srcset = await preload.getAttribute('imagesrcset');
        const href = await preload.getAttribute('href');
        const ref = srcset || href || '';
        expect(ref).toContain('/media/');
    });

    test('LCP-02: preload matches the first gallery image source', async ({ page }) => {
        await requireServer(test, page);
        await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' });

        const preload = page.locator('head link[rel="preload"][as="image"]');
        if (await preload.count() === 0) {
            test.skip(true, 'No gallery images on the home page to preload');
        }

        const srcset = (await preload.getAttribute('imagesrcset')) || (await preload.getAttribute('href')) || '';
        // First URL in the preload (strip the descriptor).
        const firstUrl = srcset.split(',')[0].trim().split(' ')[0];
        expect(firstUrl).toMatch(/\/media\/\d+_[a-z]+\.(avif|webp|jpg)/);

        // That same base id should appear among the first gallery <img>/<source> srcsets.
        const idMatch = firstUrl.match(/\/media\/(\d+)_/);
        expect(idMatch).not.toBeNull();
        const id = idMatch[1];
        const html = await page.content();
        expect(html).toContain(`/media/${id}_`);
    });
});
