<?php
/**
 * GESTIÓN DE PROGRESO Y SEGUIMIENTO ANTROPOMÉTRICO - KaloAI
 * Este script gestiona el historial de peso y medidas, asegurando la sincronización
 * automática con el perfil metabólico para mantener los cálculos de TDEE actualizados.
 */

$pageTitle = "KaloAI | Mi Progreso y Seguimiento";
session_start();

require_once __DIR__ . '/../../core/utilidades.php';
require_once __DIR__ . '/../../core/configuracion.php'; 

// CONTROL DE ACCESO: Verificación de sesión activa
if (!isset($_SESSION['user_id'])) {
    redirect('../auth/login.php');
}

$userId = $_SESSION['user_id'];
$pdo = connectDB();
$progresoData = [];
$message = null;
$messageType = 'success';

/**
 * ==========================================================
 * ACCIÓN: ELIMINAR REGISTRO Y RE-SINCRONIZAR
 * Al borrar un dato, el sistema busca la medición anterior más reciente
 * para no dejar el perfil con datos obsoletos.
 * ==========================================================
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_fecha'])) {

    $fechaBorrar = limpiarInput($_POST['delete_fecha']);

    try {
        $pdo->beginTransaction(); // Inicio de operación atómica

        // 1. Eliminación del registro puntual en el historial
        $sqlDelete = "DELETE FROM progreso WHERE id_usuario = ? AND fecha = ?";
        $stmtDelete = $pdo->prepare($sqlDelete);
        $stmtDelete->execute([$userId, $fechaBorrar]);

        // 2. RE-CÁLCULO: Localizar el nuevo "último peso" tras la eliminación
        $sqlNuevoUltimo = "SELECT peso_kg FROM progreso 
                           WHERE id_usuario = ? 
                           ORDER BY fecha DESC LIMIT 1";
        $stmtNuevo = $pdo->prepare($sqlNuevoUltimo);
        $stmtNuevo->execute([$userId]);
        $nuevoPesoData = $stmtNuevo->fetch(PDO::FETCH_ASSOC);

        // 3. SINCRONIZACIÓN: el perfil pasa a tener el último peso que queda y
        //    se recalculan sus metas. Si no queda ningún registro, el perfil no
        //    se toca (antes se ponía el peso a 0 y se rompían los cálculos).
        if ($nuevoPesoData && $nuevoPesoData['peso_kg'] !== null) {
            actualizarPesoYObjetivos($pdo, (int) $userId, (float) $nuevoPesoData['peso_kg']);
        }

        $pdo->commit(); // Consolidación de cambios

        $f_mostrar = function_exists('formatearFecha') ? formatearFecha($fechaBorrar) : $fechaBorrar;
        $message = "Registro del " . $f_mostrar . " eliminado. Perfil sincronizado con el historial.";

    } catch (\PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Error al eliminar y sincronizar: " . $e->getMessage());
        $message = "No se pudo eliminar el registro o actualizar el perfil.";
        $messageType = 'error';
    }
}

/**
 * ==========================================================
 * ACCIÓN: REGISTRAR NUEVO PROGRESO
 * Valida límites físicos y actualiza el estado actual del usuario.
 * ==========================================================
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_progreso'])) {

    // Sanitización y tipado de datos médicos
    $fecha = limpiarInput($_POST['fecha'] ?? '');
    $peso_kg_input = filter_var($_POST['peso_kg'] ?? '', FILTER_VALIDATE_FLOAT);
    $cinturaTexto = trim((string) ($_POST['cintura_cm'] ?? ''));
    $cintura_cm = filter_var($cinturaTexto, FILTER_VALIDATE_FLOAT);
    $nota = mb_substr(limpiarInput($_POST['nota'] ?? ''), 0, 100);
    $hoy = date('Y-m-d');
    
    $errors = [];

    // VALIDACIONES DE INTEGRIDAD NUTRICIONAL
    $fechaObj = DateTime::createFromFormat('!Y-m-d', $fecha);
    if (!$fechaObj || $fechaObj->format('Y-m-d') !== $fecha) {
        $errors[] = "La fecha no es válida.";
    } elseif ($fecha > $hoy) {
        $errors[] = "La fecha no puede ser futura.";
    }

    if (!$peso_kg_input || $peso_kg_input < 30 || $peso_kg_input > 350) {
        $errors[] = "Por favor, introduce un peso realista (30 - 350 kg).";
    }

    if ($cinturaTexto !== '' && ($cintura_cm === false || $cintura_cm < 40 || $cintura_cm > 250)) {
        $errors[] = "La medida de cintura debe estar entre 40 y 250 cm.";
    }

    if (empty($errors)) {
        try {
            // Si ese día ya tenía registro, se sustituye (antes había que borrarlo a mano)
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM progreso WHERE id_usuario = ? AND fecha = ?");
            $checkStmt->execute([$userId, $fecha]);
            $yaExistia = $checkStmt->fetchColumn() > 0;

            $pdo->beginTransaction();
            if ($yaExistia) {
                $pdo->prepare("DELETE FROM progreso WHERE id_usuario = ? AND fecha = ?")->execute([$userId, $fecha]);
            }

            $cintura_val = ($cintura_cm > 0) ? $cintura_cm : null;

            // A. Persistencia en historial de progreso
            $sqlProgreso = "INSERT INTO progreso (id_usuario, fecha, peso_kg, cintura_cm, nota) 
                            VALUES (?, ?, ?, ?, ?)";
            $stmtP = $pdo->prepare($sqlProgreso);
            $stmtP->execute([$userId, $fecha, $peso_kg_input, $cintura_val, $nota]);

            // B. ACTUALIZACIÓN DINÁMICA: Si es la medición más reciente, actualiza el perfil principal
            $sqlUltima = "SELECT MAX(fecha) FROM progreso WHERE id_usuario = ?";
            $stmtUltima = $pdo->prepare($sqlUltima);
            $stmtUltima->execute([$userId]);
            $fechaMasReciente = $stmtUltima->fetchColumn();

            if ($fecha >= $fechaMasReciente) {
                // También recalcula el TDEE y los macros con el nuevo peso
                actualizarPesoYObjetivos($pdo, (int) $userId, (float) $peso_kg_input);
            }

            $pdo->commit();
            $message = $yaExistia
                ? "Registro del " . formatearFecha($fecha) . " actualizado. Tus objetivos se han recalculado."
                : "¡Progreso guardado! Tus calorías y macros se han recalculado con tu peso.";
            $messageType = 'success';
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Error en tabla perfil o progreso: " . $e->getMessage());
            $message = "Error en la base de datos al sincronizar el perfil.";
            $messageType = 'error';
        }
    } else {
        $message = $errors[0];
        $messageType = 'error';
    }
}

// Registro rápido desde el panel «Hoy»: se vuelve allí con el aviso
if (($_POST['volver'] ?? '') === 'panel' && isset($_POST['register_progreso'])) {
    $_SESSION['aviso_panel'] = [$messageType === 'success' ? 'ok' : 'error', strip_tags((string) $message)];
    header('Location: ../dashboard.php');
    exit;
}

/**
 * ==========================================================
 * EXTRACCIÓN DE DATOS PARA ANÁLISIS VISUAL
 * Prepara los datos para ser consumidos por librerías de gráficos (como Chart.js).
 * ==========================================================
 */
try {
    // Orden ASCENDENTE: Requisito técnico para graficar series temporales correctamente
    $sql = "SELECT fecha, peso_kg, cintura_cm, nota 
             FROM progreso 
             WHERE id_usuario = ? 
             ORDER BY fecha ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $progresoData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // HISTORIAL VISUAL: Invertimos el orden para mostrar lo más reciente primero en la tabla
    $progresoHistorial = array_reverse($progresoData);

} catch (\PDOException $e) {
    error_log("Error al cargar historial de progreso: " . $e->getMessage());
    $message = "No se pudo cargar el historial de progreso.";
    $messageType = 'error';
}

// Inyección de datos al contexto de JavaScript para renderizado de gráficas
$jsProgresoData = json_html($progresoData); // escapado: una nota con "</script>" no rompe la página

// Carga del componente de cabecera 
include_once __DIR__ . '/../templates/header.php';
?>
<?php
// Resumen calculado aquí (antes lo calculaba el JavaScript)
$pesos = array_values(array_filter(array_column($progresoData, 'peso_kg'), fn($p) => $p !== null));
$cinturas = array_values(array_filter(array_column($progresoData, 'cintura_cm'), fn($c) => $c !== null && $c > 0));
$pesoInicial = $pesos[0] ?? null;
$pesoActual = $pesos ? end($pesos) : null;
$cambioPeso = ($pesoInicial !== null && count($pesos) > 1) ? round($pesoActual - $pesoInicial, 1) : null;
$cambioCintura = count($cinturas) > 1 ? round(end($cinturas) - $cinturas[0], 1) : null;
$coma = fn($n, $d = 1) => number_format((float) $n, $d, ',', '.');

// IMC con la altura del perfil, y ritmo medio por semana desde el primer registro
$stmtPerfil = $pdo->prepare("SELECT altura_cm, objetivo FROM perfil WHERE id_usuario = ?");
$stmtPerfil->execute([$userId]);
$perfilProg = $stmtPerfil->fetch() ?: [];
$altura = (float) ($perfilProg['altura_cm'] ?? 0);
$imc = ($altura > 0 && $pesoActual !== null) ? round($pesoActual / (($altura / 100) ** 2), 1) : null;
$categoriaImc = $imc === null ? null : ($imc < 18.5 ? ['Bajo peso', 'k-badge-warn'] : ($imc < 25 ? ['Saludable', 'k-badge-ok'] : ($imc < 30 ? ['Sobrepeso', 'k-badge-warn'] : ['Obesidad', 'k-badge-danger'])));
$conPeso = array_values(array_filter($progresoData, fn($r) => $r['peso_kg'] !== null));
$semanas = count($conPeso) > 1 ? max(1, (strtotime(end($conPeso)['fecha']) - strtotime($conPeso[0]['fecha'])) / 604800) : 0;
$ritmo = $semanas ? round($cambioPeso / $semanas, 2) : null;
$cinturaActual = $cinturas ? end($cinturas) : null;
$quiereBajar = in_array($perfilProg['objetivo'] ?? '', ['perdida_rapida', 'perdida_moderada', 'perdida_grasa'], true);
$quiereSubir = ($perfilProg['objetivo'] ?? '') === 'ganancia_muscular';
// "Va bien" según el objetivo: bajar si quiere perder, subir si quiere ganar
$vaBien = fn(?float $cambio) => $cambio === null ? true : ($quiereSubir ? $cambio >= 0 : ($quiereBajar ? $cambio <= 0 : abs($cambio) < 1));
$signo = fn($n) => ($n > 0 ? '+' : '') . number_format($n, 1, ',', '.');
?>

<div class="k-container k-page">
    <div class="k-page-head">
        <div>
            <span class="k-pill"><?= icono('activity') ?><?= count($progresoData) ?> <?= count($progresoData) === 1 ? 'registro' : 'registros' ?></span>
            <h1 class="k-title mt-3">Progreso</h1>
            <p class="k-subtitle">Registra tu peso con regularidad: tus objetivos de calorías y macros se recalculan con él.</p>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="k-alert k-alert-<?= $messageType === 'success' ? 'ok' : 'error' ?> mb-6" role="alert">
            <?= icono($messageType === 'success' ? 'check' : 'alert') ?><p><?= e($message) ?></p>
        </div>
    <?php endif; ?>

    <!-- RESUMEN -->
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="k-card flex flex-col p-5">
            <div class="flex items-center justify-between"><p class="k-stat-label">Peso actual</p><span class="k-icon-tile h-8 w-8 rounded-lg"><?= icono('activity', 'h-4 w-4') ?></span></div>
            <p class="k-stat-value mt-3"><?= $pesoActual !== null ? $coma($pesoActual) : '—' ?><span class="k-stat-unit">kg</span></p>
            <div class="mt-auto flex items-center gap-2 pt-3 text-xs k-muted">
                <?php if ($cambioPeso !== null): ?>
                    <span class="k-delta <?= $vaBien($cambioPeso) ? 'k-delta-ok' : 'k-delta-warn' ?>"><span class="k-delta-icon"><?= icono($cambioPeso <= 0 ? 'trend-down' : 'trend-up') ?></span><?= $signo($cambioPeso) ?> kg</span>
                    desde <?= $coma($pesoInicial) ?> kg
                <?php else: ?>Registra tu peso para ver el cambio<?php endif; ?>
            </div>
        </div>
        <div class="k-card flex flex-col p-5">
            <div class="flex items-center justify-between"><p class="k-stat-label">Cintura</p><span class="k-icon-tile h-8 w-8 rounded-lg k-tile-indigo"><?= icono('target', 'h-4 w-4') ?></span></div>
            <p class="k-stat-value mt-3"><?= $cinturaActual !== null ? $coma($cinturaActual) : '—' ?><span class="k-stat-unit">cm</span></p>
            <div class="mt-auto flex items-center gap-2 pt-3 text-xs k-muted">
                <?php if ($cambioCintura !== null): ?>
                    <span class="k-delta <?= $cambioCintura <= 0 ? 'k-delta-ok' : 'k-delta-warn' ?>"><span class="k-delta-icon"><?= icono($cambioCintura <= 0 ? 'trend-down' : 'trend-up') ?></span><?= $signo($cambioCintura) ?> cm</span>
                    desde el inicio
                <?php else: ?>Opcional: mídela cuando te peses<?php endif; ?>
            </div>
        </div>
        <div class="k-card flex flex-col p-5">
            <div class="flex items-center justify-between"><p class="k-stat-label">Índice de masa corporal</p><span class="k-icon-tile h-8 w-8 rounded-lg k-tile-rose"><?= icono('user', 'h-4 w-4') ?></span></div>
            <p class="k-stat-value mt-3"><?= $imc !== null ? $coma($imc) : '—' ?></p>
            <div class="mt-auto flex items-center gap-2 pt-3 text-xs k-muted">
                <?php if ($categoriaImc): ?><span class="k-badge <?= $categoriaImc[1] ?>"><?= $categoriaImc[0] ?></span>con <?= (int) $altura ?> cm de altura
                <?php else: ?>Añade tu altura en el perfil<?php endif; ?>
            </div>
        </div>
        <div class="k-card flex flex-col p-5">
            <div class="flex items-center justify-between"><p class="k-stat-label">Ritmo medio</p><span class="k-icon-tile h-8 w-8 rounded-lg k-tile-amber"><?= icono('clock', 'h-4 w-4') ?></span></div>
            <p class="k-stat-value mt-3"><?= $ritmo !== null ? $signo($ritmo) : '—' ?><span class="k-stat-unit">kg/semana</span></p>
            <p class="mt-auto pt-3 text-xs k-muted"><?= $ritmo === null ? 'Necesitas al menos dos registros' : ($quiereBajar && $ritmo < -1 ? 'Más rápido de lo recomendable (hasta 1 kg/sem)' : 'En ' . $coma($semanas, 0) . ' ' . (round($semanas) == 1 ? 'semana' : 'semanas')) ?></p>
        </div>
    </div>

    <div class="grid items-start gap-6 lg:grid-cols-3">
        <!-- GRÁFICO -->
        <section class="k-card k-card-pad lg:col-span-2">
            <div class="flex items-center justify-between"><h2 class="k-section-title">Evolución del peso</h2><?php if ($pesoInicial !== null): ?><span class="text-xs k-muted">Inicio <?= $coma($pesoInicial) ?> kg</span><?php endif; ?></div>
            <?php if (count($pesos) >= 2): ?>
                <div id="chartContainer" class="relative mt-4 h-64"><canvas id="sparklineChart" class="absolute inset-0" role="img" aria-label="Evolución del peso, de <?= e($coma($pesoInicial)) ?> a <?= e($coma($pesoActual)) ?> kg"></canvas></div>
            <?php else: ?>
                <div class="k-empty mt-4 py-10">
                    <span class="k-empty-icon"><?= icono('activity') ?></span>
                    <p class="text-sm k-muted">Con dos registros o más verás aquí tu evolución.</p>
                </div>
            <?php endif; ?>
            <?php if (count($cinturas) >= 2): ?>
                <h3 class="mt-8 text-sm font-semibold k-strong">Cintura</h3>
                <div class="relative mt-3 h-40"><canvas id="cinturaChart" class="absolute inset-0" role="img" aria-label="Evolución de la cintura, de <?= e($coma($cinturas[0])) ?> a <?= e($coma(end($cinturas))) ?> cm"></canvas></div>
            <?php endif; ?>
            <?php if (count($pesos) >= 2 || count($cinturas) >= 2): ?>
                <?= tablaDatos('Mediciones por fecha', ['Fecha', 'Peso', 'Cintura'], array_map(
                    fn($r) => [formatearFecha($r['fecha']), $r['peso_kg'] !== null ? $coma($r['peso_kg']) . ' kg' : '—', !empty($r['cintura_cm']) ? $coma($r['cintura_cm']) . ' cm' : '—'],
                    array_reverse($progresoData))) ?>
            <?php endif; ?>
        </section>

        <!-- NUEVO REGISTRO -->
        <form method="POST" action="progreso.php" class="k-card k-card-pad space-y-4">
            <?= campoCsrf() ?>
            <h2 class="k-section-title">Nuevo registro</h2>
            <div>
                <label for="fecha" class="k-label">Fecha</label>
                <input type="date" id="fecha" name="fecha" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" class="k-input">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="peso_kg" class="k-label">Peso (kg)</label>
                    <input type="number" id="peso_kg" name="peso_kg" step="0.1" min="30" max="350" required inputmode="decimal" class="k-input" placeholder="<?= $pesoActual !== null ? e($coma($pesoActual)) : '70,0' ?>">
                </div>
                <div>
                    <label for="cintura_cm" class="k-label">Cintura (cm)</label>
                    <input type="number" id="cintura_cm" name="cintura_cm" step="0.1" min="40" max="250" inputmode="decimal" class="k-input" placeholder="Opcional">
                </div>
            </div>
            <div>
                <label for="nota" class="k-label">Nota <span class="font-normal k-muted">(opcional)</span></label>
                <input type="text" id="nota" name="nota" maxlength="100" class="k-input" placeholder="Ej. semana con más entreno">
            </div>
            <button type="submit" name="register_progreso" value="1" class="k-btn k-btn-primary w-full"><?= icono('plus') ?>Guardar registro</button>
        </form>
    </div>

    <!-- HISTORIAL -->
    <section class="k-card mt-6 overflow-hidden">
        <div class="k-card-head"><h2 class="k-section-title">Historial</h2><span class="text-sm k-muted"><?= count($progresoHistorial ?? []) ?> registros</span></div>
        <?php if (empty($progresoHistorial)): ?>
            <p class="px-6 py-5 text-sm k-muted">Aún no hay registros.</p>
        <?php else: ?>
            <table class="k-table">
                <thead><tr><th>Fecha</th><th class="text-right">Peso</th><th class="hidden text-right sm:table-cell">Cintura</th><th class="hidden md:table-cell">Nota</th><th class="w-12"><span class="sr-only">Acciones</span></th></tr></thead>
                <tbody>
                    <?php foreach ($progresoHistorial as $registro):
                        $fechaLegible = formatearFecha($registro['fecha']); ?>
                        <tr>
                            <td class="k-strong"><?= e($fechaLegible) ?></td>
                            <td class="text-right font-medium tabular-nums k-strong"><?= $coma($registro['peso_kg']) ?> kg</td>
                            <td class="hidden text-right tabular-nums sm:table-cell"><?= $registro['cintura_cm'] ? $coma($registro['cintura_cm']) . ' cm' : '<span class="k-muted">—</span>' ?></td>
                            <td class="hidden max-w-xs truncate text-sm md:table-cell k-muted"><?= e($registro['nota'] ?? '') ?></td>
                            <td class="text-right">
                                <button type="button" class="btn-delete-progreso k-btn k-btn-ghost k-btn-icon k-btn-danger-text"
                                        data-fecha="<?= e($registro['fecha']) ?>" data-display-fecha="<?= e($fechaLegible) ?>"
                                        aria-label="Eliminar el registro del <?= e($fechaLegible) ?>"><?= icono('trash') ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>

<!-- MODAL: ELIMINAR REGISTRO -->
<div id="deleteModal" class="k-modal" hidden role="dialog" aria-modal="true" aria-labelledby="tituloBorrarRegistro">
    <div class="k-modal-backdrop"></div>
    <form method="POST" action="progreso.php" id="deleteFormModal" class="k-modal-panel max-w-sm">
        <?= campoCsrf() ?>
        <input type="hidden" name="delete_fecha" id="modalFechaInput">
        <h2 id="tituloBorrarRegistro" class="k-modal-title">¿Eliminar el registro del <span id="modalFechaDisplay"></span>?</h2>
        <p class="mt-2 text-sm k-muted">Tu peso del perfil pasará a ser el del registro anterior.</p>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" id="cancelDeleteBtn" class="k-btn k-btn-secondary" data-cerrar-modal>Cancelar</button>
            <button type="submit" class="k-btn k-btn-danger">Eliminar</button>
        </div>
    </form>
</div>

<script>
    // Registros para el gráfico (main.js)
    window.progresoData = <?= $jsProgresoData ?>;
</script>

<?php $usaGraficas = true; // carga Chart.js (footer.php) ?>
<?php include_once __DIR__ . '/../templates/footer.php'; ?>
