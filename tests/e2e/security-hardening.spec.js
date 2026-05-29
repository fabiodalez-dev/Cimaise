// @ts-check
// E2E coverage for the security-hardening review fixes.
//
// Target server: this project ALWAYS uses Apache (httpd) for development at
// http://localhost:8000 — never the PHP built-in server. That means .htaccess
// IS in effect, so the header-dedup (unset+set) and dotfile/traversal blocks are
// exercised exactly as in production. The router.php realpath guard (L4) is a
// dormant safety net only relevant if someone runs `php -S ... public/router.php`;
// under Apache the same protection is enforced by mod_rewrite + the dotfile rule,
// which the B-group tests verify end-to-end regardless of which layer enforces it.
//
// Mapping (review id -> tests):
//   C1  login brute-force throttling decoupled from i18n body text .... A1-A6
//   C2  secret files (.env / .env.backup) never served ................ B1-B2
//   L4  path-traversal confinement (Apache mod_rewrite / dotfile rule)  B3-B5
//   H2  upload decompression-bomb / dimension + MIME guards ........... C1-C5
//   M1  album upload requires auth + CSRF (rate-limited route) ........ C6-C7
//   M2  download emits finfo MIME + nosniff + attachment, no traversal  D1-D4
//   L1  EXIF endpoint returns sanitized, HTML-free JSON ............... E1-E2
//   hdr SecurityHeadersMiddleware posture (CSP / frame / nosniff) ..... F1-F2
//
// All tests skip cleanly when the server (or a seeded admin) is absent, so the
// file is safe to run in any environment. Tests in a single spec file run
// serially, so the brute-force test is placed last and the rate-limit counters
// are wiped in afterAll to avoid locking out the local IP.

import { test, expect } from '@playwright/test';
import {
    BASE, ADMIN_EMAIL, ADMIN_PASSWORD,
    adminLogin, tryAdminLogin, header,
    requireServer, createAlbum, deleteAlbum,
    clearRateLimitFiles, getAlbumCsrf, uploadBuffer, makeJpegBuffer,
} from './_helpers.js';

const SECRET_MARKERS = ['DB_PASSWORD', 'SESSION_SECRET', 'DB_USERNAME'];

// Shared fixture album for the upload/download/exif groups.
let sharedAlbumId = null;
let sharedImageId = null;
let adminAvailable = false;

/** Navigate to the login page (establishing a session) and return its CSRF token. */
async function getLoginCsrf(page) {
    await page.goto(`${BASE}/admin/login`);
    return await page.locator('input[name="csrf"]').first()
        .getAttribute('value', { timeout: 3000 }).catch(() => null);
}

test.beforeAll(async ({ browser }) => {
    clearRateLimitFiles();
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    try {
        adminAvailable = await tryAdminLogin(page);
        if (adminAvailable) {
            const album = await createAlbum(page, `SecTest ${Date.now()}`);
            sharedAlbumId = album.id;
            if (sharedAlbumId) {
                const csrf = await getAlbumCsrf(page, sharedAlbumId);
                if (csrf) {
                    const buf = await makeJpegBuffer(page, 320, 240, '#2563eb');
                    const resp = await uploadBuffer(page, sharedAlbumId,
                        { name: 'fixture.jpg', mimeType: 'image/jpeg', buffer: buf }, csrf);
                    if (resp.ok()) {
                        const data = await resp.json().catch(() => null);
                        sharedImageId = data?.id ?? null;
                    }
                }
            }
        }
    } catch (_e) {
        // leave fixtures null — dependent tests skip
    } finally {
        await ctx.close();
    }
});

test.afterAll(async ({ browser }) => {
    // Remove the fixture album and reset throttle counters poisoned by A5.
    if (sharedAlbumId) {
        const ctx = await browser.newContext();
        const page = await ctx.newPage();
        try {
            if (await tryAdminLogin(page)) await deleteAlbum(page, sharedAlbumId);
        } catch (_e) { /* ignore */ } finally { await ctx.close(); }
    }
    clearRateLimitFiles();
});

// ---------------------------------------------------------------------------
// Group A — Login & brute-force throttling (C1)
// ---------------------------------------------------------------------------
test.describe('A. Auth & login throttling (C1)', () => {
    test('A1: valid admin credentials reach the dashboard', async ({ page }) => {
        await requireServer(test, page);
        const ok = await tryAdminLogin(page);
        test.skip(!ok, 'No seeded admin in this environment');
        await expect(page).toHaveURL(/\/admin\/?(\?.*)?$/);
    });

    test('A2: wrong credentials (real failed-login path) do not grant access', async ({ page }) => {
        await requireServer(test, page);
        // Establish a session + CSRF so the request reaches AuthController's
        // credential check (an empty CSRF would short-circuit at the CSRF guard
        // and never exercise the real failed-login path).
        const csrf = await getLoginCsrf(page);
        test.skip(!csrf, 'Could not obtain a login CSRF token');
        const resp = await page.request.post(`${BASE}/admin/login`, {
            form: { email: 'nobody@example.com', password: 'wrong-password', csrf },
            failOnStatusCode: false,
            maxRedirects: 0,
        });
        // Rendered login (200) or a redirect to /login — never an authenticated /admin.
        const loc = header(resp, 'location') || '';
        expect(loc.includes('/admin') && !loc.includes('/login')).toBeFalsy();
        expect(resp.status()).toBeLessThan(500);
        clearRateLimitFiles();
    });

    test('A3: real failed-login response does NOT leak the X-Auth-Result sentinel', async ({ page }) => {
        await requireServer(test, page);
        const csrf = await getLoginCsrf(page);
        test.skip(!csrf, 'Could not obtain a login CSRF token');
        // Valid CSRF + wrong password -> AuthController sets X-Auth-Result: failed,
        // which the middleware must strip before the response leaves the server.
        const resp = await page.request.post(`${BASE}/admin/login`, {
            form: { email: 'nobody@example.com', password: 'wrong-password', csrf },
            failOnStatusCode: false,
            maxRedirects: 0,
        });
        expect(header(resp, 'x-auth-result')).toBeNull();
        clearRateLimitFiles();
    });

    test('A4: successful-login response does NOT leak the X-Auth-Result sentinel', async ({ page }) => {
        await requireServer(test, page);
        test.skip(!adminAvailable, 'No seeded admin in this environment');
        const csrf = await getLoginCsrf(page);
        test.skip(!csrf, 'Could not obtain a login CSRF token');
        // Drive the actual login POST (not a later GET /admin) so we inspect the
        // very response on which AuthController sets — and the middleware strips —
        // the success sentinel.
        const resp = await page.request.post(`${BASE}/admin/login`, {
            form: { email: ADMIN_EMAIL, password: ADMIN_PASSWORD, csrf },
            failOnStatusCode: false,
            maxRedirects: 0,
        });
        expect(resp.status()).toBe(302);
        expect((header(resp, 'location') || '')).toContain('/admin');
        expect(header(resp, 'x-auth-result')).toBeNull();
        clearRateLimitFiles();
    });

    test('A5: unauthenticated /admin is redirected to login', async ({ page }) => {
        await requireServer(test, page);
        const resp = await page.request.get(`${BASE}/admin`, { failOnStatusCode: false, maxRedirects: 0 });
        // Either a redirect to login or a 401/403 — never a 200 dashboard.
        expect([301, 302, 303, 401, 403]).toContain(resp.status());
    });

    test('A6: repeated failed logins are throttled to HTTP 429 (runs last, then resets)', async ({ page }) => {
        await requireServer(test, page);
        clearRateLimitFiles();
        // A real attack first GETs the login page to obtain a session + CSRF
        // token, then POSTs wrong passwords. (An empty CSRF would hit the
        // "session expired" redirect, which is intentionally NOT counted.)
        await page.goto(`${BASE}/admin/login`);
        const csrf = await page.locator('input[name="csrf"]').first()
            .getAttribute('value', { timeout: 3000 }).catch(() => null);
        test.skip(!csrf, 'Could not obtain a login CSRF token');
        let got429 = false;
        // Default login limit is 5 / 600s; the 6th wrong attempt should 429.
        for (let i = 0; i < 8; i++) {
            const resp = await page.request.post(`${BASE}/admin/login`, {
                form: { email: 'attacker@example.com', password: `bad-${i}`, csrf },
                failOnStatusCode: false,
                maxRedirects: 0,
            });
            if (resp.status() === 429) { got429 = true; break; }
        }
        clearRateLimitFiles(); // do not poison the local IP for other specs
        expect(got429).toBeTruthy();
    });
});

// ---------------------------------------------------------------------------
// Group B — Secret exposure & dev-router traversal (C2, L4)
// ---------------------------------------------------------------------------
test.describe('B. Secret files & path traversal (C2, L4)', () => {
    const cases = [
        { name: 'B1: /.env is not served', url: '/.env' },
        { name: 'B2: /.env.backup is not served', url: '/.env.backup' },
        { name: 'B3: traversal /..%2f.env leaks nothing', url: '/..%2f.env' },
        { name: 'B4: encoded traversal /%2e%2e%2f.env leaks nothing', url: '/%2e%2e%2f.env' },
        { name: 'B5: /.git/config is not served', url: '/.git/config' },
    ];
    for (const c of cases) {
        test(c.name, async ({ page }) => {
            await requireServer(test, page);
            const resp = await page.request.get(`${BASE}${c.url}`, { failOnStatusCode: false });
            const body = await resp.text().catch(() => '');
            for (const marker of SECRET_MARKERS) {
                expect(body.includes(marker), `response should not contain "${marker}"`).toBeFalsy();
            }
            // A served secret would be 200 with a body; we expect a block/redirect/404.
            expect(resp.status()).not.toBe(200);
        });
    }
});

// ---------------------------------------------------------------------------
// Group C — Upload validation (H2, MIME, M1)
// ---------------------------------------------------------------------------
test.describe('C. Upload validation (H2, M1)', () => {
    test('C1: a valid small JPEG uploads successfully', async ({ page }) => {
        await requireServer(test, page);
        test.skip(!adminAvailable || !sharedAlbumId, 'Admin/fixture album unavailable');
        await adminLogin(page);
        const csrf = await getAlbumCsrf(page, sharedAlbumId);
        const buf = await makeJpegBuffer(page, 300, 200, '#16a34a');
        const resp = await uploadBuffer(page, sharedAlbumId,
            { name: 'ok.jpg', mimeType: 'image/jpeg', buffer: buf }, csrf);
        expect(resp.ok()).toBeTruthy();
    });

    test('C2: a text file masquerading as .jpg is rejected (MIME/magic check)', async ({ page }) => {
        await requireServer(test, page);
        test.skip(!adminAvailable || !sharedAlbumId, 'Admin/fixture album unavailable');
        await adminLogin(page);
        const csrf = await getAlbumCsrf(page, sharedAlbumId);
        const fake = Buffer.from('<?php echo "not an image"; ?>\n'.repeat(10), 'utf-8');
        const resp = await uploadBuffer(page, sharedAlbumId,
            { name: 'evil.jpg', mimeType: 'image/jpeg', buffer: fake }, csrf);
        expect(resp.ok()).toBeFalsy();
    });

    test('C3: an over-resolution image is rejected (decompression-bomb guard)', async ({ page }) => {
        await requireServer(test, page);
        test.skip(!adminAvailable || !sharedAlbumId, 'Admin/fixture album unavailable');
        await adminLogin(page);
        const csrf = await getAlbumCsrf(page, sharedAlbumId);
        // 6400 x 6400 = 40.96 MP > 40 MP pixel cap, but each dimension < 20000.
        const buf = await makeJpegBuffer(page, 6400, 6400, '#dc2626');
        const resp = await uploadBuffer(page, sharedAlbumId,
            { name: 'bomb.jpg', mimeType: 'image/jpeg', buffer: buf }, csrf);
        expect(resp.ok()).toBeFalsy();
    });

    test('C4: a PNG within limits uploads successfully', async ({ page }) => {
        await requireServer(test, page);
        test.skip(!adminAvailable || !sharedAlbumId, 'Admin/fixture album unavailable');
        await adminLogin(page);
        const csrf = await getAlbumCsrf(page, sharedAlbumId);
        const dataUrl = await page.evaluate(() => {
            const c = document.createElement('canvas');
            c.width = 64; c.height = 64;
            const ctx = c.getContext('2d');
            ctx.fillStyle = '#9333ea'; ctx.fillRect(0, 0, 64, 64);
            return c.toDataURL('image/png');
        });
        const buf = Buffer.from(dataUrl.replace(/^data:image\/png;base64,/, ''), 'base64');
        const resp = await uploadBuffer(page, sharedAlbumId,
            { name: 'ok.png', mimeType: 'image/png', buffer: buf }, csrf);
        expect(resp.ok()).toBeTruthy();
    });

    test('C5: a zero-byte file is rejected', async ({ page }) => {
        await requireServer(test, page);
        test.skip(!adminAvailable || !sharedAlbumId, 'Admin/fixture album unavailable');
        await adminLogin(page);
        const csrf = await getAlbumCsrf(page, sharedAlbumId);
        const resp = await uploadBuffer(page, sharedAlbumId,
            { name: 'empty.jpg', mimeType: 'image/jpeg', buffer: Buffer.alloc(0) }, csrf);
        expect(resp.ok()).toBeFalsy();
    });

    test('C6: album upload without authentication is denied', async ({ page }) => {
        await requireServer(test, page);
        test.skip(!sharedAlbumId, 'No fixture album');
        // Fresh context (no admin cookies) via a one-off request.
        const buf = await makeJpegBuffer(page, 100, 100);
        const resp = await page.request.post(`${BASE}/admin/albums/${sharedAlbumId}/upload`, {
            multipart: { file: { name: 'x.jpg', mimeType: 'image/jpeg', buffer: buf } },
            failOnStatusCode: false,
            maxRedirects: 0,
        });
        // Refused by either the auth layer (redirect/401/403) or the global CSRF
        // layer (400) — never accepted. The upload must not succeed.
        expect(resp.ok()).toBeFalsy();
        expect(resp.status()).toBeGreaterThanOrEqual(300);
    });

    test('C7: authenticated album upload without CSRF token is rejected', async ({ page }) => {
        await requireServer(test, page);
        test.skip(!adminAvailable || !sharedAlbumId, 'Admin/fixture album unavailable');
        await adminLogin(page);
        const buf = await makeJpegBuffer(page, 120, 120);
        const resp = await page.request.post(`${BASE}/admin/albums/${sharedAlbumId}/upload`, {
            multipart: { file: { name: 'nocsrf.jpg', mimeType: 'image/jpeg', buffer: buf } },
            headers: { 'Accept': 'application/json' },
            failOnStatusCode: false,
        });
        expect(resp.ok()).toBeFalsy();
    });
});

// ---------------------------------------------------------------------------
// Group D — Download / media serving (M2)
// ---------------------------------------------------------------------------
test.describe('D. Download hardening (M2)', () => {
    test('D1: image download returns an image/* Content-Type when served', async ({ page }) => {
        await requireServer(test, page);
        test.skip(!sharedImageId, 'No fixture image to download');
        const resp = await page.request.get(`${BASE}/download/image/${sharedImageId}`, { failOnStatusCode: false });
        if (resp.status() === 200) {
            expect((header(resp, 'content-type') || '')).toMatch(/^image\//);
        } else {
            // Downloads may be disabled by settings; just assert it's controlled.
            expect([403, 404]).toContain(resp.status());
        }
    });

    test('D2: image download sets X-Content-Type-Options: nosniff', async ({ page }) => {
        await requireServer(test, page);
        test.skip(!sharedImageId, 'No fixture image to download');
        const resp = await page.request.get(`${BASE}/download/image/${sharedImageId}`, { failOnStatusCode: false });
        if (resp.status() === 200) {
            expect((header(resp, 'x-content-type-options') || '').toLowerCase()).toBe('nosniff');
        } else {
            test.skip(true, `download not served (status ${resp.status()})`);
        }
    });

    test('D3: image download is sent as an attachment', async ({ page }) => {
        await requireServer(test, page);
        test.skip(!sharedImageId, 'No fixture image to download');
        const resp = await page.request.get(`${BASE}/download/image/${sharedImageId}`, { failOnStatusCode: false });
        if (resp.status() === 200) {
            expect((header(resp, 'content-disposition') || '')).toContain('attachment');
        } else {
            test.skip(true, `download not served (status ${resp.status()})`);
        }
    });

    test('D4: a non-numeric / bogus download id never 500s', async ({ page }) => {
        await requireServer(test, page);
        const resp = await page.request.get(`${BASE}/download/image/999999999`, { failOnStatusCode: false });
        expect(resp.status()).toBeLessThan(500);
    });
});

// ---------------------------------------------------------------------------
// Group E — EXIF endpoint safety (L1)
// ---------------------------------------------------------------------------
test.describe('E. EXIF endpoint safety (L1)', () => {
    test('E1: public EXIF endpoint returns valid JSON with no HTML script payload', async ({ page }) => {
        await requireServer(test, page);
        test.skip(!sharedImageId, 'No fixture image');
        const resp = await page.request.get(`${BASE}/api/image/${sharedImageId}/exif`, { failOnStatusCode: false });
        expect(resp.status()).toBeLessThan(500);
        if (resp.status() === 200) {
            const body = await resp.text();
            expect(body.toLowerCase()).not.toContain('<script');
            expect(() => JSON.parse(body)).not.toThrow();
        }
    });

    test('E2: EXIF payload contains no raw HTML tags', async ({ page }) => {
        await requireServer(test, page);
        test.skip(!sharedImageId, 'No fixture image');
        const resp = await page.request.get(`${BASE}/api/image/${sharedImageId}/exif`, { failOnStatusCode: false });
        if (resp.status() !== 200) test.skip(true, `EXIF endpoint status ${resp.status()}`);
        const data = await resp.json().catch(() => ({}));
        const flat = JSON.stringify(data);
        // cleanString() strips tags at ingest, so no "<...>" sequence should survive.
        expect(/<[a-z!\/]/i.test(flat)).toBeFalsy();
    });
});

// ---------------------------------------------------------------------------
// Group F — Security headers posture (SecurityHeadersMiddleware)
// ---------------------------------------------------------------------------
test.describe('F. Security headers', () => {
    test('F1: frontend home carries a Content-Security-Policy header', async ({ page }) => {
        await requireServer(test, page);
        const resp = await page.request.get(`${BASE}/`, { failOnStatusCode: false });
        expect(header(resp, 'content-security-policy')).toBeTruthy();
    });

    test('F2: frontend home sets X-Frame-Options DENY and nosniff', async ({ page }) => {
        await requireServer(test, page);
        const resp = await page.request.get(`${BASE}/`, { failOnStatusCode: false });
        expect((header(resp, 'x-frame-options') || '').toUpperCase()).toBe('DENY');
        expect((header(resp, 'x-content-type-options') || '').toLowerCase()).toBe('nosniff');
    });
});
