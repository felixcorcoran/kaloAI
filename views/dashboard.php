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
                WHERE ps.id_usuario = ? 
                HAVING dia_nombre = ?"; 

    $stmtComidas = $pdo->prepare($sqlComidas);
    $stmtComidas->execute([$userId, $diaHoyNombre]); 
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

// Lo planificado hoy, sumando raciones
$hoy = ['kcal' => 0, 'p' => 0, 'c' => 0, 'g' => 0];
foreach ($comidasHoy as $c) {
    $r = (float) ($c['raciones'] ?? 1);
    $hoy['kcal'] += $c['calorias_por_racion'] * $r;
    $hoy['p'] += $c['proteinas_g_por_racion'] * $r;
    $hoy['c'] += $c['carbohidratos_g_por_racion'] * $r;
    $hoy['g'] += $c['grasas_g_por_racion'] * $r;
}
$metaKcal = (int) ($profileData['calorias_netas_objetivo'] ?? 0);
$pctKcal = $metaKcal > 0 ? min(100, round($hoy['kcal'] / $metaKcal * 100)) : 0;
$restantes = $metaKcal - (int) round($hoy['kcal']);

$pesoActual = $latestProgressData['peso_kg'] ?? null;
$pesoInicial = $progresoData[0]['peso_kg'] ?? null;
$cambioPeso = ($pesoActual !== null && $pesoInicial !== null) ? round($pesoActual - $pesoInicial, 1) : null;
?>

<div class="k-container k-page">

    <!-- INVITACIONES DE NUTRICIONISTAS -->
    <?php foreach ($solicitudes as $sol): ?>
        <div class="k-card k-card-pad mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="k-icon-tile"><?= icono('user-plus') ?></span>
                <div>
                    <p class="font-semibold k-strong"><?= e($sol['nombre_nutri']) ?> quiere ser tu nutricionista</p>
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
            <p class="k-eyebrow"><?= e($fechaHoy) ?></p>
            <h1 class="k-title mt-1">Hola, <?= $nombre ?></h1>
            <p class="k-subtitle">Esto es lo que tienes planificado para hoy.</p>
        </div>
        <a href="user/generar_receta.php" class="k-btn k-btn-primary"><?= icono('sparkles') ?>Generar receta</a>
    </div>

    <?php if (!$tienePerfil): ?>
        <div class="k-alert k-alert-info mb-6 items-center">
            <?= icono('info') ?>
            <p class="flex-1">Completa tu perfil para que KaloAI calcule tus calorías y macros diarios.</p>
            <a href="user/perfil.php" class="k-btn k-btn-primary k-btn-sm">Completar perfil</a>
        </div>
    <?php endif; ?>

    <div class="grid gap-6 lg:grid-cols-3">

        <!-- CALORÍAS Y MACROS DE HOY -->
        <section class="k-card k-card-pad flex flex-col lg:col-span-2">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="k-section-title">Calorías de hoy</h2>
                    <p class="text-sm k-muted">Según las comidas que tienes en el plan.</p>
                </div>
                <a href="user/perfil.php" class="k-btn k-btn-ghost k-btn-sm">Ajustar objetivo</a>
            </div>

            <div class="mt-5 flex flex-wrap items-end gap-x-3 gap-y-1">
                <p class="font-display text-4xl font-bold tracking-tight tabular-nums k-strong"><?= number_format($hoy['kcal'], 0, ',', '.') ?></p>
                <p class="pb-1 text-sm k-muted">
                    <?php if ($tienePerfil): ?>
                        de <?= number_format($metaKcal, 0, ',', '.') ?> kcal ·
                        <?= $restantes >= 0 ? 'te quedan ' . number_format($restantes, 0, ',', '.') : 'te pasas ' . number_format(-$restantes, 0, ',', '.') ?>
                    <?php else: ?>
                        kcal planificadas
                    <?php endif; ?>
                </p>
            </div>
            <?php if ($tienePerfil): ?>
                <div class="k-track mt-3 h-2.5"><div class="k-bar <?= $restantes < -$metaKcal * 0.1 ? 'k-bar-over' : '' ?>" style="width: <?= $pctKcal ?>%"></div></div>
            <?php endif; ?>

            <div class="mt-auto grid gap-4 pt-6 sm:grid-cols-3">
                <?php foreach ([['k-macro-p', 'Proteína', $hoy['p'], $profileData['proteinas_objetivo_g'] ?? 0],
                                ['k-macro-c', 'Carbohidratos', $hoy['c'], $profileData['carbohidratos_objetivo_g'] ?? 0],
                                ['k-macro-f', 'Grasa', $hoy['g'], $profileData['grasas_objetivo_g'] ?? 0]] as [$clase, $etiqueta, $actual, $objetivo]): ?>
                    <div class="<?= $clase ?>">
                        <div class="mb-1.5 flex items-center justify-between text-sm">
                            <span class="flex items-center gap-2 font-medium k-strong"><span class="k-macro-dot"></span><?= $etiqueta ?></span>
                            <span class="tabular-nums k-muted"><?= round($actual) ?><?= $objetivo ? ' / ' . (int) $objetivo : '' ?> g</span>
                        </div>
                        <div class="k-track"><div class="k-bar k-macro-bar" style="width: <?= $objetivo ? min(100, round($actual / $objetivo * 100)) : 0 ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- PESO -->
        <section class="k-card k-card-pad flex flex-col">
            <div class="flex items-start justify-between gap-4">
                <h2 class="k-section-title">Peso</h2>
                <a href="user/progreso.php" class="k-btn k-btn-ghost k-btn-sm">Ver progreso</a>
            </div>
            <?php if ($pesoActual !== null): ?>
                <div class="mt-4 flex items-end gap-3">
                    <p class="font-display text-4xl font-bold tracking-tight tabular-nums k-strong"><?= number_format($pesoActual, 1, ',', '') ?><span class="k-stat-unit">kg</span></p>
                    <?php if ($cambioPeso !== null && count($progresoData) > 1): ?>
                        <span class="k-badge <?= $cambioPeso <= 0 ? 'k-badge-ok' : 'k-badge-warn' ?> mb-1.5">
                            <?= icono($cambioPeso <= 0 ? 'trend-down' : 'trend-up', 'h-3.5 w-3.5') ?>
                            <?= ($cambioPeso > 0 ? '+' : '') . number_format($cambioPeso, 1, ',', '') ?> kg
                        </span>
                    <?php endif; ?>
                </div>
                <p class="mt-1 text-xs k-muted">Último registro: <?= e(formatearFecha($latestProgressData['fecha'])) ?></p>
                <div class="relative mt-4 h-24 min-h-[6rem] flex-1"><canvas id="progressChart" class="absolute inset-0" aria-label="Evolución del peso"></canvas></div>
            <?php else: ?>
                <div class="k-empty mt-4 flex-1 py-8">
                    <span class="k-empty-icon"><?= icono('activity') ?></span>
                    <p class="text-sm k-muted">Aún no has registrado tu peso.</p>
                    <a href="user/progreso.php" class="k-btn k-btn-secondary k-btn-sm mt-3">Registrar ahora</a>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <!-- COMIDAS DE HOY -->
    <section class="k-card mt-6">
        <div class="k-card-head">
            <h2 class="k-section-title">Comidas de hoy</h2>
            <a href="user/planificacion_semanal.php" class="k-btn k-btn-ghost k-btn-sm">Ver la semana <?= icono('chevron-right') ?></a>
        </div>
        <ul class="k-list">
            <?php foreach ($momentosDefinidos as $momento):
                $comida = $comidasHoy[$momento] ?? null; ?>
                <li class="flex items-center gap-4 px-5 py-4 sm:px-6">
                    <span class="w-24 shrink-0 text-xs font-semibold uppercase tracking-wider k-muted sm:w-28"><?= e($momento) ?></span>
                    <?php if ($comida): ?>
                        <a href="user/detalle_receta.php?id=<?= (int) $comida['id_receta'] ?>" class="min-w-0 flex-1 truncate font-medium k-strong hover:underline"><?= e($comida['titulo']) ?></a>
                        <span class="shrink-0 text-sm tabular-nums k-muted"><?= (int) $comida['calorias_por_racion'] ?> kcal</span>
                    <?php else: ?>
                        <span class="flex-1 text-sm k-muted">Sin planificar</span>
                        <a href="user/generar_receta.php?dia=<?= urlencode($diaHoyNombre) ?>&momento=<?= urlencode($momento) ?>" class="k-btn k-btn-secondary k-btn-sm"><?= icono('plus') ?>Añadir</a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>

<script>
    // Datos para el gráfico de peso (main.js)
    window.dashboardData = { progreso: <?= $jsProgresoData ?> };
</script>

<?php include_once __DIR__ . '/templates/footer.php'; ?>
