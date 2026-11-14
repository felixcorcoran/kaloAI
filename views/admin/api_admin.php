<?php
/**
 * ARCHIVO DE ACCIONES ADMINISTRATIVAS (AJAX) - KaloAI
 * Este script procesa las peticiones en segundo plano para gestionar usuarios,
 * roles y asignaciones de nutricionistas sin recargar la página.
 */

session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php'; // incluye la comprobación del token CSRF

// Limpieza de búfer para asegurar que solo se envíe JSON al cliente
if (ob_get_level()) ob_end_clean();
ob_start();

$pdo = connectDB();

/* ==========================================================================
   CONTROL DE ACCESO (SEGURIDAD)
   ========================================================================== */
// Verificamos que el usuario esté logueado y tenga el id_rol = 1 (Administrador)
if (!isset($_SESSION['user_role_id']) || $_SESSION['user_role_id'] != 1) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Acceso denegado: Se requieren permisos de administrador']);
    exit;
}

/**
 * Registra una acción en la tabla de auditoría (logs_actividad).
 * @param PDO $pdo Instancia de conexión.
 * @param string $detalle Texto descriptivo de lo ocurrido.
 */
function guardarLog($pdo, $detalle) {
    $stmt = $pdo->prepare("INSERT INTO logs_actividad (detalle) VALUES (?)");
    $stmt->execute([$detalle]);
}

/**
 * Genera una cadena HTML representativa del usuario (Nombre + Email).
 * Útil para que los logs sean legibles de un vistazo.
 */
function getInfoUsuario($pdo, $id) {
    $stmt = $pdo->prepare("
        SELECT p.nombre, u.correo 
        FROM usuario u 
        LEFT JOIN perfil p ON u.id_usuario = p.id_usuario 
        WHERE u.id_usuario = ?
    ");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        // El log se pinta como HTML: el nombre y el correo se escapan aquí
        $nombre = e(!empty($user['nombre']) ? $user['nombre'] : "Sin nombre");
        $correo = e($user['correo']);
        return "<b>$nombre</b> <span class='text-gray-500 text-[11px] font-normal'>($correo)</span>";
    }
    return "<b>Usuario #" . (int) $id . "</b>";
}

/* ==========================================================================
   PROCESAMIENTO DE LA PETICIÓN POST
   ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false];
    try {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $action = $_POST['action'] ?? null;
        $value = $_POST['value'] ?? null;

        if (!$userId) throw new InvalidArgumentException("Falta el usuario.");

        // Un administrador no puede quitarse el rol ni bloquearse a sí mismo:
        // podría quedarse la web sin nadie que la gestione.
        if ((int) $userId === (int) $_SESSION['user_id'] && in_array($action, ['rol', 'toggle_status'], true)) {
            throw new InvalidArgumentException("No puedes cambiar tu propio rol ni bloquear tu propia cuenta.");
        }

        // Obtenemos el "nombre visual" del usuario afectado antes de modificarlo
        $identificadorAfectado = getInfoUsuario($pdo, $userId);

        /* ------------------------------------------------------------------
           CASO 1: Cambio de Rol (Admin, Nutri, Usuario)
           ------------------------------------------------------------------ */
        if ($action === 'rol') {
            $roles = [1 => 'Admin', 2 => 'Nutricionista', 3 => 'Usuario'];
            $value = (int) $value;
            if (!isset($roles[$value])) throw new InvalidArgumentException("Rol no válido.");
            $nombreRol = $roles[$value];
            
            $stmt = $pdo->prepare("UPDATE usuario SET id_rol = ? WHERE id_usuario = ?");
            $stmt->execute([$value, $userId]);
            
            guardarLog($pdo, "Rol: $identificadorAfectado ahora es <b>$nombreRol</b>");
            $response = ['success' => true];
        } 
        
        /* ------------------------------------------------------------------
           CASO 2: Vinculación de Nutricionista
           ------------------------------------------------------------------ */
        elseif ($action === 'nutri') {
            $val = ($value === "" || $value === null) ? null : (int) $value;
            if ($val !== null) {
                // Solo se puede asignar un nutricionista activo
                $esNutri = $pdo->prepare("SELECT 1 FROM usuario WHERE id_usuario = ? AND id_rol = 2 AND activo = 1");
                $esNutri->execute([$val]);
                if (!$esNutri->fetchColumn()) throw new InvalidArgumentException("Ese usuario no es un nutricionista activo.");
            }
            $stmt = $pdo->prepare("UPDATE perfil SET id_nutricionista = ? WHERE id_usuario = ?");
            $stmt->execute([$val, $userId]);
            
            if ($val) {
                $identificadorNutri = getInfoUsuario($pdo, $val);
                $msg = "Vinculación: $identificadorNutri es el nuevo nutri de $identificadorAfectado";
            } else {
                $msg = "Desvinculación: Se quitó el nutricionista a $identificadorAfectado";
            }
            
            guardarLog($pdo, $msg);
            $response = ['success' => true];
        }
        
        /* ------------------------------------------------------------------
           CASO 3: Activar / Bloquear Usuario (Baneo)
           ------------------------------------------------------------------ */
        elseif ($action === 'toggle_status') {
            $value = ((int) $value === 1) ? 1 : 0;
            $stmt = $pdo->prepare("UPDATE usuario SET activo = ? WHERE id_usuario = ?");
            $stmt->execute([$value, $userId]);
            
            $estado = ($value == 1) ? "ACTIVADO" : "BLOQUEADO";
            guardarLog($pdo, "Estado: $identificadorAfectado ha sido <b>$estado</b>");
            $response = ['success' => true];
        }
        else {
            throw new InvalidArgumentException("Acción desconocida.");
        }

    } catch (InvalidArgumentException $e) {
        // Errores de validación: el mensaje está pensado para el administrador
        $response = ['success' => false, 'error' => $e->getMessage()];
    } catch (Exception $e) {
        // Errores técnicos: al log, nunca al navegador
        error_log("api_admin: " . $e->getMessage());
        $response = ['success' => false, 'error' => 'No se pudo guardar el cambio.'];
    }

    // Limpiamos cualquier salida accidental y devolvemos JSON puro
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}