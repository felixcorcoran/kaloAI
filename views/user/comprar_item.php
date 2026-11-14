<?php
/**
 * LÓGICA: MARCAR COMO COMPRADO - KaloAI
 * Añade al inventario lo que se acaba de comprar desde la lista de la compra.
 * Si el producto ya estaba (aunque sea en otra unidad compatible, como kg y g),
 * suma la cantidad en la unidad que ya tenía.
 */

session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php'; // incluye la comprobación del token CSRF
require_once __DIR__ . '/../../core/recetas.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    $nombre   = mb_substr(limpiarInput($_POST['nombre'] ?? ''), 0, 200);
    $cantidad = (float) (filter_var($_POST['cantidad'] ?? 0, FILTER_VALIDATE_FLOAT) ?: 0);
    $unidad   = mb_substr(limpiarInput($_POST['unidad'] ?? ''), 0, 50);

    if ($nombre === '' || $cantidad <= 0) {
        header("Location: lista_compra.php?error=datos");
        exit;
    }

    try {
        sumarAlInventario(connectDB(), (int) $_SESSION['user_id'], $nombre, $cantidad, $unidad);
        header("Location: lista_compra.php?success=1");
    } catch (Exception $e) {
        error_log("Error actualizando inventario: " . $e->getMessage());
        header("Location: lista_compra.php?error=tecnico");
    }
    exit;
}

header("Location: lista_compra.php");
exit;
