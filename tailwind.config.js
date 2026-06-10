/**
 * Default/legacy Tailwind config.
 *
 * Kept as the fallback for any CSS that does not declare its own `@config`.
 * The real builds use:
 *   - tailwind.frontend.config.js → resources/app.css (frontend bundle)
 *   - tailwind.admin.config.js   → resources/admin-app.css (admin bundle)
 * Shared theme/safelist/plugins live in tailwind.preset.js.
 *
 * @type {import('tailwindcss').Config}
 */
const preset = require('./tailwind.preset.js');

module.exports = {
  presets: [preset],
  safelist: preset.safelist,
  content: [
    "./app/Views/**/*.twig",
    "./public/**/*.{js,html}",
    "./resources/**/*.{js,ts}",
  ],
}
