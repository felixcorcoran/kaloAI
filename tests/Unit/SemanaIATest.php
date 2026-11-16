<?php

use PHPUnit\Framework\TestCase;

/** «Rellenar la semana con IA»: prompt, lectura de la respuesta y reparto (sin base de datos). */
final class SemanaIATest extends TestCase
{
    private function pedido(): array
    {
        return [
            'Desayuno' => ['cantidad' => 2, 'objetivo' => ['kcal' => 357, 'proteinas' => 27, 'carbohidratos' => 36, 'grasas' => 12]],
            'Cena'     => ['cantidad' => 1, 'objetivo' => ['kcal' => 447, 'proteinas' => 34, 'carbohidratos' => 45, 'grasas' => 15]],
        ];
    }

    public function testElPromptPideCadaMomentoConSusObjetivosYAlergias(): void
    {
        $prompt = construirPromptSemana($this->pedido(), [], [['nombre_ingrediente' => 'Cacahuete', 'nota' => 'Alergia (Riesgo Alto)']], true, 'más "pescado"');
        $this->assertStringContainsString('crea 3 recetas', $prompt);
        $this->assertStringContainsString('- MOMENTO: Desayuno | recetas: 2 | 357 kcal', $prompt);
        $this->assertStringContainsString('- MOMENTO: Cena | recetas: 1 | 447 kcal', $prompt);
        $this->assertStringContainsString('- Cacahuete (Alergia (Riesgo Alto))', $prompt);
        $this->assertStringContainsString('USA ÚNICAMENTE ingredientes de la despensa', $prompt);
        $this->assertStringContainsString("\"más 'pescado'\"", $prompt);
    }

    public function testLeeLaRespuestaYDescartaLoQueSobraONoSePidio(): void
    {
        $receta = fn($momento, $titulo) => ['momento' => $momento, 'titulo' => $titulo, 'calorias' => 400,
            'macros' => ['proteinas' => 20, 'carbohidratos' => 40, 'grasas' => 10],
            'ingredientes_usados' => [['nombre' => 'Pollo', 'cantidad' => 100, 'unidad' => 'g']], 'instrucciones' => ['Cocinar.']];
        $respuesta = json_encode(['recetas' => [
            $receta('desayuno', 'A'), $receta('Desayuno', 'B'), $receta('Desayuno', 'C (sobra)'),
            $receta('Cena', 'D'), $receta('Merienda', 'E (no pedida)'), $receta('Recena', 'F (no existe)'),
            ['momento' => 'Cena', 'titulo' => 'Sin ingredientes', 'ingredientes_usados' => []],
        ]]);

        $semana = normalizarSemanaIA($respuesta, $this->pedido());
        $this->assertSame(['A', 'B'], array_column($semana['Desayuno'], 'titulo'));
        $this->assertSame(['D'], array_column($semana['Cena'], 'titulo'));
        $this->assertArrayNotHasKey('Merienda', $semana);
        $this->assertSame('Pechuga De Pollo', $semana['Cena'][0]['ingredientes_usados'][0]['nombre'], 'Se normalizan los ingredientes');
        $this->assertNull(normalizarSemanaIA('no es json', $this->pedido()));
        $this->assertNull(normalizarSemanaIA('{"otra": 1}', $this->pedido()));
    }

    public function testRepartoRotaLasRecetasYRespetaElLimiteDiario(): void
    {
        $huecos = [];
        foreach ([0, 1, 2] as $dia) $huecos[] = ['dia' => $dia, 'momento' => 'Cena'];
        // El martes ya tiene 1.500 kcal: con 2.000 de objetivo solo cabe hasta 2.200
        $reparto = repartirRecetas($huecos, ['Cena' => [500, 800]], [1 => 1500.0], 2000);
        $this->assertSame([
            ['dia' => 0, 'momento' => 'Cena', 'indice' => 0],
            ['dia' => 1, 'momento' => 'Cena', 'indice' => 0], // la 2.ª (800) no cabe; usa la 1.ª (500)
            ['dia' => 2, 'momento' => 'Cena', 'indice' => 1],
        ], $reparto);

        // Si ninguna cabe, el hueco se queda libre
        $this->assertSame([], repartirRecetas([['dia' => 0, 'momento' => 'Cena']], ['Cena' => [900]], [0 => 1500.0], 2000));
        // Sin recetas para ese momento, no se asigna nada
        $this->assertSame([], repartirRecetas([['dia' => 0, 'momento' => 'Desayuno']], ['Cena' => [400]], [], 2000));
    }
}
