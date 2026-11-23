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

// Same QueryCache backend as the web server (file), see the fixture header.
const PHP_ARGS = ['-d', 'apc.enabled=0'];
const FIXTURE = path.join(path.dirname(fileURLToPath(import.meta.url)), 'fixtures', 'album-layout.php');

/** @returns {{revision: number, order: number[]}} */
function layout(albumId, action) {
    const out = execFileSync('php', [...PHP_ARGS, FIXTURE, String(albumId), action], { encoding: 'utf8' });
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

/** Set a setting through SettingsService; returns the previous value. */
function setSetting(key, value) {
    const out = execFileSync('php', [...PHP_ARGS, FIXTURE, 'setting', key, JSON.stringify(value)], { encoding: 'utf8' });
    return JSON.parse(out.trim().split('\n').pop() || '{}').previous;
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
    // LAY-04 checks the ImageGallery JSON-LD, which an install (or another
    // spec) may have switched off: pin it on for this file, restore after.
    let schemaBefore;

    test.beforeAll(async ({ browser }) => {
        page = await browser.newPage();
        await requireServer(test, page);
        await requireAdmin(test, page);
        schemaBefore = setSetting('seo.schema_enabled', true);
        const created = await createAlbum(page, `LAY ${Date.now()}`);
        album.id = created.id ?? 0;
        album.slug = created.slug ?? '';
        if (album.id) {
            // 8 photos: the remaining grid (2 columns) then holds 5 photos, i.e.
            // two full rows plus a lone last row.
            album.images = await uploadImages(page, album.id, 8, { labelPrefix: 'lay' });
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
            if (schemaBefore !== undefined) setSetting('seo.schema_enabled', schemaBefore);
            await page.close();
        }
    });

    test('LAY-01: an album without a layout keeps the default gallery', async ({ browser }) => {
        test.skip(!album.id || album.images.length < 8, 'album setup failed');
        const r = await anon(browser, `/album/${album.slug}`);
        expect(r.status).toBe(200);
        expect(r.body).not.toContain('album-layout');
        expect(imageIds(r.body)).toEqual(album.images);
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
        // Structured data lists the displayed photos, in the displayed order.
        const ld = [...r.body.matchAll(/<script type="application\/ld\+json"[^>]*>([\s\S]*?)<\/script>/g)]
            .map((m) => m[1]).find((s) => s.includes('ImageGallery'));
        expect(ld, 'ImageGallery JSON-LD present').toBeTruthy();
        const gallery = JSON.parse(String(ld));
        const ldIds = gallery.image.map((img) => Number(String(img.contentUrl).match(/\/media\/(\d+)_/)?.[1] ?? 0));
        expect(ldIds).toEqual(order);
        // Rows are sized by aspect ratio: every cell of a full row grows, only
        // the lone last row (5 remaining photos in 2 columns) keeps its width.
        const remaining = r.body.slice(r.body.indexOf('data-layout-remaining'));
        const rows = remaining.split('class="album-layout-row').slice(1);
        expect(rows.length).toBe(3);
        expect(rows.filter((row) => row.startsWith(' album-layout-row--lone')).length).toBe(1);
        const grows = [...remaining.matchAll(/class="album-layout-cell" style="flex-grow: ([^"]*)"/g)].map((m) => m[1]);
        expect(grows.length).toBe(5);
        expect(grows.slice(0, 4).every((g) => Number(g) > 0), `full-row flex-grow: ${grows}`).toBe(true);
        expect(grows[4]).toBe('0');
        expect(r.body, 'a cell got no width share').not.toMatch(/sizes="[^"]*, 1vw"/);
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

    test('LAY-07b: a layout that renders nothing shows the empty state, a text-only one does not', async ({ browser }) => {
        test.skip(!album.id, 'album setup failed');
        layout(album.id, 'empty');
        let r = await anon(browser, `/album/${album.slug}`);
        expect(r.status).toBe(200);
        expect(r.body).toContain('data-layout="album_blocks"');
        expect(r.body).toContain('data-album-empty');
        layout(album.id, 'text-only');
        r = await anon(browser, `/album/${album.slug}`);
        expect(r.body).toContain('LAYOUT-E2E-TEXT-ONLY');
        expect(r.body).not.toContain('data-album-empty');
        expect(imageIds(r.body)).toEqual([]);
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
