<?php
/**
 * VISTA PÚBLICA: LISTA DE LA COMPRA COMPARTIDA - KaloAI
 * Solo lectura y sin iniciar sesión, para otra persona de la casa. El enlace
 * lo crea la dueña de la lista desde «Lista de la compra» (core/recetas.php).
 * Marcar un producto solo lo tacha en este navegador: no toca la despensa.
 */

$pageTitle = "KaloAI | Lista de la compra";
$noIndexar = true;
session_start();

require_once __DIR__ . '/../core/configuracion.php';
require_once __DIR__ . '/../core/utilidades.php';
require_once __DIR__ . '/../core/recetas.php';

// El token va en la URL: que no viaje a otras webs en la cabecera Referer
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$token = (string) ($_GET['t'] ?? '');
$pdo = connectDB();
$dueno = duenoListaCompartida($pdo, $token);

$semanaActual = lunesDe();
$semana = ($_GET['semana'] ?? '') === semanaRelativa($semanaActual, 1) ? semanaRelativa($semanaActual, 1) : $semanaActual;
if ($dueno) {
    ['lista' => $listaCompra, 'comensales' => $comensales] = listaCompraDeSemana($pdo, $dueno['id_usuario'], $semana);
} else {
    http_response_code(404);
}

include_once __DIR__ . '/../views/templates/header.php';
$urlSemana = fn(string $s) => 'lista.php?t=' . urlencode($token) . ($s !== $semanaActual ? '&semana=' . $s : '');
?>

<div class="k-container max-w-3xl py-10 sm:py-14">
    <?php if (!$dueno): ?>
        <div class="k-empty">
            <span class="k-empty-icon"><?= icono('link') ?></span>
            <h1 class="font-display text-xl font-semibold k-strong">Este enlace ya no funciona</h1>
            <p class="mt-1 max-w-sm text-sm k-muted">Quien te lo pasó ha dejado de compartir su lista o ha creado un enlace nuevo. Pídele que te lo envíe otra vez.</p>
        </div>
    <?php else: ?>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <span class="k-pill"><?= icono('cart') ?>Lista compartida</span>
                <h1 class="mt-3 font-display text-3xl font-bold tracking-tight k-strong">La compra<?= $dueno['nombre'] !== '' ? ' de ' . e($dueno['nombre']) : '' ?></h1>
                <p class="mt-1 text-sm k-muted"><?= e(nombreSemana($semana)) ?><?= $comensales > 1 ? ' · para ' . $comensales . ' personas' : '' ?>. Se actualiza sola cuando cambia el plan.</p>
            </div>
            <div class="k-seg" role="group" aria-label="Semana de la lista">
                <?php foreach ([$semanaActual, semanaRelativa($semanaActual, 1)] as $opcion): ?>
                    <a href="<?= e($urlSemana($opcion)) ?>" <?= $opcion === $semana ? 'aria-current="page"' : '' ?>><?= e(nombreSemana($opcion)) ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (!$listaCompra): ?>
            <div class="k-empty mt-8">
                <span class="k-empty-icon"><?= icono('check') ?></span>
                <p class="font-medium k-strong">No hace falta comprar nada</p>
                <p class="mt-1 max-w-sm text-sm k-muted">O el plan de esta semana está vacío, o ya está todo en la despensa.</p>
            </div>
        <?php else: ?>
            <section class="k-card mt-8" id="listaCompartida" data-clave="kalo-lista-<?= e($token) ?>-<?= e($semana) ?>">
                <div class="k-card-head">
                    <h2 class="k-section-title"><?= count($listaCompra) ?> <?= count($listaCompra) === 1 ? 'producto' : 'productos' ?></h2>
                    <button type="button" id="desmarcarTodo" class="k-btn k-btn-ghost k-btn-sm" hidden>Desmarcar todo</button>
                </div>
                <?php foreach (agruparPorSeccion($listaCompra) as $seccion => $productos): ?>
                    <h3 class="flex items-center gap-2 border-t px-5 pb-1 pt-4 text-xs font-semibold uppercase tracking-wider k-muted sm:px-6" style="border-color: var(--k-border);">
                        <span aria-hidden="true"><?= SECCIONES_COMPRA[$seccion] ?></span><?= e($seccion) ?>
                    </h3>
                    <ul class="k-list">
                        <?php foreach ($productos as $item): $id = 'p-' . substr(md5($item['nombre'] . '|' . $item['unidad']), 0, 10); ?>
                            <li>
                                <label for="<?= $id ?>" class="flex cursor-pointer items-center gap-4 px-5 py-3.5 sm:px-6">
                                    <input type="checkbox" id="<?= $id ?>" data-producto="<?= e($id) ?>" class="h-5 w-5 shrink-0 accent-teal-600">
                                    <span class="min-w-0 flex-1 font-medium k-strong" data-nombre><?= e($item['nombre']) ?></span>
                                    <span class="shrink-0 text-right tabular-nums k-muted"><?= e(formatearCantidad($item['comprar'], $item['unidad'])) ?></span>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
            </section>
            <p class="mt-4 text-xs k-muted">Lo que marques solo se tacha en este móvil. Las cantidades ya descuentan lo que hay en la despensa.</p>
        <?php endif; ?>

        <p class="mt-10 text-center text-sm k-muted">Hecha con <a href="<?= e(BASE_URL) ?>" class="k-link">KaloAI</a>, planificación alimentaria con IA.</p>
    <?php endif; ?>
</div>

<script>
    // Tachar productos mientras se compra (se recuerda en este navegador, por semana)
    (() => {
        const lista = document.getElementById('listaCompartida');
        if (!lista) return;
        const clave = lista.dataset.clave;
        let marcados = [];
        try { marcados = JSON.parse(localStorage.getItem(clave) || '[]'); } catch (e) { marcados = []; }
        const casillas = lista.querySelectorAll('[data-producto]');
        const boton = document.getElementById('desmarcarTodo');
        const pintar = () => {
            casillas.forEach(c => {
                c.checked = marcados.includes(c.dataset.producto);
                c.closest('label').querySelector('[data-nombre]').classList.toggle('line-through', c.checked);
                c.closest('label').classList.toggle('opacity-60', c.checked);
            });
            boton.hidden = marcados.length === 0;
        };
        const guardar = () => { try { localStorage.setItem(clave, JSON.stringify(marcados)); } catch (e) { /* sin almacenamiento */ } };
        casillas.forEach(c => c.addEventListener('change', () => {
            marcados = c.checked ? [...new Set([...marcados, c.dataset.producto])] : marcados.filter(m => m !== c.dataset.producto);
            guardar(); pintar();
        }));
        boton.addEventListener('click', () => { marcados = []; guardar(); pintar(); });
        pintar();
    })();
</script>

<?php include_once __DIR__ . '/../views/templates/footer.php'; ?>
