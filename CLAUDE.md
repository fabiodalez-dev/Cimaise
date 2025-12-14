# CLAUDE.md - Project Guidelines for AI Assistants

## Author Policy

**Fabio Dalez is the sole author of this project.**

- Do NOT add `Co-Authored-By` headers to commits
- Do NOT add any collaborator mentions
- All commits should appear as authored only by the repository owner

## Translation System

When adding new user-facing strings (buttons, labels, messages, etc.):

1. **Never hardcode text** in templates or controllers
2. Use the translation function in Twig: `{{ trans('key.name') }}`
3. **Create translations** via the admin panel at `/admin/texts`
4. Translations are stored in:
   - Database: `frontend_texts` table
   - JSON cache: `storage/translations/{lang}.json`
5. Organize by context: `nav.`, `footer.`, `album.`, `filter.`, `messages.`, etc.
6. Support parameter interpolation: `{{ trans('results', {count: 10}) }}`

## Database Compatibility

**The application MUST work with both MySQL and SQLite.**

### Rules:
- Always use the `Database` helper methods for cross-database SQL
- Test features on BOTH databases before committing
- Use these helper methods from `app/Support/Database.php`:
  - `orderByNullsLast()` - NULL sorting
  - `nowExpression()` - Current timestamp
  - `dateSubExpression()` - Date arithmetic
  - `insertIgnoreKeyword()` - Insert or ignore

### Avoid:
- MySQL-specific syntax (e.g., `IFNULL` → use `COALESCE`)
- SQLite-specific syntax
- Database-specific date functions without the helper

## Project Structure

```
photoCMS/
├── app/
│   ├── Controllers/Admin/    # 28 admin controllers
│   ├── Controllers/Frontend/ # 6 public controllers
│   ├── Services/             # Business logic (7 services)
│   ├── Support/              # Database, Hooks, PluginManager
│   ├── Views/admin/          # Admin Twig templates
│   ├── Views/frontend/       # Public Twig templates
│   └── Tasks/                # CLI commands
├── database/                 # Schemas and seeds
├── public/                   # Web root (index.php, assets, media)
├── storage/                  # Originals, logs, translations, cache
└── plugins/                  # Plugin directory
```

## Key Technical Details

### Routing
- Slim Framework 4 with route groups
- Admin routes protected by `AuthMiddleware`
- API routes at `/api/*`

### Templates
- Twig 3.x with auto-escaping
- Custom extensions: Translation, Security, Analytics, Hooks
- Layouts: `admin/_layout.twig`, `frontend/_layout.twig`

### Image Processing
- Originals stored in `storage/originals/`
- Variants generated to `public/media/{album}/{image}/`
- Formats: AVIF, WebP, JPEG
- 6 breakpoints: xs, sm, md, lg, xl, xxl

### Security
- Argon2id password hashing
- CSRF tokens on all forms
- CSP headers via `SecurityHeadersMiddleware`
- Rate limiting on sensitive endpoints

### Rich Text / HTML Sanitization
When handling rich text content (from TinyMCE or similar):

1. **On save (controller)**: Always sanitize with `\App\Support\Sanitizer::html($content)`
2. **On render (Twig)**: Use `|safe_html` filter, NEVER `|raw`
3. **CSS classes**: Use `.rich-text-content` or `.gallery-text-content` for styling
4. **Escape titles**: Always use `|e` filter for plain text titles

Example controller:
```php
$svc->set('home.gallery_text_content', \App\Support\Sanitizer::html($data['content'] ?? ''));
```

Example template:
```twig
<h2>{{ title|e }}</h2>
<div class="rich-text-content">{{ content|safe_html }}</div>
```

### Template URL Patterns
- Always use `{{ base_path }}/path` for asset URLs
- NEVER use `|trim('/')` on `base_path` - it breaks subdirectory installations
- For dynamic URLs: `{% if url starts with '/' %}{{ base_path }}{{ url }}{% else %}{{ url }}{% endif %}`

### Plugin System
- Hook-based (similar to WordPress)
- `Hooks::addAction()`, `Hooks::doAction()`
- `Hooks::addFilter()`, `Hooks::applyFilter()`
- Plugins in `plugins/` directory

## CLI Commands

```bash
php bin/console init              # Full initialization
php bin/console db:migrate        # Run migrations
php bin/console images:generate   # Regenerate all variants
php bin/console sitemap:build     # Build sitemaps
php bin/console diagnostics:report # System health check
```

## Common Patterns

### Adding a new admin page:
1. Create controller in `app/Controllers/Admin/`
2. Create Twig template in `app/Views/admin/`
3. Add route in `app/Config/routes.php`
4. Add menu item in `app/Views/admin/_layout.twig`

### Adding a new setting:
1. Use `SettingsService::get()` and `SettingsService::set()`
2. Add to appropriate settings page
3. Document in settings controller

### Adding a new translation:
1. Go to `/admin/texts`
2. Click "Add Text"
3. Set key (e.g., `nav.new_item`), value, and context
4. Use in templates: `{{ trans('nav.new_item') }}`

### JavaScript Initialization (SPA-compatible)
For inline scripts in templates, use this pattern to avoid double initialization:

```javascript
function initializeMyPage() {
  // initialization code
}

// Make globally available for SPA re-initialization
if (!window.pageInitializers) window.pageInitializers = {};
window.pageInitializers.myPage = initializeMyPage;

// Initialize once on DOM ready or immediately if already loaded
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initializeMyPage, { once: true });
} else {
  initializeMyPage();
}
```

Key points:
- Use `{ once: true }` to prevent duplicate listeners
- Check `document.readyState` for immediate execution when DOM is ready
- Store initializer in `window.pageInitializers` for SPA navigation

### TinyMCE Configuration
When adding TinyMCE rich text editors:

1. Use class `richtext` on textarea: `<textarea class="form-input richtext">`
2. Configure `valid_elements` to whitelist allowed HTML tags
3. Include `u,s` for underline/strikethrough support
4. Toolbar example: `'undo redo | bold italic underline strikethrough | bullist numlist | link | removeformat'`
5. Build assets after changes: `npm run build`
