# Changelog

All notable changes to Cimaise are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The release workflow extracts the `## [VERSION]` section below into the GitHub
release notes, so keep one section per released tag.

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

[1.4.7]: https://github.com/fabiodalez-dev/Cimaise/releases/tag/v1.4.7
[1.4.6]: https://github.com/fabiodalez-dev/Cimaise/releases/tag/v1.4.6
[1.4.5]: https://github.com/fabiodalez-dev/Cimaise/releases/tag/v1.4.5
[1.4.4]: https://github.com/fabiodalez-dev/Cimaise/releases/tag/v1.4.4
[1.4.3]: https://github.com/fabiodalez-dev/Cimaise/releases/tag/v1.4.3
[1.4.2]: https://github.com/fabiodalez-dev/Cimaise/releases/tag/v1.4.2
[1.4.1]: https://github.com/fabiodalez-dev/Cimaise/releases/tag/v1.4.1
[1.4.0]: https://github.com/fabiodalez-dev/Cimaise/releases/tag/v1.4.0
