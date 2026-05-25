// @ts-check
// Shared install-state guard. Install specs (full-install-test, install-with-screenshots,
// mysql-installer) only make sense when the app is in a fresh state — i.e. the
// `storage/tmp/.installed` marker is absent and the installer.php redirect is active.
// Run them with PLAYWRIGHT_RESET_INSTALL=1 to bypass the skip and have globalSetup
// reset the install state first (drops DB tables + removes marker).

import fs from 'fs';
import path from 'path';

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..', '..');

export function installMarkerExists() {
    return fs.existsSync(path.join(ROOT, 'storage', 'tmp', '.installed'));
}

export function envFileExists() {
    return fs.existsSync(path.join(ROOT, '.env'));
}

/** Hook for each install test — call at top to skip when the system is installed
 *  and PLAYWRIGHT_RESET_INSTALL is not opted in. */
export function skipIfInstalled(test) {
    if (process.env.PLAYWRIGHT_RESET_INSTALL === '1') return;
    if (installMarkerExists()) {
        test.skip(true, 'System already installed — set PLAYWRIGHT_RESET_INSTALL=1 + run globalSetup to test fresh-install flow.');
    }
}
