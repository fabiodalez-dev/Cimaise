// @ts-check
// A freshly created album must be indexable.
//
// Regression: the album CREATE form carries no technical-SEO block, yet the
// controller stored `isset($_POST['robots_index']) ? 1 : 0` — so every album
// created from the admin was persisted noindex,nofollow, rendered
// <meta name="robots" content="noindex,nofollow"> and never entered the sitemap.
import { test, expect } from '@playwright/test';
import { BASE, createAlbum, uploadCover, deleteAlbum, requireServer, requireAdmin } from './_helpers.js';

test.describe.serial('Album robots default', () => {
    let page;
    let album = { id: null, slug: null };

    test.beforeAll(async ({ browser }) => {
        page = await browser.newPage();
        await requireServer(test, page);
        await requireAdmin(test, page);
        album = await createAlbum(page, `ROBOTS ${Date.now()}`);
        if (album.id) await uploadCover(page, album.id, 'ROBOTS', '#b45309');
    });

    test.afterAll(async () => {
        if (album.id) await deleteAlbum(page, album.id);
        await page.close();
    });

    test('ROB-01: a new album is index,follow on the public page', async ({ browser }) => {
        test.skip(!album.slug, 'album fixture unavailable');
        const ctx = await browser.newContext();
        try {
            const r = await ctx.request.get(`${BASE}/album/${album.slug}`);
            expect(r.status()).toBe(200);
            expect(await r.text()).toMatch(/<meta name="robots" content="index,follow"/);
        } finally {
            await ctx.close();
        }
    });

    test('ROB-02: the edit form shows both robots toggles checked', async () => {
        test.skip(!album.id, 'album fixture unavailable');
        await page.goto(`${BASE}/admin/albums/${album.id}/edit`);
        await expect(page.locator('input[name="robots_index"]')).toBeChecked();
        await expect(page.locator('input[name="robots_follow"]')).toBeChecked();
    });

    test('ROB-03: unchecking in the edit form still yields noindex,nofollow', async ({ browser }) => {
        test.skip(!album.id, 'album fixture unavailable');
        await page.goto(`${BASE}/admin/albums/${album.id}/edit`);
        const form = await page.$eval('#album-form', (f) => {
            const out = {};
            for (const [k, v] of new FormData(f).entries()) if (typeof v === 'string') out[k] = v;
            return out;
        });
        delete form.robots_index;
        delete form.robots_follow;
        const save = await page.request.post(`${BASE}/admin/albums/${album.id}`, { form, maxRedirects: 0, failOnStatusCode: false });
        expect(save.status()).toBe(302);
        const ctx = await browser.newContext();
        try {
            const r = await ctx.request.get(`${BASE}/album/${album.slug}`);
            expect(await r.text()).toMatch(/<meta name="robots" content="noindex,nofollow"/);
        } finally {
            await ctx.close();
        }
    });
});
