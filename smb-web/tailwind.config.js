/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,ts,tsx}'],
  theme: {
    extend: {
      colors: {
        ink: '#12243a', muted: '#526174', line: '#e3e9f0', canvas: '#f5f7fa',
        navy: '#102541', ocean: '#1e5a8a', teal: '#0f766e', mint: '#e5f4f1', amber: '#8a4b05', rose: '#a53d49',
      },
      boxShadow: {
        soft: '0 16px 48px rgba(17, 39, 65, 0.08)',
        card: '0 4px 18px rgba(23, 47, 73, 0.045)',
      },
      borderRadius: { '2xl': '1.1rem' },
    },
  },
  plugins: [],
}
