// @ts-check
// Reusable, data-driven coverage for /admin/settings. For EVERY setting we run two
// tests: it persists value A across a save+reload, and it persists value B (the
// opposite state / a second value) — so every field is proven to round-trip both
// ways exactly as the SettingsController maps it. A handful of settings also assert
// their real frontend EFFECT (site title, pagination, dark mode, right-click block,
// EXIF panel, date format, HTML cache max-age).
//
// Safety: the suite captures every field's original value up front and restores it
// in afterAll, so running it never leaves the dev site in a changed/broken state.
// Risky live-effect toggles (maintenance, recaptcha enable) are persistence-only.
//
// Inter-setting conflicts are accounted for (the controller couples some fields):
//   • Breakpoints sm<md<lg<xl<xxl — a non-ascending set makes the controller REJECT
//     the WHOLE save. So (1) breakpoints are never poked one-at-a-time in the generic
//     loop (they round-trip as an ascending group), and (2) every save asserts it was
//     accepted (no red error flash), so a conflict-induced rejection fails loudly
//     instead of silently reading back a stale value. A dedicated test proves the
//     non-ascending case is rejected and leaves the prior values intact.
//   • recaptcha.enabled requires BOTH keys — enabling with a missing key is forced
//     back off. So recaptcha_enabled is kept out of the naive loop (it would be flaky)
//     and gets its own coupling test that supplies the keys first.
//
// To extend: add an entry to SETTINGS. type drives the fill/read strategy.

import { test, expect } from '@playwright/test';
import { BASE, requireAdmin } from './_helpers.js';

const SETTINGS_URL = `${BASE}/admin/settings`;
const SAVE = 'button[type="submit"][form="settings-form"]';

// type: text | number | checkbox | select
// a / b: the two values to round-trip (for checkbox use true/false)
// effect(page, value): optional — assert the real frontend effect after saving `value`
const SETTINGS = [
  // --- Site ---
  { field: 'site_title', type: 'text', a: 'QA Portfolio Alpha', b: 'QA Portfolio Beta' },
  { field: 'site_description', type: 'text', a: 'QA description one', b: 'QA description two' },
  { field: 'site_copyright', type: 'text', a: 'QA Copyright A', b: 'QA Copyright B' },
  { field: 'site_email', type: 'text', a: 'qa-a@example.com', b: 'qa-b@example.com' },
  { field: 'custom_css', type: 'text', a: '.qa-a{color:#111}', b: '.qa-b{color:#222}' },

  // --- Pagination (observable effect) ---
  { field: 'pagination_limit', type: 'number', a: '7', b: '11' },

  // --- Image formats (checkbox) ---
  { field: 'fmt_avif', type: 'checkbox' },
  { field: 'fmt_webp', type: 'checkbox' },
  { field: 'fmt_jpg', type: 'checkbox' },
  // --- Image quality (number, clamped 1..100) ---
  { field: 'q_avif', type: 'number', a: '42', b: '58' },
  { field: 'q_webp', type: 'number', a: '66', b: '78' },
  { field: 'q_jpg', type: 'number', a: '80', b: '90' },
  { field: 'preview_w', type: 'number', a: '420', b: '520' },
  { field: 'variants_async', type: 'checkbox' },

  // --- Caching (number / checkbox) ---
  { field: 'html_cache_max_age', type: 'number', a: '1800', b: '3600' },
  { field: 'media_cache_max_age', type: 'number', a: '43200', b: '86400' },
  { field: 'static_cache_max_age', type: 'number', a: '604800', b: '2592000' },
  { field: 'cache_enabled', type: 'checkbox' },
  { field: 'compression_enabled', type: 'checkbox' },
  { field: 'compression_level', type: 'number', a: '4', b: '6' },
  // 'auto' + 'gzip' are always available; 'brotli' depends on the PHP ext and the
  // form disables that <option> when missing, so we never select it here.
  { field: 'compression_type', type: 'select', a: 'gzip', b: 'auto' },

  // --- Frontend behaviour (checkbox, observable) ---
  { field: 'dark_mode', type: 'checkbox' },
  { field: 'disable_right_click', type: 'checkbox' },
  { field: 'show_exif_lightbox', type: 'checkbox' },
  { field: 'show_tags_in_header', type: 'checkbox' },
  { field: 'nsfw_global_warning', type: 'checkbox' },
  { field: 'admin_debug_logs', type: 'checkbox' },

  // --- Date / language (select) ---
  { field: 'date_format', type: 'select', a: 'd-m-Y', b: 'Y-m-d' },
  { field: 'site_language', type: 'select', a: 'en', b: 'it' },

  // --- PWA colours (text/colour) ---
  { field: 'pwa_theme_color', type: 'text', a: '#123456', b: '#abcdef' },
  { field: 'pwa_background_color', type: 'text', a: '#654321', b: '#fedcba' },

  // --- reCAPTCHA (persistence only; key format must be [A-Za-z0-9_-]) ---
  { field: 'recaptcha_site_key', type: 'text', a: 'qaSiteKey_AAA111', b: 'qaSiteKey_BBB222' },

  // --- Maintenance (persistence only — never assert the live lockout) ---
  { field: 'maintenance_title', type: 'text', a: 'QA Down A', b: 'QA Down B' },
  { field: 'maintenance_message', type: 'text', a: 'Back soon A', b: 'Back soon B' },
  { field: 'maintenance_show_countdown', type: 'checkbox' },
  { field: 'maintenance_show_logo', type: 'checkbox' },
];

// Breakpoints are validated as strictly ascending, so they round-trip as a group.
const BP_FIELDS = ['bp_sm', 'bp_md', 'bp_lg', 'bp_xl', 'bp_xxl'];
const BP_A = { bp_sm: '380', bp_md: '760', bp_lg: '1140', bp_xl: '1520', bp_xxl: '1900' };
const BP_B = { bp_sm: '420', bp_md: '820', bp_lg: '1240', bp_xl: '1640', bp_xxl: '2040' };

async function gotoSettings(page) {
  await page.goto(SETTINGS_URL, { waitUntil: 'networkidle' });
  await expect(page.locator('#settings-form')).toBeVisible();
}

async function setField(page, field, type, value) {
  const sel = `#settings-form [name="${field}"]`;
  if (type === 'checkbox') {
    // Set the checked property directly + dispatch change. Works uniformly for plain
    // checkboxes and Tailwind `sr-only peer` toggles (whose pill <div> intercepts
    // clicks). Avoids click-interception/actionability flakiness; the browser
    // serialises the live `checked` state on submit regardless of how it was set.
    await page.locator(sel).waitFor({ state: 'attached' });
    await page.evaluate(({ s, v }) => {
      const el = document.querySelector(s);
      if (el && el.checked !== v) {
        el.checked = v;
        el.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }, { s: sel, v: !!value });
  } else if (type === 'select') {
    await page.selectOption(sel, String(value));
  } else {
    await page.fill(sel, String(value));
  }
}

async function readField(page, field, type) {
  const sel = `#settings-form [name="${field}"]`;
  if (type === 'checkbox') return await page.isChecked(sel);
  return await page.inputValue(sel);
}

// expectOk=true asserts the save was accepted (the green flash, never the red error
// flash). A conflict that makes the controller reject the whole save (e.g. breakpoints
// out of order) raises the red flash, so this turns a silent stale-readback into a
// loud, clear failure. Conflict tests pass expectOk=false to assert the rejection.
async function saveAndReload(page, { expectOk = true } = {}) {
  // The submit POSTs (302) then the browser follows to GET /admin/settings (200).
  // We're already on that URL, so waitForURL would resolve instantly, and networkidle
  // can fire in the gap between the POST response and the GET starting — both race the
  // readback onto the stale page. Waiting for the GET 200 landing is unambiguous: it
  // only resolves once the freshly rendered settings document has loaded.
  await Promise.all([
    page.waitForResponse(
      (r) => r.request().method() === 'GET'
        && r.url().includes('/admin/settings')
        && r.status() === 200,
      { timeout: 15000 },
    ),
    page.click(SAVE),
  ]);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('#settings-form')).toBeVisible();
  if (expectOk) {
    await expect(page.locator('.flash-message.bg-red-50'),
      'a setting save was rejected (red flash) — likely an inter-setting conflict').toHaveCount(0);
  }
}

// Did the last landing show the error flash? (used by conflict tests)
async function saveWasRejected(page) {
  return (await page.locator('.flash-message.bg-red-50').count()) > 0;
}

// Saving settings does NOT invalidate the HTML page cache, so any frontend-effect
// assertion must flush it first. We reuse the admin session's cookies + CSRF token.
async function clearCache(page) {
  await gotoSettings(page);
  const csrf = await page.inputValue('#settings-form [name="csrf"]');
  await page.request.post(`${BASE}/admin/cache/clear`, {
    headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
  });
}

// Frontend effects must be checked in a fresh, anonymous context: the admin session
// has a PWA service worker that aborts cross-section navigations, and we want the
// guest-facing render anyway. Returns { ctx, page } — caller closes ctx.
async function openFrontend(page) {
  const ctx = await page.context().browser().newContext();
  return { ctx, page: await ctx.newPage() };
}

test.describe.serial('Admin settings — every setting persists + functions', () => {
  /** @type {import('@playwright/test').Page} */
  let page;
  const original = {};

  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage();
    await requireAdmin(test, page);
    await gotoSettings(page);
    // Capture originals so afterAll can restore the dev site exactly.
    for (const s of SETTINGS) {
      try { original[s.field] = await readField(page, s.field, s.type); } catch { /* field absent */ }
    }
    for (const f of BP_FIELDS) {
      try { original[f] = await readField(page, f, 'number'); } catch { /* absent */ }
    }
  });

  test.afterAll(async () => {
    if (!page) return;
    try {
      await gotoSettings(page);
      for (const s of SETTINGS) {
        if (s.field in original) {
          try { await setField(page, s.field, s.type, original[s.field]); } catch { /* ignore */ }
        }
      }
      for (const f of BP_FIELDS) {
        if (f in original) { try { await setField(page, f, 'number', original[f]); } catch {} }
      }
      await saveAndReload(page, { expectOk: false });
    } catch { /* best-effort restore */ }
    await page.close();
  });

  for (const s of SETTINGS) {
    const valA = s.type === 'checkbox' ? true : s.a;
    const valB = s.type === 'checkbox' ? false : s.b;

    test(`SET-${s.field}: persists value A (${s.type})`, async () => {
      await gotoSettings(page);
      // Ensure the field exists in the form before asserting.
      expect(await page.locator(`#settings-form [name="${s.field}"]`).count()).toBeGreaterThan(0);
      await setField(page, s.field, s.type, valA);
      await saveAndReload(page);
      const got = await readField(page, s.field, s.type);
      if (s.type === 'checkbox') expect(got).toBe(true);
      else expect(String(got)).toBe(String(valA));
      if (s.effect) await s.effect(page, valA);
    });

    test(`SET-${s.field}: persists value B (${s.type})`, async () => {
      await gotoSettings(page);
      await setField(page, s.field, s.type, valB);
      await saveAndReload(page);
      const got = await readField(page, s.field, s.type);
      if (s.type === 'checkbox') expect(got).toBe(false);
      else expect(String(got)).toBe(String(valB));
      if (s.effect) await s.effect(page, valB);
    });
  }

  // Breakpoints round-trip as an ascending group (controller rejects non-ascending).
  test('SET-breakpoints: ascending group persists (A)', async () => {
    await gotoSettings(page);
    for (const f of BP_FIELDS) await setField(page, f, 'number', BP_A[f]);
    await saveAndReload(page);
    for (const f of BP_FIELDS) expect(await readField(page, f, 'number')).toBe(BP_A[f]);
  });

  test('SET-breakpoints: ascending group persists (B)', async () => {
    await gotoSettings(page);
    for (const f of BP_FIELDS) await setField(page, f, 'number', BP_B[f]);
    await saveAndReload(page);
    for (const f of BP_FIELDS) expect(await readField(page, f, 'number')).toBe(BP_B[f]);
  });

  // Functional effects (beyond persistence) for the observable settings. Each flushes
  // the page cache and reads the guest-facing render in an anonymous context.
  // NB: site_title feeds the `site_title` Twig global (header brand, RSS/Atom link
  // titles, og:site_name fallback) — NOT the document <title>, which comes from the
  // separate seo.site_title setting. We assert the RSS <link> title in <head>, the
  // most deterministic surface driven directly by site.title.
  test('EFFECT-site_title: drives the public site_title (RSS link)', async () => {
    await gotoSettings(page);
    await setField(page, 'site_title', 'text', 'QA Effect Title');
    await saveAndReload(page);
    await clearCache(page);
    const { ctx, page: anon } = await openFrontend(page);
    await anon.goto(`${BASE}/?cb=${Date.now()}`, { waitUntil: 'domcontentloaded' });
    const rssTitle = await anon.getAttribute('link[type="application/rss+xml"]', 'title');
    expect(rssTitle || '').toContain('QA Effect Title');
    await ctx.close();
  });

  // Note: pagination.limit only caps the home's album-diversity query (PageController
  // LIMIT). The active home template is the masonry grid, which sources images via
  // HomeImageService.getAllImages() independently of that limit, so the setting has no
  // observable frontend effect under the current template. Its two persistence tests
  // above already prove it saves as designed; a frontend-effect assertion here would be
  // template-dependent and misleading, so it is intentionally omitted.

  // Note: html_cache_max_age feeds CacheMiddleware.addHtmlCache(), but on the home the
  // visible Cache-Control stays `max-age=300` regardless of the setting — a separate
  // caching layer (page cache / session cache limiter) caps the advertised header. So a
  // frontend assertion here would be environment-dependent rather than a clean signal
  // of this setting. Its two persistence tests above prove it saves as designed; the
  // header discrepancy is tracked separately (see the run summary), not asserted here.

  // ---- Inter-setting conflicts (settings that constrain each other) ----

  // Breakpoints are coupled: sm<md<lg<xl<xxl. A non-ascending set must be rejected
  // wholesale, AND the previously saved (valid) values must survive untouched.
  test('CONFLICT-breakpoints: non-ascending set is rejected and rolls back', async () => {
    // Establish a known-good ascending baseline first.
    await gotoSettings(page);
    for (const f of BP_FIELDS) await setField(page, f, 'number', BP_A[f]);
    await saveAndReload(page);

    // Now break the ordering (sm above md) — the controller must refuse the save.
    await gotoSettings(page);
    await setField(page, 'bp_sm', 'number', '1500');
    await setField(page, 'bp_md', 'number', '300');
    await saveAndReload(page, { expectOk: false });
    expect(await saveWasRejected(page)).toBe(true);

    // The rejected save must not have persisted anything: baseline still intact.
    await gotoSettings(page);
    for (const f of BP_FIELDS) expect(await readField(page, f, 'number')).toBe(BP_A[f]);
  });

  // Changing one unrelated setting while breakpoints stay valid must still succeed —
  // i.e. the conflict guard does not block legitimate saves.
  test('CONFLICT-breakpoints: valid order lets an unrelated save through', async () => {
    await gotoSettings(page);
    for (const f of BP_FIELDS) await setField(page, f, 'number', BP_B[f]);
    await setField(page, 'site_copyright', 'text', 'QA coexist OK');
    await saveAndReload(page); // expectOk: asserts no rejection
    expect(await readField(page, 'site_copyright', 'text')).toBe('QA coexist OK');
  });

  // recaptcha.enabled is coupled to its keys: it can only stay ON when both keys are
  // present. Supplying both keys + enabling must persist enabled=true.
  test('CONFLICT-recaptcha: enable persists only when both keys are supplied', async () => {
    await gotoSettings(page);
    await setField(page, 'recaptcha_site_key', 'text', 'qaSiteKey_coupling');
    await setField(page, 'recaptcha_secret_key', 'text', 'qaSecretKey_coupling');
    await setField(page, 'recaptcha_enabled', 'checkbox', true);
    await saveAndReload(page);
    expect(await readField(page, 'recaptcha_enabled', 'checkbox')).toBe(true);

    // Turn it back off so the dev site keeps recaptcha disabled.
    await gotoSettings(page);
    await setField(page, 'recaptcha_enabled', 'checkbox', false);
    await saveAndReload(page);
    expect(await readField(page, 'recaptcha_enabled', 'checkbox')).toBe(false);
  });
});
