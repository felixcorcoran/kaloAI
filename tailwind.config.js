/**
 * Tailwind de KaloAI. Busca las clases en las vistas PHP y en main.js y genera
 * solo las que se usan en public/css/tailwind.css (npm run build:css).
 * Antes se cargaba desde cdn.tailwindcss.com, que compila en el navegador en
 * cada visita y su propia documentación desaconseja usar en producción.
 */
module.exports = {
  // El modo oscuro se activa con la clase .dark en <html> (views/templates/tema.php)
  darkMode: 'class',
  content: [
    './index.php',
    './views/**/*.php',
    './public/**/*.php',
    './public/js/**/*.js',
  ],
  // main.js construye estas clases con plantillas (`text-${m.color}-600`),
  // así que no aparecen enteras en el código
  safelist: [],
  theme: {
    extend: {
      fontFamily: {
        // Inter como fuente base y Plus Jakarta Sans para titulares (font-display)
        sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'sans-serif'],
        display: ['"Plus Jakarta Sans"', 'Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
    },
  },
};
