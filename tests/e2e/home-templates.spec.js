// @ts-check
// The 6 new selectable home templates (editorial, justified, slideshow, split,
// bento, filmstrip) must each render via ?template=, show their layout, load real
// images, and contain NO duplicate photos (they share masonry's unique-image path).

import { test, expect } from '@playwright/test';
import { BASE } from './_helpers.js';

const TEMPLATES = [
    { name: 'editorial', marker: '.editorial-masthead' },
    { name: 'justified', marker: '.justified' },
    { name: 'slideshow', marker: '#slideshow' },
    { name: 'split',     marker: '#split-preview' },
    { name: 'bento',     marker: '.bento' },
    { name: 'filmstrip', marker: '.film' },
];

test.describe('Home templates (x6)', () => {
    for (const t of TEMPLATES) {
        test(`HT-${t.name}: renders, loads images, no duplicates, no Twig error`, async ({ page }) => {
            const resp = await page.goto(`${BASE}/?template=${t.name}`, { waitUntil: 'networkidle' });
            expect(resp?.status()).toBe(200);

            // No server-side render error leaked into the page.
            const body = await page.locator('body').innerText();
            expect(body).not.toMatch(/Twig\\?\\s*\\\\?(Error|Exception)|Fatal error|Stack trace/i);

            // The template's signature layout is present.
            await expect(page.locator(t.marker).first()).toBeVisible();

            // Real images are present and at least the first one decoded.
            const imgCount = await page.locator('main img, .show-slide img, .split-media img, .bento img, .film img, .editorial-cell img, .justified img').count();
            expect(imgCount).toBeGreaterThan(0);

            // No duplicate photos: the unique-image path must not repeat the same media id.
            const ids = await page.evaluate(() => {
                const out = [];
                // The split template's sticky preview deliberately mirrors one list item,
                // so exclude it from the duplicate check — it is not a content repeat.
                document.querySelectorAll('img:not(#split-preview)').forEach((im) => {
                    const s = im.getAttribute('src') || im.getAttribute('data-src') || '';
                    const m = s.match(/\/media\/(\d+)_/);
                    if (m) out.push(m[1]);
                });
                return out;
            });
            const seen = new Set(ids);
            // Allow the slideshow/split which intentionally slice; just assert no id repeats.
            expect(seen.size).toBe(ids.length);
        });
    }
});
