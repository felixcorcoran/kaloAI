<?php
/**
 * AÑADIR A LA DESPENSA CON UNA FOTO DEL TIQUE
 *
 * La foto se manda a Gemini (que lee imágenes) para sacar los productos de
 * comida con su cantidad. La persona revisa la lista antes de guardar nada.
 * La foto no se guarda en ningún sitio: se lee, se envía y se descarta.
 */
require_once __DIR__ . '/consultas_ia.php';

const TIQUE_MAX_BYTES = 6 * 1024 * 1024;
const TIQUE_MAX_PRODUCTOS = 60;
const TIQUE_TIPOS = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
const TIQUE_UNIDADES = ['g', 'kg', 'ml', 'L', 'unidad'];

function construirPromptTique(): string {
    return "Esta imagen es un tique de compra de un supermercado (normalmente de España).\n"
        . "Saca SOLO los productos de comida o bebida (no bolsas, limpieza, droguería ni descuentos).\n"
        . "Para cada uno: un nombre corto y genérico en español, como lo escribiría alguien en su despensa "
        . "(\"Leche semidesnatada\", no \"LCHE SEMI HDO 6X1L\"), la cantidad TOTAL comprada y su unidad.\n"
        . "Unidades permitidas: g, kg, ml, L, unidad. Si el tique dice el peso o el volumen (\"6x1L\", \"500G\", \"1,234 kg\"), úsalo "
        . "(6x1L = 6 L). Si no, cuenta unidades (2 x Yogur = 2 unidad). No inventes productos que no se lean.\n"
        . "Si la imagen no es un tique o no se lee, devuelve una lista vacía.\n"
        . 'Responde solo con este JSON: {"productos": [{"nombre": "", "cantidad": 1, "unidad": "unidad"}]}';
}

/** Unidad de la IA a una de las de la despensa, o null. Función pura. */
function unidadTique(?string $unidad): ?string {
    $u = mb_strtolower(trim((string) $unidad), 'UTF-8');
    return match ($u) {
        'g', 'gr', 'gramo', 'gramos' => 'g',
        'kg', 'kilo', 'kilos', 'kilogramo', 'kilogramos' => 'kg',
        'ml', 'mililitro', 'mililitros' => 'ml',
        'l', 'litro', 'litros' => 'L',
        'unidad', 'unidades', 'ud', 'uds', 'u', 'pieza', 'piezas', '' => 'unidad',
        default => null,
    };
}

/**
 * Productos válidos de la respuesta de la IA: nombre (1-100 caracteres),
 * cantidad positiva y razonable, y unidad de la despensa. Une los repetidos.
 * Devuelve null si la respuesta no es JSON con una lista. Función pura.
 */
function normalizarProductosTique(?string $respuesta): ?array {
    if ($respuesta === null) return null;
    $json = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', $respuesta)), true);
    $lista = is_array($json) ? ($json['productos'] ?? (array_is_list($json) ? $json : null)) : null;
    if (!is_array($lista)) return null;

    $productos = [];
    foreach ($lista as $p) {
        if (!is_array($p)) continue;
        $nombre = trim(preg_replace('/\s+/u', ' ', (string) ($p['nombre'] ?? '')) ?? '');
        $nombre = mb_strtoupper(mb_substr($nombre, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($nombre, 1, 99, 'UTF-8');
        $cantidad = (float) str_replace(',', '.', (string) ($p['cantidad'] ?? 1));
        $unidad = unidadTique($p['unidad'] ?? 'unidad');
        if ($nombre === '' || $unidad === null || $cantidad <= 0 || $cantidad > 100000) continue;

        $clave = mb_strtolower($nombre, 'UTF-8') . '|' . $unidad;
        if (isset($productos[$clave])) {
            $productos[$clave]['cantidad'] += $cantidad;
        } else {
            $productos[$clave] = ['nombre' => $nombre, 'cantidad' => round($cantidad, 3), 'unidad' => $unidad];
        }
        if (count($productos) >= TIQUE_MAX_PRODUCTOS) break;
    }
    return array_values($productos);
}

/**
 * Comprueba la foto subida. Devuelve ['mime' => ..., 'datos' => base64] o un
 * texto con el error para la persona.
 */
function leerFotoTique(?array $archivo): array|string {
    if (!$archivo || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return 'Elige o haz una foto del tique.';
    if (in_array($archivo['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || ($archivo['size'] ?? 0) > TIQUE_MAX_BYTES) {
        return 'La foto es demasiado grande (máximo ' . (TIQUE_MAX_BYTES / 1024 / 1024) . ' MB).';
    }
    if ($archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) return 'No se ha podido subir la foto. Inténtalo de nuevo.';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']) ?: '';
    if (!in_array($mime, TIQUE_TIPOS, true)) return 'Eso no parece una foto (JPG, PNG, WebP o HEIC).';
    $datos = file_get_contents($archivo['tmp_name']);
    if ($datos === false || $datos === '') return 'No se ha podido leer la foto.';
    return ['mime' => $mime, 'datos' => base64_encode($datos)];
}
