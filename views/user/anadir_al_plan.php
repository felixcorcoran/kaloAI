<?php
/**
 * LÓGICA: AÑADIR UNA RECETA AL PLAN DESDE SU DETALLE - KaloAI
 * POST con id_receta, semana (lunes), dia (0-6) y momento. Si el hueco ya
 * tenía comida, se sustituye. Vuelve al plan de esa semana.
 */

session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php'; // incluye la comprobación del token CSRF
require_once __DIR__ . '/../../core/recetas.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}
$idReceta = (int) ($_POST['id_receta'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$idReceta) {
    header('Location: mis_recetas.php');
    exit;
}

$semana = semanaValida($_POST['semana'] ?? null);
$dia = filter_var($_POST['dia'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 6]]);
$momento = momentoValido($_POST['momento'] ?? null);

$ok = false;
try {
    $ok = $dia !== false && $momento !== null
        && asignarRecetaAlPlan(connectDB(), (int) $_SESSION['user_id'], $idReceta, (int) $dia, $momento, $semana);
} catch (PDOException $e) {
    error_log('Error al añadir la receta al plan: ' . $e->getMessage());
}

if (!$ok) {
    $_SESSION['mensaje'] = 'No se ha podido añadir la receta al plan.';
    $_SESSION['mensaje_tipo'] = 'error';
    header('Location: detalle_receta.php?id=' . $idReceta);
    exit;
}
$_SESSION['aviso_plan'] = ['ok', 'Receta añadida: ' . $momento . ' del ' . mb_strtolower(DIAS_SEMANA[$dia], 'UTF-8') . '.'];
header('Location: planificacion_semanal.php' . ($semana !== lunesDe() ? '?semana=' . $semana : '') . '#dia-' . mb_strtolower(DIAS_SEMANA[$dia], 'UTF-8'));
exit;
