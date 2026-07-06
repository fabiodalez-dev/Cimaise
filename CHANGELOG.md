# Changelog

All notable changes to Cimaise are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The release workflow extracts the `## [VERSION]` section below into the GitHub
release notes, so keep one section per released tag.

## [1.4.17] - 2026-07-06
### Fixed
- **Admin changes now reach the frontend immediately.** Saving home, galleries,
  filter, category or album settings soft-invalidates the page cache
  (stale-while-revalidate), so the next visitor — typically the admin checking
  their own change — was still served the OLD page once, and on low-traffic
  sites the stale copy could linger. Affected pages are now re-warmed in the
  background right after the save (single-flight, no admin slowdown): the very
  first visit after saving gets the fresh page.
- **Settings page: Enter now saves.** Pressing Enter in a text field on
  `/admin/settings` triggered "Generate favicons" instead of saving — HTML
  implicit submission fires the first `type=submit` button in the form, which
  was the favicon one. A hidden default submit now makes Enter save the form.

## [1.4.16] - 2026-07-02
### Fixed
- **Every first visit loaded each page twice.** The PWA runtime reloaded the
  page on `controllerchange` when the service worker claimed the page on its
  very first install (~430 ms wasted plus a full refetch for every new
  visitor). The reload now only fires for real updates (after the "Refresh"
  banner); the first-install claim is adopted silently.
- **Horizontal page scroll on mobile.** The home albums-carousel full-bleed
  negative margin exceeded the container padding, making the whole page scroll
  sideways by 16px at 375px and 8px at 768px. Margins now match the padding at
  each breakpoint, with `overflow-x: clip` on the section as a safety net.
- **Oversized image downloads in the dense-grid album layout.** Its
  `<source>` elements had no `sizes` (browsers assumed 100vw on a 3–4 column
  grid) and the `<img>` fallback pointed at the 3840px JPEG; both now carry the
  proper responsive chain.
- **Wasted preloads.** `Link: rel=preload` headers are no longer emitted on
  media/asset responses, and `/fonts/font-faces.css` is no longer preloaded at
  all — no layout references it as a stylesheet (typography.css embeds the
  @font-face rules), so it burned a request per page and spammed "preloaded
  but not used" console warnings.
- **Tracked `vendor/` synced with `composer.lock`.** The 1.4.15 security bumps
  (slim 4.15.2, twig 3.27.1) updated the lock file but not the committed
  vendor tree, so git-clone installs still shipped the old packages. Release
  ZIPs and the Docker image were unaffected (CI runs `composer install`).

### Accessibility
- Mobile menu button now has an `aria-label`; the header logo is no longer a
  second `<h1>`; added a skip-to-content link and a `main` landmark id.

## [1.4.15] - 2026-06-25
### Security
- **slim/slim → 4.15.2** (CVE-2026-48157): reflected XSS in Slim's
  `HtmlErrorRenderer` (affected `>=4.4.0,<=4.15.1`). Exposure here was limited
  because the app overrides the built-in renderer with custom Twig error
  handlers, but the dependency is now patched.
- **twig/twig → 3.27.1** (CVE-2026-48808, CVE-2026-48805): Twig sandbox
  allowlist-bypass / state-regression advisories (affected `<3.27.0`). Not
  exploitable here (the app does not use the Twig sandbox); bumped to stay
  current and clear the advisory.
- **phpunit/phpunit → 10.5.63** (CVE-2026-24765, dev-only): not shipped in the
  production build; bumped so `composer audit` reports no advisories.

### Added
- Official **Docker image** (multi-arch `linux/amd64` + `linux/arm64`):
  `Dockerfile`, `docker-compose.yml`, `docker/` runtime config, and a
  `docker-publish.yml` workflow that builds each architecture on a native runner
  and publishes to Docker Hub (`fabiodalez/cimaise`) and GHCR on every tag. See
  [`DOCKER.md`](DOCKER.md).

## [1.4.14] - 2026-06-18
### Fixed
- **Broken photos / "some images don't load".** When the host had PHP
  `display_errors` on, any warning/deprecation (e.g. a Twig deprecation on PHP
  8.4) was printed into the HTTP response body; for binary `/media` responses
  that prepended HTML to the image bytes, making them undecodable so the photo
  rendered broken (and the service worker then cached the corrupt bytes). PHP
  diagnostics now always go to the error log, never the response body — on-screen
  display is enabled only when `APP_DEBUG=true`.
- **Home (modern):** above-the-fold photos load eagerly (real `srcset` +
  `fetchpriority`) instead of showing LQIP "holes" until JavaScript ran.
- **Home (classic):** the animated infinite-gallery now server-renders the lead
  images of all three columns, so each column's first + next photo are visible at
  first paint instead of empty columns filling in later.
- Admin page editors: the "view page" button now respects the configurable About
  slug, and every `target="_blank"` view-page link carries `rel="noopener
  noreferrer"`.

### Changed
- **Self-updating PWA cache (cleanup for returning visitors on every update).**
  The service-worker cache is now tied to the app version (`cimaise-<version>`,
  derived from the `/sw.js?v=<version>` the page registers), so each update
  automatically purges the previous caches — visitors no longer need to clear
  their browser cache by hand after an update. The worker also leaves
  cross-origin requests to the network and only caches genuine image responses,
  and a broken `/media` image self-heals at runtime (evict the bad cache entry,
  then retry with a cache-bust).

## [1.4.13] - 2026-06-18
### Added
- **Modern image pipeline (#109).** A new capability-detected engine produces
  variants via a fast, low-memory libvips path when available, falling back to
  Imagick/GD so it runs unchanged on any host.
- **HEIC/HEIF import** — iPhone photos are accepted whenever the server can
  decode them (libheif via libvips, or the Imagick HEIC delegate) and converted
  to standard web variants; originals keep their `.heic`/`.heif` extension.
- **JPEG-XL (opt-in)** — when enabled in Settings → Image Processing and the
  server can encode it (a libvips build with libjxl, or the standalone `cjxl`
  binary), Cimaise generates `.jxl` variants and serves them via `<picture>` to
  capable browsers, falling back to AVIF/WebP/JPEG everywhere else.
- **Diagnostics → Imaging Engine** panel showing the active engine and what the
  host supports (libvips, HEIC read, AVIF/JPEG-XL write, optimizers).
- **"Back to Pages" arrow** on the home/about/cookie/license/privacy editors.
- **Admin backend redesign** — the "Mindful Moments" palette (admin-scoped),
  a dashboard with real KPI stat blocks + an analytics snapshot, a refreshed
  launcher grid, and a dark-mode switcher.

### Changed
- `image_variants.format` now allows `jxl` (cross-DB migration 1.4.13 for both
  SQLite and MySQL; prebuilt template regenerated).
- Admin dark-mode toggle and the layout script block are SPA-safe (no duplicate
  listeners after in-place navigation); destructive/disabled buttons are legible
  in both themes.

### Fixed
- Cross-database SQL portability (SQLite + MySQL, incl. 5.7): analytics
  top-pages, updater history, and the image-rating plugin migration no longer
  rely on engine-specific syntax.
- Protected-media serving hardened: the strict DB-path MIME gate verifies the
  JPEG-XL signature directly (libmagic-independent), and all media deletions are
  realpath-confined to the media directories.
- Admin i18n: filled missing `admin.*` translation keys; JPEG-XL settings labels
  use `trans()` like the other formats.
- HEIC dimensions are read with a HEIC-aware reader (no more zeroed height), and
  the "HEIC not supported" message no longer leaks server library details to the
  client (logged server-side instead).

## [1.4.12] - 2026-06-16
### Changed
- Lightbox caption now fully hides when the photo is zoomed in / shown full
  screen, so the enlarged image is unobstructed. Building on 1.4.11 (which keeps
  the caption readable above the photo in the fit view), entering zoom now sets
  the caption to `opacity:0; pointer-events:none` instead of leaving it to
  overlap the enlarged image; returning to fit restores its toggled state.

## [1.4.11] - 2026-06-16
### Fixed
- Lightbox caption was hidden behind the photo at full screen / when zoomed, and
  appeared a beat late when opening. Root cause: PhotoSwipe core styles the
  caption element (it carries `pswp__hide-on-close`) with
  `.pswp .pswp__hide-on-close { z-index: 10 }` (specificity 0,2,0), which sat
  below our zoomed-image `z-index: 30` and beat our single-class caption rule.
  The caption is now pinned above the image with `z-index: 35 !important`,
  removed from the zoom "demote" list (so it stays visible while zoomed/zooming,
  including through the open animation), rendered earlier (PhotoSwipe
  `firstUpdate`), and its fade removed (`transition: none`) so the solid bar
  snaps in instead of appearing late. Service worker cache bumped to v5.

## [1.4.10] - 2026-06-16
### Fixed
- Lightbox caption (mobile): the 1.4.8 "solid background" never actually applied
  because the caption element carried an inline `style=""` (set in JS) that hard
  -overrode the stylesheet — including the mobile rule. The inline appearance is
  removed (all styling now lives in CSS), so on mobile the caption is a clean
  edge-to-edge bar at the bottom: solid background, **no drop shadow, no rounded
  corners**, and generous padding so the text/equipment no longer touch the
  edges. Desktop keeps its centered transparent overlay (also de-rounded).

## [1.4.9] - 2026-06-16
### Fixed
- Updater: a transient GitHub-side failure no longer breaks the update check or
  surfaces as a misleading "Version not found". GitHub GET requests now retry on
  5xx (the "Unicorn!" 502/503/504 page), 429 soft-throttle, and dropped
  connections (up to 3 attempts with a short linear backoff), and when GitHub is
  still unavailable after the retries the error message says so explicitly
  ("GitHub is temporarily unavailable (HTTP 5xx). Please try again in a few
  minutes.") instead of being collapsed into "Version not found" downstream.

## [1.4.8] - 2026-06-16
### Fixed
- Lightbox caption: on mobile the description/equipment area now has a solid
  background (white in light mode, dark in dark mode) so the text no longer
  overlaps and becomes unreadable over the photo.
- Magazine album layout: only the visible responsive variant's marquee tracks
  are cloned/filled now (hidden variants are skipped via an `offsetParent`
  guard, and originals are stored on the track element), and the visible
  variant is (re)filled on resize — aligning album.twig with the shared gallery
  runtime and avoiding ~3× DOM duplication on large albums (CodeRabbit #107).

## [1.4.7] - 2026-06-16
### Fixed
- Magazine album layout (`?template=3`): smoother scroll and no more "the
  preloader adjusts and shifts the text" jump while loading.
  - Reserved the template-switcher row height (`#template-switcher` min-height)
    so it no longer grows when FontAwesome loads async and pushes the gallery
    (and everything below) down — the visible layout jump on load. CLS on the
    page dropped from ~0.38 to ~0.01.
  - Flattened the magazine's `perspective`/`rotateX` (it was visually
    imperceptible but forced the whole animated image subtree into a costly 3D
    compositing context that repainted every frame) and added `contain` to the
    columns, so the marquee scroll is noticeably smoother.

## [1.4.6] - 2026-06-16
### Fixed
- Updater: clear the compiled Twig template cache, the file page cache and the DB
  `page_cache` after installing an update. Previously an update that changed
  templates (inline styles/markup) kept serving the stale compiled templates
  until the caches expired or were cleared by hand.

## [1.4.5] - 2026-06-16
### Fixed
- Home templates no longer scroll horizontally on mobile. The LQIP placeholders
  use `transform: scale(1.1)` (to hide their blurred edges); while still blurred
  the scaled box overflowed its column and, where the wrapper didn't clip,
  pushed the whole page sideways. The masonry items now clip the overflow
  (`overflow: hidden`, which also honours the image border-radius), and a global
  `body { overflow-x: clip }` guard in `app.css` (loaded by every frontend
  layout, including the no-`<main>` modern layout) prevents any accidental
  horizontal page scroll without breaking the sticky header or smooth scroll.

## [1.4.4] - 2026-06-15
### Fixed
- Magazine layout: `applyVariant()` re-queries the wrap nodes on every call so the
  resize handler stays correct after the template switcher replaces the gallery
  contents in place (previously it could style detached, stale nodes).
- Snap home: keep `aria-current` in sync with the visual reset when the breakpoint
  changes, and preserve `env(safe-area-inset-bottom)` at the 480px breakpoint so
  the album text isn't hidden under the home indicator on notched phones.
- Category page: pass the NSFW-consent flag to the parent-category hierarchy so
  admins and consented users see the same listing as the categories list.
- Image variants: force the `jpg` baseline when a persisted formats record is a
  non-empty all-`false` array (legacy/corrupt), in both `VariantMaintenanceService`
  and `UploadService` — this previously slipped through the guard and silently
  disabled all variant generation.

## [1.4.3] - 2026-06-15
### Added
- Updater now merges new default translation keys into preserved translation files
  on update: keys added in newer releases reach existing installs (no more raw
  `admin.x.y` labels after updating), while admin edits are never overwritten.

## [1.4.2] - 2026-06-15
### Fixed
- Admin: mobile-friendly sticky action bars — buttons wrap/stack instead of
  overflowing and being clipped off-screen on narrow viewports (updates, SEO,
  privacy, filter settings, pages, home).
- Frontend: dark-mode fix for the share button label and social icons (they no
  longer render black on a dark background).
### Changed
- Tests: classic-home access tests aligned to the "only real covers on the home"
  behaviour; magazine resize spec hardened; PHPStan baseline regenerated.

## [1.4.1] - 2026-06-15
### Fixed
- Magazine layout: re-apply the responsive variant and load its images on resize
  so narrowing the window no longer leaves the wrong variant or blank photos; the
  bottom veil now covers the desktop 3D-tilt overhang (no 1px sliver).
- Mobile menu: opens to full height in a single tap (height capped to the menu's
  own top edge, not a circular header measurement); scroll lock + close-timer race
  handled.
- Home: shows only real covers — password-protected albums are never previewed,
  NSFW albums are gated by consent, filtered at the SQL level (also in the cache
  warm path).
- Mobile lightbox: counter no longer wraps, fullscreen-exit icon, empty-category
  filtering.
- Home performance: single JS-built desktop marquee; only the visible variant's
  lead images are eager-loaded.
### Changed
- PWA service worker cache version bumped (drops stale cached pages after deploy).

## [1.4.0] - 2026-06-15
### Added
- Signed (Ed25519) plugin/update mechanism, admin-configurable GitHub token
  (encrypted at rest), DELIMITER/BEGIN-END-aware migration SQL splitter, and a
  dual-DB migration smoke test.

[1.4.14]: https://github.com/fabiodalez-dev/Cimaise/releases/tag/v1.4.14
[1.4.13]: https://github.com/fabiodalez-dev/Cimaise/releases/tag/v1.4.13
[1.4.12]: https://github.com/fabiodalez-dev/Cimaise/releases/tag/v1.4.12
[1.4.11]: https://github.com/fabiodalez-dev/Cimaise/releases/tag/v1.4.11
[1.4.10]: https://github.com/fabiodalez-dev/Cimaise/releases/tag/v1.4.10
[1.4.9]: https://github.com/fabiodalez-dev/Cimaise/releases/tag/v1.4.9
[1.4.8]: https://github.com/fabiodalez-dev/Cimaise/releases/tag/v1.4.8
[1.4.7]: https://github.com/fabiodalez-dev/Cimaise/releases/tag/v1.4.7
[1.4.6]: https://github.com/fabiodalez-dev/Cimaise/releases/tag/v1.4.6
[1.4.5]: https://github.com/fabiodalez-dev/Cimaise/releases/tag/v1.4.5
[1.4.4]: https://github.com/fabiodalez-dev/Cimaise/releases/tag/v1.4.4
[1.4.3]: https://github.com/fabiodalez-dev/Cimaise/releases/tag/v1.4.3
[1.4.2]: https://github.com/fabiodalez-dev/Cimaise/releases/tag/v1.4.2
[1.4.1]: https://github.com/fabiodalez-dev/Cimaise/releases/tag/v1.4.1
[1.4.0]: https://github.com/fabiodalez-dev/Cimaise/releases/tag/v1.4.0
