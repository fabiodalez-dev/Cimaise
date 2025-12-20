# Cimaise

**The photography portfolio CMS that gets out of your way.**

![License](https://img.shields.io/badge/license-MIT-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-purple.svg)
![Version](https://img.shields.io/badge/version-1.0.0-green.svg)

<p align="center">
  <img src="docs/preview.png" alt="Cimaise Preview" width="800">
</p>

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

## Two Ways to Present Your Work

Choose the homepage layout that matches your style:

### Classic Home

<p align="center">
  <img src="docs/home-classic.png" alt="Classic Home" width="600">
</p>

**The editorial approach.** A dramatic hero section welcomes visitors, followed by an infinite scroll masonry grid of your albums. Perfect for photographers who want:

- **Hero Section** — Full-screen welcome with your logo and tagline
- **Album Carousel** — Smooth horizontal scrolling through featured work
- **Masonry Grid** — Pinterest-style layout respecting each image's aspect ratio
- **Infinite Scroll** — Seamless vertical discovery, no pagination
- **Configurable Animation** — Scroll direction (up/down) and speed

Ideal for: Wedding photographers, portrait artists, commercial studios with diverse portfolios.

### Modern Home

<p align="center">
  <img src="docs/home-modern.png" alt="Modern Home" width="600">
</p>

**The gallery approach.** A minimalist split-screen design with fixed sidebar navigation and a scrolling image grid. Perfect for photographers who want:

- **Fixed Sidebar** — Category filters are always visible, never scroll away
- **Two-Column Grid** — Clean, uniform presentation with smooth parallax effect
- **Hover Reveals** — Album title and description appear on hover
- **Mega Menu** — Full-screen navigation overlay
- **Lenis Smooth Scroll** — Buttery 60fps scrolling experience

Ideal for: Fine art photographers, minimalist portfolios, those with well-defined categories.

**Switch anytime** from Admin → Pages → Home Page. No content migration needed.

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
- **Session Memory** — Visitors confirm once per session, not per image
- **Server-Side Enforcement** — Blur can't be bypassed by inspecting HTML

Perfect for: Boudoir photographers, figure artists, any work requiring viewer discretion.

---

## Gallery Filters That Work

Let visitors explore your entire body of work:

### Multi-Criteria Filtering

<p align="center">
  <img src="docs/galleries-filters.png" alt="Gallery Filters" width="600">
</p>

- **Categories** — Wedding, Portrait, Landscape, etc.
- **Tags** — Multiple tags per album for cross-cutting themes
- **Year** — Filter by when the work was created
- **Location** — Where the shoot happened
- **Equipment** — Filter by camera, lens, or film stock

### For Analog Photographers

- **Camera** — Hasselblad 500C/M, Leica M6, Mamiya RB67...
- **Lens** — 50mm f/1.4, 80mm f/2.8, 35mm Summicron...
- **Film Stock** — Portra 400, Tri-X 400, Ektar 100...
- **Process** — C-41, E-6, Black & White

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

Tune the balance between quality and file size for your specific work.

---

## Settings That Matter

Cimaise focuses on what photographers actually need:

### Site Identity
- **Logo & Favicon** — Upload once, automatic generation of all sizes (16px to 512px)
- **Site Title & Description** — Used in browser tabs, search results, social shares
- **Copyright Notice** — `© {year}` auto-updates each January

### Gallery Presentation
- **Template Selection** — Grid, Masonry, Magazine, Magazine+Cover per gallery
- **Column Configuration** — Desktop (1-6), Tablet (1-4), Mobile (1-2)
- **Lightbox Options** — Zoom, loop, keyboard navigation, share buttons
- **Home Page Layout** — Classic or Modern, switchable anytime

### Image Handling
- **Format Enable/Disable** — Turn off AVIF if your host doesn't support it
- **Quality Sliders** — Balance quality vs file size per format
- **Breakpoints** — Customize which sizes get generated
- **Lazy Loading** — Above-fold images load instantly, below-fold on scroll

### Languages
- **Site Language** — English, Italian (more coming)
- **Admin Language** — Can differ from public site
- **Date Format** — ISO (2024-01-15) or European (15-01-2024)

### Privacy & Compliance
- **Cookie Banner** — GDPR-compliant consent (Silktide integration)
- **Built-in Analytics** — No Google required, data stays on your server
- **reCAPTCHA** — Optional spam protection for contact forms

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

### Meta Control Per Page

For each album, customize:
- Page title (default: Album Name — Site Name)
- Meta description
- Social share image (defaults to album cover)

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

## See It In Action

### Gallery Templates

<table>
<tr>
<td width="50%">
<strong>Classic Grid</strong><br>
Clean, uniform thumbnails. Perfect for consistent series.
</td>
<td width="50%">
<strong>Masonry</strong><br>
Pinterest-style layout that respects aspect ratios.
</td>
</tr>
<tr>
<td width="50%">
<strong>Magazine</strong><br>
Editorial spreads with dramatic full-width images.
</td>
<td width="50%">
<strong>Magazine + Cover</strong><br>
Hero image with magazine-style scrolling content.
</td>
</tr>
</table>

### Admin Experience

A dashboard that doesn't insult your intelligence:

- **Drag & Drop Everything** — Reorder albums, images, categories with intuitive dragging
- **Bulk Upload** — Drop 100 images at once, grab coffee, come back to organized variants
- **Inline Editing** — Click any text to edit it. No page reloads, no modal dialogs
- **Real-Time Preview** — See exactly how your gallery will look before publishing

---

## The Technical Stuff

### Stack

| Layer | Technology |
|-------|------------|
| **Backend** | PHP 8.2+, Slim 4, Twig 3 |
| **Database** | SQLite (default) or MySQL 8+ |
| **Frontend** | Vite 6, Tailwind CSS 3.4, GSAP |
| **Lightbox** | PhotoSwipe 5 |
| **Upload** | Uppy 4 |

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
