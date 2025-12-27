# Cimaise

**The photography portfolio CMS that gets out of your way.**

![License](https://img.shields.io/badge/license-MIT-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-purple.svg)
![Version](https://img.shields.io/badge/version-1.0.0-green.svg)

---

## Your Work Deserves Better

You've spent hours in the darkroom, days on location, years perfecting your craft. Your portfolio platform shouldn't fight against you.

**Cimaise** is built by photographers, for photographers. No bloated page builders. No plugin hell. No monthly fees. Just a clean, fast, beautiful showcase for your work.

### Why Photographers Choose Cimaise

**Blazing Fast** — Your images load instantly with automatic AVIF, WebP, and JPEG optimization. Six responsive breakpoints ensure perfect delivery on any device. No more visitors leaving because your site is slow.

**Film-Ready** — Unlike generic CMSs, Cimaise speaks your language. Track cameras, lenses, film stocks, developers, and labs. Whether you shoot Portra 400 on a Hasselblad or digital on a Leica, your metadata is organized and searchable.

**SEO That Works** — Server-side rendering, structured data, automatic sitemaps. Google actually understands your portfolio. No JavaScript-dependent pages that search engines can't read.

**Privacy First** — Built-in GDPR-compliant cookie consent, privacy-focused analytics, and no third-party tracking by default. Your visitors' data stays yours.

**Truly Yours** — Self-hosted, open source, MIT licensed. Install it on any PHP host. No vendor lock-in, no surprise price increases, no features held hostage behind premium tiers.

---

## 6 Home Page Templates

Choose the homepage layout that matches your style:

### 1. Classic

**The editorial approach.** A dramatic hero section welcomes visitors, followed by an infinite scroll masonry grid of your albums.

- **Hero Section** — Full-screen welcome with your logo and tagline
- **Album Carousel** — Smooth horizontal scrolling through featured work
- **Masonry Grid** — Pinterest-style layout respecting each image's aspect ratio
- **Infinite Scroll** — Seamless vertical discovery, no pagination
- **Configurable Animation** — Scroll direction (up/down) and speed

*Ideal for: Wedding photographers, portrait artists, commercial studios with diverse portfolios.*

---

### 2. Modern

**The gallery approach.** A minimalist split-screen design with fixed sidebar navigation and a scrolling image grid.

- **Fixed Sidebar** — Category filters are always visible, never scroll away
- **Two-Column Grid** — Clean, uniform presentation with smooth parallax effect
- **Hover Reveals** — Album title and description appear on hover
- **Mega Menu** — Full-screen navigation overlay
- **Lenis Smooth Scroll** — Buttery 60fps scrolling experience

*Ideal for: Fine art photographers, minimalist portfolios, those with well-defined categories.*

---

### 3. Parallax

**The immersive experience.** A three-column grid with smooth scroll parallax effects that brings your images to life.

- **Three-Column Grid** — Responsive layout (3 → 2 → 1 columns)
- **Parallax Motion** — Images move at different speeds as you scroll
- **Hover Overlays** — Album info appears on hover
- **Smooth Scroll** — Custom lerp-based scroll smoothing
- **Full-Screen Cards** — Each image takes 400px height for dramatic impact

*Ideal for: Landscape photographers, travel photographers, visual storytellers.*

---

### 4. Masonry Wall

**The pure gallery.** A CSS column-based masonry layout that fills the screen with your work.

- **Configurable Columns** — Desktop (2-8), Tablet (2-6), Mobile (1-4)
- **Adjustable Gaps** — Horizontal and vertical spacing (0-40px)
- **Infinite Scroll** — Automatic cloning creates seamless infinite loop
- **Fade-In Animation** — Staggered reveal as images load
- **Responsive Priority** — Above-fold images load first with high priority

*Ideal for: Street photographers, documentary work, high-volume portfolios.*

---

### 5. Snap Albums

**The presentation mode.** Full-screen split layout with synchronized vertical scrolling between album info and cover images.

- **Split Layout** — 45% info panel / 55% cover images (desktop)
- **Scroll Sync** — Left and right columns scroll together
- **Album Details** — Title, year, description, and photo count
- **Dot Indicators** — Visual navigation between albums
- **Mobile Optimized** — Stacked vertical cards on small screens

*Ideal for: Editorial portfolios, project-based work, photographers who tell stories.*

---

### 6. Gallery Wall

**The horizontal experience.** A scroll-linked horizontal gallery that transforms vertical scrolling into horizontal movement.

- **Sticky Container** — Gallery stays in viewport while scrolling
- **Horizontal Motion** — Scroll down, gallery moves sideways
- **Aspect-Aware Sizing** — Horizontal and vertical images sized proportionally
- **Hover Details** — Album info overlay on hover
- **Smooth Animation** — Lenis-powered buttery smooth movement

*Ideal for: Exhibition-style presentations, photographers who want something different.*

---

**Switch templates anytime** from Admin → Pages → Home Page. No content migration needed.

---

## 6 Gallery Templates

Each album can use a different presentation style:

<table>
<tr>
<td width="33%">
<strong>1. Classic Grid</strong><br>
Clean, uniform thumbnails in a regular grid. Perfect for consistent series where uniformity matters.
</td>
<td width="33%">
<strong>2. Masonry</strong><br>
Pinterest-style layout that respects aspect ratios. Images flow naturally without cropping.
</td>
<td width="33%">
<strong>3. Masonry Full</strong><br>
Full uncropped images in CSS columns. No cropping, no resizing—your images as you intended.
</td>
</tr>
<tr>
<td width="33%">
<strong>4. Magazine</strong><br>
Three-column animated scroll with direction control. Editorial spreads with dramatic presentation.
</td>
<td width="33%">
<strong>5. Magazine + Cover</strong><br>
Hero cover image with magazine-style scrolling content below. The best of both worlds.
</td>
<td width="33%">
<strong>6. Slideshow</strong><br>
Full-screen presentation mode. One image at a time, maximum impact.
</td>
</tr>
</table>

### Per-Gallery Configuration

Each template offers fine-grained control:

- **Columns** — Desktop (1-6), Tablet (1-4), Mobile (1-2)
- **Gaps** — Horizontal and vertical spacing
- **Animation** — Scroll direction, duration, effects
- **Lightbox** — Zoom, loop, keyboard nav, share buttons

---

## Protect Your Work

### Password-Protected Galleries

Share private client galleries without making them public:

- **Per-Album Passwords** — Each gallery can have its own access code
- **Session-Based Access** — Unlock once, browse freely for 24 hours
- **Clean URLs** — Share `yoursite.com/album/wedding-jones` not ugly token links
- **No Account Required** — Clients enter the password, that's it
- **Rate Limited** — Brute-force protection prevents password guessing

Perfect for: Client proofing, private event galleries, pre-release work.

### NSFW / Adult Content Mode

Show mature work responsibly:

- **Blur Previews** — Thumbnails are automatically blurred until age confirmation
- **Age Gate** — "I am 18+" confirmation before accessing content
- **Per-Album Setting** — Mark individual galleries as NSFW, keep the rest public
- **Global NSFW Warning** — Optional site-wide age gate that covers all NSFW content at once
- **Session Memory** — Visitors confirm once per session, not per image
- **Server-Side Enforcement** — Blur can't be bypassed by inspecting HTML

Perfect for: Boudoir photographers, figure artists, any work requiring viewer discretion.

---

## Film Photography Ready

Cimaise understands analog workflow:

### Equipment Tracking

- **Cameras** — Hasselblad 500C/M, Leica M6, Mamiya RB67, Canon AE-1...
- **Lenses** — 50mm f/1.4, 80mm f/2.8, Summicron 35mm...
- **Film Stocks** — Portra 400, Tri-X 400, Ektar 100, HP5+...
- **Developers** — Rodinal, HC-110, XTOL, D-76...
- **Labs** — Your trusted processing partners

### Automatic EXIF Extraction

Upload a digital file and Cimaise extracts:
- Camera make and model
- Lens information (including adapted lenses)
- ISO, shutter speed, aperture, focal length
- Exposure program, metering mode, flash
- GPS coordinates (if embedded)
- Artist and copyright metadata

### EXIF Display in Lightbox

When visitors view your images in the lightbox, they see the technical details:
- Camera and lens used
- Exposure settings (shutter, aperture, ISO)
- For film: stock, developer, lab
- Toggle on/off from Admin → Settings

### Lensfun Database Integration

Cimaise includes the complete [Lensfun](https://lensfun.github.io/) camera and lens database:
- **1,000+ cameras** from all major manufacturers
- **1,300+ lenses** with focal length data
- **Autocomplete** when adding equipment in admin
- **Auto-fill** focal lengths when selecting a lens
- Database updates available from Admin → Settings

### Film Metadata Input

For scans, manually add:
- Film stock and format (35mm, 120, 4x5)
- Developer and dilution
- Lab and scanning details
- Push/pull processing notes

### Custom Fields

Create your own metadata types:
- **Text fields** — Free-form input
- **Select fields** — Single choice from predefined values
- **Multi-select** — Multiple tags from a list

---

## Gallery Filters That Work

Let visitors explore your entire body of work:

### Multi-Criteria Filtering

- **Categories** — Wedding, Portrait, Landscape, etc.
- **Tags** — Multiple tags per album for cross-cutting themes
- **Year** — Filter by when the work was created
- **Location** — Where the shoot happened
- **Equipment** — Filter by camera, lens, or film stock

Visitors can combine filters: "Show me all medium-format Portra 400 shots from 2024."

### Shareable Searches

Every filter combination creates a unique URL. Share `yoursite.com/galleries?film=portra-400&year=2024` and recipients see exactly that filtered view.

---

## Automatic Image Optimization

**Upload once. Cimaise handles everything.**

Every photo you upload automatically generates optimized variants:

```text
Your Upload (8000x5333 RAW/JPEG)
    ↓
Originals stored safely in storage/originals/
    ↓
Public variants generated:
    ├── Small (768px)  → AVIF, WebP, JPEG
    ├── Medium (1200px) → AVIF, WebP, JPEG
    ├── Large (1920px)  → AVIF, WebP, JPEG
    ├── XL (2560px)     → AVIF, WebP, JPEG
    └── XXL (3840px)    → AVIF, WebP, JPEG
```

### Client-Side Compression

Before upload, Cimaise compresses images in your browser:
- **85% quality**, max 4000×4000px
- **50-70% smaller** uploads
- Faster uploads, same visual quality

### Why This Matters

| Visitor's Device | What They Get | Savings |
|------------------|---------------|---------|
| iPhone SE | Small WebP (768px) | 95% smaller |
| MacBook Pro | Large AVIF (1920px) | 80% smaller |
| 4K Display | XXL AVIF (3840px) | 70% smaller |

**Result:** Fast loading everywhere. No manual resizing. No Photoshop exports.

### Quality You Control

From Admin → Settings → Image Processing:

| Format | Default | Your Choice |
|--------|---------|-------------|
| AVIF | 50% | 40-70% |
| WebP | 75% | 60-90% |
| JPEG | 85% | 70-95% |

---

## Settings That Matter

Cimaise focuses on what photographers actually need:

### Site Identity
- **Logo & Favicon** — Upload once, automatic generation of all sizes (16px to 512px)
- **Site Title & Description** — Used in browser tabs, search results, social shares
- **Copyright Notice** — `© {year}` auto-updates each January
- **Social Profiles** — Display your Instagram, 500px, Flickr, website links in the header

### Gallery Presentation
- **Template Selection** — 6 gallery templates per album
- **Column Configuration** — Desktop (1-6), Tablet (1-4), Mobile (1-2)
- **Lightbox Options** — Zoom, loop, keyboard navigation, share buttons
- **Home Page Layout** — 6 templates, switchable anytime

### Image Handling
- **Format Enable/Disable** — Turn off AVIF if your host doesn't support it
- **Quality Sliders** — Balance quality vs file size per format
- **Breakpoints** — Customize which sizes get generated
- **Lazy Loading** — Above-fold images load instantly, below-fold on scroll

### Languages
- **Site Language** — English, Italian (fully translated)
- **Admin Language** — Complete Italian backend translation
- **Date Format** — ISO (2024-01-15) or European (15-01-2024)
- **i18n System** — Easy to add new languages via JSON files

### Frontend & Theming
- **Dark Mode** — One-click toggle to invert all frontend colors for a dark theme
  - Applies to all 6 home pages, galleries, albums, and login page
  - Near-black (#0a0a0a, #171717) and near-white (#fafafa) for optimal contrast
  - Smooth 0.3s transitions when switching modes
  - Admin panel always stays in light mode for clarity
- **Custom CSS** — Add your own CSS rules for fine-tuning
  - 50,000 character limit for extensive customizations
  - Security-sanitized (strips scripts, blocks external imports)
  - CSP-compliant with nonce-based inline styles
  - Frontend-only (doesn't affect admin panel)
  - Perfect for brand colors, custom fonts, or layout tweaks

### Developer Tools
- **Debug Logs** — View application logs from Admin → Settings
- **System Updater** — Check for and apply updates from admin panel

### Privacy & Compliance
- **Cookie Banner** — GDPR-compliant consent (Silktide integration)
- **Built-in Analytics** — No Google required, data stays on your server
- **reCAPTCHA** — Optional spam protection for contact forms

---

## Plugins

Cimaise includes a plugin system for extending functionality:

### Maintenance Mode

**Put your site under construction while you build.**

When enabled, visitors see a beautiful maintenance page while you work on your portfolio. Admins can still access the site normally.

- **One-Click Activation** — Enable/disable from Admin → Settings
- **Custom Message** — Write your own "coming soon" text
- **Site Branding** — Automatically shows your logo and site name
- **SEO Protected** — Sends proper 503 status and noindex headers
- **Admin Bypass** — Logged-in admins always see the real site
- **Multi-Language** — Admin login button adapts to site language (EN/IT/DE/FR/ES)

Perfect for: Initial setup, major redesigns, temporary closures.

---

## SEO Built for Photographers

### Automatic Structured Data

Every page outputs JSON-LD that Google understands:

```json
{
  "@type": "ImageGallery",
  "name": "Autumn in Kyoto",
  "author": { "@type": "Person", "name": "Your Name" },
  "image": [/* all your gallery images */]
}
```

### Rich Results Ready

- **BreadcrumbList** — `Home > Landscape > Autumn in Kyoto` in search results
- **ImageGallery** — Proper attribution and licensing info
- **Organization/Person** — Your professional identity
- **LocalBusiness** — For studio photographers with physical locations

### Social Sharing Optimized

When someone shares your gallery on social media:

- **Open Graph** — Beautiful previews on Facebook, LinkedIn
- **Twitter Cards** — Large image cards with proper attribution
- **Pinterest** — Rich pins with your images
- **WhatsApp** — Preview thumbnails in chat

### Technical SEO

- **Server-Side Rendering** — Every page is real HTML, not JavaScript-generated
- **Clean URLs** — `/album/autumn-kyoto` not `/album?id=42`
- **Automatic Sitemap** — XML sitemap updates as you add content
- **Canonical URLs** — No duplicate content penalties
- **robots.txt** — Configurable crawler instructions

---

## Security That Protects

Your portfolio is your livelihood. Cimaise takes security seriously:

### Attack Prevention
- **SQL Injection** — 100% prepared statements, no exceptions
- **XSS Attacks** — Automatic output escaping in all templates
- **CSRF Protection** — Every form has a unique token
- **Rate Limiting** — Login attempts, API calls, form submissions

### Authentication
- **Argon2id Hashing** — The most secure password algorithm available
- **Brute Force Protection** — Lockout after failed attempts
- **Session Security** — Secure cookies, proper expiration

### Content Security Policy

Modern CSP headers prevent malicious script injection:
- Inline scripts require unique nonces
- External scripts whitelisted by domain
- Frame embedding blocked
- HTTPS enforced (HSTS)

### Protected Media Serving

All image requests go through PHP validation:
- Password-protected albums require session authentication
- NSFW content requires age confirmation
- Path traversal attacks blocked
- Only image MIME types served

---

## Admin Experience

A dashboard that doesn't insult your intelligence:

- **Drag & Drop Everything** — Reorder albums, images, categories with intuitive dragging
- **Bulk Upload** — Drop 100+ images at once with parallel processing
- **Inline Editing** — Click any text to edit it. No page reloads
- **Real-Time Preview** — See exactly how your gallery will look before publishing
- **Visual Template Selector** — Preview home and gallery templates before applying
- **Equipment Browser** — Browse by camera, lens, film, or location

---

## The Technical Stuff

### Stack

| Layer | Technology |
|-------|------------|
| **Backend** | PHP 8.2+, Slim 4, Twig 3 |
| **Database** | SQLite (default) or MySQL 8+ |
| **Frontend** | Vite 6, Tailwind CSS 3.4, GSAP |
| **Lightbox** | PhotoSwipe 5 |
| **Upload** | Uppy 4 with Compressor |
| **Scroll** | Lenis Smooth Scroll |

### Requirements

- PHP 8.2+ with extensions: `pdo_sqlite` or `pdo_mysql`, `gd`, `curl`, `mbstring`, `json`
- Composer 2.x
- Node.js 18+ (for building frontend assets)
- Any web server (Apache, Nginx, Caddy) or PHP built-in server for development

### Quick Install (5 Minutes)

```bash
# Clone
git clone https://github.com/yourusername/cimaise.git
cd cimaise

# Install dependencies
composer install
npm install && npm run build

# Start the installer
php -S localhost:8080 -t public public/router.php
```

Open `http://localhost:8080/install` and follow the wizard:

1. **Database** — Choose SQLite (zero config) or enter MySQL credentials
2. **Admin Account** — Set your login credentials
3. **Site Settings** — Title, description, language, logo
4. **Done** — Start uploading your work

### CLI Alternative

```bash
php bin/console install
```

Interactive prompts guide you through the same setup without a browser.

---

## CLI Commands

```bash
php bin/console install              # Interactive installer
php bin/console migrate              # Run database migrations
php bin/console seed                 # Seed default templates and categories
php bin/console user:create          # Create admin user
php bin/console images:generate      # Generate all image variants
php bin/console nsfw:blur:generate   # Generate blur variants for NSFW albums
php bin/console sitemap:generate     # Build XML sitemap
php bin/console analytics:cleanup    # Purge old analytics data
```

---

## Deployment

### Apache

The included `.htaccess` handles everything. Just ensure `mod_rewrite` is enabled.

### Nginx

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/cimaise/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Cache static assets aggressively
    location ~* \.(avif|webp|jpg|jpeg|png|gif|ico|css|js)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

### Subdirectory Installation

Cimaise automatically detects subdirectory installations (e.g., `yoursite.com/portfolio/`) and adjusts all URLs accordingly. No configuration needed.

---

## Contributing

Contributions are welcome! Whether it's bug fixes, new features, translations, or documentation improvements.

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Make your changes
4. Submit a Pull Request

---

## License

MIT License — Use it however you want, commercially or personally.

---

## Support

- **Issues**: [GitHub Issues](https://github.com/yourusername/cimaise/issues)
- **Discussions**: [GitHub Discussions](https://github.com/yourusername/cimaise/discussions)

---

<p align="center">
  <strong>Built with care for photographers who refuse to compromise.</strong>
</p>
