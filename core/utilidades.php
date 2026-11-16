<?php

/**
 * Seguridad común (token CSRF y escapado). Todo POST que llegue a una página
 * que cargue este archivo debe traer un token válido.
 */
require_once __DIR__ . '/seguridad.php';
exigirCsrfEnPost();

/**
 * Migraciones de la base de datos: tras un despliegue, la primera visita
 * aplica las que falten (core/migraciones.php). Si no hay nada nuevo, solo
 * lee un archivo pequeño.
 */
require_once __DIR__ . '/configuracion.php';
require_once __DIR__ . '/migraciones.php';
comprobarMigraciones();

/**
 * GESTIÓN DE SEGURIDAD (Contraseñas)
 * Implemento funciones para el manejo seguro de credenciales mediante hashing.
 */

/**
 * Genero un hash seguro para la contraseña utilizando el algoritmo por defecto del sistema.
 * Actualmente utiliza Bcrypt o Argon2 según la versión de PHP.
 */
function hashContrasena(string $password): string {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Comparo la contraseña introducida en el login con el hash almacenado en la base de datos.
 * Devuelve true si la verificación es exitosa.
 */
function verificarContrasena(string $password, string $hash): bool {
    return password_verify($password, $hash);
}


/**
 * SANITIZACIÓN Y VALIDACIÓN DE DATOS
 * Conjunto de funciones para limpiar entradas de usuario y prevenir ataques como XSS.
 */

/**
 * Normalizo el texto que llega de un formulario: quito espacios en los extremos
 * y caracteres de control invisibles. No lo convierto a HTML: el texto se guarda
 * tal cual y se escapa al mostrarlo con e() (core/seguridad.php). Escapar al
 * guardar dejaba "&amp;" en la base de datos y fallaba en los textos que no
 * pasaban por aquí, como los de la IA.
 */
function limpiarInput(string $data): string {
    $data = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $data) ?? '';
    return trim($data);
}

/**
 * Verifico si el formato del correo electrónico es válido siguiendo los estándares de PHP.
 */
function emailValido(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Realizo una redirección HTTP forzosa y finalizo la ejecución del script actual.
 */
function redirect(string $url): void {
    header("Location: " . $url);
    exit();
}


/**
 * CÁLCULOS NUTRICIONALES Y LÓGICA DE SALUD
 * Funciones encargadas de procesar los datos antropométricos del usuario.
 */

/**
 * Obtengo la edad exacta calculando la diferencia entre la fecha de nacimiento y la fecha actual.
 */
function calcularEdad(string $fechaNacimiento): int {
    try {
        $fechaNacimientoObj = new DateTime($fechaNacimiento);
        $hoy = new DateTime();
        $diferencia = $hoy->diff($fechaNacimientoObj);
        return $diferencia->y;
    } catch (Exception $e) {
        // Registro fallos en el formato de fecha para depuración
        error_log("Error al calcular la edad: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calculo el Gasto Energético Diario Total (TDEE).
 * Primero obtengo la Tasa Metabólica Basal (TMB) con la ecuación de Mifflin-St Jeor 
 * y luego aplico el factor de actividad física seleccionado.
 */
function calcularTDEE(string $genero, float $pesoKg, int $estaturaCm, int $edad, float $nivelActividad): int {
    // Calculo la base metabólica diferenciando por género
    $tmb = (10 * $pesoKg) + (6.25 * $estaturaCm) - (5 * $edad);

    if ($genero === "M") {
        $tmb += 5; // Ajuste para hombres
    } else {
        $tmb -= 161; // Ajuste para mujeres
    }

    // Multiplico por el factor de actividad para obtener las calorías de mantenimiento
    $tdee = $tmb * $nivelActividad;

    return (int) round($tdee);
}


/**
 * Determino los objetivos de macronutrientes en gramos.
 * Ajusto las calorías totales según el objetivo (déficit o superávit) y reparto 
 * los porcentajes de Proteínas, Carbohidratos y Grasas.
 */
function calcularMacrosObjetivo(int $tdeeCalorias, string $objetivo): array {
    // Valores energéticos estándar por gramo de macronutriente
    $KCAL_POR_PROTEINA = 4;
    $KCAL_POR_CARBOHIDRATO = 4;
    $KCAL_POR_GRASA = 9;

    $caloriasNetas = $tdeeCalorias;
    
    /**
     * AJUSTE CALÓRICO POR OBJETIVO
     * Aplico superávit para ganancia muscular o déficit para pérdida de peso.
     */
    switch ($objetivo) {
        case 'perdida_rapida':
            $caloriasNetas -= 750;
            break;
        case 'perdida_moderada':
            $caloriasNetas -= 500;
            break;
        case 'perdida_grasa':
            $caloriasNetas -= 350;
            break;
        case 'ganancia_muscular':
            $caloriasNetas += 300;
            break;
        case 'recomposicion_corporal':
            $caloriasNetas = $tdeeCalorias;
            break;
        case 'rendimiento_deportivo':
            $caloriasNetas += 500;
            break;
        case 'mantenimiento':
        default:
            $caloriasNetas = $tdeeCalorias;
            break;
    }
    
    // Establezco un suelo calórico de seguridad para evitar dietas extremas
    $caloriasNetas = max(1200, $caloriasNetas);
    
    /**
     * REPARTO DE MACRONUTRIENTES
     * Distribuyo las calorías totales en porcentajes optimizados para cada meta.
     */
    $macroPorcentajes = [
        'proteinas' => 0.30, 
        'carbohidratos' => 0.45, 
        'grasas' => 0.25 
    ];

    switch ($objetivo) {
        case 'perdida_rapida':
        case 'perdida_grasa':
        case 'recomposicion_corporal':
            // Priorizo la proteína para proteger la masa muscular en déficit
            $macroPorcentajes = ['proteinas' => 0.35, 'carbohidratos' => 0.40, 'grasas' => 0.25];
            break;
        case 'ganancia_muscular':
            // Priorizo carbohidratos para favorecer el entrenamiento intenso
            $macroPorcentajes = ['proteinas' => 0.30, 'carbohidratos' => 0.50, 'grasas' => 0.20];
            break;
        case 'rendimiento_deportivo':
            $macroPorcentajes = ['proteinas' => 0.25, 'carbohidratos' => 0.55, 'grasas' => 0.20];
            break;
    }

    // Calculo el aporte calórico de cada grupo y convierto a gramos
    $caloriasProteinas = $caloriasNetas * $macroPorcentajes['proteinas'];
    $caloriasCarbohidratos = $caloriasNetas * $macroPorcentajes['carbohidratos'];
    $caloriasGrasas = $caloriasNetas - $caloriasProteinas - $caloriasCarbohidratos;
    
    $proteinasGramos = $caloriasProteinas / $KCAL_POR_PROTEINA;
    $carbohidratosGramos = $caloriasCarbohidratos / $KCAL_POR_CARBOHIDRATO;
    $grasasGramos = $caloriasGrasas / $KCAL_POR_GRASA;

    return [
        'proteinas' => (int) round($proteinasGramos),
        'carbohidratos' => (int) round($carbohidratosGramos),
        'grasas' => (int) round($grasasGramos),
        'calorias_netas' => (int) round($caloriasNetas)
    ];
}

/**
 * Formateo fechas para una visualización más amigable en la interfaz (ej: 14 Nov 2025).
 */
function formatearFecha($date) {
    if (!$date) return '';
    $timestamp = strtotime($date);
    if (!$timestamp) return $date;
    return date('d M Y', $timestamp);
}

/**
 * Extraigo el nombre de pila de una cadena de nombre completo para personalizar el saludo.
 */
function getPrimerNombre($fullName) {
    if (empty($fullName)) return 'KaloAI';
    $parts = explode(' ', $fullName);
    return htmlspecialchars($parts[0]);
}

/**
 * Defino los factores de conversión para las distintas unidades de medida del sistema.
 */
function getDetallesConversion() {
    return [
        'g' => ['group' => 'peso', 'base_unit' => 'g', 'conversion_factor' => 1],
        'kg' => ['group' => 'peso', 'base_unit' => 'g', 'conversion_factor' => 1000],
        'ml' => ['group' => 'volumen', 'base_unit' => 'ml', 'conversion_factor' => 1],
        'L' => ['group' => 'volumen', 'base_unit' => 'ml', 'conversion_factor' => 1000],
        'unidad' => ['group' => 'conteo', 'base_unit' => 'unidad', 'conversion_factor' => 1],
        'caja' => ['group' => 'conteo', 'base_unit' => 'caja', 'conversion_factor' => 1],
    ];
}

/**
 * Normalizo cadenas de texto eliminando tildes y espacios extra para comparaciones precisas.
 */
function normalizarString($str) {
    $str = mb_strtolower($str, 'UTF-8');
    $str = str_replace(
        ['á', 'é', 'í', 'ó', 'ú', 'Á', 'É', 'Í', 'Ó', 'Ú', 'ñ', 'Ñ'],
        ['a', 'e', 'i', 'o', 'u', 'A', 'E', 'I', 'O', 'U', 'n', 'N'],
        $str
    );
    $str = trim(preg_replace('/\s+/', ' ', $str));
    return $str;
}

/**
 * Redirijo al usuario a su panel correspondiente según el nivel de acceso asignado.
 */
function redirigirPorRol($role_id) {
    switch ($role_id) {
        case 1: // Administrador
            header("Location: ../admin/dashboard_admin.php");
            break;
        case 2: // Nutricionista
            header("Location: ../nutri/dashboard_nutri.php");
            break;
        case 3: // Usuario final
            header("Location: ../dashboard.php");
            break;
        default:
            header("Location: ../auth/login.php");
            break;
    }
    exit;
}


/**
 * CONFIGURACIÓN DINÁMICA DE LA URL BASE (Local + Hosting)
 * En el hosting, la URL pública. En local (php -S localhost:8000 o XAMPP),
 * la del propio servidor, sumando la carpeta si el proyecto no está en la raíz.
 */
$kaloHost = $_SERVER['HTTP_HOST'] ?? '';
if (preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $kaloHost)) {
    // Carpeta del proyecto respecto a la raíz del servidor ('' si está en la raíz)
    $kaloRaiz = str_replace(DIRECTORY_SEPARATOR, '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
    $kaloProyecto = str_replace(DIRECTORY_SEPARATOR, '/', dirname(__DIR__));
    $kaloCarpeta = trim(substr($kaloProyecto, strlen($kaloRaiz)), '/');
    define('BASE_URL', 'http://' . $kaloHost . '/' . ($kaloCarpeta !== '' ? $kaloCarpeta . '/' : ''));
} elseif (!defined('BASE_URL')) {
    // APP_URL viene de configuracion.php; las configuraciones antiguas no la tienen
    define('BASE_URL', defined('APP_URL') ? APP_URL : 'https://kaloai.infinityfreeapp.com/');
}

/**
 * Factores de actividad física para el TDEE (Mifflin-St Jeor x actividad).
 */
const FACTORES_ACTIVIDAD = [
    'sedentario' => 1.2,
    'ligero'     => 1.375,
    'moderado'   => 1.55,
    'activo'     => 1.725,
    'muy_activo' => 1.9,
];

/**
 * Pone el peso actual en el perfil y, si el perfil está completo, recalcula el
 * TDEE y los macros objetivo con ese peso. Se usa al registrar o borrar una
 * medición en "Mi progreso", para que las metas no se queden con el peso antiguo.
 */
function actualizarPesoYObjetivos(PDO $pdo, int $userId, float $pesoKg): void {
    $stmt = $pdo->prepare("SELECT fecha_nacimiento, genero, altura_cm, nivel_actividad, objetivo FROM perfil WHERE id_usuario = ?");
    $stmt->execute([$userId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) return;

    $completo = !empty($p['fecha_nacimiento']) && in_array($p['genero'], ['M', 'F'], true)
        && !empty($p['altura_cm']) && isset(FACTORES_ACTIVIDAD[$p['nivel_actividad'] ?? '']);

    if (!$completo) {
        $pdo->prepare("UPDATE perfil SET peso_kg = ? WHERE id_usuario = ?")->execute([$pesoKg, $userId]);
        return;
    }

    $tdee = calcularTDEE($p['genero'], $pesoKg, (int) $p['altura_cm'], calcularEdad($p['fecha_nacimiento']), FACTORES_ACTIVIDAD[$p['nivel_actividad']]);
    $macros = calcularMacrosObjetivo($tdee, $p['objetivo'] ?? 'mantenimiento');
    $pdo->prepare("UPDATE perfil SET peso_kg = ?, tdee_calorias = ?, proteinas_objetivo_g = ?, carbohidratos_objetivo_g = ?,
                       grasas_objetivo_g = ?, calorias_netas_objetivo = ? WHERE id_usuario = ?")
        ->execute([$pesoKg, $tdee, $macros['proteinas'], $macros['carbohidratos'], $macros['grasas'], $macros['calorias_netas'], $userId]);
}

/**
 * Resumen semanal por correo: desde el lunes por la mañana, cada visita envía
 * unos pocos pendientes cuando ya ha respondido (core/resumen_semanal.php).
 * El resto de la semana solo lee un archivo pequeño.
 */
require_once __DIR__ . '/resumen_semanal.php';
comprobarResumenSemanal();
