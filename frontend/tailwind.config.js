/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./src/**/*.{js,jsx,ts,tsx}",
  ],
  theme: {
    extend: {
      fontFamily: {
        'orbitron': ['Orbitron', 'sans-serif'],
      },
      colors: {
        forest: {
          50: '#f0fdf9',
          100: '#d1f5e8',
          200: '#a3ebd1',
          300: '#6edeb5',
          400: '#3fcb9a',
          500: '#2bb382',
          600: '#12443e',
          700: '#236f4f',
          800: '#12443e',
          900: '#1a4a35',
          950: '#0d2a1f',
        },
        // Dynamic brand colors - reference CSS custom properties
        brand: {
          primary: 'var(--color-primary)',
          'primary-hover': 'var(--color-primary-hover)',
          'primary-dark': 'var(--color-primary-dark)',
          secondary: 'var(--color-secondary)',
          'secondary-hover': 'var(--color-secondary-hover)',
          accent: 'var(--color-accent)',
          light: 'var(--color-light)',
          muted: 'var(--color-muted)',
        },
      },
      borderRadius: {
        'none': '0',
        'sm': '0.25rem',    // 4px
        DEFAULT: '0.25rem', // 4px
        'md': '0.25rem',    // 4px
        'lg': '0.5rem',     // 8px
        'xl': '0.75rem',    // 12px
      }
    },
  },
  plugins: [
    require('@tailwindcss/typography'),
  ],
}