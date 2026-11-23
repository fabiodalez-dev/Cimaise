// @ts-check
// Published visual album layouts (issue #158) on the public album page.
//
// Covers what unit tests cannot: the real Apache + page cache + HTML cache
// stack, the lightbox, and the password / NSFW gates. Layouts are written
// through tests/e2e/fixtures/album-layout.php (AlbumLayoutService) until the
// admin save endpoints land.
import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    BASE, createAlbum, deleteAlbum, publishAlbum, requireServer, requireAdmin, header,
} from './_helpers.js';
import { uploadImages } from './_upload-helpers.js';

const FIXTURE = path.join(path.dirname(fileURLToPath(import.meta.url)), 'fixtures', 'album-layout.php');

/** @returns {{revision: number, order: number[]}} */
function layout(albumId, action) {
    const out = execFileSync('php', [FIXTURE, String(albumId), action], { encoding: 'utf8' });
    return JSON.parse(out.trim().split('\n').pop() || '{}');
}

/** Cookie-less GET, as a first-time anonymous visitor. */
async function anon(browser, urlPath) {
    const ctx = await browser.newContext();
    try {
        const r = await ctx.request.get(`${BASE}${urlPath}`, { failOnStatusCode: false });
        return { status: r.status(), cache: header(r, 'x-cimaise-cache'), body: await r.text() };
    } finally {
        await ctx.close();
    }
}

const imageIds = (html) => [...html.matchAll(/data-image-id="(\d+)"/g)].map((m) => Number(m[1]));

/** Gallery markup without per-request noise (nonces, CSRF, cache-busting). */
function galleryMarkup(html) {
    const start = html.indexOf('id="images-gallery"');
    expect(start, 'gallery container present').toBeGreaterThan(-1);
    return html.slice(start, html.indexOf('</main>', start) > -1 ? html.indexOf('</main>', start) : undefined)
        .replace(/nonce="[^"]*"/g, '')
        .replace(/name="csrf" value="[^"]*"/g, '')
        .replace(/[?&]v=[\w.-]+/g, '');
}

test.describe.serial('Visual album layouts on the public page', () => {
    let page;
    const album = { id: 0, slug: '', images: /** @type {number[]} */ ([]) };
    let baseline = '';

    test.beforeAll(async ({ browser }) => {
        page = await browser.newPage();
        await requireServer(test, page);
        await requireAdmin(test, page);
        const created = await createAlbum(page, `LAY ${Date.now()}`);
        album.id = created.id ?? 0;
        album.slug = created.slug ?? '';
        if (album.id) {
            album.images = await uploadImages(page, album.id, 5, { labelPrefix: 'lay' });
            await publishAlbum(page, album.id);
        }
    });

    test.afterAll(async () => {
        try {
            if (album.id) {
                layout(album.id, 'clear');
                await deleteAlbum(page, album.id);
            }
        } finally {
            await page.close();
        }
    });

    test('LAY-01: an album without a layout keeps the default gallery', async ({ browser }) => {
        test.skip(!album.id || album.images.length < 5, 'album setup failed');
        const r = await anon(browser, `/album/${album.slug}`);
        expect(r.status).toBe(200);
        expect(r.body).not.toContain('album-layout');
        expect(imageIds(r.body).slice(0, 5)).toEqual(album.images);
        baseline = galleryMarkup(r.body);
    });

    test('LAY-02: the cookie-less album page is served from the HTML cache', async ({ browser }) => {
        test.skip(!album.id, 'album setup failed');
        await anon(browser, `/album/${album.slug}`);
        const r = await anon(browser, `/album/${album.slug}`);
        expect(r.status).toBe(200);
        expect(r.cache).toBe('hit');
    });

    test('LAY-03: a draft stays private', async ({ browser }) => {
        test.skip(!album.id, 'album setup failed');
        layout(album.id, 'draft');
        for (const url of [`/album/${album.slug}`, `/album/${album.slug}?layout_preview=draft`]) {
            const r = await anon(browser, url);
            expect(r.status).toBe(200);
            expect(r.body, url).not.toContain('LAYOUT-E2E-TEXT');
            expect(r.body, url).not.toContain('album-layout');
        }
    });

    test('LAY-04: publishing shows the layout at once, bypassing every cache', async ({ browser }) => {
        test.skip(!album.id, 'album setup failed');
        const { order } = layout(album.id, 'publish');
        const r = await anon(browser, `/album/${album.slug}`);
        expect(r.status).toBe(200);
        expect(r.cache, 'stale HTML cache served after publish').not.toBe('hit');
        expect(r.body).toContain('data-layout="album_blocks"');
        expect(imageIds(r.body)).toEqual(order);
        // Text is plain text: markup is escaped, never rendered.
        expect(r.body).toContain('LAYOUT-E2E-TEXT &lt;b&gt;not html&lt;/b&gt;');
        // Configurable remaining grid (2 columns, 12px gap).
        expect(r.body).toMatch(/data-layout-remaining>\s*<div class="album-layout-grid" style="--block-gap: 12px; --block-columns: 2"/);
        // Structured data follows the displayed order.
        const ld = [...r.body.matchAll(/<script type="application\/ld\+json"[^>]*>([\s\S]*?)<\/script>/g)]
            .map((m) => m[1]).find((s) => s.includes('ImageGallery'));
        if (ld) {
            const urls = [...ld.matchAll(/"contentUrl"\s*:\s*"([^"]+)"/g)].map((m) => m[1]);
            expect(urls.length).toBe(order.length);
        }
        // Once rebuilt, the layout page itself is cacheable again.
        const again = await anon(browser, `/album/${album.slug}`);
        expect(again.body).toContain('data-layout="album_blocks"');
    });

    test('LAY-05: the lightbox follows the layout order', async ({ browser }) => {
        test.skip(!album.id, 'album setup failed');
        const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        const p = await ctx.newPage();
        try {
            await p.goto(`${BASE}/album/${album.slug}`, { waitUntil: 'load' });
            const links = p.locator('#images-gallery a.pswp-link');
            const expected = await links.evaluateAll((as) => as.map((a) => Number(a.getAttribute('data-image-id'))));
            expect(expected.length).toBe(album.images.length);
            // The lightbox may load another variant (avif/webp) than the link
            // href, so compare the image id encoded in /media/<id>_<variant>.
            const active = p.locator('.pswp__item[aria-hidden="false"] img.pswp__img').last();
            const activeId = async () => Number(((await active.getAttribute('src')) || '').match(/\/media\/(\d+)_/)?.[1] ?? 0);
            await links.first().click();
            const seen = [];
            for (let i = 0; i < expected.length; i++) {
                await expect.poll(activeId, { timeout: 10000 }).toBe(expected[i]);
                seen.push(await activeId());
                await p.keyboard.press('ArrowRight');
            }
            expect(seen).toEqual(expected);
        } finally {
            await ctx.close();
        }
    });

    test('LAY-06: a ?template= override does not bypass the layout', async ({ browser }) => {
        test.skip(!album.id, 'album setup failed');
        const r = await anon(browser, `/album/${album.slug}?template=1`);
        expect(r.status).toBe(200);
        expect(r.body).toContain('data-layout="album_blocks"');
    });

    test('LAY-07: the admin edit page warns that templates are not used', async () => {
        test.skip(!album.id, 'album setup failed');
        await page.goto(`${BASE}/admin/albums/${album.id}/edit`, { waitUntil: 'load' });
        await expect(page.locator('[data-visual-layout-notice]')).toBeVisible();
    });

    test('LAY-08: back to default restores the original gallery markup', async ({ browser }) => {
        test.skip(!album.id || !baseline, 'album setup failed');
        layout(album.id, 'default');
        const r = await anon(browser, `/album/${album.slug}`);
        expect(r.status).toBe(200);
        expect(r.cache).not.toBe('hit');
        expect(r.body).not.toContain('album-layout');
        expect(galleryMarkup(r.body)).toBe(baseline);
        await page.goto(`${BASE}/admin/albums/${album.id}/edit`, { waitUntil: 'load' });
        await expect(page.locator('[data-visual-layout-notice]')).toHaveCount(0);
    });
});

test.describe.serial('Visual album layouts behind access gates', () => {
    let page;
    /** @type {{id: number, slug: string}[]} */
    const created = [];

    test.beforeAll(async ({ browser }) => {
        page = await browser.newPage();
        await requireServer(test, page);
        await requireAdmin(test, page);
    });

    test.afterAll(async () => {
        try {
            for (const a of created) {
                layout(a.id, 'clear');
                await deleteAlbum(page, a.id);
            }
        } finally {
            await page.close();
        }
    });

    async function albumWithLayout(opts) {
        const a = await createAlbum(page, `LAYG ${Date.now()}`, opts);
        if (!a.id || !a.slug) return null;
        created.push({ id: a.id, slug: a.slug });
        const ids = await uploadImages(page, a.id, 4, { labelPrefix: 'layg' });
        if (ids.length < 4) return null;
        await publishAlbum(page, a.id);
        layout(a.id, 'publish');
        return { id: a.id, slug: a.slug, ids };
    }

    test('LAY-09: a password-protected album hides its layout until unlocked', async ({ browser }) => {
        const a = await albumWithLayout({ password: 'layout-secret' });
        test.skip(!a, 'album setup failed');
        const ctx = await browser.newContext();
        try {
            const gate = await ctx.request.get(`${BASE}/album/${a.slug}`);
            const body = await gate.text();
            expect(body).toContain('album-password-form');
            expect(body).not.toContain('LAYOUT-E2E-TEXT');
            expect(imageIds(body)).toEqual([]);
            const csrf = (body.match(/name="csrf"[^>]*value="([^"]+)"/) || [])[1] || '';
            await ctx.request.post(`${BASE}/album/${a.slug}/unlock`, {
                form: { csrf, password: 'layout-secret' }, maxRedirects: 5, failOnStatusCode: false,
            });
            const open = await (await ctx.request.get(`${BASE}/album/${a.slug}`)).text();
            expect(open).toContain('data-layout="album_blocks"');
            expect(open).toContain('LAYOUT-E2E-TEXT');
        } finally {
            await ctx.close();
        }
    });

    test('LAY-10: an NSFW album shows its layout only after consent', async ({ browser }) => {
        const a = await albumWithLayout({ isNsfw: true });
        test.skip(!a, 'album setup failed');
        const ctx = await browser.newContext();
        try {
            const gate = await ctx.request.get(`${BASE}/album/${a.slug}`);
            const body = await gate.text();
            expect(body).toMatch(/nsfw-confirm|data-nsfw-gate|nsfw-warning/);
            expect(body).not.toContain('LAYOUT-E2E-TEXT');
            const csrf = (body.match(/name="csrf"[^>]*value="([^"]+)"/) || [])[1] || '';
            await ctx.request.post(`${BASE}/album/${a.slug}/nsfw-confirm`, {
                form: { csrf, nsfw_confirmed: 1 }, maxRedirects: 5, failOnStatusCode: false,
            });
            const open = await (await ctx.request.get(`${BASE}/album/${a.slug}`)).text();
            expect(open).toContain('data-layout="album_blocks"');
            expect(open).toContain('LAYOUT-E2E-TEXT');
        } finally {
            await ctx.close();
        }
    });
});
