// @ts-check
// Settings that are saved in the admin must take effect on the public site
// IMMEDIATELY (no 24h page-cache TTL) and must honour an explicit "off".
//
// Regression coverage for:
//  - Twig `|default(true)` swallowing FALSE (right-click blocker, cookie-banner
//    consent gate, image JSON-LD) — an unchecked toggle used to be ignored.
//  - JSON-LD (ImageGallery / BreadcrumbList / WebSite) still emitted with
//    seo.schema_enabled off.
//  - page_cache not invalidated by /admin/settings and /admin/social saves
//    (gallery page template, default gallery template, share buttons).
//  - SEO toggles that were saved but never read (keywords, lazy loading,
//    LCP preload, areaServed).
import { test, expect } from '@playwright/test';
import {
    BASE, adminLogin, createAlbum, uploadCover, deleteAlbum, requireServer, requireAdmin,
} from './_helpers.js';

/** Serialize an admin form exactly as the browser would (unchecked boxes
 *  omitted) and POST it out-of-band. Avoids racing the admin SPA navigation. */
async function readForm(page, url, formSel) {
    await page.goto(url, { waitUntil: 'load' });
    return page.$eval(formSel, (f) => {
        const out = {};
        for (const [k, v] of new FormData(f).entries()) if (typeof v === 'string') out[k] = v;
        return out;
    });
}
async function postForm(page, url, form) {
    const r = await page.request.post(url, { form, maxRedirects: 0, failOnStatusCode: false });
    expect(r.status(), `POST ${url}`).toBe(302);
}
/** Apply overrides to the settings form: `null` removes a key (unchecked box). */
function withOverrides(form, overrides) {
    const out = { ...form };
    for (const [k, v] of Object.entries(overrides)) {
        if (v === null) delete out[k]; else out[k] = v;
    }
    return out;
}
async function anonGet(browser, path) {
    const ctx = await browser.newContext();
    try {
        const r = await ctx.request.get(`${BASE}${path}`);
        return { status: r.status(), headers: r.headers(), body: await r.text() };
    } finally {
        await ctx.close();
    }
}

const SETTINGS_URL = `${BASE}/admin/settings`;
const SEO_URL = `${BASE}/admin/seo`;
const SOCIAL_URL = `${BASE}/admin/social`;

test.describe.serial('Admin settings take effect on the frontend', () => {
    let page;
    let settingsOrig;
    let seoOrig;
    let album = { id: null, slug: null };

    test.beforeAll(async ({ browser }) => {
        page = await browser.newPage();
        await requireServer(test, page);
        await requireAdmin(test, page);
        settingsOrig = await readForm(page, SETTINGS_URL, '#settings-form');
        seoOrig = await readForm(page, SEO_URL, '#seo-form');
        // A public album WITHOUT its own gallery template so the default
        // template / page template settings drive its rendering.
        album = await createAlbum(page, `SFX ${Date.now()}`);
        if (album.id) await uploadCover(page, album.id, 'SFX', '#0ea5e9');
    });

    test.afterAll(async () => {
        try {
            if (settingsOrig) await postForm(page, SETTINGS_URL, settingsOrig);
            if (seoOrig) await postForm(page, SEO_URL, seoOrig);
            if (album.id) await deleteAlbum(page, album.id);
        } finally {
            await page.close();
        }
    });

    test('SFX-01: right-click blocker is removed when the toggle is OFF', async ({ browser }) => {
        await postForm(page, SETTINGS_URL, withOverrides(settingsOrig, { disable_right_click: null }));
        const off = await anonGet(browser, '/');
        expect(off.body).not.toContain("addEventListener('contextmenu'");

        await postForm(page, SETTINGS_URL, withOverrides(settingsOrig, { disable_right_click: '1' }));
        const on = await anonGet(browser, '/');
        expect(on.body).toContain("addEventListener('contextmenu'");
    });

    test('SFX-02: default gallery template change applies to an already-cached album', async ({ browser }) => {
        test.skip(!album.slug, 'album fixture unavailable');
        // Prime the page cache with template 2 (masonry portfolio).
        await postForm(page, SETTINGS_URL, withOverrides(settingsOrig, { default_template_id: '2' }));
        let r = await anonGet(browser, `/album/${album.slug}`);
        expect(r.body).toMatch(/id="images-gallery"[^>]*data-layout="masonry_portfolio"/);
        r = await anonGet(browser, `/album/${album.slug}`); // cache hit
        expect(r.body).toMatch(/data-layout="masonry_portfolio"/);

        // Switch to template 5 (dense grid): must show up at once.
        await postForm(page, SETTINGS_URL, withOverrides(settingsOrig, { default_template_id: '5' }));
        r = await anonGet(browser, `/album/${album.slug}`);
        expect(r.body).toMatch(/id="images-gallery"[^>]*data-layout="dense_grid"/);
    });

    test('SFX-03: album page template (classic/hero) change applies to a cached album', async ({ browser }) => {
        test.skip(!album.slug, 'album fixture unavailable');
        await postForm(page, SETTINGS_URL, withOverrides(settingsOrig, { gallery_page_template: 'classic' }));
        let r = await anonGet(browser, `/album/${album.slug}`);
        expect(r.body).not.toContain('data-hero-image="1"');
        await postForm(page, SETTINGS_URL, withOverrides(settingsOrig, { gallery_page_template: 'hero' }));
        r = await anonGet(browser, `/album/${album.slug}`);
        expect(r.body).toContain('data-hero-image="1"');
    });

    test('SFX-04: pagination limit resizes the (cached) home album list at once', async ({ browser }) => {
        await postForm(page, SETTINGS_URL, withOverrides(settingsOrig, { pagination_limit: '1' }));
        const r = await anonGet(browser, '/');
        const ids = new Set([...r.body.matchAll(/data-album-id="(\d+)"/g)].map((m) => m[1]));
        expect(ids.size).toBeLessThanOrEqual(1);
    });

    test('SFX-05: schema off removes every JSON-LD block (album + home)', async ({ browser }) => {
        test.skip(!album.slug, 'album fixture unavailable');
        await postForm(page, SEO_URL, withOverrides(seoOrig, { schema_enabled: null, breadcrumbs_enabled: null }));
        const a = await anonGet(browser, `/album/${album.slug}`);
        expect(a.body).not.toContain('application/ld+json');
        const h = await anonGet(browser, '/');
        expect(h.body).not.toContain('application/ld+json');

        await postForm(page, SEO_URL, withOverrides(seoOrig, { schema_enabled: '1', breadcrumbs_enabled: '1' }));
        const b = await anonGet(browser, `/album/${album.slug}`);
        expect(b.body).toContain('"@type":"ImageGallery"');
        expect(b.body).toContain('"@type": "BreadcrumbList"');
    });

    test('SFX-06: keywords + areaServed are emitted when set', async ({ browser }) => {
        await postForm(page, SEO_URL, withOverrides(seoOrig, {
            schema_enabled: '1',
            site_keywords: 'sfx-kw-one, sfx-kw-two',
            author_name: 'SFX Author',
            photographer_area_served: 'SFX City',
        }));
        const r = await anonGet(browser, '/');
        // html_attr-escaped (spaces/commas become &#x..;) — match the tokens only.
        const kw = (r.body.match(/<meta name="keywords" content="([^"]*)"/) || [])[1] || '';
        expect(kw).toContain('sfx-kw-one');
        expect(kw).toContain('sfx-kw-two');
        expect(r.body).toContain('"areaServed":"SFX City"');
    });

    test('SFX-07: lazy loading / LCP preload toggles are honoured', async ({ browser }) => {
        test.skip(!album.slug, 'album fixture unavailable');
        await postForm(page, SEO_URL, withOverrides(seoOrig, { lazy_load_images: null, preload_critical_images: null }));
        const a = await anonGet(browser, `/album/${album.slug}`);
        // Only real <img> tags count: the gallery runtime JS contains the
        // literal selector string img[loading="lazy"].
        expect(a.body).not.toMatch(/<img[^>]*loading="lazy"/);
        expect(a.body).toMatch(/<img[^>]*loading="eager"/);
        const h = await anonGet(browser, '/');
        expect(h.body).not.toMatch(/rel="preload" as="image"/);

        await postForm(page, SEO_URL, withOverrides(seoOrig, { lazy_load_images: '1', preload_critical_images: '1' }));
        const b = await anonGet(browser, `/album/${album.slug}`);
        expect(b.body).toMatch(/<img[^>]*loading="lazy"/);
    });

    test('SFX-08: social share button changes reach a cached album page immediately', async ({ browser }) => {
        test.skip(!album.slug, 'album fixture unavailable');
        const socialOrig = await readForm(page, SOCIAL_URL, 'form[action$="/admin/social"]');
        try {
            // Prime cache with the current share buttons, then disable them all.
            await anonGet(browser, `/album/${album.slug}`);
            const none = Object.fromEntries(Object.entries(socialOrig).filter(([k]) => !k.startsWith('social')));
            none.csrf = socialOrig.csrf;
            await postForm(page, SOCIAL_URL, none);
            const r = await anonGet(browser, `/album/${album.slug}`);
            expect(r.body).not.toContain('data-share-action=');
        } finally {
            await postForm(page, SOCIAL_URL, socialOrig);
        }
    });
});
