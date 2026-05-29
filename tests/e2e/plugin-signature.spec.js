// @ts-check
// E2E for the Ed25519 plugin-signature trust boundary (review finding H3).
//
// Strategy: this suite ENABLES enforcement by writing a throwaway public key to
// resources/keys/plugin-signing.pub (read per-request by the live Apache app),
// signs test archives via `php bin/console plugin:sign`, drives the real
// POST /admin/plugins/upload endpoint, and removes the key in afterAll so the app
// returns to its default (signature-disabled) state. Keep this in its own file —
// while the key is present, ALL plugin uploads require a valid signature.
//
// Target server: Apache at http://localhost:8000 (see security-hardening.spec.js).

import { test, expect } from '@playwright/test';
import { execFileSync } from 'child_process';
import fs from 'fs';
import os from 'os';
import path from 'path';
import { fileURLToPath } from 'url';
import { BASE, adminLogin, tryAdminLogin, requireServer } from './_helpers.js';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const PUBKEY_PATH = path.join(ROOT, 'resources', 'keys', 'plugin-signing.pub');

let secretB64 = null;       // vendor secret key for this run
let pubBackup = null;       // existing pubkey content to restore, if any
let adminAvailable = false;

/** Build a minimal ZIP (optionally containing plugin.json) and return its path. */
function makeZip(withPluginJson) {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'psig-'));
    if (withPluginJson) {
        fs.writeFileSync(path.join(dir, 'plugin.json'),
            JSON.stringify({ name: 'sigtest-' + Date.now(), version: '1.0.0' }));
    } else {
        fs.writeFileSync(path.join(dir, 'readme.txt'), 'no plugin json here');
    }
    const zipPath = path.join(dir, 'plugin.zip');
    // execFileSync with an argument array: no shell, so paths are never interpolated.
    const entries = fs.readdirSync(dir);
    execFileSync('zip', ['-q', 'plugin.zip', ...entries], { cwd: dir, stdio: 'ignore' });
    return zipPath;
}

/** Sign a file with the run's secret key via the PHP console; return base64 sig. */
function signFile(zipPath) {
    const sigPath = zipPath + '.sig';
    execFileSync('php', ['bin/console', 'plugin:sign', zipPath, '--key', secretB64, '--out', sigPath],
        { cwd: ROOT, stdio: 'ignore' });
    return fs.readFileSync(sigPath, 'utf-8').trim();
}

async function getCsrf(page) {
    await page.goto(`${BASE}/admin/plugins`);
    return await page.locator('input[name="csrf"]').first()
        .getAttribute('value', { timeout: 3000 }).catch(() => null);
}

test.beforeAll(async ({ browser }) => {
    // Generate a throwaway keypair; publish the public key to enable enforcement.
    const json = execFileSync('php',
        ['-r', 'require "vendor/autoload.php"; echo json_encode(App\\Support\\PluginSignature::generateKeypair());'],
        { cwd: ROOT }).toString();
    const kp = JSON.parse(json);
    secretB64 = kp.secret;
    if (fs.existsSync(PUBKEY_PATH)) pubBackup = fs.readFileSync(PUBKEY_PATH, 'utf-8');
    fs.mkdirSync(path.dirname(PUBKEY_PATH), { recursive: true });
    fs.writeFileSync(PUBKEY_PATH, kp.public + '\n');

    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    adminAvailable = await tryAdminLogin(page).catch(() => false);
    await ctx.close();
});

test.afterAll(async () => {
    // Restore the prior state: put back any original key, else remove ours so the
    // app reverts to signature-disabled (its committed default).
    if (pubBackup !== null) fs.writeFileSync(PUBKEY_PATH, pubBackup);
    else if (fs.existsSync(PUBKEY_PATH)) fs.unlinkSync(PUBKEY_PATH);
});

test('PSIG-01: unsigned plugin upload is rejected when signing is enabled', async ({ page }) => {
    await requireServer(test, page);
    test.skip(!adminAvailable, 'No seeded admin');
    await adminLogin(page);
    const csrf = await getCsrf(page);
    test.skip(!csrf, 'No CSRF token');

    const zip = makeZip(true);
    const resp = await page.request.post(`${BASE}/admin/plugins/upload`, {
        headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
        multipart: { file: { name: 'plugin.zip', mimeType: 'application/zip', buffer: fs.readFileSync(zip) } },
        failOnStatusCode: false,
    });
    expect(resp.ok()).toBeFalsy();
    const data = await resp.json().catch(() => ({}));
    expect(data.success).toBeFalsy();
});

test('PSIG-02: plugin upload with an INVALID signature is rejected', async ({ page }) => {
    await requireServer(test, page);
    test.skip(!adminAvailable, 'No seeded admin');
    await adminLogin(page);
    const csrf = await getCsrf(page);
    test.skip(!csrf, 'No CSRF token');

    const zip = makeZip(true);
    const bogusSig = Buffer.alloc(64, 7).toString('base64'); // valid length, wrong content
    const resp = await page.request.post(`${BASE}/admin/plugins/upload`, {
        headers: { 'X-CSRF-Token': csrf, 'X-Plugin-Signature': bogusSig, 'Accept': 'application/json' },
        multipart: { file: { name: 'plugin.zip', mimeType: 'application/zip', buffer: fs.readFileSync(zip) } },
        failOnStatusCode: false,
    });
    expect(resp.ok()).toBeFalsy();
    const data = await resp.json().catch(() => ({}));
    expect(data.success).toBeFalsy();
});

test('PSIG-03: a VALID signature passes the gate (fails later, not on signature)', async ({ page }) => {
    await requireServer(test, page);
    test.skip(!adminAvailable, 'No seeded admin');
    await adminLogin(page);
    const csrf = await getCsrf(page);
    test.skip(!csrf, 'No CSRF token');

    // A correctly-signed archive WITHOUT plugin.json: it must clear the signature
    // gate and fail downstream ("plugin.json not found"), proving the gate opened
    // for a valid signature — and nothing gets installed.
    const zipNoJson = makeZip(false);
    const validSig = signFile(zipNoJson);

    const resp = await page.request.post(`${BASE}/admin/plugins/upload`, {
        headers: { 'X-CSRF-Token': csrf, 'X-Plugin-Signature': validSig, 'Accept': 'application/json' },
        multipart: { file: { name: 'plugin.zip', mimeType: 'application/zip', buffer: fs.readFileSync(zipNoJson) } },
        failOnStatusCode: false,
    });
    const data = await resp.json().catch(() => ({}));
    // Still a failure (no plugin.json), but NOT the signature rejection.
    expect(data.success).toBeFalsy();
    const msg = String(data.message || '').toLowerCase();
    expect(msg.includes('signature') || msg.includes('firma')).toBeFalsy();

    // And cross-check: the SAME zip with a bad signature IS rejected for signature.
    const bad = await page.request.post(`${BASE}/admin/plugins/upload`, {
        headers: { 'X-CSRF-Token': csrf, 'X-Plugin-Signature': Buffer.alloc(64, 1).toString('base64'), 'Accept': 'application/json' },
        multipart: { file: { name: 'plugin.zip', mimeType: 'application/zip', buffer: fs.readFileSync(zipNoJson) } },
        failOnStatusCode: false,
    });
    const badData = await bad.json().catch(() => ({}));
    expect(String(badData.message || '')).not.toBe(String(data.message || ''));
});
