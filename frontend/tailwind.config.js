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
          600: '#278e63',
          700: '#236f4f',
          800: '#278e63',
          900: '#1a4a35',
          950: '#0d2a1f',
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
  plugins: [],
}