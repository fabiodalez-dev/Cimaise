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
      },
      colors: {
        // Clean cool gray (white + #E6E7EB family) — shared visual language
        // with the Pinakes/biblioteca admin so the two read as one suite.
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
        // Single accent: magenta #D70261 (Pinakes brand) for active nav,
        // primary actions, focus rings, links.
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
