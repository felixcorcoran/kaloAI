# Guía de estilo de KaloAI

Toda la aplicación usa un mismo sistema visual. Para una pantalla nueva o un cambio, usa estas piezas en lugar de inventar estilos: así todo encaja y el modo oscuro funciona solo.

Las piezas están en `public/css/tailwind.src.css` (componentes `k-*`) y `views/templates/iconos.php` (iconos). Después de tocar clases, recompila con `npm run build:css` (la CI avisa si se olvida).

## Principios

- **Panel de datos moderno.** Inspirado en plantillas tipo «Prestige»: en oscuro, fondo casi negro (`#0c0c0e`) con un brillo verde azulado arriba y tarjetas grafito con borde fino; en claro, fondo gris muy claro y tarjetas blancas. Un único color de marca (verde azulado) para lo importante.
- **Cada pantalla informa.** Debajo del título, una fila de cifras (`k-stat-*` con `k-icon-tile` de color) que resume la sección; después el contenido y, si cabe, una columna lateral con contexto (cobertura, objetivos, consejos).
- **Botones en píldora.** La acción principal es blanca sobre oscuro (negra sobre claro); lo que usa la IA va con `k-btn-accent` (degradado verde azulado).
- **Un acento por dato.** Los macronutrientes tienen siempre el mismo color: proteína azul (`k-macro-p`), carbohidratos ámbar (`k-macro-c`), grasa rosa (`k-macro-f`).
- **Tipografía DM Sans** en toda la aplicación (`font-sans` y `font-display`). La portada mantiene Plus Jakarta Sans.
- **Iconos de línea** con `icono('nombre')`. Los emojis solo para el tipo de comida de una receta (`estiloTipoReceta()`).
- **Texto en frases normales**, no en mayúsculas. Las mayúsculas pequeñas se reservan a las etiquetas de momento del día y a los grupos de la barra lateral.

## Colores

Se definen como variables en `:root` (claro) y `.dark` (oscuro). Úsalas en vez de colores fijos cuando el componente no lo resuelva ya:

| Variable | Uso |
| --- | --- |
| `--k-bg` / `--k-surface` / `--k-surface-2` | fondo de página / tarjetas / zonas suaves dentro de una tarjeta |
| `--k-text` / `--k-text-2` / `--k-muted` | títulos y datos / texto normal / texto secundario |
| `--k-border` / `--k-border-strong` | bordes de tarjeta / bordes de campos |
| `--k-brand`, `--k-brand-soft`, `--k-brand-text` | color de marca, su fondo suave y su texto |
| `--k-danger`, `--k-warn-*`, `--k-ok-*` | errores, avisos y confirmaciones |
| `--k-prot`, `--k-carb`, `--k-fat` (+ `-soft`) | macronutrientes |
| `--k-indigo` | segundo color de las gráficas (barras de la semana, cintura, altas) |

Clases de ayuda: `k-strong` (color de título), `k-muted` (texto secundario), `k-subtle` (fondo suave), `k-link` (enlace dentro de un texto).

## Estructura de una pantalla

```php
<?php include_once __DIR__ . '/../templates/header.php'; ?>
<div class="k-container k-page">
    <div class="k-page-head">
        <div>
            <span class="k-pill"><?= icono('calendar') ?>Contexto</span>
            <h1 class="k-title mt-3">Título</h1>
            <p class="k-subtitle">Una frase que explique para qué sirve.</p>
        </div>
        <a href="…" class="k-btn k-btn-primary"><?= icono('plus') ?>Acción principal</a>
    </div>

    <!-- Cifras de la sección -->
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="k-card flex items-center gap-4 p-5">
            <span class="k-icon-tile k-tile-indigo"><?= icono('calendar') ?></span>
            <div><p class="k-stat-label">Comidas</p><p class="k-stat-value">19<span class="k-stat-unit">/ 35</span></p></div>
        </div>
        …
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="k-card lg:col-span-2">…</section>
        <aside class="space-y-6">…</aside>
    </div>
</div>
<?php include_once __DIR__ . '/../templates/footer.php'; ?>
```

- Formularios y lecturas largas: `k-container k-page max-w-4xl`.
- Subpáginas (detalle, editar…): enlace de vuelta con `k-back` encima del título.
- La navegación es una barra lateral fija en escritorio y una fila desplazable en móvil, y sale sola según el rol (`views/templates/header.php`): las secciones de trabajo del rol van en «Trabajo» y las de todo usuario en «Mi plan». Si añades una sección, va en `$seccionesUsuario` o en `$seccionesEquipo`.
- La pantalla de entrada y la de registro usan dos columnas: el formulario y `views/templates/auth_lateral.php` (vista previa decorativa).

## Componentes

| Necesito… | Clases |
| --- | --- |
| Tarjeta | `k-card` + `k-card-pad`, o `k-card-head` para una cabecera con borde inferior |
| Tarjeta que es un enlace | `k-card k-card-link` |
| Lista dentro de tarjeta | `<ul class="k-list">` (separadores automáticos) |
| Tabla | `<table class="k-table">` dentro de `k-card overflow-hidden` |
| Botón | `k-btn` + `k-btn-primary` / `k-btn-accent` (IA) / `k-btn-secondary` / `k-btn-ghost` / `k-btn-danger`; texto rojo sin fondo `k-btn-ghost k-btn-danger-text`; tamaños `k-btn-sm`, `k-btn-lg`; solo icono `k-btn-icon` (con `aria-label`) |
| Contexto sobre el título | `k-pill` con un icono (fecha, rol, sección…) |
| Campo | `k-label` + `k-input` (también en `select` y `textarea`) + `k-hint` |
| Buscador | `<label class="k-search"><?= icono('search') ?><input class="k-input"></label>` |
| Opciones tipo píldora | `<input type="radio" class="peer sr-only">` + `<span class="k-choice">` |
| Filtros | `<div class="k-seg">` con botones `aria-pressed="true|false"` |
| Etiqueta | `k-badge` (+ `k-badge-brand`, `-ok`, `-warn`, `-danger`) |
| Aviso | `k-alert k-alert-ok|error|warn|info` con un icono delante |
| Dato destacado | `k-stat-label` + `k-stat-value` + `k-stat-unit` |
| Variación de un dato | `k-delta` + `k-delta-ok` / `k-delta-warn`, con `<span class="k-delta-icon"><?= icono('trend-down') ?></span>` |
| Barra de progreso | `<div class="k-track"><div class="k-bar" style="width: 40%"></div></div>` (`k-bar-over` si se pasa) |
| Macronutriente | envoltorio `k-macro-p|c|f` y dentro `k-macro-dot`, `k-macro-bar` o `k-macro-chip` |
| Estado vacío | `k-empty` con `k-empty-icon`, una frase y una acción |
| Icono en recuadro | `k-icon-tile` (verde azulado) o con color: `k-tile-indigo`, `-orange`, `-rose`, `-amber`, `-blue`, `-danger` |
| Avatar con inicial | `k-avatar` |
| Gráfica | `<canvas>` dentro de un `div relative` con altura fija; se dibuja en `main.js` con Chart.js (`graficoPeso()`, `iniciarGraficoPanel()`, `iniciarGraficoAdmin()`), leyendo los colores de las variables CSS para que sirva en claro y oscuro |

## Modales

```html
<div id="miModal" class="k-modal" hidden role="dialog" aria-modal="true" aria-labelledby="tituloMiModal">
    <div class="k-modal-backdrop"></div>
    <div class="k-modal-panel max-w-sm">
        <h2 id="tituloMiModal" class="k-modal-title">¿Seguro?</h2>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="k-btn k-btn-secondary" data-cerrar-modal>Cancelar</button>
            <button type="button" class="k-btn k-btn-danger" data-confirmar>Eliminar</button>
        </div>
    </div>
</div>
```

En `public/js/main.js`: `abrirModal('miModal')`, `cerrarModal('miModal')` y `confirmar('miModal', () => enviarPost(url, campos))` para confirmaciones. Se cierran solos con Escape, al pulsar fuera o con cualquier `[data-cerrar-modal]`. Mientras hay uno abierto, el tabulador no sale de él, y al cerrarlo el foco vuelve al elemento que lo abrió.

El JavaScript nunca depende de clases de estilo: usa `id` y atributos `data-*`. Así se puede cambiar el diseño sin romper el comportamiento.

## Accesibilidad

- Todo botón que solo tenga icono lleva `aria-label`.
- Los campos llevan `<label for>`; si la etiqueta no debe verse, `sr-only`.
- Para ocultar, el atributo `hidden` (gana siempre a cualquier clase).
- Los colores de texto cumplen contraste en claro y oscuro; no pongas texto sobre `--k-brand-soft` con un color más claro que `--k-brand-text`.
- Cada gráfica (`<canvas>`) lleva `role="img"` y un `aria-label` que diga lo esencial («Evolución del peso, de 66,8 a 64,5 kg»), y debajo sus datos en tabla con `tablaDatos()` (`views/templates/tabla_datos.php`): `<?= tablaDatos('Peso por fecha', ['Fecha', 'Peso'], $filas) ?>`.
- Una página con gráficas pone `$usaGraficas = true` antes de incluir el pie: solo entonces se carga Chart.js.
