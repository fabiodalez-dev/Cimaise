// @ts-check
// Regression: admin forms must survive a stale CSRF token.
//
// Admin pages bake the session CSRF token into a hidden field at render time.
// When that token later rotates (remember-me re-auth, re-login in another tab,
// session GC) the baked token goes stale and a normal submit used to die with a
// hard "Invalid CSRF token" 400 — losing the user's input with no recovery path.
// The admin layout now refreshes the token from GET /admin/csrf-token right before
// every POST submit, so a stale token self-heals. These tests simulate staleness
// by corrupting the hidden field and asserting the save still goes through.

import { test, expect } from '@playwright/test';
import { BASE, requireAdmin } from './_helpers.js';

test.describe.serial('Admin CSRF resilience', () => {
    let page;
    test.beforeAll(async ({ browser }) => {
        page = await browser.newPage();
        await requireAdmin(test, page);
    });
    test.afterAll(async () => { await page?.close(); });

    test('CSRF-HEAL-01: GET /admin/csrf-token returns the live session token (authenticated)', async () => {
        const res = await page.request.get(`${BASE}/admin/csrf-token`);
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(typeof body.token).toBe('string');
        expect(body.token).toMatch(/^[a-f0-9]{64}$/);
    });

    test('CSRF-HEAL-02: stale token in the home page form self-heals on submit', async () => {
        await page.goto(`${BASE}/admin/pages/home`, { waitUntil: 'networkidle' });

        // The fix must be present on the page.
        const hasFix = await page.evaluate(() => typeof window.syncCsrfTokens === 'function');
        expect(hasFix).toBe(true);

        // Simulate a rotated/stale token in the already-rendered form.
        await page.evaluate(() => {
            const f = document.getElementById('home-form');
            const c = f.querySelector('input[name="csrf"]');
            c.value = 'STALE_INVALID_TOKEN_0000000000000000000000000000000000000000';
        });

        // Submit. Without the fix the POST is a 400 "Invalid CSRF token"; with the fix
        // the token is refreshed first, so the controller accepts it and 302-redirects.
        const [postResp] = await Promise.all([
            page.waitForResponse(r => r.url().includes('/admin/pages/home') && r.request().method() === 'POST'),
            page.waitForLoadState('networkidle'),
            page.click('button[type="submit"][form="home-form"]'),
        ]);
        // 302 redirect == accepted; 400 == rejected stale token.
        expect(postResp.status()).not.toBe(400);
        expect([301, 302, 303].includes(postResp.status())).toBe(true);

        // Let the 302 -> GET redirect settle before inspecting the landed page.
        await page.waitForLoadState('networkidle');
        // We landed back on the real editor (not the bare 400 "Invalid CSRF token" page).
        expect(page.url()).toMatch(/\/admin\/pages\/home/);
        await expect(page.locator('#home-form')).toHaveCount(1);
        const text = await page.locator('body').innerText();
        expect(text).not.toMatch(/invalid csrf|csrf.*non valido|token non valido/i);
    });

    test('CSRF-HEAL-03: the refreshed field holds a valid 64-hex token after heal', async () => {
        await page.goto(`${BASE}/admin/pages/home`, { waitUntil: 'networkidle' });
        await page.evaluate(() => {
            document.querySelector('#home-form input[name="csrf"]').value = 'STALE';
        });
        // Drive the same refresh path the submit handler uses and assert the result.
        const token = await page.evaluate(async () => {
            const r = await fetch('/admin/csrf-token', { headers: { Accept: 'application/json' }, cache: 'no-store' });
            const d = await r.json();
            window.syncCsrfTokens(d.token);
            return document.querySelector('#home-form input[name="csrf"]').value;
        });
        expect(token).toMatch(/^[a-f0-9]{64}$/);
    });

    // Regression for the review findings: the interceptor must bind exactly once even
    // though the admin SPA re-executes layout <script> blocks on every pushState
    // navigation. A stacked listener would fire N token fetches / N submits per click.
    test('CSRF-HEAL-04: interceptor binds once across SPA navigations (no stacking)', async () => {
        await page.goto(`${BASE}/admin/pages`, { waitUntil: 'networkidle' });

        // SPA-navigate a few times (pushState, no full reload) to re-run the layout script.
        for (const path of ['/admin', '/admin/pages', '/admin/pages/home']) {
            await page.evaluate((p) => {
                const link = document.querySelector(`a[href$="${p}"]`);
                if (link) link.click();
            }, path);
            await page.waitForTimeout(400); // let the SPA swap + re-exec scripts settle
        }
        await page.waitForLoadState('networkidle');

        // The bind-once guard must hold regardless of how many navigations happened.
        const boundOnce = await page.evaluate(() => window.__csrfResilienceBound === true);
        expect(boundOnce).toBe(true);

        // Count token fetches triggered by a single submit: exactly one (one live listener).
        await page.evaluate(() => {
            const f = document.getElementById('home-form');
            if (f) f.querySelector('input[name="csrf"]').value = 'STALE';
        });
        let tokenFetches = 0;
        const onReq = (req) => { if (req.url().includes('/admin/csrf-token')) tokenFetches++; };
        page.on('request', onReq);
        const [postResp] = await Promise.all([
            page.waitForResponse(r => r.url().includes('/admin/pages/home') && r.request().method() === 'POST'),
            page.click('button[type="submit"][form="home-form"]'),
        ]);
        await page.waitForLoadState('networkidle');
        page.off('request', onReq);

        expect(tokenFetches).toBe(1);              // single listener → single refresh
        expect(postResp.status()).not.toBe(400);   // and it still self-heals
    });
});
