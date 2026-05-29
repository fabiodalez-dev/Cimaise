// @ts-check
// Helpers specific to the upload-flow-50 spec.
// All seeding goes through the live admin UI / API — no out-of-band sqlite3
// or PDO writes. Mirrors the pattern already in _helpers.js.

import { expect, request } from '@playwright/test';
import { BASE, ADMIN_EMAIL, ADMIN_PASSWORD } from './_helpers.js';

/**
 * Run the public installer to completion. Idempotent: returns false (no work
 * done) when the system is already installed. Returns true after a successful
 * fresh install.
 *
 * Drives the installer.php step-by-step UI so the seed flow is fully
 * "via the app" — no .env edits, no PDO writes.
 */
export async function runInstallerIfNeeded(page) {
    await page.goto(`${BASE}/installer.php?step=requirements`, { waitUntil: 'domcontentloaded' });

    // Detect "already installed" — installer.php redirects via empty
    // Location header when the app is provisioned (basePath = ''), so
    // page.url() still reads installer.php?step=requirements but the body
    // is empty. Use presence of the requirements page CTA as the canonical
    // signal that the installer is actually rendering.
    const ctaCount = await page.locator('a:has-text("Continue to Database Setup")').count();
    if (ctaCount === 0) {
        return false; // already installed
    }

    // Step 1 — Requirements
    await page.click('a:has-text("Continue to Database Setup")');
    await page.waitForURL(/step=database/, { timeout: 10000 });

    // Step 2 — SQLite database (test fixture; MySQL paths are exercised by mysql-installer.spec)
    const sqliteRadio = page.locator('input[value="sqlite"]');
    if (await sqliteRadio.count() > 0) {
        await sqliteRadio.check({ force: true });
    }
    await page.click('button:has-text("Continue"), button[type="submit"]');
    await page.waitForURL(/step=admin/, { timeout: 15000 });

    // Step 3 — Admin account creation. The form expects:
    //   admin_name, admin_email, admin_password, admin_password_confirm
    await page.fill('input[name="admin_name"]', 'Test Admin');
    await page.fill('input[name="admin_email"]', ADMIN_EMAIL);
    await page.fill('input[name="admin_password"]', ADMIN_PASSWORD);
    await page.fill('input[name="admin_password_confirm"]', ADMIN_PASSWORD);
    await page.click('button:has-text("Continue"), button[type="submit"]');
    await page.waitForURL(/step=(settings|install|complete)/, { timeout: 15000 });

    // Step 4 — Site settings (accept defaults). Optional fields may not all exist.
    if (page.url().includes('step=settings')) {
        const siteTitle = page.locator('input[name="site_title"], input[name="site.title"]').first();
        if (await siteTitle.count() > 0) {
            await siteTitle.fill('Cimaise Upload Test');
        }
        await page.click('button:has-text("Continue"), button:has-text("Install"), button[type="submit"]');
    }
    await page.waitForURL(/step=(install|complete)/, { timeout: 30000 });

    // Step 5 — "Ready to Install" review page. The wizard does NOT auto-run;
    // the operator has to click the final "Install" button to commit the
    // database schema + create the admin user + write .env + drop the
    // .installed marker. Click it explicitly.
    if (page.url().includes('step=install')) {
        await page.click('button:has-text("Install"), button[type="submit"]');
    }
    await page.waitForURL(/step=complete/, { timeout: 60000 });

    return true;
}

/**
 * Synthesize a small JPEG/PNG/WebP/GIF buffer in-browser using <canvas>.
 * Returns Node Buffer so it can be used in APIRequestContext multipart
 * uploads. Keeps the seed inside the running page context — no node
 * crypto/PIL/ImageMagick required at the test layer.
 */
export async function makeTestImage(page, { width = 200, height = 150, label = 'T', color = '#3b82f6', format = 'image/jpeg', quality = 0.9 } = {}) {
    const dataUrl = await page.evaluate(({ width, height, label, color, format, quality }) => {
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        // Background
        ctx.fillStyle = color;
        ctx.fillRect(0, 0, width, height);
        // Variation to defeat solid-color detection
        for (let i = 0; i < 12; i++) {
            ctx.fillStyle = `hsl(${(i * 30) % 360}, 70%, ${30 + (i * 5) % 50}%)`;
            ctx.fillRect((i * 17) % width, (i * 23) % height, 30, 20);
        }
        ctx.fillStyle = '#fff';
        ctx.font = '24px sans-serif';
        ctx.fillText(label, 16, 40);
        return canvas.toDataURL(format, quality);
    }, { width, height, label, color, format, quality });
    const base64 = dataUrl.split(',', 2)[1];
    return Buffer.from(base64, 'base64');
}

/**
 * Pull the per-album CSRF token from the album edit page. Returns null on
 * miss so callers can skip cleanly.
 */
export async function getAlbumCsrf(page, albumId) {
    await page.goto(`${BASE}/admin/albums/${albumId}/edit`);
    const csrfField = page.locator('input[name="csrf"]').first();
    return await csrfField.getAttribute('value', { timeout: 3000 }).catch(() => null);
}

/**
 * Upload a single image to an album via the admin upload endpoint.
 * Returns { ok, status, body } — never throws on HTTP errors so tests can
 * assert on non-2xx responses directly.
 */
export async function uploadImage(page, albumId, buffer, { name = 'test.jpg', mimeType = 'image/jpeg', csrf = null } = {}) {
    csrf = csrf || await getAlbumCsrf(page, albumId);
    if (!csrf) return { ok: false, status: 0, body: null, error: 'no-csrf' };
    const resp = await page.request.post(`${BASE}/admin/albums/${albumId}/upload`, {
        headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
        multipart: {
            file: { name, mimeType, buffer },
            csrf,
        },
        failOnStatusCode: false,
    });
    const status = resp.status();
    const body = await resp.json().catch(() => null);
    return { ok: resp.ok(), status, body };
}

/**
 * Upload N images sequentially. Returns array of imageIds (filtering failed).
 */
export async function uploadImages(page, albumId, count, { labelPrefix = 'img', baseColor = '#a855f7' } = {}) {
    const csrf = await getAlbumCsrf(page, albumId);
    const ids = [];
    for (let i = 0; i < count; i++) {
        const buf = await makeTestImage(page, { label: `${labelPrefix}${i}`, color: shiftColor(baseColor, i) });
        const r = await uploadImage(page, albumId, buf, { name: `${labelPrefix}-${i}.jpg`, csrf });
        if (r.ok && r.body?.id) ids.push(r.body.id);
    }
    return ids;
}

function shiftColor(hex, offset) {
    // Rotate the H component a bit so subsequent images differ in dominant colour.
    const n = parseInt(hex.slice(1), 16);
    const r = (n >> 16) & 0xff;
    const g = (n >> 8) & 0xff;
    const b = n & 0xff;
    const rot = (v) => Math.max(0, Math.min(255, v + ((offset * 13) % 60) - 30));
    return `#${[rot(r), rot(g), rot(b)].map(v => v.toString(16).padStart(2, '0')).join('')}`;
}

/**
 * Read state for an album via the admin edit page. Counts the rendered
 * thumbnails by matching /media/{N}_{variant}.{ext} URLs in the HTML —
 * more robust than relying on a single CSS attribute that may change with
 * the template. Returns null on fetch failure.
 */
export async function getAlbumInfo(page, albumId) {
    const resp = await page.request.get(`${BASE}/admin/albums/${albumId}/edit`);
    if (!resp.ok()) return null;
    const html = await resp.text();
    // Distinct image ids referenced via any variant URL — covers data-image-id,
    // <img src>, srcset, picture sources, and inline style backgrounds alike.
    const seen = new Set();
    for (const m of html.matchAll(/\/media\/(\d+)_(?:sm|md|lg|xl|xxl|blur|lqip|preview)\.(?:jpg|jpeg|webp|avif|png)/g)) {
        seen.add(Number(m[1]));
    }
    // Belt-and-braces: also grep data-image-id="N" (older template version)
    for (const m of html.matchAll(/data-image-id="(\d+)"/g)) {
        seen.add(Number(m[1]));
    }
    return {
        imageCount: seen.size,
        imageIds: Array.from(seen),
        rawLength: html.length,
    };
}
