<?php

/**
 * MIGRACIONES AUTOMÁTICAS - KaloAI
 *
 * Cada archivo de database/migraciones/ se aplica una sola vez, en orden de
 * nombre, y queda apuntado en la tabla `migraciones`. No hace falta entrar en
 * phpMyAdmin: tras un despliegue, la primera visita a la web aplica las que
 * falten (el hosting no permite conectarse a MySQL desde fuera).
 *
 * Para que las visitas normales no consulten la base de datos, tras aplicarlas
 * se guarda la lista en core/.migraciones_aplicadas. Mientras ese archivo
 * coincida con las migraciones presentes, no se hace nada más.
 *
 * Reglas para escribir una migración nueva:
 * - Nombre con número delante: 003_lo_que_hace.sql (se aplican en ese orden).
 * - Una sentencia por cada ";" de final de línea; sin ";" dentro de textos.
 * - Mejor si se puede repetir sin romper nada (por si falla a medias).
 * - Si también cambia el esquema, actualiza database/schema.sql y apúntala
 *   como aplicada al final de ese archivo.
 */

const CARPETA_MIGRACIONES = __DIR__ . '/../database/migraciones';
const MARCA_MIGRACIONES = __DIR__ . '/.migraciones_aplicadas';

/** Nombres de los archivos de migración disponibles, en orden. */
function migracionesDisponibles(): array {
    $archivos = glob(CARPETA_MIGRACIONES . '/*.sql') ?: [];
    $nombres = array_map('basename', $archivos);
    sort($nombres, SORT_STRING);
    return $nombres;
}

/** Divide un archivo SQL en sentencias (quita comentarios "-- " de línea completa). */
function sentenciasSql(string $sql): array {
    $sinComentarios = preg_replace('/^\s*--.*$/m', '', $sql);
    $sentencias = preg_split('/;\s*(\R|$)/', $sinComentarios);
    return array_values(array_filter(array_map('trim', $sentencias), fn($s) => $s !== ''));
}

/**
 * Aplica las migraciones pendientes. Devuelve los nombres de las aplicadas.
 * Usa un candado de MySQL para que dos visitas simultáneas no las ejecuten a la vez.
 * Si una falla, lanza la excepción y no apunta esa ni las siguientes.
 */
function aplicarMigracionesPendientes(PDO $pdo, ?array $disponibles = null): array {
    $disponibles = $disponibles ?? migracionesDisponibles();

    $pdo->exec("CREATE TABLE IF NOT EXISTS migraciones (
        nombre VARCHAR(190) NOT NULL PRIMARY KEY,
        aplicada_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if ((int) $pdo->query("SELECT GET_LOCK('kaloai_migraciones', 30)")->fetchColumn() !== 1) {
        throw new RuntimeException("Otra petición está aplicando las migraciones.");
    }

    try {
        $hechas = $pdo->query("SELECT nombre FROM migraciones")->fetchAll(PDO::FETCH_COLUMN);
        $aplicadas = [];
        foreach (array_diff($disponibles, $hechas) as $nombre) {
            foreach (sentenciasSql(file_get_contents(CARPETA_MIGRACIONES . '/' . $nombre)) as $sentencia) {
                $pdo->exec($sentencia);
            }
            $pdo->prepare("INSERT INTO migraciones (nombre) VALUES (?)")->execute([$nombre]);
            error_log("KaloAI: migración aplicada: $nombre");
            $aplicadas[] = $nombre;
        }
        return $aplicadas;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('kaloai_migraciones')");
    }
}

/**
 * Comprobación que se hace en cada petición web: barata si todo está al día
 * (solo lee un archivo pequeño); si hay migraciones nuevas, las aplica.
 * Un fallo se registra en el log y no impide que la página se muestre.
 */
function comprobarMigraciones(): void {
    if (PHP_SAPI === 'cli' || !function_exists('connectDB')) {
        return;
    }
    $disponibles = migracionesDisponibles();
    $firma = implode("\n", $disponibles);
    if (@file_get_contents(MARCA_MIGRACIONES) === $firma) {
        return;
    }

    try {
        aplicarMigracionesPendientes(connectDB(), $disponibles);
        // Si la carpeta no admite escritura, se volverá a comprobar en la BD (sin repetir nada)
        @file_put_contents(MARCA_MIGRACIONES, $firma, LOCK_EX);
    } catch (Throwable $e) {
        error_log("KaloAI: error al aplicar migraciones: " . $e->getMessage());
    }
}
