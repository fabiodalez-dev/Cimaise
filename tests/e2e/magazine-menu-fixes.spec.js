// @ts-check
// Regression coverage for two frontend fixes:
//  1) Magazine layout (?template=3): narrowing the window must switch to the
//     correct responsive variant AND load its images. The variant display is
//     set inline by JS and re-applied on resize (applyVariant + loadWrapImages);
//     before the fix the load-time variant stuck and photos disappeared/blanked.
//  2) Mobile menu must open to its full height in ONE tap. #mobile-menu is nested
//     inside #main-header, so capping by header.offsetHeight was circular and
//     collapsed the menu to a ~23px sliver; the cap is now anchored to the menu's
//     own top edge.
//
// Read-only: navigates existing public content, performs no admin writes.

import { test, expect } from '@playwright/test';
import { BASE } from './_helpers.js';

async function firstAlbumPath(page) {
    await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' });
    const href = await page.locator('a[href*="/album/"]').first().getAttribute('href').catch(() => null);
    if (!href) return null;
    // strip any existing query so we can append ?template=3
    return href.split('?')[0];
}

test.describe('Magazine responsive variant on resize (?template=3)', () => {
    test('narrowing desktop -> mobile shows the mobile variant with loaded images', async ({ page }) => {
        const albumPath = await firstAlbumPath(page);
        test.skip(!albumPath, 'no public album available to test the magazine layout');

        await page.setViewportSize({ width: 1440, height: 900 });
        const resp = await page.goto(`${albumPath}?template=3`, { waitUntil: 'networkidle' });
        expect(resp?.status()).toBe(200);

        // At desktop width the desktop variant is the visible one.
        const desk = page.locator('.m-desktop-wrap');
        const mob = page.locator('.m-mobile-wrap');
        await expect(desk).toBeVisible();
        await expect(mob).toBeHidden();

        // Narrow below the mobile breakpoint and let the debounced resize fire.
        await page.setViewportSize({ width: 390, height: 844 });
        await page.waitForTimeout(400);

        // The mobile variant is now the visible one, the desktop one is hidden.
        await expect(mob).toBeVisible();
        await expect(desk).toBeHidden();

        // The now-visible mobile variant must have actually loaded images
        // (data-src resolved on the variant that became active).
        const loaded = await mob.evaluate((el) => {
            const imgs = [...el.querySelectorAll('img')];
            return imgs.length > 0 && imgs.some((i) => i.complete && i.naturalWidth > 0);
        });
        expect(loaded).toBe(true);
    });
});

test.describe('Mobile menu opens full height in one tap', () => {
    for (const variant of [{ name: 'default', q: '' }, { name: 'modern', q: '?template=modern' }]) {
        test(`menu is not clipped to a sliver (${variant.name})`, async ({ page }) => {
            await page.setViewportSize({ width: 390, height: 844 });
            const resp = await page.goto(`${BASE}/${variant.q}`, { waitUntil: 'domcontentloaded' });
            expect(resp?.status()).toBe(200);

            const toggle = page.locator('#mobile-menu-toggle');
            const menu = page.locator('#mobile-menu');
            await expect(toggle).toBeVisible();

            await toggle.click();
            await page.waitForTimeout(150); // rAF + open transition start

            // Menu is shown and NOT collapsed to the ~23px sliver bug.
            await expect(menu).toBeVisible();
            const offset = await menu.evaluate((el) => el.offsetHeight);
            expect(offset).toBeGreaterThan(100);

            // A second tap closes it (clean toggle).
            await toggle.click();
            await page.waitForTimeout(400);
            const closed = await menu.evaluate(
                (el) => el.classList.contains('hidden') || el.style.maxHeight === '0px'
            );
            expect(closed).toBe(true);
        });
    }
});
