<?php

use PHPUnit\Framework\TestCase;

/**
 * Raciones, plantillas de semana, respuestas a las notas, lista compartida y
 * resumen semanal, contra MariaDB/MySQL con el esquema real y los datos de
 * ejemplo (mismo arranque que BaseDatosTest).
 */
final class MejorasBaseDatosTest extends TestCase
{
    private static ?PDO $pdo = null;
    private const LAURA = 3;
    private const MARCOS = 4;
    private const NURIA = 2;

    public static function setUpBeforeClass(): void
    {
        $nombre = getenv('KALOAI_TEST_DB_NAME') ?: '';
        if ($nombre === '') return;
        if (!str_ends_with($nombre, '_test')) {
            throw new RuntimeException("KALOAI_TEST_DB_NAME debe terminar en _test (se borran sus tablas)");
        }
        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306');
        self::$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$pdo->exec("CREATE DATABASE IF NOT EXISTS `$nombre` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        self::$pdo->exec("USE `$nombre`");
        self::$pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }

    protected function setUp(): void
    {
        if (!self::$pdo) {
            $this->markTestSkipped('Define KALOAI_TEST_DB_NAME (terminado en _test) para las pruebas con base de datos.');
        }
        foreach (['schema.sql', 'datos_demo.sql'] as $archivo) {
            self::$pdo->exec(file_get_contents(__DIR__ . '/../../database/' . $archivo));
        }
    }

    private function valor(string $sql, array $params = []): mixed
    {
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public function testCambiaLasRacionesSoloDeSuPlan(): void
    {
        $id = (int) $this->valor("SELECT id_comida_planificada FROM comida_planificada WHERE id_plan = 1 AND dia_semana = 0 AND momento_comida = 'Cena'");
        $this->assertTrue(cambiarRaciones(self::$pdo, self::LAURA, $id, '1,5'));
        $this->assertSame('1.50', $this->valor("SELECT raciones FROM comida_planificada WHERE id_comida_planificada = ?", [$id]));
        $this->assertTrue(cambiarRaciones(self::$pdo, self::LAURA, $id, '1.5'), 'Repetir el mismo valor también vale');
        $this->assertFalse(cambiarRaciones(self::$pdo, self::MARCOS, $id, 2), 'No es su plan');
        $this->assertFalse(cambiarRaciones(self::$pdo, self::LAURA, $id, 7), 'Raciones no permitidas');
        $this->assertSame('1.50', $this->valor("SELECT raciones FROM comida_planificada WHERE id_comida_planificada = ?", [$id]));

        // La lista de la compra lo multiplica
        $antes = listaCompraDeSemana(self::$pdo, self::LAURA, lunesDe());
        cambiarRaciones(self::$pdo, self::LAURA, $id, 3);
        $despues = listaCompraDeSemana(self::$pdo, self::LAURA, lunesDe());
        $total = fn($l) => array_sum(array_map(fn($f) => (float) $f['cantidad'] * (float) $f['raciones'], $l['necesarios']));
        $this->assertGreaterThan($total($antes), $total($despues), 'Más raciones, más ingredientes');
    }

    public function testGuardaYAplicaUnaPlantilla(): void
    {
        $semana = lunesDe();
        $siguiente = semanaRelativa($semana, 1);
        $this->assertIsString(guardarPlantilla(self::$pdo, self::LAURA, $siguiente, 'Vacía'), 'Una semana sin comidas no se guarda');
        $this->assertIsString(guardarPlantilla(self::$pdo, self::LAURA, $semana, '   '), 'Sin nombre');

        $id = guardarPlantilla(self::$pdo, self::LAURA, $semana, 'Semana de entreno');
        $this->assertIsInt($id);
        $this->assertSame(19, (int) $this->valor("SELECT COUNT(*) FROM plantilla_comida WHERE id_plantilla = ?", [$id]));
        $this->assertSame($id, guardarPlantilla(self::$pdo, self::LAURA, semanaRelativa($semana, -1), 'Semana de entreno'), 'Mismo nombre: la sustituye');
        $this->assertSame(15, (int) $this->valor("SELECT COUNT(*) FROM plantilla_comida WHERE id_plantilla = ?", [$id]));
        $this->assertCount(1, plantillasDe(self::$pdo, self::LAURA));
        $this->assertSame([], plantillasDe(self::$pdo, self::MARCOS));

        // Aplicar: en una semana vacía pone todo; otra vez, nada (huecos ocupados)
        $this->assertNull(aplicarPlantilla(self::$pdo, self::MARCOS, $id, $siguiente), 'No es suya');
        $this->assertSame(15, aplicarPlantilla(self::$pdo, self::LAURA, $id, $siguiente));
        $this->assertSame(0, aplicarPlantilla(self::$pdo, self::LAURA, $id, $siguiente));
        // Sustituir: vacía la semana antes
        cambiarRaciones(self::$pdo, self::LAURA, (int) $this->valor("SELECT cp.id_comida_planificada FROM comida_planificada cp JOIN plan_semanal ps ON ps.id_plan = cp.id_plan WHERE ps.fecha_inicio_semana = ? LIMIT 1", [$siguiente]), 2);
        $this->assertSame(15, aplicarPlantilla(self::$pdo, self::LAURA, $id, $siguiente, true));
        $this->assertSame(0, (int) $this->valor("SELECT COUNT(*) FROM comida_planificada cp JOIN plan_semanal ps ON ps.id_plan = cp.id_plan WHERE ps.fecha_inicio_semana = ? AND cp.raciones <> 1", [$siguiente]));

        // Si se borra una receta, sale de la plantilla; borrar la plantilla no toca las semanas
        self::$pdo->exec("DELETE FROM comida_planificada WHERE id_receta = 3");
        self::$pdo->exec("DELETE FROM detalle_receta WHERE id_receta = 3");
        self::$pdo->exec("DELETE FROM receta WHERE id_receta = 3");
        $this->assertSame(10, (int) $this->valor("SELECT COUNT(*) FROM plantilla_comida WHERE id_plantilla = ?", [$id]));
        $this->assertFalse(borrarPlantilla(self::$pdo, self::MARCOS, $id));
        $this->assertTrue(borrarPlantilla(self::$pdo, self::LAURA, $id));
        $this->assertSame(10, (int) $this->valor("SELECT COUNT(*) FROM comida_planificada cp JOIN plan_semanal ps ON ps.id_plan = cp.id_plan WHERE ps.fecha_inicio_semana = ?", [$siguiente]));
    }

    public function testElPacienteRespondeASuNutricionista(): void
    {
        self::$pdo->exec("DELETE FROM nota_paciente");
        $this->assertNull(crearNota(self::$pdo, self::NURIA, self::LAURA, 'Prueba a cenar antes.'));
        $this->assertSame(1, notasSinLeerDelPaciente(self::$pdo, self::LAURA));

        $this->assertNotNull(responderNota(self::$pdo, self::LAURA, '  '), 'Vacía');
        $this->assertNotNull(responderNota(self::$pdo, self::MARCOS, 'Hola'), 'Marcos no tiene nutricionista');
        $this->assertNull(responderNota(self::$pdo, self::LAURA, "¡Vale!\r\nLo intento."));
        $this->assertNull(responderNota(self::$pdo, self::LAURA, "¡Vale!\nLo intento."), 'Doble clic: no se duplica');
        $this->assertSame(1, (int) $this->valor("SELECT COUNT(*) FROM nota_paciente WHERE de_paciente = 1"));

        // La respuesta no cuenta como nota sin leer para el paciente, sí para la nutricionista
        $this->assertSame(1, notasSinLeerDelPaciente(self::$pdo, self::LAURA));
        $this->assertSame([self::LAURA => 1], respuestasSinLeer(self::$pdo, self::NURIA));
        marcarNotasLeidas(self::$pdo, self::LAURA);
        $this->assertSame(0, notasSinLeerDelPaciente(self::$pdo, self::LAURA));
        $this->assertSame([self::LAURA => 1], respuestasSinLeer(self::$pdo, self::NURIA), 'Leer las notas no marca las respuestas');
        marcarRespuestasLeidas(self::$pdo, self::NURIA, self::LAURA);
        $this->assertSame([], respuestasSinLeer(self::$pdo, self::NURIA));

        $conversacion = notasDelPaciente(self::$pdo, self::LAURA);
        $this->assertSame("¡Vale!\nLo intento.", $conversacion[0]['texto']);
        $this->assertSame(1, (int) $conversacion[0]['de_paciente']);
        $this->assertSame('Laura Martín', $conversacion[0]['autor']);
        $this->assertSame('Nuria Nutri', $conversacion[1]['autor']);

        // La nutricionista no borra respuestas; el paciente solo las suyas
        $idRespuesta = (int) $conversacion[0]['id_nota'];
        $this->assertFalse(borrarNota(self::$pdo, self::NURIA, $idRespuesta));
        $this->assertFalse(borrarRespuesta(self::$pdo, self::LAURA, (int) $conversacion[1]['id_nota']), 'No puede borrar la nota de la nutricionista');
        $this->assertFalse(borrarRespuesta(self::$pdo, self::MARCOS, $idRespuesta));
        $this->assertTrue(borrarRespuesta(self::$pdo, self::LAURA, $idRespuesta));

        $exportado = exportarDatosCuenta(self::$pdo, self::LAURA);
        $this->assertCount(1, $exportado['mensajes_con_el_nutricionista']);
    }

    public function testListaCompartidaDeSoloLectura(): void
    {
        $this->assertNull(tokenListaCompartida(self::$pdo, self::LAURA));
        $token = crearListaCompartida(self::$pdo, self::LAURA);
        $this->assertTrue(tokenListaValido($token));
        $this->assertSame($token, tokenListaCompartida(self::$pdo, self::LAURA));
        $this->assertSame(['id_usuario' => self::LAURA, 'nombre' => 'Laura'], duenoListaCompartida(self::$pdo, $token));
        $this->assertNotNull($this->valor("SELECT ultima_visita FROM lista_compartida WHERE id_usuario = 3"));

        $nuevo = crearListaCompartida(self::$pdo, self::LAURA);
        $this->assertNotSame($token, $nuevo);
        $this->assertNull(duenoListaCompartida(self::$pdo, $token), 'El enlace anterior deja de funcionar');
        $this->assertNull(duenoListaCompartida(self::$pdo, "' OR '1'='1"));

        self::$pdo->exec("UPDATE usuario SET activo = 0 WHERE id_usuario = 3");
        $this->assertNull(duenoListaCompartida(self::$pdo, $nuevo), 'Cuenta desactivada');
        self::$pdo->exec("UPDATE usuario SET activo = 1 WHERE id_usuario = 3");

        dejarDeCompartirLista(self::$pdo, self::LAURA);
        $this->assertNull(duenoListaCompartida(self::$pdo, $nuevo));
    }

    public function testResumenSemanalSeEnviaUnaVezYSePuedeDarDeBaja(): void
    {
        $semanaPasada = semanaRelativa(lunesDe(), -1);
        $enviados = [];
        $transporte = function (string $url, array $cabeceras, string $cuerpo) use (&$enviados) {
            $enviados[] = json_decode($cuerpo, true);
            return [200, '{"id":"x"}'];
        };

        // Nadie lo tiene activado
        $this->assertSame(0, enviarResumenesPendientes(self::$pdo, 'https://kalo.test/', $semanaPasada, 10, $transporte)['enviados']);

        cambiarResumenSemanal(self::$pdo, self::LAURA, true);
        $token = $this->valor("SELECT token_baja FROM perfil WHERE id_usuario = 3");
        $this->assertTrue(tokenListaValido($token));
        $this->assertSame(semanaRelativa(lunesDe(), -1), $this->valor("SELECT ultimo_resumen FROM perfil WHERE id_usuario = 3"),
            'Al activarlo, la semana pasada cuenta como resumida: el primero llega el próximo lunes');

        // El lunes siguiente: se envía la semana que acaba de terminar
        $esta = lunesDe();
        $r = enviarResumenesPendientes(self::$pdo, 'https://kalo.test/', $esta, 10, $transporte);
        $this->assertSame(['enviados' => 1, 'fallidos' => 0, 'pendientes' => 0], $r);
        $this->assertSame(['laura@kaloai.test'], $enviados[0]['to']);
        $this->assertStringContainsString('baja_resumen.php?t=' . $token, $enviados[0]['html']);
        $this->assertSame(0, enviarResumenesPendientes(self::$pdo, 'https://kalo.test/', $esta, 10, $transporte)['enviados'], 'No se repite');

        $d = datosResumenSemanal(self::$pdo, self::LAURA, $semanaPasada);
        $this->assertSame(64.9, $d['peso'], 'Peso de hace 7 días, dentro de la semana pasada');
        $this->assertSame(19, $d['comidas_siguiente']);

        $this->assertFalse(darDeBajaResumen(self::$pdo, 'no-es-un-token'));
        $this->assertTrue(darDeBajaResumen(self::$pdo, $token));
        $this->assertSame(0, (int) $this->valor("SELECT resumen_semanal FROM perfil WHERE id_usuario = 3"));
    }

    public function testUnEnvioFallidoNoSeReintentaEnCadaVisita(): void
    {
        cambiarResumenSemanal(self::$pdo, self::LAURA, true);
        self::$pdo->exec("UPDATE perfil SET ultimo_resumen = NULL WHERE id_usuario = 3");
        $falla = fn() => [500, 'error'];
        $this->assertSame(['enviados' => 0, 'fallidos' => 1, 'pendientes' => 0],
            enviarResumenesPendientes(self::$pdo, 'https://kalo.test/', lunesDe(), 10, $falla));
    }
}
