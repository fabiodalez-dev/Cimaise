// @ts-check
// Full-text search (#1): the public /search page finds published albums and
// handles the empty / no-result states. Ranking + privacy are unit-tested in
// tests/Services/SearchServiceTest.php; this proves the end-to-end wiring
// (route -> controller -> SearchService -> view) against a real album.
//
// Page content is asserted via the request context rather than page.goto: the
// no-query /search page is cacheable and the site registers a PWA service
// worker, which makes full navigations intermittently abort in headless
// Chromium. Fetching the HTML directly is deterministic and still exercises the
// whole server pipeline.

import { test, expect } from '@playwright/test';
import {
    BASE,
    requireAdmin,
    createAlbum,
    publishAlbum,
} from './_helpers.js';

test.describe.serial('Full-text search', () => {
    let page;
    let album = { id: null, slug: null };
    let csrf = null;
    const token = `zqxsearch${Date.now()}`;

    test.beforeAll(async ({ browser }) => {
        page = await browser.newPage();
        await requireAdmin(test, page);
        album = await createAlbum(page, `Findable ${token}`);
        if (album.id) {
            await publishAlbum(page, album.id);
            csrf = await page.locator('input[name="csrf"]').first().getAttribute('value').catch(() => null);
        }
    });

    test.afterAll(async () => {
        try {
            if (album.id && csrf) {
                await page.request.post(`${BASE}/admin/albums/${album.id}/delete`, {
                    headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
                    form: { csrf },
                    failOnStatusCode: false,
                });
            }
        } catch { /* best-effort cleanup */ }
        await page?.close();
    });

    test('SR-01: finds a published album by a unique title token', async () => {
        test.skip(!album.id, 'Album setup failed');
        const res = await page.request.get(`${BASE}/search?q=${token}`);
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).toContain(`/album/${album.slug}`);
    });

    test('SR-02: empty query renders the prompt, no result cards', async () => {
        const res = await page.request.get(`${BASE}/search`);
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).not.toContain('class="search-result"');
        // The search page rendered (its heading is present).
        expect(body).toMatch(/Ricerca|Search/);
    });

    test('SR-03: a non-matching query returns no result cards', async () => {
        const res = await page.request.get(`${BASE}/search?q=zzznotarealtermxyz999`);
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).not.toContain('class="search-result"');
    });

    test('SR-04: the header search form targets /search', async () => {
        const body = await (await page.request.get(`${BASE}/`)).text();
        expect(body).toMatch(/id="header-search-form"[^>]*action="[^"]*\/search"/);
    });
});
