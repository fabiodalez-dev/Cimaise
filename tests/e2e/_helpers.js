// @ts-check
// Shared utilities for the e2e test suite.
// Import from individual spec files via `import { ... } from './_helpers.js';`.

import { expect } from '@playwright/test';

export const BASE = process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8000';
export const ADMIN_EMAIL = process.env.TEST_ADMIN_EMAIL || 'admin@test.com';
export const ADMIN_PASSWORD = process.env.TEST_ADMIN_PASSWORD || 'TestPass123!';

export const ADMIN_DASHBOARD_RE = /\/admin\/?(\?.*)?$/;
export const ADMIN_ALBUMS_LIST_RE = /\/admin\/albums\/?(\?.*)?$/;

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
    await page.click('button[type="submit"][form="album-form"]');
    await page.waitForURL(/\/admin\/albums/, { timeout: 15000 });
    // Find the album in the list
    await page.goto(`${BASE}/admin/albums`);
    const link = page.locator(`a:has-text("${title}")`).first();
    const href = await link.getAttribute('href').catch(() => null);
    const id = href?.match(/\/albums\/(\d+)/)?.[1];
    if (!id) return { id: null, slug: null };
    // Get slug from edit page
    await page.goto(`${BASE}/admin/albums/${id}/edit`);
    const slug = await page.locator('input[name="slug"]').inputValue().catch(() => null);
    return { id: Number(id), slug };
}

function escapeRegExp(s) {
    return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** Upload a small canvas-generated JPEG to an album and optionally set as cover. */
export async function uploadCover(page, albumId, label = 'Cover', color = '#3b82f6') {
    await page.goto(`${BASE}/admin/albums/${albumId}/edit`);
    await page.waitForSelector('#uppy', { timeout: 5000 });

    const result = await page.evaluate(async ({ albumId, base, label, color }) => {
        const csrf = document.querySelector('input[name="csrf"]')?.value
                  || document.querySelector('#uppy')?.dataset?.csrf || '';
        const canvas = document.createElement('canvas');
        canvas.width = 200; canvas.height = 200;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = color; ctx.fillRect(0, 0, 200, 200);
        ctx.fillStyle = '#fff'; ctx.font = '20px sans-serif';
        ctx.fillText(label, 20, 110);
        const blob = await new Promise(r => canvas.toBlob(r, 'image/jpeg', 0.9));
        const fd = new FormData();
        fd.append('file', blob, `test-${label}.jpg`);
        const res = await fetch(`${base}/admin/albums/${albumId}/upload`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
            body: fd
        });
        const data = await res.json().catch(() => null);
        if (res.ok && data?.id) {
            await fetch(`${base}/admin/albums/${albumId}/cover/${data.id}`, {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' }
            });
        }
        return { ok: res.ok, imageId: data?.id || null };
    }, { albumId, base: BASE, label, color });

    return result;
}

/** Delete an album (admin) by id. Best-effort; ignores 404. */
export async function deleteAlbum(page, albumId) {
    if (!albumId) return;
    await page.goto(`${BASE}/admin/albums`);
    await page.waitForSelector('body');
    const csrf = await page.locator('meta[name="csrf-token"]').first().getAttribute('content').catch(() => null)
              || await page.locator('input[name="csrf"]').first().getAttribute('value').catch(() => null);
    if (!csrf) return;
    await page.evaluate(async ({ albumId, base, csrf }) => {
        await fetch(`${base}/admin/albums/${albumId}/delete`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' }
        });
    }, { albumId, base: BASE, csrf });
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
