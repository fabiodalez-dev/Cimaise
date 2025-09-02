# Changelog

All notable changes to this project will be documented in this file.

## [1.0.0] - 2025-09-02

### Added
- Post-install page available after installation to fine-tune site settings (optional).
- Admin login now shows the configured `site_logo` or falls back to `site_title`.
- Security `.htaccess` files committed and ensured for:
  - `public/media/` (PHP execution disabled, no directory listing)
  - `storage/originals/` and `storage/tmp/` (deny direct access, PHP off, no listing).

### Changed
- Installer UX overhauled for responsiveness and clarity:
  - Cards are full-width on small screens (mobile-first) and split on larger screens.
  - SQLite setup no longer asks for a path (display only with hidden field).
  - Subtle animations aligned with app style.
- Installer flow corrected:
  - Settings are collected before Confirm.
  - Database is created first; only then the site settings are inserted into production DB.
- Default seeded category is now `Photo` (slug `photo`).

### Fixed
- Album create page: image uploads only require selecting at least one Category; Title is auto-generated if missing.
- Post-install page routing works reliably after install.
- Eliminated DOM-based XSS vectors in galleries (safe DOM creation and escaping).

### Security
- Hardened headers (CSP cleaned, added `X-Permitted-Cross-Domain-Policies`, `Expect-CT`).
- Twig `safe_html` filter introduced and applied to previously `|raw` HTML outputs.
- Basic rate-limiting on analytics endpoint to reduce abuse.
- Reset script preserves and (re)creates `.htaccess` protections automatically.

### DevOps
- `bin/cleanup_leftovers.sh` now supports `--apply --reset-install` to:
  - Purge originals and variants (preserving `.htaccess`).
  - Remove `.env` and `database/database.sqlite`.
  - Recreate critical directories and ensure `.htaccess` files exist.

---

[1.0.0]: https://example.com/photocms/releases/1.0.0
