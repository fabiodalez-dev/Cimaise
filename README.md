# Cimaise

**The photography portfolio CMS that gets out of your way.**

![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg?logo=php&logoColor=white)
![Slim](https://img.shields.io/badge/Slim-4.x-74a045.svg?logo=slim&logoColor=white)
![SQLite](https://img.shields.io/badge/SQLite-3-003B57.svg?logo=sqlite&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.x-4479A1.svg?logo=mysql&logoColor=white)
![Tailwind CSS](https://img.shields.io/badge/Tailwind-3.x-06B6D4.svg?logo=tailwindcss&logoColor=white)
![Vite](https://img.shields.io/badge/Vite-5.x-646CFF.svg?logo=vite&logoColor=white)

---

## Screenshots

### Home Page Templates

Choose from 7 distinct homepage layouts. The Classic template supports both horizontal and vertical scroll directions.

<table>
<tr>
<td width="33%"><img src="screenshot/home classic horizontal.jpg" alt="Classic Horizontal"><br><strong>Classic (Horizontal)</strong></td>
<td width="33%"><img src="screenshot/home classic vertical.jpg" alt="Classic Vertical"><br><strong>Classic (Vertical)</strong></td>
<td width="33%"><img src="screenshot/modern home.jpg" alt="Modern"><br><strong>Modern</strong></td>
</tr>
<tr>
<td width="33%"><img src="screenshot/Parallax Home.jpg" alt="Parallax"><br><strong>Parallax</strong></td>
<td width="33%"><img src="screenshot/home gallery wall.jpg" alt="Gallery Wall"><br><strong>Gallery Wall</strong></td>
<td width="33%"><img src="screenshot/home pure masonry.jpg" alt="Masonry Wall"><br><strong>Masonry Wall</strong></td>
</tr>
<tr>
<td width="33%"><img src="screenshot/home snap album.jpg" alt="Snap Albums"><br><strong>Snap Albums</strong></td>
<td width="33%"></td>
<td width="33%"></td>
</tr>
</table>

### Gallery Templates

6 ways to present your albums:

<table>
<tr>
<td width="33%"><img src="screenshot/template 1.jpg" alt="Classic Grid"><br><strong>Classic Grid</strong></td>
<td width="33%"><img src="screenshot/template 2.jpg" alt="Masonry"><br><strong>Masonry</strong></td>
<td width="33%"><img src="screenshot/template 3.jpg" alt="Masonry Full"><br><strong>Masonry Full</strong></td>
</tr>
<tr>
<td width="33%"><img src="screenshot/template 4.jpg" alt="Magazine"><br><strong>Magazine</strong></td>
<td width="33%"><img src="screenshot/template 5.jpg" alt="Dense Grid"><br><strong>Dense Grid</strong></td>
<td width="33%"><img src="screenshot/Template 6.jpg" alt="Large Grid"><br><strong>Large Grid</strong></td>
</tr>
</table>

### Password-Protected Galleries

<img src="screenshot/album protetto password.jpg" alt="Password Protected Album" width="600">

Share private client work without making it public. Each album can have its own password—clients enter it once and browse freely for 24 hours. Clean URLs like `yoursite.com/album/wedding-jones`, no ugly tokens. Rate limiting prevents brute-force attacks. Perfect for client proofing, private events, or pre-release work.

### NSFW / Adult Content Mode

<img src="screenshot/album nsfw.jpg" alt="NSFW Age Gate" width="600">

Show mature work responsibly. Thumbnails are automatically blurred until age confirmation. Visitors must confirm they're 18+ before accessing content. The setting is per-album—mark individual galleries as NSFW while keeping the rest public. Server-side enforcement means the blur can't be bypassed by inspecting HTML.

### NSFW + Password Combined

<img src="screenshot/album nsfw protetto da password.jpg" alt="NSFW and Password Protected" width="600">

For maximum protection, combine both. Visitors must confirm their age AND enter a password. The age gate appears first, followed by the password prompt. Ideal for private boudoir sessions or commissioned figure work.

### Dark Mode

<img src="screenshot/Dark Mode Category Archive.jpg" alt="Dark Mode" width="800">

One-click toggle inverts all frontend colors for a dark theme. Near-black backgrounds (#0a0a0a) with near-white text (#fafafa) for optimal contrast. Applies to all home pages, galleries, albums, and login. Smooth 0.3s transitions when switching modes.

### Category & Tag Archives

Dedicated archive pages for each category and tag, with smart filtering and beautiful layouts:

- **Category Archives** — Browse all albums in a category with filter sidebar
- **Subcategory Support** — Nested categories for complex organization
- **Tag Archives** — Cross-cutting themes across your portfolio
- **JSON Caching** — Archive pages are cached for instant loading
- **SEO Optimized** — Proper structured data for category/tag pages

### Typography Customization

<img src="screenshot/Typography.jpg" alt="Typography Settings" width="800">

Customize fonts for your portfolio with live preview. Choose from curated font pairs—serif options like EB Garamond, Playfair Display, Cormorant Garamond, or clean sans-serif like Inter, DM Sans, Manrope. Set different fonts for headings and body text. All fonts are served locally for GDPR compliance.

### PhotoSwipe Lightbox

<img src="screenshot/Lightbox.jpg" alt="Lightbox" width="800">

Full-screen image viewing with instant navigation. Caption and equipment metadata displayed below (camera, lens, category). Dot navigation shows position in gallery. Keyboard navigation, zoom controls, and share buttons. Clean minimal UI that doesn't distract from your images. Performance-optimized with disabled animations for instant image switching.

### EXIF Data Display

<img src="screenshot/exif.jpg" alt="EXIF Details" width="800">

Technical details panel shows focal length, aperture, shutter speed, ISO, and capture date. Automatically extracted from your uploads. Toggle on/off from settings. Film photographers can manually add stock, developer, and lab information.

### Gallery Filters

<img src="screenshot/filtri pagina galleria.jpg" alt="Gallery Filters" width="800">

Multi-criteria filtering lets visitors explore by category, tag, camera, lens, film stock, location, or year. Combine filters: "Show me all medium-format Portra 400 shots from 2024." Every filter combination creates a shareable URL.

### Galleries Archive

<img src="screenshot/gallerie.jpg" alt="Galleries Page" width="800">

Browse all albums with collapsible filter panel. Each card shows cover image, category badge, photo count, title, and description preview. Breadcrumb navigation and result count.

### Album Page

<img src="screenshot/album testata.jpg" alt="Album Header" width="800">

Rich album headers with colored category/tag badges, title, date, description, and equipment used. Social sharing buttons for Bluesky, Facebook, Pinterest, Telegram, Threads, WhatsApp, X. Template switcher lets visitors change gallery layout on the fly.

### About & Contact

<img src="screenshot/about-me.jpg" alt="About Page" width="600">

Customizable About page with bio text and contact form. Portrait placeholder, rich text description, and contact form with name, email, and message fields. reCAPTCHA v3 protection available.

---

## Admin Panel

### Category Hierarchy Management

Organize your portfolio with nested categories and drag-and-drop reordering:

- **Nested Categories** — Create parent/child category hierarchies for complex portfolios
- **Drag-and-Drop** — SortableJS-powered reordering with visual nesting
- **Categories Mega Menu** — Responsive navigation showing subcategories in grid layout
- **Badge Icons** — Custom icons for category identification
- **Album Counts** — Each category shows how many albums it contains

### Privacy, Cookie & License Pages

Built-in page management for legal compliance:

- **Privacy Policy** — Full TinyMCE editor for GDPR privacy notices
- **Cookie Policy** — Explain your cookie usage and consent practices
- **License Page** — Define image usage rights and licensing terms
- **Multi-Language** — Separate content per language (EN/IT)
- **Footer Links** — Automatic links in site footer

### Album Management

<img src="screenshot/admin album.jpg" alt="Album Management" width="800">

Create and organize your albums with drag-and-drop reordering. Each row shows cover thumbnail, title, categories, publish status, and SEO slug. Quick actions: view grid, edit, unpublish, delete. Switch between manual order and date order. Full metadata sidebar with Cameras, Lenses, Films, Developers, Labs, and Locations for film photographers.

### Media Library

<img src="screenshot/media page.jpg" alt="Media Library" width="800">

Visual grid of all uploaded images with batch selection and pagination. Right panel shows photo metadata—assign camera (Hasselblad 500C/M shown), lens, film stock, developer, lab, location, and custom fields. Bulk upload 100+ images at once. Click any image to edit its metadata or view in lightbox. Handles large libraries with thousands of images through efficient server-side pagination.

### Translation Management

<img src="screenshot/Traduzione.jpg" alt="Translation Management" width="800">

Full i18n system with separate Frontend and Admin Panel scopes. Import/export translations as JSON. Search and filter by context. Inline editing—click any value to modify. Preset languages (English, Italian) or upload your own. Every text string in Cimaise is translatable.

### Built-in Analytics

<img src="screenshot/Analytics.jpg" alt="Analytics Dashboard" width="800">

Privacy-first analytics that keep data on your server. Sessions, page views, lightbox opens tracked over time. Date range filtering, comparison with previous periods. No Google required, no third-party tracking.

### Plugin System

<img src="screenshot/Plugin.jpg" alt="Plugins" width="800">

Extend functionality with plugins. One-click install from the plugins directory. Available plugins include Analytics Logger, Image Rating, and more. Each plugin shows version, author, and description.

### Social Sharing

<img src="screenshot/social share.jpg" alt="Social Sharing Settings" width="800">

Configure which social networks appear on album pages. Drag to reorder, toggle to enable/disable. Supports Bluesky, Facebook, Pinterest, Telegram, Threads, WhatsApp, X, DeviantArt, Behance, and more. Preview shows exactly how buttons will appear.

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

## Custom Templates with AI

**Create unique gallery layouts by describing them to an AI assistant.**

Cimaise includes a powerful Custom Templates plugin that lets you design completely original gallery, album page, and homepage templates. The innovation: you don't need to code. Just describe what you want to an AI assistant (Claude, ChatGPT, etc.), and it generates the template for you.

### How It Works

1. **Describe Your Vision** — Tell the AI what you want: "A polaroid-style gallery with scattered photos on a corkboard background" or "A minimalist grid with large whitespace and subtle hover animations"

2. **Include the Instructions** — Copy the provided LLM instruction guide and paste it with your description. The guide tells the AI exactly what variables are available, what HTML structure to use, and how to format the output

3. **Get Your Template** — The AI generates a complete template package with Twig template, CSS styles, and JavaScript if needed

4. **Install & Use** — Upload the ZIP to Admin → Templates → Custom Templates, and assign it to any album

### Available Template Types

| Type | What You Can Customize |
|------|------------------------|
| **Gallery Templates** | How photos display within albums (grid, masonry, carousel, etc.) |
| **Album Page Templates** | The entire album page including header, metadata, and photo grid |
| **Homepage Templates** | Complete homepage layouts with album showcases |

### Included LLM Guides

The plugin includes detailed instruction files for AI assistants:

```text
plugins/custom-templates-pro/guides/
├── en/
│   ├── gallery-template-guide.md      # Instructions for gallery templates
│   ├── album-page-guide.md   # Instructions for album page templates
│   └── homepage-guide.md     # Instructions for homepage templates
└── it/
    └── ... (Italian translations)
```

Each guide includes:
- Available Twig variables (album data, images, settings, translations)
- Required HTML structure and CSS classes
- PhotoSwipe lightbox integration patterns
- Responsive image handling with srcset
- Security requirements (XSS prevention, CSP compliance)

### Example Prompts

**Polaroid Gallery:**
> "Create a gallery template that displays photos as polaroid snapshots scattered on a wooden desk. Each photo should have a slight random rotation, a white border like a polaroid, and a handwritten-style caption below."

**Magazine Editorial:**
> "Design an album page template with a full-bleed hero image, large serif typography for the title, and a two-column text layout for the description. Photos should display in an asymmetric editorial grid."

**Minimal Portfolio:**
> "Build a homepage template with a single large featured image that changes on scroll, minimal navigation, and a dark background. The aesthetic should be high-end fashion photography."

### Why This Matters

Traditional CMS template creation requires:
- Learning a templating language (Twig, Blade, etc.)
- Understanding CSS frameworks
- JavaScript for interactivity
- Hours of trial and error

With Cimaise + AI:
- Describe in plain language
- Get working code in minutes
- Iterate by conversation: "Make the hover effect more subtle" or "Add a parallax effect"

**The included instruction guides ensure AI assistants generate templates that actually work**—with proper escaping, responsive images, and PhotoSwipe integration already handled.

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

### Smart Category Counts

Navigation menus show accurate album counts per category. Protected albums (NSFW or password-protected) are automatically excluded from these counts for non-authenticated visitors—no spoilers about hidden content.

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

### Demo Mode

**Showcase all features with an interactive template switcher.**

Perfect for demo sites and client presentations. Let visitors experience all home page templates without accessing admin.

- **Template Switcher** — Dropdown in header lets visitors switch between all 6 home templates
- **Live Preview** — Instant template switching without page reload
- **Demo Banner** — Shows demo credentials in admin panel
- **Password Protection** — Prevents users from changing admin password
- **24-Hour Auto-Reset** — Cron script resets demo to clean state daily
- **Sync Script** — `php bin/sync-demo.php` keeps demo in sync with main codebase

Perfect for: Live demos, client presentations, feature showcases.

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

## Performance & Caching

Cimaise is optimized for speed out of the box:

### HTTP Compression

All text-based responses are compressed automatically:

- **Brotli** — Modern compression (20-30% smaller than Gzip) for browsers that support it
- **Gzip** — Universal fallback for older browsers
- **Automatic Detection** — Server chooses the best compression based on `Accept-Encoding`

Compressed content types:
- HTML, CSS, JavaScript
- JSON, XML, SVG
- Web fonts (WOFF, WOFF2, TTF)

### Browser Caching

Smart cache headers maximize repeat-visit performance:

| Asset Type | Cache Duration | Strategy |
|------------|----------------|----------|
| Images (JPEG, WebP, AVIF) | 1 year | Immutable (versioned filenames) |
| CSS & JavaScript | 1 year | Immutable (Vite hashed builds) |
| Fonts | 1 year | Immutable |
| HTML pages | 5 minutes | Must-revalidate |
| JSON/XML | 1 hour | Must-revalidate |

**Result:** Returning visitors load pages instantly from browser cache.

### Progressive Web App (PWA)

Install Cimaise as an app on any device:

- **Service Worker** — Caches core assets for offline access
- **Web App Manifest** — Customizable theme colors and icons
- **Offline Page** — Graceful fallback when connection is lost
- **Add to Home Screen** — Works like a native app on mobile
- **Web Share API** — Native sharing on mobile devices (share images directly to other apps)
- **Wake Lock API** — Keeps screen awake during lightbox slideshows

PWA features are configured from Admin → Settings:
- Theme color (affects browser chrome and splash screen)
- Background color
- App name and short name

### First-Visit Preloader

Elegant loading animation for first-time visitors:

- **Smooth Reveal** — Animated preloader while assets load on first visit
- **Session Memory** — Preloader only shows once per session
- **Template-Specific** — Different animations per home template style
- **Instant Repeat Visits** — Cached assets mean no preloader on return

### SQLite WAL Mode

Optimized database performance with Write-Ahead Logging:

- **Concurrent Reads** — Multiple visitors can read while writes happen
- **Faster Writes** — Significantly improved write performance
- **Crash Recovery** — Better durability with automatic checkpointing
- **Auto-Enabled** — Configured by default for SQLite installations

### Resource Optimization

- **DNS Prefetch** — Pre-resolves external domains (Google Fonts, analytics)
- **Preconnect** — Establishes early connections to CDN origins
- **Critical CSS** — Above-fold styles load first
- **Deferred Scripts** — Non-critical JavaScript loads after page render
- **Image Priority** — First image loads with `fetchpriority="high"`

### LQIP (Low-Quality Image Placeholder)

Instant perceived image loading with progressive enhancement:

- **40x30px Placeholders** — Tiny images (~1-2KB) generated for all public album images
- **Base64 Data URIs** — Inlined directly in HTML, zero additional HTTP requests
- **Smooth Transitions** — Blur effect fades from placeholder to full-quality image
- **Security-First** — LQIP automatically skipped for NSFW and password-protected albums
- **Core Web Vitals** — Eliminates Cumulative Layout Shift (CLS 0.20 → 0.0)

**Performance Impact:**
- **Perceived Load Time**: 500ms → 0ms (instant)
- **LCP Improvement**: ~28% faster Largest Contentful Paint
- **Zero CLS**: Perfect layout stability with proper aspect ratios

LQIP are generated using ImageMagick or GD with light Gaussian blur. Images are stored as `lqip` variants in the database and automatically injected into home page templates.

**Generation:**
```bash
# Generate LQIP for all public images
php bin/console images:generate-lqip

# Or use the admin interface at /admin/cache
```

**Supported Templates:**
- Home Modern
- Home Masonry
- Home Parallax
- Home Gallery Wall
- Home Snap Albums

### Database Query Optimization

- **Batch Album Enrichment** — Equipment, categories, and tags loaded in single queries instead of N+1
- **Settings Cache** — Configuration values cached with automatic invalidation
- **Efficient Pagination** — Server-side pagination for media library and galleries

### JSON Page Caching

Full-page caching for instant responses:

- **Database Storage** — Cache stored in SQLite/MySQL with gzip compression (~70% smaller)
- **ETag Validation** — Automatic 304 Not Modified responses for unchanged content
- **Tag-based Invalidation** — Smart cache clearing when specific content changes
- **Lazy Regeneration** — Stale-while-revalidate pattern serves fast responses while refreshing in background
- **Auto-Warm on Save** — Optional automatic cache refresh after content updates
- **Post-Login Warmup** — Pre-warms critical caches after admin login

Cached pages include:
- Home page (all templates)
- Galleries listing with filters
- Individual album pages
- Navigation and settings

Manage from **Admin → System → Cache Management**:
- **Clear Page Cache** — Remove cached pages for fresh regeneration
- **Clear Query Cache** — Flush database query cache
- **Clear Twig Cache** — Clear compiled template cache
- **Clear ALL Caches** — One-click button to clear everything at once
- **Warm Caches** — Pre-generate caches for optimal performance
- **Auto-Warm** — Automatically refresh caches after content updates

**CLI Cache Management:**
```bash
# Clear all page caches (database backend)
sqlite3 database/database.sqlite "DELETE FROM page_cache;"

# Clear file-based cache (if using file backend)
rm -rf storage/cache/pages/

# Clear filter options cache
rm -f storage/cache/filter_options.cache
```

### Query Cache System

Cimaise includes a multi-layer query caching system for expensive database queries:

- **APCu Primary** — In-memory cache when APCu extension is available (fastest)
- **File Fallback** — Automatic fallback to file-based cache on shared hosting
- **Automatic Invalidation** — Cache cleared when albums, images, or settings change
- **Configurable TTL** — Per-query cache duration (navigation: 1 hour, home images: 5 minutes)

Cached queries include:
- Navigation categories and subcategories
- Home page image pools
- Album metadata and counts
- Settings and translations

### Twig Template Caching

Template compilation is cached for fast page rendering:

- **Compiled Templates** — Twig templates compiled to PHP and cached in `storage/cache/twig/`
- **Auto-Recompile** — Templates recompiled only when source files change
- **Warmup Scripts** — Pre-compile all templates during deployment:

```bash
# Warm up Twig template cache
php scripts/twig-cache-warmup.php

# Warm up all caches (templates + queries)
php scripts/cache-warmup.php
```

**Deployment Tip:** Run warmup scripts after deployment to eliminate first-request compilation delay.

### Installer Configuration

During installation, you can enable:
- **Cache System** — Browser and server-side caching
- **Compression** — Brotli/Gzip response compression

Both are enabled by default for optimal performance.

---

## Admin Experience

A dashboard that doesn't insult your intelligence:

- **Drag & Drop Everything** — Reorder albums, images, categories with intuitive dragging
- **Bulk Upload** — Drop 100+ images at once with parallel processing
- **Inline Editing** — Click any text to edit it. No page reloads
- **Real-Time Preview** — See exactly how your gallery will look before publishing
- **Visual Template Selector** — Preview home and gallery templates before applying
- **Equipment Browser** — Browse by camera, lens, film, or location
- **Mobile Responsive** — Full admin functionality on tablets and phones

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
php bin/console nsfw:generate-blur   # Generate blur variants for protected albums
php bin/console images:generate-lqip # Generate LQIP (Low-Quality Image Placeholders)
php bin/console maintenance:run      # Run daily maintenance (variants + blur)
php bin/console sitemap:generate     # Build XML sitemap
php bin/console analytics:cleanup    # Purge old analytics data
```

### LQIP Generation Options

The `images:generate-lqip` command generates tiny Low-Quality Image Placeholders (LQIP) for instant perceived loading. LQIP are 40x30px images (~1-2KB) that load instantly while the full-quality image loads in the background.

**Security Note:** LQIP are only generated for public albums. NSFW and password-protected albums are automatically skipped to prevent bypassing age gates or password protection.

```bash
# Generate LQIP for all public images that don't have one yet
php bin/console images:generate-lqip

# Force regeneration of all LQIP (including existing ones)
php bin/console images:generate-lqip --force

# Process only a specific album
php bin/console images:generate-lqip --album=42

# Limit processing to first N images
php bin/console images:generate-lqip --limit=100

# Dry run - show what would be processed without generating
php bin/console images:generate-lqip --dry-run
```

**Performance Impact:**
- Perceived load time: 500ms → 0ms (instant)
- Cumulative Layout Shift (CLS): 0.20 → 0.0 (perfect)
- Largest Contentful Paint (LCP): ~28% improvement
- Zero additional HTTP requests (inlined as base64 data URIs)

**Admin Interface:**
You can also trigger LQIP generation from the admin panel at `/admin/cache`:
- **Generate New LQIP**: Process only images without LQIP
- **Force Regenerate All**: Regenerate all LQIP variants

### Blur Generation Options

The `nsfw:generate-blur` command generates blurred preview images for protected albums:

```bash
# Generate blur for all NSFW and password-protected albums
php bin/console nsfw:generate-blur

# Process only NSFW albums
php bin/console nsfw:generate-blur --nsfw-only

# Process only password-protected albums
php bin/console nsfw:generate-blur --password-only

# Force regeneration of existing blur variants
php bin/console nsfw:generate-blur --force

# Process all images in albums (not just covers)
php bin/console nsfw:generate-blur --all

# Process a specific album
php bin/console nsfw:generate-blur --album=42
```

---

## Development Scripts

Scripts for development and testing workflows:

### Demo Data Seeder

Populate your installation with sample albums, categories, and images for testing:

```bash
# Run with confirmation prompt
php bin/dev/seed_demo_data.php

# Skip confirmation (for CI/automation)
php bin/dev/seed_demo_data.php --force
```

**What it creates:**
- Categories (Street, Portrait, Landscape, Film, etc.)
- Equipment (cameras, lenses, film stocks)
- Sample albums with images downloaded from Unsplash
- NSFW and password-protected albums for testing
- Draft albums for workflow testing

Images are stored in `/storage/originals/` (matching production upload behavior) and variants are automatically generated.

### Clean for Reinstallation

Reset the application to a fresh state for reinstallation:

```bash
# Interactive (asks for confirmation)
bash bin/dev/clean_for_reinstall.sh

# Non-interactive (for automation)
bash bin/dev/clean_for_reinstall.sh --force
```

**What it removes:**
- All image variants (`public/media/*`)
- All original files (`storage/originals/*`)
- Database (`database/database.sqlite`)
- Environment config (`.env`)
- Cache, logs, and temp files

After running, navigate to `/install` to set up a fresh installation.

### Demo Site Sync

Maintain a synchronized demo site for showcasing Cimaise:

```bash
# Full sync from main app to demo folder
php bin/sync-demo.php

# Preview changes without applying
php bin/sync-demo.php --dry-run
```

The sync script:
- Copies all application code to `/demo/` folder
- Applies demo-specific patches (template switcher, demo banner, password protection)
- Preserves demo-only files (database, media, configuration)
- Injects demo mode detection (`DEMO_MODE` constant)

Demo features:
- **Template Switcher** — Visitors can switch between all 6 home templates via dropdown
- **Demo Banner** — Shows demo credentials in admin panel
- **Password Change Block** — Prevents users from locking themselves out
- **24-Hour Reset** — Cron script resets demo to clean state daily

---

## Scheduled Maintenance (Cron)

Cimaise includes two CLI commands for scheduled tasks: **cache warming** for faster page loads, and **maintenance** for image variant generation. For best performance, schedule both via cron.

### Recommended Cron Setup

Add this to your crontab (`crontab -e`):

```bash
# Pre-generate page caches every 6 hours
0 0,6,12,18 * * * cd /path/to/cimaise && php bin/console cache:warm --quiet-mode

# Run maintenance daily at 3 AM
0 3 * * * cd /path/to/cimaise && php bin/console maintenance:run --quiet-mode

# Alternative: Combined schedule for high-traffic sites
0 */6 * * * cd /path/to/cimaise && php bin/console cache:warm --quiet-mode && php bin/console maintenance:run --quiet-mode --force
```

---

### Cache Warming (`cache:warm`)

Pre-generates JSON caches for all public pages, dramatically reducing load times for visitors.

#### What Gets Cached

- **Home page** — All images with srcsets, categories, and settings
- **Galleries page** — Album listings with filters and metadata
- **Individual albums** — Full album data with images, equipment info, and srcsets
  - Excludes NSFW and password-protected albums (served dynamically for security)

#### Cache Warming Options

```bash
# Warm all caches
php bin/console cache:warm

# Clear existing caches before warming
php bin/console cache:warm --clear

# Warm only specific caches
php bin/console cache:warm --home
php bin/console cache:warm --galleries
php bin/console cache:warm --albums

# Quiet mode for cron (no output)
php bin/console cache:warm --quiet-mode
```

#### Cache Invalidation

Caches are automatically invalidated when:
- Albums are created, updated, or deleted
- Images are added or removed from albums
- Categories or tags are modified
- Home page settings are changed

You can also manually clear caches from **Admin → System → Cache Management**.

#### Auto-Warm on Save

Enable **Auto-Warm** in Cache Management settings to automatically regenerate caches whenever content changes. This ensures visitors always see fresh data without waiting for the next scheduled cron run.

When enabled, the following caches are regenerated after each album save/delete:
- Home page cache
- Galleries listing cache
- The specific album cache (if applicable)

**Recommended for:** Sites with infrequent updates (< 10 updates/day).

#### Lazy Regeneration (Stale-While-Revalidate)

Even without auto-warm or scheduled crons, Cimaise uses **lazy regeneration** to serve fast responses:

1. When cache expires, the first visitor receives **stale data** (instant response)
2. Cache regenerates in the background after the response is sent
3. Subsequent visitors receive the fresh cache

This ensures pages never "feel slow" even when cache expires — visitors always get an instant response while fresh data is generated behind the scenes.

**Cache TTL:** 24 hours by default (configurable in settings).

---

### Maintenance (`maintenance:run`)

Generates missing image variants and blur previews for protected albums.

#### What Maintenance Does

1. **Image Variant Generation** — Creates missing responsive variants (AVIF, WebP, JPEG) for all uploaded images
2. **Blur Variant Generation** — Creates blurred preview images for:
   - NSFW albums (for age-gated content)
   - Password-protected albums (for locked content previews)

#### Maintenance Features

- **File-based locking** — Prevents concurrent execution if the cron runs while another is still processing
- **Date tracking** — Skips execution if already run today (unless `--force` is used)
- **Graceful failure** — Logs errors without blocking other operations

#### Maintenance Options

```bash
# Normal run (skips if already run today)
php bin/console maintenance:run

# Force run even if already run today
php bin/console maintenance:run --force

# Quiet mode for cron (no output)
php bin/console maintenance:run --quiet-mode
```

---

### When to Use Cron

| Scenario | Recommendation |
|----------|----------------|
| Small portfolio (<100 images) | Optional, automatic cache works fine |
| Large portfolio (1000+ images) | Use cron to pre-warm caches |
| High traffic site | Essential — run both commands during off-peak hours |
| Frequent uploads | Run `cache:warm` after content updates |

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
