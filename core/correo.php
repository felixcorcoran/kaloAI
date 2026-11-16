<?php
/**
 * ENVÍO DE CORREOS CON RESEND (https://resend.com)
 *
 * La clave se busca, por este orden:
 *   1. la constante RESEND_API_KEY (core/configuracion.php)
 *   2. core/secretos.php, que escribe el despliegue a partir del secreto de
 *      GitHub RESEND_API_KEY (así no hay que tocar nada en el servidor)
 *   3. la variable de entorno RESEND_API_KEY (Docker, local)
 * Sin clave, no se envía nada y se deja constancia en el log de errores.
 */

if (is_file(__DIR__ . '/secretos.php')) {
    require_once __DIR__ . '/secretos.php';
}

function configCorreo(string $nombre, string $porDefecto = ''): string {
    if (defined($nombre) && constant($nombre) !== '') return (string) constant($nombre);
    $valor = getenv($nombre);
    return ($valor === false || $valor === '') ? $porDefecto : $valor;
}

/**
 * Envía un correo. Devuelve true si Resend lo ha aceptado.
 *
 * @param callable|null $transporte Función que hace la petición HTTP (los tests pasan una falsa):
 *                                  recibe (url, cabeceras, cuerpo JSON) y devuelve [código HTTP, respuesta]
 */
function enviarCorreo(string $para, string $asunto, string $html, string $texto, ?callable $transporte = null): bool {
    $clave = configCorreo('RESEND_API_KEY');
    if ($clave === '' && $transporte === null) {
        error_log('KaloAI: no hay clave de Resend (RESEND_API_KEY); el correo no se ha enviado');
        return false;
    }

    $cuerpo = json_encode([
        'from'    => configCorreo('CORREO_REMITENTE', 'KaloAI <no-reply@felixcorcoran.es>'),
        'to'      => [$para],
        'subject' => $asunto,
        'html'    => $html,
        'text'    => $texto,
    ], JSON_UNESCAPED_UNICODE);
    $url = configCorreo('RESEND_API_URL', 'https://api.resend.com/emails');
    $cabeceras = ['Content-Type: application/json', 'Authorization: Bearer ' . $clave];

    $transporte = $transporte ?? function (string $url, array $cabeceras, string $cuerpo): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $cuerpo,
            CURLOPT_HTTPHEADER     => $cabeceras,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $respuesta = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return [$codigo, $respuesta === false ? $error : (string) $respuesta];
    };

    [$codigo, $respuesta] = $transporte($url, $cabeceras, $cuerpo);
    if ($codigo >= 200 && $codigo < 300) return true;
    // Sin la clave ni el destinatario en el log
    error_log("KaloAI: Resend no ha aceptado el correo (HTTP $codigo): " . mb_substr((string) $respuesta, 0, 300));
    return false;
}

/**
 * Plantilla HTML sencilla con el estilo de KaloAI (tablas y estilos en línea:
 * es lo que entienden todos los programas de correo). Función pura.
 */
function plantillaCorreo(string $titulo, string $parrafoHtml, string $textoBoton, string $urlBoton, string $notaHtml = ''): string {
    $e = fn($t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#18181b;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:32px 16px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#ffffff;border-radius:20px;padding:32px;">'
        . '<tr><td style="font-size:20px;font-weight:700;color:#0d9488;padding-bottom:24px;">KaloAI</td></tr>'
        . '<tr><td style="font-size:22px;font-weight:700;padding-bottom:12px;">' . $e($titulo) . '</td></tr>'
        . '<tr><td style="font-size:15px;line-height:1.6;color:#3f3f46;padding-bottom:24px;">' . $parrafoHtml . '</td></tr>'
        . '<tr><td style="padding-bottom:24px;"><a href="' . $e($urlBoton) . '" style="display:inline-block;background:#0d9488;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;padding:12px 24px;border-radius:999px;">' . $e($textoBoton) . '</a></td></tr>'
        . '<tr><td style="font-size:13px;line-height:1.6;color:#71717a;">' . $notaHtml . '</td></tr>'
        . '</table>'
        . '<p style="font-size:12px;color:#a1a1aa;margin-top:16px;">KaloAI · Planificación alimentaria con IA</p>'
        . '</td></tr></table></body></html>';
}
