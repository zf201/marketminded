/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './web/templates/**/*.templ',
    './web/static/js/**/*.js',
  ],
  theme: {
    extend: {
      colors: {
        sidebar: '#0a0a0f',
        surface: '#111118',
      },
    },
  },
  plugins: [],
}
