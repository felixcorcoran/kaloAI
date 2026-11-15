<?php
/**
 * GESTOR DE PLANIFICACIÓN SEMANAL - KaloAI
 * Este módulo actúa como el panel de control donde se visualiza el calendario semanal,
 * se calculan los totales diarios y se comparan con la meta del usuario.
 */
$pageTitle = "KaloAI | Mi Plan Semanal";
    
session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php'; 

// SEGURIDAD: Validación de estado de sesión para evitar acceso a calendarios ajenos
if (!isset($_SESSION['user_id'])) { 
    header("Location: ../auth/login.php"); 
    exit; 
}

$userId = $_SESSION['user_id'];
$pdo = connectDB();

/**
 * 1. LÓGICA DE ELIMINACIÓN (Mantenimiento del Plan)
 * Permite al usuario retirar recetas específicas de su calendario.
 * Se incluye un JOIN de seguridad para asegurar que el registro pertenece al usuario actual.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_id'])) {
    $idComida = intval($_POST['eliminar_id']);
    try {
        $sqlDelete = "DELETE cp FROM comida_planificada cp 
                      JOIN plan_semanal ps ON cp.id_plan = ps.id_plan 
                      WHERE cp.id_comida_planificada = ? AND ps.id_usuario = ?";
        $stmtDel = $pdo->prepare($sqlDelete);
        $stmtDel->execute([$idComida, $userId]);
        
        // Redirección limpia para evitar reenvíos de formulario
        header("Location: planificacion_semanal.php?deleted=1");
        exit;
    } catch (Exception $e) { 
        $error = "No se pudo eliminar la comida del plan."; 
    }
}

/**
 * 2. OBJETIVOS DIARIOS
 * Los mismos que muestra el panel: calorías netas según el objetivo y los macros
 * calculados en el perfil. Sin perfil completo, 2000 kcal con un reparto estándar.
 */
$stmtPerfil = $pdo->prepare("SELECT calorias_netas_objetivo, proteinas_objetivo_g, carbohidratos_objetivo_g, grasas_objetivo_g FROM perfil WHERE id_usuario = ?");
$stmtPerfil->execute([$userId]);
$perfil = $stmtPerfil->fetch() ?: [];

$metaKcal = (float) ($perfil['calorias_netas_objetivo'] ?? 0) ?: 2000;
$metaP = (float) ($perfil['proteinas_objetivo_g'] ?? 0) ?: ($metaKcal * 0.30) / 4;
$metaC = (float) ($perfil['carbohidratos_objetivo_g'] ?? 0) ?: ($metaKcal * 0.40) / 4;
$metaG = (float) ($perfil['grasas_objetivo_g'] ?? 0) ?: ($metaKcal * 0.30) / 9;

/**
 * 3. OBTENCIÓN Y ESTRUCTURACIÓN DEL PLAN (Motor de Cómputo)
 * Consulta las recetas asignadas a cada día y momento del día (Desayuno, Cena, etc.).
 */
$sql = "SELECT cp.*, r.*, 
               CASE cp.dia_semana 
                    WHEN 0 THEN 'Lunes' WHEN 1 THEN 'Martes' WHEN 2 THEN 'Miércoles' 
                    WHEN 3 THEN 'Jueves' WHEN 4 THEN 'Viernes' WHEN 5 THEN 'Sábado' WHEN 6 THEN 'Domingo' 
               END as dia_nombre
        FROM comida_planificada cp
        JOIN receta r ON cp.id_receta = r.id_receta
        JOIN plan_semanal ps ON cp.id_plan = ps.id_plan
        WHERE ps.id_usuario = ?"; 

$stmt = $pdo->prepare($sql);
$stmt->execute([$userId]);
$comidas = $stmt->fetchAll();

// Matrices para organizar la visualización y el sumatorio acumulado
$plan = [];
$totalesDia = [];

foreach ($comidas as $c) {
    $dia = $c['dia_nombre'];
    $momento = trim($c['momento_comida']); 
    
    // Agrupamos por [Día][Momento] para el renderizado del grid en el frontend
    $plan[$dia][$momento] = $c;
    
    /**
     * ACUMULADOR NUTRICIONAL:
     * Suma las calorías y gramos de cada receta para dar un total diario.
     */
    if(!isset($totalesDia[$dia])) {
        $totalesDia[$dia] = ['kcal'=>0, 'p'=>0, 'c'=>0, 'g'=>0];
    }
    $raciones = (float) ($c['raciones'] ?? 1);
    $totalesDia[$dia]['kcal'] += $c['calorias_por_racion'] * $raciones;
    $totalesDia[$dia]['p'] += $c['proteinas_g_por_racion'] * $raciones;
    $totalesDia[$dia]['c'] += $c['carbohidratos_g_por_racion'] * $raciones;
    $totalesDia[$dia]['g'] += $c['grasas_g_por_racion'] * $raciones;
}

// Estructuras de control para la generación de la tabla/interfaz
$dias = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
$todasComidas = ['Desayuno', 'Media Mañana', 'Almuerzo', 'Merienda', 'Cena'];

// Carga del componente de cabecera 
include_once __DIR__ . '/../templates/header.php';
?>
<?php
$diasEsp = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$hoyNombre = $diasEsp[date('w')];
// Resumen de la semana
$numComidas = count($comidas);
$diasConComida = array_filter($totalesDia, fn($t) => $t['kcal'] > 0);
$mediaKcal = $diasConComida ? array_sum(array_column($diasConComida, 'kcal')) / count($diasConComida) : 0;
$mediaProt = $diasConComida ? array_sum(array_column($diasConComida, 'p')) / count($diasConComida) : 0;
$diasEnObjetivo = $metaKcal > 0 ? count(array_filter($totalesDia, fn($t) => $t['kcal'] >= $metaKcal * 0.9 && $t['kcal'] <= $metaKcal * 1.1)) : 0;
$alertas = [
    'deleted'  => ['ok', 'Comida quitada del plan.'],
    'success'  => ['ok', 'Plan actualizado.'],
];
?>

<div class="k-container k-page">
    <div class="k-page-head">
        <div>
            <span class="k-pill"><?= icono('calendar') ?>Lunes a domingo</span>
            <h1 class="k-title mt-3">Plan semanal</h1>
            <p class="k-subtitle">Toca una comida para ver la receta, o el día para ver su resumen.</p>
        </div>
        <!-- Objetivos diarios -->
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="k-badge k-badge-brand"><?= icono('target', 'h-3.5 w-3.5') ?><?= number_format($metaKcal, 0, ',', '.') ?> kcal/día</span>
            <span class="k-badge k-macro-p"><span class="k-macro-dot"></span>P <?= round($metaP) ?> g</span>
            <span class="k-badge k-macro-c"><span class="k-macro-dot"></span>C <?= round($metaC) ?> g</span>
            <span class="k-badge k-macro-f"><span class="k-macro-dot"></span>G <?= round($metaG) ?> g</span>
        </div>
    </div>

    <?php foreach ($alertas as $clave => [$tipo, $texto]): if (isset($_GET[$clave])): ?>
        <div class="k-alert k-alert-<?= $tipo ?> mb-6"><?= icono('check') ?><p><?= e($texto) ?></p></div>
    <?php endif; endforeach; ?>
    <?php if (($_GET['error'] ?? '') === 'limite_superado'): ?>
        <div class="k-alert k-alert-warn mb-6"><?= icono('alert') ?><p>Ese día ya supera tu objetivo de calorías en más de un 10 %. Quita alguna comida antes de añadir otra.</p></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="k-alert k-alert-error mb-6"><?= icono('alert') ?><p><?= e($error) ?></p></div>
    <?php endif; ?>

    <!-- RESUMEN DE LA SEMANA -->
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="k-card flex flex-col p-5">
            <div class="flex items-center justify-between"><p class="k-stat-label">Comidas planificadas</p><span class="k-icon-tile h-8 w-8 rounded-lg k-tile-indigo"><?= icono('utensils', 'h-4 w-4') ?></span></div>
            <p class="k-stat-value mt-3"><?= $numComidas ?><span class="k-stat-unit">/ 35</span></p>
            <div class="k-track mt-auto h-1.5"><div class="k-bar" style="width: <?= min(100, round($numComidas / 35 * 100)) ?>%"></div></div>
        </div>
        <div class="k-card flex flex-col p-5">
            <div class="flex items-center justify-between"><p class="k-stat-label">Media diaria</p><span class="k-icon-tile h-8 w-8 rounded-lg k-tile-orange"><?= icono('flame', 'h-4 w-4') ?></span></div>
            <p class="k-stat-value mt-3"><?= number_format($mediaKcal, 0, ',', '.') ?><span class="k-stat-unit">kcal</span></p>
            <p class="mt-auto pt-2 text-xs k-muted"><?= $metaKcal ? round($mediaKcal / $metaKcal * 100) . '% de tu objetivo, en los días con comidas' : 'Completa tu perfil para ver tu objetivo' ?></p>
        </div>
        <div class="k-card flex flex-col p-5">
            <div class="flex items-center justify-between"><p class="k-stat-label">Proteína media</p><span class="k-icon-tile h-8 w-8 rounded-lg k-tile-blue"><?= icono('activity', 'h-4 w-4') ?></span></div>
            <p class="k-stat-value mt-3"><?= round($mediaProt) ?><span class="k-stat-unit">/ <?= round($metaP) ?> g</span></p>
            <div class="k-macro-p k-track mt-auto h-1.5"><div class="k-bar k-macro-bar" style="width: <?= $metaP > 0 ? min(100, round($mediaProt / $metaP * 100)) : 0 ?>%"></div></div>
        </div>
        <div class="k-card flex flex-col p-5">
            <div class="flex items-center justify-between"><p class="k-stat-label">Días en su objetivo</p><span class="k-icon-tile h-8 w-8 rounded-lg"><?= icono('target', 'h-4 w-4') ?></span></div>
            <p class="k-stat-value mt-3"><?= $diasEnObjetivo ?><span class="k-stat-unit">/ 7</span></p>
            <div class="mt-auto flex items-end gap-1.5 pt-3" aria-label="Calorías de cada día frente al objetivo">
                <?php foreach ($dias as $dia):
                    $kcalDia = $totalesDia[$dia]['kcal'] ?? 0;
                    $alto = $metaKcal > 0 ? max(8, min(100, round($kcalDia / $metaKcal * 100))) : 8;
                    $enRango = $metaKcal > 0 && $kcalDia >= $metaKcal * 0.9 && $kcalDia <= $metaKcal * 1.1; ?>
                    <a href="#dia-<?= e(mb_strtolower($dia, 'UTF-8')) ?>" class="flex flex-1 flex-col items-center gap-1" title="<?= e($dia) ?>: <?= number_format($kcalDia, 0, ',', '.') ?> kcal">
                        <span class="flex h-8 w-full items-end overflow-hidden rounded" style="background-color: var(--k-surface-2);"><span class="block w-full rounded" style="height: <?= $alto ?>%; background-color: <?= $kcalDia > 0 ? ($enRango ? 'var(--k-brand)' : 'var(--k-indigo)') : 'transparent' ?>;"></span></span>
                        <span class="text-[10px] k-muted"><?= e(mb_substr($dia, 0, 1)) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="space-y-4">
        <?php foreach ($dias as $dia):
            $t = $totalesDia[$dia] ?? ['kcal' => 0, 'p' => 0, 'c' => 0, 'g' => 0];
            $pct = $metaKcal > 0 ? ($t['kcal'] / $metaKcal) * 100 : 0;
            $exceso = $pct > 110;
            $esHoy = $dia === $hoyNombre; ?>
            <section id="dia-<?= e(mb_strtolower($dia, 'UTF-8')) ?>" class="k-card scroll-mt-6 overflow-hidden <?= $esHoy ? 'ring-2 ring-teal-500/40' : '' ?>">
                <!-- Cabecera del día: abre el resumen -->
                <button type="button" onclick='abrirResumenDia(<?= json_html($dia) ?>, <?= json_html($t) ?>)'
                        class="flex w-full flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5 text-left transition hover:bg-[color:var(--k-surface-2)] sm:px-6">
                    <span class="flex items-center gap-2">
                        <span class="font-display text-base font-semibold k-strong"><?= e($dia) ?></span>
                        <?php if ($esHoy): ?><span class="k-badge k-badge-brand">Hoy</span><?php endif; ?>
                    </span>
                    <span class="ml-auto flex min-w-[12rem] flex-1 items-center gap-3 sm:max-w-xs">
                        <span class="k-track flex-1"><span class="k-bar block <?= $exceso ? 'k-bar-over' : '' ?>" style="width: <?= min(100, round($pct)) ?>%"></span></span>
                        <span class="w-24 text-right text-xs tabular-nums <?= $exceso ? 'text-red-600' : 'k-muted' ?>"><?= number_format($t['kcal'], 0, ',', '.') ?> kcal</span>
                    </span>
                    <?= icono('chevron-right', 'h-4 w-4 k-muted') ?>
                </button>

                <!-- Comidas del día -->
                <div class="grid gap-2 border-t p-3 sm:grid-cols-5 sm:p-4" style="border-color: var(--k-border);">
                    <?php foreach ($todasComidas as $m): ?>
                        <?php if (isset($plan[$dia][$m])): $c = $plan[$dia][$m]; ?>
                            <div class="group relative">
                                <button type="button" onclick='abrirReceta(<?= json_html($c) ?>)'
                                        class="k-subtle flex h-full w-full flex-col rounded-xl border border-transparent p-3 text-left transition hover:border-teal-500/40">
                                    <span class="text-[11px] font-semibold uppercase tracking-wider k-muted"><?= e($m) ?></span>
                                    <span class="mt-1 line-clamp-2 pr-5 text-sm font-medium leading-snug k-strong"><?= e($c['titulo']) ?></span>
                                    <span class="mt-auto pt-2 text-xs tabular-nums k-muted"><?= round($c['calorias_por_racion']) ?> kcal</span>
                                </button>
                                <button type="button" onclick="confirmarEliminarComida(<?= (int) $c['id_comida_planificada'] ?>)"
                                        class="absolute right-1.5 top-1.5 grid h-7 w-7 place-items-center rounded-lg opacity-60 transition hover:bg-red-500/10 hover:text-red-600 hover:opacity-100 k-muted"
                                        aria-label="Quitar <?= e($c['titulo']) ?> del <?= e($dia) ?>">
                                    <?= icono('x', 'h-4 w-4') ?>
                                </button>
                            </div>
                        <?php elseif ($exceso): ?>
                            <div class="flex flex-col justify-center rounded-xl border border-dashed p-3 text-xs k-muted" style="border-color: var(--k-border-strong);">
                                <span class="font-semibold uppercase tracking-wider"><?= e($m) ?></span>
                                <span class="mt-1">Límite de calorías</span>
                            </div>
                        <?php else: ?>
                            <a href="generar_receta.php?dia=<?= urlencode($dia) ?>&momento=<?= urlencode($m) ?>"
                               class="flex flex-col justify-center rounded-xl border border-dashed p-3 text-xs transition hover:border-teal-500/60 hover:text-teal-700 k-muted" style="border-color: var(--k-border-strong);">
                                <span class="font-semibold uppercase tracking-wider"><?= e($m) ?></span>
                                <span class="mt-1 flex items-center gap-1"><?= icono('plus', 'h-3.5 w-3.5') ?>Añadir</span>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</div>

<!-- MODAL: DETALLE DE UNA COMIDA -->
<div id="modalReceta" class="k-modal" hidden role="dialog" aria-modal="true" aria-labelledby="mTit">
    <div class="k-modal-backdrop"></div>
    <div class="k-modal-panel max-w-lg">
        <button type="button" class="k-btn k-btn-ghost k-btn-icon k-modal-close" data-cerrar-modal aria-label="Cerrar"><?= icono('x') ?></button>
        <p id="mMom" class="k-eyebrow"></p>
        <h2 id="mTit" class="k-modal-title mt-1 pr-8"></h2>
        <div id="mMacros" class="mt-5 grid grid-cols-2 gap-2 sm:grid-cols-4"></div>
        <h3 class="mt-6 text-sm font-semibold k-strong">Preparación</h3>
        <div class="custom-scrollbar mt-2 max-h-56 overflow-y-auto pr-1">
            <p id="mDesc" class="whitespace-pre-line text-sm leading-relaxed k-muted"></p>
        </div>
        <div class="mt-6 flex justify-end">
            <a id="mVer" href="#" class="k-btn k-btn-secondary">Ver receta completa <?= icono('arrow-right') ?></a>
        </div>
    </div>
</div>

<!-- MODAL: RESUMEN DE UN DÍA -->
<div id="modalDia" class="k-modal" hidden role="dialog" aria-modal="true" aria-labelledby="mDiaTit">
    <div class="k-modal-backdrop"></div>
    <div class="k-modal-panel">
        <button type="button" class="k-btn k-btn-ghost k-btn-icon k-modal-close" data-cerrar-modal aria-label="Cerrar"><?= icono('x') ?></button>
        <p class="k-eyebrow">Resumen del día</p>
        <h2 id="mDiaTit" class="k-modal-title mt-1"></h2>
        <div class="k-subtle mt-5 rounded-xl p-4">
            <div class="mb-2 flex items-center justify-between text-sm">
                <span class="font-medium k-strong">Calorías</span>
                <span id="mDiaKcalText" class="tabular-nums k-muted"></span>
            </div>
            <div class="k-track h-2.5" style="background-color: var(--k-surface);"><div id="mDiaKcalBar" class="k-bar"></div></div>
        </div>
        <div id="mDiaMacrosList" class="mt-5 space-y-4"></div>
        <p class="mt-6 text-xs k-muted">Comparado con tus objetivos diarios del perfil.</p>
    </div>
</div>

<!-- MODAL: QUITAR UNA COMIDA -->
<div id="modalEliminar" class="k-modal" hidden role="dialog" aria-modal="true" aria-labelledby="tituloQuitar">
    <div class="k-modal-backdrop"></div>
    <div class="k-modal-panel max-w-sm">
        <h2 id="tituloQuitar" class="k-modal-title">¿Quitar esta comida del plan?</h2>
        <p class="mt-2 text-sm k-muted">La receta seguirá en tu biblioteca; solo se libera el hueco.</p>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="k-btn k-btn-secondary" data-cerrar-modal>Cancelar</button>
            <button type="button" id="btnConfirmarEliminar" class="k-btn k-btn-danger" data-confirmar>Quitar</button>
        </div>
    </div>
</div>

<script>
    // Objetivos diarios para el resumen de cada día (main.js)
    window.planData = { meta: { kcal: <?= (float) $metaKcal ?>, p: <?= (float) $metaP ?>, c: <?= (float) $metaC ?>, g: <?= (float) $metaG ?> } };
</script>

<?php include_once __DIR__ . '/../templates/footer.php'; ?>
