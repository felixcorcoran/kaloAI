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
    SELECT u.id_usuario, p.nombre, u.correo, p.peso_kg, p.objetivo 
    FROM usuario u
    JOIN perfil p ON u.id_usuario = p.id_usuario
    WHERE p.id_nutricionista = ?
");
$stmt->execute([$nutriId]);
$pacientes = $stmt->fetchAll(PDO::FETCH_ASSOC);



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
            <h1 class="k-title">Pacientes</h1>
            <p class="k-subtitle">Entra en la cuenta de un paciente para ajustar su plan, sus recetas y su perfil como si fueras él.</p>
        </div>
    </div>

    <?php foreach ($avisos as $clave => [$tipo, $texto]):
        [$param, $valor] = explode(':', $clave);
        if (($_GET[$param] ?? null) === $valor): ?>
            <div class="k-alert k-alert-<?= $tipo ?> mb-6"><?= icono($tipo === 'ok' ? 'check' : 'alert') ?><p><?= e($texto) ?></p></div>
    <?php endif; endforeach; ?>

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
                            </div>
                            <div class="hidden text-right text-sm sm:block">
                                <p class="tabular-nums k-strong"><?= !empty($p['peso_kg']) ? e(number_format((float) $p['peso_kg'], 1, ',', '')) . ' kg' : '—' ?></p>
                                <p class="text-xs k-muted"><?= e($objetivosTexto[$p['objetivo'] ?? ''] ?? 'Sin objetivo') ?></p>
                            </div>
                            <form method="POST" action="entrar_como_paciente.php" class="m-0 shrink-0">
                                <?= campoCsrf() ?>
                                <input type="hidden" name="id_paciente" value="<?= (int) $p['id_usuario'] ?>">
                                <button type="submit" class="k-btn k-btn-secondary k-btn-sm">Gestionar <?= icono('arrow-right') ?></button>
                            </form>
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
