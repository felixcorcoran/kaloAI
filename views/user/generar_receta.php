<?php
/**
 * LÓGICA DE CONTROL - CHEF IA (KaloAI)
 * Gestiona la generación inteligente de recetas, validación de límites calóricos 
 * y la asignación de platos al plan semanal.
 */

session_start();

// Carga de dependencias centrales
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php'; // incluye la comprobación del token CSRF
require_once __DIR__ . '/../../core/generar_recetas_ia.php'; // Motor de IA (Gemini)
require_once __DIR__ . '/../../core/recetas.php';

// 1. SEGURIDAD Y CONTROL DE ACCESO
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$pdo = connectDB(); 

$receta = null;
$errorIA = null;

// 2. CAPTURA DE CONTEXTO (Proveniente del calendario o navegación directa)
// Se identifica para qué día y momento (Desayuno, Almuerzo...) se está trabajando

// Solo se aceptan días y momentos conocidos; cualquier otro valor se ignora
$diaUrl = $_GET['dia'] ?? $_POST['dia_contexto'] ?? null;
$diaUrl = diaANumero($diaUrl) !== null ? DIAS_SEMANA[diaANumero($diaUrl)] : null;
$momentoUrl = momentoValido($_GET['momento'] ?? $_POST['momento_contexto'] ?? null);
// Semana del plan en la que se trabaja (lunes); por defecto, la actual
$semanaUrl = semanaValida($_GET['semana'] ?? $_POST['semana_contexto'] ?? null);
$volverPlan = 'planificacion_semanal.php' . ($semanaUrl !== lunesDe() ? '?semana=' . $semanaUrl : '');
$conSemana = fn(string $url) => $url . (str_contains($url, '?') ? '&' : '?') . 'semana=' . $semanaUrl;

/* ==========================================================================
   3. VALIDACIÓN DE LÍMITE CALÓRICO
   Evita que el usuario añada más comida si ya superó su meta diaria (Margen 10%)
   ========================================================================== */
if ($diaUrl) {
    $diaNum = diaANumero($diaUrl);

    // Sumar calorías ya planificadas para el día seleccionado
    $stmtCheck = $pdo->prepare("SELECT SUM(r.calorias_por_racion) as total 
                                FROM comida_planificada cp 
                                JOIN receta r ON cp.id_receta = r.id_receta 
                                JOIN plan_semanal ps ON cp.id_plan = ps.id_plan
                                WHERE ps.id_usuario = ? AND ps.fecha_inicio_semana = ? AND cp.dia_semana = ?");
    $stmtCheck->execute([$userId, $semanaUrl, $diaNum]);
    $actual = $stmtCheck->fetch();
    $caloriasActuales = $actual['total'] ?? 0;

    // Obtener la meta TDEE (Gasto calórico total) del perfil
    $stmtMeta = $pdo->prepare("SELECT COALESCE(calorias_netas_objetivo, tdee_calorias) FROM perfil WHERE id_usuario = ?");
    $stmtMeta->execute([$userId]);
    $meta = (float) ($stmtMeta->fetchColumn() ?: 2000);

    // Si excede el 110% de la meta, bloqueamos la generación para proteger el objetivo
    if ($caloriasActuales >= ($meta * 1.1)) {
        header("Location: " . $conSemana('planificacion_semanal.php?error=limite_superado'));
        exit;
    }
}

/* ==========================================================================
   4. PROCESAR SOLICITUD IA
   Llamada al motor de IA con los parámetros de personalización
   ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generar'])) {
    // Modo estricto: Solo ingredientes en la despensa del usuario
    $soloInventario = isset($_POST['modo_generacion']) && $_POST['modo_generacion'] === 'estricto';
    
    // Texto libre del usuario (ej: "Sin lactosa", "Muy picante")
    $sugerencia = mb_substr(trim((string) ($_POST['sugerencia_ia'] ?? '')), 0, 300);

    // Determinar el tipo de comida final
    $momentoFinal = $momentoUrl ?: tipoRecetaParaMomento($_POST['tipo_comida_manual'] ?? 'Almuerzo');

    // Ejecución de la IA
    $resultado = generarRecetaInteligente($userId, $soloInventario, $diaUrl, $momentoFinal, $sugerencia, null, null, $semanaUrl);

    if (isset($resultado['error'])) {
        $errorIA = $resultado['error'];
    } else {
        $receta = $resultado; // Array con titulo, ingredientes, instrucciones y macros
        // La receta se queda en el servidor hasta que la persona decide guardarla.
        // Antes viajaba en un campo oculto y se podía modificar antes de guardarla.
        $_SESSION['receta_ia_pendiente'] = [
            'receta'  => $receta,
            'dia'     => $diaUrl,
            'semana'  => $semanaUrl,
            'momento' => $momentoUrl ?: $momentoFinal,
        ];
    }
}

/* ==========================================================================
   5. BIBLIOTECA DE RECETAS PROPIAS
   Filtra las recetas guardadas del usuario que coinciden con el tipo buscado
   ========================================================================== */
$tipoBuscado = 'Almuerzo';
$momentoParaFiltrar = $momentoUrl ?: tipoRecetaParaMomento($_POST['tipo_comida_manual'] ?? 'Almuerzo');
// Todo lo que va entre horas se guarda como 'Snack'
$tipoBuscado = tipoRecetaParaMomento($momentoParaFiltrar);

$stmtRecetas = $pdo->prepare("SELECT * FROM receta WHERE id_usuario_creador = ? AND tipo_comida = ? ORDER BY favorita DESC, titulo ASC");
$stmtRecetas->execute([$userId, $tipoBuscado]);
$misRecetas = $stmtRecetas->fetchAll();

/* ==========================================================================
   6. ASIGNACIÓN DE RECETA EXISTENTE
   Gestiona la transacción para guardar una receta de la biblioteca en el plan
   ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['seleccionar_existente'])) {
    $idRecetaSel = filter_var($_POST['id_receta_seleccionada'] ?? null, FILTER_VALIDATE_INT);
    $diaNumero = diaANumero($diaUrl);

    try {
        $pdo->beginTransaction();
        // asignarRecetaAlPlan comprueba que la receta sea de esta persona y que
        // el día y el momento existan; crea el plan si aún no lo tiene
        $asignada = $idRecetaSel && $diaNumero !== null && $momentoUrl
            && asignarRecetaAlPlan($pdo, (int) $userId, $idRecetaSel, $diaNumero, $momentoUrl, $semanaUrl);
        $pdo->commit();

        if ($asignada) {
            header("Location: " . $conSemana('planificacion_semanal.php?success=assigned'));
            exit;
        }
        $errorIA = "No se pudo añadir esa receta al plan.";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Error al asignar receta al plan: " . $e->getMessage());
        $errorIA = "No se pudo añadir la receta al plan. Inténtalo de nuevo.";
    }
}

// INICIO DE LA VISTA (FRONTEND)
$pageTitle = "KaloAI | Generar receta con IA";

// Carga del componente de cabecera 
include_once __DIR__ . '/../templates/header.php';
?>
<?php
// Contexto: si se viene de un hueco del plan, la receta irá a ese día y momento
$enPlan = $diaUrl && $momentoUrl;

// Lo que la IA tendrá en cuenta: objetivo por comida, alergias y despensa
$stmtCtx = $pdo->prepare("SELECT COALESCE(calorias_netas_objetivo, tdee_calorias) FROM perfil WHERE id_usuario = ?");
$stmtCtx->execute([$userId]);
$metaDiaria = (int) ($stmtCtx->fetchColumn() ?: 0);
$stmtCtx = $pdo->prepare("SELECT nombre_ingrediente, nota FROM ingrediente_prohibido WHERE id_usuario = ? ORDER BY nombre_ingrediente");
$stmtCtx->execute([$userId]);
$prohibidosCtx = $stmtCtx->fetchAll();
$stmtCtx = $pdo->prepare("SELECT COUNT(*) FROM inventario WHERE id_usuario = ?");
$stmtCtx->execute([$userId]);
$enDespensa = (int) $stmtCtx->fetchColumn();
$momentoActivo = $enPlan ? $momentoUrl : $tipoBuscado;
$objetivosMomento = [];
foreach (['Desayuno', 'Almuerzo', 'Snack', 'Cena'] as $m) {
    $objetivosMomento[$m] = $metaDiaria ? objetivoRacion($metaDiaria, $m) : null;
}
if ($enPlan && $metaDiaria) {
    $objetivosMomento = [$momentoUrl => objetivoRacion($metaDiaria, $momentoUrl, (float) ($caloriasActuales ?? 0))];
}
?>

<div class="k-container k-page">
    <a href="<?= $diaUrl ? e($volverPlan) : 'mis_recetas.php' ?>" class="k-back">
        <?= icono('chevron-left', 'h-4 w-4') ?><?= $diaUrl ? 'Plan semanal' : 'Mis recetas' ?>
    </a>
    <div class="k-page-head">
        <div>
            <span class="k-pill"><?= icono('sparkles') ?><?= $enPlan ? e($momentoUrl . ' · ' . $diaUrl . ($semanaUrl !== lunesDe() ? ' · ' . nombreSemana($semanaUrl) : '')) : 'Chef IA' ?></span>
            <h1 class="k-title mt-3">Generar receta con IA</h1>
            <p class="k-subtitle">
                <?php if ($enPlan): ?>
                    Para el <strong class="k-strong"><?= e(mb_strtolower($momentoUrl, 'UTF-8')) ?></strong> del <strong class="k-strong"><?= e(mb_strtolower($diaUrl, 'UTF-8')) ?></strong>. Se ajusta a las calorías que te quedan ese día y respeta tus alergias.
                <?php else: ?>
                    Una receta para una ración, ajustada a tus objetivos y sin los alimentos que tienes marcados como alergia o intolerancia.
                <?php endif; ?>
            </p>
        </div>
    </div>

    <?php if ($errorIA): ?>
        <div class="k-alert k-alert-error mb-6"><?= icono('alert') ?><p><?= e($errorIA) ?></p></div>
    <?php endif; ?>

    <div class="grid items-start gap-6 lg:grid-cols-5">
        <!-- FORMULARIO -->
        <form id="formGeneradorIA" method="POST" class="k-card k-card-pad space-y-6 lg:sticky lg:top-24 lg:col-span-2">
            <?= campoCsrf() ?>
            <input type="hidden" name="dia_contexto" value="<?= e($diaUrl) ?>">
            <input type="hidden" name="semana_contexto" value="<?= e($semanaUrl) ?>">
            <input type="hidden" name="momento_contexto" value="<?= e($momentoUrl) ?>">

            <?php if (!$momentoUrl): ?>
                <fieldset>
                    <legend class="k-label">¿Para qué comida?</legend>
                    <div class="grid grid-cols-2 gap-2">
                        <?php foreach (['Desayuno', 'Almuerzo', 'Snack', 'Cena'] as $nombre):
                            $est = estiloTipoReceta($nombre); ?>
                            <label class="block">
                                <input type="radio" name="tipo_comida_manual" value="<?= e($nombre) ?>" class="peer sr-only" <?= $tipoBuscado === $nombre ? 'checked' : '' ?>>
                                <span class="k-choice"><span aria-hidden="true"><?= $est['emoji'] ?></span><?= e($nombre) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            <?php endif; ?>

            <div>
                <label for="sugerencia_ia" class="k-label">¿Te apetece algo en concreto? <span class="font-normal k-muted">(opcional)</span></label>
                <textarea id="sugerencia_ia" name="sugerencia_ia" rows="3" maxlength="300" class="k-input" placeholder="Ej. algo con pollo y un poco picante"><?= e($_POST['sugerencia_ia'] ?? '') ?></textarea>
            </div>

            <fieldset>
                <legend class="k-label">Ingredientes</legend>
                <div class="space-y-2">
                    <?php $modo = $_POST['modo_generacion'] ?? ($_GET['modo'] ?? 'flexible'); ?>
                    <label class="block">
                        <input type="radio" name="modo_generacion" value="flexible" class="peer sr-only" <?= $modo !== 'estricto' ? 'checked' : '' ?>>
                        <span class="k-choice"><?= icono('cart', 'h-4 w-4 shrink-0') ?><span><span class="block k-strong">Lo que haga falta</span><span class="block text-xs font-normal k-muted">Prioriza tu despensa y añade lo necesario</span></span></span>
                    </label>
                    <label class="block">
                        <input type="radio" name="modo_generacion" value="estricto" class="peer sr-only" <?= $modo === 'estricto' ? 'checked' : '' ?>>
                        <span class="k-choice"><?= icono('package', 'h-4 w-4 shrink-0') ?><span><span class="block k-strong">Solo mi despensa</span><span class="block text-xs font-normal k-muted">Sin comprar nada</span></span></span>
                    </label>
                </div>
            </fieldset>

            <button type="submit" name="generar" class="k-btn k-btn-accent k-btn-lg w-full"><?= icono('sparkles') ?><?= $receta ? 'Generar otra' : 'Generar receta' ?></button>
        </form>

        <div class="space-y-6 lg:col-span-3">
            <?php if ($receta): ?>
                <!-- RECETA GENERADA -->
                <section class="k-card overflow-hidden">
                    <div class="k-card-pad">
                        <p class="k-eyebrow flex items-center gap-1.5"><?= icono('sparkles', 'h-3.5 w-3.5') ?>Propuesta de KaloAI</p>
                        <h2 class="k-title mt-1 text-xl sm:text-2xl"><?= e($receta['titulo']) ?></h2>
                        <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <div class="k-macro-chip k-subtle"><p class="k-stat-label">Calorías</p><p class="font-display text-lg font-bold k-strong"><?= e(round((float) $receta['calorias'])) ?><span class="k-stat-unit">kcal</span></p></div>
                            <?php foreach ([['k-macro-p', 'Proteína', 'proteinas'], ['k-macro-c', 'Carbohidratos', 'carbohidratos'], ['k-macro-f', 'Grasa', 'grasas']] as [$clase, $etiqueta, $clave]): ?>
                                <div class="k-macro-chip <?= $clase ?>"><p class="k-stat-label"><?= $etiqueta ?></p><p class="font-display text-lg font-bold k-strong"><?= e(round((float) $receta['macros'][$clave])) ?><span class="k-stat-unit">g</span></p></div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="grid gap-6 border-t p-5 sm:p-6 md:grid-cols-2" style="border-color: var(--k-border);">
                        <div>
                            <h3 class="text-sm font-semibold k-strong">Ingredientes <span class="font-normal k-muted">· 1 ración</span></h3>
                            <ul class="mt-3 space-y-2 text-sm">
                                <?php foreach ($receta['ingredientes_usados'] as $ing): ?>
                                    <li class="flex justify-between gap-3"><span><?= e($ing['nombre']) ?></span><span class="shrink-0 tabular-nums k-muted"><?= e($ing['cantidad']) ?> <?= e($ing['unidad'] ?? '') ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold k-strong">Preparación</h3>
                            <ol class="mt-3 space-y-3 text-sm">
                                <?php foreach ($receta['instrucciones'] as $i => $paso): ?>
                                    <li class="flex gap-3"><span class="grid h-6 w-6 shrink-0 place-items-center rounded-full text-xs font-semibold" style="background-color: var(--k-brand-soft); color: var(--k-brand-text);"><?= $i + 1 ?></span><span class="pt-0.5 leading-relaxed"><?= e($paso) ?></span></li>
                                <?php endforeach; ?>
                            </ol>
                        </div>
                    </div>

                    <!-- La receta está guardada en la sesión del servidor; aquí solo se confirma -->
                    <form method="POST" action="guardar_receta_ia.php" class="k-subtle flex flex-col gap-3 border-t p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6" style="border-color: var(--k-border);">
                        <?= campoCsrf() ?>
                        <p class="text-sm k-muted">¿No te convence? Genera otra con el formulario.</p>
                        <button type="submit" name="save_recipe" class="k-btn k-btn-primary"><?= icono('check') ?><?= $diaUrl ? 'Guardar y añadir al plan' : 'Guardar en mis recetas' ?></button>
                    </form>
                </section>
            <?php else: ?>
                <div class="k-empty">
                    <span class="k-empty-icon"><?= icono('sparkles') ?></span>
                    <p class="font-medium k-strong">Tu receta aparecerá aquí</p>
                    <p class="mt-1 max-w-sm text-sm k-muted">Elige las opciones y pulsa «Generar receta». Tarda unos segundos.</p>
                </div>
            <?php endif; ?>

            <!-- LO QUE LA IA TIENE EN CUENTA -->
            <section class="k-card k-card-pad">
                <h2 class="k-section-title">Lo que la IA tendrá en cuenta</h2>
                <div class="mt-5 grid gap-6 md:grid-cols-2">
                    <div>
                        <p class="flex items-center gap-2 text-sm font-medium k-strong"><span class="k-icon-tile h-7 w-7 rounded-lg k-tile-orange"><?= icono('flame', 'h-3.5 w-3.5') ?></span><?= $enPlan ? 'Objetivo para esta comida' : 'Objetivo por comida' ?></p>
                        <?php if (!$metaDiaria): ?>
                            <p class="mt-3 text-sm k-muted">Completa tu <a href="perfil.php" class="k-link">perfil</a> para que ajuste las calorías.</p>
                        <?php else: ?>
                            <ul class="mt-3 space-y-1.5 text-sm">
                                <?php foreach ($objetivosMomento as $m => $obj): $activo = mb_strtolower($m, 'UTF-8') === mb_strtolower((string) $momentoActivo, 'UTF-8'); ?>
                                    <li class="flex items-center justify-between rounded-lg px-3 py-2 <?= $activo ? 'k-subtle' : '' ?>">
                                        <span class="<?= $activo ? 'font-medium k-strong' : 'k-muted' ?>"><?= estiloTipoReceta($m)['emoji'] ?> <?= e($m) ?></span>
                                        <span class="tabular-nums <?= $activo ? 'font-semibold k-strong' : 'k-muted' ?>"><?= $obj['kcal'] ?> kcal · <?= $obj['proteinas'] ?> g prot.</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <p class="mt-2 text-xs k-muted">Sobre tu objetivo de <?= number_format($metaDiaria, 0, ',', '.') ?> kcal al día<?= $enPlan ? ', descontando lo que ya tienes ese día' : '' ?>.</p>
                        <?php endif; ?>
                    </div>
                    <div class="space-y-5">
                        <div>
                            <p class="flex items-center gap-2 text-sm font-medium k-strong"><span class="k-icon-tile h-7 w-7 rounded-lg k-tile-danger"><?= icono('ban', 'h-3.5 w-3.5') ?></span>Nunca usará</p>
                            <?php if ($prohibidosCtx): ?>
                                <div class="mt-3 flex flex-wrap gap-1.5">
                                    <?php foreach ($prohibidosCtx as $pr): ?>
                                        <span class="k-badge <?= str_starts_with((string) $pr['nota'], 'Alergia') ? 'k-badge-danger' : 'k-badge-warn' ?>"><?= e(mb_convert_case($pr['nombre_ingrediente'], MB_CASE_TITLE, 'UTF-8')) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="mt-3 text-sm k-muted">No tienes alergias marcadas. <a href="perfil.php" class="k-link">Añadir</a></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <p class="flex items-center gap-2 text-sm font-medium k-strong"><span class="k-icon-tile h-7 w-7 rounded-lg"><?= icono('package', 'h-3.5 w-3.5') ?></span>Tu despensa</p>
                            <p class="mt-3 text-sm k-muted"><?= $enDespensa ? $enDespensa . ' ' . ($enDespensa === 1 ? 'ingrediente' : 'ingredientes') . ' que intentará aprovechar.' : 'Vacía: puedes añadir lo que tienes en casa.' ?> <a href="inventario.php" class="k-link">Ver</a></p>
                        </div>
                    </div>
                </div>
            </section>

            <?php if ($diaUrl): ?>
                <!-- O USAR UNA RECETA YA GUARDADA -->
                <section class="k-card">
                    <div class="k-card-head">
                        <div>
                            <h2 class="k-section-title">O usa una de tus recetas</h2>
                            <p class="text-sm k-muted"><?= e($tipoBuscado) ?> guardados</p>
                        </div>
                    </div>
                    <?php if (empty($misRecetas)): ?>
                        <p class="px-6 py-5 text-sm k-muted">Aún no tienes recetas de este tipo.</p>
                    <?php else: ?>
                        <ul class="k-list custom-scrollbar max-h-80 overflow-y-auto">
                            <?php foreach ($misRecetas as $r): ?>
                                <li class="flex items-center justify-between gap-4 px-5 py-3 sm:px-6">
                                    <div class="min-w-0">
                                        <p class="flex items-center gap-1.5 truncate text-sm font-medium k-strong"><?php if (!empty($r['favorita'])): ?><span class="k-fav" aria-pressed="true" title="Favorita"><?= icono('heart', 'h-3.5 w-3.5') ?></span><?php endif; ?><?= e($r['titulo']) ?></p>
                                        <p class="text-xs tabular-nums k-muted"><?= (int) $r['calorias_por_racion'] ?> kcal · <?= e(round((float) $r['proteinas_g_por_racion'])) ?> g proteína</p>
                                    </div>
                                    <form method="POST" class="m-0 shrink-0">
                                        <?= campoCsrf() ?>
                                        <input type="hidden" name="id_receta_seleccionada" value="<?= (int) $r['id_receta'] ?>">
                                        <input type="hidden" name="dia_contexto" value="<?= e($diaUrl) ?>">
                                        <input type="hidden" name="semana_contexto" value="<?= e($semanaUrl) ?>">
                                        <input type="hidden" name="momento_contexto" value="<?= e($momentoUrl) ?>">
                                        <button type="submit" name="seleccionar_existente" class="k-btn k-btn-secondary k-btn-sm"><?= icono('plus') ?>Usar</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- PANTALLA DE ESPERA MIENTRAS RESPONDE LA IA -->
<div id="loadingOverlay" class="k-modal" hidden aria-live="polite">
    <div class="k-modal-backdrop"></div>
    <div class="k-modal-panel max-w-xs text-center">
        <div class="mx-auto mb-4 h-10 w-10 animate-spin rounded-full border-4 border-teal-500/20 border-t-teal-500"></div>
        <p class="font-medium k-strong">Creando tu receta…</p>
        <p id="loadingMessage" class="mt-1 text-sm k-muted">Revisando tu despensa…</p>
    </div>
</div>

<?php include_once __DIR__ . '/../templates/footer.php'; ?>
