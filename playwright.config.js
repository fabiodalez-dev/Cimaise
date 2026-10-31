import { defineConfig } from '@playwright/test';

// Specs that write site-wide settings (cache on/off, image formats, home page,
// pagination, gallery filters, variant mode) or depend on site-wide state
// (security-hardening A6: the login limiter counts per IP and every other
// spec's successful login clears it). Run in parallel with the rest they
// change the site under other specs' feet, so they run afterwards, one at a
// time, as the teardown of the parallel gate: a teardown still runs when the
// parallel suite has failures, unlike a dependency.
const SETTINGS_WRITERS = [
  'admin-settings',
  'settings-effects',
  'album-edit-cache',
  'image-queue',
  'home-hero-toggle',
  'admin-csrf-resilience',
  'cache-invalidation',
  'security-hardening',
];
const settingsWriters = new RegExp(`/(${SETTINGS_WRITERS.join('|')})\\.spec\\.js$`);

// The gate orders the suite, but a teardown project runs in full whatever
// files the command line names. So it is dropped only when the named files
// cannot collide: spec files only (no directory), and either no settings
// writer or a single writer on its own. Without the gate every spec lives in
// the one "chromium" project, as before, so `--project=chromium <file>` keeps
// working for any file. Decided once in the runner and handed to the workers,
// which load this config with their own argv.
if (process.env.CIMAISE_E2E_GATE === undefined) {
  const args = process.argv.slice(2).filter((arg) => !arg.startsWith('-'));
  const specs = args.filter((arg) => /\.spec\.js$/.test(arg));
  const namesDirectory = args.some((arg) => /^(\.\/)?tests(\/|$)/.test(arg) && !/\.(spec|setup)\.js$/.test(arg));
  const writers = specs.filter((arg) => settingsWriters.test(`/${arg}`)).length;
  const isolated = specs.length > 0 && !namesDirectory
    && (writers === 0 || (writers === 1 && specs.length === 1));
  process.env.CIMAISE_E2E_GATE = isolated ? '0' : '1';
}
const gated = process.env.CIMAISE_E2E_GATE === '1';

const browser = { browserName: /** @type {'chromium'} */ ('chromium') };
const projects = gated
  ? [
      { name: 'parallel-gate', testMatch: /parallel-gate\.setup\.js$/, teardown: 'settings' },
      { name: 'chromium', use: browser, testIgnore: settingsWriters, dependencies: ['parallel-gate'] },
      { name: 'settings', use: browser, testMatch: settingsWriters, workers: 1 },
    ]
  : [{ name: 'chromium', use: browser }];

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 60000,
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8000',
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  projects,
});
