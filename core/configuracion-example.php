<?php

/**
 * CONFIGURACIÓN DE KALOAI
 * Copia este archivo como core/configuracion.php (no se sube al repositorio).
 *
 * Cada valor se lee primero de una variable de entorno (así funciona sin tocar
 * nada en Docker y en la integración continua) y, si no existe, usa el valor
 * escrito aquí. En un hosting sin variables de entorno, como InfinityFree,
 * escribe tus datos directamente en el segundo argumento de kaloEnv().
 */
function kaloEnv(string $nombre, string $porDefecto = ''): string {
    $valor = getenv($nombre);
    return ($valor === false || $valor === '') ? $porDefecto : $valor;
}

/**
 * CONEXIÓN A MySQL / MariaDB
 */
define('DB_HOST', kaloEnv('DB_HOST', 'localhost'));
define('DB_PORT', kaloEnv('DB_PORT', '3306'));
define('DB_NAME', kaloEnv('DB_NAME', 'kaloai_db'));
define('DB_USER', kaloEnv('DB_USER', 'root'));
define('DB_PASS', kaloEnv('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

/**
 * API DE GOOGLE GEMINI
 * Una clave basta. Si pones varias separadas por comas, se usa la siguiente
 * solo cuando una falla (por ejemplo, si se revoca o caduca).
 */
define('GEMINI_API_KEYS', array_values(array_filter(array_map('trim', explode(',', kaloEnv('GEMINI_API_KEY', ''))))));
define('GEMINI_API_URL', kaloEnv('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent'));

/**
 * URL PÚBLICA DE LA WEB (con barra final)
 * En localhost se calcula sola; en el hosting se usa esta.
 */
define('APP_URL', kaloEnv('APP_URL', 'https://kaloai.infinityfreeapp.com/'));

/**
 * Abre la conexión con PDO: errores como excepciones, arrays asociativos
 * y sentencias preparadas reales del motor (sin emulación).
 */
function connectDB(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        return $pdo;
    } catch (\PDOException $e) {
        error_log("Error de conexión a la BD: " . $e->getMessage());
        http_response_code(503);
        die("No se pudo conectar a la base de datos. Inténtalo de nuevo en unos minutos.");
    }
}
