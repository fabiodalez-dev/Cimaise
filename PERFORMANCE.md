# Performance & Cache System

This document describes the performance optimization system implemented in Cimaise, including compression and caching features.

## Overview

The performance system consists of three layers:

1. **PHP Middleware** - Runtime compression and cache headers
2. **Apache Configuration** - Server-level compression and caching
3. **Vite Build Optimization** - Frontend asset optimization

## Features

### 1. Compression System

The compression system supports both Brotli and Gzip compression algorithms.

#### Supported Algorithms

- **Brotli** (preferred): Better compression ratio, supported by modern browsers
- **Gzip**: Fallback for older browsers, universal support
- **Deflate**: Additional fallback option

#### Configuration

Compression can be configured via the Admin Settings page under "Performance & Cache":

- **Visual Status Indicators**: The admin interface shows which compression algorithms are available on your server with color-coded badges:
  - 🟢 Green badge = Extension available and working
  - 🔴 Red badge = Extension not available
  - Real-time detection of Brotli, Gzip, and Zlib availability

- **Enable/Disable**: Toggle compression on/off (automatically disabled if no compression extensions are available)

- **Compression Type**:
  - `auto` (recommended) - Automatically select best available (Brotli → Gzip → Deflate → None)
    - The dropdown shows which algorithms will be used based on availability
  - `brotli` - Force Brotli only (option disabled if not available)
  - `gzip` - Force Gzip only (option disabled if not available)

- **Compression Level**: 0-11 for Brotli, 1-9 for Gzip (higher = better compression but slower)

- **Smart UI**: The interface adapts based on server capabilities:
  - Fields are disabled if no compression is available
  - Contextual warnings appear if extensions are missing
  - Installation tips are shown for missing extensions

#### How It Works

The `CompressionMiddleware` (`app/Middlewares/CompressionMiddleware.php`):

1. Checks if compression is enabled in settings
2. Verifies the response is compressible (minimum 860 bytes, appropriate content type)
3. Selects the best compression algorithm based on:
   - Server availability (checked via `function_exists()`)
   - Client support (from `Accept-Encoding` header)
   - User preference (from settings)
4. Compresses the response body
5. Adds appropriate headers (`Content-Encoding`, `Vary`)

**Automatic Fallback Chain:**
```
User Request
    ↓
Auto Mode Selected?
    ↓
├─→ Brotli available & client supports? → Use Brotli
├─→ Gzip available & client supports?   → Use Gzip
├─→ Deflate available & client supports? → Use Deflate
└─→ No compression available            → Serve uncompressed
```

This means **you don't need to configure anything** - just enable compression and the system will automatically use the best available option. If Brotli is not installed, Gzip will be used seamlessly.

#### Compressible Content Types

- HTML, CSS, JavaScript
- JSON, XML, RSS, Atom
- SVG images
- Web fonts (WOFF, WOFF2, TTF, OTF, EOT)

### 2. HTTP Cache System

The cache system adds appropriate HTTP cache headers to improve browser caching.

#### Cache Strategies

Different cache strategies are applied based on content type:

| Content Type | Default TTL | Strategy |
|--------------|-------------|----------|
| Static Assets (CSS, JS, images, fonts) | 1 year | Immutable, long cache |
| Media Files (photography) | 1 day | Public cache with ETag |
| HTML Pages | 5 minutes | Short cache with revalidation |
| Admin Pages | No cache | No-store |
| API Routes | No cache | No-store |

#### Configuration

Cache settings are configurable via Admin Settings:

- **Enable/Disable**: Toggle HTTP caching on/off
- **Static Assets Cache**: Max-age for CSS, JS, images, fonts (default: 31536000 seconds = 1 year)
- **Media Files Cache**: Max-age for photography images (default: 86400 seconds = 1 day)
- **HTML Pages Cache**: Max-age for dynamic HTML pages (default: 300 seconds = 5 minutes)

#### How It Works

The `CacheMiddleware` (`app/Middlewares/CacheMiddleware.php`):

1. Checks if caching is enabled in settings
2. Identifies the request type (static asset, media, HTML, admin, API)
3. Applies appropriate cache headers based on content type
4. Adds `ETag` for media files to enable conditional requests
5. Skips caching for admin and API routes

### 3. Apache-Level Optimization

The `.htaccess` file provides server-level compression and caching:

#### Compression (mod_brotli, mod_deflate)

```apache
<IfModule mod_brotli.c>
  # Brotli compression for text-based content
</IfModule>

<IfModule mod_deflate.c>
  # Gzip compression for text-based content
</IfModule>
```

#### Cache Control (mod_expires, mod_headers)

```apache
<IfModule mod_expires.c>
  # Expires headers for different file types
</IfModule>

<IfModule mod_headers.c>
  # Cache-Control headers
  # Vary header for proper content negotiation
</IfModule>
```

### 4. Vite Build Optimization

The Vite configuration (`vite.config.js`) has been optimized for production:

- **Minification**: Terser minification with console.log removal
- **Source Maps**: Disabled in production
- **CSS Code Splitting**: Enabled for better caching
- **Chunk Size Warning**: Set to 1000KB

## Requirements

### PHP Extensions

- **brotli** (optional): For Brotli compression support
  - Install: `pecl install brotli`
  - Enable: Add `extension=brotli.so` to `php.ini`
- **zlib** (required): For Gzip compression
  - Usually included with PHP by default

### Apache Modules

- **mod_brotli** (optional): Server-level Brotli compression
- **mod_deflate** (required): Server-level Gzip compression
- **mod_expires** (recommended): Expires headers
- **mod_headers** (recommended): Custom headers

## Testing

### Verify Compression

```bash
# Test Brotli compression
curl -H "Accept-Encoding: br" -I https://yourdomain.com/

# Test Gzip compression
curl -H "Accept-Encoding: gzip" -I https://yourdomain.com/

# Expected response:
# Content-Encoding: br
# or
# Content-Encoding: gzip
```

### Verify Cache Headers

```bash
# Test static asset caching
curl -I https://yourdomain.com/assets/app.css

# Expected response:
# Cache-Control: public, max-age=31536000, immutable
# Expires: [date 1 year in future]
```

### Browser DevTools

1. Open Chrome DevTools (F12)
2. Go to Network tab
3. Reload the page
4. Check response headers for:
   - `Content-Encoding: br` or `gzip`
   - `Cache-Control: ...`
   - Reduced file sizes (e.g., 100KB → 20KB)

## Performance Impact

Expected improvements:

- **Brotli Compression**: 15-25% better than Gzip for text content
- **Gzip Compression**: 60-80% size reduction for text content
- **HTTP Cache**:
  - Eliminates repeated asset downloads
  - Reduces server load
  - Faster page loads for returning visitors

## Troubleshooting

### Compression Not Working

1. **Check PHP extensions**: `php -m | grep -E "brotli|zlib"`
   - If only zlib is shown: Gzip will work, Brotli won't
   - If neither is shown: No compression available
2. **Check admin interface**: Go to Settings → Performance & Cache
   - Look at the colored badges to see what's available
   - Green badge = working, Red badge = not available
3. **Check settings**: Ensure compression is enabled in Admin Settings
4. **Check response size**: Responses < 860 bytes are not compressed
5. **Check content type**: Only compressible types are compressed
6. **Check client support**: Verify `Accept-Encoding` header in request

**Example Scenarios:**

| Server Setup | Admin Shows | Result |
|--------------|-------------|--------|
| Brotli + Gzip installed | 🟢 Brotli, 🟢 Gzip | Auto mode uses Brotli |
| Only Gzip installed | 🔴 Brotli, 🟢 Gzip | Auto mode uses Gzip |
| No extensions | 🔴 Brotli, 🔴 Gzip | Compression disabled automatically |

### Cache Not Working

1. **Check settings**: Ensure cache is enabled in Admin Settings
2. **Hard refresh**: Use Ctrl+Shift+R to bypass cache
3. **Check route**: Admin and API routes are never cached
4. **Check headers**: Verify `Cache-Control` header in response

### Performance Not Improved

1. **Clear browser cache**: Old cached files may still be in use
2. **Check Apache modules**: Ensure mod_expires and mod_headers are enabled
3. **Rebuild assets**: Run `npm run build` to regenerate optimized assets
4. **Check database**: Large database queries may still slow down pages

## Database Schema

Performance settings are stored in the `settings` table:

```sql
-- Compression settings
performance.compression_enabled (boolean)
performance.compression_type (string: 'auto', 'brotli', 'gzip')
performance.compression_level (integer: 0-11)

-- Cache settings
performance.cache_enabled (boolean)
performance.static_cache_max_age (integer: seconds)
performance.media_cache_max_age (integer: seconds)
performance.html_cache_max_age (integer: seconds)
```

## Files Modified/Created

### New Files
- `app/Middlewares/CompressionMiddleware.php` - Compression middleware
- `app/Middlewares/CacheMiddleware.php` - Cache headers middleware
- `PERFORMANCE.md` - This documentation

### Modified Files
- `app/Services/SettingsService.php` - Added performance defaults
- `app/Controllers/Admin/SettingsController.php` - Added settings save logic
- `app/Views/admin/settings.twig` - Added admin UI
- `public/index.php` - Registered middleware
- `public/.htaccess` - Added compression and cache directives
- `vite.config.js` - Optimized build configuration

## Best Practices

1. **Keep cache enabled**: Improves performance significantly
2. **Use Brotli when possible**: Better compression than Gzip
3. **Set appropriate cache times**:
   - Long for static assets (they rarely change)
   - Short for dynamic content (to see updates quickly)
4. **Monitor performance**: Use tools like GTmetrix or PageSpeed Insights
5. **Test after changes**: Always verify compression and caching work after updates

## License

Same as Cimaise project.
