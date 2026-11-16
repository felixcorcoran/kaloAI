<?php
/**
 * VISTA: FICHA DEL PACIENTE - KaloAI
 * El nutricionista ve la evolución de un paciente (peso, lo que come, su plan)
 * sin entrar en su cuenta, y le deja notas que el paciente lee en su panel.
 */
$pageTitle = "KaloAI | Ficha del paciente";

session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php'; // incluye la comprobación del token CSRF
require_once __DIR__ . '/../../core/seguimiento.php';

// Nutricionistas y administradores, y nunca desde el modo gestión
if (!isset($_SESSION['user_role_id']) || $_SESSION['user_role_id'] > 2 || !empty($_SESSION['is_impersonating'])) {
    header("Location: ../dashboard.php");
    exit;
}

$nutriId = (int) $_SESSION['user_id'];
$pacienteId = (int) ($_GET['id'] ?? 0);
$pdo = connectDB();
if (!$pacienteId || !esPacienteDe($pdo, $nutriId, $pacienteId)) {
    header("Location: dashboard_nutri.php");
    exit;
}
$urlFicha = 'paciente.php?id=' . $pacienteId;

// Notas: crear o borrar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'nota') {
        $error = crearNota($pdo, $nutriId, $pacienteId, (string) ($_POST['texto'] ?? ''));
        $_SESSION['aviso_ficha'] = $error ? ['error', $error] : ['ok', 'Nota enviada. Tu paciente la verá en su panel.'];
    } elseif ($accion === 'borrar_nota') {
        borrarNota($pdo, $nutriId, (int) ($_POST['id_nota'] ?? 0));
        $_SESSION['aviso_ficha'] = ['ok', 'Nota borrada.'];
    }
    header('Location: ' . $urlFicha . '#notas');
    exit;
}

$d = datosSeguimiento($pdo, $pacienteId);
$notas = notasDelPaciente($pdo, $pacienteId);
$aviso = $_SESSION['aviso_ficha'] ?? null;
unset($_SESSION['aviso_ficha']);

include_once __DIR__ . '/../templates/header.php';

/* ==========================================================================
   DATOS PARA LA VISTA
   ========================================================================== */
$perfil = $d['perfil'];
$nombre = $perfil['nombre'] ?: $perfil['correo'];
$meta = (int) ($perfil['calorias_netas_objetivo'] ?? 0);
$objetivosTexto = [
    'perdida_rapida' => 'Pérdida rápida', 'perdida_moderada' => 'Pérdida moderada', 'perdida_grasa' => 'Pérdida de grasa',
    'mantenimiento' => 'Mantenimiento', 'ganancia_muscular' => 'Ganancia muscular',
    'recomposicion_corporal' => 'Recomposición', 'rendimiento_deportivo' => 'Rendimiento deportivo',
];
$pesos = array_values(array_filter($d['progreso'], fn($p) => $p['peso_kg'] !== null));
$pesoActual = $pesos ? (float) end($pesos)['peso_kg'] : null;
$pesoInicial = $pesos ? (float) $pesos[0]['peso_kg'] : null;
$cambio = count($pesos) > 1 ? round($pesoActual - $pesoInicial, 1) : null;
$ultimoPeso = $pesos ? end($pesos)['fecha'] : null;
$consumo = $d['consumo'];
$coma = fn($n, $dec = 1) => number_format((float) $n, $dec, ',', '.');
$entero = fn($n) => number_format((float) $n, 0, ',', '.');
$mesesCortos = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
$fechaCorta = fn(string $f) => (int) substr($f, 8, 2) . ' ' . $mesesCortos[(int) substr($f, 5, 2) - 1];
$tiposRes = ['Alergia (Riesgo Alto)' => 'k-badge-danger', 'Intolerancia (Riesgo Medio)' => 'k-badge-warn'];
$planHoy = $d['plan'][(int) date('N') - 1];
?>

<div class="k-container k-page">
    <a href="dashboard_nutri.php" class="k-back"><?= icono('chevron-left', 'h-4 w-4') ?>Pacientes</a>

    <div class="k-page-head">
        <div class="flex items-center gap-4">
            <span class="k-avatar h-14 w-14 text-xl" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($nombre, 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
            <div>
                <span class="k-pill"><?= icono('target') ?><?= e($objetivosTexto[$perfil['objetivo'] ?? ''] ?? 'Sin objetivo') ?><?= $meta ? ' · ' . $entero($meta) . ' kcal/día' : '' ?></span>
                <h1 class="k-title mt-2"><?= e($nombre) ?></h1>
                <p class="text-sm k-muted"><?= e($perfil['correo']) ?> · en KaloAI desde <?= e(formatearFecha($perfil['fecha_creacion'])) ?></p>
            </div>
        </div>
        <form method="POST" action="entrar_como_paciente.php" class="m-0">
            <?= campoCsrf() ?>
            <input type="hidden" name="id_paciente" value="<?= $pacienteId ?>">
            <button type="submit" class="k-btn k-btn-secondary"><?= icono('pencil') ?>Ajustar su plan</button>
        </form>
    </div>

    <?php if ($d['restricciones']): ?>
        <div class="-mt-2 mb-6 flex flex-wrap items-center gap-1.5">
            <span class="mr-1 text-xs k-muted">No puede tomar:</span>
            <?php foreach ($d['restricciones'] as $r): ?><span class="k-badge <?= $tiposRes[$r['nota']] ?? '' ?>"><?= e($r['nombre_ingrediente']) ?></span><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- CIFRAS -->
    <div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <div class="k-card flex flex-col p-5">
            <div class="flex items-center justify-between"><p class="k-stat-label">Peso</p><span class="hidden sm:grid k-icon-tile h-8 w-8 rounded-lg"><?= icono('activity', 'h-4 w-4') ?></span></div>
            <p class="k-stat-value mt-3"><?= $pesoActual !== null ? $coma($pesoActual) : '—' ?><span class="k-stat-unit">kg</span></p>
            <div class="mt-auto pt-2 text-xs k-muted">
                <?php if ($cambio !== null): ?><span class="k-delta <?= $cambio <= 0 ? 'k-delta-ok' : 'k-delta-warn' ?>"><?= ($cambio > 0 ? '+' : '') . $coma($cambio) ?> kg</span> desde el inicio<?php else: ?>Sin registros suficientes<?php endif; ?>
            </div>
        </div>
        <div class="k-card flex flex-col p-5">
            <div class="flex items-center justify-between"><p class="k-stat-label">Constancia</p><span class="hidden sm:grid k-icon-tile h-8 w-8 rounded-lg k-tile-indigo"><?= icono('check', 'h-4 w-4') ?></span></div>
            <p class="k-stat-value mt-3"><?= $consumo['con_registro'] ?><span class="k-stat-unit">/ 14 días</span></p>
            <p class="mt-auto pt-2 text-xs k-muted">Días en los que ha apuntado lo que come</p>
        </div>
        <div class="k-card flex flex-col p-5">
            <div class="flex items-center justify-between"><p class="k-stat-label">Media comida</p><span class="hidden sm:grid k-icon-tile h-8 w-8 rounded-lg k-tile-orange"><?= icono('flame', 'h-4 w-4') ?></span></div>
            <p class="k-stat-value mt-3"><?= $consumo['media'] !== null ? $entero($consumo['media']) : '—' ?><span class="k-stat-unit">kcal</span></p>
            <p class="mt-auto pt-2 text-xs k-muted"><?= $consumo['media'] !== null && $meta ? round($consumo['media'] / $meta * 100) . '% de su objetivo' : 'En los días que apunta' ?></p>
        </div>
        <div class="k-card flex flex-col p-5">
            <div class="flex items-center justify-between"><p class="k-stat-label">Plan de esta semana</p><span class="hidden sm:grid k-icon-tile h-8 w-8 rounded-lg k-tile-blue"><?= icono('calendar', 'h-4 w-4') ?></span></div>
            <p class="k-stat-value mt-3"><?= $d['comidasPlan'] ?><span class="k-stat-unit">/ 35</span></p>
            <p class="mt-auto pt-2 text-xs k-muted">Hoy tiene <?= $entero($planHoy) ?> kcal planificadas</p>
        </div>
    </div>

    <!-- GRÁFICAS -->
    <div class="mb-6 grid gap-6 lg:grid-cols-3">
        <section class="k-card k-card-pad flex flex-col lg:col-span-2">
            <h2 class="k-section-title">Lo que ha comido</h2>
            <p class="mt-0.5 text-sm k-muted">Calorías apuntadas cada día de las dos últimas semanas<?= $meta ? ', frente a su objetivo' : '' ?>.</p>
            <div class="relative mt-4 min-h-[15rem] flex-1"><canvas id="consumoPaciente" class="absolute inset-0" aria-label="Calorías comidas por día"></canvas></div>
        </section>
        <section class="k-card k-card-pad flex flex-col">
            <h2 class="k-section-title">Peso</h2>
            <p class="mt-0.5 text-sm k-muted"><?= count($pesos) ?> registros<?= $ultimoPeso ? ' · último el ' . e($fechaCorta($ultimoPeso)) : '' ?></p>
            <?php if (count($pesos) >= 2): ?>
                <div class="relative mt-4 min-h-[12rem] flex-1"><canvas id="pesoPaciente" class="absolute inset-0" aria-label="Evolución del peso"></canvas></div>
            <?php else: ?>
                <div class="k-empty mt-4 flex-1 py-8"><span class="k-empty-icon"><?= icono('activity') ?></span><p class="text-sm k-muted">Aún no hay suficientes registros de peso.</p></div>
            <?php endif; ?>
        </section>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <!-- LO ÚLTIMO APUNTADO -->
        <section class="k-card min-w-0">
            <div class="k-card-head"><h2 class="k-section-title">Lo último que ha apuntado</h2></div>
            <?php if (!$d['recientes']): ?>
                <p class="px-6 py-5 text-sm k-muted">Todavía no ha apuntado ninguna comida.</p>
            <?php else: ?>
                <ul class="k-list">
                    <?php foreach ($d['recientes'] as $r): ?>
                        <li class="flex items-center gap-3 px-5 py-3 text-sm sm:px-6">
                            <span class="w-14 shrink-0 text-xs tabular-nums k-muted"><?= e($fechaCorta($r['fecha'])) ?></span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate k-strong"><?= e($r['descripcion']) ?></p>
                                <p class="text-xs k-muted"><?= e($r['momento_comida']) ?></p>
                            </div>
                            <?php if (!$r['id_receta']): ?><span class="k-badge k-badge-warn hidden sm:inline-flex">Fuera del plan</span><?php endif; ?>
                            <span class="shrink-0 tabular-nums k-muted"><?= (int) $r['calorias'] ?> kcal</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <!-- NOTAS -->
        <section id="notas" class="k-card min-w-0 scroll-mt-6">
            <div class="k-card-head"><h2 class="k-section-title">Notas para <?= e(explode(' ', $nombre)[0]) ?></h2><span class="text-xs k-muted">Las ve en su panel</span></div>
            <div class="p-5 sm:p-6">
                <?php if ($aviso): [$tipo, $texto] = $aviso; ?>
                    <div class="k-alert k-alert-<?= $tipo === 'ok' ? 'ok' : 'error' ?> mb-4" role="status"><?= icono($tipo === 'ok' ? 'check' : 'alert') ?><p><?= e($texto) ?></p></div>
                <?php endif; ?>
                <form method="POST" action="<?= e($urlFicha) ?>" class="space-y-3">
                    <?= campoCsrf() ?>
                    <input type="hidden" name="accion" value="nota">
                    <label for="textoNota" class="sr-only">Nota</label>
                    <textarea id="textoNota" name="texto" rows="3" required maxlength="<?= NOTA_MAX_CARACTERES ?>" class="k-input" placeholder="Ej. Esta semana prueba a cenar antes de las 21:00 y añade verdura en la comida."></textarea>
                    <div class="flex justify-end"><button type="submit" class="k-btn k-btn-primary k-btn-sm"><?= icono('mail') ?>Enviar nota</button></div>
                </form>
                <?php if ($notas): ?>
                    <ul class="mt-5 space-y-3">
                        <?php foreach ($notas as $n): ?>
                            <li class="k-subtle rounded-2xl p-4 text-sm">
                                <div class="flex items-start justify-between gap-3">
                                    <p class="whitespace-pre-line leading-relaxed k-strong"><?= e($n['texto']) ?></p>
                                    <?php if ((int) $n['id_nutricionista'] === $nutriId): ?>
                                        <form method="POST" action="<?= e($urlFicha) ?>" class="m-0 shrink-0">
                                            <?= campoCsrf() ?>
                                            <input type="hidden" name="accion" value="borrar_nota">
                                            <input type="hidden" name="id_nota" value="<?= (int) $n['id_nota'] ?>">
                                            <button type="submit" class="k-btn k-btn-ghost k-btn-icon k-btn-danger-text h-7 w-7" aria-label="Borrar la nota"><?= icono('trash', 'h-4 w-4') ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <p class="mt-2 text-xs k-muted"><?= e(formatearFecha($n['fecha'])) ?> · <?= $n['leida'] ? 'Leída' : 'Sin leer' ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<script>
    // Datos para las gráficas (main.js)
    window.fichaPaciente = <?= json_html([
        'progreso' => array_map(fn($p) => ['fecha' => $p['fecha'], 'peso_kg' => $p['peso_kg']], $pesos),
        'dias'     => array_map($fechaCorta, array_keys($consumo['dias'])),
        'consumo'  => array_values($consumo['dias']),
        'meta'     => $meta,
    ]) ?>;
</script>

<?php include_once __DIR__ . '/../templates/footer.php'; ?>
