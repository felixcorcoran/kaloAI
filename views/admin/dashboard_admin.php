<?php
/**
 * VISTA: DASHBOARD DE ADMINISTRACIÓN - KaloAI
 * Proporciona una interfaz para la gestión de usuarios, roles, 
 * asignaciones de nutricionistas y visualización de logs de auditoría.
 */
$pageTitle = "KaloAI | Usuarios";
    
session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php';

/* ==========================================================================
   SEGURIDAD Y ACCESO
   ========================================================================== */
if (!isset($_SESSION['user_role_id']) || $_SESSION['user_role_id'] != 1) {
    header("Location: ../dashboard.php");
    exit;
}

$pdo = connectDB();

/* ==========================================================================
   CONSULTAS DE DATOS (ESTADÍSTICAS Y LISTADOS)
   ========================================================================== */

$totalUsuarios = $pdo->query("SELECT COUNT(*) FROM usuario")->fetchColumn();
$totalNutris = $pdo->query("SELECT COUNT(*) FROM usuario WHERE id_rol = 2 AND activo = 1")->fetchColumn();

$nutricionistas = $pdo->query("
    SELECT u.id_usuario, p.nombre 
    FROM usuario u 
    JOIN perfil p ON u.id_usuario = p.id_usuario 
    WHERE u.id_rol = 2 AND u.activo = 1
")->fetchAll();

// Listado maestro de usuarios (sin el hash de la contraseña: el panel no lo necesita)
$usuarios = $pdo->query("
    SELECT u.id_usuario, u.correo, u.id_rol, u.activo, u.fecha_creacion, p.nombre AS nombre_usuario, p.id_nutricionista
    FROM usuario u
    LEFT JOIN perfil p ON u.id_usuario = p.id_usuario
    ORDER BY u.id_rol ASC, u.id_usuario DESC
")->fetchAll();

$logs = $pdo->query("SELECT * FROM logs_actividad ORDER BY fecha DESC LIMIT 10")->fetchAll();

// Mensajes del formulario de contacto: primero los no leídos
$mensajes = $pdo->query("SELECT * FROM mensaje_contacto ORDER BY leido ASC, fecha DESC LIMIT 15")->fetchAll();
$mensajesSinLeer = (int) $pdo->query("SELECT COUNT(*) FROM mensaje_contacto WHERE leido = 0")->fetchColumn();

// Uso de la aplicación
$uso = $pdo->query("SELECT
    (SELECT COUNT(*) FROM receta) AS recetas,
    (SELECT COUNT(*) FROM receta WHERE generado_ia = 1) AS recetas_ia,
    (SELECT COUNT(*) FROM comida_planificada) AS comidas,
    (SELECT COUNT(*) FROM progreso) AS registros,
    (SELECT COUNT(*) FROM inventario) AS despensa")->fetch();

// Altas de los últimos 6 meses (incluido el actual)
$altas = [];
$mesesCortos = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
for ($i = 5; $i >= 0; $i--) {
    $marca = strtotime("first day of -$i month");
    $altas[date('Y-m', $marca)] = ['etiqueta' => $mesesCortos[date('n', $marca) - 1], 'total' => 0];
}
$stmtAltas = $pdo->query("SELECT DATE_FORMAT(fecha_creacion, '%Y-%m') AS mes, COUNT(*) AS total FROM usuario
                          WHERE fecha_creacion >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH) GROUP BY mes");
foreach ($stmtAltas->fetchAll() as $fila) {
    if (isset($altas[$fila['mes']])) $altas[$fila['mes']]['total'] = (int) $fila['total'];
}

// Carga del componente de cabecera 
include_once __DIR__ . '/../templates/header.php'; 
?>

<?php
$roles = [1 => 'Admin', 2 => 'Nutricionista', 3 => 'Usuario'];
$bloqueados = count(array_filter($usuarios, fn($u) => (int) $u['activo'] !== 1));
$conNutri = count(array_filter($usuarios, fn($u) => !empty($u['id_nutricionista'])));
$avisos = [
    'borrado_exitoso' => ['ok', 'Usuario eliminado junto con todos sus datos.'],
    'borrado_fallido' => ['error', 'No se pudo eliminar el usuario. Inténtalo de nuevo.'],
    'no_autoborrado'  => ['error', 'No puedes eliminar tu propia cuenta desde aquí.'],
];
?>

<div class="k-container k-page">
    <div class="k-page-head">
        <div>
            <span class="k-pill"><?= icono('shield') ?>Administración</span>
            <h1 class="k-title mt-3">Usuarios</h1>
            <p class="k-subtitle">Roles, nutricionista asignado y acceso de cada cuenta. Cada cambio queda en la actividad.</p>
        </div>
    </div>

    <?php if (isset($avisos[$_GET['msg'] ?? ''])): [$tipo, $texto] = $avisos[$_GET['msg']]; ?>
        <div class="k-alert k-alert-<?= $tipo ?> mb-6"><?= icono($tipo === 'ok' ? 'check' : 'alert') ?><p><?= e($texto) ?></p></div>
    <?php endif; ?>

    <!-- CIFRAS -->
    <div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <?php foreach ([['Cuentas', $totalUsuarios, 'users', ''], ['Nutricionistas activos', $totalNutris, 'user-plus', 'k-tile-indigo'],
                        ['Pacientes con nutricionista', $conNutri, 'activity', 'k-tile-blue'], ['Bloqueadas', $bloqueados, 'lock', 'k-tile-danger']] as [$etiqueta, $valor, $ic, $tinte]): ?>
            <div class="k-card flex items-center gap-4 p-5">
                <span class="hidden sm:grid k-icon-tile <?= $tinte ?>"><?= icono($ic) ?></span>
                <div><p class="k-stat-label"><?= $etiqueta ?></p><p class="k-stat-value"><?= (int) $valor ?></p></div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ALTAS Y USO -->
    <div class="mb-6 grid gap-6 lg:grid-cols-3">
        <section class="k-card k-card-pad flex flex-col lg:col-span-2">
            <div class="flex items-start justify-between">
                <div>
                    <h2 class="k-section-title">Altas por mes</h2>
                    <p class="mt-1 text-sm k-muted">Cuentas creadas en los últimos seis meses.</p>
                </div>
                <p class="text-right"><span class="k-stat-value"><?= array_sum(array_column($altas, 'total')) ?></span><span class="block text-xs k-muted">en total</span></p>
            </div>
            <div class="relative mt-4 min-h-[14rem] flex-1"><canvas id="altasChart" class="absolute inset-0" aria-label="Gráfico de altas por mes"></canvas></div>
        </section>
        <section class="k-card k-card-pad">
            <h2 class="k-section-title">Uso de KaloAI</h2>
            <?php $pctIa = $uso['recetas'] ? (int) round($uso['recetas_ia'] / $uso['recetas'] * 100) : 0; ?>
            <div class="mt-4 flex items-baseline gap-2"><p class="k-stat-value"><?= (int) $uso['recetas'] ?></p><span class="text-sm k-muted">recetas guardadas</span></div>
            <div class="k-track mt-3 h-2"><div class="k-bar" style="width: <?= $pctIa ?>%"></div></div>
            <p class="mt-2 text-xs k-muted"><?= $pctIa ?>% creadas con IA (<?= (int) $uso['recetas_ia'] ?>)</p>
            <dl class="mt-5 space-y-3 text-sm">
                <div class="flex items-center justify-between"><dt class="flex items-center gap-2 k-muted"><?= icono('calendar', 'h-4 w-4') ?>Comidas planificadas</dt><dd class="font-medium tabular-nums k-strong"><?= (int) $uso['comidas'] ?></dd></div>
                <div class="flex items-center justify-between"><dt class="flex items-center gap-2 k-muted"><?= icono('activity', 'h-4 w-4') ?>Registros de peso</dt><dd class="font-medium tabular-nums k-strong"><?= (int) $uso['registros'] ?></dd></div>
                <div class="flex items-center justify-between"><dt class="flex items-center gap-2 k-muted"><?= icono('package', 'h-4 w-4') ?>Productos en despensas</dt><dd class="font-medium tabular-nums k-strong"><?= (int) $uso['despensa'] ?></dd></div>
            </dl>
        </section>
    </div>

    <!-- USUARIOS -->
    <section class="k-card">
        <div class="k-card-head flex-col items-stretch gap-3 md:flex-row md:items-center">
            <h2 class="k-section-title">Todas las cuentas</h2>
            <div class="flex flex-col gap-2 sm:flex-row">
                <label class="k-search sm:w-64">
                    <?= icono('search') ?><span class="sr-only">Buscar usuario</span>
                    <input type="search" id="realTimeSearch" class="k-input h-9" placeholder="Nombre o correo">
                </label>
                <label for="roleFilter" class="sr-only">Filtrar por rol</label>
                <select id="roleFilter" class="k-input h-9 sm:w-44">
                    <option value="all">Todos los roles</option>
                    <?php foreach ($roles as $id => $nombre): ?><option value="<?= $id ?>"><?= $nombre ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>

        <ul class="k-list">
            <?php foreach ($usuarios as $u):
                $esYo = (int) $u['id_usuario'] === (int) $_SESSION['user_id'];
                $nombreU = $u['nombre_usuario'] ?: 'Sin perfil';
                $activo = (int) $u['activo'] === 1; ?>
                <li class="user-row grid items-center gap-3 px-5 py-4 sm:px-6 md:grid-cols-[minmax(0,1fr)_10.5rem_12rem_auto]"
                    data-buscar="<?= e(mb_strtolower($nombreU . ' ' . $u['correo'], 'UTF-8')) ?>" data-rol="<?= (int) $u['id_rol'] ?>">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="k-avatar <?= $activo ? '' : 'opacity-40' ?>" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($nombreU, 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
                        <div class="min-w-0">
                            <p class="truncate font-medium k-strong"><?= e($nombreU) ?> <?php if ($esYo): ?><span class="k-badge ml-1">Tú</span><?php endif; ?></p>
                            <p class="truncate text-sm k-muted"><?= e($u['correo']) ?> · desde <?= e(formatearFecha($u['fecha_creacion'])) ?></p>
                        </div>
                    </div>

                    <label class="block">
                        <span class="sr-only">Rol de <?= e($nombreU) ?></span>
                        <select class="k-input h-9" onchange="actualizarCampoUsuario(<?= (int) $u['id_usuario'] ?>, 'rol', this.value)" <?= $esYo ? 'disabled' : '' ?>>
                            <?php foreach ($roles as $id => $nombre): ?>
                                <option value="<?= $id ?>" <?= (int) $u['id_rol'] === $id ? 'selected' : '' ?>><?= $nombre ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <?php if ((int) $u['id_rol'] === 3): ?>
                        <label class="block">
                            <span class="sr-only">Nutricionista de <?= e($nombreU) ?></span>
                            <select class="k-input h-9" onchange="actualizarCampoUsuario(<?= (int) $u['id_usuario'] ?>, 'nutri', this.value)">
                                <option value="">Sin nutricionista</option>
                                <?php foreach ($nutricionistas as $nutri): ?>
                                    <option value="<?= (int) $nutri['id_usuario'] ?>" <?= (int) $u['id_nutricionista'] === (int) $nutri['id_usuario'] ? 'selected' : '' ?>><?= e($nutri['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php else: ?>
                        <span class="hidden text-sm md:block k-muted">—</span>
                    <?php endif; ?>

                    <div class="flex items-center justify-end gap-1">
                        <?php if (!$esYo): ?>
                            <button type="button" onclick="alternarEstadoUsuario(<?= (int) $u['id_usuario'] ?>, <?= (int) $u['id_rol'] ?>, <?= $activo ? 0 : 1 ?>)"
                                    class="k-btn k-btn-sm <?= $activo ? 'k-btn-secondary' : 'k-btn-primary' ?>" title="<?= $activo ? 'Bloquear el acceso' : 'Devolver el acceso' ?>">
                                <?= $activo ? 'Bloquear' : 'Activar' ?>
                            </button>
                            <button type="button" onclick="abrirModalEliminarAdmin(<?= (int) $u['id_usuario'] ?>, <?= e(json_html($nombreU)) ?>)"
                                    class="k-btn k-btn-ghost k-btn-icon k-btn-danger-text" aria-label="Eliminar a <?= e($nombreU) ?>"><?= icono('trash') ?></button>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <p id="sinUsuarios" class="px-6 py-5 text-sm k-muted" hidden>Ninguna cuenta coincide con la búsqueda.</p>
    </section>

    <!-- MENSAJES DE CONTACTO -->
    <section id="mensajes" class="k-card mt-6 scroll-mt-6">
        <div class="k-card-head">
            <h2 class="k-section-title">Mensajes de contacto</h2>
            <?php if ($mensajesSinLeer): ?><span class="k-badge k-badge-warn"><?= $mensajesSinLeer ?> sin leer</span><?php else: ?><span class="text-xs k-muted">Al día</span><?php endif; ?>
        </div>
        <?php if (empty($mensajes)): ?>
            <p class="px-6 py-5 text-sm k-muted">Nadie ha escrito todavía desde la página de contacto.</p>
        <?php else: ?>
            <ul class="k-list">
                <?php foreach ($mensajes as $m): ?>
                    <li class="px-5 py-4 sm:px-6 <?= $m['leido'] ? 'opacity-70' : '' ?>">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-medium k-strong"><?= e($m['nombre']) ?> <span class="font-normal k-muted">· <?= e($m['correo']) ?></span><?php if (!$m['leido']): ?> <span class="k-badge k-badge-brand ml-1">Nuevo</span><?php endif; ?></p>
                                <p class="text-xs k-muted"><?= date('d/m/Y H:i', strtotime($m['fecha'])) ?></p>
                            </div>
                            <div class="flex shrink-0 gap-1">
                                <a href="mailto:<?= e($m['correo']) ?>?subject=<?= rawurlencode('Re: tu mensaje a KaloAI') ?>" class="k-btn k-btn-secondary k-btn-sm"><?= icono('mail') ?>Responder</a>
                                <?php if (!$m['leido']): ?>
                                    <form method="POST" action="mensajes.php" class="m-0"><?= campoCsrf() ?><input type="hidden" name="id_mensaje" value="<?= (int) $m['id_mensaje'] ?>"><input type="hidden" name="accion" value="leido">
                                        <button type="submit" class="k-btn k-btn-ghost k-btn-sm" title="Marcar como leído"><?= icono('check') ?><span class="hidden sm:inline">Leído</span></button></form>
                                <?php endif; ?>
                                <form method="POST" action="mensajes.php" class="m-0"><?= campoCsrf() ?><input type="hidden" name="id_mensaje" value="<?= (int) $m['id_mensaje'] ?>"><input type="hidden" name="accion" value="borrar">
                                    <button type="submit" class="k-btn k-btn-ghost k-btn-icon k-btn-danger-text h-8 w-8" aria-label="Borrar el mensaje de <?= e($m['nombre']) ?>"><?= icono('trash', 'h-4 w-4') ?></button></form>
                            </div>
                        </div>
                        <p class="mt-2 whitespace-pre-line text-sm leading-relaxed k-strong"><?= e($m['mensaje']) ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <!-- ACTIVIDAD -->
    <section class="k-card mt-6">
        <div class="k-card-head"><h2 class="k-section-title">Actividad reciente</h2><span class="text-xs k-muted">Últimos 10 cambios</span></div>
        <?php if (empty($logs)): ?>
            <p class="px-6 py-5 text-sm k-muted">Sin actividad todavía.</p>
        <?php else: ?>
            <ul class="k-list">
                <?php foreach ($logs as $log): ?>
                    <li class="flex items-start gap-4 px-5 py-3 text-sm sm:px-6">
                        <span class="mt-0.5 shrink-0 k-muted"><?= icono('clock', 'h-4 w-4') ?></span>
                        <!-- El detalle se guarda como HTML con los nombres ya escapados (api_admin.php) -->
                        <span class="flex-1 [&_b]:font-semibold [&_b]:text-[color:var(--k-text)]"><?= $log['detalle'] ?></span>
                        <span class="shrink-0 text-xs tabular-nums k-muted"><?= date('d/m H:i', strtotime($log['fecha'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<!-- MODAL: ELIMINAR USUARIO -->
<div id="modalBorrarUsuario" class="k-modal" hidden role="dialog" aria-modal="true" aria-labelledby="tituloBorrarUsuario">
    <div class="k-modal-backdrop"></div>
    <div class="k-modal-panel max-w-sm">
        <h2 id="tituloBorrarUsuario" class="k-modal-title">¿Eliminar a <span id="borrarUsuarioNombre">este usuario</span>?</h2>
        <p class="mt-2 text-sm k-muted">Se borrarán su perfil, sus recetas, su plan, su despensa y su progreso. No se puede deshacer.</p>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="k-btn k-btn-secondary" data-cerrar-modal>Cancelar</button>
            <button type="button" class="k-btn k-btn-danger" data-confirmar>Eliminar</button>
        </div>
    </div>
</div>

<script>
    window.adminData = <?= json_html(['etiquetas' => array_column(array_values($altas), 'etiqueta'), 'altas' => array_column(array_values($altas), 'total')]) ?>;
</script>

<?php include_once __DIR__ . '/../templates/footer.php'; ?>
