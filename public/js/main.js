/**
 * KaloAI · JavaScript de la web
 *
 * No depende de clases de estilo: los elementos se buscan por id o por
 * atributos data-*, así que cambiar el diseño no rompe el comportamiento.
 *
 *  1. Seguridad: token CSRF y envíos por POST
 *  2. Modales (abrirModal / cerrarModal)
 *  3. Plan semanal
 *  4. Despensa (inventario)
 *  5. Recetas
 *  6. Administración y nutricionista
 *  7. Perfil: restricciones alimentarias
 *  8. Progreso y gráficos
 *  9. Portada: carrusel de testimonios
 */

/* ==========================================================================
   1. SEGURIDAD
   ========================================================================== */

/** Token CSRF que la cabecera deja en <meta name="csrf-token">. */
function tokenCsrf() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
}

/**
 * Envía una acción por POST con el token CSRF, como un formulario normal.
 * Las acciones que cambian datos nunca van en un enlace GET: otra web
 * podría dispararlas con una simple imagen.
 */
function enviarPost(url, campos = {}) {
    const formulario = document.createElement('form');
    formulario.method = 'POST';
    formulario.action = url;
    formulario.hidden = true;
    Object.entries({ ...campos, csrf_token: tokenCsrf() }).forEach(([nombre, valor]) => {
        const campo = document.createElement('input');
        campo.type = 'hidden';
        campo.name = nombre;
        campo.value = valor;
        formulario.appendChild(campo);
    });
    document.body.appendChild(formulario);
    formulario.submit();
}

/** Escapa texto antes de meterlo en innerHTML. */
function escaparHtml(texto) {
    const div = document.createElement('div');
    div.textContent = texto == null ? '' : String(texto);
    return div.innerHTML;
}

/* ==========================================================================
   2. MODALES
   Estructura: <div class="k-modal" id="..." hidden> con un .k-modal-backdrop
   y un .k-modal-panel. Se cierran con [data-cerrar-modal], al pulsar fuera
   o con Escape.
   ========================================================================== */

function abrirModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.hidden = false;
    document.body.classList.add('overflow-hidden');
    const foco = modal.querySelector('[data-autofocus]') || modal.querySelector('input:not([type=hidden]), select, textarea, button');
    if (foco) setTimeout(() => foco.focus(), 30);
}

function cerrarModal(id) {
    const modal = typeof id === 'string' ? document.getElementById(id) : id;
    if (!modal) return;
    modal.hidden = true;
    if (!document.querySelector('.k-modal:not([hidden])')) {
        document.body.classList.remove('overflow-hidden');
    }
}

document.addEventListener('click', (evento) => {
    const cerrar = evento.target.closest('[data-cerrar-modal]');
    if (cerrar) cerrarModal(cerrar.closest('.k-modal'));
    if (evento.target.classList.contains('k-modal-backdrop')) cerrarModal(evento.target.closest('.k-modal'));
});

document.addEventListener('keydown', (evento) => {
    if (evento.key !== 'Escape') return;
    const abiertos = document.querySelectorAll('.k-modal:not([hidden])');
    if (abiertos.length) cerrarModal(abiertos[abiertos.length - 1]);
});

/** Modal de confirmación genérico: guarda la acción y la ejecuta al confirmar. */
function confirmar(idModal, accion) {
    const modal = document.getElementById(idModal);
    if (!modal) return;
    const boton = modal.querySelector('[data-confirmar]');
    if (boton) boton.onclick = () => { boton.disabled = true; accion(); };
    abrirModal(idModal);
}

/* ==========================================================================
   3. PLAN SEMANAL
   ========================================================================== */

/** Pinta tres fichas de macros (proteína, carbohidratos, grasa) en un contenedor. */
function pintarMacros(contenedor, p, c, g, kcal) {
    const ficha = (clase, etiqueta, valor, unidad) => `
        <div class="k-macro-chip ${clase}">
            <p class="k-stat-label">${etiqueta}</p>
            <p class="font-display text-lg font-bold k-strong tabular-nums">${valor}<span class="k-stat-unit">${unidad}</span></p>
        </div>`;
    contenedor.innerHTML =
        (kcal !== undefined ? ficha('k-subtle', 'Calorías', Math.round(kcal), 'kcal') : '') +
        ficha('k-macro-p', 'Proteína', Math.round(p), 'g') +
        ficha('k-macro-c', 'Carbohidratos', Math.round(c), 'g') +
        ficha('k-macro-f', 'Grasa', Math.round(g), 'g');
}

/** Detalle de una comida del plan. */
function abrirReceta(r) {
    if (!document.getElementById('modalReceta')) return;
    document.getElementById('mTit').textContent = r.titulo;
    document.getElementById('mMom').textContent = r.momento_comida;
    document.getElementById('mDesc').textContent = r.descripcion || 'Sin instrucciones.';
    document.getElementById('mVer').href = 'detalle_receta.php?id=' + encodeURIComponent(r.id_receta);
    pintarMacros(document.getElementById('mMacros'), r.proteinas_g_por_racion, r.carbohidratos_g_por_racion, r.grasas_g_por_racion, r.calorias_por_racion);
    abrirModal('modalReceta');
}

/** Resumen de un día frente a los objetivos. */
function abrirResumenDia(nombre, totales) {
    if (!document.getElementById('modalDia') || !window.planData) return;
    const meta = window.planData.meta;
    document.getElementById('mDiaTit').textContent = nombre;
    document.getElementById('mDiaKcalText').textContent = `${Math.round(totales.kcal)} de ${Math.round(meta.kcal)} kcal`;

    const barra = document.getElementById('mDiaKcalBar');
    barra.style.width = Math.min((totales.kcal / meta.kcal) * 100, 100) + '%';
    barra.classList.toggle('k-bar-over', totales.kcal > meta.kcal * 1.1);

    const filas = [
        ['k-macro-p', 'Proteína', totales.p, meta.p],
        ['k-macro-c', 'Carbohidratos', totales.c, meta.c],
        ['k-macro-f', 'Grasa', totales.g, meta.g],
    ];
    document.getElementById('mDiaMacrosList').innerHTML = filas.map(([clase, etiqueta, actual, objetivo]) => `
        <div class="${clase}">
            <div class="mb-1.5 flex items-center justify-between text-sm">
                <span class="flex items-center gap-2 k-strong"><span class="k-macro-dot"></span>${etiqueta}</span>
                <span class="tabular-nums k-muted">${Math.round(actual)} / ${Math.round(objetivo)} g</span>
            </div>
            <div class="k-track"><div class="k-bar k-macro-bar" style="width:${Math.min((actual / objetivo) * 100, 100)}%"></div></div>
        </div>`).join('');
    abrirModal('modalDia');
}

/** Quitar una comida del plan. */
function confirmarEliminarComida(id) {
    confirmar('modalEliminar', () => enviarPost('planificacion_semanal.php', { eliminar_id: id }));
}

/* ==========================================================================
   4. DESPENSA
   ========================================================================== */

function abrirModalInventario(modo, datos = {}) {
    if (!document.getElementById('itemModal')) return;
    const editar = modo === 'edit';
    document.getElementById('modal-title').textContent = editar ? 'Editar ingrediente' : 'Añadir ingrediente';
    document.getElementById('actionField').value = editar ? 'edit' : 'add';
    document.getElementById('idInventarioField').value = editar ? datos.id_inventario : '';
    document.getElementById('nombre_ingrediente').value = editar ? datos.nombre_ingrediente : '';
    document.getElementById('cantidad').value = editar ? parseFloat(datos.cantidad) : '';
    document.getElementById('unidad').value = editar ? datos.unidad : '';
    document.getElementById('submitButton').textContent = editar ? 'Guardar cambios' : 'Añadir';
    abrirModal('itemModal');
}

function abrirModalEliminarInventario(id, nombre) {
    const visor = document.getElementById('deleteItemName');
    if (visor) visor.textContent = nombre;
    confirmar('modalBorrarIngrediente', () => enviarPost('inventario.php', { action: 'delete', id_inventario: id }));
}

/* ==========================================================================
   5. RECETAS
   ========================================================================== */

window.filtroRecetaActual = 'todos';

/** Filtro por tipo de comida (botones con data-filtro). */
function filtrarPor(tipo) {
    window.filtroRecetaActual = tipo;
    document.querySelectorAll('[data-filtro]').forEach(boton => {
        boton.setAttribute('aria-pressed', boton.dataset.filtro === tipo ? 'true' : 'false');
    });
    aplicarFiltrosRecetas();
}

/** Búsqueda por título + filtro por tipo. */
function aplicarFiltrosRecetas() {
    const tarjetas = document.querySelectorAll('.recipe-card');
    if (!tarjetas.length) return;
    const termino = (document.getElementById('recipeSearch')?.value || '').toLowerCase().trim();
    let visibles = 0;
    tarjetas.forEach(tarjeta => {
        const coincide = (window.filtroRecetaActual === 'todos' || tarjeta.dataset.tipo === window.filtroRecetaActual)
            && tarjeta.dataset.titulo.includes(termino);
        tarjeta.hidden = !coincide;
        if (coincide) visibles++;
    });
    const sinResultados = document.getElementById('noResults');
    if (sinResultados) sinResultados.hidden = visibles > 0;
}

function abrirModalEliminar(id) {
    confirmar('modalBorrarReceta', () => enviarPost('../../core/borrar_receta.php', { id: id }));
}

/* ==========================================================================
   6. ADMINISTRACIÓN Y NUTRICIONISTA
   ========================================================================== */

function llamarApiAdmin(campos, mensajeError) {
    const datos = new FormData();
    Object.entries(campos).forEach(([clave, valor]) => datos.append(clave, valor));
    fetch('api_admin.php', { method: 'POST', body: datos, headers: { 'X-CSRF-Token': tokenCsrf() } })
        .then(respuesta => respuesta.json())
        .then(json => {
            if (json.success) window.location.reload();
            else alert(json.error || mensajeError);
        })
        .catch(() => alert(mensajeError));
}

function actualizarCampoUsuario(idUsuario, tipo, valor) {
    llamarApiAdmin({ user_id: idUsuario, action: tipo, value: valor }, 'No se pudo guardar el cambio.');
}

function alternarEstadoUsuario(idUsuario, _rol, nuevoValor) {
    llamarApiAdmin({ user_id: idUsuario, action: 'toggle_status', value: nuevoValor }, 'No se pudo cambiar el estado.');
}

function abrirModalEliminarAdmin(id, nombre) {
    const visor = document.getElementById('borrarUsuarioNombre');
    if (visor && nombre) visor.textContent = nombre;
    confirmar('modalBorrarUsuario', () => enviarPost('borrar_usuario.php', { id: id }));
}

/** Filtra filas por texto (data-buscar) y, si hay selector, por rol (data-rol). */
function prepararFiltroDeFilas(idBuscador, selectorFilas, idSinResultados, idSelectorRol) {
    const buscador = document.getElementById(idBuscador);
    if (!buscador) return;
    const selectorRol = idSelectorRol ? document.getElementById(idSelectorRol) : null;
    const filtrar = () => {
        const termino = buscador.value.toLowerCase().trim();
        const rol = selectorRol ? selectorRol.value : 'all';
        let visibles = 0;
        document.querySelectorAll(selectorFilas).forEach(fila => {
            const coincide = (fila.dataset.buscar || '').includes(termino) && (rol === 'all' || fila.dataset.rol === rol);
            fila.hidden = !coincide;
            if (coincide) visibles++;
        });
        const vacio = document.getElementById(idSinResultados);
        if (vacio) vacio.hidden = visibles > 0;
    };
    buscador.addEventListener('input', filtrar);
    if (selectorRol) selectorRol.addEventListener('change', filtrar);
}

/* ==========================================================================
   INICIALIZACIÓN
   ========================================================================== */

document.addEventListener('DOMContentLoaded', () => {
    // Recetas: buscador
    document.getElementById('recipeSearch')?.addEventListener('input', aplicarFiltrosRecetas);

    // Listados con buscador
    prepararFiltroDeFilas('realTimeSearch', '.user-row', 'sinUsuarios', 'roleFilter');
    prepararFiltroDeFilas('buscadorPaciente', '.fila-paciente', 'noResultados');

    // Generador con IA: pantalla de espera mientras responde la API
    const formularioIA = document.getElementById('formGeneradorIA');
    if (formularioIA) {
        const frases = ['Revisando tu despensa…', 'Teniendo en cuenta tus alergias…', 'Ajustando los macros…', 'Casi lista…'];
        formularioIA.addEventListener('submit', (evento) => {
            if (evento.submitter && evento.submitter.name !== 'generar') return;
            const capa = document.getElementById('loadingOverlay');
            const mensaje = document.getElementById('loadingMessage');
            if (capa) capa.hidden = false;
            let i = 0;
            setInterval(() => { if (mensaje) mensaje.textContent = frases[i++ % frases.length]; }, 2200);
        });
    }

    iniciarRestricciones();
    iniciarProgreso();
    iniciarGraficoPanel();
    iniciarCarrusel();
});

/* ==========================================================================
   7. PERFIL: RESTRICCIONES ALIMENTARIAS
   ========================================================================== */

function iniciarRestricciones() {
    const lista = document.getElementById('restrictionsList');
    const campoJson = document.getElementById('restricciones_json');
    const boton = document.getElementById('agregarRestriccionBtn');
    const entrada = document.getElementById('nuevo_ingrediente');
    const selector = document.getElementById('new_tipo');
    const aviso = document.getElementById('restriccionAviso');
    if (!lista || !campoJson || !boton) return;

    let restricciones = (window.profileData && window.profileData.currentRestrictions) || [];
    const tipos = (window.profileData && window.profileData.tipoMap) || {};
    const claseTipo = { alergia: 'k-badge-danger', intolerancia: 'k-badge-warn' };

    const avisar = (texto) => {
        if (!aviso) return;
        aviso.textContent = texto;
        aviso.hidden = !texto;
    };

    const pintar = () => {
        campoJson.value = JSON.stringify(restricciones);
        if (!restricciones.length) {
            lista.innerHTML = '<li class="py-3 text-sm k-muted">Aún no has añadido ninguna.</li>';
            return;
        }
        lista.innerHTML = '';
        restricciones.forEach((r, indice) => {
            const fila = document.createElement('li');
            fila.className = 'flex items-center justify-between gap-3 py-2.5';
            fila.innerHTML = `
                <span class="flex min-w-0 items-center gap-2.5">
                    <span class="truncate text-sm font-medium k-strong">${escaparHtml(r.ingrediente)}</span>
                    <span class="k-badge ${claseTipo[r.tipo] || ''}">${escaparHtml(tipos[r.tipo] || r.tipo)}</span>
                </span>`;
            const quitar = document.createElement('button');
            quitar.type = 'button';
            quitar.className = 'k-btn k-btn-ghost k-btn-icon k-btn-danger-text';
            quitar.setAttribute('aria-label', 'Quitar ' + r.ingrediente);
            quitar.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
            quitar.onclick = () => { restricciones.splice(indice, 1); pintar(); };
            fila.appendChild(quitar);
            lista.appendChild(fila);
        });
    };

    const anadir = () => {
        const nombre = entrada.value.trim();
        if (!nombre) { avisar('Escribe el alimento que quieres evitar.'); return; }
        if (restricciones.some(r => r.ingrediente.toLowerCase() === nombre.toLowerCase())) {
            avisar(`«${nombre}» ya está en la lista.`);
            return;
        }
        restricciones.push({ ingrediente: nombre.charAt(0).toUpperCase() + nombre.slice(1), tipo: selector.value });
        entrada.value = '';
        avisar('');
        pintar();
        entrada.focus();
    };

    boton.addEventListener('click', anadir);
    entrada.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); anadir(); } });
    pintar();
}

/* ==========================================================================
   8. PROGRESO Y GRÁFICOS
   ========================================================================== */

/** Color de marca actual (claro u oscuro) para los gráficos. */
function colorMarca() {
    return getComputedStyle(document.documentElement).getPropertyValue('--k-brand').trim() || '#0d9488';
}

/** Gráfico de línea de peso en un <canvas>. */
function graficoPeso(lienzo, datos, conEjes) {
    if (!window.Chart || !lienzo || datos.length < 2) return;
    const color = colorMarca();
    const contexto = lienzo.getContext('2d');
    const degradado = contexto.createLinearGradient(0, 0, 0, lienzo.clientHeight || 240);
    degradado.addColorStop(0, color + (conEjes ? '33' : '26'));
    degradado.addColorStop(1, color + '00');
    const pesos = datos.map(d => parseFloat(d.peso_kg));

    new Chart(contexto, {
        type: 'line',
        data: {
            labels: datos.map(d => d.fecha),
            datasets: [{ data: pesos, borderColor: color, borderWidth: 2, backgroundColor: degradado, fill: true, tension: 0.4,
                         pointRadius: 0, pointHoverRadius: 4, pointBackgroundColor: color }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    displayColors: false,
                    callbacks: {
                        title: c => new Date(c[0].label).toLocaleDateString('es-ES', { day: 'numeric', month: 'short' }),
                        label: c => c.raw + ' kg',
                    },
                },
            },
            scales: {
                x: { display: conEjes, grid: { display: false }, border: { display: false },
                     ticks: { maxTicksLimit: 6, callback(v) { return new Date(this.getLabelForValue(v)).toLocaleDateString('es-ES', { day: 'numeric', month: 'short' }); } } },
                y: { display: conEjes, suggestedMin: Math.min(...pesos) - 1, suggestedMax: Math.max(...pesos) + 1, ticks: { maxTicksLimit: 4 },
                     border: { display: false }, grid: { color: varCss('--k-border', 'rgba(0,0,0,.06)') } },
            },
        },
    });
}

function iniciarProgreso() {
    if (window.progresoData) graficoPeso(document.getElementById('sparklineChart'), window.progresoData, true);

    // Borrar un registro: el botón lleva la fecha en data-fecha
    document.querySelectorAll('.btn-delete-progreso').forEach(boton => {
        boton.addEventListener('click', () => {
            document.getElementById('modalFechaDisplay').textContent = boton.dataset.displayFecha;
            document.getElementById('modalFechaInput').value = boton.dataset.fecha;
            abrirModal('deleteModal');
        });
    });
}

/** Valor de una variable CSS del tema actual (claro u oscuro). */
function varCss(nombre, porDefecto) {
    return getComputedStyle(document.documentElement).getPropertyValue(nombre).trim() || porDefecto;
}

function iniciarGraficoPanel() {
    const datos = window.dashboardData;
    if (!datos || !window.Chart) return;
    graficoPeso(document.getElementById('progressChart'), datos.progreso || [], true);
    graficoPeso(document.getElementById('pesoMini'), datos.progreso || [], false);

    // Calorías de cada día: barras redondeadas (hoy en color de marca) y línea de objetivo
    const lienzoSemana = document.getElementById('semanaChart');
    if (lienzoSemana) {
        const ctx = lienzoSemana.getContext('2d');
        const indigo = varCss('--k-indigo', '#6366f1');
        const marca = colorMarca();
        const degradado = ctx.createLinearGradient(0, 0, 0, lienzoSemana.clientHeight || 256);
        degradado.addColorStop(0, indigo);
        degradado.addColorStop(1, indigo + '55');
        const conjuntos = [{
            type: 'bar',
            data: datos.semana,
            backgroundColor: datos.semana.map((_, i) => i === datos.hoy ? marca : degradado),
            borderRadius: 10,
            borderSkipped: false,
            barPercentage: 0.62,
            categoryPercentage: 0.8,
            order: 2,
        }];
        if (datos.meta > 0) {
            conjuntos.push({
                type: 'line', label: 'Objetivo', data: datos.semana.map(() => datos.meta),
                borderColor: varCss('--k-muted', '#71717a'), borderDash: [5, 5], borderWidth: 1.5,
                pointRadius: 0, pointHoverRadius: 0, order: 1,
            });
        }
        new Chart(ctx, {
            data: { labels: datos.dias, datasets: conjuntos },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { displayColors: false, callbacks: { label: c => Math.round(c.raw) + ' kcal' + (c.dataset.label ? ' (objetivo)' : '') } } },
                scales: {
                    x: { grid: { display: false }, border: { display: false } },
                    y: { beginAtZero: true, border: { display: false }, ticks: { maxTicksLimit: 5 }, grid: { color: varCss('--k-border', 'rgba(0,0,0,.06)') } },
                },
            },
        });
    }

    // Reparto de calorías de hoy entre proteína, carbohidratos y grasa
    const lienzoMacros = document.getElementById('macrosChart');
    if (lienzoMacros) {
        const hayDatos = datos.macros.some(v => v > 0);
        new Chart(lienzoMacros, {
            type: 'doughnut',
            data: {
                labels: ['Proteína', 'Carbohidratos', 'Grasa'],
                datasets: [{
                    data: hayDatos ? datos.macros : [1],
                    backgroundColor: hayDatos ? [varCss('--k-prot', '#3b82f6'), varCss('--k-carb', '#f59e0b'), varCss('--k-fat', '#f43f5e')] : [varCss('--k-surface-3', '#e5e5e5')],
                    borderWidth: 0, spacing: hayDatos ? 3 : 0, borderRadius: 6,
                }],
            },
            options: {
                cutout: '78%', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { enabled: hayDatos, displayColors: false, callbacks: { label: c => c.label + ': ' + c.raw + ' kcal' } } },
            },
        });
    }
}

/* ==========================================================================
   9. PORTADA: CARRUSEL DE TESTIMONIOS
   ========================================================================== */

function iniciarCarrusel() {
    const carril = document.getElementById('testimony-track');
    if (!carril) return;
    const contenedor = document.getElementById('testimony-wrapper');
    const total = carril.querySelectorAll('.flex-shrink-0').length;
    const puntos = document.querySelectorAll('#testimony-dots .dot');
    let actual = 0;

    const mover = () => {
        carril.style.transform = `translateX(${-actual * 100}%)`;
        puntos.forEach((punto, i) => {
            punto.classList.toggle('bg-teal-600', i === actual);
            punto.classList.toggle('bg-gray-300', i !== actual);
        });
    };
    const siguiente = () => { actual = (actual + 1) % total; mover(); };

    let intervalo = setInterval(siguiente, 7000);
    contenedor?.addEventListener('mouseenter', () => clearInterval(intervalo));
    contenedor?.addEventListener('mouseleave', () => { intervalo = setInterval(siguiente, 7000); });
    mover();
}
