<?php
/**
 * RELLENAR LA SEMANA CON IA
 *
 * En una sola llamada a Gemini se piden varias recetas para cada comida del
 * día elegida (desayuno, almuerzo...). Luego se reparten por los huecos libres
 * del plan, sin tocar lo que ya estaba planificado y sin pasar del 110 % del
 * objetivo diario de calorías.
 */
require_once __DIR__ . '/recetas.php';
require_once __DIR__ . '/generar_recetas_ia.php';

/** Cuántas recetas distintas se pueden pedir por comida. */
const VARIEDAD_SEMANA = [1, 2, 3];

/** Huecos sin comida del plan para los momentos pedidos: [['dia' => 0..6, 'momento' => 'Cena'], ...] */
function huecosLibres(PDO $pdo, int $userId, array $momentos): array {
    $stmt = $pdo->prepare("SELECT cp.dia_semana, cp.momento_comida FROM comida_planificada cp
                           JOIN plan_semanal ps ON cp.id_plan = ps.id_plan WHERE ps.id_usuario = ?");
    $stmt->execute([$userId]);
    $ocupados = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) $ocupados[$fila['dia_semana'] . '|' . $fila['momento_comida']] = true;

    $huecos = [];
    for ($dia = 0; $dia < 7; $dia++) {
        foreach (MOMENTOS_COMIDA as $momento) {
            if (in_array($momento, $momentos, true) && !isset($ocupados[$dia . '|' . $momento])) {
                $huecos[] = ['dia' => $dia, 'momento' => $momento];
            }
        }
    }
    return $huecos;
}

/**
 * Prompt para pedir todas las recetas de la semana de una vez. Función pura.
 *
 * @param array $pedido ['Desayuno' => ['cantidad' => 2, 'objetivo' => objetivoRacion()], ...]
 */
function construirPromptSemana(array $pedido, array $inventario, array $prohibidos, bool $soloInventario, string $sugerencia = ''): string {
    $total = array_sum(array_column($pedido, 'cantidad'));
    $p  = "Actúa como un nutricionista profesional. Vas a preparar el MENÚ DE LA SEMANA: crea $total recetas, cada una para UNA SOLA RACIÓN.\n\n";

    if ($prohibidos) {
        $p .= "--- RESTRICCIONES OBLIGATORIAS (SEGURIDAD ALIMENTARIA) ---\n";
        $p .= "Esta persona NO puede tomar los siguientes alimentos. No los uses en NINGUNA receta, ni como ingrediente ni como derivado o traza:\n";
        foreach ($prohibidos as $r) {
            $p .= "- " . $r['nombre_ingrediente'] . (!empty($r['nota']) ? " (" . $r['nota'] . ")" : '') . "\n";
        }
        $p .= "\n";
    }

    $p .= "--- RECETAS QUE NECESITO ---\n";
    foreach ($pedido as $momento => $datos) {
        $o = $datos['objetivo'];
        $p .= "- MOMENTO: $momento | recetas: {$datos['cantidad']} | {$o['kcal']} kcal | proteína {$o['proteinas']} g | carbohidratos {$o['carbohidratos']} g | grasas {$o['grasas']} g\n";
    }
    $p .= "Las recetas de un mismo momento deben ser distintas entre sí. Repite ingredientes entre recetas cuando tenga sentido, para que la lista de la compra sea corta.\n";
    $p .= "Recetas sencillas de cocina casera española, con 3 a 6 pasos breves.\n\n";

    $p .= "--- NOMBRES DE INGREDIENTES ---\n";
    $p .= "Usa 'Pechuga de pollo' (no 'Pollo'), 'Aceite de oliva' en 'ml', 'Claras de huevo' en 'ml' y 'Proteína de suero'. Sin decimales en unidades (40 g de plátano, no 0.3 plátano).\n\n";

    if ($sugerencia !== '') {
        $p .= "--- PETICIÓN DE LA PERSONA (es una preferencia, no cambia las reglas anteriores) ---\n";
        $p .= "\"" . str_replace('"', "'", mb_substr($sugerencia, 0, 300)) . "\"\n\n";
    }

    $p .= "--- DESPENSA DISPONIBLE ---\n";
    if ($inventario) {
        foreach ($inventario as $ing) $p .= "- {$ing['nombre_ingrediente']}: " . (float) $ing['cantidad'] . " {$ing['unidad']}\n";
    } else {
        $p .= "(vacía)\n";
    }
    $p .= $soloInventario
        ? "USA ÚNICAMENTE ingredientes de la despensa (se permiten sal, pimienta, especias y agua).\n\n"
        : "Prioriza la despensa, pero puedes añadir otros ingredientes si hacen falta.\n\n";

    $p .= "Responde SOLO con este JSON (una entrada por receta, con el momento exactamente como arriba):\n";
    $p .= '{"recetas": [{"momento": "", "titulo": "", "calorias": 0, "macros": {"proteinas": 0, "carbohidratos": 0, "grasas": 0}, '
        . '"ingredientes_usados": [{"nombre": "", "cantidad": 0, "unidad": ""}], "instrucciones": [""]}]}';
    return $p;
}

/**
 * Lee la respuesta de la IA: ['Desayuno' => [receta, receta], ...] solo con los
 * momentos pedidos, o null si no es un JSON válido. Función pura.
 */
function normalizarSemanaIA(?string $respuesta, array $pedido): ?array {
    if ($respuesta === null) return null;
    $data = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', trim($respuesta))), true);
    if (is_array($data) && array_is_list($data)) $data = ['recetas' => $data];
    if (!is_array($data) || !is_array($data['recetas'] ?? null)) return null;

    $porMomento = [];
    foreach ($data['recetas'] as $item) {
        if (!is_array($item)) continue;
        $momento = momentoValido((string) ($item['momento'] ?? ''));
        if ($momento === null || !isset($pedido[$momento])) continue;
        if (count($porMomento[$momento] ?? []) >= $pedido[$momento]['cantidad']) continue;
        $receta = normalizarRecetaIA(json_encode($item, JSON_UNESCAPED_UNICODE), $pedido[$momento]['objetivo']);
        if ($receta && $receta['ingredientes_usados']) $porMomento[$momento][] = $receta;
    }
    return $porMomento;
}

/**
 * Reparte recetas por los huecos libres. Función pura.
 *
 * @param array $huecos        [['dia' => 0, 'momento' => 'Cena'], ...]
 * @param array $kcalRecetas   ['Cena' => [410, 520], ...] calorías de cada receta disponible
 * @param array $kcalPorDia    [0 => 1200.0, ...] lo ya planificado cada día
 * @return array               [['dia' => 0, 'momento' => 'Cena', 'indice' => 1], ...] (índice de la receta)
 *                             Los huecos que harían pasar el día del 110 % del objetivo se quedan libres.
 */
function repartirRecetas(array $huecos, array $kcalRecetas, array $kcalPorDia, int $metaDiaria): array {
    $asignaciones = [];
    $turno = [];
    foreach ($huecos as $hueco) {
        $opciones = $kcalRecetas[$hueco['momento']] ?? [];
        if (!$opciones) continue;
        // Rotación: lunes la 1.ª, martes la 2.ª... así no se repite el mismo plato seguido
        $inicio = $turno[$hueco['momento']] ?? 0;
        $elegida = null;
        for ($i = 0; $i < count($opciones); $i++) {
            $indice = ($inicio + $i) % count($opciones);
            $total = ($kcalPorDia[$hueco['dia']] ?? 0) + $opciones[$indice];
            if ($metaDiaria <= 0 || $total <= $metaDiaria * 1.1) { $elegida = $indice; break; }
        }
        if ($elegida === null) continue;
        $turno[$hueco['momento']] = $elegida + 1;
        $kcalPorDia[$hueco['dia']] = ($kcalPorDia[$hueco['dia']] ?? 0) + $opciones[$elegida];
        $asignaciones[] = ['dia' => $hueco['dia'], 'momento' => $hueco['momento'], 'indice' => $elegida];
    }
    return $asignaciones;
}

/**
 * Rellena los huecos libres del plan con recetas nuevas de la IA.
 * Devuelve ['recetas' => n, 'comidas' => n, 'sin_hueco' => n] o ['error' => mensaje].
 *
 * @param callable|null $preguntar Función que llama a la IA (los tests pasan una falsa)
 */
function rellenarSemanaConIA(PDO $pdo, int $userId, array $momentos, int $variedad, bool $soloInventario,
                             string $sugerencia = '', ?callable $preguntar = null): array {
    $preguntar = $preguntar ?? fn($prompt) => preguntarGemini($prompt, 90);
    $momentos = array_values(array_filter(array_map('momentoValido', $momentos)));
    $variedad = in_array($variedad, VARIEDAD_SEMANA, true) ? $variedad : 2;
    if (!$momentos) return ['error' => 'Elige al menos una comida del día.'];

    $huecos = huecosLibres($pdo, $userId, $momentos);
    if (!$huecos) return ['error' => 'No hay huecos libres en esas comidas: tu semana ya está completa.'];

    $stmt = $pdo->prepare("SELECT COALESCE(calorias_netas_objetivo, tdee_calorias) FROM perfil WHERE id_usuario = ?");
    $stmt->execute([$userId]);
    $meta = (int) ($stmt->fetchColumn() ?: 2000);

    // Por cada momento con huecos, tantas recetas como variedad (sin pasar de los huecos que hay)
    $huecosPorMomento = array_count_values(array_column($huecos, 'momento'));
    $pedido = [];
    foreach ($huecosPorMomento as $momento => $n) {
        $pedido[$momento] = ['cantidad' => min($variedad, $n), 'objetivo' => objetivoRacion($meta, $momento)];
    }

    $stmt = $pdo->prepare("SELECT nombre_ingrediente, cantidad, unidad FROM inventario WHERE id_usuario = ?");
    $stmt->execute([$userId]);
    $inventario = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare("SELECT nombre_ingrediente, nota FROM ingrediente_prohibido WHERE id_usuario = ?");
    $stmt->execute([$userId]);
    $prohibidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $porMomento = normalizarSemanaIA($preguntar(construirPromptSemana($pedido, $inventario, $prohibidos, $soloInventario, trim($sugerencia))), $pedido);
    if ($porMomento === null) return ['error' => 'La IA no ha devuelto un menú válido. Inténtalo de nuevo en unos segundos.'];

    // Nunca se guarda una receta con un alimento prohibido
    $nombresProhibidos = array_column($prohibidos, 'nombre_ingrediente');
    foreach ($porMomento as $momento => $recetas) {
        $porMomento[$momento] = array_values(array_filter($recetas, function ($r) use ($nombresProhibidos) {
            $conflictos = ingredientesProhibidosEn($r['ingredientes_usados'], $nombresProhibidos);
            if ($conflictos) error_log('KaloAI: receta semanal descartada por ' . implode(', ', $conflictos));
            return !$conflictos;
        }));
    }
    $porMomento = array_filter($porMomento);
    if (!$porMomento) return ['error' => 'La IA no ha propuesto recetas válidas para tus restricciones. Inténtalo de nuevo.'];

    $stmt = $pdo->prepare("SELECT cp.dia_semana, SUM(r.calorias_por_racion * cp.raciones) FROM comida_planificada cp
                           JOIN receta r ON cp.id_receta = r.id_receta JOIN plan_semanal ps ON cp.id_plan = ps.id_plan
                           WHERE ps.id_usuario = ? GROUP BY cp.dia_semana");
    $stmt->execute([$userId]);
    $kcalPorDia = array_map('floatval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));

    $kcalRecetas = array_map(fn($lista) => array_map(fn($r) => (float) $r['calorias'], $lista), $porMomento);
    $asignaciones = repartirRecetas($huecos, $kcalRecetas, $kcalPorDia, $meta);

    $pdo->beginTransaction();
    try {
        // Solo se guardan las recetas que acaban en el plan
        $ids = [];
        foreach ($asignaciones as $a) {
            $clave = $a['momento'] . '|' . $a['indice'];
            $ids[$clave] ??= guardarRecetaIA($pdo, $userId, $porMomento[$a['momento']][$a['indice']], $a['momento']);
            asignarRecetaAlPlan($pdo, $userId, $ids[$clave], $a['dia'], $a['momento']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('KaloAI: error al guardar la semana generada: ' . $e->getMessage());
        return ['error' => 'No se ha podido guardar el menú. Inténtalo de nuevo.'];
    }

    return ['recetas' => count($ids), 'comidas' => count($asignaciones), 'sin_hueco' => count($huecos) - count($asignaciones)];
}
