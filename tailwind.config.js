/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./app/Views/**/*.twig",
    "./public/**/*.{js,html}",
    "./resources/**/*.{js,ts}",
  ],
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
        primary: {
          50: '#f8fafc',
          100: '#f1f5f9',
          200: '#e2e8f0',
          300: '#cbd5e1',
          400: '#94a3b8',
          500: '#64748b',
          600: '#475569',
          700: '#334155',
          800: '#1e293b',
          900: '#0f172a',
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