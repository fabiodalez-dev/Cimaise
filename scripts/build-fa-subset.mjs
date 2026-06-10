/**
 * Build a frontend-only FontAwesome subset (CSS + optional woff2/ttf fonts).
 *
 * - Scans every source that can emit frontend markup (frontend views, shared
 *   partials, controllers/services that build HTML strings, plugins, frontend
 *   JS, DB seed SQL) for `fa-*` class usage.
 * - Extracts from the vendored all.min.css only: the base/utility rules
 *   (.fa, sizing, animations, etc.), the @font-face declarations for the
 *   Font Awesome 6 families actually referenced, and the glyph rules for the
 *   icons in use (alias selectors are kept automatically because a rule is
 *   kept when ANY of its selectors matches).
 * - If python3 + fonttools (pyftsubset) are available, also produces subset
 *   font files (new *.subset.woff2/.subset.ttf — originals are never touched)
 *   and points the subset CSS at them.
 *
 * Output: public/assets/vendor/fontawesome/css/frontend-subset.css
 *         public/assets/vendor/fontawesome/webfonts/*.subset.{woff2,ttf}
 *
 * Admin and installer keep using all.min.css (full set, user-pasted icons).
 * Run after copy-vendor.js (postinstall) and as part of `npm run build`.
 */

import { readFileSync, writeFileSync, readdirSync, statSync, existsSync } from 'fs';
import { dirname, join, extname } from 'path';
import { fileURLToPath } from 'url';
import { spawnSync } from 'child_process';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = join(__dirname, '..');
const faDir = join(root, 'public', 'assets', 'vendor', 'fontawesome');
const allCssPath = join(faDir, 'css', 'all.min.css');
const outCssPath = join(faDir, 'css', 'frontend-subset.css');

// ---------------------------------------------------------------------------
// 1. Collect used fa-* class names
// ---------------------------------------------------------------------------
const SCAN_DIRS = [
  'app/Views/frontend',
  'app/Views/errors',
  'app/Views/partials',
  'app/Controllers',
  'app/Services',
  'plugins',
  'resources/js',
  'public/assets/js',
  'database',
  'translations',
];
const SCAN_EXT = new Set(['.twig', '.php', '.js', '.ts', '.mjs', '.sql', '.json', '.html']);
const EXCLUDE_DIRS = new Set(['node_modules', 'vendor', '.git']);

const used = new Set();
const faRe = /\bfa-[a-z0-9][a-z0-9-]*\b/g;

function scan(dir) {
  let entries;
  try {
    entries = readdirSync(dir);
  } catch {
    return;
  }
  for (const entry of entries) {
    if (EXCLUDE_DIRS.has(entry)) continue;
    const p = join(dir, entry);
    const st = statSync(p);
    if (st.isDirectory()) {
      scan(p);
    } else if (SCAN_EXT.has(extname(entry))) {
      const txt = readFileSync(p, 'utf8');
      for (const m of txt.matchAll(faRe)) used.add(m[0]);
    }
  }
}
for (const d of SCAN_DIRS) scan(join(root, d));

// Manual extras: style prefixes + states that may be toggled from JS at runtime.
for (const extra of [
  'fa', 'fas', 'far', 'fab', 'fa-solid', 'fa-regular', 'fa-brands',
  'fa-fw', 'fa-spin', 'fa-pulse', 'fa-spin-pulse', 'fa-inverse',
  'fa-xs', 'fa-sm', 'fa-lg', 'fa-2x',
]) used.add(extra);

// ---------------------------------------------------------------------------
// 2. Parse all.min.css into top-level rules (brace-aware)
// ---------------------------------------------------------------------------
const css = readFileSync(allCssPath, 'utf8');
const rules = [];
{
  let i = 0;
  const n = css.length;
  while (i < n) {
    // skip whitespace
    while (i < n && /\s/.test(css[i])) i++;
    if (i >= n) break;
    // comments
    if (css[i] === '/' && css[i + 1] === '*') {
      const end = css.indexOf('*/', i + 2);
      rules.push({ raw: css.slice(i, end + 2), comment: true });
      i = end + 2;
      continue;
    }
    const start = i;
    // read until first { then to matching }
    let depth = 0;
    let selEnd = -1;
    while (i < n) {
      const c = css[i];
      if (c === '{') {
        if (depth === 0) selEnd = i;
        depth++;
      } else if (c === '}') {
        depth--;
        if (depth === 0) { i++; break; }
      }
      i++;
    }
    const raw = css.slice(start, i);
    const selector = css.slice(start, selEnd).trim();
    const body = css.slice(selEnd + 1, i - 1).trim();
    rules.push({ raw, selector, body });
  }
}

// ---------------------------------------------------------------------------
// 3. Filter rules
// ---------------------------------------------------------------------------
const KEEP_FONT_FAMILIES = new Set(['Font Awesome 6 Free', 'Font Awesome 6 Brands']);
const glyphBodyRe = /^--fa:"(\\[0-9a-f]+)"(?:;--fa--fa:"[^"]*")?$/;

const kept = [];
const codepoints = new Set();
let glyphTotal = 0;
let glyphKept = 0;

for (const rule of rules) {
  if (rule.comment) {
    kept.push(rule.raw);
    continue;
  }
  const sel = rule.selector;
  if (sel.startsWith('@font-face')) {
    const fam = rule.body.match(/font-family:"([^"]+)"/);
    if (fam && KEEP_FONT_FAMILIES.has(fam[1])) kept.push(rule.raw);
    continue; // drop FA5 / FontAwesome (v4 shim) faces: nothing on the frontend uses them
  }
  const isGlyph = glyphBodyRe.test(rule.body) && sel.startsWith('.fa-') && !sel.includes(' ') && !sel.includes(':');
  if (!isGlyph) {
    kept.push(rule.raw);
    continue;
  }
  glyphTotal++;
  const selectors = sel.split(',').map((s) => s.trim().replace(/^\./, ''));
  if (selectors.some((s) => used.has(s))) {
    glyphKept++;
    kept.push(rule.raw);
    const cp = rule.body.match(glyphBodyRe)[1]; // e.g. \f063
    codepoints.add(parseInt(cp.slice(1), 16));
  }
}

// ---------------------------------------------------------------------------
// 4. Optional: subset the fonts with pyftsubset (fonttools)
// ---------------------------------------------------------------------------
let fontsSubset = false;
const hasFontTools = spawnSync('python3', ['-c', 'import fontTools, brotli'], { stdio: 'ignore' }).status === 0;
if (hasFontTools && codepoints.size > 0) {
  const unicodes = [...codepoints].map((c) => 'U+' + c.toString(16).toUpperCase()).join(',');
  const fonts = ['fa-solid-900', 'fa-regular-400', 'fa-brands-400'];
  let ok = true;
  for (const f of fonts) {
    for (const [flavor, ext] of [['woff2', 'woff2'], [null, 'ttf']]) {
      const src = join(faDir, 'webfonts', `${f}.${ext}`);
      const dst = join(faDir, 'webfonts', `${f}.subset.${ext}`);
      if (!existsSync(src)) { ok = false; continue; }
      const args = [
        src,
        `--unicodes=${unicodes}`,
        `--output-file=${dst}`,
        '--ignore-missing-unicodes',
        '--no-hinting',
        '--desubroutinize',
      ];
      if (flavor) args.push(`--flavor=${flavor}`);
      const res = spawnSync('pyftsubset', args, { stdio: 'inherit' });
      if (res.status !== 0) ok = false;
    }
  }
  fontsSubset = ok;
}

// ---------------------------------------------------------------------------
// 5. Write the subset CSS
// ---------------------------------------------------------------------------
let outCss = kept.join('');
if (fontsSubset) {
  outCss = outCss.replace(/\.\.\/webfonts\/(fa-(?:solid-900|regular-400|brands-400))\.(woff2|ttf)/g, '../webfonts/$1.subset.$2');
}
writeFileSync(outCssPath, outCss);

const kb = (p) => (statSync(p).size / 1024).toFixed(1) + ' KB';
console.log(`FontAwesome frontend subset written to ${outCssPath}`);
console.log(`  used fa-* tokens found: ${used.size}`);
console.log(`  glyph rules kept: ${glyphKept}/${glyphTotal} (${codepoints.size} codepoints)`);
console.log(`  css: ${kb(allCssPath)} -> ${kb(outCssPath)}`);
if (fontsSubset) {
  for (const f of ['fa-solid-900', 'fa-regular-400', 'fa-brands-400']) {
    const orig = join(faDir, 'webfonts', `${f}.woff2`);
    const sub = join(faDir, 'webfonts', `${f}.subset.woff2`);
    if (existsSync(sub)) console.log(`  ${f}.woff2: ${kb(orig)} -> ${kb(sub)}`);
  }
} else {
  console.log('  fonts NOT subset (fonttools/brotli unavailable) — subset css references original webfonts');
}
