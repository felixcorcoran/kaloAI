<?php

/**
 * Arranque de los tests.
 *
 * Los tests unitarios no tocan la base de datos. Los de integración se
 * ejecutan solo si existe la variable KALOAI_TEST_DB_NAME y crean desde cero
 * las tablas de esa base de datos: por seguridad, su nombre tiene que terminar
 * en "_test" para no borrar nunca una base de datos real por error.
 *
 *   KALOAI_TEST_DB_NAME=kaloai_test DB_USER=root DB_PASS=secreto vendor/bin/phpunit
 */

require_once __DIR__ . '/../vendor/autoload.php';

// configuracion.php no se sube al repositorio: si no existe, vale la plantilla
$configuracion = __DIR__ . '/../core/configuracion.php';
if (!file_exists($configuracion)) {
    copy(__DIR__ . '/../core/configuracion-example.php', $configuracion);
}

require_once __DIR__ . '/../core/configuracion.php';
require_once __DIR__ . '/../core/utilidades.php';
require_once __DIR__ . '/../core/recetas.php';
require_once __DIR__ . '/../core/generar_recetas_ia.php';
