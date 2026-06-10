/**
 * Tailwind config for the ADMIN CSS bundle (resources/admin-app.css, imported
 * by resources/admin.js, via @config).
 *
 * The admin panel keeps loading app.css for the shared base layer (CSS
 * variables, element resets); this build supplies the admin-only utilities
 * and component classes that the frontend-purged app.css no longer contains.
 *
 * @type {import('tailwindcss').Config}
 */
const preset = require('./tailwind.preset.js');

module.exports = {
  presets: [preset],
  safelist: preset.safelist,
  content: [
    './app/Views/admin/**/*.twig',
    './app/Views/partials/**/*.twig',
    './plugins/**/*.{twig,php}',
    './resources/admin.js',
  ],
}
