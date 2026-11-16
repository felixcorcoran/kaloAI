<?php
/**
 * LÓGICA: RELLENAR LA SEMANA CON IA - KaloAI
 * Recibe las comidas del día elegidas, la variedad y el modo de ingredientes,
 * y llena los huecos libres del plan (core/semana_ia.php).
 */

session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php'; // incluye la comprobación del token CSRF
require_once __DIR__ . '/../../core/semana_ia.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: planificacion_semanal.php');
    exit;
}

// Una semana entera puede tardar más que una receta suelta
@set_time_limit(150);
$semana = semanaValida($_POST['semana'] ?? null);
$volver = 'planificacion_semanal.php' . ($semana !== lunesDe() ? '?semana=' . $semana : '');

$resultado = rellenarSemanaConIA(
    connectDB(),
    (int) $_SESSION['user_id'],
    array_map('strval', (array) ($_POST['momentos'] ?? [])),
    (int) ($_POST['variedad'] ?? 2),
    ($_POST['modo_generacion'] ?? '') === 'estricto',
    mb_substr(trim((string) ($_POST['sugerencia'] ?? '')), 0, 300),
    null,
    $semana
);

if (isset($resultado['error'])) {
    $_SESSION['aviso_plan'] = ['error', $resultado['error']];
} else {
    $texto = "Listo: {$resultado['comidas']} " . ($resultado['comidas'] === 1 ? 'comida añadida' : 'comidas añadidas')
           . " con {$resultado['recetas']} " . ($resultado['recetas'] === 1 ? 'receta nueva' : 'recetas nuevas') . '.';
    if ($resultado['sin_hueco'] > 0) {
        $texto .= " {$resultado['sin_hueco']} " . ($resultado['sin_hueco'] === 1 ? 'hueco se ha quedado libre' : 'huecos se han quedado libres')
                . ' para no pasarte de tus calorías del día.';
    }
    $_SESSION['aviso_plan'] = ['ok', $texto];
}

header('Location: ' . $volver);
exit;
