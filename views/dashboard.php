<?php
/**
 * PANEL DE CONTROL PRINCIPAL (DASHBOARD) - KaloAI
 * Este es el punto de entrada tras el login. Orquesta la carga de datos 
 * de múltiples módulos para ofrecer una visión 360° del estado del usuario.
 */

$pageTitle = "KaloAI | Panel de Control";

// 1. INICIALIZACIÓN Y SEGURIDAD
session_start();
require_once __DIR__ . '/../core/configuracion.php'; 
require_once __DIR__ . '/../core/utilidades.php';
require_once __DIR__ . '/../core/recetas.php';
require_once __DIR__ . '/../core/registro.php';

// Validar que la sesión esté activa; de lo contrario, proteger la ruta
if (!isset($_SESSION['user_id'])) {
    redirect('auth/login.php');
}

// Mapeo de identidad y permisos
$userEmail = $_SESSION['user_email'] ?? 'Usuario KaloAI';
$userId = $_SESSION['user_id'] ?? 'N/A';
$userRole = $_SESSION['user_role_id'] ?? 3; 

$roleMap = [
    1 => 'Administrador',
    2 => 'Nutricionista',
    3 => 'Usuario Estándar'
];
$roleName = $roleMap[$userRole] ?? 'Desconocido';

// Inicialización de contenedores de datos
$profileData = null;
$latestProgressData = null;
$progresoData = []; 
$jsProgresoData = '[]'; 
$pdo = connectDB();

/**
 * ==========================================================
 * ACCIÓN: CIERRE DE SESIÓN
 * Limpieza de buffers y destrucción de tokens de acceso.
 * ==========================================================
 */
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = [];
    session_destroy();
    redirect('auth/login.php?loggedout=true');
}

/**
 * ==========================================================
 * CARGA DE PERFIL METABÓLICO
 * Extrae los objetivos nutricionales (Kcal/Macros) para mostrarlos en el resumen.
 * ==========================================================
 */
try {
    $stmt = $pdo->prepare("SELECT 
        nombre, 
        tdee_calorias, 
        proteinas_objetivo_g, 
        carbohidratos_objetivo_g, 
        grasas_objetivo_g, 
        calorias_netas_objetivo 
    FROM perfil 
    WHERE id_usuario = ?");
    $stmt->execute([$userId]);
    $profileData = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (\PDOException $e) {
    error_log("Error al cargar datos del perfil en dashboard: " . $e->getMessage());
}

/**
 * ==========================================================
 * CARGA DE PROGRESO ANTROPOMÉTRICO
 * Recupera la serie temporal para gráficos y el registro más reciente.
 * ==========================================================
 */
try {
    $sql_all = "SELECT fecha, peso_kg, cintura_cm, nota 
                FROM progreso 
                WHERE id_usuario = ? 
                ORDER BY fecha ASC"; 
    $stmt_all = $pdo->prepare($sql_all);
    $stmt_all->execute([$userId]);
    $progresoData = $stmt_all->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($progresoData)) {
        $latestProgressData = end($progresoData); 
    }

    // Transformación a formato consumible por Chart.js
    $jsProgresoData = json_html($progresoData); // escapado para incrustarlo en <script>

} catch (\PDOException $e) {
    error_log("Error al cargar datos de progreso en dashboard: " . $e->getMessage());
}

/**
 * ==========================================================
 * LÓGICA DE ALIMENTACIÓN DIARIA (5 MOMENTOS)
 * Versión optimizada con traducción de índices de base de datos a nombres.
 * ==========================================================
 */
$diasEsp = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$diaHoyNombre = $diasEsp[date('w')]; 

$momentosDefinidos = ['Desayuno', 'Media Mañana', 'Almuerzo', 'Merienda', 'Cena'];
$comidasHoy = [];

try {
    /**
     * USO DE CASE EN SQL:
     * Transforma el ID numérico guardado en 'dia_semana' (0-6) en el nombre 
     * legible para comparar con $diaHoyNombre mediante la cláusula HAVING.
     */
    $sqlComidas = "SELECT cp.*, r.titulo, r.calorias_por_racion, r.id_receta,
                    r.proteinas_g_por_racion, r.carbohidratos_g_por_racion, r.grasas_g_por_racion,
                    CASE cp.dia_semana 
                        WHEN 0 THEN 'Lunes' WHEN 1 THEN 'Martes' WHEN 2 THEN 'Miércoles' 
                        WHEN 3 THEN 'Jueves' WHEN 4 THEN 'Viernes' WHEN 5 THEN 'Sábado' WHEN 6 THEN 'Domingo' 
                    END as dia_nombre
                FROM comida_planificada cp
                JOIN receta r ON cp.id_receta = r.id_receta
                JOIN plan_semanal ps ON cp.id_plan = ps.id_plan
                WHERE ps.id_usuario = ? AND ps.fecha_inicio_semana = ?
                HAVING dia_nombre = ?"; 

    $stmtComidas = $pdo->prepare($sqlComidas);
    $stmtComidas->execute([$userId, lunesDe(), $diaHoyNombre]); 
    $rows = $stmtComidas->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        // Trim para evitar que espacios en blanco en la DB rompan las claves del array
        $momentoKey = trim($row['momento_comida']); 
        $comidasHoy[$momentoKey] = $row;
    }
} catch (\PDOException $e) { 
    error_log("Error Comidas Hoy: " . $e->getMessage()); 
}

/**
 * ==========================================================
 * SISTEMA DE VINCULACIÓN PROFESIONAL
 * Detecta si un Nutricionista ha solicitado acceso a los datos del paciente.
 * ==========================================================
 */
$stmtSol = $pdo->prepare("
    SELECT s.id_solicitud, p.nombre AS nombre_nutri 
    FROM solicitudes_vinculacion s
    JOIN perfil p ON s.id_nutricionista = p.id_usuario
    WHERE s.id_paciente = ? AND s.estado = 'pendiente'
");
$stmtSol->execute([$_SESSION['user_id']]);
// En modo gestión el nutricionista no debe ver (ni responder) las invitaciones del paciente
$solicitudes = empty($_SESSION['is_impersonating']) ? $stmtSol->fetchAll() : [];

/**
 * ==========================================================
 * RESUMEN PARA EL PANEL: semana, despensa, compra, recetas,
 * restricciones y nutricionista
 * ==========================================================
 */
$diasSemana = DIAS_SEMANA;
$semana = array_fill(0, 7, ['kcal' => 0.0, 'comidas' => 0]);
$stmtSemana = $pdo->prepare("SELECT cp.dia_semana, SUM(r.calorias_por_racion * cp.raciones) AS kcal, COUNT(*) AS comidas
                             FROM comida_planificada cp
                             JOIN receta r ON cp.id_receta = r.id_receta
                             JOIN plan_semanal ps ON cp.id_plan = ps.id_plan
                             WHERE ps.id_usuario = ? AND ps.fecha_inicio_semana = ? GROUP BY cp.dia_semana");
$stmtSemana->execute([$userId, lunesDe()]);
foreach ($stmtSemana->fetchAll() as $fila) {
    $semana[(int) $fila['dia_semana']] = ['kcal' => (float) $fila['kcal'], 'comidas' => (int) $fila['comidas']];
}
$huecosPlanificados = array_sum(array_column($semana, 'comidas'));

// Lo comido de verdad: hoy y cada día de esta semana (lunes a domingo)
$registrosHoy = registrosDelDia($pdo, (int) $userId, date('Y-m-d'));
$lunesSemana = new DateTimeImmutable('monday this week');
$consumoSemanaPorFecha = caloriasConsumidasPorDia($pdo, (int) $userId, $lunesSemana->format('Y-m-d'), $lunesSemana->modify('+6 days')->format('Y-m-d'));
$consumoSemana = [];
for ($i = 0; $i < 7; $i++) {
    $fechaDia = $lunesSemana->modify("+$i days")->format('Y-m-d');
    // Los días que aún no han llegado no tienen consumo (null: no se dibujan)
    $consumoSemana[] = $fechaDia > date('Y-m-d') ? null : round($consumoSemanaPorFecha[$fechaDia] ?? 0);
}

// Lista de la compra pendiente (misma lógica que lista_compra.php)
$stmtNec = $pdo->prepare("SELECT dr.nombre_ingrediente, dr.cantidad, cp.raciones, dr.unidad
                          FROM comida_planificada cp
                          JOIN detalle_receta dr ON cp.id_receta = dr.id_receta
                          JOIN plan_semanal ps ON cp.id_plan = ps.id_plan
                          WHERE ps.id_usuario = ? AND ps.fecha_inicio_semana = ?");
$stmtNec->execute([$userId, lunesDe()]);
$stmtInv = $pdo->prepare("SELECT nombre_ingrediente, cantidad, unidad FROM inventario WHERE id_usuario = ?");
$stmtInv->execute([$userId]);
$inventario = $stmtInv->fetchAll();
$pendientesCompra = calcularListaCompra(multiplicarRaciones($stmtNec->fetchAll(), comensalesDe($pdo, (int) $userId)), $inventario);

$stmtRec = $pdo->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(generado_ia), 0) AS ia FROM receta WHERE id_usuario_creador = ?");
$stmtRec->execute([$userId]);
$recetasResumen = $stmtRec->fetch();

$stmtRes = $pdo->prepare("SELECT nombre_ingrediente, nota FROM ingrediente_prohibido WHERE id_usuario = ? ORDER BY nombre_ingrediente");
$stmtRes->execute([$userId]);
$restricciones = $stmtRes->fetchAll();

$stmtNutri = $pdo->prepare("SELECT pn.nombre, un.correo FROM perfil p
                            JOIN usuario un ON un.id_usuario = p.id_nutricionista
                            LEFT JOIN perfil pn ON pn.id_usuario = p.id_nutricionista
                            WHERE p.id_usuario = ?");
$stmtNutri->execute([$userId]);
$miNutricionista = $stmtNutri->fetch();

// Carga del componente de cabecera (antes de cualquier HTML: el <!DOCTYPE> va primero)
include_once __DIR__ . '/templates/header.php';
?>
<?php
/* ==========================================================================
   DATOS PARA LA VISTA
   ========================================================================== */
$mesesEsp = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$fechaHoy = $diaHoyNombre . ', ' . date('j') . ' de ' . $mesesEsp[date('n') - 1];
$nombre = getPrimerNombre($profileData['nombre'] ?? '');
$tienePerfil = $profileData && ($profileData['calorias_netas_objetivo'] ?? 0) > 0;
$indiceHoy = (int) date('N') - 1; // 0 = lunes

// Lo planificado hoy, sumando raciones
$hoy = ['kcal' => 0, 'p' => 0, 'c' => 0, 'g' => 0];
foreach ($comidasHoy as $c) {
    $r = (float) ($c['raciones'] ?? 1);
    $hoy['kcal'] += $c['calorias_por_racion'] * $r;
    $hoy['p'] += $c['proteinas_g_por_racion'] * $r;
    $hoy['c'] += $c['carbohidratos_g_por_racion'] * $r;
    $hoy['g'] += $c['grasas_g_por_racion'] * $r;
}
$comido = sumarRegistros($registrosHoy);
// Por momento: qué se ha registrado y si la comida planificada ya está hecha
$registrosPorMomento = [];
foreach ($registrosHoy as $r) $registrosPorMomento[$r['momento_comida']][] = $r;
$planHecho = function (string $momento) use ($comidasHoy, $registrosPorMomento): ?array {
    $plan = $comidasHoy[$momento] ?? null;
    if (!$plan) return null;
    foreach ($registrosPorMomento[$momento] ?? [] as $r) {
        if ((int) $r['id_receta'] === (int) $plan['id_receta']) return $r;
    }
    return null;
};
$comidasHechas = count(array_filter(array_keys($comidasHoy), fn($m) => $planHecho($m) !== null));
$avisoRegistro = $_SESSION['aviso_registro'] ?? null;
unset($_SESSION['aviso_registro']);
$metaKcal = (int) ($profileData['calorias_netas_objetivo'] ?? 0);
$metas = ['p' => (int) ($profileData['proteinas_objetivo_g'] ?? 0), 'c' => (int) ($profileData['carbohidratos_objetivo_g'] ?? 0), 'g' => (int) ($profileData['grasas_objetivo_g'] ?? 0)];
$pctKcal = $metaKcal > 0 ? (int) round($comido['kcal'] / $metaKcal * 100) : 0;
$pctPlan = $metaKcal > 0 ? (int) round($hoy['kcal'] / $metaKcal * 100) : 0;
$restantes = $metaKcal - (int) round($comido['kcal']);

$pesos = array_values(array_filter($progresoData, fn($p) => $p['peso_kg'] !== null));
$pesoActual = $pesos ? (float) end($pesos)['peso_kg'] : null;
$pesoInicial = $pesos ? (float) $pesos[0]['peso_kg'] : null;
$cambioPeso = count($pesos) > 1 ? round($pesoActual - $pesoInicial, 1) : null;
$cinturas = array_values(array_filter(array_column($progresoData, 'cintura_cm'), fn($c) => $c !== null && $c > 0));
$cambioCintura = count($cinturas) > 1 ? round(end($cinturas) - $cinturas[0], 1) : null;

$diasConComida = count(array_filter($semana, fn($d) => $d['comidas'] > 0));
$kcalSemana = array_column($semana, 'kcal');
$mediaSemana = $diasConComida ? array_sum($kcalSemana) / $diasConComida : 0;

$coma = fn($n, $d = 1) => number_format((float) $n, $d, ',', '.');
$entero = fn($n) => number_format((float) $n, 0, ',', '.');
$tiposRes = ['Alergia (Riesgo Alto)' => 'k-badge-danger', 'Intolerancia (Riesgo Medio)' => 'k-badge-warn'];
?>

<div class="k-container k-page">

    <!-- INVITACIONES DE NUTRICIONISTAS -->
    <?php foreach ($solicitudes as $sol): ?>
        <div class="k-card k-card-pad mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="k-icon-tile k-tile-indigo"><?= icono('user-plus') ?></span>
                <div>
                    <p class="font-medium k-strong"><?= e($sol['nombre_nutri']) ?> quiere ser tu nutricionista</p>
                    <p class="text-sm k-muted">Podrá ver y ajustar tu plan, tus recetas y tu progreso.</p>
                </div>
            </div>
            <div class="flex shrink-0 gap-2">
                <form method="POST" action="user/aceptar_nutri.php" class="m-0">
                    <?= campoCsrf() ?>
                    <input type="hidden" name="id" value="<?= (int) $sol['id_solicitud'] ?>">
                    <button type="submit" name="accion" value="rechazar" class="k-btn k-btn-secondary">Ahora no</button>
                </form>
                <form method="POST" action="user/aceptar_nutri.php" class="m-0">
                    <?= campoCsrf() ?>
                    <input type="hidden" name="id" value="<?= (int) $sol['id_solicitud'] ?>">
                    <button type="submit" name="accion" value="aceptar" class="k-btn k-btn-primary"><?= icono('check') ?>Aceptar</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- CABECERA -->
    <div class="k-page-head">
        <div>
            <span class="k-pill"><?= icono('calendar') ?><?= e($fechaHoy) ?></span>
            <h1 class="k-title mt-3">Hola, <?= $nombre ?></h1>
            <p class="k-subtitle">Así va tu día y tu semana.</p>
        </div>
        <div class="flex gap-2">
            <a href="user/progreso.php" class="k-btn k-btn-secondary"><?= icono('activity') ?>Registrar peso</a>
            <a href="user/generar_receta.php" class="k-btn k-btn-accent"><?= icono('sparkles') ?>Generar receta</a>
        </div>
    </div>

    <?php if (!$tienePerfil): ?>
        <div class="k-alert k-alert-info mb-6 items-center">
            <?= icono('info') ?>
            <p class="flex-1">Completa tu perfil para que KaloAI calcule tus calorías y macros diarios.</p>
            <a href="user/perfil.php" class="k-btn k-btn-primary k-btn-sm">Completar perfil</a>
        </div>
    <?php endif; ?>

    <!-- CIFRAS PRINCIPALES -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <!-- Calorías de hoy: lo comido, con lo planificado como referencia -->
        <a href="#comidas" class="k-card k-card-link flex flex-col p-5">
            <div class="flex items-center justify-between"><p class="k-stat-label">Comido hoy</p><span class="k-icon-tile h-8 w-8 rounded-lg k-tile-orange"><?= icono('flame', 'h-4 w-4') ?></span></div>
            <div class="mt-3 flex items-baseline gap-2">
                <p class="k-stat-value"><?= $entero($comido['kcal']) ?></p>
                <?php if ($tienePerfil): ?><span class="text-sm k-muted">/ <?= $entero($metaKcal) ?> kcal</span><?php endif; ?>
            </div>
            <div class="mt-auto pt-4">
                <!-- Barra: lo comido encima; la marca clara es lo planificado para hoy -->
                <div class="k-track relative h-2" title="Planificado: <?= $entero($hoy['kcal']) ?> kcal">
                    <?php if ($pctPlan > 0): ?><div class="absolute inset-y-0 left-0 rounded-full" style="width: <?= min(100, $pctPlan) ?>%; background-color: var(--k-brand-soft);"></div><?php endif; ?>
                    <div class="k-bar relative <?= $restantes < -$metaKcal * 0.1 ? 'k-bar-over' : '' ?>" style="width: <?= min(100, $pctKcal) ?>%"></div>
                </div>
                <p class="mt-2 text-xs k-muted"><?= !$tienePerfil ? 'Sin objetivo todavía' : ($restantes >= 0 ? 'Te quedan ' . $entero($restantes) . ' kcal' : 'Te pasas ' . $entero(-$restantes) . ' kcal') ?> · <?= $entero($hoy['kcal']) ?> planificadas</p>
            </div>
        </a>

        <!-- Peso -->
        <a href="user/progreso.php" class="k-card k-card-link flex flex-col p-5">
            <div class="flex items-center justify-between"><p class="k-stat-label">Peso</p><span class="k-icon-tile h-8 w-8 rounded-lg"><?= icono('activity', 'h-4 w-4') ?></span></div>
            <div class="mt-3 flex items-center gap-3">
                <p class="k-stat-value"><?= $pesoActual !== null ? $coma($pesoActual) : '—' ?><span class="k-stat-unit">kg</span></p>
                <?php if ($cambioPeso !== null): ?>
                    <span class="k-delta <?= $cambioPeso <= 0 ? 'k-delta-ok' : 'k-delta-warn' ?>">
                        <span class="k-delta-icon"><?= icono($cambioPeso <= 0 ? 'trend-down' : 'trend-up') ?></span><?= ($cambioPeso > 0 ? '+' : '') . $coma($cambioPeso) ?> kg
                    </span>
                <?php endif; ?>
            </div>
            <div class="relative mt-auto h-14 pt-2"><canvas id="pesoMini" class="absolute inset-0" aria-hidden="true"></canvas></div>
        </a>

        <!-- Plan de la semana -->
        <a href="user/planificacion_semanal.php" class="k-card k-card-link flex flex-col p-5">
            <div class="flex items-center justify-between"><p class="k-stat-label">Plan de la semana</p><span class="k-icon-tile h-8 w-8 rounded-lg k-tile-indigo"><?= icono('calendar', 'h-4 w-4') ?></span></div>
            <div class="mt-3 flex items-baseline gap-2"><p class="k-stat-value"><?= $huecosPlanificados ?></p><span class="text-sm k-muted">/ 35 comidas</span></div>
            <div class="mt-auto flex items-end gap-1.5 pt-4" aria-label="Comidas planificadas por día">
                <?php foreach ($semana as $i => $d): ?>
                    <div class="flex flex-1 flex-col items-center gap-1">
                        <div class="flex h-9 w-full items-end overflow-hidden rounded-md" style="background-color: var(--k-surface-2);">
                            <div class="w-full rounded-md" style="height: <?= $d['comidas'] * 20 ?>%; background-color: <?= $i === $indiceHoy ? 'var(--k-brand)' : 'var(--k-indigo)' ?>; opacity: <?= $i === $indiceHoy ? 1 : 0.7 ?>;"></div>
                        </div>
                        <span class="text-[10px] k-muted"><?= mb_substr($diasSemana[$i], 0, 1) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </a>

        <!-- Compra -->
        <a href="user/lista_compra.php" class="k-card k-card-link flex flex-col p-5">
            <div class="flex items-center justify-between"><p class="k-stat-label">Lista de la compra</p><span class="k-icon-tile h-8 w-8 rounded-lg k-tile-amber"><?= icono('cart', 'h-4 w-4') ?></span></div>
            <div class="mt-3 flex items-baseline gap-2"><p class="k-stat-value"><?= count($pendientesCompra) ?></p><span class="text-sm k-muted"><?= count($pendientesCompra) === 1 ? 'producto' : 'productos' ?> por comprar</span></div>
            <div class="mt-auto flex flex-wrap gap-1.5 pt-4">
                <?php foreach (array_slice($pendientesCompra, 0, 3) as $item): ?>
                    <span class="k-badge"><?= e($item['nombre']) ?></span>
                <?php endforeach; ?>
                <?php if (count($pendientesCompra) > 3): ?><span class="k-badge">+<?= count($pendientesCompra) - 3 ?></span><?php endif; ?>
                <?php if (!$pendientesCompra): ?><span class="k-badge k-badge-ok"><?= icono('check') ?>No te falta nada</span><?php endif; ?>
            </div>
        </a>
    </div>

    <!-- SEMANA Y MACROS -->
    <div class="mt-4 grid gap-4 lg:grid-cols-3">
        <section class="k-card k-card-pad flex flex-col lg:col-span-2">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="k-section-title">Calorías de la semana</h2>
                    <p class="mt-0.5 text-sm k-muted">Lo planificado y lo comido cada día<?= $tienePerfil ? ' frente a tu objetivo de ' . $entero($metaKcal) . ' kcal' : '' ?>.</p>
                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs k-muted">
                        <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm" style="background-color: var(--k-indigo);"></span>Planificado</span>
                        <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm" style="background-color: var(--k-brand);"></span>Comido</span>
                        <?php if ($tienePerfil): ?><span class="flex items-center gap-1.5"><span class="w-3 border-t-2 border-dashed" style="border-color: var(--k-muted);"></span>Objetivo</span><?php endif; ?>
                    </div>
                </div>
                <div class="text-right">
                    <p class="font-display text-xl font-semibold tabular-nums k-strong"><?= $entero($mediaSemana) ?> <span class="text-sm font-normal k-muted">kcal/día</span></p>
                    <p class="text-xs k-muted">media de los días con plan</p>
                </div>
            </div>
            <div class="relative mt-5 min-h-[16rem] flex-1"><canvas id="semanaChart" class="absolute inset-0" aria-label="Calorías planificadas por día"></canvas></div>
        </section>

        <section class="k-card k-card-pad flex flex-col">
            <h2 class="k-section-title">Macros de hoy</h2>
            <p class="mt-0.5 text-sm k-muted">Lo que has comido frente a tu objetivo.</p>
            <div class="relative mx-auto mt-4 h-44 w-44">
                <canvas id="macrosChart" class="absolute inset-0" aria-label="Reparto de macronutrientes de hoy"></canvas>
                <div class="pointer-events-none absolute inset-0 grid place-items-center text-center">
                    <div><p class="font-display text-2xl font-semibold tabular-nums k-strong"><?= $entero($comido['kcal']) ?></p><p class="text-xs k-muted">kcal comidas</p></div>
                </div>
            </div>
            <ul class="mt-auto space-y-3 pt-5">
                <?php foreach ([['k-macro-p', 'Proteína', 'p'], ['k-macro-c', 'Carbohidratos', 'c'], ['k-macro-f', 'Grasa', 'g']] as [$clase, $etiqueta, $k]): ?>
                    <li class="<?= $clase ?>">
                        <div class="mb-1 flex items-center justify-between text-sm">
                            <span class="flex items-center gap-2 k-strong"><span class="k-macro-dot"></span><?= $etiqueta ?></span>
                            <span class="tabular-nums k-muted"><?= round($comido[$k]) ?><?= $metas[$k] ? ' / ' . $metas[$k] : '' ?> g</span>
                        </div>
                        <div class="k-track"><div class="k-bar k-macro-bar" style="width: <?= $metas[$k] ? min(100, round($comido[$k] / $metas[$k] * 100)) : 0 ?>%"></div></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>

    <!-- COMIDAS DE HOY Y PESO -->
    <div class="mt-4 grid gap-4 lg:grid-cols-3">
        <section id="comidas" class="k-card scroll-mt-6 lg:col-span-2">
            <div class="k-card-head flex-wrap gap-3">
                <div>
                    <h2 class="k-section-title">Comidas de hoy</h2>
                    <p class="text-xs k-muted"><?= $comidasHoy ? $comidasHechas . ' de ' . count($comidasHoy) . ' planificadas hechas' : 'Marca lo que vas comiendo' ?></p>
                </div>
                <div class="flex gap-2">
                    <button type="button" onclick="abrirComidaLibre()" class="k-btn k-btn-secondary k-btn-sm"><?= icono('plus') ?>He comido otra cosa</button>
                    <a href="user/planificacion_semanal.php" class="k-btn k-btn-ghost k-btn-sm">Semana <?= icono('chevron-right') ?></a>
                </div>
            </div>
            <?php if ($avisoRegistro): [$tipoAviso, $textoAviso] = $avisoRegistro; ?>
                <div class="k-alert k-alert-<?= $tipoAviso === 'ok' ? 'ok' : 'error' ?> mx-5 mt-4 sm:mx-6" role="status"><?= icono($tipoAviso === 'ok' ? 'check' : 'alert') ?><p><?= e($textoAviso) ?></p></div>
            <?php endif; ?>
            <ul class="k-list">
                <?php foreach ($momentosDefinidos as $momento):
                    $comida = $comidasHoy[$momento] ?? null;
                    $hecho = $planHecho($momento);
                    // Lo registrado en este momento que no es la comida del plan
                    $extras = array_filter($registrosPorMomento[$momento] ?? [], fn($r) => !$hecho || (int) $r['id_registro'] !== (int) $hecho['id_registro']);
                    $estilo = $comida ? estiloTipoReceta(tipoRecetaParaMomento($momento)) : null; ?>
                    <li class="px-5 py-3.5 sm:px-6">
                        <div class="flex items-center gap-4">
                            <span class="relative grid h-10 w-10 shrink-0 place-items-center rounded-xl text-lg <?= $comida ? '' : 'border border-dashed k-muted' ?>" style="background-color: var(--k-surface-2); <?= $comida ? '' : 'border-color: var(--k-border-strong);' ?>" aria-hidden="true">
                                <?= $comida ? $estilo['emoji'] : icono('utensils', 'h-4 w-4') ?>
                                <?php if ($hecho): ?><span class="absolute -bottom-1 -right-1 grid h-5 w-5 place-items-center rounded-full" style="background-color: var(--k-brand); color: var(--k-inverse-text, #fff);"><?= icono('check', 'h-3 w-3') ?></span><?php endif; ?>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs k-muted"><?= e($momento) ?></p>
                                <?php if ($comida): ?>
                                    <a href="user/detalle_receta.php?id=<?= (int) $comida['id_receta'] ?>" class="block truncate font-medium hover:underline <?= $hecho ? 'k-muted line-through decoration-1' : 'k-strong' ?>"><?= e($comida['titulo']) ?></a>
                                <?php elseif (!$extras): ?>
                                    <p class="text-sm k-muted">Nada planificado</p>
                                <?php endif; ?>
                            </div>
                            <?php if ($comida): ?>
                                <span class="hidden text-sm tabular-nums sm:block k-muted"><?= (int) round($comida['calorias_por_racion'] * ((float) ($comida['raciones'] ?? 1) ?: 1)) ?> kcal</span>
                                <form method="POST" action="user/registrar_comida.php" class="m-0 shrink-0">
                                    <?= campoCsrf() ?>
                                    <?php if ($hecho): ?>
                                        <input type="hidden" name="accion" value="deshacer">
                                        <input type="hidden" name="id_registro" value="<?= (int) $hecho['id_registro'] ?>">
                                        <button type="submit" class="k-btn k-btn-ghost k-btn-sm" aria-label="Desmarcar <?= e($comida['titulo']) ?>"><?= icono('x') ?>Deshacer</button>
                                    <?php else: ?>
                                        <input type="hidden" name="accion" value="hecho">
                                        <input type="hidden" name="id_comida" value="<?= (int) $comida['id_comida_planificada'] ?>">
                                        <button type="submit" class="k-btn k-btn-primary k-btn-sm" aria-label="Marcar <?= e($comida['titulo']) ?> como comida"><?= icono('check') ?>Hecho</button>
                                    <?php endif; ?>
                                </form>
                            <?php elseif (!$extras): ?>
                                <a href="user/generar_receta.php?dia=<?= urlencode($diaHoyNombre) ?>&momento=<?= urlencode($momento) ?>" class="k-btn k-btn-secondary k-btn-sm"><?= icono('plus') ?>Planificar</a>
                            <?php endif; ?>
                        </div>
                        <?php foreach ($extras as $extra): ?>
                            <div class="ml-14 mt-2 flex items-center gap-3 rounded-xl px-3 py-2 text-sm" style="background-color: var(--k-surface-2);">
                                <span class="k-badge k-badge-warn shrink-0">Fuera del plan</span>
                                <span class="min-w-0 flex-1 truncate k-strong"><?= e($extra['descripcion']) ?></span>
                                <span class="shrink-0 tabular-nums k-muted"><?= (int) $extra['calorias'] ?> kcal</span>
                                <form method="POST" action="user/registrar_comida.php" class="m-0 shrink-0">
                                    <?= campoCsrf() ?>
                                    <input type="hidden" name="accion" value="deshacer">
                                    <input type="hidden" name="id_registro" value="<?= (int) $extra['id_registro'] ?>">
                                    <button type="submit" class="k-btn k-btn-ghost k-btn-icon k-btn-danger-text h-7 w-7" aria-label="Quitar <?= e($extra['descripcion']) ?>"><?= icono('x', 'h-4 w-4') ?></button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="k-card k-card-pad flex flex-col">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="k-section-title">Evolución del peso</h2>
                    <p class="mt-0.5 text-sm k-muted"><?= count($pesos) ?> registros</p>
                </div>
                <a href="user/progreso.php" class="k-btn k-btn-ghost k-btn-sm">Ver todo</a>
            </div>
            <?php if (count($pesos) >= 2): ?>
                <div class="relative mt-4 min-h-[10rem] flex-1"><canvas id="progressChart" class="absolute inset-0" aria-label="Evolución del peso"></canvas></div>
                <dl class="mt-4 grid grid-cols-3 gap-2 border-t pt-4 text-center" style="border-color: var(--k-border);">
                    <div><dt class="text-[11px] k-muted">Inicio</dt><dd class="font-medium tabular-nums k-strong"><?= $coma($pesoInicial) ?> kg</dd></div>
                    <div><dt class="text-[11px] k-muted">Ahora</dt><dd class="font-medium tabular-nums k-strong"><?= $coma($pesoActual) ?> kg</dd></div>
                    <div><dt class="text-[11px] k-muted">Cintura</dt><dd class="font-medium tabular-nums k-strong"><?= $cambioCintura !== null ? (($cambioCintura > 0 ? '+' : '') . $coma($cambioCintura) . ' cm') : '—' ?></dd></div>
                </dl>
            <?php else: ?>
                <div class="k-empty mt-4 flex-1 py-8">
                    <span class="k-empty-icon"><?= icono('activity') ?></span>
                    <p class="text-sm k-muted">Con dos registros verás aquí tu evolución.</p>
                    <a href="user/progreso.php" class="k-btn k-btn-secondary k-btn-sm mt-3">Registrar peso</a>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <!-- RESUMEN: RESTRICCIONES, NUTRICIONISTA Y BIBLIOTECA -->
    <div class="mt-4 grid gap-4 md:grid-cols-3">
        <a href="user/perfil.php" class="k-card k-card-link k-card-pad">
            <div class="flex items-center gap-3"><span class="k-icon-tile k-tile-danger"><?= icono('ban') ?></span><h2 class="k-section-title">Lo que la IA evita</h2></div>
            <div class="mt-4 flex flex-wrap gap-1.5">
                <?php foreach ($restricciones as $r): ?>
                    <span class="k-badge <?= $tiposRes[$r['nota']] ?? '' ?>"><?= e($r['nombre_ingrediente']) ?></span>
                <?php endforeach; ?>
                <?php if (!$restricciones): ?><p class="text-sm k-muted">No tienes alergias ni intolerancias marcadas.</p><?php endif; ?>
            </div>
        </a>

        <div class="k-card k-card-pad">
            <div class="flex items-center gap-3"><span class="k-icon-tile k-tile-indigo"><?= icono('users') ?></span><h2 class="k-section-title">Tu nutricionista</h2></div>
            <?php if ($miNutricionista): ?>
                <div class="mt-4 flex items-center gap-3">
                    <span class="k-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($miNutricionista['nombre'] ?: $miNutricionista['correo'], 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
                    <div class="min-w-0"><p class="truncate font-medium k-strong"><?= e($miNutricionista['nombre'] ?: 'Nutricionista') ?></p><p class="truncate text-sm k-muted"><?= e($miNutricionista['correo']) ?></p></div>
                </div>
                <p class="mt-3 text-xs k-muted">Puede ver y ajustar tu plan.</p>
            <?php else: ?>
                <p class="mt-4 text-sm k-muted">Aún no tienes. Si trabajas con uno, pídele que te invite con tu correo.</p>
            <?php endif; ?>
        </div>

        <a href="user/mis_recetas.php" class="k-card k-card-link k-card-pad">
            <div class="flex items-center gap-3"><span class="k-icon-tile k-tile-rose"><?= icono('book') ?></span><h2 class="k-section-title">Tu cocina</h2></div>
            <dl class="mt-4 grid grid-cols-3 gap-2">
                <div><dt class="text-[11px] k-muted">Recetas</dt><dd class="k-stat-value text-xl"><?= (int) $recetasResumen['total'] ?></dd></div>
                <div><dt class="text-[11px] k-muted">Con IA</dt><dd class="k-stat-value text-xl"><?= (int) $recetasResumen['ia'] ?></dd></div>
                <div><dt class="text-[11px] k-muted">En despensa</dt><dd class="k-stat-value text-xl"><?= count($inventario) ?></dd></div>
            </dl>
        </a>
    </div>

    <!-- ACCESOS -->
    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <?php foreach ([['user/planificacion_semanal.php', 'calendar', 'Plan semanal', 'Tus menús de los 7 días', 'k-tile-indigo'],
                        ['user/mis_recetas.php', 'book', 'Mis recetas', 'Las tuyas y las de la IA', 'k-tile-rose'],
                        ['user/lista_compra.php', 'cart', 'Lista de la compra', 'Lo que te falta comprar', ''],
                        ['user/inventario.php', 'package', 'Despensa', 'Lo que ya tienes en casa', 'k-tile-amber']] as [$url, $ic, $titulo, $texto, $tile]): ?>
            <a href="<?= $url ?>" class="k-card k-card-link group flex items-center gap-4 p-5">
                <span class="k-icon-tile <?= $tile ?> transition-transform group-hover:scale-105"><?= icono($ic) ?></span>
                <span class="min-w-0 flex-1">
                    <span class="block font-medium k-strong"><?= $titulo ?></span>
                    <span class="block truncate text-sm k-muted"><?= $texto ?></span>
                </span>
                <?= icono('chevron-right', 'h-4 w-4 shrink-0 k-muted transition-transform group-hover:translate-x-0.5') ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<?php
$hora = (int) date('G');
$momentoPorHora = $hora < 11 ? 'Desayuno' : ($hora < 13 ? 'Media Mañana' : ($hora < 16 ? 'Almuerzo' : ($hora < 20 ? 'Merienda' : 'Cena')));
?>
<!-- MODAL: HE COMIDO OTRA COSA -->
<div id="modalComidaLibre" class="k-modal" hidden role="dialog" aria-modal="true" aria-labelledby="tituloComidaLibre">
    <div class="k-modal-backdrop"></div>
    <form method="POST" action="user/registrar_comida.php" class="k-modal-panel">
        <?= campoCsrf() ?>
        <input type="hidden" name="accion" value="libre">
        <button type="button" class="k-btn k-btn-ghost k-btn-icon k-modal-close" data-cerrar-modal aria-label="Cerrar"><?= icono('x') ?></button>
        <h2 id="tituloComidaLibre" class="k-modal-title">He comido otra cosa</h2>
        <p class="mt-1 text-sm k-muted">Apunta lo que has tomado fuera del plan para que tus cifras de hoy sean reales.</p>
        <div class="mt-5 space-y-4">
            <div>
                <label for="libreDescripcion" class="k-label">¿Qué has comido?</label>
                <input id="libreDescripcion" name="descripcion" type="text" required maxlength="200" class="k-input" placeholder="Ej. bocadillo de jamón y una caña" data-autofocus>
            </div>
            <div>
                <label for="libreMomento" class="k-label">¿Cuándo?</label>
                <select id="libreMomento" name="momento" class="k-input">
                    <?php foreach ($momentosDefinidos as $m): ?><option value="<?= e($m) ?>" <?= $m === $momentoPorHora ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="rounded-2xl border p-4" style="border-color: var(--k-border);">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm font-medium k-strong">Calorías y macros</p>
                    <button type="button" id="btnEstimar" onclick="estimarComidaLibre()" class="k-btn k-btn-accent k-btn-sm"><?= icono('sparkles') ?>Calcular con IA</button>
                </div>
                <p id="estimarAviso" class="mt-2 text-xs k-muted" hidden></p>
                <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <?php foreach ([['calorias', 'Calorías', 'kcal', ''], ['proteinas', 'Proteína', 'g', 'k-macro-p'], ['carbohidratos', 'Carbos', 'g', 'k-macro-c'], ['grasas', 'Grasa', 'g', 'k-macro-f']] as [$campo, $texto, $unidad, $clase]): ?>
                        <div class="<?= $clase ?>">
                            <label for="libre_<?= $campo ?>" class="k-label flex items-center gap-1.5 text-xs"><?php if ($clase): ?><span class="k-macro-dot"></span><?php endif; ?><?= $texto ?> <span class="font-normal k-muted">(<?= $unidad ?>)</span></label>
                            <input id="libre_<?= $campo ?>" name="<?= $campo ?>" type="number" min="0" step="1" inputmode="numeric" class="k-input" <?= $campo === 'calorias' ? 'required' : '' ?> placeholder="0">
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="k-btn k-btn-secondary" data-cerrar-modal>Cancelar</button>
            <button type="submit" class="k-btn k-btn-primary"><?= icono('check') ?>Apuntar</button>
        </div>
    </form>
</div>

<script>
    // Datos para los gráficos del panel (main.js)
    window.dashboardData = <?= json_html([
        'progreso' => array_map(fn($p) => ['fecha' => $p['fecha'], 'peso_kg' => $p['peso_kg']], $pesos),
        'semana'   => array_map(fn($d) => round($d['kcal']), $semana),
        'comido'   => $consumoSemana,
        'dias'     => array_map(fn($d) => mb_substr($d, 0, 3), DIAS_SEMANA),
        'hoy'      => $indiceHoy,
        'meta'     => $metaKcal,
        'macros'   => [round($comido['p'] * 4), round($comido['c'] * 4), round($comido['g'] * 9)],
    ]) ?>;
</script>

<?php include_once __DIR__ . '/templates/footer.php'; ?>
