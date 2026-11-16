<?php

use PHPUnit\Framework\TestCase;

/** Raciones, plantillas, lista compartida, resumen semanal y tique (sin base de datos). */
final class MejorasTest extends TestCase
{
    public function testRacionesPermitidas(): void
    {
        $this->assertSame(1.5, racionesValidas('1,5'));
        $this->assertSame(0.5, racionesValidas('0.5'));
        $this->assertSame(2.0, racionesValidas(2));
        $this->assertNull(racionesValidas('1.25'));
        $this->assertNull(racionesValidas('0'));
        $this->assertNull(racionesValidas('abc'));
    }

    public function testTextoDeLasRaciones(): void
    {
        $this->assertSame('½ ración', textoRaciones(0.5));
        $this->assertSame('1 ración', textoRaciones(1));
        $this->assertSame('1½ raciones', textoRaciones(1.5));
        $this->assertSame('3 raciones', textoRaciones(3));
    }

    public function testNombreDePlantilla(): void
    {
        $this->assertSame('Semana de entreno', nombrePlantillaValido("  Semana   de\tentreno "));
        $this->assertNull(nombrePlantillaValido('   '));
        $this->assertNull(nombrePlantillaValido(str_repeat('a', 61)));
        $this->assertSame(str_repeat('ñ', 60), nombrePlantillaValido(str_repeat('ñ', 60)), 'Cuenta caracteres, no bytes');
    }

    public function testFormatoDelToken(): void
    {
        $this->assertTrue(tokenListaValido(bin2hex(random_bytes(16))));
        $this->assertFalse(tokenListaValido('ABCDEF0123456789abcdef0123456789'), 'Solo minúsculas');
        $this->assertFalse(tokenListaValido('abc'));
        $this->assertFalse(tokenListaValido(null));
    }

    public function testCantidadLegible(): void
    {
        $this->assertSame('1,5 kg', formatearCantidad(1500, 'g'));
        $this->assertSame('250 g', formatearCantidad(250, 'g'));
        $this->assertSame('2 L', formatearCantidad(2000, 'ml'));
        $this->assertSame('1 unidad', formatearCantidad(1, 'unidad'));
        $this->assertSame('3 unidades', formatearCantidad(3, 'unidad'));
    }

    public function testLasUnidadesSeCompranEnteras(): void
    {
        $lista = calcularListaCompra(
            [['nombre_ingrediente' => 'Huevo', 'cantidad' => 3, 'unidad' => 'unidad', 'raciones' => 1.5],
             ['nombre_ingrediente' => 'Arroz', 'cantidad' => 75, 'unidad' => 'g', 'raciones' => 0.5]],
            [['nombre_ingrediente' => 'Huevo', 'cantidad' => 2, 'unidad' => 'unidad']]);
        $porNombre = array_column($lista, null, 'nombre');
        $this->assertSame(3.0, $porNombre['Huevo']['comprar'], '4,5 - 2 = 2,5 huevos: se compran 3');
        $this->assertSame(37.5, $porNombre['Arroz']['comprar'], 'Los gramos no se redondean');
    }

    private function datosResumen(array $cambios = []): array
    {
        return array_merge([
            'nombre' => 'Laura Martín', 'lunes' => '2026-11-09', 'objetivo' => 1800,
            'consumo' => ['2026-11-09' => 1750, '2026-11-10' => 1900, '2026-11-11' => 2300],
            'peso' => 64.5, 'peso_anterior' => 64.9, 'comidas_siguiente' => 12,
        ], $cambios);
    }

    public function testCorreoDelResumenSemanal(): void
    {
        $c = construirCorreoResumen($this->datosResumen(), 'https://kalo.test/', 'https://kalo.test/public/baja_resumen.php?t=abc');
        $this->assertSame('Tu semana en KaloAI: 3 de 7 días apuntados', $c['asunto']);
        $this->assertStringContainsString('Hola, Laura.', $c['texto']);
        $this->assertStringContainsString('del 9 al 15 nov', $c['texto']);
        $this->assertStringContainsString('Media de 1.983 kcal al día (por encima de tu objetivo de 1.800)', $c['texto']);
        $this->assertStringContainsString('2 días a menos de un 10 %', $c['texto']);
        $this->assertStringContainsString('64,5 kg (−0,4 kg)', $c['texto']);
        $this->assertStringContainsString('12 comidas planificadas', $c['texto']);
        $this->assertStringContainsString('https://kalo.test/views/user/planificacion_semanal.php', $c['html']);
        $this->assertStringContainsString('baja_resumen.php?t=abc', $c['html']);
    }

    public function testResumenDeUnaSemanaSinDatosYConNombreRaro(): void
    {
        $c = construirCorreoResumen($this->datosResumen(['nombre' => '<b>Eva</b>', 'consumo' => [], 'peso' => null, 'comidas_siguiente' => 0, 'lunes' => '2026-11-30']),
            'https://kalo.test', 'https://kalo.test/baja');
        $this->assertSame('Tu semana en KaloAI: empieza la siguiente con buen pie', $c['asunto']);
        $this->assertStringContainsString('No apuntaste ninguna comida', $c['texto']);
        $this->assertStringContainsString('No te pesaste', $c['texto']);
        $this->assertStringContainsString('Aún no has planificado nada', $c['texto']);
        $this->assertStringContainsString('del 30 nov al 6 dic', $c['texto'], 'Semana que cambia de mes');
        $this->assertStringNotContainsString('<b>Eva</b>', $c['html'], 'El nombre va escapado');
    }

    public function testCuandoTocaEnviarElResumen(): void
    {
        $lunes = new DateTimeImmutable('2026-11-16 06:30');
        $this->assertNull(semanaPorResumir($lunes, null), 'Antes de las 7:00 del lunes, no');
        $this->assertSame('2026-11-09', semanaPorResumir(new DateTimeImmutable('2026-11-16 07:00'), null));
        $this->assertSame('2026-11-09', semanaPorResumir(new DateTimeImmutable('2026-11-18 12:00'), '2026-11-09'), 'Marca de la semana anterior');
        $this->assertNull(semanaPorResumir(new DateTimeImmutable('2026-11-18 12:00'), '2026-11-16'), 'Ya enviados esta semana');
    }

    public function testUnidadesDelTique(): void
    {
        $this->assertSame('L', unidadTique('litros'));
        $this->assertSame('g', unidadTique('GR'));
        $this->assertSame('unidad', unidadTique(''));
        $this->assertNull(unidadTique('docena'));
    }

    public function testProductosDelTique(): void
    {
        $respuesta = "```json\n" . json_encode(['productos' => [
            ['nombre' => 'leche semidesnatada', 'cantidad' => 6, 'unidad' => 'l'],
            ['nombre' => 'Yogur natural', 'cantidad' => '2', 'unidad' => 'ud'],
            ['nombre' => 'yogur natural', 'cantidad' => 4, 'unidad' => 'unidades'],
            ['nombre' => 'Pollo', 'cantidad' => '1,25', 'unidad' => 'kg'],
            ['nombre' => '', 'cantidad' => 1, 'unidad' => 'g'],
            ['nombre' => 'Huevos', 'cantidad' => 1, 'unidad' => 'docena'],
            ['nombre' => 'Arroz', 'cantidad' => -1, 'unidad' => 'kg'],
            'no es un producto',
        ]]) . "\n```";
        $this->assertSame([
            ['nombre' => 'Leche semidesnatada', 'cantidad' => 6.0, 'unidad' => 'L'],
            ['nombre' => 'Yogur natural', 'cantidad' => 6.0, 'unidad' => 'unidad'],
            ['nombre' => 'Pollo', 'cantidad' => 1.25, 'unidad' => 'kg'],
        ], normalizarProductosTique($respuesta));
        $this->assertSame([], normalizarProductosTique('{"productos": []}'));
        $this->assertNull(normalizarProductosTique('no es JSON'));
        $this->assertNull(normalizarProductosTique(null));
    }
}
