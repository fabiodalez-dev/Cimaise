// @ts-check
// Curated collections (#2): end-to-end through the admin CRUD + photo picker
// and out to the public pages. Privacy / ordering are unit-tested in
// tests/Services/CollectionServiceTest.php; this proves the full wiring:
// create album -> upload photo -> create collection -> attach photo -> publish
// -> the public /collection/{slug} gallery and /collections listing render it.

import { test, expect } from '@playwright/test';
import {
    BASE,
    requireAdmin,
    createAlbum,
    publishAlbum,
    uploadCover,
    adminCsrf,
} from './_helpers.js';

test.describe.serial('Curated collections', () => {
    let page;
    let album = { id: null, slug: null };
    let imageId = null;
    let collectionId = null;
    let collectionSlug = null;
    let csrf = null;
    const token = `zqxcoll${Date.now()}`;

    const post = (url, body) =>
        page.request.post(`${BASE}${url}`, {
            headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' },
            data: body,
            failOnStatusCode: false,
        });

    test.beforeAll(async ({ browser }) => {
        page = await browser.newPage();
        await requireAdmin(test, page);

        // A published album with one photo to curate from.
        album = await createAlbum(page, `CollSrc ${token}`);
        if (album.id) {
            await publishAlbum(page, album.id);
            const up = await uploadCover(page, album.id);
            imageId = up.imageId;
        }

        // Create a published collection (form POST, read the redirect for its id).
        csrf = await adminCsrf(page, `${BASE}/admin/collections/create`);
        if (csrf) {
            const res = await page.request.post(`${BASE}/admin/collections`, {
                headers: { 'X-CSRF-Token': csrf },
                form: { title: `Curated ${token}`, is_published: '1', csrf },
                maxRedirects: 0,
                failOnStatusCode: false,
            });
            const loc = res.headers()['location'] || '';
            const m = loc.match(/\/admin\/collections\/(\d+)\/edit/);
            collectionId = m ? Number(m[1]) : null;
        }

        // Attach the photo and read the generated slug from the edit page.
        if (collectionId && imageId) {
            await post(`/admin/collections/${collectionId}/images/attach`, { image_id: imageId });
            const editHtml = await (await page.request.get(`${BASE}/admin/collections/${collectionId}/edit`)).text();
            const sm = editHtml.match(/name="slug"[^>]*value="([^"]*)"/);
            collectionSlug = sm ? sm[1] : null;
        }
    });

    test.afterAll(async () => {
        try {
            if (collectionId && csrf) {
                await page.request.post(`${BASE}/admin/collections/${collectionId}/delete`, {
                    headers: { 'X-CSRF-Token': csrf }, form: { csrf }, failOnStatusCode: false,
                });
            }
            if (album.id && csrf) {
                await page.request.post(`${BASE}/admin/albums/${album.id}/delete`, {
                    headers: { 'X-CSRF-Token': csrf }, form: { csrf }, failOnStatusCode: false,
                });
            }
        } catch { /* best-effort cleanup */ }
        await page?.close();
    });

    test('COLL-01: setup produced a published collection with a photo', async () => {
        expect(album.id, 'album created').toBeTruthy();
        expect(imageId, 'photo uploaded').toBeTruthy();
        expect(collectionId, 'collection created').toBeTruthy();
        expect(collectionSlug, 'collection slug').toBeTruthy();
    });

    test('COLL-02: public collection page renders the photo gallery', async () => {
        test.skip(!collectionSlug, 'Collection setup failed');
        const res = await page.request.get(`${BASE}/collection/${collectionSlug}`);
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).toContain(`Curated ${token}`);
        // The shared lightbox gallery contract is present with at least one photo.
        expect(body).toContain('id="images-gallery"');
        expect(body).toMatch(/class="pswp-link/);
    });

    test('COLL-03: public collections listing includes the collection', async () => {
        test.skip(!collectionSlug, 'Collection setup failed');
        const body = await (await page.request.get(`${BASE}/collections`)).text();
        expect(body).toContain(`/collection/${collectionSlug}`);
        expect(body).toContain(`Curated ${token}`);
    });

    test('COLL-04: admin collections list shows the collection', async () => {
        test.skip(!collectionId, 'Collection setup failed');
        const body = await (await page.request.get(`${BASE}/admin/collections`)).text();
        expect(body).toContain(`Curated ${token}`);
        expect(body).toContain(`/admin/collections/${collectionId}/edit`);
    });

    test('COLL-05: detaching the photo empties the public gallery', async () => {
        test.skip(!collectionId || !imageId || !collectionSlug, 'Collection setup failed');
        const detach = await post(`/admin/collections/${collectionId}/images/detach`, { image_id: imageId });
        expect(detach.ok()).toBeTruthy();
        const body = await (await page.request.get(`${BASE}/collection/${collectionSlug}`)).text();
        expect(body).not.toMatch(/class="pswp-link/);
        // Re-attach so COLL state is restored (harmless; cleanup deletes anyway).
        await post(`/admin/collections/${collectionId}/images/attach`, { image_id: imageId });
    });
});
