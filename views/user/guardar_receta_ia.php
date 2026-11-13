<?php
/**
 * PROCESADOR DE GUARDADO - CHEF IA
 * Guarda la receta que acaba de generar la IA (receta, ingredientes y, si se
 * venía del calendario, su hueco en el plan semanal).
 *
 * La receta no viene del navegador: generar_receta.php la deja en la sesión
 * ($_SESSION['receta_ia_pendiente']) y aquí solo se confirma, con token CSRF.
 */

session_start();

require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php'; // incluye la comprobación del token CSRF
require_once __DIR__ . '/../../core/recetas.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$pendiente = $_SESSION['receta_ia_pendiente'] ?? null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_array($pendiente) || empty($pendiente['receta'])) {
    // Nada que guardar (recarga, doble clic o acceso directo): vuelta al generador
    header("Location: generar_receta.php");
    exit;
}

$userId = (int) $_SESSION['user_id'];
$diaNumero = diaANumero($pendiente['dia'] ?? null);
$momento = $pendiente['momento'] ?? null;
$pdo = connectDB();

try {
    $pdo->beginTransaction();

    $idReceta = guardarRecetaIA($pdo, $userId, $pendiente['receta'], $momento);

    // Si se venía de un hueco del calendario, la receta se coloca ahí
    $planificada = false;
    if ($diaNumero !== null && momentoValido($momento)) {
        $planificada = asignarRecetaAlPlan($pdo, $userId, $idReceta, $diaNumero, $momento);
    }

    $pdo->commit();
    unset($_SESSION['receta_ia_pendiente']); // evita guardarla dos veces

    $_SESSION['mensaje'] = "Receta guardada correctamente.";
    header("Location: " . ($planificada ? "planificacion_semanal.php?success=1" : "mis_recetas.php"));
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error al guardar receta de IA: " . $e->getMessage());
    $_SESSION['mensaje'] = "No se pudo guardar la receta. Inténtalo de nuevo.";
    header("Location: mis_recetas.php");
    exit;
}
