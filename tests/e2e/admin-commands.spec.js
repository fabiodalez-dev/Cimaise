// @ts-check
// Admin console commands (/admin/commands): they execute bin/console via exec().
// Regression guard for the "php: command not found" (exit 127) failure where the
// web-server user's PATH lacks a php CLI and PHP_BINARY is the FPM/mod_php SAPI.
// CommandsController::resolvePhpBinary() must find a real CLI so commands run.

import { test, expect } from '@playwright/test';
import { BASE, requireAdmin } from './_helpers.js';

test.describe.serial('Admin console commands', () => {
    let page;
    let csrf = null;

    test.beforeAll(async ({ browser }) => {
        page = await browser.newPage();
        await requireAdmin(test, page);
        const html = await (await page.request.get(`${BASE}/admin/commands`)).text();
        const m = html.match(/X-CSRF-Token'\s*:\s*'([a-f0-9]+)'/i) || html.match(/[a-f0-9]{64}/);
        csrf = m ? (m[1] || m[0]) : null;
    });

    test.afterAll(async () => { await page?.close(); });

    const run = (command) => page.request.post(`${BASE}/admin/commands/execute`, {
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        data: { command },
        failOnStatusCode: false,
    });

    test('CMD-01: diagnostics:report runs (exit 0), not "php: command not found"', async () => {
        test.skip(!csrf, 'CSRF not found on the commands page');
        const res = await run('diagnostics:report');
        expect(res.ok()).toBeTruthy();
        const body = await res.json();
        expect(body.output || '').not.toContain('command not found');
        expect(body.exit_code).toBe(0);
        expect(body.success).toBe(true);
    });

    test('CMD-02: db:test connects successfully', async () => {
        test.skip(!csrf, 'CSRF not found on the commands page');
        const body = await (await run('db:test')).json();
        expect(body.success).toBe(true);
        expect(body.exit_code).toBe(0);
    });

    test('CMD-03: a non-allowlisted command is rejected', async () => {
        test.skip(!csrf, 'CSRF not found on the commands page');
        const res = await run('rm:rf');
        const body = await res.json();
        expect(body.success).toBeFalsy();
    });
});
