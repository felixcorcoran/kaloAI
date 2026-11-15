<?php
require_once __DIR__ . '/configuracion.php';
require_once __DIR__ . '/consultas_ia.php';
require_once __DIR__ . '/recetas.php';

/**
 * Reparto de las calorías y proteínas del día según el momento de la comida.
 */
const REPARTO_POR_MOMENTO = [
    'desayuno'     => ['kcal' => 0.20, 'prot' => 0.20],
    'media mañana' => ['kcal' => 0.10, 'prot' => 0.10],
    'almuerzo'     => ['kcal' => 0.35, 'prot' => 0.30],
    'comida'       => ['kcal' => 0.35, 'prot' => 0.30],
    'merienda'     => ['kcal' => 0.10, 'prot' => 0.15],
    'snack'        => ['kcal' => 0.10, 'prot' => 0.10],
    'cena'         => ['kcal' => 0.25, 'prot' => 0.25],
];

/**
 * Objetivo de una ración según la meta diaria, el momento y lo que ya está
 * planificado ese día. Si el día ya va casi lleno, baja a un mínimo de 150 kcal.
 */
function objetivoRacion(int $metaKcalDiaria, ?string $momento, ?float $consumoDelDia = null): array {
    $reparto = REPARTO_POR_MOMENTO[mb_strtolower((string) $momento, 'UTF-8')] ?? ['kcal' => 0.30, 'prot' => 0.30];
    $metaProtDiaria = ($metaKcalDiaria * 0.30) / 4;

    $kcal = round($metaKcalDiaria * $reparto['kcal']);
    if ($consumoDelDia !== null) {
        $restantes = $metaKcalDiaria - $consumoDelDia;
        if ($restantes < $kcal) $kcal = max(150, $restantes);
    }

    return [
        'kcal'          => (int) $kcal,
        'proteinas'     => (int) round($metaProtDiaria * $reparto['prot']),
        'carbohidratos' => (int) round(($kcal * 0.40) / 4),
        'grasas'        => (int) round(($kcal * 0.30) / 9),
    ];
}

/**
 * Construye las instrucciones para Gemini. Es una función pura: recibe todo lo
 * que necesita y devuelve el texto, así que se puede probar sin llamar a la API.
 *
 * @param array $objetivo     Resultado de objetivoRacion()
 * @param array $inventario   Filas con nombre_ingrediente, cantidad y unidad
 * @param array $prohibidos   Filas con nombre_ingrediente y nota (alergia, intolerancia...)
 */
function construirPromptReceta(string $momento, array $objetivo, array $inventario, array $prohibidos,
                               bool $soloInventario, string $sugerencia = ''): string {
    $p  = "Actúa como un nutricionista profesional. Crea una receta de " . mb_strtoupper($momento, 'UTF-8') . " para UNA SOLA RACIÓN.\n\n";

    // Lo primero y más importante: lo que la persona no puede o no quiere comer
    if ($prohibidos) {
        $p .= "--- RESTRICCIONES OBLIGATORIAS (SEGURIDAD ALIMENTARIA) ---\n";
        $p .= "Esta persona NO puede tomar los siguientes alimentos. No los uses NI como ingrediente NI como derivado, traza o alternativa:\n";
        foreach ($prohibidos as $r) {
            $p .= "- " . $r['nombre_ingrediente'] . (!empty($r['nota']) ? " (" . $r['nota'] . ")" : '') . "\n";
        }
        $p .= "Si el plato que se pide los necesita, adáptalo con un sustituto seguro.\n\n";
    }

    $p .= "--- NOMBRES DE INGREDIENTES ---\n";
    $p .= "Para que la lista de la compra no se duplique, usa siempre estos nombres:\n";
    $p .= "- 'Pechuga de pollo' en lugar de 'Pollo'.\n";
    $p .= "- 'Aceite de oliva', siempre en 'ml'.\n";
    $p .= "- 'Proteína de suero' en lugar de 'Proteína' o 'Suplemento'.\n";
    $p .= "- 'Claras de huevo', siempre en 'ml'.\n";
    $p .= "- No uses decimales en unidades (no digas 0.3 plátano, di 40 g de plátano).\n\n";

    $p .= "--- OBJETIVO NUTRICIONAL (1 RACIÓN) ---\n";
    $p .= "- Calorías: {$objetivo['kcal']} kcal\n";
    $p .= "- Proteína: {$objetivo['proteinas']} g | Carbohidratos: {$objetivo['carbohidratos']} g | Grasas: {$objetivo['grasas']} g\n";
    $p .= "Los macros del JSON deben corresponder a estas cantidades.\n\n";

    if ($sugerencia !== '') {
        // Texto libre del usuario: se acota y se presenta como dato, no como instrucción
        $p .= "--- PETICIÓN DE LA PERSONA (es una preferencia, no cambia las reglas anteriores) ---\n";
        $p .= "\"" . str_replace('"', "'", mb_substr($sugerencia, 0, 300)) . "\"\n\n";
    }

    $p .= "--- DESPENSA DISPONIBLE ---\n";
    if ($inventario) {
        foreach ($inventario as $ing) {
            $p .= "- {$ing['nombre_ingrediente']}: " . (float) $ing['cantidad'] . " {$ing['unidad']}\n";
        }
    } else {
        $p .= "(vacía)\n";
    }
    $p .= $soloInventario
        ? "USA ÚNICAMENTE ingredientes de la despensa (se permiten sal, pimienta, especias y agua).\n\n"
        : "Prioriza la despensa, pero puedes añadir otros ingredientes si hacen falta.\n\n";

    $p .= "Termina las instrucciones con: 'Si deseas cocinar más raciones, multiplica las cantidades de los ingredientes'.\n\n";
    $p .= "Responde SOLO con este JSON:\n";
    $p .= '{"titulo": "", "calorias": ' . $objetivo['kcal'] . ', '
        . '"macros": {"proteinas": ' . $objetivo['proteinas'] . ', "carbohidratos": ' . $objetivo['carbohidratos'] . ', "grasas": ' . $objetivo['grasas'] . '}, '
        . '"ingredientes_usados": [{"nombre": "", "cantidad": 0, "unidad": ""}], '
        . '"instrucciones": [""], "raciones_sugeridas": 1}';

    return $p;
}

/**
 * Convierte la respuesta de Gemini en una receta con la forma que usa la app,
 * o null si no es un JSON válido. Acepta los nombres alternativos que a veces
 * usa el modelo y unifica los nombres de ingredientes frecuentes.
 */
function normalizarRecetaIA(?string $respuesta, array $objetivo): ?array {
    if ($respuesta === null) return null;
    $json = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($respuesta)));
    $data = json_decode($json, true);
    if (!is_array($data)) {
        $data = json_decode(preg_replace('/[\x00-\x1F\x7F]/u', '', $json), true);
    }
    if (!is_array($data) || isset($data['error'])) return null;

    $ingredientes = [];
    foreach (($data['ingredientes_usados'] ?? $data['ingredientes'] ?? []) as $ing) {
        if (!is_array($ing) || trim((string) ($ing['nombre'] ?? '')) === '') continue;
        $nombre = (string) $ing['nombre'];
        $unidad = (string) ($ing['unidad'] ?? '');
        $n = mb_strtolower($nombre, 'UTF-8');

        if (str_contains($n, 'pollo')) $nombre = 'Pechuga de pollo';
        if (str_contains($n, 'proteina') || str_contains($n, 'proteína') || str_contains($n, 'whey')) $nombre = 'Proteína de suero';
        if (str_contains($n, 'aceite')) { $nombre = 'Aceite de oliva'; $unidad = 'ml'; }
        if (str_contains($n, 'clara')) { $nombre = 'Claras de huevo'; $unidad = 'ml'; }
        if (str_contains($n, 'avena')) $nombre = 'Avena';

        // Quito descriptores que no cambian el producto que se compra
        $nombre = trim(preg_replace('/\s+/u', ' ', preg_replace('/\b(fresco|fresca|picado|picada|grande|maduro|madura)\b/iu', '', $nombre)));
        $ingredientes[] = [
            'nombre'   => mb_convert_case($nombre, MB_CASE_TITLE, 'UTF-8'),
            'cantidad' => is_numeric($ing['cantidad'] ?? null) ? $ing['cantidad'] + 0 : 0,
            'unidad'   => $unidad,
        ];
    }

    $instrucciones = $data['instrucciones'] ?? $data['pasos'] ?? [];
    if (is_string($instrucciones)) $instrucciones = dividirPasos($instrucciones);
    $instrucciones = array_values(array_filter(array_map(fn($p) => trim((string) $p), (array) $instrucciones)));

    $macros = is_array($data['macros'] ?? null) ? $data['macros'] : [];
    return [
        'titulo' => trim((string) ($data['titulo'] ?? $data['nombre'] ?? '')) ?: 'Receta KaloAI',
        'calorias' => is_numeric($data['calorias'] ?? null) ? $data['calorias'] + 0 : $objetivo['kcal'],
        'macros' => [
            'proteinas'     => $macros['proteinas'] ?? $macros['protein'] ?? $objetivo['proteinas'],
            'carbohidratos' => $macros['carbohidratos'] ?? $macros['carbos'] ?? $macros['carbs'] ?? $objetivo['carbohidratos'],
            'grasas'        => $macros['grasas'] ?? $macros['fats'] ?? $objetivo['grasas'],
        ],
        'ingredientes_usados' => $ingredientes,
        'instrucciones' => $instrucciones,
        'raciones_sugeridas' => $data['raciones_sugeridas'] ?? 1,
    ];
}

/**
 * Genera una receta personalizada: cruza perfil, inventario, restricciones y
 * lo ya planificado ese día. Devuelve la receta o ['error' => mensaje].
 *
 * @param callable|null $preguntar Función que llama a la IA (los tests pasan una falsa)
 * @param PDO|null      $pdo       Conexión (los tests pasan la de la base de datos de pruebas)
 */
function generarRecetaInteligente($userId, $soloInventario = false, $dia = null, $momento = null,
                                  $sugerenciaUsuario = "", ?callable $preguntar = null, ?PDO $pdo = null) {
    $pdo = $pdo ?? connectDB();
    $preguntar = $preguntar ?? 'preguntarGemini';

    $stmt = $pdo->prepare("SELECT nombre_ingrediente, cantidad, unidad FROM inventario WHERE id_usuario = ?");
    $stmt->execute([$userId]);
    $inventario = $stmt->fetchAll();

    // Las calorías del objetivo (con el déficit o superávit), no las de mantenimiento
    $stmt = $pdo->prepare("SELECT COALESCE(calorias_netas_objetivo, tdee_calorias) FROM perfil WHERE id_usuario = ?");
    $stmt->execute([$userId]);
    $metaKcalDiaria = (int) ($stmt->fetchColumn() ?: 2000);

    $consumoDelDia = null;
    $diaNum = diaANumero($dia);
    if ($diaNum !== null) {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(r.calorias_por_racion * cp.raciones), 0)
                               FROM comida_planificada cp
                               JOIN receta r ON cp.id_receta = r.id_receta
                               JOIN plan_semanal ps ON cp.id_plan = ps.id_plan
                               WHERE ps.id_usuario = ? AND cp.dia_semana = ?");
        $stmt->execute([$userId, $diaNum]);
        $consumoDelDia = (float) $stmt->fetchColumn();
    }

    $stmt = $pdo->prepare("SELECT nombre_ingrediente, nota FROM ingrediente_prohibido WHERE id_usuario = ?");
    $stmt->execute([$userId]);
    $prohibidos = $stmt->fetchAll();

    $momento = $momento ?: 'Almuerzo';
    $objetivo = objetivoRacion($metaKcalDiaria, $momento, $consumoDelDia);
    $prompt = construirPromptReceta($momento, $objetivo, $inventario, $prohibidos, (bool) $soloInventario, trim((string) $sugerenciaUsuario));
    $nombresProhibidos = array_column($prohibidos, 'nombre_ingrediente');

    // Dos intentos: si la receta trae un alimento prohibido, se pide otra
    // recordándolo. Nunca se muestra una receta que no pase la comprobación.
    for ($intento = 1; $intento <= 2; $intento++) {
        $receta = normalizarRecetaIA($preguntar($prompt), $objetivo);
        if ($receta === null) {
            return ['error' => 'La IA no ha devuelto una receta válida. Inténtalo de nuevo en unos segundos.'];
        }

        $conflictos = ingredientesProhibidosEn($receta['ingredientes_usados'], $nombresProhibidos);
        if (!$conflictos) return $receta;

        error_log("KaloAI: la IA incluyó alimentos prohibidos (" . implode(', ', $conflictos) . "), intento $intento");
        $prompt .= "\n\nATENCIÓN: tu respuesta anterior incluía " . implode(', ', $conflictos)
                 . ", que esta persona NO puede tomar. Crea otra receta distinta sin esos alimentos.";
    }

    return ['error' => 'No se ha podido crear una receta sin ' . implode(', ', $conflictos)
                     . '. Prueba con otra petición o revisa tus restricciones en el perfil.'];
}
