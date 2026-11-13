<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Cálculos de core/utilidades.php: TDEE (Mifflin-St Jeor), macros y edad. */
final class NutricionTest extends TestCase
{
    public function testTdeeHombreSegunMifflinStJeor(): void
    {
        // TMB = 10*80 + 6.25*180 - 5*30 + 5 = 1780; x1.55 = 2759
        $this->assertSame(2759, calcularTDEE('M', 80, 180, 30, 1.55));
    }

    public function testTdeeMujerSegunMifflinStJeor(): void
    {
        // TMB = 10*64.5 + 6.25*168 - 5*31 - 161 = 1379; x1.55 = 2137.45
        $this->assertSame(2137, calcularTDEE('F', 64.5, 168, 31, 1.55));
    }

    public function testMantenimientoNoCambiaLasCalorias(): void
    {
        $macros = calcularMacrosObjetivo(2500, 'mantenimiento');
        $this->assertSame(2500, $macros['calorias_netas']);
        // 30 % proteína, 45 % carbohidratos, 25 % grasa
        $this->assertSame(188, $macros['proteinas']);
        $this->assertSame(281, $macros['carbohidratos']);
        $this->assertSame(69, $macros['grasas']);
    }

    public static function objetivos(): array
    {
        return [
            'pérdida rápida'   => ['perdida_rapida', 2500, 1750],
            'pérdida moderada' => ['perdida_moderada', 2500, 2000],
            'pérdida de grasa' => ['perdida_grasa', 2500, 2150],
            'ganancia'         => ['ganancia_muscular', 2500, 2800],
            'rendimiento'      => ['rendimiento_deportivo', 2500, 3000],
            'recomposición'    => ['recomposicion_corporal', 2500, 2500],
            'desconocido'      => ['inventado', 2500, 2500],
        ];
    }

    #[DataProvider('objetivos')]
    public function testAjusteCaloricoPorObjetivo(string $objetivo, int $tdee, int $esperado): void
    {
        $this->assertSame($esperado, calcularMacrosObjetivo($tdee, $objetivo)['calorias_netas']);
    }

    public function testNuncaBajaDeUnSueloDe1200Kcal(): void
    {
        $this->assertSame(1200, calcularMacrosObjetivo(1500, 'perdida_rapida')['calorias_netas']);
    }

    public function testLosMacrosSumanLasCaloriasObjetivo(): void
    {
        foreach (array_keys(self::objetivos()) as $nombre) {
            $objetivo = self::objetivos()[$nombre][0];
            $m = calcularMacrosObjetivo(2300, $objetivo);
            $kcal = $m['proteinas'] * 4 + $m['carbohidratos'] * 4 + $m['grasas'] * 9;
            // Solo el redondeo de cada macro a gramos enteros separa ambas cifras
            $this->assertEqualsWithDelta($m['calorias_netas'], $kcal, 10, "Objetivo $objetivo");
        }
    }

    public function testEdad(): void
    {
        $haceTreintaAnos = (new DateTime('-30 years'))->format('Y-m-d');
        $this->assertSame(30, calcularEdad($haceTreintaAnos));
        $this->assertSame(29, calcularEdad((new DateTime('-30 years +1 day'))->format('Y-m-d')));
    }

    public function testLimpiarInputYaNoConvierteAHtml(): void
    {
        // El texto se guarda tal cual; se escapa al mostrarlo
        $this->assertSame("Pan & aceite d'oliva", limpiarInput("  Pan & aceite d'oliva \x07 "));
    }

    public function testNormalizarString(): void
    {
        $this->assertSame('cafe con nata', normalizarString('  Café   con  Nata '));
    }
}
