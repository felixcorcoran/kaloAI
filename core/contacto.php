<?php
/**
 * FORMULARIO DE CONTACTO
 * Los mensajes se guardan en mensaje_contacto (el administrador los ve en su
 * panel) y, si hay un correo de contacto configurado (CORREO_CONTACTO), se
 * avisa por correo con Resend.
 */
require_once __DIR__ . '/correo.php';

const CONTACTO_MAX_MENSAJE = 3000;

/** Valida el formulario. Devuelve los datos limpios o ['error' => mensaje]. Función pura. */
function validarContacto(array $datos): array {
    $nombre = trim(preg_replace('/\s+/u', ' ', (string) ($datos['nombre'] ?? '')));
    $correo = trim((string) ($datos['email'] ?? ''));
    $mensaje = trim(str_replace("\r\n", "\n", (string) ($datos['mensaje'] ?? '')));
    if ($nombre === '' || mb_strlen($nombre, 'UTF-8') > 100) return ['error' => 'Escribe tu nombre (máximo 100 caracteres).'];
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || strlen($correo) > 255) return ['error' => 'Escribe un correo electrónico válido para poder responderte.'];
    if (mb_strlen($mensaje, 'UTF-8') < 10) return ['error' => 'Cuéntanos un poco más (al menos 10 caracteres).'];
    if (mb_strlen($mensaje, 'UTF-8') > CONTACTO_MAX_MENSAJE) return ['error' => 'El mensaje es demasiado largo (máximo ' . CONTACTO_MAX_MENSAJE . ' caracteres).'];
    return ['nombre' => $nombre, 'correo' => $correo, 'mensaje' => $mensaje];
}

/** Guarda el mensaje y avisa por correo si está configurado. Devuelve el id del mensaje. */
function guardarContacto(PDO $pdo, array $datos, ?int $userId, ?callable $transporte = null): int {
    $pdo->prepare("INSERT INTO mensaje_contacto (id_usuario, nombre, correo, mensaje) VALUES (?, ?, ?, ?)")
        ->execute([$userId, $datos['nombre'], $datos['correo'], $datos['mensaje']]);
    $id = (int) $pdo->lastInsertId();

    $destino = configCorreo('CORREO_CONTACTO');
    if ($destino !== '' || $transporte !== null) {
        $e = fn($t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $html = '<p><b>' . $e($datos['nombre']) . '</b> (' . $e($datos['correo']) . ') ha escrito desde KaloAI:</p>'
              . '<blockquote style="border-left:3px solid #0d9488;margin:0;padding:8px 16px;white-space:pre-line;">' . $e($datos['mensaje']) . '</blockquote>';
        $texto = $datos['nombre'] . ' (' . $datos['correo'] . ") ha escrito desde KaloAI:\n\n" . $datos['mensaje'];
        enviarCorreo($destino ?: 'contacto@ejemplo.test', 'Nuevo mensaje de contacto en KaloAI', $html, $texto, $transporte);
    }
    return $id;
}
