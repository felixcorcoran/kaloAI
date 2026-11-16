<?php
/**
 * GENERADOR DE LISTA DE COMPRA INTELIGENTE - KaloAI
 * Este script realiza un cruce de datos (JOIN) entre la planificación semanal
 * y el inventario actual para calcular cantidades netas a comprar.
 */

$pageTitle = "KaloAI | Lista de la compra";
session_start();

require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php';
require_once __DIR__ . '/../../core/recetas.php';

// 1. SEGURIDAD: Garantizar que solo usuarios identificados accedan a sus datos
if (!isset($_SESSION['user_id'])) {
    redirect('../auth/login.php');
}

$userId = $_SESSION['user_id'];
$pdo = connectDB();

/**
 * ==========================================================================
 * LÓGICA DE CÁLCULO OPTIMIZADA Y NORMALIZADA
 * El objetivo es que "Leche" y "leche" (o "Arroz" y "Arroz cocido") 
 * se traten como el mismo producto para sumarse correctamente.
 * ==========================================================================
 */

// 1. OBTENCIÓN DE DEMANDA: Extraer ingredientes de todas las recetas en el plan actual
$sqlNecesario = "SELECT dr.nombre_ingrediente, dr.cantidad, cp.raciones, dr.unidad 
                 FROM comida_planificada cp
                 JOIN detalle_receta dr ON cp.id_receta = dr.id_receta
                 JOIN plan_semanal ps ON cp.id_plan = ps.id_plan
                 WHERE ps.id_usuario = ? AND ps.fecha_inicio_semana = ?";

$stmtNec = $pdo->prepare($sqlNecesario);
// Semana de la lista: la actual o la que se pida (?semana=AAAA-MM-DD)
$semana = semanaValida($_GET['semana'] ?? null);
$stmtNec->execute([$userId, $semana]);
$necesariosRaw = $stmtNec->fetchAll();

// Lo que falta = lo que piden las recetas del plan - lo que hay en el inventario,
// sumado en la misma unidad (kg/g, L/ml). La lógica está en core/recetas.php.
$stmtInv = $pdo->prepare("SELECT nombre_ingrediente, cantidad, unidad FROM inventario WHERE id_usuario = ?");
$stmtInv->execute([$userId]);
$listaCompra = calcularListaCompra($necesariosRaw, $stmtInv->fetchAll());

// Resumen: cuántos ingredientes distintos pide el plan y cuántos cubre ya la despensa
$ingredientesPlan = count(array_unique(array_map(fn($f) => claveProducto((string) $f['nombre_ingrediente']), $necesariosRaw)));
$cubiertos = max(0, $ingredientesPlan - count($listaCompra));
$parciales = count(array_filter($listaCompra, fn($i) => $i['en_despensa'] > 0));
$stmtCom = $pdo->prepare("SELECT COUNT(*) FROM comida_planificada cp JOIN plan_semanal ps ON cp.id_plan = ps.id_plan WHERE ps.id_usuario = ? AND ps.fecha_inicio_semana = ?");
$stmtCom->execute([$userId, $semana]);
$comidasPlan = (int) $stmtCom->fetchColumn();

// Carga del componente de cabecera 
include_once __DIR__ . '/../templates/header.php';
?>
<?php
// Cantidad legible: "1,5 kg" en vez de "1500 g", "3 unidades"
$formatear = function (float $cantidad, string $unidad): string {
    if ($unidad === 'g' && $cantidad >= 1000) { $cantidad /= 1000; $unidad = 'kg'; }
    if ($unidad === 'ml' && $cantidad >= 1000) { $cantidad /= 1000; $unidad = 'L'; }
    if ($unidad === 'unidad') $unidad = $cantidad == 1 ? 'unidad' : 'unidades';
    $numero = rtrim(rtrim(number_format($cantidad, 2, ',', '.'), '0'), ',');
    return $numero . ' ' . $unidad;
};
?>

<div class="k-container k-page">
    <div class="k-page-head">
        <div>
            <span class="k-pill"><?= icono('cart') ?><?= e(nombreSemana($semana)) ?></span>
            <h1 class="k-title mt-3">Lista de la compra</h1>
            <p class="k-subtitle">Lo que necesitan las recetas de tu plan, menos lo que ya tienes en la despensa.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <!-- Esta semana o la siguiente (para hacer la compra con antelación) -->
            <div class="k-seg" role="group" aria-label="Semana de la lista">
                <?php foreach ([lunesDe(), semanaRelativa(lunesDe(), 1)] as $opcion): ?>
                    <a href="lista_compra.php<?= $opcion !== lunesDe() ? '?semana=' . $opcion : '' ?>" <?= $opcion === $semana ? 'aria-current="page"' : '' ?>><?= e(nombreSemana($opcion)) ?></a>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($listaCompra)): ?>
                <a href="descargar_lista.php<?= $semana !== lunesDe() ? '?semana=' . $semana : '' ?>" class="k-btn k-btn-secondary"><?= icono('download') ?>Descargar PDF</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="k-alert k-alert-ok mb-6"><?= icono('check') ?><p>Añadido a tu despensa.</p></div>
    <?php elseif (isset($_GET['error'])): ?>
        <div class="k-alert k-alert-error mb-6"><?= icono('alert') ?><p>No se pudo añadir a la despensa. Inténtalo de nuevo.</p></div>
    <?php endif; ?>

    <!-- CIFRAS -->
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="k-card flex items-center gap-4 p-5">
            <span class="k-icon-tile k-tile-orange"><?= icono('cart') ?></span>
            <div><p class="k-stat-label">Por comprar</p><p class="k-stat-value"><?= count($listaCompra) ?><span class="k-stat-unit"><?= count($listaCompra) === 1 ? 'producto' : 'productos' ?></span></p></div>
        </div>
        <div class="k-card flex items-center gap-4 p-5">
            <span class="k-icon-tile"><?= icono('package') ?></span>
            <div><p class="k-stat-label">Ya en tu despensa</p><p class="k-stat-value"><?= $cubiertos ?><span class="k-stat-unit">de <?= $ingredientesPlan ?></span></p></div>
        </div>
        <div class="k-card flex items-center gap-4 p-5">
            <span class="k-icon-tile k-tile-indigo"><?= icono('calendar') ?></span>
            <div><p class="k-stat-label">Comidas en el plan</p><p class="k-stat-value"><?= $comidasPlan ?><span class="k-stat-unit">de 35</span></p></div>
        </div>
    </div>

    <?php if (empty($listaCompra)): ?>
        <div class="k-empty">
            <span class="k-empty-icon"><?= icono('check') ?></span>
            <p class="font-medium k-strong"><?= $comidasPlan ? 'No te falta nada' : 'Tu plan está vacío' ?></p>
            <p class="mt-1 max-w-sm text-sm k-muted"><?= $comidasPlan ? 'Tienes en la despensa todo lo que piden las recetas del plan.' : 'Añade recetas al plan semanal y aquí verás lo que tienes que comprar.' ?></p>
            <div class="mt-5 flex gap-2">
                <a href="planificacion_semanal.php<?= $semana !== lunesDe() ? '?semana=' . $semana : '' ?>" class="k-btn k-btn-secondary">Ver el plan</a>
                <a href="inventario.php" class="k-btn k-btn-secondary">Ver la despensa</a>
            </div>
        </div>
    <?php else: ?>
        <div class="grid gap-6 lg:grid-cols-3">
            <section class="k-card lg:col-span-2">
                <div class="k-card-head">
                    <h2 class="k-section-title">Productos</h2>
                    <p class="text-xs k-muted">Al marcarlo como comprado, pasa a la despensa.</p>
                </div>
                <?php foreach (agruparPorSeccion($listaCompra) as $seccion => $productos): ?>
                    <h3 class="flex items-center gap-2 border-t px-5 pb-1 pt-4 text-xs font-semibold uppercase tracking-wider k-muted sm:px-6" style="border-color: var(--k-border);">
                        <span aria-hidden="true"><?= SECCIONES_COMPRA[$seccion] ?></span><?= e($seccion) ?> <span class="font-normal normal-case tracking-normal">· <?= count($productos) ?></span>
                    </h3>
                    <ul class="k-list">
                        <?php foreach ($productos as $item):
                            $pctTengo = $item['total_recetas'] > 0 ? min(100, round($item['en_despensa'] / $item['total_recetas'] * 100)) : 0; ?>
                        <li class="flex items-center gap-4 px-5 py-3.5 sm:px-6">
                            <span class="k-icon-tile hidden h-10 w-10 shrink-0 text-sm font-semibold sm:grid" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($item['nombre'], 0, 1))) ?></span>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium k-strong"><?= e($item['nombre']) ?></p>
                                <p class="mt-0.5 text-xs k-muted">
                                    Necesitas <?= e($formatear($item['total_recetas'], $item['unidad'])) ?>
                                    <?php if ($item['en_despensa'] > 0): ?> · tienes <?= e($formatear($item['en_despensa'], $item['unidad'])) ?><?php endif; ?>
                                </p>
                                <div class="k-track mt-2 h-1 max-w-[12rem]" title="<?= $pctTengo ?>% ya en tu despensa"><div class="k-bar" style="width: <?= $pctTengo ?>%"></div></div>
                            </div>
                            <span class="shrink-0 text-right font-display text-lg font-semibold tabular-nums k-strong"><?= e($formatear($item['comprar'], $item['unidad'])) ?></span>
                            <form action="comprar_item.php" method="POST" class="m-0 shrink-0">
                                <?= campoCsrf() ?>
                                <input type="hidden" name="nombre" value="<?= e($item['nombre']) ?>">
                                <input type="hidden" name="cantidad" value="<?= e($item['comprar']) ?>">
                                <input type="hidden" name="unidad" value="<?= e($item['unidad']) ?>">
                                <button type="submit" class="k-btn k-btn-secondary k-btn-sm" aria-label="Marcar <?= e($item['nombre']) ?> como comprado"><?= icono('check') ?><span class="hidden sm:inline">Comprado</span></button>
                            </form>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
            </section>

            <aside class="space-y-6">
                <section class="k-card k-card-pad">
                    <h2 class="k-section-title">Cobertura de la despensa</h2>
                    <?php $pctCubierto = $ingredientesPlan ? (int) round($cubiertos / $ingredientesPlan * 100) : 0; ?>
                    <div class="mt-4 flex items-baseline gap-2"><p class="k-stat-value"><?= $pctCubierto ?>%</p><span class="text-sm k-muted">del plan sin comprar nada</span></div>
                    <div class="k-track mt-3 h-2"><div class="k-bar" style="width: <?= $pctCubierto ?>%"></div></div>
                    <dl class="mt-5 space-y-2.5 text-sm">
                        <div class="flex justify-between"><dt class="k-muted">Ingredientes del plan</dt><dd class="font-medium tabular-nums k-strong"><?= $ingredientesPlan ?></dd></div>
                        <div class="flex justify-between"><dt class="k-muted">Los tienes todos</dt><dd class="font-medium tabular-nums k-strong"><?= $cubiertos ?></dd></div>
                        <div class="flex justify-between"><dt class="k-muted">Tienes una parte</dt><dd class="font-medium tabular-nums k-strong"><?= $parciales ?></dd></div>
                        <div class="flex justify-between"><dt class="k-muted">No tienes nada</dt><dd class="font-medium tabular-nums k-strong"><?= count($listaCompra) - $parciales ?></dd></div>
                    </dl>
                </section>
                <section class="k-card k-card-pad">
                    <div class="flex items-start gap-3">
                        <span class="k-icon-tile k-tile-amber"><?= icono('lightbulb') ?></span>
                        <div>
                            <p class="font-medium k-strong">Compra con el móvil</p>
                            <p class="mt-1 text-sm k-muted">Descarga el PDF o abre esta página en el súper: cada producto que marques se suma a la despensa.</p>
                        </div>
                    </div>
                    <a href="inventario.php" class="k-btn k-btn-secondary mt-4 w-full"><?= icono('package') ?>Ver la despensa</a>
                </section>
            </aside>
        </div>
    <?php endif; ?>
</div>

<?php include_once __DIR__ . '/../templates/footer.php'; ?>
