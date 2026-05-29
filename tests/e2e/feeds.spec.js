// @ts-check
// Syndication feeds: RSS 2.0 (/feed.xml) and Atom 1.0 (/feed/atom), plus the
// <link rel="alternate"> autodiscovery in the public layouts.
//
// Structural / transport-level coverage. The visibility rule (published, non
// password-protected, non NSFW) is exercised at the unit level in
// tests/Services/FeedServiceTest.php.

import { test, expect } from '@playwright/test';
import { BASE, requireServer } from './_helpers.js';

test.describe('Syndication feeds', () => {
    test('RSS-01: /feed.xml is a well-formed RSS 2.0 document', async ({ page }) => {
        await requireServer(test, page);
        const res = await page.request.get(`${BASE}/feed.xml`);
        expect(res.status()).toBe(200);
        expect(res.headers()['content-type']).toContain('application/rss+xml');

        const body = await res.text();
        expect(body).toContain('<rss');
        expect(body).toContain('version="2.0"');
        expect(body).toContain('<channel>');
        // No PHP error / stack trace leaked into the feed.
        expect(body).not.toContain('Fatal error');
        expect(body).not.toContain('Stack trace');
        expect(body).not.toContain('SQLSTATE');
    });

    test('RSS-02: items carry absolute permalinks', async ({ page }) => {
        await requireServer(test, page);
        const body = await (await page.request.get(`${BASE}/feed.xml`)).text();
        if (!body.includes('<item>')) {
            test.skip(true, 'No published albums to syndicate');
        }
        // Each link is an absolute URL to an album page.
        expect(body).toMatch(/<link>https?:\/\/[^<]+\/album\/[^<]+<\/link>/);
        expect(body).toContain('isPermaLink="true"');
    });

    test('ATOM-01: /feed/atom is a well-formed Atom 1.0 document', async ({ page }) => {
        await requireServer(test, page);
        const res = await page.request.get(`${BASE}/feed/atom`);
        expect(res.status()).toBe(200);
        expect(res.headers()['content-type']).toContain('application/atom+xml');

        const body = await res.text();
        expect(body).toContain('<feed');
        expect(body).toContain('http://www.w3.org/2005/Atom');
        expect(body).toContain('<updated>');
        expect(body).not.toContain('Fatal error');
    });

    test('DISC-01: autodiscovery links are present on the home page', async ({ page }) => {
        await requireServer(test, page);
        await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' });
        await expect(page.locator('link[rel="alternate"][type="application/rss+xml"]')).toHaveCount(1);
        await expect(page.locator('link[rel="alternate"][type="application/atom+xml"]')).toHaveCount(1);
        const rssHref = await page.locator('link[rel="alternate"][type="application/rss+xml"]').getAttribute('href');
        expect(rssHref).toContain('/feed.xml');
    });
});
