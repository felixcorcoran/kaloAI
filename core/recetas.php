<?php

/**
 * RECETAS Y PLAN SEMANAL - KaloAI
 * Funciones compartidas por el generador con IA, el guardado de recetas y el
 * plan semanal. Las que no tocan la base de datos son puras y tienen tests
 * (tests/RecetasTest.php).
 */

/** Momentos del día, tal como los guarda comida_planificada.momento_comida. */
const MOMENTOS_COMIDA = ['Desayuno', 'Media Mañana', 'Almuerzo', 'Merienda', 'Cena'];

/** Días de la semana: el índice es comida_planificada.dia_semana (0 = lunes). */
const DIAS_SEMANA = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

/** Tipos de receta que admite receta.tipo_comida. */
const TIPOS_RECETA = ['Desayuno', 'Almuerzo', 'Cena', 'Snack', 'Postre'];

/** "Miércoles" -> 2. Devuelve null si no es un día válido. */
function diaANumero(?string $dia): ?int {
    if ($dia === null) return null;
    $indice = array_search(mb_strtolower(trim($dia), 'UTF-8'), array_map(fn($d) => mb_strtolower($d, 'UTF-8'), DIAS_SEMANA), true);
    return $indice === false ? null : $indice;
}

/** "media mañana" -> "Media Mañana". Devuelve null si no es un momento válido. */
function momentoValido(?string $momento): ?string {
    if ($momento === null) return null;
    $buscado = mb_strtolower(trim($momento), 'UTF-8');
    foreach (MOMENTOS_COMIDA as $m) {
        if (mb_strtolower($m, 'UTF-8') === $buscado) return $m;
    }
    return null;
}

/**
 * Tipo con el que se guarda una receta en la biblioteca según el momento:
 * media mañana, merienda y similares son "Snack".
 */
function tipoRecetaParaMomento(?string $momento): string {
    $m = mb_strtolower(trim((string) $momento), 'UTF-8');
    if (in_array($m, ['media mañana', 'merienda', 'snack', 'tentempié'], true)) return 'Snack';
    foreach (TIPOS_RECETA as $tipo) {
        if (mb_strtolower($tipo, 'UTF-8') === $m) return $tipo;
    }
    return 'Almuerzo';
}

/**
 * Divide las instrucciones guardadas en pasos. Las recetas nuevas guardan un
 * paso por línea; las antiguas, todo seguido, y se cortan por los puntos.
 * Quita la numeración inicial ("1.", "2)") para no repetirla en la vista.
 */
function dividirPasos(?string $descripcion): array {
    $texto = trim((string) $descripcion);
    if ($texto === '') return [];

    $partes = str_contains($texto, "\n")
        ? preg_split('/\R+/u', $texto)
        : preg_split('/(?<=[.!?])\s+/u', $texto);

    $pasos = [];
    foreach ($partes as $parte) {
        $paso = trim(preg_replace('/^\s*\d+\s*[.)-]\s*/u', '', $parte));
        if ($paso !== '') $pasos[] = $paso;
    }
    return $pasos;
}

/** Texto en minúsculas y sin tildes, para comparar nombres de ingredientes. */
function normalizarIngrediente(string $texto): string {
    $texto = mb_strtolower(trim($texto), 'UTF-8');
    $texto = strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return preg_replace('/\s+/u', ' ', $texto);
}

/**
 * Comprueba si alguno de los ingredientes contiene un alimento prohibido
 * (alergia, intolerancia o preferencia). Compara palabras completas sin tildes
 * y admite el plural simple: "Nuez" prohíbe "Nueces"; "Huevo" prohíbe
 * "Claras de huevo". Devuelve los prohibidos encontrados, sin repetir.
 */
function ingredientesProhibidosEn(array $ingredientes, array $prohibidos): array {
    $encontrados = [];
    foreach ($prohibidos as $prohibido) {
        $base = normalizarIngrediente((string) $prohibido);
        if ($base === '') continue;
        // "nuez" también tiene que encajar con "nueces"
        $raiz = preg_quote(preg_replace('/z$/u', '', $base), '/');
        $patron = '/\b' . $raiz . '(z|ces|s|es)?\b/u';
        if ($raiz === preg_quote($base, '/')) {
            $patron = '/\b' . $raiz . '(s|es)?\b/u';
        }
        foreach ($ingredientes as $ingrediente) {
            $nombre = is_array($ingrediente) ? (string) ($ingrediente['nombre'] ?? '') : (string) $ingrediente;
            if (preg_match($patron, normalizarIngrediente($nombre))) {
                $encontrados[] = $prohibido;
                break;
            }
        }
    }
    return array_values(array_unique($encontrados));
}

/**
 * Junta los ingredientes repetidos (mismo nombre y unidad) sumando cantidades.
 * detalle_receta tiene como clave (id_receta, nombre_ingrediente), así que un
 * ingrediente repetido hacía fallar el guardado de toda la receta.
 */
function unificarIngredientes(array $ingredientes): array {
    $resultado = [];
    foreach ($ingredientes as $ing) {
        $nombre = trim((string) ($ing['nombre'] ?? ''));
        if ($nombre === '') continue;
        $clave = normalizarIngrediente($nombre);
        $cantidad = is_numeric($ing['cantidad'] ?? null) ? (float) $ing['cantidad'] : 0.0;
        $unidad = trim((string) ($ing['unidad'] ?? '')) ?: 'unidad';

        if (isset($resultado[$clave])) {
            if ($resultado[$clave]['unidad'] === $unidad) {
                $resultado[$clave]['cantidad'] += $cantidad;
            }
            // Misma comida con otra unidad: se queda la primera (no se pueden sumar)
            continue;
        }
        $resultado[$clave] = ['nombre' => mb_substr($nombre, 0, 200), 'cantidad' => $cantidad, 'unidad' => mb_substr($unidad, 0, 50)];
    }
    return array_values($resultado);
}

/**
 * SEMANAS
 * Hay un plan por persona y semana; cada semana se identifica por su lunes
 * ('Y-m-d'), guardado en plan_semanal.fecha_inicio_semana.
 */

/** Lunes de la semana de una fecha ('Y-m-d'); sin fecha, el de esta semana. */
function lunesDe(?string $fecha = null): string {
    $dia = $fecha ? DateTimeImmutable::createFromFormat('!Y-m-d', $fecha) : new DateTimeImmutable('today');
    if (!$dia) $dia = new DateTimeImmutable('today');
    return $dia->modify('-' . ((int) $dia->format('N') - 1) . ' days')->format('Y-m-d');
}

/**
 * Semana pedida por la URL o un formulario, convertida en su lunes y acotada
 * a un rango razonable (dos años atrás, ocho semanas adelante). Si no es una
 * fecha válida, la semana actual.
 */
function semanaValida(?string $semana, ?string $hoy = null): string {
    $actual = lunesDe($hoy);
    $fecha = $semana ? DateTimeImmutable::createFromFormat('!Y-m-d', $semana) : false;
    // createFromFormat acepta el 31 de febrero (lo pasa a marzo): se exige que la fecha exista
    if (!$fecha || $fecha->format('Y-m-d') !== $semana) return $actual;
    $lunes = lunesDe($semana);
    $min = (new DateTimeImmutable($actual))->modify('-104 weeks')->format('Y-m-d');
    $max = (new DateTimeImmutable($actual))->modify('+8 weeks')->format('Y-m-d');
    return max($min, min($max, $lunes));
}

/** Lunes de la semana anterior o siguiente. */
function semanaRelativa(string $lunes, int $semanas): string {
    return (new DateTimeImmutable($lunes))->modify(($semanas >= 0 ? '+' : '') . $semanas . ' weeks')->format('Y-m-d');
}

/** Fecha concreta ('Y-m-d') de un día (0 = lunes) de una semana. */
function fechaDelDia(string $lunes, int $diaNumero): string {
    return (new DateTimeImmutable($lunes))->modify("+$diaNumero days")->format('Y-m-d');
}

/** Texto de la semana: «Esta semana», «La semana que viene» o «9 – 15 nov». */
function nombreSemana(string $lunes, ?string $hoy = null): string {
    $actual = lunesDe($hoy);
    if ($lunes === $actual) return 'Esta semana';
    if ($lunes === semanaRelativa($actual, 1)) return 'La semana que viene';
    if ($lunes === semanaRelativa($actual, -1)) return 'La semana pasada';
    $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $ini = new DateTimeImmutable($lunes);
    $fin = $ini->modify('+6 days');
    $texto = $ini->format('j') . ($ini->format('n') !== $fin->format('n') ? ' ' . $meses[$ini->format('n') - 1] : '')
           . ' – ' . $fin->format('j') . ' ' . $meses[$fin->format('n') - 1];
    $anioActual = (new DateTimeImmutable($actual))->format('Y');
    return $texto . ($ini->format('Y') !== $anioActual || $fin->format('Y') !== $anioActual ? ' ' . $fin->format('Y') : '');
}

/** Id del plan de una semana, o null si esa semana aún no tiene plan. */
function obtenerPlan(PDO $pdo, int $userId, ?string $lunes = null): ?int {
    $stmt = $pdo->prepare("SELECT id_plan FROM plan_semanal WHERE id_usuario = ? AND fecha_inicio_semana = ?");
    $stmt->execute([$userId, $lunes ?? lunesDe()]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

/** Devuelve el plan de una semana (por defecto la actual) y lo crea si aún no existe. */
function obtenerOCrearPlan(PDO $pdo, int $userId, ?string $lunes = null): int {
    $lunes = $lunes ?? lunesDe();
    if ($id = obtenerPlan($pdo, $userId, $lunes)) return $id;
    // INSERT IGNORE: si dos peticiones llegan a la vez, la clave única evita el duplicado
    $pdo->prepare("INSERT IGNORE INTO plan_semanal (id_usuario, fecha_inicio_semana) VALUES (?, ?)")->execute([$userId, $lunes]);
    return obtenerPlan($pdo, $userId, $lunes);
}

/**
 * Pone una receta de la persona en un hueco del plan de una semana (sustituye
 * lo que hubiera). Devuelve false si la receta no es suya o el día/momento no son válidos.
 */
function asignarRecetaAlPlan(PDO $pdo, int $userId, int $idReceta, int $diaNumero, string $momento, ?string $lunes = null): bool {
    $momento = momentoValido($momento);
    if ($momento === null || $diaNumero < 0 || $diaNumero > 6) return false;

    $propia = $pdo->prepare("SELECT 1 FROM receta WHERE id_receta = ? AND id_usuario_creador = ?");
    $propia->execute([$idReceta, $userId]);
    if (!$propia->fetchColumn()) return false;

    $idPlan = obtenerOCrearPlan($pdo, $userId, $lunes);
    $pdo->prepare("DELETE FROM comida_planificada WHERE id_plan = ? AND dia_semana = ? AND momento_comida = ?")
        ->execute([$idPlan, $diaNumero, $momento]);
    $pdo->prepare("INSERT INTO comida_planificada (id_plan, id_receta, dia_semana, momento_comida, raciones) VALUES (?, ?, ?, ?, 1)")
        ->execute([$idPlan, $idReceta, $diaNumero, $momento]);
    return true;
}

/**
 * Copia las comidas de una semana en otra, solo en los huecos vacíos (lo que
 * ya hubiera en la semana de destino no se toca). Devuelve cuántas ha copiado.
 */
function copiarSemana(PDO $pdo, int $userId, string $origen, string $destino): int {
    $idOrigen = obtenerPlan($pdo, $userId, $origen);
    if (!$idOrigen || $origen === $destino) return 0;
    $idDestino = obtenerOCrearPlan($pdo, $userId, $destino);
    $stmt = $pdo->prepare("INSERT INTO comida_planificada (id_plan, id_receta, dia_semana, momento_comida, raciones)
                           SELECT ?, o.id_receta, o.dia_semana, o.momento_comida, o.raciones
                           FROM comida_planificada o
                           WHERE o.id_plan = ?
                             AND NOT EXISTS (SELECT 1 FROM comida_planificada d
                                             WHERE d.id_plan = ? AND d.dia_semana = o.dia_semana AND d.momento_comida = o.momento_comida)");
    $stmt->execute([$idDestino, $idOrigen, $idDestino]);
    return $stmt->rowCount();
}

/**
 * Guarda una receta generada por la IA (ya normalizada por
 * generarRecetaInteligente) con sus ingredientes. Devuelve el id de la receta.
 * Debe llamarse dentro de una transacción.
 */
function guardarRecetaIA(PDO $pdo, int $userId, array $receta, ?string $momento): int {
    $instrucciones = $receta['instrucciones'] ?? [];
    $instrucciones = is_array($instrucciones) ? implode("\n", array_map('strval', $instrucciones)) : (string) $instrucciones;
    $numero = fn($v) => is_numeric($v) ? (float) $v : 0.0;

    $pdo->prepare("INSERT INTO receta (id_usuario_creador, titulo, tipo_comida, descripcion, calorias_por_racion,
                       proteinas_g_por_racion, carbohidratos_g_por_racion, grasas_g_por_racion, generado_ia)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)")
        ->execute([
            $userId,
            mb_substr(trim((string) ($receta['titulo'] ?? '')) ?: 'Receta KaloAI', 0, 200),
            tipoRecetaParaMomento($momento),
            $instrucciones,
            (int) round($numero($receta['calorias'] ?? 0)),
            $numero($receta['macros']['proteinas'] ?? 0),
            $numero($receta['macros']['carbohidratos'] ?? 0),
            $numero($receta['macros']['grasas'] ?? 0),
        ]);
    $idReceta = (int) $pdo->lastInsertId();

    $stmtDetalle = $pdo->prepare("INSERT INTO detalle_receta (id_receta, nombre_ingrediente, cantidad, unidad) VALUES (?, ?, ?, ?)");
    foreach (unificarIngredientes($receta['ingredientes_usados'] ?? []) as $ing) {
        $stmtDetalle->execute([$idReceta, $ing['nombre'], $ing['cantidad'], $ing['unidad']]);
    }
    return $idReceta;
}

/** Unidades que se reconocen al escribir un ingrediente a mano. */
const UNIDADES_INGREDIENTE = [
    'g' => 'g', 'gr' => 'g', 'gramos' => 'g', 'kg' => 'kg', 'mg' => 'mg',
    'ml' => 'ml', 'l' => 'L', 'litro' => 'L', 'litros' => 'L', 'cl' => 'cl',
    'unidad' => 'unidad', 'unidades' => 'unidad', 'ud' => 'unidad', 'uds' => 'unidad', 'u' => 'unidad',
    'cda' => 'cda', 'cdas' => 'cda', 'cucharada' => 'cda', 'cucharadas' => 'cda',
    'cdta' => 'cdta', 'cucharadita' => 'cdta', 'cucharaditas' => 'cdta',
    'taza' => 'taza', 'tazas' => 'taza', 'pizca' => 'pizca', 'diente' => 'diente', 'dientes' => 'diente',
];

/**
 * "150 g Pechuga de pollo" -> ['nombre' => 'Pechuga de pollo', 'cantidad' => 150.0, 'unidad' => 'g'].
 * Sin número al principio, se entiende 1 unidad. Acepta coma decimal y "de" tras la unidad.
 */
function parsearLineaIngrediente(string $linea): ?array {
    $linea = trim($linea);
    if ($linea === '') return null;

    if (preg_match('/^(\d+(?:[.,]\d+)?)\s*([[:alpha:]]+\.?)?\s+(?:de\s+)?(.+)$/u', $linea, $m)) {
        $cantidad = (float) str_replace(',', '.', $m[1]);
        $posibleUnidad = mb_strtolower(rtrim($m[2] ?? '', '.'), 'UTF-8');
        if ($posibleUnidad !== '' && isset(UNIDADES_INGREDIENTE[$posibleUnidad])) {
            return ['nombre' => trim($m[3]), 'cantidad' => $cantidad, 'unidad' => UNIDADES_INGREDIENTE[$posibleUnidad]];
        }
        // "2 huevos": el número es la cantidad y lo demás, el nombre
        $nombre = trim(($m[2] ?? '') . ' ' . $m[3]);
        return ['nombre' => $nombre, 'cantidad' => $cantidad, 'unidad' => 'unidad'];
    }
    return ['nombre' => $linea, 'cantidad' => 1.0, 'unidad' => 'unidad'];
}

/** Lo contrario: una fila de detalle_receta como línea editable ("150 g Pechuga de pollo"). */
function formatearLineaIngrediente(array $ing): string {
    $cantidad = (float) ($ing['cantidad'] ?? 0);
    $cantidadTexto = rtrim(rtrim(number_format($cantidad, 3, '.', ''), '0'), '.');
    $unidad = (string) ($ing['unidad'] ?? 'unidad');
    $nombre = (string) ($ing['nombre_ingrediente'] ?? $ing['nombre'] ?? '');
    if ($cantidad <= 0) return $nombre;
    return $unidad === 'unidad' ? "$cantidadTexto $nombre" : "$cantidadTexto $unidad $nombre";
}

/**
 * Unidad base y factor de cada unidad, para poder sumar y restar cantidades
 * expresadas en unidades distintas (1 kg = 1000 g, 1 L = 1000 ml).
 */
function unidadBase(string $unidad): array {
    $u = mb_strtolower(trim($unidad), 'UTF-8');
    return match ($u) {
        'g', 'gr', 'gramos' => ['g', 1.0],
        'kg'                => ['g', 1000.0],
        'mg'                => ['g', 0.001],
        'ml'                => ['ml', 1.0],
        'cl'                => ['ml', 10.0],
        'l', 'litro', 'litros' => ['ml', 1000.0],
        '', 'u', 'u.', 'ud', 'uds', 'unidad', 'unidades' => ['unidad', 1.0],
        default             => [$u, 1.0],
    };
}

/** Nombre de un ingrediente reducido a lo que identifica el producto que se compra. */
function claveProducto(string $nombre): string {
    $n = normalizarIngrediente($nombre);
    // "Arroz cocido" y "Arroz" son el mismo producto en la lista de la compra
    $n = preg_replace('/\b(cocido|cocida|en seco|peso en seco|en hojuelas|en copos|frescas?|frescos?|semidesnatada|desnatada)\b|\(peso ?\)/u', '', $n);
    return trim(preg_replace('/\s+/u', ' ', $n));
}

/**
 * Lista de la compra: lo que piden las recetas del plan (cantidad x raciones)
 * menos lo que ya hay en el inventario, sumando en la misma unidad base.
 * Antes "1 kg" en la despensa no descontaba "80 g" de una receta.
 *
 * @param array $necesarios Filas con nombre_ingrediente, cantidad, unidad y raciones
 * @param array $inventario Filas con nombre_ingrediente, cantidad y unidad
 * @return array Filas con nombre, total_recetas, en_despensa, comprar y unidad
 */
function calcularListaCompra(array $necesarios, array $inventario): array {
    $pasarABase = function (string $nombre, string $unidad): array {
        [$base, $factor] = unidadBase($unidad);
        $clave = claveProducto($nombre);
        // Aceite y leche siempre en ml, aunque la receta diga gramos
        if ($base === 'g' && preg_match('/\b(aceite|leche)\b/u', $clave)) $base = 'ml';
        return [$clave . '|' . $base, $base, $factor];
    };

    $necesidad = [];
    foreach ($necesarios as $fila) {
        $nombre = trim((string) $fila['nombre_ingrediente']);
        [$clave, $base, $factor] = $pasarABase($nombre, (string) $fila['unidad']);
        $cantidad = (float) $fila['cantidad'] * (float) ($fila['raciones'] ?? 1) * $factor;
        if (!isset($necesidad[$clave])) {
            $necesidad[$clave] = ['nombre' => mb_strtoupper(mb_substr($nombre, 0, 1)) . mb_substr($nombre, 1), 'cantidad' => 0.0, 'unidad' => $base];
        }
        $necesidad[$clave]['cantidad'] += $cantidad;
    }

    $despensa = [];
    foreach ($inventario as $fila) {
        [$clave, , $factor] = $pasarABase((string) $fila['nombre_ingrediente'], (string) $fila['unidad']);
        $despensa[$clave] = ($despensa[$clave] ?? 0.0) + (float) $fila['cantidad'] * $factor;
    }

    $lista = [];
    foreach ($necesidad as $clave => $item) {
        $tengo = $despensa[$clave] ?? 0.0;
        $falta = round($item['cantidad'] - $tengo, 2);
        if ($falta > 0) {
            $lista[] = [
                'nombre'        => $item['nombre'],
                'total_recetas' => round($item['cantidad'], 2),
                'en_despensa'   => round($tengo, 2),
                'comprar'       => $falta,
                'unidad'        => $item['unidad'],
            ];
        }
    }
    usort($lista, fn($a, $b) => strcmp(claveProducto($a['nombre']), claveProducto($b['nombre'])));
    return $lista;
}

/**
 * Suma una compra al inventario. Si ya hay ese producto en una unidad
 * compatible (g/kg, ml/L), lo suma en la unidad que ya tenía; si no, crea la fila.
 */
function sumarAlInventario(PDO $pdo, int $userId, string $nombre, float $cantidad, string $unidad): void {
    [$baseNueva, $factorNuevo] = unidadBase($unidad);
    $clave = claveProducto($nombre);

    $stmt = $pdo->prepare("SELECT id_inventario, nombre_ingrediente, cantidad, unidad FROM inventario WHERE id_usuario = ?");
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $fila) {
        [$base, $factor] = unidadBase((string) $fila['unidad']);
        if ($base === $baseNueva && claveProducto((string) $fila['nombre_ingrediente']) === $clave) {
            $nuevaCantidad = (float) $fila['cantidad'] + ($cantidad * $factorNuevo) / $factor;
            $pdo->prepare("UPDATE inventario SET cantidad = ? WHERE id_inventario = ? AND id_usuario = ?")
                ->execute([round($nuevaCantidad, 3), $fila['id_inventario'], $userId]);
            return;
        }
    }
    $pdo->prepare("INSERT INTO inventario (id_usuario, nombre_ingrediente, cantidad, unidad) VALUES (?, ?, ?, ?)")
        ->execute([$userId, mb_substr($nombre, 0, 200), round($cantidad, 3), mb_substr($unidad, 0, 50) ?: 'unidad']);
}

/**
 * SECCIONES DEL SÚPER para ordenar la lista de la compra.
 * El orden es el de un recorrido típico por la tienda.
 */
const SECCIONES_COMPRA = [
    'Frutas y verduras'          => '🥬',
    'Carne y pescado'            => '🍗',
    'Huevos y lácteos'           => '🥚',
    'Pan, cereales y pasta'      => '🍞',
    'Legumbres y conservas'      => '🥫',
    'Frutos secos y semillas'    => '🥜',
    'Aceites, salsas y especias' => '🧂',
    'Otros'                      => '🛒',
];

/**
 * Sección del súper de un producto, por palabras clave (sin tildes, en
 * singular o plural). Las reglas van en un orden pensado para los casos
 * dudosos: «tomate triturado» es conserva, «salsa de tomate» es salsa,
 * «bebida de avena» va con los lácteos y «crema de cacahuete» con los frutos secos.
 * Función pura.
 */
function seccionProducto(string $nombre): string {
    static $reglas = [
        ['Legumbres y conservas', 'lata|latas|conserva|en bote|triturado|tomate frito|atun|garbanzos?|lentejas?|alubias?|judias? (blancas?|rojas?|pintas?)|frijol(es)?|soja|tofu|tempeh|edamame|hummus|maiz dulce'],
        ['Frutos secos y semillas', 'nuez|nueces|almendras?|cacahuetes?|anacardos?|pistachos?|avellanas?|pipas?|semillas?|chia|lino|sesamo|crema de cacahuete'],
        ['Aceites, salsas y especias', 'aceite|vinagre|sal|pimienta|especias?|oregano|comino|pimenton|curry|canela|curcuma|salsa|mostaza|mayonesa|ketchup|tomate frito|caldo|miel|azucar|edulcorante|levadura|vainilla|nuez moscada|tomillo|romero|laurel'],
        ['Carne y pescado', 'pollo|pavo|ternera|cerdo|lomo|jamon|chorizo|salchichas?|carne|hamburguesas?|solomillo|pechugas?|muslos?|conejo|cordero|bacon|panceta|pescado|salmon|merluza|bacalao|dorada|lubina|sardinas?|boquerones?|gambas?|langostinos?|mejillones?|calamar(es)?|pulpo|marisco|sepia|rape|trucha|caballa'],
        ['Huevos y lácteos', 'huevos?|claras?|leche|yogur(es)?|queso|mantequilla|nata|kefir|requeson|bebida|cuajada'],
        ['Pan, cereales y pasta', 'pan|panes|tostadas?|arroz|pasta|espaguetis?|macarrones|fideos?|avena|quinoa|cuscus|harina|cereales?|tortillas? de trigo|wraps?|galletas?|copos|bulgur|biscotes?'],
        ['Frutas y verduras', 'tomates?|lechugas?|cebollas?|ajos?|pimientos?|zanahorias?|calabacin(es)?|berenjenas?|espinacas?|brocoli|coliflor|patatas?|boniatos?|pepinos?|calabaza|champinones|setas?|puerros?|apio|rucula|canonigos|aguacates?|platanos?|manzanas?|peras?|naranjas?|limon(es)?|lima|fresas?|frutos rojos|arandanos?|uvas?|kiwis?|mango|pina|melon|sandia|melocoton(es)?|verduras?|frutas?|perejil|cilantro|albahaca|jengibre|judias? verdes?|guisantes?|hierbabuena|menta|alcachofas?|esparragos?'],
    ];
    $n = normalizarIngrediente($nombre);
    foreach ($reglas as [$seccion, $patron]) {
        if (preg_match('/\b(' . $patron . ')\b/u', $n)) return $seccion;
    }
    return 'Otros';
}

/** Agrupa la lista de la compra por secciones, en el orden de SECCIONES_COMPRA. Función pura. */
function agruparPorSeccion(array $lista): array {
    $grupos = array_fill_keys(array_keys(SECCIONES_COMPRA), []);
    foreach ($lista as $item) $grupos[seccionProducto($item['nombre'])][] = $item;
    return array_filter($grupos);
}

/**
 * FAVORITAS Y COMENSALES
 */

/** Marca o desmarca una receta propia como favorita. Devuelve el estado nuevo, o null si no es suya. */
function alternarFavorita(PDO $pdo, int $userId, int $idReceta): ?bool {
    $stmt = $pdo->prepare("UPDATE receta SET favorita = 1 - favorita WHERE id_receta = ? AND id_usuario_creador = ?");
    $stmt->execute([$idReceta, $userId]);
    if ($stmt->rowCount() === 0) return null;
    $estado = $pdo->prepare("SELECT favorita FROM receta WHERE id_receta = ?");
    $estado->execute([$idReceta]);
    return (bool) $estado->fetchColumn();
}

/** Para cuántas personas cocina (perfil.comensales), entre 1 y 12. */
function comensalesDe(PDO $pdo, int $userId): int {
    $stmt = $pdo->prepare("SELECT comensales FROM perfil WHERE id_usuario = ?");
    $stmt->execute([$userId]);
    return max(1, min(12, (int) ($stmt->fetchColumn() ?: 1)));
}

/**
 * Multiplica las raciones de las filas de la lista de la compra por el número
 * de comensales (cada comida del plan se cocina para toda la casa). Función pura.
 */
function multiplicarRaciones(array $necesarios, int $comensales): array {
    if ($comensales <= 1) return $necesarios;
    return array_map(function ($fila) use ($comensales) {
        $fila['raciones'] = (float) ($fila['raciones'] ?? 1) * $comensales;
        return $fila;
    }, $necesarios);
}
