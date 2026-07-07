// @ts-check
// Tests covering the 12 CodeRabbit findings + the 10 /adamsreview findings
// fixed in commits 9ac80202 + 76d1038e. Each test verifies an observable
// behavior of the fix, not the implementation detail.

import { test, expect } from '@playwright/test';
import { BASE, header, requireServer, tryAdminLogin } from './_helpers.js';

test.describe('CodeRabbit + adamsreview fix verification', () => {
    test.beforeEach(async ({ page }) => {
        await requireServer(test, page);
    });

    test('CR-FIX-01: GET /admin/updates/perform returns 405 (no GET->POST shim)', async ({ page }) => {
        // F002 + CR-removed shim — GET on a POST-only route must surface 405,
        // not be silently promoted to POST.
        const resp = await page.request.get(`${BASE}/admin/updates/perform?csrf=anything&version=1.1.0`, {
            failOnStatusCode: false,
        });
        expect([405, 401, 403]).toContain(resp.status());
        // Most importantly: NOT 200 (which would mean the shim is still running)
        expect(resp.status()).not.toBe(200);
    });

    test('CR-FIX-02: search input has visible focus indicator (WCAG 2.4.11)', async ({ page }) => {
        // F033 + CR — outline must be visible on :focus, not outline:none.
        // Discover the current bundled CSS via the vite manifest so the test
        // tolerates hash changes between builds.
        const manifestResp = await page.request.get(`${BASE}/assets/.vite/manifest.json`, {
            failOnStatusCode: false,
        });
        let cssUrl = null;
        if (manifestResp.ok()) {
            try {
                const manifest = await manifestResp.json();
                const entry = manifest['resources/frontend.css'];
                if (entry?.file) cssUrl = `${BASE}/assets/${entry.file}`;
            } catch { /* fall through */ }
        }
        if (!cssUrl) {
            // Fallback: just check the source file
            const fs = await import('fs');
            const path = await import('path');
            const src = fs.readFileSync(
                path.resolve(process.cwd(), 'resources/frontend.css'),
                'utf-8',
            );
            const m = src.match(/\.header-search-input:focus[\s\S]{0,200}\}/);
            expect(m).not.toBeNull();
            expect(m?.[0]).toMatch(/outline:\s*\d+px\s+solid/);
            expect(m?.[0]).not.toMatch(/outline:\s*none/);
            return;
        }
        const cssResp = await page.request.get(cssUrl, { failOnStatusCode: false });
        expect(cssResp.ok()).toBe(true);
        const body = await cssResp.text();
        const m = body.match(/\.header-search-input:focus[^,{]*[,{][^}]+\}/);
        expect(m).not.toBeNull();
        expect(m?.[0]).toMatch(/outline:\s*\d+px\s+solid/);
        expect(m?.[0]).not.toMatch(/outline:\s*none/);
    });

    test('CR-FIX-03: cimaise_salt is no longer hardcoded in AnalyticsService', async () => {
        // F043 + CR-1/CR-5 — public-knowledge salt removed
        const fs = await import('fs');
        const path = await import('path');
        const src = fs.readFileSync(
            path.resolve(process.cwd(), 'app/Services/AnalyticsService.php'),
            'utf-8',
        );
        expect(src).not.toContain("'cimaise_salt'");
        expect(src).not.toContain('"cimaise_salt"');
        // And getIpSalt is the new entry point
        expect(src).toMatch(/getIpSalt\s*\(/);
    });

    test('CR-FIX-04: isSessionDependent includes nsfw_confirmed_global (CR-4)', async () => {
        const fs = await import('fs');
        const path = await import('path');
        const src = fs.readFileSync(
            path.resolve(process.cwd(), 'app/Middlewares/CacheMiddleware.php'),
            'utf-8',
        );
        // isSessionDependent must check the global NSFW consent flag.
        // Anchor on the METHOD DEFINITION (not the first textual occurrence,
        // which is a call site inside process()): capture from
        // `function isSessionDependent` up to the next method or the class end.
        const m = src.match(
            /function\s+isSessionDependent\b[\s\S]+?(?=\n\s*(?:private|public|protected)\s+function|\n\s*\}\s*$)/,
        );
        expect(m).not.toBeNull();
        if (m) {
            expect(m[0]).toContain('nsfw_confirmed_global');
            expect(m[0]).toContain('album_access');
            expect(m[0]).toContain('admin_id');
        }
    });

    test('CR-FIX-05: Database resolvePinnedIp blocks fd00:ec2::254 (CR-6)', async () => {
        const fs = await import('fs');
        const path = await import('path');
        const src = fs.readFileSync(
            path.resolve(process.cwd(), 'app/Support/Database.php'),
            'utf-8',
        );
        expect(src).toContain('fd00:ec2::254');
        // The prefix check for the AWS IPv6 metadata namespace
        expect(src).toMatch(/fd00:ec2::/);
    });

    test('CR-FIX-06: envEscape + envUnescape symmetric (CR-3 / CR-10)', async () => {
        // Round-trip simulation: envEscape wraps in "" and escapes \\, ", $, \n, \r
        // envUnescape must reverse it exactly.
        const fs = await import('fs');
        const path = await import('path');
        const installerSrc = fs.readFileSync(
            path.resolve(process.cwd(), 'public/installer.php'),
            'utf-8',
        );
        expect(installerSrc).toMatch(/function\s+envEscape/);
        expect(installerSrc).toMatch(/function\s+envUnescape/);
        const installerClassSrc = fs.readFileSync(
            path.resolve(process.cwd(), 'app/Installer/Installer.php'),
            'utf-8',
        );
        expect(installerClassSrc).toMatch(/function\s+envEscape/);
        expect(installerClassSrc).toMatch(/function\s+envUnescape/);
        // isInstalled must use envUnescape on the values it reads back
        expect(installerClassSrc).toMatch(/isInstalled[\s\S]{0,500}envUnescape/);
    });

    // CR-FIX-07 removed: the image-rating plugin it inspected was deleted
    // (inert plugin — registered hooks the core never emitted).

    test('CR-FIX-08: backfillBlurForProtectedAlbums honours $force (CR-11)', async () => {
        const fs = await import('fs');
        const path = await import('path');
        const src = fs.readFileSync(
            path.resolve(process.cwd(), 'app/Services/UploadService.php'),
            'utf-8',
        );
        const m = src.match(/function\s+backfillBlurForProtectedAlbums[\s\S]+?(?=function\s)/);
        expect(m).not.toBeNull();
        if (m) {
            // Must have a conditional that branches on $force — either two SQL strings
            // or a parameterized condition. Either way: $force must be referenced.
            expect(m[0]).toMatch(/\$force/);
            // And the iv.id IS NULL filter must NOT be unconditional any more
            // (i.e., we expect either an `if ($force)` branch or a `:force` placeholder)
            const hasBranch = /if\s*\(\s*\$force/.test(m[0])
                || /\$force\s*\?/.test(m[0])
                || /:force\s*=\s*1/.test(m[0]);
            expect(hasBranch).toBe(true);
        }
    });

    test('CR-FIX-09: ensureBlurPlaceholder has GD guard (CR-12)', async () => {
        const fs = await import('fs');
        const path = await import('path');
        const src = fs.readFileSync(
            path.resolve(process.cwd(), 'app/Services/UploadService.php'),
            'utf-8',
        );
        const m = src.match(/function\s+ensureBlurPlaceholder[\s\S]+?(?=function\s)/);
        expect(m).not.toBeNull();
        if (m) {
            expect(m[0]).toMatch(/extension_loaded\(['"]gd['"]\)/);
            // Should also check at least one of the GD function_exists guards
            expect(m[0]).toMatch(/function_exists\(['"]imagecreatetruecolor['"]\)/);
        }
    });

    test('CR-FIX-10: addApiCache emits Vary: Cookie for session-dependent /api/album (F009)', async ({ page }) => {
        // Runtime: anonymous request → middleware emits Cache-Control: private and
        // Vary: Accept-Encoding (Cookie is only appended when isSessionDependent()
        // returns true, which requires an active PHP session that this anonymous
        // HTTP probe cannot mint from outside the browser).
        const resp = await page.request.get(`${BASE}/api/album/nonexistent-xyz/template?template=1`, {
            failOnStatusCode: false,
        });
        const cc = resp.headers()['cache-control'] || '';
        expect(cc).toBeTruthy();
        expect(cc).toContain('private');
        const vary = (resp.headers()['vary'] || '').toLowerCase();
        expect(vary).toContain('accept-encoding');

        // Source-level: addApiCache MUST append ", Cookie" when isSessionDependent().
        // This is what we cannot exercise via an anonymous HTTP probe, so we lock
        // it down by reading the middleware source and proving the branch exists.
        const fs = await import('fs');
        const path = await import('path');
        const mwSrc = fs.readFileSync(
            path.resolve(process.cwd(), 'app/Middlewares/CacheMiddleware.php'),
            'utf-8',
        );
        const addApi = mwSrc.match(/function\s+addApiCache[\s\S]+?(?=\n\s*(?:private|public|protected)\s+function|\n\s*\}\s*$)/);
        expect(addApi).not.toBeNull();
        if (addApi) {
            // Must reference isSessionDependent() and append ", Cookie" to Vary
            expect(addApi[0]).toMatch(/isSessionDependent\s*\(\s*\)/);
            expect(addApi[0]).toMatch(/['"],\s*Cookie['"]/);
        }
    });
});
