// @ts-check
// Media self-heal (v1.4.14). A broken <picture> media image — e.g. a corrupt /
// stale cache entry, the class of failure that made photos render as broken on
// load — recovers automatically client-side: the self-heal listener strips the
// failed <source> variants, reloads the universally-decodable JPEG baseline
// (cache-busted), and — via the data-swHealed gate — is NOT hidden by the
// data-fallback="hide" handler. Also asserts both home layouts ship the shared
// PWA runtime (versioned SW registration + self-heal). Regression guard for the
// review fixes: self-heal display:none conflict, <picture> ineffectiveness, and
// the modern-layout coverage gap.

import { test, expect } from '@playwright/test';
import { BASE, requireServer } from './_helpers.js';

test.describe('Media self-heal', () => {
    // Disable the Service Worker for this suite. In a fresh context the SW
    // installs + takes control and fires controllerchange -> location.reload()
    // (intentional, to pick up new SW versions), which would abort the test's
    // navigations and destroy the evaluate context. The self-heal under test is
    // page JS and works without the SW (its EVICT postMessage is a guarded no-op
    // when there is no controller), so blocking SWs isolates the behaviour.
    test.use({ serviceWorkers: 'block' });

    test.beforeEach(async ({ page }) => {
        await requireServer(test, page);
    });

    test('SH-01: a broken <picture> media image self-heals to the JPEG baseline and stays visible', async ({ page }) => {
        await page.goto(`${BASE}/`, { waitUntil: 'load' });

        // The bare <img src> the grid renders is the JPEG baseline (forced in the
        // templates). Grab a real one so the test uses data that actually exists.
        const realJpg = await page.evaluate(() => {
            const img = document.querySelector('img[src*="/media/"]');
            return img ? img.getAttribute('src') : null;
        });
        expect(realJpg, 'home should render at least one /media/ image').toBeTruthy();

        const result = await page.evaluate(async (jpg) => {
            // A <picture> whose avif <source> points at a non-existent /media file
            // (404) mimics a corrupt/failed variant; the bare <img> is the real jpg
            // baseline. The self-heal window listener (shared PWA partial) should
            // recover it.
            const pic = document.createElement('picture');
            const s = document.createElement('source');
            s.type = 'image/avif';
            s.srcset = '/media/zzz_selfheal_probe_999999_xxl.avif';
            const img = document.createElement('img');
            img.src = jpg;
            img.setAttribute('data-fallback', 'hide');
            // The page binds the data-fallback hide handler only to imgs present at
            // DOMContentLoaded; replicate its current logic (with the swHealed gate)
            // on this injected img so the gate is genuinely exercised.
            img.addEventListener('error', function () {
                if (this.dataset.swHealed) return;
                this.style.display = 'none';
            });
            pic.appendChild(s);
            pic.appendChild(img);
            document.body.appendChild(pic);
            // Event-driven + short cap: the heal (avif 404 -> error -> strip
            // sources -> reload jpg) settles in well under a second locally;
            // a fixed multi-second wait risks crossing the home's own late
            // re-render and destroying this context.
            await new Promise((resolve) => {
                let done = false;
                const finish = () => { if (!done) { done = true; resolve(); } };
                img.addEventListener('load', finish);
                setTimeout(finish, 1200);
            });
            return {
                healed: img.dataset.swHealed || null,
                display: getComputedStyle(img).display,
                sourceCount: pic.querySelectorAll('source').length,
                finalSrc: img.currentSrc || img.src || '',
                naturalW: img.naturalWidth,
            };
        }, realJpg);

        expect(result.healed, 'self-heal should fire on the broken media image').toBe('1');
        expect(result.sourceCount, 'the failed <source> variants should be stripped').toBe(0);
        expect(result.display, 'a self-healed image must NOT be hidden by data-fallback').not.toBe('none');
        expect(result.finalSrc, 'should reload the jpg baseline, cache-busted').toContain('swcb=');
        expect(result.finalSrc, 'the reloaded src must be the jpg, not an avif/webp/jxl').not.toMatch(/\.(avif|webp|jxl)(\?|$)/);
        expect(result.naturalW, 'the recovered jpg should decode (naturalWidth > 0)').toBeGreaterThan(0);
    });

    test('SH-02: both home layouts ship the versioned SW registration + self-heal runtime', async ({ page }) => {
        for (const url of [`${BASE}/`, `${BASE}/?template=modern`]) {
            const resp = await page.goto(url, { waitUntil: 'load' });
            expect(resp && resp.status(), `${url} should return 200`).toBe(200);
            const html = await page.content();
            expect(html, `${url} should register the SW with a version query`).toMatch(/sw\.js\?v=[\d.]+/);
            expect(html, `${url} should carry the self-heal runtime`).toContain('swHealed');
        }
    });
});
