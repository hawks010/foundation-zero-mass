/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './assets/admin-app.js',
    './src/admin-app.css',
  ],
  theme: {
    extend: {
      colors: {
        inkfire: {
          deep: '#0f172a',
          teal: '#138170',
          mint: '#32b190',
          peach: '#facabb',
          ember: '#f18e5c',
        },
      },
      boxShadow: {
        panel: '0 18px 48px rgba(15, 23, 42, 0.08)',
      },
      borderRadius: {
        '4xl': '2rem',
      },
    },
  },
  corePlugins: {
    preflight: false,
  },
  plugins: [],
};
