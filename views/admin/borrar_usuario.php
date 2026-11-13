<?php
/**
 * CONTROLADOR: ELIMINACIÓN DE USUARIOS - KaloAI
 * Borra a un usuario con todos sus datos y deja constancia en el log de actividad.
 * Solo acepta POST con token CSRF (lo comprueba utilidades.php).
 */

session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php';

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

    $pdo->beginTransaction();

    // 2. Sus recetas: la clave foránea las dejaría sin dueño (SET NULL), así que
    //    se apuntan antes y se borran después de borrar al usuario
    $stmtRecetas = $pdo->prepare("SELECT id_receta FROM receta WHERE id_usuario_creador = ?");
    $stmtRecetas->execute([$idABorrar]);
    $idsRecetas = $stmtRecetas->fetchAll(PDO::FETCH_COLUMN);

    // 3. El usuario. Las claves foráneas borran en cascada su perfil, inventario,
    //    alergias, progreso, plan semanal (y sus comidas) y solicitudes de vínculo,
    //    y desvinculan a sus pacientes si era nutricionista.
    //    Las solicitudes se borran a mano por si la base de datos aún no tiene la
    //    migración 001 (sin ella, la del nutricionista no se borra en cascada).
    $pdo->prepare("DELETE FROM solicitudes_vinculacion WHERE id_nutricionista = ? OR id_paciente = ?")
        ->execute([$idABorrar, $idABorrar]);
    $pdo->prepare("DELETE FROM usuario WHERE id_usuario = ?")->execute([$idABorrar]);

    if ($idsRecetas) {
        $marcas = implode(',', array_fill(0, count($idsRecetas), '?'));
        // Por si alguna receta suya seguía en el plan de otra persona
        $pdo->prepare("DELETE FROM comida_planificada WHERE id_receta IN ($marcas)")->execute($idsRecetas);
        // detalle_receta se borra en cascada
        $pdo->prepare("DELETE FROM receta WHERE id_receta IN ($marcas)")->execute($idsRecetas);
    }

    // 4. Registro en el log de actividad del panel
    $pdo->prepare("INSERT INTO logs_actividad (detalle) VALUES (?)")->execute([$detalleLog]);

    $pdo->commit();

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
