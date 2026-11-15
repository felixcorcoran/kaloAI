<?php
/**
 * BIBLIOTECA DE RECETAS - KaloAI
 * Este módulo gestiona la visualización de la colección de recetas del usuario,
 * incluyendo las creadas por él y las recetas base del sistema (NULL).
 */

$pageTitle = "KaloAI | Mis Recetas";
session_start();
require_once __DIR__ . '/../../core/utilidades.php';
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/recetas.php';

// SEGURIDAD: Redirección si no hay sesión activa
if (!isset($_SESSION['user_id'])) { redirect('../auth/login.php'); }

$pdo = connectDB();
$userId = $_SESSION['user_id'];

try {
    /**
     * CONSULTA JERÁRQUICA:
     * 1. Trae recetas propias (ID usuario) y recetas globales (NULL).
     * 2. Ordena prioritariamente por tipo de comida (flujo lógico de un día)
     *    y secundariamente por orden alfabético.
     */
    $sql = "SELECT * FROM receta 
            WHERE id_usuario_creador = ? 
            ORDER BY 
                CASE 
                    WHEN LOWER(tipo_comida) = 'desayuno' THEN 1
                    WHEN LOWER(tipo_comida) IN ('almuerzo', 'comida') THEN 2
                    WHEN LOWER(tipo_comida) IN ('snack', 'merienda') THEN 3
                    WHEN LOWER(tipo_comida) = 'cena' THEN 4
                    ELSE 5 
                END ASC, 
                titulo ASC";
                
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $recetas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    error_log("Error en Mis Recetas: " . $e->getMessage());
    $recetas = [];
}

// Mensaje de la última acción (guardar, borrar…), una sola vez
$mensaje = $_SESSION['mensaje'] ?? null;
$mensajeTipo = ($_SESSION['mensaje_tipo'] ?? 'success') === 'error' ? 'error' : 'ok';
unset($_SESSION['mensaje'], $_SESSION['mensaje_tipo']);

// Cuántas veces está cada receta en el plan, para el resumen y las tarjetas
$stmtUso = $pdo->prepare("SELECT cp.id_receta, COUNT(*) AS veces FROM comida_planificada cp JOIN plan_semanal ps ON cp.id_plan = ps.id_plan WHERE ps.id_usuario = ? AND ps.fecha_inicio_semana = ? GROUP BY cp.id_receta");
$stmtUso->execute([$userId, lunesDe()]);
$usoEnPlan = array_column($stmtUso->fetchAll(PDO::FETCH_ASSOC), 'veces', 'id_receta');
$recetasIa = count(array_filter($recetas, fn($r) => !empty($r['generado_ia'])));
$mediaKcalRecetas = $recetas ? array_sum(array_column($recetas, 'calorias_por_racion')) / count($recetas) : 0;
$enPlanDistintas = count(array_intersect(array_column($recetas, 'id_receta'), array_keys($usoEnPlan)));

include_once __DIR__ . '/../templates/header.php';
?>

<div class="k-container k-page">
    <div class="k-page-head">
        <div>
            <span class="k-pill"><?= icono('book') ?>Tu biblioteca</span>
            <h1 class="k-title mt-3">Mis recetas</h1>
            <p class="k-subtitle"><?= count($recetas) === 1 ? '1 receta guardada' : count($recetas) . ' recetas guardadas' ?>. Úsalas para llenar tu plan semanal.</p>
        </div>
        <div class="flex gap-2">
            <a href="crear_receta.php" class="k-btn k-btn-secondary"><?= icono('pencil') ?>Escribir receta</a>
            <a href="generar_receta.php" class="k-btn k-btn-accent"><?= icono('sparkles') ?>Generar con IA</a>
        </div>
    </div>

    <?php if ($mensaje): ?>
        <div class="k-alert k-alert-<?= $mensajeTipo ?> mb-6"><?= icono($mensajeTipo === 'ok' ? 'check' : 'alert') ?><p><?= e($mensaje) ?></p></div>
    <?php endif; ?>

    <?php if (empty($recetas)): ?>
        <div class="k-empty">
            <span class="k-empty-icon"><?= icono('book') ?></span>
            <p class="font-medium k-strong">Todavía no tienes recetas</p>
            <p class="mt-1 text-sm k-muted">Genera una con IA a partir de tu despensa y tus objetivos, o escribe la tuya.</p>
            <a href="generar_receta.php" class="k-btn k-btn-accent mt-5"><?= icono('sparkles') ?>Generar mi primera receta</a>
        </div>
    <?php else: ?>
        <!-- RESUMEN -->
        <div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <?php foreach ([['Recetas', count($recetas), '', 'book', ''],
                            ['Creadas con IA', $recetasIa, 'de ' . count($recetas), 'sparkles', 'k-tile-indigo'],
                            ['Media por ración', number_format($mediaKcalRecetas, 0, ',', '.'), 'kcal', 'flame', 'k-tile-orange'],
                            ['En tu plan', $enPlanDistintas, 'de ' . count($recetas), 'calendar', 'k-tile-blue']] as [$etiqueta, $valor, $unidad, $ic, $tinte]): ?>
                <div class="k-card flex items-center gap-4 p-5">
                    <span class="hidden sm:grid k-icon-tile <?= $tinte ?>"><?= icono($ic) ?></span>
                    <div><p class="k-stat-label"><?= $etiqueta ?></p><p class="k-stat-value"><?= $valor ?><?php if ($unidad): ?><span class="k-stat-unit"><?= $unidad ?></span><?php endif; ?></p></div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- BUSCADOR Y FILTROS -->
        <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <label class="k-search md:w-80">
                <?= icono('search') ?>
                <span class="sr-only">Buscar receta</span>
                <input type="search" id="recipeSearch" class="k-input" placeholder="Buscar por nombre…">
            </label>
            <div class="k-seg" role="group" aria-label="Filtrar por tipo de comida">
                <?php foreach (['todos' => 'Todas', 'desayuno' => 'Desayuno', 'almuerzo' => 'Almuerzo', 'snack' => 'Snack', 'cena' => 'Cena'] as $filtro => $texto): ?>
                    <button type="button" data-filtro="<?= $filtro ?>" aria-pressed="<?= $filtro === 'todos' ? 'true' : 'false' ?>" onclick="filtrarPor('<?= $filtro ?>')"><?= $texto ?></button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- RECETAS -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($recetas as $receta):
                $estilo = estiloTipoReceta($receta['tipo_comida']); ?>
                <a href="detalle_receta.php?id=<?= (int) $receta['id_receta'] ?>"
                   class="recipe-card k-card k-card-link group flex flex-col overflow-hidden"
                   data-tipo="<?= e($estilo['filtro']) ?>" data-titulo="<?= e(mb_strtolower($receta['titulo'], 'UTF-8')) ?>">
                    <div class="<?= $estilo['tinte'] ?> relative grid h-28 place-items-center text-5xl" aria-hidden="true">
                        <span class="transition-transform duration-300 group-hover:scale-110"><?= $estilo['emoji'] ?></span>
                        <?php if (!empty($receta['generado_ia'])): ?>
                            <span class="k-badge absolute right-3 top-3 text-[11px]" style="background-color: var(--k-surface);"><?= icono('sparkles', 'h-3 w-3') ?>IA</span>
                        <?php endif; ?>
                        <?php if (!empty($usoEnPlan[$receta['id_receta']])): ?>
                            <span class="k-badge absolute left-3 top-3 text-[11px]" style="background-color: var(--k-surface);"><?= icono('calendar', 'h-3 w-3') ?><?= (int) $usoEnPlan[$receta['id_receta']] ?>× en el plan</span>
                        <?php endif; ?>
                    </div>
                    <div class="flex flex-1 flex-col p-5">
                        <p class="text-xs font-medium k-muted"><?= e($estilo['nombre']) ?></p>
                        <h2 class="mt-1 line-clamp-2 font-display text-base font-semibold leading-snug k-strong"><?= e($receta['titulo']) ?></h2>
                        <div class="mt-auto flex flex-wrap items-center gap-x-4 gap-y-1 pt-4 text-xs tabular-nums k-muted">
                            <span class="flex items-center gap-1 font-semibold k-strong"><?= icono('flame', 'h-3.5 w-3.5 text-orange-500') ?><?= round($receta['calorias_por_racion']) ?> kcal</span>
                            <span class="k-macro-p flex items-center gap-1.5"><span class="k-macro-dot h-2 w-2"></span><?= round($receta['proteinas_g_por_racion']) ?> g</span>
                            <span class="k-macro-c flex items-center gap-1.5"><span class="k-macro-dot h-2 w-2"></span><?= round($receta['carbohidratos_g_por_racion']) ?> g</span>
                            <span class="k-macro-f flex items-center gap-1.5"><span class="k-macro-dot h-2 w-2"></span><?= round($receta['grasas_g_por_racion']) ?> g</span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <div id="noResults" class="k-empty mt-4" hidden>
            <span class="k-empty-icon"><?= icono('search') ?></span>
            <p class="font-medium k-strong">Ninguna receta coincide</p>
            <p class="mt-1 text-sm k-muted">Prueba con otro nombre o quita el filtro.</p>
        </div>
    <?php endif; ?>
</div>

<?php include_once __DIR__ . '/../templates/footer.php'; ?>
