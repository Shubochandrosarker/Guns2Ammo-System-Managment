/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        brand: {
          50:  '#fff5eb',
          100: '#ffe4c9',
          200: '#ffc48a',
          300: '#ffa04b',
          400: '#ff7f1a',
          500: '#e56410',
          600: '#b84c0b',
          700: '#8a3808',
          800: '#5c2506',
          900: '#2f1303',
        },
        ink: {
          50:  '#f7f8fa',
          100: '#eef0f4',
          200: '#dde1e8',
          300: '#c1c7d2',
          400: '#8b93a3',
          500: '#5b6272',
          600: '#3e4552',
          700: '#2a2f3a',
          800: '#171a22',
          900: '#0b0d13',
        },
      },
      fontFamily: {
        sans: [
          'Inter',
          'ui-sans-serif',
          'system-ui',
          '-apple-system',
          'Segoe UI',
          'Roboto',
          'sans-serif',
        ],
      },
      boxShadow: {
        card: '0 1px 2px rgba(0,0,0,0.04), 0 8px 24px rgba(15,23,42,0.06)',
      },
    },
  },
  plugins: [],
}
