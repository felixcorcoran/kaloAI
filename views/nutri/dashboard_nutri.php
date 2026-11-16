<?php
/**
 * VISTA: PANEL DEL NUTRICIONISTA - KaloAI
 * Permite a los profesionales gestionar su cartera de clientes, visualizar 
 * estadísticas rápidas y acceder a la gestión individual de cada paciente.
 */
$pageTitle = "KaloAI | Pacientes";

session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php';
require_once __DIR__ . '/../../core/recetas.php';
require_once __DIR__ . '/../../core/seguimiento.php';

/* ==========================================================================
   SEGURIDAD Y CONTROL DE ACCESO
   ========================================================================== */
// Solo permitimos el acceso a Nutricionistas (Rol 2) o Administradores (Rol 1).
// Si el rol es mayor a 2 (ej. Usuario estándar), se redirige al dashboard.
if (!isset($_SESSION['user_role_id']) || $_SESSION['user_role_id'] > 2) {
    header("Location: ../dashboard.php");
    exit;
}

$nutriId = $_SESSION['user_id'];
$pdo = connectDB();

/* ==========================================================================
   EXTRACCIÓN DE DATOS
   ========================================================================== */

// 1. Obtener el número total de pacientes vinculados a este nutricionista
$totalPacientes = $pdo->prepare("SELECT COUNT(*) FROM perfil WHERE id_nutricionista = ?");
$totalPacientes->execute([$nutriId]);
$numPacientes = $totalPacientes->fetchColumn();

// 2. Listado detallado de pacientes
// Combinamos 'usuario' (para el correo) y 'perfil' (para datos antropométricos)
$stmt = $pdo->prepare("
    SELECT u.id_usuario, p.nombre, u.correo, p.peso_kg, p.objetivo, p.calorias_netas_objetivo,
           (SELECT pr.peso_kg FROM progreso pr WHERE pr.id_usuario = u.id_usuario AND pr.peso_kg IS NOT NULL ORDER BY pr.fecha ASC LIMIT 1) AS peso_inicial,
           (SELECT MAX(pr.fecha) FROM progreso pr WHERE pr.id_usuario = u.id_usuario) AS ultimo_registro,
           (SELECT COUNT(*) FROM comida_planificada cp JOIN plan_semanal ps ON cp.id_plan = ps.id_plan WHERE ps.id_usuario = u.id_usuario AND ps.fecha_inicio_semana = ?) AS comidas_plan,
           (SELECT COUNT(DISTINCT rc.fecha) FROM registro_comida rc WHERE rc.id_usuario = u.id_usuario AND rc.fecha > DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS dias_registrados
    FROM usuario u
    JOIN perfil p ON u.id_usuario = p.id_usuario
    WHERE p.id_nutricionista = ?
    ORDER BY p.nombre
");
$stmt->execute([lunesDe(), $nutriId]);
$pacientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Mensajes de pacientes que aún no se han leído en su ficha
$sinLeerPorPaciente = respuestasSinLeer($pdo, $nutriId);



// Carga del componente de cabecera 
// Invitaciones enviadas que el paciente aún no ha contestado
$stmtPend = $pdo->prepare("
    SELECT p.nombre, u.correo, s.fecha_solicitud
    FROM solicitudes_vinculacion s
    JOIN usuario u ON u.id_usuario = s.id_paciente
    LEFT JOIN perfil p ON p.id_usuario = s.id_paciente
    WHERE s.id_nutricionista = ? AND s.estado = 'pendiente'
    ORDER BY s.fecha_solicitud DESC
");
$stmtPend->execute([$nutriId]);
$pendientes = $stmtPend->fetchAll(PDO::FETCH_ASSOC);

// Cifras del panel
$haceUnaSemana = date('Y-m-d', strtotime('-7 days'));
$activosSemana = count(array_filter($pacientes, fn($p) => $p['ultimo_registro'] && $p['ultimo_registro'] >= $haceUnaSemana));
$mediaPlan = $pacientes ? array_sum(array_column($pacientes, 'comidas_plan')) / count($pacientes) : 0;
$cambios = array_filter(array_map(fn($p) => ($p['peso_inicial'] !== null && $p['peso_kg'] !== null) ? (float) $p['peso_kg'] - (float) $p['peso_inicial'] : null, $pacientes), fn($c) => $c !== null);
$cambioMedio = $cambios ? array_sum($cambios) / count($cambios) : null;

$objetivosTexto = [
    'perdida_rapida' => 'Pérdida rápida', 'perdida_moderada' => 'Pérdida moderada', 'perdida_grasa' => 'Pérdida de grasa',
    'mantenimiento' => 'Mantenimiento', 'ganancia_muscular' => 'Ganancia muscular',
    'recomposicion_corporal' => 'Recomposición', 'rendimiento_deportivo' => 'Rendimiento deportivo',
];
$avisos = [
    'msj:solicitud_enviada'    => ['ok', 'Invitación enviada. Tu paciente la verá al entrar en KaloAI.'],
    'err:usuario_no_encontrado' => ['error', 'No hay ningún usuario con ese correo (o no es una cuenta de paciente).'],
    'err:ya_solicitado'         => ['warn', 'Ya tienes una invitación pendiente para ese correo.'],
];

include_once __DIR__ . '/../templates/header.php';
?>

<div class="k-container k-page">
    <div class="k-page-head">
        <div>
            <span class="k-pill"><?= icono('users') ?>Panel profesional</span>
            <h1 class="k-title mt-3">Pacientes</h1>
            <p class="k-subtitle">Sigue su evolución en su ficha, déjale notas y, si hace falta, entra en su cuenta para ajustar su plan.</p>
        </div>
    </div>

    <?php foreach ($avisos as $clave => [$tipo, $texto]):
        [$param, $valor] = explode(':', $clave);
        if (($_GET[$param] ?? null) === $valor): ?>
            <div class="k-alert k-alert-<?= $tipo ?> mb-6"><?= icono($tipo === 'ok' ? 'check' : 'alert') ?><p><?= e($texto) ?></p></div>
    <?php endif; endforeach; ?>

    <!-- CIFRAS -->
    <div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <div class="k-card flex items-center gap-4 p-5">
            <span class="hidden sm:grid k-icon-tile"><?= icono('users') ?></span>
            <div><p class="k-stat-label">Pacientes</p><p class="k-stat-value"><?= (int) $numPacientes ?></p></div>
        </div>
        <div class="k-card flex items-center gap-4 p-5">
            <span class="hidden sm:grid k-icon-tile k-tile-amber"><?= icono('mail') ?></span>
            <div><p class="k-stat-label">Invitaciones pendientes</p><p class="k-stat-value"><?= count($pendientes) ?></p></div>
        </div>
        <div class="k-card flex items-center gap-4 p-5">
            <span class="hidden sm:grid k-icon-tile k-tile-indigo"><?= icono('activity') ?></span>
            <div><p class="k-stat-label">Se han pesado esta semana</p><p class="k-stat-value"><?= $activosSemana ?><span class="k-stat-unit">de <?= count($pacientes) ?></span></p></div>
        </div>
        <div class="k-card flex items-center gap-4 p-5">
            <span class="hidden sm:grid k-icon-tile k-tile-rose"><?= icono('trend-down') ?></span>
            <div><p class="k-stat-label">Cambio medio de peso</p><p class="k-stat-value"><?= $cambioMedio !== null ? ($cambioMedio > 0 ? '+' : '') . number_format($cambioMedio, 1, ',', '.') : '—' ?><span class="k-stat-unit">kg</span></p></div>
        </div>
    </div>

    <div class="grid items-start gap-6 lg:grid-cols-3">
        <!-- LISTADO -->
        <section class="k-card lg:col-span-2">
            <div class="k-card-head flex-col items-stretch gap-3 sm:flex-row sm:items-center">
                <h2 class="k-section-title"><?= (int) $numPacientes ?> <?= (int) $numPacientes === 1 ? 'paciente' : 'pacientes' ?></h2>
                <?php if ($pacientes): ?>
                    <label class="k-search sm:w-64">
                        <?= icono('search') ?><span class="sr-only">Buscar paciente</span>
                        <input type="search" id="buscadorPaciente" class="k-input h-9" placeholder="Buscar por nombre o correo">
                    </label>
                <?php endif; ?>
            </div>
            <?php if (empty($pacientes)): ?>
                <div class="p-6">
                    <div class="k-empty">
                        <span class="k-empty-icon"><?= icono('users') ?></span>
                        <p class="font-medium k-strong">Aún no tienes pacientes</p>
                        <p class="mt-1 text-sm k-muted">Invítalos con su correo desde el formulario.</p>
                    </div>
                </div>
            <?php else: ?>
                <ul class="k-list">
                    <?php foreach ($pacientes as $p):
                        $inicialPaciente = mb_strtoupper(mb_substr($p['nombre'] ?: $p['correo'], 0, 1, 'UTF-8'), 'UTF-8'); ?>
                        <li class="fila-paciente flex items-center gap-4 px-5 py-4 sm:px-6" data-buscar="<?= e(mb_strtolower(($p['nombre'] ?? '') . ' ' . $p['correo'], 'UTF-8')) ?>">
                            <span class="k-avatar" aria-hidden="true"><?= e($inicialPaciente) ?></span>
                            <div class="min-w-0 flex-1">
                                <p class="nombre-paciente truncate font-medium k-strong"><?= e($p['nombre'] ?: 'Sin nombre') ?></p>
                                <p class="correo-paciente truncate text-sm k-muted"><?= e($p['correo']) ?></p>
                                <p class="mt-0.5 text-xs k-muted"><?= $p['ultimo_registro'] ? 'Último peso: ' . e(formatearFecha($p['ultimo_registro'])) : 'Aún no ha registrado su peso' ?> · Ha apuntado comidas <?= (int) $p['dias_registrados'] ?> de los últimos 7 días</p>
                            </div>
                            <div class="hidden w-28 md:block" title="Comidas planificadas esta semana">
                                <p class="text-xs k-muted">Plan <span class="tabular-nums k-strong"><?= (int) $p['comidas_plan'] ?>/35</span></p>
                                <div class="k-track mt-1.5 h-1.5"><div class="k-bar" style="width: <?= min(100, round($p['comidas_plan'] / 35 * 100)) ?>%"></div></div>
                            </div>
                            <div class="hidden w-32 text-right text-sm sm:block">
                                <p class="flex items-center justify-end gap-2 tabular-nums k-strong">
                                    <?= !empty($p['peso_kg']) ? e(number_format((float) $p['peso_kg'], 1, ',', '')) . ' kg' : '—' ?>
                                    <?php if ($p['peso_inicial'] !== null && !empty($p['peso_kg']) && (float) $p['peso_kg'] != (float) $p['peso_inicial']):
                                        $dif = (float) $p['peso_kg'] - (float) $p['peso_inicial']; ?>
                                        <span class="k-delta <?= $dif < 0 ? 'k-delta-ok' : 'k-delta-warn' ?>"><?= ($dif > 0 ? '+' : '') . number_format($dif, 1, ',', '') ?></span>
                                    <?php endif; ?>
                                </p>
                                <p class="text-xs k-muted"><?= e($objetivosTexto[$p['objetivo'] ?? ''] ?? 'Sin objetivo') ?></p>
                            </div>
                            <?php $nuevos = $sinLeerPorPaciente[(int) $p['id_usuario']] ?? 0; ?>
                            <?php if ($nuevos): ?>
                                <a href="paciente.php?id=<?= (int) $p['id_usuario'] ?>#notas" class="k-badge k-badge-brand shrink-0" title="Mensajes sin leer"><?= icono('message') ?><?= $nuevos ?><span class="sr-only"> <?= $nuevos === 1 ? 'mensaje sin leer' : 'mensajes sin leer' ?></span></a>
                            <?php endif; ?>
                            <a href="paciente.php?id=<?= (int) $p['id_usuario'] ?>" class="k-btn k-btn-secondary k-btn-sm shrink-0">Ver ficha <?= icono('arrow-right') ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p id="noResultados" class="px-6 py-5 text-sm k-muted" hidden>Ningún paciente coincide con la búsqueda.</p>
            <?php endif; ?>
        </section>

        <!-- INVITAR -->
        <aside class="space-y-6">
            <form method="POST" action="vincula_paciente.php" class="k-card k-card-pad space-y-4">
                <?= campoCsrf() ?>
                <div>
                    <h2 class="k-section-title">Invitar a un paciente</h2>
                    <p class="mt-1 text-sm k-muted">Tiene que tener ya una cuenta en KaloAI. Verá la invitación al entrar.</p>
                </div>
                <div>
                    <label for="email_busqueda" class="k-label">Correo del paciente</label>
                    <input type="email" id="email_busqueda" name="email_busqueda" required class="k-input" placeholder="nombre@correo.com">
                </div>
                <button type="submit" class="k-btn k-btn-primary w-full"><?= icono('user-plus') ?>Enviar invitación</button>
            </form>

            <?php if ($pendientes): ?>
                <section class="k-card">
                    <div class="k-card-head"><h2 class="k-section-title">Pendientes</h2><span class="k-badge k-badge-warn"><?= count($pendientes) ?></span></div>
                    <ul class="k-list">
                        <?php foreach ($pendientes as $pend): ?>
                            <li class="px-5 py-3 text-sm sm:px-6">
                                <p class="truncate font-medium k-strong"><?= e($pend['nombre'] ?: $pend['correo']) ?></p>
                                <p class="text-xs k-muted">Enviada el <?= e(formatearFecha($pend['fecha_solicitud'])) ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>
        </aside>
    </div>
</div>

<?php include_once __DIR__ . '/../templates/footer.php'; ?>
