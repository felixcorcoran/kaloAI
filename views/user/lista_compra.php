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
                 WHERE ps.id_usuario = ?";

$stmtNec = $pdo->prepare($sqlNecesario);
$stmtNec->execute([$userId]);
$necesariosRaw = $stmtNec->fetchAll();

// Lo que falta = lo que piden las recetas del plan - lo que hay en el inventario,
// sumado en la misma unidad (kg/g, L/ml). La lógica está en core/recetas.php.
$stmtInv = $pdo->prepare("SELECT nombre_ingrediente, cantidad, unidad FROM inventario WHERE id_usuario = ?");
$stmtInv->execute([$userId]);
$listaCompra = calcularListaCompra($necesariosRaw, $stmtInv->fetchAll());

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

<div class="k-container k-page max-w-4xl">
    <div class="k-page-head">
        <div>
            <h1 class="k-title">Lista de la compra</h1>
            <p class="k-subtitle">Lo que necesitan las recetas de tu plan, menos lo que ya tienes en la despensa.</p>
        </div>
        <?php if (!empty($listaCompra)): ?>
            <a href="descargar_lista.php" class="k-btn k-btn-secondary"><?= icono('download') ?>Descargar PDF</a>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="k-alert k-alert-ok mb-6"><?= icono('check') ?><p>Añadido a tu despensa.</p></div>
    <?php elseif (isset($_GET['error'])): ?>
        <div class="k-alert k-alert-error mb-6"><?= icono('alert') ?><p>No se pudo añadir a la despensa. Inténtalo de nuevo.</p></div>
    <?php endif; ?>

    <?php if (empty($listaCompra)): ?>
        <div class="k-empty">
            <span class="k-empty-icon"><?= icono('check') ?></span>
            <p class="font-medium k-strong">No te falta nada</p>
            <p class="mt-1 max-w-sm text-sm k-muted">Tienes en la despensa todo lo que piden las recetas del plan.</p>
            <div class="mt-5 flex gap-2">
                <a href="planificacion_semanal.php" class="k-btn k-btn-secondary">Ver el plan</a>
                <a href="inventario.php" class="k-btn k-btn-secondary">Ver la despensa</a>
            </div>
        </div>
    <?php else: ?>
        <section class="k-card">
            <div class="k-card-head">
                <h2 class="k-section-title"><?= count($listaCompra) ?> <?= count($listaCompra) === 1 ? 'producto' : 'productos' ?></h2>
                <p class="text-xs k-muted">Al marcarlo como comprado, pasa a la despensa.</p>
            </div>
            <ul class="k-list">
                <?php foreach ($listaCompra as $item): ?>
                    <li class="flex items-center gap-4 px-5 py-3.5 sm:px-6">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium k-strong"><?= e($item['nombre']) ?></p>
                            <p class="mt-0.5 text-xs k-muted">
                                Necesitas <?= e($formatear($item['total_recetas'], $item['unidad'])) ?>
                                <?php if ($item['en_despensa'] > 0): ?> · tienes <?= e($formatear($item['en_despensa'], $item['unidad'])) ?><?php endif; ?>
                            </p>
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
        </section>
    <?php endif; ?>
</div>

<?php include_once __DIR__ . '/../templates/footer.php'; ?>
