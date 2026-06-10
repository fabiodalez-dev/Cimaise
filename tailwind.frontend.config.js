/**
 * Tailwind config for the FRONTEND CSS bundle (resources/app.css via @config).
 *
 * Content is limited to the templates/scripts that render visitor-facing
 * pages, so admin-only utilities no longer bloat the public app.css.
 * Plugins are included because custom-templates-pro (and friends) render
 * frontend output; the installer is included because public/installer.php
 * links assets/app.css directly.
 *
 * @type {import('tailwindcss').Config}
 */
const preset = require('./tailwind.preset.js');

module.exports = {
  presets: [preset],
  safelist: preset.safelist,
  content: [
    './app/Views/frontend/**/*.twig',
    './app/Views/errors/**/*.twig',
    './app/Views/installer/**/*.twig',
    './app/Views/partials/**/*.twig',
    './plugins/**/*.{twig,php}',
    './resources/js/**/*.{js,ts}',
    './public/assets/js/**/*.js',
    './public/installer.php',
  ],
}
