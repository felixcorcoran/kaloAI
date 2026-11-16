<?php
/**
 * LÓGICA: REGISTRO DE LO QUE SE COME - KaloAI
 * Acciones (siempre por POST, con token CSRF):
 *   hecho     marca como comida una del plan de hoy (id_comida)
 *   deshacer  borra un registro propio (id_registro)
 *   libre     apunta algo comido fuera del plan (descripcion, momento, calorias, macros)
 *   estimar   pide a la IA las calorías y macros de una descripción (responde JSON)
 */

session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php'; // incluye la comprobación del token CSRF
require_once __DIR__ . '/../../core/registro.php';
require_once __DIR__ . '/../../core/consultas_ia.php';

$volver = '../dashboard.php#comidas';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id'])) {
    header('Location: ' . (isset($_SESSION['user_id']) ? $volver : '../auth/login.php'));
    exit;
}

$userId = (int) $_SESSION['user_id'];
$accion = $_POST['accion'] ?? '';
$hoy = date('Y-m-d');
$pdo = connectDB();

// La estimación con IA se pide desde el modal con fetch y espera JSON
if ($accion === 'estimar') {
    header('Content-Type: application/json; charset=utf-8');
    $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
    if ($descripcion === '' || mb_strlen($descripcion, 'UTF-8') > 200) {
        echo json_encode(['success' => false, 'error' => 'Escribe primero qué has comido (máximo 200 caracteres).']);
        exit;
    }
    $estimacion = normalizarEstimacion(preguntarGemini(construirPromptEstimacion($descripcion)));
    echo json_encode($estimacion
        ? ['success' => true] + $estimacion
        : ['success' => false, 'error' => 'La IA no ha podido calcularlo. Escribe las calorías a mano o inténtalo de nuevo.']);
    exit;
}

try {
    switch ($accion) {
        case 'hecho':
            $ok = registrarComidaPlanificada($pdo, $userId, (int) ($_POST['id_comida'] ?? 0), $hoy) !== null;
            $_SESSION['aviso_registro'] = $ok ? ['ok', 'Apuntado. ¡Buen provecho!'] : ['error', 'No se ha podido marcar esa comida.'];
            break;
        case 'deshacer':
            $ok = borrarRegistroComida($pdo, $userId, (int) ($_POST['id_registro'] ?? 0));
            $_SESSION['aviso_registro'] = $ok ? ['ok', 'Quitado de lo que has comido hoy.'] : ['error', 'No se ha podido quitar.'];
            break;
        case 'libre':
            $comida = validarComidaLibre($_POST);
            if (isset($comida['error'])) {
                $_SESSION['aviso_registro'] = ['error', $comida['error']];
            } else {
                registrarComidaLibre($pdo, $userId, $hoy, $comida);
                $_SESSION['aviso_registro'] = ['ok', 'Apuntado: ' . $comida['descripcion'] . '.'];
            }
            break;
        default:
            $_SESSION['aviso_registro'] = ['error', 'Acción no reconocida.'];
    }
} catch (PDOException $e) {
    error_log('Error en el registro de comidas: ' . $e->getMessage());
    $_SESSION['aviso_registro'] = ['error', 'No se ha podido guardar. Inténtalo de nuevo.'];
}

header('Location: ' . $volver);
exit;
