<?php

use PHPUnit\Framework\TestCase;

/**
 * Pruebas contra MariaDB/MySQL con el esquema real (database/schema.sql) y los
 * datos de ejemplo. Cada test parte de una base de datos recién cargada.
 * Solo se ejecutan con KALOAI_TEST_DB_NAME=<algo>_test (ver tests/bootstrap.php).
 */
final class BaseDatosTest extends TestCase
{
    private static ?PDO $pdo = null;
    private const LAURA = 3;
    private const MARCOS = 4;

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
        // Modo estricto, como MySQL 8 y MariaDB por defecto: así se ven los INSERT incompletos
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

    public function testElRegistroPuedeCrearUnPerfilSoloConElNombre(): void
    {
        // Es lo que hace views/auth/registro.php; con el esquema antiguo fallaba en modo estricto
        self::$pdo->prepare("INSERT INTO usuario (correo, contrasena_hash, id_rol) VALUES (?, ?, 3)")->execute(['nueva@kaloai.test', 'x']);
        self::$pdo->prepare("INSERT INTO perfil (id_usuario, nombre) VALUES (?, ?)")->execute([self::$pdo->lastInsertId(), 'Nueva']);
        $this->assertSame('Nueva', $this->valor("SELECT nombre FROM perfil WHERE nombre = 'Nueva'"));
    }

    public function testCreaElPlanSemanalCuandoNoExiste(): void
    {
        $idPlan = obtenerOCrearPlan(self::$pdo, self::MARCOS);
        $this->assertSame($idPlan, obtenerOCrearPlan(self::$pdo, self::MARCOS), 'No crea un segundo plan');
        $this->assertSame((new DateTimeImmutable('monday this week'))->format('Y-m-d'),
            $this->valor("SELECT fecha_inicio_semana FROM plan_semanal WHERE id_plan = ?", [$idPlan]));
    }

    public function testNoSePuedePlanificarUnaRecetaAjena(): void
    {
        $this->assertFalse(asignarRecetaAlPlan(self::$pdo, self::MARCOS, 1, 0, 'Cena'));
        $this->assertTrue(asignarRecetaAlPlan(self::$pdo, self::LAURA, 3, 0, 'Desayuno'));
        $this->assertFalse(asignarRecetaAlPlan(self::$pdo, self::LAURA, 3, 0, 'Recena'), 'Momento fuera del ENUM');
        $this->assertSame(3, (int) $this->valor("SELECT id_receta FROM comida_planificada WHERE dia_semana = 0 AND momento_comida = 'Desayuno'"));
    }

    public function testGuardarRecetaDeLaIAConIngredientesRepetidos(): void
    {
        self::$pdo->beginTransaction();
        $id = guardarRecetaIA(self::$pdo, self::LAURA, [
            'titulo' => 'Prueba', 'calorias' => 500,
            'macros' => ['proteinas' => 30, 'carbohidratos' => 50, 'grasas' => 15],
            'ingredientes_usados' => [
                ['nombre' => 'Aceite De Oliva', 'cantidad' => 5, 'unidad' => 'ml'],
                ['nombre' => 'Aceite De Oliva', 'cantidad' => 5, 'unidad' => 'ml'],
            ],
            'instrucciones' => ['Paso uno', 'Paso dos'],
        ], 'Merienda');
        self::$pdo->commit();

        $this->assertSame('Snack', $this->valor("SELECT tipo_comida FROM receta WHERE id_receta = ?", [$id]));
        $this->assertSame("Paso uno\nPaso dos", $this->valor("SELECT descripcion FROM receta WHERE id_receta = ?", [$id]));
        $this->assertEquals(10, $this->valor("SELECT cantidad FROM detalle_receta WHERE id_receta = ?", [$id]));
    }

    public function testLaIANuncaDevuelveUnaRecetaConAlergenos(): void
    {
        $llamadas = [];
        $iaFalsa = function (string $prompt) use (&$llamadas) {
            $llamadas[] = $prompt;
            $ingrediente = count($llamadas) === 1 ? 'Salsa de cacahuetes' : 'Salsa de soja';
            return json_encode(['titulo' => 'Wok', 'calorias' => 500, 'macros' => [],
                'ingredientes_usados' => [['nombre' => $ingrediente, 'cantidad' => 20, 'unidad' => 'g']],
                'instrucciones' => ['Saltea.']]);
        };

        $receta = generarRecetaInteligente(self::LAURA, false, 'Martes', 'Cena', '', $iaFalsa, self::$pdo);

        $this->assertCount(2, $llamadas, 'Pide una segunda receta');
        $this->assertStringContainsString('- Cacahuete (Alergia (Riesgo Alto))', $llamadas[0]);
        $this->assertStringContainsString('incluía Cacahuete', $llamadas[1]);
        $this->assertSame('Salsa De Soja', $receta['ingredientes_usados'][0]['nombre']);
    }

    public function testSiLaIAInsisteEnElAlergenoDevuelveUnError(): void
    {
        $iaTerca = fn() => json_encode(['titulo' => 'Satay', 'ingredientes_usados' => [['nombre' => 'Cacahuete', 'cantidad' => 30, 'unidad' => 'g']]]);
        $resultado = generarRecetaInteligente(self::LAURA, false, null, 'Cena', '', $iaTerca, self::$pdo);
        $this->assertArrayHasKey('error', $resultado);
        $this->assertStringContainsString('Cacahuete', $resultado['error']);
    }

    public function testLaRacionTieneEnCuentaLoYaPlanificadoEseDia(): void
    {
        $prompts = [];
        $ia = function (string $p) use (&$prompts) { $prompts[] = $p; return json_encode(['titulo' => 'x', 'ingredientes_usados' => []]); };
        // Un día con 1900 kcal ya planificadas: para la cena quedan menos que su 25 % habitual
        $meta = (int) $this->valor("SELECT tdee_calorias FROM perfil WHERE id_usuario = 3");
        self::$pdo->exec("UPDATE receta SET calorias_por_racion = 900 WHERE id_receta = 2");
        $planificadas = (int) $this->valor("SELECT SUM(r.calorias_por_racion) FROM comida_planificada cp JOIN receta r USING (id_receta) WHERE cp.dia_semana = 0");
        generarRecetaInteligente(self::LAURA, false, 'Lunes', 'Cena', '', $ia, self::$pdo);
        $this->assertLessThan(round($meta * 0.25), $meta - $planificadas, 'El escenario de la prueba es el correcto');
        $this->assertStringContainsString('Calorías: ' . ($meta - $planificadas) . ' kcal', $prompts[0]);
    }

    public function testComprarSumaEnLaUnidadQueYaHabia(): void
    {
        sumarAlInventario(self::$pdo, self::LAURA, 'Arroz integral', 500, 'g');
        $this->assertEquals(1.5, $this->valor("SELECT cantidad FROM inventario WHERE id_usuario = 3 AND nombre_ingrediente = 'Arroz Integral'"));

        sumarAlInventario(self::$pdo, self::LAURA, 'Calabacín', 240, 'g');
        $this->assertEquals(240, $this->valor("SELECT cantidad FROM inventario WHERE id_usuario = 3 AND nombre_ingrediente = 'Calabacín'"));
    }

    public function testUnPesoNuevoRecalculaLosObjetivos(): void
    {
        actualizarPesoYObjetivos(self::$pdo, self::LAURA, 60.0);

        // Laura: mujer, 168 cm, actividad moderada (x1,55), objetivo pérdida de grasa
        $tdee = calcularTDEE('F', 60.0, 168, calcularEdad('1995-04-12'), 1.55);
        $macros = calcularMacrosObjetivo($tdee, 'perdida_grasa');
        $perfil = self::$pdo->query("SELECT * FROM perfil WHERE id_usuario = 3")->fetch();
        $this->assertSame($tdee, (int) $perfil['tdee_calorias']);
        $this->assertSame($macros['proteinas'], (int) $perfil['proteinas_objetivo_g']);
        $this->assertSame($macros['calorias_netas'], (int) $perfil['calorias_netas_objetivo']);

        // Un perfil a medias solo guarda el peso
        actualizarPesoYObjetivos(self::$pdo, self::MARCOS, 80.0);
        $this->assertNull($this->valor("SELECT tdee_calorias FROM perfil WHERE id_usuario = 4"));
        $this->assertEquals(80, $this->valor("SELECT peso_kg FROM perfil WHERE id_usuario = 4"));
    }

    public function testLaMigracionDeTextoDeshaceElEscapadoAntiguo(): void
    {
        self::$pdo->exec("UPDATE perfil SET nombre = 'Lucía O&#039;Brien &amp; Co &lt;3' WHERE id_usuario = 4");
        self::$pdo->exec(file_get_contents(__DIR__ . '/../../database/migraciones/002_texto_sin_escapar.sql'));
        $this->assertSame("Lucía O'Brien & Co <3", $this->valor("SELECT nombre FROM perfil WHERE id_usuario = 4"));
    }

    public function testLasMigracionesSeAplicanUnaSolaVez(): void
    {
        // La base de datos de schema.sql ya las tiene apuntadas: no hay nada que hacer
        $this->assertSame([], aplicarMigracionesPendientes(self::$pdo));

        // Sin el registro (como en producción antes del primer despliegue) se
        // aplican todas, sobre un esquema que ya tiene sus cambios, sin romper nada
        self::$pdo->exec("DROP TABLE migraciones");
        $this->assertSame(migracionesDisponibles(), aplicarMigracionesPendientes(self::$pdo));
        $this->assertSame([], aplicarMigracionesPendientes(self::$pdo));
        $this->assertSame(3, (int) $this->valor("SELECT COUNT(*) FROM rol"));
    }
}
