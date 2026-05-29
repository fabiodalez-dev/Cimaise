// @ts-check
// Shared utilities for the e2e test suite.
// Import from individual spec files via `import { ... } from './_helpers.js';`.

import { expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const PROJECT_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

export const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8000';
export const ADMIN_EMAIL = process.env.TEST_ADMIN_EMAIL || 'admin@test.com';
export const ADMIN_PASSWORD = process.env.TEST_ADMIN_PASSWORD || 'TestPass123!';

export const ADMIN_DASHBOARD_RE = /\/admin\/?(\?.*)?$/;
export const ADMIN_ALBUMS_LIST_RE = /\/admin\/albums\/?(\?.*)?$/;
// Post-create redirect destination — the controller sends the user to the
// edit page of the freshly created album (NOT back to the list). Used by
// createAlbum() to know when the submit has actually landed somewhere we
// can read the new album id from.
export const ADMIN_ALBUM_EDIT_RE = /\/admin\/albums\/\d+\/edit\/?(\?.*)?$/;

/** Log in as the test admin via the admin login form. */
export async function adminLogin(page) {
    await page.goto(`${BASE}/admin/login`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASSWORD);
    await Promise.all([
        page.waitForURL(ADMIN_DASHBOARD_RE, { timeout: 15000 }),
        page.click('button[type="submit"]'),
    ]);
}

/** Best-effort admin-login: returns true on success, false if the dashboard
 *  is not reached within a short window. Useful for tests that should skip
 *  cleanly when the env doesn't have a seeded admin. */
export async function tryAdminLogin(page) {
    try {
        await adminLogin(page);
        return true;
    } catch (_e) {
        return false;
    }
}

/** Create an album from the admin /admin/albums/create form. Returns {id, slug}.
 *  Mirrors the proven pattern in nsfw-password-complete.spec.js verbatim. */
export async function createAlbum(page, title, opts = {}) {
    const { isNsfw = false, password = null } = opts;
    await page.goto(`${BASE}/admin/albums/create`);
    await page.waitForSelector('form#album-form', { timeout: 5000 });
    await page.fill('input[name="title"]', title);
    await page.fill('textarea[name="excerpt"]', `Test album: ${title}`);
    if (password) {
        await page.fill('input[name="password"]', password);
    }
    const nsfwBox = page.locator('input[name="is_nsfw"]');
    await nsfwBox.scrollIntoViewIfNeeded();
    if (isNsfw && !(await nsfwBox.isChecked())) await nsfwBox.check({ force: true });
    if (!isNsfw && (await nsfwBox.isChecked())) await nsfwBox.uncheck({ force: true });
    // The controller redirects to /admin/albums/<id>/edit on success.
    // Wait for that URL — fall back to /admin/albums (list) if some build
    // routes back there instead, then walk the list.
    await Promise.all([
        page.waitForURL(
            (url) => ADMIN_ALBUM_EDIT_RE.test(url.pathname + url.search)
                  || ADMIN_ALBUMS_LIST_RE.test(url.pathname + url.search),
            { timeout: 15000 },
        ),
        page.click('button[type="submit"][form="album-form"]'),
    ]);
    // Pick the id directly from the URL when we landed on the edit page.
    const currentUrl = page.url();
    const m = currentUrl.match(/\/admin\/albums\/(\d+)\/edit/);
    if (m) {
        const id = Number(m[1]);
        const slug = await page.locator('input[name="slug"]').inputValue().catch(() => null);
        return { id, slug };
    }
    // Fallback: scan the listing for the freshly created title.
    await page.goto(`${BASE}/admin/albums`);
    const link = page.locator(`a:has-text("${title}")`).first();
    const href = await link.getAttribute('href').catch(() => null);
    const id = href?.match(/\/albums\/(\d+)/)?.[1];
    if (!id) return { id: null, slug: null };
    await page.goto(`${BASE}/admin/albums/${id}/edit`);
    const slug = await page.locator('input[name="slug"]').inputValue().catch(() => null);
    return { id: Number(id), slug };
}

function escapeRegExp(s) {
    return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** Upload a small canvas-generated JPEG to an album and optionally set as cover.
 *  Synthesizes the JPEG bytes in the page, then uses Playwright's
 *  APIRequestContext to POST the multipart upload — avoids the in-page
 *  fetch failing opaquely when a worker dies or a CSP edge case bites. */
export async function uploadCover(page, albumId, label = 'Cover', color = '#3b82f6') {
    await page.goto(`${BASE}/admin/albums/${albumId}/edit`);
    await page.waitForSelector('#uppy', { timeout: 5000 });

    const csrf = await page.locator('input[name="csrf"]').first().getAttribute('value', { timeout: 2000 }).catch(() => null)
              || await page.locator('#uppy').first().getAttribute('data-csrf', { timeout: 500 }).catch(() => null);
    if (!csrf) return { ok: false, imageId: null };

    // Render the test image as a data: URL inside the page, then transport
    // the raw bytes to node so we can POST the multipart from outside.
    const dataUrl = await page.evaluate(({ label, color }) => {
        const canvas = document.createElement('canvas');
        canvas.width = 200; canvas.height = 200;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = color; ctx.fillRect(0, 0, 200, 200);
        ctx.fillStyle = '#fff'; ctx.font = '20px sans-serif';
        ctx.fillText(label, 20, 110);
        return canvas.toDataURL('image/jpeg', 0.9);
    }, { label, color });

    const base64 = dataUrl.replace(/^data:image\/jpeg;base64,/, '');
    const buffer = Buffer.from(base64, 'base64');

    const uploadResp = await page.request.post(`${BASE}/admin/albums/${albumId}/upload`, {
        headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
        multipart: {
            file: { name: `test-${label}.jpg`, mimeType: 'image/jpeg', buffer },
            csrf,
        },
        failOnStatusCode: false,
    }).catch(() => null);
    if (!uploadResp || !uploadResp.ok()) return { ok: false, imageId: null };
    const data = await uploadResp.json().catch(() => null);
    const imageId = data?.id || null;

    if (imageId) {
        await page.request.post(`${BASE}/admin/albums/${albumId}/cover/${imageId}`, {
            headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
            form: { csrf },
            failOnStatusCode: false,
        }).catch(() => {});
    }

    return { ok: !!imageId, imageId };
}

/** Delete an album (admin) by id. Best-effort; ignores 404 / missing CSRF. */
export async function deleteAlbum(page, albumId) {
    if (!albumId) return;
    // Use the album EDIT page — it always renders a hidden `input[name="csrf"]`
    // in the surrounding admin form. The albums list page does not expose the
    // CSRF in a meta tag, so probing for one there hangs Playwright until the
    // default 30s locator timeout.
    await page.goto(`${BASE}/admin/albums/${albumId}/edit`);
    const csrfField = page.locator('input[name="csrf"]').first();
    const csrf = await csrfField.getAttribute('value', { timeout: 2000 }).catch(() => null);
    if (!csrf) return;
    // Use Playwright's APIRequestContext (not in-page fetch) so a server-side
    // worker crash doesn't surface as an opaque "TypeError: Failed to fetch".
    await page.request.post(`${BASE}/admin/albums/${albumId}/delete`, {
        headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
        form: { csrf },
        failOnStatusCode: false,
    }).catch(() => {});
}

/** Read the value of an HTTP response header (case-insensitive). */
export function header(response, name) {
    const lc = name.toLowerCase();
    const headers = response.headers();
    for (const k of Object.keys(headers)) {
        if (k.toLowerCase() === lc) return headers[k];
    }
    return null;
}

/** Skip the test with a message if the dev server is not reachable. */
export async function requireServer(test, page) {
    try {
        const resp = await page.request.get(`${BASE}/`, { timeout: 3000 });
        if (!resp.ok() && resp.status() !== 302) {
            test.skip(true, `Dev server returned ${resp.status()} at ${BASE}`);
        }
    } catch (_e) {
        test.skip(true, `Dev server not reachable at ${BASE}`);
    }
}

/** Skip the test if we cannot log in as the test admin. */
export async function requireAdmin(test, page) {
    const ok = await tryAdminLogin(page);
    if (!ok) {
        test.skip(true, `Admin login failed for ${ADMIN_EMAIL} at ${BASE}`);
    }
}

/** Remove file-based rate-limit counters so a throttling test does not lock out
 *  subsequent logins/uploads from the same IP for the whole window. Matches the
 *  FileBasedRateLimitMiddleware (`login_*`) and RateLimitMiddleware
 *  (`rate_limit_*`) storage under storage/tmp. Best-effort, never throws. */
export function clearRateLimitFiles() {
    const dir = path.join(PROJECT_ROOT, 'storage', 'tmp');
    try {
        if (!fs.existsSync(dir)) return;
        for (const f of fs.readdirSync(dir)) {
            if (/^(login|rate_limit)_.*\.json$/.test(f)) {
                try { fs.unlinkSync(path.join(dir, f)); } catch (_e) { /* ignore */ }
            }
        }
    } catch (_e) { /* ignore */ }
}

/** Read the CSRF token from an album edit page (the admin form always renders a
 *  hidden input[name="csrf"]). Returns null when unavailable. Reusable across
 *  any test that needs to POST to a CSRF-protected admin endpoint. */
export async function getAlbumCsrf(page, albumId) {
    await page.goto(`${BASE}/admin/albums/${albumId}/edit`);
    return await page.locator('input[name="csrf"]').first()
        .getAttribute('value', { timeout: 2000 }).catch(() => null);
}

/** POST an arbitrary multipart file buffer to the album upload endpoint and
 *  return the raw Playwright APIResponse (failOnStatusCode disabled) so tests
 *  can assert on both success and rejection paths. */
export async function uploadBuffer(page, albumId, { name, mimeType, buffer }, csrf) {
    return await page.request.post(`${BASE}/admin/albums/${albumId}/upload`, {
        headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
        multipart: {
            file: { name, mimeType, buffer },
            csrf,
        },
        failOnStatusCode: false,
    });
}

/** Synthesize a solid-color JPEG of the given pixel dimensions inside the page.
 *  Used to exercise the decompression-bomb / dimension guards with a real
 *  decodable image whose declared size is what validateImageFile() reads. */
export async function makeJpegBuffer(page, width, height, color = '#10b981') {
    const dataUrl = await page.evaluate(({ width, height, color }) => {
        const canvas = document.createElement('canvas');
        canvas.width = width; canvas.height = height;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = color;
        ctx.fillRect(0, 0, width, height);
        return canvas.toDataURL('image/jpeg', 0.6);
    }, { width, height, color });
    return Buffer.from(dataUrl.replace(/^data:image\/jpeg;base64,/, ''), 'base64');
}
