/**
 * Shared Tailwind preset for Cimaise.
 *
 * Holds the theme, plugins and safelist that are common to every Tailwind
 * build (frontend, admin, legacy default). The per-target configs
 * (tailwind.frontend.config.js / tailwind.admin.config.js) only differ in
 * their `content` globs so each bundle is purged against the templates that
 * actually load it.
 *
 * @type {import('tailwindcss').Config}
 */
module.exports = {
  darkMode: 'class',
  // Safelist essential responsive utilities that must always be included
  safelist: [
    'hidden',
    'block',
    'flex',
    'inline-flex',
    'sm:hidden',
    'sm:block',
    'sm:flex',
    'sm:inline-flex',
    'md:hidden',
    'md:block',
    'md:flex',
    'md:inline-flex',
    'lg:hidden',
    'lg:block',
    'lg:flex',
    'lg:inline-flex',
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'],
        // Editorial display face (self-hosted) for the wordmark, page titles
        // and feature numbers. Never used for labels/data.
        serif: ['Playfair Display', 'Georgia', 'serif'],
        display: ['Playfair Display', 'Georgia', 'serif'],
      },
      colors: {
        // Clean cool gray (white + #E6E7EB family) — frontend default.
        primary: {
          50: '#f9fafb',
          100: '#f3f4f6',
          200: '#e6e7eb',
          300: '#d1d5db',
          400: '#9ca3af',
          500: '#6b7280',
          600: '#4b5563',
          700: '#374151',
          800: '#1f2937',
          900: '#111827',
        },
        // Accent: magenta #D70261 (frontend default; the admin overrides this
        // to forest green in tailwind.admin.config.js).
        accent: {
          50: '#fde8f1',
          100: '#fbc9de',
          200: '#f59ec5',
          300: '#ee6ca5',
          400: '#e23a82',
          500: '#d70261',
          600: '#b80254',
          700: '#970146',
          800: '#770138',
          900: '#54012a',
        },
        // Secondary — sage. Active fills, soft chips, supportive surfaces.
        sage: {
          50: '#eef3f0',
          100: '#dde9e3',
          200: '#c3d6cc',
          300: '#a2c2b3',
          400: '#84ab98',
          500: '#6b9080',
          600: '#557667',
          700: '#445f53',
        },
        // Tertiary — amber. Scheduled/highlight states, warm accents.
        amber: {
          50: '#fdf6e8',
          100: '#f9e9c4',
          200: '#f2d18a',
          300: '#ecbe5f',
          400: '#ebb552',
          500: '#d99a2f',
          600: '#b97f1c',
          700: '#8a5e0d',
        },
        // Warm neutral blocks.
        cream: '#f8f3ee',
        blush: '#f1cdbe',
      },
      animation: {
        'fade-in': 'fadeIn 0.5s ease-in-out',
        'slide-up': 'slideUp 0.3s ease-out',
        'slide-down': 'slideDown 0.3s ease-out',
      },
      keyframes: {
        fadeIn: {
          '0%': { opacity: '0' },
          '100%': { opacity: '1' },
        },
        slideUp: {
          '0%': { transform: 'translateY(20px)', opacity: '0' },
          '100%': { transform: 'translateY(0)', opacity: '1' },
        },
        slideDown: {
          '0%': { transform: 'translateY(-20px)', opacity: '0' },
          '100%': { transform: 'translateY(0)', opacity: '1' },
        },
      },
    },
  },
  plugins: [
    require('@tailwindcss/forms'),
  ],
}
