<?php
/**
 * CONTROLADOR: ELIMINACIÓN DE USUARIOS - KaloAI
 * Borra a un usuario con todos sus datos y deja constancia en el log de actividad.
 * Solo acepta POST con token CSRF (lo comprueba utilidades.php).
 */

session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php';
require_once __DIR__ . '/../../core/cuenta.php';

/* ==========================================================================
   SEGURIDAD Y CONTROL DE ACCESO
   ========================================================================== */
if (!isset($_SESSION['user_role_id']) || $_SESSION['user_role_id'] != 1) {
    header("Location: ../dashboard.php");
    exit;
}

$idABorrar = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$idABorrar) {
    header("Location: dashboard_admin.php");
    exit;
}

// Un administrador no puede borrar su propia cuenta desde el panel
if ($idABorrar === (int) $_SESSION['user_id']) {
    header("Location: dashboard_admin.php?msg=no_autoborrado");
    exit;
}

$pdo = connectDB();

try {
    // 1. Datos para el log, antes de que desaparezcan
    $stmtInfo = $pdo->prepare("
        SELECT p.nombre, u.correo
        FROM usuario u
        LEFT JOIN perfil p ON u.id_usuario = p.id_usuario
        WHERE u.id_usuario = ?
    ");
    $stmtInfo->execute([$idABorrar]);
    $usuarioAfectado = $stmtInfo->fetch(PDO::FETCH_ASSOC);

    if (!$usuarioAfectado) {
        header("Location: dashboard_admin.php");
        exit;
    }

    // El log se pinta como HTML: nombre y correo van escapados
    $nombre = e($usuarioAfectado['nombre'] ?: 'Sin nombre');
    $correo = e($usuarioAfectado['correo']);
    $detalleLog = "<b>ELIMINACIÓN</b>: Se eliminó permanentemente al usuario <b>$nombre</b> ($correo)";

    // 2. Borrado de la cuenta con todos sus datos (core/cuenta.php)
    borrarCuenta($pdo, $idABorrar, $detalleLog);

    header("Location: dashboard_admin.php?msg=borrado_exitoso");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error al borrar el usuario $idABorrar: " . $e->getMessage());
    header("Location: dashboard_admin.php?msg=borrado_fallido");
    exit;
}
