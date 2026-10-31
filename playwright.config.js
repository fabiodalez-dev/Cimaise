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

// A teardown project runs in full whatever files the command line names, so
// the gate is only wired for whole-suite runs; naming spec files runs just
// those, as before. Decided once in the runner and handed to the workers,
// which load this config with their own argv.
if (process.env.CIMAISE_E2E_GATE === undefined) {
  const namesFiles = process.argv.slice(2).some((arg) => /\.(spec|setup)\.js$|^tests\//.test(arg));
  process.env.CIMAISE_E2E_GATE = namesFiles ? '0' : '1';
}
const gated = process.env.CIMAISE_E2E_GATE === '1';

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 60000,
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8000',
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'parallel-gate',
      testMatch: /parallel-gate\.setup\.js$/,
      ...(gated ? { teardown: 'settings' } : {}),
    },
    {
      name: 'chromium',
      use: { browserName: 'chromium' },
      testIgnore: settingsWriters,
      ...(gated ? { dependencies: ['parallel-gate'] } : {}),
    },
    {
      name: 'settings',
      use: { browserName: 'chromium' },
      testMatch: settingsWriters,
      workers: 1,
    },
  ],
});
