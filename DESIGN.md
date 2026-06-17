# DESIGN.md — Cimaise Admin

Design tokens and rules for the **admin** (backend) of Cimaise. The public
frontend is **out of scope** and intentionally untouched (it keeps its own
magenta brand — see "Scoping" below).

Register: **product** (the design serves the tool; photography carries the color).

## Palette — "Mindful Moments"

Forest green accent + sage + amber on warm cream. Restrained: the green accent
is used sparingly (active nav, primary actions, focus rings, links); the rest is
warm neutral; **photography carries the real color**. No gradients.

## Scoping (admin only — IMPORTANT)

The green palette is **admin-scoped**. The public frontend still loads
`resources/app.css` (magenta `--color-*` tokens) and the shared
`tailwind.preset.js` (magenta `accent`, cool-gray `primary`). The admin layers
its own palette on top because it loads `admin-app.css` **after** `app.css`:

- `resources/admin-app.css` — `:root` / `html.dark` override the shared
  `--color-*` tokens with the green system (admin-only, loaded after app.css).
- `tailwind.admin.config.js` — `theme.extend.colors` overrides `accent` +
  `primary` to green/green-gray, and remaps the **stock** scales so no framework
  default survives: `blue`/`sky`/`indigo` → forest green, `red`/`rose` →
  terracotta danger.
- `tailwind.preset.js` — adds the shared, additive tokens: `serif`/`display`
  fontFamily (Playfair) and `sage`/`amber`/`cream`/`blush` colors. Its `accent`
  and `primary` stay magenta/cool-gray for the frontend.

Never move the green tokens into `resources/app.css` or the preset's
`accent`/`primary` — that would recolor the public site.

## Color tokens

### Accent — forest green `#1C322D`
- `#1C322D` (accent-500) — base; ≈12:1 on white, AAA for text + buttons
- `#16271F` (accent-600) — hover / pressed
- Dark-mode accent lifts to **sage** `#A2C2B3` so it stays visible on dark
- Soft accent (active nav bg): light `#E7EEE9` (sage tint), dark `#1F2A24`

### Secondary / tertiary
- Sage `#A2C2B3` — active fills, soft chips, supportive surfaces
- Amber `#EBB552` — scheduled/highlight states, warm accents
- Warm neutral blocks: cream `#F8F3EE`, blush `#F1CDBE`

### Neutrals — warm cream + green-gray
Light: bg `#F4EFE8`, surface `#FFFFFF`, subtle `#ECE5DB`, border `#E4DED5`,
text `#1C322D` / `#5D6F69` / `#7C8A85`.
Dark: bg `#0F140F`, surface `#161C16`, subtle `#1E261D`, border `#2B332A`,
text `#F1F3EF` / `#C4CDC4` / `#9AA79F`.

### Semantic
Success green; **danger = terracotta** `#9C3826` (the stock red `#DC2626` is
remapped, no jarring stock red in the admin); warning amber `#EBB552`.

## Typography
- **Inter** (self-hosted) for all UI, labels, data, body.
- **Playfair Display** (self-hosted) for the wordmark + page titles ONLY
  (`font-serif`, `#page-content h1`). Never for labels, buttons, table data.
- Weights 400/500/600/700. Hierarchy via size + weight.
- All fonts local (`/fonts/font-faces.css`); no CDN.

## Components
- **Cards / surfaces:** white, 1px `--color-border`, `border-radius: 8px`, soft
  shadow. No nested cards. No gradients.
- **Sidebar (editorial):** forest-green `#1C322D` panel, cream-muted nav text,
  two-line items (title + subtitle) with a 32px icon chip. Active: sage-tint
  fill + white text + **sage icon chip with dark-green icon**. Scoped via
  `#sidebar …` rules (ID specificity wins in light + dark).
- **Topbar:** white, Playfair wordmark.
- **Buttons:** primary = accent (green) bg + cream text; secondary = tertiary bg
  + primary text; danger = terracotta outline.
- **Login:** split — forest-green stage (Playfair wordmark) + form on white;
  Chrome autofill blue is neutralized to white/cream.
- **Focus:** 2px ring in `--color-focus-ring` (green); global override of the
  `@tailwindcss/forms` default blue ring.
- **TomSelect:** stock blue active-option + focus border remapped to sage/green
  (override in `admin-app.css`, `!important` — the static `/assets/admin.css`
  loads after).

## Motion
- 150–250ms, `ease`/`cubic-bezier(0.4,0,0.2,1)`. No layout-property animation.

## Bans
No gradients. No stock framework blue (mapped to green) or stock red (mapped to
terracotta). No `#000`/`#fff` as text/bg tokens. No side-stripe borders.
No display fonts in labels/buttons/data.

## Build
`resources/{app,admin-app}.css` → `npm run build` (Vite) → hashed
`public/assets/*.css` + manifest (`vite_asset()` resolves them). Clear
`storage/cache/twig/*` and the DB `page_cache` after template edits.
