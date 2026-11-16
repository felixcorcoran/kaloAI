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
        $this->assertSame(3, (int) $this->valor("SELECT id_receta FROM comida_planificada WHERE id_plan = ? AND dia_semana = 0 AND momento_comida = 'Desayuno'",
            [obtenerPlan(self::$pdo, self::LAURA)]));
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
        // Un día casi lleno: para la cena quedan menos calorías que su 25 % habitual (y más de 150)
        // La meta es la del objetivo (calorías netas), no el TDEE de mantenimiento
        $meta = (int) $this->valor("SELECT calorias_netas_objetivo FROM perfil WHERE id_usuario = 3");
        self::$pdo->exec("UPDATE receta SET calorias_por_racion = 800 WHERE id_receta = 2");
        $planificadas = (int) $this->valor("SELECT SUM(r.calorias_por_racion) FROM comida_planificada cp JOIN receta r USING (id_receta) WHERE cp.dia_semana = 0 AND cp.id_plan = ?",
            [obtenerPlan(self::$pdo, self::LAURA)]);
        generarRecetaInteligente(self::LAURA, false, 'Lunes', 'Cena', '', $ia, self::$pdo);
        $this->assertLessThan(round($meta * 0.25), $meta - $planificadas, "El escenario de la prueba es el correcto");
        $this->assertGreaterThan(150, $meta - $planificadas);
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

    public function testMarcarComoHechaUnaComidaDelPlan(): void
    {
        $hoy = new DateTimeImmutable('today');
        $diaHoy = (int) $hoy->format('N') - 1;
        asignarRecetaAlPlan(self::$pdo, self::LAURA, 2, $diaHoy, 'Almuerzo');
        $idComida = (int) $this->valor("SELECT cp.id_comida_planificada FROM comida_planificada cp JOIN plan_semanal ps USING (id_plan)
                                         WHERE ps.id_usuario = ? AND ps.fecha_inicio_semana = ? AND cp.dia_semana = ? AND cp.momento_comida = 'Almuerzo'",
                                         [self::LAURA, lunesDe($hoy->format('Y-m-d')), $diaHoy]);

        $id = registrarComidaPlanificada(self::$pdo, self::LAURA, $idComida, $hoy->format('Y-m-d'));
        $this->assertNotNull($id);
        $this->assertSame($id, registrarComidaPlanificada(self::$pdo, self::LAURA, $idComida, $hoy->format('Y-m-d')), 'Dos clics no duplican');
        $this->assertSame(620, (int) $this->valor("SELECT calorias FROM registro_comida WHERE id_registro = ?", [$id]));

        // Ni la comida de otra persona ni la de otro día de la semana
        $this->assertNull(registrarComidaPlanificada(self::$pdo, self::MARCOS, $idComida, $hoy->format('Y-m-d')));
        $this->assertNull(registrarComidaPlanificada(self::$pdo, self::LAURA, $idComida, $hoy->modify('+1 day')->format('Y-m-d')));

        // El historial se conserva aunque se borre la receta
        self::$pdo->exec("DELETE FROM comida_planificada WHERE id_receta = 2");
        self::$pdo->exec("DELETE FROM receta WHERE id_receta = 2");
        $this->assertSame(620, (int) $this->valor("SELECT calorias FROM registro_comida WHERE id_registro = ?", [$id]));
        $this->assertNull($this->valor("SELECT id_receta FROM registro_comida WHERE id_registro = ?", [$id]));
    }

    public function testComidaLibreYBorradoSoloDelDueno(): void
    {
        $id = registrarComidaLibre(self::$pdo, self::LAURA, date('Y-m-d'),
            validarComidaLibre(['descripcion' => 'Pizza', 'momento' => 'Cena', 'calorias' => 850, 'proteinas' => 32]));
        $this->assertFalse(borrarRegistroComida(self::$pdo, self::MARCOS, $id));
        $hoy = sumarRegistros(registrosDelDia(self::$pdo, self::LAURA, date('Y-m-d')));
        $this->assertSame(380.0 + 850.0, $hoy['kcal'], 'Desayuno de los datos de ejemplo + la pizza');
        $this->assertTrue(borrarRegistroComida(self::$pdo, self::LAURA, $id));
        $this->assertSame(380.0, caloriasConsumidasPorDia(self::$pdo, self::LAURA, date('Y-m-d'), date('Y-m-d'))[date('Y-m-d')]);
    }

    public function testRellenarLaSemanaSoloUsaHuecosLibresYNuncaAlergenos(): void
    {
        $antes = (int) $this->valor("SELECT COUNT(*) FROM comida_planificada cp JOIN plan_semanal ps USING (id_plan) WHERE ps.id_usuario = ?", [self::LAURA]);
        $libres = count(huecosLibres(self::$pdo, self::LAURA, ['Merienda']));
        $this->assertSame(7, $libres);

        $prompts = [];
        $iaFalsa = function (string $prompt) use (&$prompts) {
            $prompts[] = $prompt;
            $receta = fn($titulo, $ingrediente) => ['momento' => 'Merienda', 'titulo' => $titulo, 'calorias' => 150,
                'macros' => ['proteinas' => 10, 'carbohidratos' => 15, 'grasas' => 5],
                'ingredientes_usados' => [['nombre' => $ingrediente, 'cantidad' => 30, 'unidad' => 'g']], 'instrucciones' => ['Mezclar.']];
            return json_encode(['recetas' => [$receta('Yogur con cacahuetes', 'Cacahuetes'), $receta('Fruta con avena', 'Avena')]]);
        };

        $resultado = rellenarSemanaConIA(self::$pdo, self::LAURA, ['Merienda'], 2, false, '', $iaFalsa);
        $this->assertSame(['recetas' => 1, 'comidas' => 7, 'sin_hueco' => 0], $resultado);
        $this->assertCount(1, $prompts, 'Una sola llamada a la IA para toda la semana');
        $this->assertStringContainsString('Cacahuete', $prompts[0]);
        $this->assertSame($antes + 7, (int) $this->valor("SELECT COUNT(*) FROM comida_planificada cp JOIN plan_semanal ps USING (id_plan) WHERE ps.id_usuario = ?", [self::LAURA]));
        $this->assertSame(0, (int) $this->valor("SELECT COUNT(*) FROM receta WHERE titulo LIKE '%cacahuete%'"), 'La receta con alérgeno no se guarda');
        // Lo que ya estaba planificado no se toca
        $this->assertSame(1, (int) $this->valor("SELECT id_receta FROM comida_planificada cp JOIN plan_semanal ps USING (id_plan) WHERE ps.id_usuario = ? AND dia_semana = 0 AND momento_comida = 'Desayuno'", [self::LAURA]));

        $this->assertArrayHasKey('error', rellenarSemanaConIA(self::$pdo, self::LAURA, ['Merienda'], 2, false, '', $iaFalsa), 'Ya no quedan huecos');
        $this->assertArrayHasKey('error', rellenarSemanaConIA(self::$pdo, self::LAURA, ['Cena'], 2, false, '', fn() => 'basura'));
    }

    public function testCadaSemanaTieneSuPropioPlan(): void
    {
        $esta = lunesDe();
        $siguiente = semanaRelativa($esta, 1);
        $this->assertTrue(asignarRecetaAlPlan(self::$pdo, self::LAURA, 2, 0, 'Merienda', $siguiente));
        $this->assertNotSame(obtenerPlan(self::$pdo, self::LAURA, $esta), obtenerPlan(self::$pdo, self::LAURA, $siguiente));
        $this->assertSame(obtenerPlan(self::$pdo, self::LAURA, $siguiente), obtenerOCrearPlan(self::$pdo, self::LAURA, $siguiente), 'No duplica el plan');
        // La merienda del lunes que viene no aparece en esta semana
        $this->assertSame(0, (int) $this->valor("SELECT COUNT(*) FROM comida_planificada cp JOIN plan_semanal ps USING (id_plan)
                                                  WHERE ps.id_usuario = ? AND ps.fecha_inicio_semana = ? AND cp.momento_comida = 'Merienda'", [self::LAURA, $esta]));
        $this->assertSame(0, count(huecosLibres(self::$pdo, self::LAURA, ['Merienda'], $siguiente)) - 6);
    }

    public function testCopiarLaSemanaSoloRellenaHuecos(): void
    {
        $esta = lunesDe();
        $siguiente = semanaRelativa($esta, 1);
        // En la semana siguiente ya hay algo el lunes en el desayuno: se respeta
        asignarRecetaAlPlan(self::$pdo, self::LAURA, 3, 0, 'Desayuno', $siguiente);

        $copiadas = copiarSemana(self::$pdo, self::LAURA, $esta, $siguiente);
        $this->assertSame(18, $copiadas, '19 comidas de esta semana menos el hueco ya ocupado');
        $this->assertSame(3, (int) $this->valor("SELECT id_receta FROM comida_planificada WHERE id_plan = ? AND dia_semana = 0 AND momento_comida = 'Desayuno'",
            [obtenerPlan(self::$pdo, self::LAURA, $siguiente)]));
        $this->assertSame(0, copiarSemana(self::$pdo, self::LAURA, $esta, $siguiente), 'Copiar otra vez no duplica');
        $this->assertSame(0, copiarSemana(self::$pdo, self::MARCOS, $esta, $siguiente), 'Sin plan de origen no copia nada');
    }

    public function testSoloSeMarcaHechaLaComidaDeLaSemanaDeEseDia(): void
    {
        $lunesPasado = semanaRelativa(lunesDe(), -1);
        $idComida = (int) $this->valor("SELECT cp.id_comida_planificada FROM comida_planificada cp JOIN plan_semanal ps USING (id_plan)
                                         WHERE ps.fecha_inicio_semana = ? AND cp.dia_semana = 0 AND cp.momento_comida = 'Cena'", [$lunesPasado]);
        $this->assertNotNull(registrarComidaPlanificada(self::$pdo, self::LAURA, $idComida, $lunesPasado));
        // El mismo lunes pero de esta semana no es esa comida
        $this->assertNull(registrarComidaPlanificada(self::$pdo, self::LAURA, $idComida, lunesDe()));
    }

    public function testLaMigracionDeSemanasJuntaLosPlanesAntiguos(): void
    {
        // Como antes: un plan viejo con otra fecha y otro plan suelto con comidas
        self::$pdo->exec("DELETE FROM plan_semanal WHERE id_plan = 2");
        self::$pdo->exec("UPDATE plan_semanal SET fecha_inicio_semana = '2025-01-06' WHERE id_plan = 1");
        self::$pdo->exec("INSERT INTO plan_semanal (id_plan, id_usuario, fecha_inicio_semana) VALUES (9, 3, '2025-03-03')");
        self::$pdo->exec("INSERT INTO comida_planificada (id_plan, id_receta, dia_semana, momento_comida) VALUES (9, 2, 6, 'Merienda')");
        self::$pdo->exec("DELETE FROM migraciones WHERE nombre = '004_un_plan_por_semana.sql'");

        $this->assertSame(['004_un_plan_por_semana.sql'], aplicarMigracionesPendientes(self::$pdo));
        $this->assertSame(1, (int) $this->valor("SELECT COUNT(*) FROM plan_semanal WHERE id_usuario = ?", [self::LAURA]));
        $this->assertSame(1, obtenerPlan(self::$pdo, self::LAURA, lunesDe()), 'El plan pasa a ser el de esta semana');
        $this->assertSame(20, (int) $this->valor("SELECT COUNT(*) FROM comida_planificada WHERE id_plan = 1"), 'No se pierde ninguna comida');
    }

    public function testExportarLosDatosDeLaCuentaSinLaContrasena(): void
    {
        $datos = exportarDatosCuenta(self::$pdo, self::LAURA);
        $this->assertSame('laura@kaloai.test', $datos['cuenta']['correo']);
        $this->assertSame('Laura Martín', $datos['perfil']['nombre']);
        $this->assertCount(3, $datos['recetas']);
        $this->assertNotEmpty($datos['recetas'][0]['ingredientes']);
        $this->assertCount(2, $datos['planes_semanales'], 'Esta semana y la pasada');
        $this->assertNotEmpty($datos['comidas_registradas']);
        $this->assertCount(2, $datos['alergias_y_preferencias']);
        $json = json_encode($datos);
        $this->assertStringNotContainsString('contrasena', $json);
        $this->assertStringNotContainsString('$2y$', $json, 'Ningún hash de contraseña');
    }

    public function testBorrarLaCuentaBorraTodosSusDatos(): void
    {
        $this->assertTrue(borrarCuenta(self::$pdo, self::LAURA, 'baja de prueba'));
        foreach (['usuario', 'perfil', 'receta' => 'id_usuario_creador', 'plan_semanal', 'registro_comida', 'progreso', 'inventario', 'ingrediente_prohibido'] as $tabla => $columna) {
            if (is_int($tabla)) { $tabla = $columna; $columna = 'id_usuario'; }
            $this->assertSame(0, (int) $this->valor("SELECT COUNT(*) FROM $tabla WHERE $columna = ?", [self::LAURA]), $tabla);
        }
        $this->assertSame(0, (int) $this->valor("SELECT COUNT(*) FROM comida_planificada"), 'Sus comidas planificadas también');
        $this->assertSame('baja de prueba', $this->valor("SELECT detalle FROM logs_actividad ORDER BY id_log DESC LIMIT 1"));
        $this->assertFalse(borrarCuenta(self::$pdo, self::LAURA, 'otra vez'), 'Ya no existe');
    }

    public function testNoSePuedeBorrarElUltimoAdministrador(): void
    {
        $this->assertTrue(esUltimoAdministrador(self::$pdo, 1));
        $this->assertFalse(esUltimoAdministrador(self::$pdo, self::LAURA), 'No es administradora');
        self::$pdo->exec("UPDATE usuario SET id_rol = 1 WHERE id_usuario = 2");
        $this->assertFalse(esUltimoAdministrador(self::$pdo, 1), 'Hay otra cuenta de administración');
    }
}
