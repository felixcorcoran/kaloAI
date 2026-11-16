<?php
/**
 * RECUPERAR LA CONTRASEÑA
 *
 * 1. La persona escribe su correo. Si existe una cuenta activa, se crea un
 *    token aleatorio, se guarda solo su hash y se envía el enlace por correo.
 *    La respuesta en pantalla es siempre la misma, exista o no la cuenta.
 * 2. El enlace caduca en una hora, sirve una sola vez y, al usarse, anula los
 *    demás enlaces pendientes de esa cuenta.
 */
require_once __DIR__ . '/correo.php';

const MINUTOS_VALIDEZ_ENLACE = 60;
const ENLACES_POR_HORA = 3;

/** Hash que se guarda en la base de datos (el token en claro solo va en el correo). */
function hashToken(string $token): string {
    return hash('sha256', $token);
}

/**
 * Crea un enlace para la cuenta de ese correo y lo envía. Devuelve true si se
 * ha enviado; false si no hay cuenta activa, se ha superado el límite por hora
 * o el correo ha fallado (la pantalla no lo distingue, para no revelar si el
 * correo está registrado).
 */
function solicitarRestablecimiento(PDO $pdo, string $correo, string $baseUrl, ?callable $transporte = null): bool {
    $stmt = $pdo->prepare("SELECT u.id_usuario, p.nombre FROM usuario u LEFT JOIN perfil p ON p.id_usuario = u.id_usuario
                           WHERE u.correo = ? AND u.activo = 1");
    $stmt->execute([trim($correo)]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$usuario) return false;

    // Límite para que nadie llene el buzón de otra persona
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM restablecimiento_contrasena
                           WHERE id_usuario = ? AND fecha_creacion > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->execute([$usuario['id_usuario']]);
    if ((int) $stmt->fetchColumn() >= ENLACES_POR_HORA) return false;

    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO restablecimiento_contrasena (id_usuario, token_hash, expira) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))")
        ->execute([$usuario['id_usuario'], hashToken($token), MINUTOS_VALIDEZ_ENLACE]);

    $enlace = rtrim($baseUrl, '/') . '/views/auth/restablecer.php?token=' . $token;
    $nombre = trim((string) ($usuario['nombre'] ?? '')) ?: 'hola';
    $saludo = $nombre === 'hola' ? 'Hola' : 'Hola, ' . htmlspecialchars(explode(' ', $nombre)[0], ENT_QUOTES, 'UTF-8');
    $html = plantillaCorreo(
        'Cambia tu contraseña',
        $saludo . ':<br>Hemos recibido una petición para cambiar la contraseña de tu cuenta de KaloAI. Pulsa el botón para elegir una nueva.',
        'Elegir nueva contraseña',
        $enlace,
        'El enlace caduca en ' . MINUTOS_VALIDEZ_ENLACE . ' minutos y solo sirve una vez. Si no lo has pedido tú, ignora este correo: tu contraseña no cambiará.'
    );
    $texto = ($nombre === 'hola' ? 'Hola' : 'Hola, ' . explode(' ', $nombre)[0]) . ":\n\n"
           . "Hemos recibido una petición para cambiar la contraseña de tu cuenta de KaloAI.\n"
           . "Abre este enlace para elegir una nueva (caduca en " . MINUTOS_VALIDEZ_ENLACE . " minutos):\n$enlace\n\n"
           . "Si no lo has pedido tú, ignora este correo: tu contraseña no cambiará.";

    return enviarCorreo(trim($correo), 'Cambia tu contraseña de KaloAI', $html, $texto, $transporte);
}

/** Id de la persona si el token es válido (existe, no ha caducado y no se ha usado), o null. */
function usuarioDelToken(PDO $pdo, string $token): ?int {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $stmt = $pdo->prepare("SELECT r.id_usuario FROM restablecimiento_contrasena r JOIN usuario u ON u.id_usuario = r.id_usuario
                           WHERE r.token_hash = ? AND r.usado = 0 AND r.expira > NOW() AND u.activo = 1");
    $stmt->execute([hashToken($token)]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

/** Comprueba la nueva contraseña. Devuelve el mensaje de error o null si vale. Función pura. */
function errorNuevaContrasena(string $contrasena, string $repetida): ?string {
    if (mb_strlen($contrasena, 'UTF-8') < 8) return 'La contraseña debe tener al menos 8 caracteres.';
    if (strlen($contrasena) > 72) return 'La contraseña es demasiado larga (máximo 72 caracteres).';
    if ($contrasena !== $repetida) return 'Las dos contraseñas no coinciden.';
    return null;
}

/**
 * Cambia la contraseña con un token válido y anula todos los enlaces
 * pendientes de esa cuenta. Devuelve false si el token ya no vale.
 */
function restablecerContrasena(PDO $pdo, string $token, string $nuevaContrasena): bool {
    $pdo->beginTransaction();
    try {
        $idUsuario = usuarioDelToken($pdo, $token);
        if ($idUsuario === null) { $pdo->rollBack(); return false; }
        $pdo->prepare("UPDATE usuario SET contrasena_hash = ? WHERE id_usuario = ?")
            ->execute([password_hash($nuevaContrasena, PASSWORD_DEFAULT), $idUsuario]);
        $pdo->prepare("UPDATE restablecimiento_contrasena SET usado = 1 WHERE id_usuario = ?")->execute([$idUsuario]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
