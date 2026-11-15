# Guía de estilo de KaloAI

Toda la aplicación usa un mismo sistema visual. Para una pantalla nueva o un cambio, usa estas piezas en lugar de inventar estilos: así todo encaja y el modo oscuro funciona solo.

Las piezas están en `public/css/tailwind.src.css` (componentes `k-*`) y `views/templates/iconos.php` (iconos). Después de tocar clases, recompila con `npm run build:css` (la CI avisa si se olvida).

## Principios

- **Sobrio y legible.** Fondo gris muy claro, tarjetas blancas con borde fino, un único color de marca (verde azulado) para lo importante.
- **Un acento por dato.** Los macronutrientes tienen siempre el mismo color: proteína azul (`k-macro-p`), carbohidratos ámbar (`k-macro-c`), grasa rosa (`k-macro-f`).
- **Titulares en Plus Jakarta Sans** (`font-display`), negrita normal, sin cursiva. Texto en Inter.
- **Iconos de línea** con `icono('nombre')`. Los emojis solo para el tipo de comida de una receta (`estiloTipoReceta()`).
- **Texto en frases normales**, no en mayúsculas. Las mayúsculas pequeñas se reservan a `k-eyebrow` y a las etiquetas de momento del día.

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

Clases de ayuda: `k-strong` (color de título), `k-muted` (texto secundario), `k-subtle` (fondo suave).

## Estructura de una pantalla

```php
<?php include_once __DIR__ . '/../templates/header.php'; ?>
<div class="k-container k-page">
    <div class="k-page-head">
        <div>
            <h1 class="k-title">Título</h1>
            <p class="k-subtitle">Una frase que explique para qué sirve.</p>
        </div>
        <a href="…" class="k-btn k-btn-primary"><?= icono('plus') ?>Acción principal</a>
    </div>

    <section class="k-card k-card-pad">…</section>
</div>
<?php include_once __DIR__ . '/../templates/footer.php'; ?>
```

- Formularios y lecturas largas: `k-container k-page max-w-4xl`.
- Subpáginas (detalle, editar…): enlace de vuelta con `k-back` encima del título.
- La navegación de la cabecera sale sola según el rol (`views/templates/header.php`); si añades una sección, va en `$seccionesUsuario`.

## Componentes

| Necesito… | Clases |
| --- | --- |
| Tarjeta | `k-card` + `k-card-pad`, o `k-card-head` para una cabecera con borde inferior |
| Tarjeta que es un enlace | `k-card k-card-link` |
| Lista dentro de tarjeta | `<ul class="k-list">` (separadores automáticos) |
| Tabla | `<table class="k-table">` dentro de `k-card overflow-hidden` |
| Botón | `k-btn` + `k-btn-primary` / `k-btn-secondary` / `k-btn-ghost` / `k-btn-danger`; tamaños `k-btn-sm`, `k-btn-lg`; solo icono `k-btn-icon` (con `aria-label`) |
| Campo | `k-label` + `k-input` (también en `select` y `textarea`) + `k-hint` |
| Buscador | `<label class="k-search"><?= icono('search') ?><input class="k-input"></label>` |
| Opciones tipo píldora | `<input type="radio" class="peer sr-only">` + `<span class="k-choice">` |
| Filtros | `<div class="k-seg">` con botones `aria-pressed="true|false"` |
| Etiqueta | `k-badge` (+ `k-badge-brand`, `-ok`, `-warn`, `-danger`) |
| Aviso | `k-alert k-alert-ok|error|warn|info` con un icono delante |
| Dato destacado | `k-stat-label` + `k-stat-value` + `k-stat-unit` |
| Barra de progreso | `<div class="k-track"><div class="k-bar" style="width: 40%"></div></div>` (`k-bar-over` si se pasa) |
| Macronutriente | envoltorio `k-macro-p|c|f` y dentro `k-macro-dot`, `k-macro-bar` o `k-macro-chip` |
| Estado vacío | `k-empty` con `k-empty-icon`, una frase y una acción |
| Icono en recuadro | `k-icon-tile` |

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

En `public/js/main.js`: `abrirModal('miModal')`, `cerrarModal('miModal')` y `confirmar('miModal', () => enviarPost(url, campos))` para confirmaciones. Se cierran solos con Escape, al pulsar fuera o con cualquier `[data-cerrar-modal]`.

El JavaScript nunca depende de clases de estilo: usa `id` y atributos `data-*`. Así se puede cambiar el diseño sin romper el comportamiento.

## Accesibilidad

- Todo botón que solo tenga icono lleva `aria-label`.
- Los campos llevan `<label for>`; si la etiqueta no debe verse, `sr-only`.
- Para ocultar, el atributo `hidden` (gana siempre a cualquier clase).
- Los colores de texto cumplen contraste en claro y oscuro; no pongas texto sobre `--k-brand-soft` con un color más claro que `--k-brand-text`.
