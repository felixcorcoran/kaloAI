<?php
/**
 * VISTA: CREAR RECETA - KaloAI
 * Formulario para escribir una receta a mano (views/templates/form_receta.php);
 * la guarda core/guardar_receta.php.
 */

$pageTitle = "KaloAI | Nueva receta";
session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php';

if (!isset($_SESSION['user_id'])) {
    redirect('../auth/login.php');
}

$receta = null;
$ingredientesText = '';

include_once __DIR__ . '/../templates/header.php';
?>

<div class="k-container k-page max-w-4xl">
    <a href="mis_recetas.php" class="k-back"><?= icono('chevron-left', 'h-4 w-4') ?>Mis recetas</a>
    <div class="k-page-head">
        <div>
            <h1 class="k-title">Nueva receta</h1>
            <p class="k-subtitle">Escribe una receta tuya. Si prefieres que la cree la IA según tus objetivos, <a href="generar_receta.php" class="font-medium text-teal-700 underline-offset-2 hover:underline">genérala aquí</a>.</p>
        </div>
    </div>
    <?php include __DIR__ . '/../templates/form_receta.php'; ?>
</div>

<?php include_once __DIR__ . '/../templates/footer.php'; ?>
