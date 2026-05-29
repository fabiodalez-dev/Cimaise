# Design System — MASTER (Cimaise Admin)

Global source of truth for the **admin** UI. Page-specific overrides go in
`design-system/pages/`. Frontend is out of scope. See also `/DESIGN.md`.

## Identity
Photography CMS admin, sibling of **Pinakes/biblioteca** (shared suite look).
Pattern: clean dashboard. Style: minimal, white + cool gray, single magenta
accent. No gradients. Inter (self-hosted).

## Colors

| Role | Light | Dark |
|------|-------|------|
| App background | `#F6F7F9` | `#0F1115` |
| Surface (cards/sidebar) | `#FFFFFF` | `#171A1F` |
| Subtle / hover | `#E6E7EB` | `#1F242B` |
| Border | `#E6E7EB` | `#2A2F37` |
| Text primary | `#111827` | `#F3F4F6` |
| Text secondary | `#4B5563` | `#C2C7D0` |
| Text tertiary | `#6B7280` | `#9AA1AD` |
| **Accent** | `#D70261` | `#EC1F76` |
| Accent hover | `#B80254` | `#F0357F` |
| Accent soft (bg) | `#FDE8F1` | `#2A0D1B` |

Tailwind scales: `primary` (cool gray 50–900), `accent` (magenta 50–900).
CSS vars: `--color-bg-*`, `--color-text-*`, `--color-border*`, `--color-accent*`,
`--color-focus-ring` in `resources/app.css`.

## Typography
Inter (400/500/600/700), self-hosted. Title + subtitle pattern in nav rows.

## Effects
- Radius: 8px (cards, nav rows, chips, buttons).
- Shadow: soft `0 1px 3px rgba(0,0,0,.1)` light; deeper in dark.
- Transitions: 150–200ms ease. No gradients, no glassmorphism.

## Key components
- **Sidebar nav:** card-rows (fill + border), 14px/16px padding, two-line
  (title + subtitle), 32px icon chip, uniform `space-y-2`. Active = soft-magenta.
- **Stat/feature cards:** white, bordered, icon chip, no gradient.
- **Buttons:** primary magenta, secondary gray, danger outline red.
- **Inputs:** magenta focus ring (global override of forms-plugin blue).

## Anti-patterns
Gradients, blue accents, pure #000 text, nested cards, side-stripe borders.
