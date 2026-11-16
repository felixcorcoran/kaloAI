<?php
/**
 * LÓGICA: MENSAJES DE CONTACTO (administración) - KaloAI
 * POST con id_mensaje y accion: «leido» o «borrar».
 */
session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php'; // incluye la comprobación del token CSRF

if (!isset($_SESSION['user_role_id']) || (int) $_SESSION['user_role_id'] !== 1 || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../dashboard.php');
    exit;
}

$id = (int) ($_POST['id_mensaje'] ?? 0);
$pdo = connectDB();
if (($_POST['accion'] ?? '') === 'borrar') {
    $pdo->prepare("DELETE FROM mensaje_contacto WHERE id_mensaje = ?")->execute([$id]);
} else {
    $pdo->prepare("UPDATE mensaje_contacto SET leido = 1 WHERE id_mensaje = ?")->execute([$id]);
}
header('Location: dashboard_admin.php#mensajes');
exit;
