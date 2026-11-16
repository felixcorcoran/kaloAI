<?php
require_once __DIR__ . '/configuracion.php';

/**
 * Envía un prompt a Google Gemini y devuelve el texto de la respuesta, o null
 * si no se ha podido obtener.
 *
 * - La clave va en la cabecera x-goog-api-key, no en la URL: así no aparece en
 *   los logs de proxies ni del servidor.
 * - El certificado TLS se verifica siempre (sin esto, cualquiera en medio de la
 *   conexión podría leer la clave y las respuestas).
 * - Si hay varias claves configuradas, se prueba la siguiente solo cuando una
 *   no es válida (revocada o sin permiso). Un límite de uso (HTTP 429) no se
 *   esquiva con otra clave: se informa a la persona para que lo intente luego.
 *
 * @param array|null $imagen ['mime' => 'image/jpeg', 'datos' => base64] para preguntar sobre una foto
 */
function preguntarGemini(string $prompt, int $segundos = 45, ?array $imagen = null): ?string {
    $claves = defined('GEMINI_API_KEYS') ? array_filter((array) GEMINI_API_KEYS) : [];
    if (!$claves) {
        error_log("KaloAI: no hay ninguna clave de Gemini configurada (GEMINI_API_KEY)");
        return null;
    }

    // Con imagen (p. ej. la foto de un tique), va delante del texto; y como se
    // trata de leer, no de inventar, con menos «creatividad»
    $partes = [["text" => $prompt]];
    if ($imagen !== null) {
        array_unshift($partes, ["inline_data" => ["mime_type" => $imagen['mime'], "data" => $imagen['datos']]]);
    }
    $cuerpo = json_encode([
        "contents" => [["parts" => $partes]],
        "generationConfig" => [
            "temperature" => $imagen !== null ? 0.2 : 0.7,
            "response_mime_type" => "application/json",
        ],
    ]);

    foreach (array_values($claves) as $indice => $clave) {
        $ch = curl_init(GEMINI_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $cuerpo,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $clave],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $segundos,
        ]);
        $respuesta = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorCurl = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200) {
            $json = json_decode((string) $respuesta, true);
            $texto = $json['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (is_string($texto) && $texto !== '') {
                return $texto;
            }
            error_log("KaloAI: Gemini respondió sin texto (posible bloqueo de seguridad del modelo)");
            return null;
        }

        $mensaje = $errorCurl ?: (json_decode((string) $respuesta, true)['error']['message'] ?? "HTTP $httpCode");
        error_log("KaloAI: falló la clave " . ($indice + 1) . " de Gemini: " . $mensaje);

        // Solo tiene sentido probar otra clave si el problema es de esta clave
        if (!in_array($httpCode, [400, 401, 403], true) || !str_contains(strtolower($mensaje), 'key')) {
            return null;
        }
    }
    return null;
}
