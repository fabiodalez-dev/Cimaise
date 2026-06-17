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
  // ADMIN-ONLY palette override: forest green accent + warm green-gray neutrals
  // ("Mindful Moments" system). The public frontend keeps the preset defaults.
  theme: {
    extend: {
      colors: {
        primary: {
          50: '#f7f4ef',
          100: '#efeae2',
          200: '#e4ded5',
          300: '#cdc7bc',
          400: '#9aa79f',
          500: '#7c8a85',
          600: '#5d6f69',
          700: '#41534d',
          800: '#28352f',
          900: '#1c322d',
        },
        accent: {
          50: '#eef2f0',
          100: '#d7e2dc',
          200: '#b5c9c0',
          300: '#8aa89c',
          400: '#4d6b60',
          500: '#1c322d',
          600: '#16271f',
          700: '#112019',
          800: '#0c1813',
          900: '#08100c',
        },
        // Kill the "standard" framework blues — remap every blue/sky/indigo
        // utility to the forest-green accent so no stock blue survives.
        blue: {
          50: '#eef2f0', 100: '#d7e2dc', 200: '#b5c9c0', 300: '#8aa89c', 400: '#4d6b60',
          500: '#1c322d', 600: '#16271f', 700: '#112019', 800: '#0c1813', 900: '#08100c',
        },
        sky: {
          50: '#eef2f0', 100: '#d7e2dc', 200: '#b5c9c0', 300: '#8aa89c', 400: '#4d6b60',
          500: '#1c322d', 600: '#16271f', 700: '#112019', 800: '#0c1813', 900: '#08100c',
        },
        indigo: {
          50: '#eef2f0', 100: '#d7e2dc', 200: '#b5c9c0', 300: '#8aa89c', 400: '#4d6b60',
          500: '#1c322d', 600: '#16271f', 700: '#112019', 800: '#0c1813', 900: '#08100c',
        },
        // Danger stays meaningful but on-palette: warm terracotta instead of
        // the jarring stock Tailwind red. Used for destructive/error states.
        red: {
          50: '#fbeae6', 100: '#f6d2c8', 200: '#eaa996', 300: '#dd7d63', 400: '#c75940',
          500: '#b4452f', 600: '#9c3826', 700: '#7e2c1e', 800: '#65241a', 900: '#4f1d15',
        },
        rose: {
          50: '#fbeae6', 100: '#f6d2c8', 200: '#eaa996', 300: '#dd7d63', 400: '#c75940',
          500: '#b4452f', 600: '#9c3826', 700: '#7e2c1e', 800: '#65241a', 900: '#4f1d15',
        },
        // Success / positive states → sage family (on-palette, distinct from the
        // dark forest accent), instead of the jarring stock bright green.
        green: {
          50: '#eef3f0', 100: '#dde9e3', 200: '#c3d6cc', 300: '#a2c2b3', 400: '#84ab98',
          500: '#6b9080', 600: '#557667', 700: '#445f53', 800: '#33473e', 900: '#26352f',
        },
        emerald: {
          50: '#eef3f0', 100: '#dde9e3', 200: '#c3d6cc', 300: '#a2c2b3', 400: '#84ab98',
          500: '#6b9080', 600: '#557667', 700: '#445f53', 800: '#33473e', 900: '#26352f',
        },
        // Warnings → the system amber (no stock yellow).
        yellow: {
          50: '#fdf6e8', 100: '#f9e9c4', 200: '#f2d18a', 300: '#ecbe5f', 400: '#ebb552',
          500: '#d99a2f', 600: '#b97f1c', 700: '#8a5e0d', 800: '#6b4a0a', 900: '#4f3607',
        },
      },
    },
  },
  content: [
    './app/Views/admin/**/*.twig',
    './app/Views/partials/**/*.twig',
    './plugins/**/*.{twig,php}',
    './resources/admin.js',
  ],
}
