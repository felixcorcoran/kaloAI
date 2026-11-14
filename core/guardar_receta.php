<?php
/**
 * CONTROLADOR: GUARDAR RECETA - KaloAI
 * Crea o edita una receta escrita a mano con sus ingredientes, en una sola
 * transacción. Los ingredientes llegan uno por línea ("150 g Pechuga de pollo").
 */

session_start();
require_once __DIR__ . '/configuracion.php';
require_once __DIR__ . '/utilidades.php'; // incluye la comprobación del token CSRF
require_once __DIR__ . '/recetas.php';

// 1. CONTROL DE ACCESO
if (!isset($_SESSION['user_id'])) {
    header("Location: ../views/auth/login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../views/user/mis_recetas.php");
    exit;
}

$pdo = connectDB();
$userId = (int) $_SESSION['user_id'];
$errores = [];

// 2. RECOGIDA DE DATOS
$idReceta = filter_var($_POST['id_receta'] ?? null, FILTER_VALIDATE_INT) ?: null; // si existe, es una edición
$titulo = mb_substr(limpiarInput($_POST['titulo'] ?? ''), 0, 200);
$tipoComida = tipoRecetaParaMomento($_POST['tipo_comida'] ?? '');

$numero = fn($campo) => max(0.0, (float) (filter_var($_POST[$campo] ?? 0, FILTER_VALIDATE_FLOAT) ?: 0.0));
$calorias = (int) round($numero('calorias_por_racion'));
$proteinas = $numero('proteinas_g_por_racion');
$carbohidratos = $numero('carbohidratos_g_por_racion');
$grasas = $numero('grasas_g_por_racion');

$ingredientes = unificarIngredientes(array_values(array_filter(array_map(
    'parsearLineaIngrediente',
    preg_split('/\R/u', (string) ($_POST['ingredientes'] ?? ''))
))));
$instrucciones = trim((string) ($_POST['instrucciones'] ?? ''));

// 3. VALIDACIONES
if ($titulo === '') $errores[] = "El título es obligatorio.";
if (!$ingredientes) $errores[] = "Debes añadir al menos un ingrediente.";
if ($instrucciones === '') $errores[] = "Las instrucciones son obligatorias.";

// En una edición, la receta tiene que ser de esta persona. Sin esta comprobación,
// con el id de otra receta se podían borrar y reemplazar sus ingredientes.
if ($idReceta) {
    $propia = $pdo->prepare("SELECT 1 FROM receta WHERE id_receta = ? AND id_usuario_creador = ?");
    $propia->execute([$idReceta, $userId]);
    if (!$propia->fetchColumn()) {
        header("Location: ../views/user/mis_recetas.php");
        exit;
    }
}

if ($errores) {
    $_SESSION['errores_form'] = $errores;
    $_SESSION['post_data'] = $_POST;
    header("Location: ../views/user/" . ($idReceta ? "editar_receta.php?id=$idReceta" : "crear_receta.php"));
    exit;
}

try {
    $pdo->beginTransaction();

    if ($idReceta) {
        // 4. EDICIÓN
        $pdo->prepare("UPDATE receta SET titulo = ?, tipo_comida = ?, calorias_por_racion = ?, proteinas_g_por_racion = ?,
                           carbohidratos_g_por_racion = ?, grasas_g_por_racion = ?, descripcion = ?
                       WHERE id_receta = ? AND id_usuario_creador = ?")
            ->execute([$titulo, $tipoComida, $calorias, $proteinas, $carbohidratos, $grasas, $instrucciones, $idReceta, $userId]);
        $pdo->prepare("DELETE FROM detalle_receta WHERE id_receta = ?")->execute([$idReceta]);
        $idFinal = $idReceta;
    } else {
        // 5. CREACIÓN
        $pdo->prepare("INSERT INTO receta (titulo, tipo_comida, calorias_por_racion, proteinas_g_por_racion,
                           carbohidratos_g_por_racion, grasas_g_por_racion, descripcion, id_usuario_creador, generado_ia)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)")
            ->execute([$titulo, $tipoComida, $calorias, $proteinas, $carbohidratos, $grasas, $instrucciones, $userId]);
        $idFinal = (int) $pdo->lastInsertId();
    }

    // 6. INGREDIENTES, con su cantidad y unidad (antes se guardaban todos como "1 unidad")
    $stmtDetalle = $pdo->prepare("INSERT INTO detalle_receta (id_receta, nombre_ingrediente, cantidad, unidad) VALUES (?, ?, ?, ?)");
    foreach ($ingredientes as $ing) {
        $stmtDetalle->execute([$idFinal, $ing['nombre'], $ing['cantidad'], $ing['unidad']]);
    }

    $pdo->commit();

    $_SESSION['mensaje'] = "Receta guardada correctamente.";
    header("Location: ../views/user/detalle_receta.php?id=" . $idFinal);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error en guardar_receta: " . $e->getMessage());
    $_SESSION['errores_form'] = ["No se pudo guardar la receta. Inténtalo de nuevo."];
    $_SESSION['post_data'] = $_POST;
    header("Location: ../views/user/" . ($idReceta ? "editar_receta.php?id=$idReceta" : "crear_receta.php"));
    exit;
}
