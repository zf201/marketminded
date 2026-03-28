/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './web/templates/**/*.templ',
    './web/static/js/**/*.js',
  ],
  theme: {
    extend: {},
  },
  plugins: [require('daisyui')],
  daisyui: {
    themes: ['business'],
  },
}
