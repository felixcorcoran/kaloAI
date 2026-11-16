<?php
/**
 * LÓGICA: TU CUENTA - KaloAI
 * Acciones (por POST, con token CSRF):
 *   descargar  descarga en JSON todo lo que KaloAI guarda de la persona
 *   borrar     borra la cuenta y todos sus datos (pide la contraseña)
 * En modo gestión (un nutricionista dentro de la cuenta de su paciente) no se
 * permite ninguna de las dos: son decisiones de la propia persona.
 */

session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php'; // incluye la comprobación del token CSRF
require_once __DIR__ . '/../../core/recetas.php';
require_once __DIR__ . '/../../core/cuenta.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !empty($_SESSION['is_impersonating'])) {
    header('Location: perfil.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$pdo = connectDB();
$accion = $_POST['accion'] ?? '';

if ($accion === 'descargar') {
    $datos = exportarDatosCuenta($pdo, $userId);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="kaloai-mis-datos-' . date('Y-m-d') . '.json"');
    header('Cache-Control: no-store');
    echo json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($accion === 'borrar') {
    $stmt = $pdo->prepare("SELECT u.contrasena_hash, u.correo, p.nombre FROM usuario u LEFT JOIN perfil p ON p.id_usuario = u.id_usuario WHERE u.id_usuario = ?");
    $stmt->execute([$userId]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario || !password_verify((string) ($_POST['contrasena'] ?? ''), $usuario['contrasena_hash'])) {
        $_SESSION['aviso_cuenta'] = ['error', 'La contraseña no es correcta. No se ha borrado nada.'];
        header('Location: perfil.php#cuenta');
        exit;
    }
    if (esUltimoAdministrador($pdo, $userId)) {
        $_SESSION['aviso_cuenta'] = ['error', 'Eres la única cuenta de administración: nombra antes a otra persona administradora.'];
        header('Location: perfil.php#cuenta');
        exit;
    }

    try {
        // El log se pinta como HTML: nombre y correo van escapados
        borrarCuenta($pdo, $userId, '<b>BAJA</b>: <b>' . e($usuario['nombre'] ?: 'Sin nombre') . '</b> (' . e($usuario['correo']) . ') borró su cuenta');
    } catch (Throwable $e) {
        error_log('Error al borrar la cuenta ' . $userId . ': ' . $e->getMessage());
        $_SESSION['aviso_cuenta'] = ['error', 'No se ha podido borrar la cuenta. Inténtalo de nuevo.'];
        header('Location: perfil.php#cuenta');
        exit;
    }

    // Se cierra la sesión por completo
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $c = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $c['path'], $c['domain'], $c['secure'], $c['httponly']);
    }
    session_destroy();
    header('Location: ../auth/login.php?cuenta_borrada=1');
    exit;
}

header('Location: perfil.php');
exit;
