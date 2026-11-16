<?php
/**
 * GESTIÓN DE PERFIL NUTRICIONAL Y OBJETIVOS - KaloAI
 * Este script actúa como el "cerebro" metabólico de la aplicación, calculando
 * el TDEE (Gasto Energético Total) y el reparto de macros según el objetivo.
 */

$pageTitle = "KaloAI | Mi perfil";

session_start();

require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php'; 

// 1. SEGURIDAD: Control de acceso para proteger datos sensibles de salud
if (!isset($_SESSION['user_id'])) {
    redirect('../auth/login.php'); 
}

$userId = $_SESSION['user_id'];
$message = '';
$messageType = 'success';
$profileData = [];
$pdo = connectDB();

// Diccionario de seguridad alimentaria para la base de datos
$tiposRestriccion = [
    'alergia' => 'Alergia (Riesgo Alto)',
    'intolerancia' => 'Intolerancia (Riesgo Medio)',
    'no_gusto' => 'No Gusto (Preferencia)'
];

/**
 * ==========================================================
 * MAPEOS DE DATOS NUTRICIONALES
 * Factores basados en la fórmula de Harris-Benedict / Mifflin-St Jeor
 * ==========================================================
 */
$activityFactors = [
    'sedentario' => 1.2,
    'ligero' => 1.375,
    'moderado' => 1.55,
    'activo' => 1.725,
    'muy_activo' => 1.9
];

$nivelesActividad = [
    'sedentario' => 'Sedentario (poco o ningún ejercicio)',
    'ligero' => 'Ejercicio ligero (1-3 días/semana)',
    'moderado' => 'Ejercicio moderado (3-5 días/semana)',
    'activo' => 'Ejercicio activo (6-7 días/semana)',
    'muy_activo' => 'Muy Activo (ejercicio diario intenso / doble sesión)'
];

$objetivos = [
    'perdida_rapida' => 'Pérdida de peso Rápida (déficit alto)',
    'perdida_moderada' => 'Pérdida de peso Moderada (déficit medio)',
    'perdida_grasa' => 'Pérdida de Grasa (definición)',
    'mantenimiento' => 'Mantenimiento de peso actual',
    'ganancia_muscular' => 'Ganancia Muscular (volumen)',
    'recomposicion_corporal' => 'Recomposición Corporal (ganar músculo y perder grasa)',
    'rendimiento_deportivo' => 'Rendimiento Deportivo (máximo rendimiento físico)'
];

// Inicialización de variables para cálculos de macronutrientes
$proteinasObjetivo = 0;
$carbohidratosObjetivo = 0;
$grasasObjetivo = 0;
$caloriasNetasObjetivo = 0;

$currentRestrictions = []; 

/**
 * ==========================================================
 * 2. LÓGICA DE ACTUALIZACIÓN (POST)
 * Procesa el formulario, valida límites biológicos y guarda en DB.
 * ==========================================================
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];

    // Sanitización exhaustiva de entradas para prevenir XSS e inyecciones
    $nombre = limpiarInput($_POST['nombre'] ?? '');
    $fechaNacimiento = limpiarInput($_POST['fecha_nacimiento'] ?? '');
    $genero = limpiarInput($_POST['genero'] ?? '');
    $alturaCm = filter_var($_POST['altura_cm'] ?? '', FILTER_VALIDATE_INT);
    $pesoKg = filter_var($_POST['peso_kg'] ?? '', FILTER_VALIDATE_FLOAT);
    $nivelActividad = limpiarInput($_POST['nivel_actividad'] ?? '');
    $objetivo = limpiarInput($_POST['objetivo'] ?? ''); 
    // Para cuántas personas cocina (solo cambia las cantidades de la lista de la compra)
    $comensales = max(1, min(12, (int) ($_POST['comensales'] ?? 1)));
    
    // Decodificación de restricciones enviadas vía JSON desde el frontend
    $restriccionesJson = $_POST['restricciones_json'] ?? '[]';
    $restriccionesArray = json_decode($restriccionesJson, true);
    // Solo una lista de objetos, y con un máximo razonable de elementos
    $restriccionesArray = is_array($restriccionesArray) ? array_slice(array_filter($restriccionesArray, 'is_array'), 0, 100) : [];
    $currentRestrictions = $restriccionesArray; 

    // VALIDACIONES DE INTEGRIDAD BIOLÓGICA
    if (strlen($nombre) < 2 || strlen($nombre) > 50) {
        $errors[] = "El nombre debe tener entre 2 y 50 caracteres.";
    }

    if (!$pesoKg || $pesoKg < 30 || $pesoKg > 350) {
        $errors[] = "Por favor, introduce un peso válido entre 30 y 350 kg.";
    }

    if (!$alturaCm || $alturaCm < 100 || $alturaCm > 250) {
        $errors[] = "La altura debe estar entre 100 y 250 cm.";
    }

    // El sistema restringe el uso a rangos donde las fórmulas son precisas (15-100 años)
    if (!empty($fechaNacimiento) && strtotime($fechaNacimiento) !== false) {
        $edad = calcularEdad($fechaNacimiento);
        if ($edad === false || $edad < 15 || $edad > 100) {
            $errors[] = "La edad permitida es entre 15 y 100 años para garantizar cálculos precisos.";
        }
    } else {
        $errors[] = "La fecha de nacimiento no es válida.";
    }

    if (!in_array($genero, ['M', 'F'])) {
        $errors[] = "El género seleccionado no es válido.";
    }

    if (!array_key_exists($nivelActividad, $nivelesActividad)) {
        $errors[] = "El nivel de actividad seleccionado no es válido.";
    } else {
        $activityFactor = $activityFactors[$nivelActividad];
    }
    
    if (!array_key_exists($objetivo, $objetivos)) { 
        $errors[] = "El objetivo seleccionado no es válido.";
    }

    // PROCESAMIENTO MATEMÁTICO: Si los inputs son válidos, calculamos la dieta
    if (empty($errors)) {
        try {
            // 1. TDEE: Calorías de mantenimiento (gasto total diario)
            $tdeeCalorias = calcularTDEE($genero, $pesoKg, $alturaCm, $edad, $activityFactor);
            $tdeeCalorias = (int) round($tdeeCalorias);

            // 2. MACROS: Ajuste de calorías y gramos según objetivo (ej: déficit para perder peso)
            $macros = calcularMacrosObjetivo($tdeeCalorias, $objetivo);
            $proteinasObjetivo = $macros['proteinas'];
            $carbohidratosObjetivo = $macros['carbohidratos'];
            $grasasObjetivo = $macros['grasas'];
            $caloriasNetasObjetivo = $macros['calorias_netas'];
            
        } catch (\Exception $e) {
            error_log("Error al calcular datos nutricionales: " . $e->getMessage());
            $errors[] = "No se pudieron calcular tus objetivos con esos datos.";
            $tdeeCalorias = 0; 
        }
    }

    // PERSISTENCIA DE DATOS: Guardado transaccional en la base de datos
    if (empty($errors)) {
        try {
            $pdo->beginTransaction(); 

            // A. GESTIÓN DEL PERFIL: Detectamos si es actualización o creación nueva
            $stmt = $pdo->prepare("SELECT id_perfil FROM perfil WHERE id_usuario = ?");
            $stmt->execute([$userId]);
            $profileExists = $stmt->fetch();

            $params = [
                $nombre, $fechaNacimiento, $genero, $alturaCm, $pesoKg, $nivelActividad, $objetivo, 
                $tdeeCalorias, $proteinasObjetivo, $carbohidratosObjetivo, $grasasObjetivo, $caloriasNetasObjetivo, $comensales,
            ];

            $sqlSet = "nombre = ?, fecha_nacimiento = ?, genero = ?, altura_cm = ?, peso_kg = ?, nivel_actividad = ?, objetivo = ?, tdee_calorias = ?, proteinas_objetivo_g = ?, carbohidratos_objetivo_g = ?, grasas_objetivo_g = ?, calorias_netas_objetivo = ?, comensales = ?";
            
            if ($profileExists) {
                $sql = "UPDATE perfil SET {$sqlSet} WHERE id_usuario = ?";
                $params[] = $userId;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            } else {
                $cols = "id_usuario, nombre, fecha_nacimiento, genero, altura_cm, peso_kg, nivel_actividad, objetivo, tdee_calorias, proteinas_objetivo_g, carbohidratos_objetivo_g, grasas_objetivo_g, calorias_netas_objetivo, comensales";
                $placeholders = implode(', ', array_fill(0, 14, '?'));
                $sql = "INSERT INTO perfil ({$cols}) VALUES ({$placeholders})";
                array_unshift($params, $userId); 
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }

            // B. GESTIÓN DE EXCLUSIONES ALIMENTARIAS: Limpiar e insertar nuevas reglas
            $stmt = $pdo->prepare("DELETE FROM ingrediente_prohibido WHERE id_usuario = ?");
            $stmt->execute([$userId]);
            
            if (!empty($restriccionesArray)) {
                $sqlInsert = "INSERT INTO ingrediente_prohibido (id_usuario, nombre_ingrediente, nota) VALUES (?, ?, ?)";
                $stmtInsert = $pdo->prepare($sqlInsert);
                
                $vistos = []; // la tabla no admite el mismo ingrediente dos veces
                foreach ($restriccionesArray as $restriccion) {
                    $ingrediente = mb_substr(limpiarInput((string) ($restriccion['ingrediente'] ?? '')), 0, 200);
                    $tipo = limpiarInput((string) ($restriccion['tipo'] ?? 'no_especificado'));
                    
                    $claveIngrediente = normalizarString($ingrediente);
                    if (!empty($ingrediente) && !isset($vistos[$claveIngrediente])) {
                        $vistos[$claveIngrediente] = true;
                        $nota = $tiposRestriccion[$tipo] ?? 'Restricción Personalizada';
                        $stmtInsert->execute([$userId, $ingrediente, $nota]);
                    }
                }
            }
            
            $pdo->commit(); 
            $message = "Perfil, objetivos y restricciones alimentarias actualizadas.";
            
            // Actualización del estado local para refrescar la vista inmediatamente
            $profileData = [
                'nombre' => $nombre, 'fecha_nacimiento' => $fechaNacimiento, 'genero' => $genero, 
                'altura_cm' => $alturaCm, 'peso_kg' => $pesoKg, 'nivel_actividad' => $nivelActividad,
                'objetivo' => $objetivo, 'tdee_calorias' => $tdeeCalorias,
                'proteinas_objetivo_g' => $proteinasObjetivo, 
                'carbohidratos_objetivo_g' => $carbohidratosObjetivo,
                'grasas_objetivo_g' => $grasasObjetivo,
                'calorias_netas_objetivo' => $caloriasNetasObjetivo,
                'comensales' => $comensales,
            ];
            $currentRestrictions = $restriccionesArray;

        } catch (\PDOException $e) {
            $pdo->rollBack(); 
            error_log("Error al guardar el perfil: " . $e->getMessage());
            $errors[] = "No se pudo guardar el perfil. Inténtalo de nuevo.";
            $messageType = 'error';
        }
    }

    if (!empty($errors)) {
        $message = "<ul><li>" . implode("</li><li>", array_map('e', $errors)) . "</li></ul>";
        $messageType = 'error';
        // Mantener datos en memoria para no borrar el formulario en caso de error
        $profileData = array_merge($profileData, [
            'nombre' => $nombre, 'fecha_nacimiento' => $fechaNacimiento, 'genero' => $genero, 
            'altura_cm' => $alturaCm, 'peso_kg' => $pesoKg, 'nivel_actividad' => $nivelActividad,
            'objetivo' => $objetivo, 'comensales' => $comensales,
        ]);
    }
}

/**
 * ==========================================================
 * 3. LÓGICA DE CARGA (GET)
 * Recupera la información guardada para pre-rellenar el formulario.
 * ==========================================================
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' || !empty($errors)) {
    try {
        // Carga de metadatos del perfil
        $stmt = $pdo->prepare("SELECT nombre, fecha_nacimiento, genero, altura_cm, peso_kg, nivel_actividad, objetivo, tdee_calorias, proteinas_objetivo_g, carbohidratos_objetivo_g, grasas_objetivo_g, calorias_netas_objetivo, comensales FROM perfil WHERE id_usuario = ?");
        $stmt->execute([$userId]);
        $fetchedData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($fetchedData) {
            $profileData = array_merge($profileData, $fetchedData);
        } else if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($errors)) {
            $message = "¡Bienvenido! Completa tu perfil y objetivos para calcular tu Gasto Calórico Total (TDEE) y personalizar tus planes.";
        }
        
        // Carga y mapeo inverso de restricciones (DB Nota -> Tipo Clave)
        $stmtRestrictions = $pdo->prepare("SELECT nombre_ingrediente, nota FROM ingrediente_prohibido WHERE id_usuario = ?");
        $stmtRestrictions->execute([$userId]);
        $fetchedRestrictions = $stmtRestrictions->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($fetchedRestrictions)) {
            $tipoKeyMap = array_flip($tiposRestriccion);
            foreach ($fetchedRestrictions as $row) {
                $tipo = $tipoKeyMap[$row['nota']] ?? 'no_gusto'; 
                $currentRestrictions[] = [
                    'ingrediente' => $row['nombre_ingrediente'],
                    'tipo' => $tipo
                ];
            }
        }

    } catch (\PDOException $e) {
        error_log("Error al cargar el perfil o restricciones: " . $e->getMessage());
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
             $message = "No se pudieron cargar tus datos. Recarga la página.";
             $messageType = 'error';
        }
    }
}

// Carga del componente de cabecera 
include_once __DIR__ . '/../templates/header.php'; 
?>

<?php
$tieneObjetivos = ($profileData['tdee_calorias'] ?? 0) > 0 && empty($errors);
$netas = (int) ($profileData['calorias_netas_objetivo'] ?? 0);
$macrosPerfil = [
    ['k-macro-p', 'Proteína', (int) ($profileData['proteinas_objetivo_g'] ?? 0), 4],
    ['k-macro-c', 'Carbohidratos', (int) ($profileData['carbohidratos_objetivo_g'] ?? 0), 4],
    ['k-macro-f', 'Grasa', (int) ($profileData['grasas_objetivo_g'] ?? 0), 9],
];
$fechaNac = $profileData['fecha_nacimiento'] ?? '';
if ($fechaNac === '0000-00-00') $fechaNac = '';
?>

<div class="k-container k-page max-w-4xl">
    <div class="k-page-head">
        <div class="flex items-center gap-4">
            <span class="k-avatar h-14 w-14 text-xl" aria-hidden="true"><?= e($inicial) ?></span>
            <div>
            <span class="k-pill"><?= icono('user') ?><?= e($nombreRol) ?></span>
            <h1 class="k-title mt-2">Tu perfil</h1>
            </div>
        </div>
    </div>
    <p class="k-subtitle -mt-2 mb-6">Con estos datos KaloAI calcula tus calorías y macros diarios, y la IA evita lo que no puedes comer.</p>

    <?php if (isset($_GET['bienvenida']) && !$message): ?>
        <div class="k-alert k-alert-ok mb-6" role="status">
            <?= icono('sparkles') ?>
            <p><strong>¡Te damos la bienvenida a KaloAI!</strong> Completa estos datos y calcularemos tus calorías y macros. Después, la IA te prepara la semana.</p>
        </div>
    <?php endif; ?>
    <?php if ($message): ?>
        <div class="k-alert k-alert-<?= $messageType === 'success' ? (($profileData['tdee_calorias'] ?? 0) > 0 ? 'ok' : 'info') : 'error' ?> mb-6" role="alert">
            <?= icono($messageType === 'success' ? 'check' : 'alert') ?>
            <div><?= $message /* textos fijos; los errores ya vienen escapados */ ?></div>
        </div>
    <?php endif; ?>

    <?php if ($tieneObjetivos): ?>
        <!-- OBJETIVOS CALCULADOS -->
        <section class="k-card k-card-pad mb-6">
            <div class="flex flex-col gap-6 sm:flex-row sm:items-center">
                <div class="sm:w-56">
                    <p class="k-stat-label">Tu objetivo diario</p>
                    <p class="mt-1 font-display text-4xl font-bold tracking-tight tabular-nums k-strong"><?= number_format($netas, 0, ',', '.') ?><span class="k-stat-unit">kcal</span></p>
                    <p class="mt-1 text-xs k-muted">Mantenimiento (TDEE): <?= number_format((int) $profileData['tdee_calorias'], 0, ',', '.') ?> kcal</p>
                    <?php $ajuste = $netas - (int) $profileData['tdee_calorias']; if ($ajuste !== 0): ?>
                        <span class="k-delta mt-2 <?= $ajuste < 0 ? 'k-delta-ok' : 'k-delta-warn' ?>"><span class="k-delta-icon"><?= icono($ajuste < 0 ? 'trend-down' : 'trend-up') ?></span><?= ($ajuste > 0 ? '+' : '') . number_format($ajuste, 0, ',', '.') ?> kcal <?= $ajuste < 0 ? 'de déficit' : 'de superávit' ?></span>
                    <?php endif; ?>
                </div>
                <div class="flex-1">
                    <div class="flex h-2.5 overflow-hidden rounded-full" style="background-color: var(--k-surface-2);">
                        <?php foreach ($macrosPerfil as [$clase, , $gramos, $kcalG]): ?>
                            <span class="<?= $clase ?> k-macro-bar block h-full" style="width: <?= $netas ? round($gramos * $kcalG / $netas * 100, 1) : 0 ?>%"></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-4 grid grid-cols-3 gap-2">
                        <?php foreach ($macrosPerfil as [$clase, $etiqueta, $gramos, $kcalG]): ?>
                            <div class="k-macro-chip <?= $clase ?>">
                                <p class="k-stat-label flex items-center gap-1.5"><span class="k-macro-dot h-2 w-2"></span><?= $etiqueta ?></p>
                                <p class="font-display text-lg font-bold tabular-nums k-strong"><?= $gramos ?><span class="k-stat-unit">g</span></p>
                                <p class="text-[11px] k-muted"><?= $netas ? round($gramos * $kcalG / $netas * 100) : 0 ?>% de las kcal</p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <form method="POST" id="profileForm" action="perfil.php" class="space-y-6">
        <?= campoCsrf() ?>
        <input type="hidden" name="restricciones_json" id="restricciones_json" value='<?= json_html($currentRestrictions) ?>'>

        <!-- DATOS PERSONALES -->
        <section class="k-card k-card-pad">
            <h2 class="k-section-title">Datos personales</h2>
            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="nombre" class="k-label">Nombre</label>
                    <input id="nombre" name="nombre" type="text" required maxlength="50" class="k-input" value="<?= e($profileData['nombre'] ?? '') ?>">
                </div>
                <div>
                    <label for="fecha_nacimiento" class="k-label">Fecha de nacimiento</label>
                    <input id="fecha_nacimiento" name="fecha_nacimiento" type="date" required class="k-input" value="<?= e($fechaNac) ?>">
                </div>
                <div>
                    <label for="genero" class="k-label">Sexo <span class="font-normal k-muted">(para la fórmula)</span></label>
                    <select id="genero" name="genero" required class="k-input">
                        <option value="" disabled <?= empty($profileData['genero']) ? 'selected' : '' ?>>Elige…</option>
                        <option value="F" <?= ($profileData['genero'] ?? '') === 'F' ? 'selected' : '' ?>>Mujer</option>
                        <option value="M" <?= ($profileData['genero'] ?? '') === 'M' ? 'selected' : '' ?>>Hombre</option>
                    </select>
                </div>
                <div>
                    <label for="altura_cm" class="k-label">Altura (cm)</label>
                    <input id="altura_cm" name="altura_cm" type="number" step="1" min="100" max="250" required inputmode="numeric" class="k-input" value="<?= e($profileData['altura_cm'] ?? '') ?>">
                </div>
                <div>
                    <label for="peso_kg" class="k-label">Peso (kg)</label>
                    <input id="peso_kg" name="peso_kg" type="number" step="0.1" min="30" max="350" required inputmode="decimal" class="k-input" value="<?= e($profileData['peso_kg'] ?? '') ?>">
                </div>
            </div>
        </section>

        <!-- ACTIVIDAD Y OBJETIVO -->
        <section class="k-card k-card-pad">
            <h2 class="k-section-title">Actividad y objetivo</h2>
            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="nivel_actividad" class="k-label">Nivel de actividad</label>
                    <select id="nivel_actividad" name="nivel_actividad" required class="k-input">
                        <?php foreach ($nivelesActividad as $key => $value): ?>
                            <option value="<?= e($key) ?>" <?= ($profileData['nivel_actividad'] ?? '') === $key ? 'selected' : '' ?>><?= e($value) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="objetivo" class="k-label">Objetivo</label>
                    <select id="objetivo" name="objetivo" required class="k-input">
                        <?php foreach ($objetivos as $key => $value): ?>
                            <option value="<?= e($key) ?>" <?= ($profileData['objetivo'] ?? 'mantenimiento') === $key ? 'selected' : '' ?>><?= e($value) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label for="comensales" class="k-label">¿Para cuántas personas cocinas?</label>
                    <select id="comensales" name="comensales" class="k-input sm:w-56">
                        <?php for ($n = 1; $n <= 8; $n++): ?>
                            <option value="<?= $n ?>" <?= (int) ($profileData['comensales'] ?? 1) === $n ? 'selected' : '' ?>><?= $n === 1 ? 'Solo para mí' : "$n personas" ?></option>
                        <?php endfor; ?>
                    </select>
                    <p class="k-hint">La lista de la compra multiplica las cantidades. Tus calorías y macros siguen siendo los de tu ración.</p>
                </div>
            </div>
        </section>

        <!-- ALERGIAS E INTOLERANCIAS -->
        <section class="k-card k-card-pad">
            <div class="flex items-start gap-3">
                <span class="k-icon-tile" style="background-color: var(--k-danger-soft); color: var(--k-danger);"><?= icono('ban') ?></span>
                <div>
                    <h2 class="k-section-title">Alergias, intolerancias y preferencias</h2>
                    <p class="text-sm k-muted">La IA nunca usará estos alimentos, y KaloAI descarta cualquier receta que los incluya.</p>
                </div>
            </div>
            <div class="mt-5 flex flex-col gap-2 sm:flex-row">
                <label for="nuevo_ingrediente" class="sr-only">Alimento</label>
                <input type="text" id="nuevo_ingrediente" maxlength="200" class="k-input flex-1" placeholder="Ej. cacahuete, lactosa, marisco…">
                <label for="new_tipo" class="sr-only">Tipo</label>
                <select id="new_tipo" class="k-input sm:w-56">
                    <?php foreach ($tiposRestriccion as $key => $value): ?>
                        <option value="<?= e($key) ?>"><?= e($value) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="agregarRestriccionBtn" class="k-btn k-btn-secondary h-11"><?= icono('plus') ?>Añadir</button>
            </div>
            <p id="restriccionAviso" class="mt-2 text-sm text-red-600" hidden></p>
            <ul id="restrictionsList" class="k-list mt-3"></ul>
        </section>

        <div class="flex justify-end">
            <button type="submit" class="k-btn k-btn-primary k-btn-lg"><?= icono('check') ?>Guardar y recalcular</button>
        </div>
    </form>

    <?php if (empty($impersonando)):
        $avisoCuenta = $_SESSION['aviso_cuenta'] ?? null;
        unset($_SESSION['aviso_cuenta']); ?>
        <!-- TU CUENTA: tus datos son tuyos -->
        <section id="cuenta" class="k-card k-card-pad mt-10 scroll-mt-6">
            <div class="flex items-start gap-3">
                <span class="k-icon-tile k-tile-indigo"><?= icono('shield') ?></span>
                <div>
                    <h2 class="k-section-title">Tu cuenta</h2>
                    <p class="mt-1 text-sm k-muted">Tus datos son tuyos: puedes llevártelos o borrarlos cuando quieras.</p>
                </div>
            </div>
            <?php if ($avisoCuenta): [$tipoAviso, $textoAviso] = $avisoCuenta; ?>
                <div class="k-alert k-alert-<?= $tipoAviso === 'ok' ? 'ok' : 'error' ?> mt-5" role="alert"><?= icono('alert') ?><p><?= e($textoAviso) ?></p></div>
            <?php endif; ?>
            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                <form method="POST" action="cuenta.php" class="k-subtle m-0 flex flex-col rounded-2xl p-4">
                    <?= campoCsrf() ?>
                    <input type="hidden" name="accion" value="descargar">
                    <p class="font-medium k-strong">Descargar mis datos</p>
                    <p class="mt-1 flex-1 text-sm k-muted">Perfil, recetas, planes, comidas apuntadas, progreso y despensa en un archivo JSON.</p>
                    <button type="submit" class="k-btn k-btn-secondary mt-4 self-start"><?= icono('download') ?>Descargar</button>
                </form>
                <div class="k-subtle flex flex-col rounded-2xl p-4">
                    <p class="font-medium k-strong">Borrar mi cuenta</p>
                    <p class="mt-1 flex-1 text-sm k-muted">Se borran tu cuenta y todos tus datos para siempre. No se puede deshacer.</p>
                    <button type="button" onclick="abrirModal('modalBorrarCuenta')" class="k-btn k-btn-ghost k-btn-danger-text mt-4 self-start"><?= icono('trash') ?>Borrar mi cuenta</button>
                </div>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php if (empty($impersonando)): ?>
<!-- MODAL: BORRAR LA CUENTA -->
<div id="modalBorrarCuenta" class="k-modal" hidden role="dialog" aria-modal="true" aria-labelledby="tituloBorrarCuenta">
    <div class="k-modal-backdrop"></div>
    <form method="POST" action="cuenta.php" class="k-modal-panel max-w-sm">
        <?= campoCsrf() ?>
        <input type="hidden" name="accion" value="borrar">
        <span class="k-icon-tile k-tile-danger"><?= icono('alert') ?></span>
        <h2 id="tituloBorrarCuenta" class="k-modal-title mt-4">¿Borrar tu cuenta para siempre?</h2>
        <p class="mt-2 text-sm k-muted">Se borrarán tu perfil, tus recetas, tus planes, lo que has apuntado, tu progreso y tu despensa. Si quieres conservarlos, descárgalos antes.</p>
        <div class="mt-5">
            <label for="contrasenaBorrar" class="k-label">Escribe tu contraseña para confirmarlo</label>
            <input id="contrasenaBorrar" name="contrasena" type="password" required autocomplete="current-password" class="k-input" data-autofocus>
        </div>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="k-btn k-btn-secondary" data-cerrar-modal>Cancelar</button>
            <button type="submit" class="k-btn k-btn-danger">Borrar mi cuenta</button>
        </div>
    </form>
</div>
<?php endif; ?>

<script>
    // Datos para la lista de restricciones (main.js)
    window.profileData = {
        tipoMap: <?= json_html($tiposRestriccion) ?>,
        currentRestrictions: <?= json_html($currentRestrictions) ?>
    };
</script>

<?php include_once __DIR__ . '/../templates/footer.php'; ?>
