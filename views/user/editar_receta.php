<?php
/**
 * VISTA: EDITAR RECETA - KaloAI
 * Permite modificar una receta existente. 
 * Implementa una validación crucial: solo el creador original puede editarla.
 */

$pageTitle = "KaloAI | Editar Receta";
session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php';
require_once __DIR__ . '/../../core/recetas.php';

// 1. CONTROL DE ACCESO
if (!isset($_SESSION['user_id'])) { redirect('../auth/login.php'); }

$pdo = connectDB();
$userId = $_SESSION['user_id'];
$recetaId = $_GET['id'] ?? null;

/* ==========================================================================
   2. CARGA DE DATOS Y VALIDACIÓN DE SEGURIDAD
   ========================================================================== */
$receta = null;
if ($recetaId) {
    // Filtramos por id_usuario_creador para que nadie edite lo que no es suyo
    $stmt = $pdo->prepare("SELECT * FROM receta WHERE id_receta = ? AND id_usuario_creador = ?");
    $stmt->execute([$recetaId, $userId]);
    $receta = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$receta) {
    header("Location: mis_recetas.php");
    exit;
}

/* ==========================================================================
   3. RECONSTRUCCIÓN DE INGREDIENTES
   ========================================================================== */
// Cada ingrediente con su cantidad y unidad ("150 g Pechuga de pollo"), para que
// al guardar no se pierdan (antes se mostraba solo el nombre y volvía como "1 unidad")
$stmtIng = $pdo->prepare("SELECT nombre_ingrediente, cantidad, unidad FROM detalle_receta WHERE id_receta = ?");
$stmtIng->execute([$recetaId]);
$ingredientesText = implode("\n", array_map('formatearLineaIngrediente', $stmtIng->fetchAll(PDO::FETCH_ASSOC)));

include_once __DIR__ . '/../templates/header.php';
?>

<div class="k-container k-page max-w-4xl">
    <a href="detalle_receta.php?id=<?= (int) $receta['id_receta'] ?>" class="k-back"><?= icono('chevron-left', 'h-4 w-4') ?>Volver a la receta</a>
    <div class="k-page-head">
        <div>
            <span class="k-pill"><?= icono('pencil') ?>Editando</span>
            <h1 class="k-title mt-3">Editar receta</h1>
            <p class="k-subtitle"><?= e($receta['titulo']) ?></p>
        </div>
    </div>
    <?php include __DIR__ . '/../templates/form_receta.php'; ?>
</div>

<?php include_once __DIR__ . '/../templates/footer.php'; ?>
