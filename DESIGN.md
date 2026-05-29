# DESIGN.md — Cimaise Admin

Design tokens and rules for the **admin** (backend) of Cimaise. The public
frontend is out of scope here. The admin shares its visual language with the
sibling project **Pinakes/biblioteca** so the two read as one suite.

Register: **product** (the design serves the tool; photography carries the color).

## Strategy

Restrained: clean white + cool light-gray surfaces, a single magenta accent used
sparingly (active nav, primary actions, focus rings, links). **No gradients.**
Inter (self-hosted) for all text. Light + dark via the `html.dark` class.

## Color tokens

Source of truth: CSS variables in `resources/app.css` (`:root` / `html.dark`),
mirrored as Tailwind scales `primary` (cool gray) and `accent` (magenta) in
`tailwind.config.js`.

### Accent — magenta (Pinakes brand)
- `#D70261` (accent-500) — base; ≈5.1:1 on white, AA-safe for text and buttons
- `#B80254` (accent-600) — hover / pressed
- Dark mode accent: `#EC1F76`
- Soft accent (active nav bg, badges): light `#FDE8F1`, dark `#2A0D1B`

### Neutrals — white + cool gray (`#E6E7EB` family)
Light: bg `#F6F7F9`, surface `#FFFFFF`, subtle `#E6E7EB`, border `#E6E7EB`,
text `#111827` / `#4B5563` / `#6B7280`.
Dark: bg `#0F1115`, surface `#171A1F`, subtle `#1F242B`, border `#2A2F37`,
text `#F3F4F6` / `#C2C7D0` / `#9AA1AD`.

### Semantic (unchanged)
Success green, danger `#DC2626`, warning yellow — kept distinct from the accent.

## Typography
- Inter (self-hosted, `public/fonts/inter/*.woff2`). No new font families.
- Weights 400/500/600/700. Hierarchy via size + weight.

## Components
- **Cards / surfaces:** white, 1px `--color-border`, `border-radius: 8px`, soft
  shadow. No nested cards. No gradients.
- **Sidebar nav (Pinakes-style):** each item is a card-row — `padding: 14px 16px`,
  `border-radius: 8px`, subtle fill + 1px border, **two lines** (title + subtitle),
  icon inside a 32px rounded chip. Uniform 8px gap (`space-y-2`, no per-item margin).
  - Active: soft-magenta bg + magenta border + magenta text/icon + white icon chip.
  - Hover: tertiary bg, white icon chip with magenta icon.
- **Buttons:** primary = accent bg + white text (`--color-accent`/`-hover`);
  secondary = tertiary bg + primary text; danger = outline red.
- **Focus:** 2px ring in `--color-focus-ring` (magenta); global override of the
  `@tailwindcss/forms` default blue ring on every input.

## Motion
- 150–200ms, `ease`/`cubic-bezier(0.4,0,0.2,1)`. No layout-property animation.

## Bans
No gradients. No `#000`/`#fff` as text/bg tokens (use the neutral scale; pure
white is allowed only for card surfaces per product brief). No blue accents
(mapped to magenta). No side-stripe borders.

## Build
`resources/app.css` → `npm run build` (Vite) → `public/assets/app-*.css`, then
the non-hashed `public/assets/app.css` (loaded by `app/Views/admin/_layout.twig`)
is synced from the hashed build. Clear `storage/cache/twig/*` after template edits.
