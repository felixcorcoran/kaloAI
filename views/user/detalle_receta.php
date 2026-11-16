<?php
/**
 * VISTA: DETALLE DE RECETA - KaloAI
 * Muestra toda la información de una receta específica: pasos, macros y metadatos.
 * Incluye un sistema dinámico de estilos por categoría y gestión de borrado seguro.
 */

$pageTitle = "KaloAI | Detalle de Receta";
session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php';
require_once __DIR__ . '/../../core/recetas.php';

// Control de acceso: Solo usuarios registrados
if (!isset($_SESSION['user_id'])) { redirect('../auth/login.php'); }

$pdo = connectDB();
$recetaId = $_GET['id'] ?? null;
$receta = null;

/* ==========================================================================
   1. CARGA DE DATOS DESDE LA BD
   ========================================================================== */
if ($recetaId) {
    try {
        // Solo las recetas propias: antes cualquiera podía ver las de otra
        // persona cambiando el número de la URL
        $stmt = $pdo->prepare("SELECT * FROM receta WHERE id_receta = ? AND id_usuario_creador = ?");
        $stmt->execute([$recetaId, $_SESSION['user_id']]);
        $receta = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        error_log("Error al cargar detalle de receta: " . $e->getMessage());
    }
}

// Redirección de seguridad si la receta no existe o el ID es inválido
if (!$receta) {
    header("Location: mis_recetas.php");
    exit;
}

// Ingredientes con su cantidad, para mostrarlos como "150 g Pechuga de pollo"
$stmtIng = $pdo->prepare("SELECT nombre_ingrediente, cantidad, unidad FROM detalle_receta WHERE id_receta = ? ORDER BY nombre_ingrediente");
$stmtIng->execute([$recetaId]);
$ingredientes = $stmtIng->fetchAll(PDO::FETCH_ASSOC);
$pasos = dividirPasos($receta['descripcion']);

// Qué ingredientes hay ya en la despensa, cuántas veces está en el plan y objetivos diarios
$stmtInv = $pdo->prepare("SELECT nombre_ingrediente FROM inventario WHERE id_usuario = ?");
$stmtInv->execute([$_SESSION['user_id']]);
$enDespensa = array_flip(array_map(fn($n) => claveProducto((string) $n), $stmtInv->fetchAll(PDO::FETCH_COLUMN)));
$tengoIngrediente = fn(array $ing) => isset($enDespensa[claveProducto((string) $ing['nombre_ingrediente'])]);
$ingredientesEnCasa = count(array_filter($ingredientes, $tengoIngrediente));
$stmtVeces = $pdo->prepare("SELECT COUNT(*) FROM comida_planificada cp JOIN plan_semanal ps ON cp.id_plan = ps.id_plan WHERE ps.id_usuario = ? AND ps.fecha_inicio_semana = ? AND cp.id_receta = ?");
$stmtVeces->execute([$_SESSION['user_id'], lunesDe(), $recetaId]);
$vecesEnPlan = (int) $stmtVeces->fetchColumn();
$comensales = comensalesDe($pdo, (int) $_SESSION['user_id']);
$stmtObj = $pdo->prepare("SELECT calorias_netas_objetivo, proteinas_objetivo_g, carbohidratos_objetivo_g, grasas_objetivo_g FROM perfil WHERE id_usuario = ?");
$stmtObj->execute([$_SESSION['user_id']]);
$objetivos = $stmtObj->fetch(PDO::FETCH_ASSOC) ?: [];

$pageTitle = "KaloAI | " . $receta['titulo'];
include_once __DIR__ . '/../templates/header.php';
$estilo = estiloTipoReceta($receta['tipo_comida']);
$kcal = max(1, (float) $receta['calorias_por_racion']);
$macros = [
    ['k-macro-p', 'Proteína', (float) $receta['proteinas_g_por_racion'], 4, (int) ($objetivos['proteinas_objetivo_g'] ?? 0)],
    ['k-macro-c', 'Carbohidratos', (float) $receta['carbohidratos_g_por_racion'], 4, (int) ($objetivos['carbohidratos_objetivo_g'] ?? 0)],
    ['k-macro-f', 'Grasa', (float) $receta['grasas_g_por_racion'], 9, (int) ($objetivos['grasas_objetivo_g'] ?? 0)],
];
$metaKcal = (int) ($objetivos['calorias_netas_objetivo'] ?? 0);
?>

<div class="k-container k-page">
    <a href="mis_recetas.php" class="k-back"><?= icono('chevron-left', 'h-4 w-4') ?>Mis recetas</a>

    <div class="k-page-head">
        <div class="flex items-start gap-4">
            <span class="<?= $estilo['tinte'] ?> grid h-14 w-14 shrink-0 place-items-center rounded-2xl text-3xl" aria-hidden="true"><?= $estilo['emoji'] ?></span>
            <div>
                <span class="k-pill"><?= e($estilo['nombre']) ?></span>
                <h1 class="k-title mt-2"><?= e($receta['titulo']) ?></h1>
                <div class="mt-2 flex flex-wrap gap-2">
                    <span class="k-badge"><?= icono('utensils', 'h-3.5 w-3.5') ?>1 ración</span>
                    <?php if (!empty($receta['generado_ia'])): ?>
                        <span class="k-badge k-badge-brand"><?= icono('sparkles', 'h-3.5 w-3.5') ?>Generada con IA</span>
                    <?php endif; ?>
                    <span class="k-badge"><?= icono('clock', 'h-3.5 w-3.5') ?><?= e(formatearFecha($receta['fecha_creacion'])) ?></span>
                    <?php if ($vecesEnPlan): ?><span class="k-badge k-badge-ok"><?= icono('calendar', 'h-3.5 w-3.5') ?><?= $vecesEnPlan ?> <?= $vecesEnPlan === 1 ? 'vez' : 'veces' ?> en tu plan</span><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="flex gap-2">
            <button type="button" onclick="alternarFavorita(this)" data-receta="<?= (int) $receta['id_receta'] ?>" data-titulo="<?= e($receta['titulo']) ?>"
                    class="k-btn k-btn-secondary k-btn-icon k-fav" aria-pressed="<?= !empty($receta['favorita']) ? 'true' : 'false' ?>"
                    aria-label="<?= !empty($receta['favorita']) ? 'Quitar de favoritas' : 'Añadir a favoritas' ?>: <?= e($receta['titulo']) ?>"><?= icono('heart') ?></button>
            <a href="editar_receta.php?id=<?= (int) $receta['id_receta'] ?>" class="k-btn k-btn-secondary"><?= icono('pencil') ?>Editar</a>
            <button type="button" onclick="abrirModalEliminar(<?= (int) $receta['id_receta'] ?>)" class="k-btn k-btn-ghost k-btn-danger-text"><?= icono('trash') ?>Eliminar</button>
        </div>
    </div>

    <?php if (!empty($_SESSION['mensaje'])): ?>
        <div class="k-alert k-alert-ok mb-6"><?= icono('check') ?><p><?= e($_SESSION['mensaje']) ?></p></div>
        <?php unset($_SESSION['mensaje']); ?>
    <?php endif; ?>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <!-- INGREDIENTES -->
            <section class="k-card">
                <div class="k-card-head flex-wrap gap-3"><h2 class="k-section-title">Ingredientes</h2>
                    <!-- Cantidades para varias personas (solo cambia lo que se ve) -->
                    <div class="flex items-center gap-1 text-sm" aria-label="Número de personas">
                        <button type="button" onclick="cambiarPersonasReceta(-1)" class="k-btn k-btn-ghost k-btn-icon h-8 w-8" aria-label="Una persona menos"><?= icono('minus', 'h-4 w-4') ?></button>
                        <span class="min-w-[5.5rem] text-center k-strong" aria-live="polite"><span id="personasReceta"><?= (int) $comensales ?></span> <span id="personasTexto"><?= $comensales === 1 ? 'persona' : 'personas' ?></span></span>
                        <button type="button" onclick="cambiarPersonasReceta(1)" class="k-btn k-btn-ghost k-btn-icon h-8 w-8" aria-label="Una persona más"><?= icono('plus', 'h-4 w-4') ?></button>
                    </div><?php if ($ingredientes): ?><span class="k-badge <?= $ingredientesEnCasa === count($ingredientes) ? 'k-badge-ok' : '' ?>"><?= icono('package', 'h-3.5 w-3.5') ?><?= $ingredientesEnCasa ?> de <?= count($ingredientes) ?> en tu despensa</span><?php endif; ?></div>
                <?php if ($ingredientes): ?>
                    <ul class="k-list">
                        <?php foreach ($ingredientes as $ing):
                            $cantidad = (float) $ing['cantidad']; ?>
                            <li class="flex items-center justify-between gap-4 px-5 py-3 text-sm sm:px-6">
                                <span class="flex items-center gap-3 k-strong">
                                    <?php if ($tengoIngrediente($ing)): ?>
                                        <span class="grid h-5 w-5 place-items-center rounded-full" style="background-color: var(--k-brand-soft); color: var(--k-brand-text);" title="Lo tienes en la despensa"><?= icono('check', 'h-3 w-3') ?></span>
                                    <?php else: ?>
                                        <span class="h-5 w-5 rounded-full border border-dashed" style="border-color: var(--k-border-strong);" title="No está en tu despensa"></span>
                                    <?php endif; ?>
                                    <?= e($ing['nombre_ingrediente']) ?>
                                </span>
                                <span class="shrink-0 tabular-nums k-muted" data-cantidad="<?= e($cantidad) ?>" data-unidad="<?= e($ing['unidad']) ?>">
                                    <?= $cantidad > 0 ? e(rtrim(rtrim(number_format($cantidad * $comensales, 2, ',', '.'), '0'), ',')) . ' ' . e($ing['unidad']) : '' ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="px-6 py-5 text-sm k-muted">Esta receta no tiene ingredientes guardados.</p>
                <?php endif; ?>
            </section>

            <!-- PREPARACIÓN -->
            <section class="k-card k-card-pad">
                <h2 class="k-section-title">Preparación</h2>
                <?php if ($pasos): ?>
                    <ol class="mt-4 space-y-4">
                        <?php foreach ($pasos as $i => $paso): ?>
                            <li class="flex gap-4">
                                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full text-xs font-semibold" style="background-color: var(--k-brand-soft); color: var(--k-brand-text);"><?= $i + 1 ?></span>
                                <p class="pt-0.5 text-sm leading-relaxed"><?= e($paso) ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php else: ?>
                    <p class="mt-3 text-sm k-muted">Sin instrucciones.</p>
                <?php endif; ?>
            </section>
        </div>

        <!-- NUTRICIÓN -->
        <aside class="k-card k-card-pad h-fit lg:sticky lg:top-24">
            <h2 class="k-section-title">Nutrición <span class="text-sm font-normal k-muted">por ración</span></h2>
            <p class="mt-4 flex items-baseline gap-1">
                <span class="font-display text-4xl font-bold tracking-tight tabular-nums k-strong"><?= round($receta['calorias_por_racion']) ?></span>
                <span class="text-sm k-muted">kcal</span>
            </p>
            <!-- Reparto de las calorías entre los tres macros -->
            <div class="mt-4 flex h-2.5 overflow-hidden rounded-full" style="background-color: var(--k-surface-2);">
                <?php foreach ($macros as [$clase, , $gramos, $kcalPorGramo]): ?>
                    <span class="<?= $clase ?> k-macro-bar block h-full" style="width: <?= round($gramos * $kcalPorGramo / $kcal * 100, 1) ?>%"></span>
                <?php endforeach; ?>
            </div>
            <ul class="mt-5 space-y-3">
                <?php foreach ($macros as [$clase, $etiqueta, $gramos]): ?>
                    <li class="<?= $clase ?> flex items-center justify-between text-sm">
                        <span class="flex items-center gap-2"><span class="k-macro-dot"></span><?= $etiqueta ?></span>
                        <span class="font-semibold tabular-nums k-strong"><?= round($gramos) ?> g</span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($metaKcal > 0): ?>
                <div class="mt-6 border-t pt-5" style="border-color: var(--k-border);">
                    <p class="text-sm font-medium k-strong">De tu objetivo diario</p>
                    <ul class="mt-3 space-y-3 text-xs">
                        <?php foreach (array_merge([['', 'Calorías', (float) $receta['calorias_por_racion'], 0, $metaKcal]], $macros) as [$clase, $etiqueta, $valor, , $meta]):
                            $pct = $meta > 0 ? (int) round($valor / $meta * 100) : 0; ?>
                            <li class="<?= $clase ?>">
                                <div class="flex justify-between"><span class="k-muted"><?= $etiqueta ?></span><span class="tabular-nums k-strong"><?= $pct ?>%</span></div>
                                <div class="k-track mt-1.5 h-1.5"><div class="k-bar <?= $clase ? 'k-macro-bar' : '' ?>" style="width: <?= min(100, $pct) ?>%"></div></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <a href="planificacion_semanal.php" class="k-btn k-btn-secondary mt-6 w-full"><?= icono('calendar') ?>Ir al plan semanal</a>
        </aside>
    </div>
</div>

<!-- MODAL: ELIMINAR RECETA -->
<div id="modalBorrarReceta" class="k-modal" hidden role="dialog" aria-modal="true" aria-labelledby="tituloBorrarReceta">
    <div class="k-modal-backdrop"></div>
    <div class="k-modal-panel max-w-sm">
        <h2 id="tituloBorrarReceta" class="k-modal-title">¿Eliminar «<?= e($receta['titulo']) ?>»?</h2>
        <p class="mt-2 text-sm k-muted">Se borrará de tu biblioteca y de los días del plan en los que aparezca. No se puede deshacer.</p>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="k-btn k-btn-secondary" data-cerrar-modal>Cancelar</button>
            <button type="button" id="confirmDeleteButton" class="k-btn k-btn-danger" data-confirmar>Eliminar</button>
        </div>
    </div>
</div>

<?php include_once __DIR__ . '/../templates/footer.php'; ?>
