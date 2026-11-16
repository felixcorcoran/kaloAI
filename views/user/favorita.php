<?php
/**
 * LÓGICA: MARCAR UNA RECETA COMO FAVORITA - KaloAI
 * POST (fetch con el token CSRF en la cabecera) con id_receta. Responde JSON
 * con el estado nuevo. Sin JavaScript, vuelve a la página de origen.
 */

session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php'; // incluye la comprobación del token CSRF
require_once __DIR__ . '/../../core/recetas.php';

$esJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    exit;
}

$estado = alternarFavorita(connectDB(), (int) $_SESSION['user_id'], (int) ($_POST['id_receta'] ?? 0));

if ($esJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($estado === null ? ['success' => false] : ['success' => true, 'favorita' => $estado]);
    exit;
}
header('Location: ' . ($estado === null ? 'mis_recetas.php' : 'detalle_receta.php?id=' . (int) $_POST['id_receta']));
exit;
