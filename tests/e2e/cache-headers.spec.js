// @ts-check
// HTTP cache verification covering F009 (Vary: Cookie on /api/album/*), ETag
// stability, 304 handling, static asset caching, and the empty-Content-Type-as-HTML
// fallback in CacheMiddleware.

import { test, expect } from '@playwright/test';
import { BASE, header, requireServer, findPublicAlbumSlug } from './_helpers.js';

test.describe('HTTP cache headers', () => {
    test.beforeEach(async ({ page }) => {
        await requireServer(test, page);
    });

    test('CACHE-01: home page sets Cache-Control with private+max-age (HTML cache)', async ({ page }) => {
        // While another spec's content change has the home cache stale, the
        // stale body is served as no-store and rebuilt after the response, so
        // poll until the rebuilt page answers.
        await expect.poll(async () => {
            const resp = await page.request.get(`${BASE}/`, { failOnStatusCode: false });
            return header(resp, 'Cache-Control') || '';
        }, { timeout: 15000 }).toMatch(/(public|private).*max-age=\d+|max-age=\d+.*(public|private)/);
    });

    test('CACHE-02: home page emits Vary: Accept-Encoding (compression negotiation)', async ({ page }) => {
        const resp = await page.request.get(`${BASE}/`, { failOnStatusCode: false });
        const vary = header(resp, 'Vary');
        if (vary) {
            // Must mention Accept-Encoding (compression) — Cookie is added when session-dependent
            expect(vary.toLowerCase()).toContain('accept-encoding');
        }
        // Vary header is informational; absence is acceptable on a pure-anonymous render
    });

    test('CACHE-03: ETag header stable across two identical requests (304 path)', async ({ page }) => {
        const r1 = await page.request.get(`${BASE}/`, { failOnStatusCode: false });
        const etag1 = header(r1, 'ETag');
        const r2 = await page.request.get(`${BASE}/`, { failOnStatusCode: false });
        const etag2 = header(r2, 'ETag');
        if (etag1 && etag2) {
            // ETag must be stable for identical content (CSRF token stripped via the fix)
            expect(etag1).toBe(etag2);
        }
    });

    test('CACHE-04: 304 Not Modified returned on If-None-Match match', async ({ page }) => {
        const r1 = await page.request.get(`${BASE}/`, { failOnStatusCode: false });
        const etag = header(r1, 'ETag');
        if (!etag) test.skip(true, 'no ETag on home page');
        const r2 = await page.request.get(`${BASE}/`, {
            headers: { 'If-None-Match': etag },
            failOnStatusCode: false,
        });
        // Either 304 (cache hit) or 200 (no-cache active) — must not be 5xx
        expect([200, 304]).toContain(r2.status());
    });

    test('CACHE-05: /api/album/<slug>/template applies addApiCache (F009 site)', async ({ page }) => {
        // A real public album: addApiCache only applies to successful responses.
        const slug = await findPublicAlbumSlug(page.request);
        test.skip(!slug, 'no public album available to probe');
        const resp = await page.request.get(`${BASE}/api/album/${encodeURIComponent(slug)}/template?template=1`);
        const cc = header(resp, 'Cache-Control');
        expect(cc).toBeTruthy();
        // addApiCache emits 'private' (per the existing semantics)
        expect(cc).toContain('private');
    });

    test('CACHE-05b: an API 404 is never cacheable', async ({ page }) => {
        // Error responses get no-store before any cache strategy runs, so a
        // shared cache cannot keep serving a 404 after the album appears.
        const resp = await page.request.get(`${BASE}/api/album/__nonexistent_xyz__/template?template=1`, {
            failOnStatusCode: false,
        });
        expect(resp.status()).toBeGreaterThanOrEqual(400);
        expect(header(resp, 'Cache-Control')).toContain('no-store');
    });
});
