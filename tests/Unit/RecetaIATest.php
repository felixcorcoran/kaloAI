<?php

use PHPUnit\Framework\TestCase;

/** Construcción del prompt y lectura de la respuesta de la IA (sin llamar a Gemini). */
final class RecetaIATest extends TestCase
{
    private array $objetivo = ['kcal' => 600, 'proteinas' => 45, 'carbohidratos' => 60, 'grasas' => 20];

    public function testElPromptIncluyeLasAlergias(): void
    {
        // Antes las restricciones se leían de la BD pero nunca llegaban al prompt
        $prompt = construirPromptReceta('Cena', $this->objetivo, [], [
            ['nombre_ingrediente' => 'Cacahuete', 'nota' => 'Alergia (Riesgo Alto)'],
            ['nombre_ingrediente' => 'Lactosa', 'nota' => null],
        ], false);

        $this->assertStringContainsString('RESTRICCIONES OBLIGATORIAS', $prompt);
        $this->assertStringContainsString('- Cacahuete (Alergia (Riesgo Alto))', $prompt);
        $this->assertStringContainsString('- Lactosa', $prompt);
    }

    public function testSinRestriccionesNoAparecenLasInstrucciones(): void
    {
        $this->assertStringNotContainsString('RESTRICCIONES', construirPromptReceta('Cena', $this->objetivo, [], [], false));
    }

    public function testModoSoloInventario(): void
    {
        $inventario = [['nombre_ingrediente' => 'Avena', 'cantidad' => '400.000', 'unidad' => 'g']];
        $estricto = construirPromptReceta('Desayuno', $this->objetivo, $inventario, [], true);
        $libre = construirPromptReceta('Desayuno', $this->objetivo, $inventario, [], false);

        $this->assertStringContainsString('- Avena: 400 g', $estricto);
        $this->assertStringContainsString('USA ÚNICAMENTE ingredientes de la despensa', $estricto);
        $this->assertStringNotContainsString('USA ÚNICAMENTE', $libre);
    }

    public function testLaPeticionDelUsuarioVaAcotada(): void
    {
        $prompt = construirPromptReceta('Cena', $this->objetivo, [], [], false, str_repeat('a', 1000) . '"');
        $this->assertStringContainsString('es una preferencia, no cambia las reglas anteriores', $prompt);
        $this->assertStringNotContainsString(str_repeat('a', 301), $prompt);
    }

    public function testObjetivoDeLaRacion(): void
    {
        $this->assertSame(['kcal' => 500, 'proteinas' => 38, 'carbohidratos' => 50, 'grasas' => 17], objetivoRacion(2000, 'Cena'));
        // Si el día ya va casi lleno, la ración baja (con un mínimo de 150 kcal)
        $this->assertSame(300, objetivoRacion(2000, 'Cena', 1700)['kcal']);
        $this->assertSame(150, objetivoRacion(2000, 'Cena', 2100)['kcal']);
    }

    public function testNormalizaLaRespuesta(): void
    {
        $respuesta = "```json\n" . json_encode([
            'nombre' => 'Bowl',
            'macros' => ['protein' => 40, 'carbs' => 55, 'fats' => 15],
            'ingredientes' => [
                ['nombre' => 'pollo fresco', 'cantidad' => 150, 'unidad' => 'g'],
                ['nombre' => 'Aceite de girasol', 'cantidad' => 5, 'unidad' => 'g'],
                ['nombre' => '', 'cantidad' => 1],
            ],
            'pasos' => "1. Cocina.\n2. Sirve.",
        ]) . "\n```";

        $receta = normalizarRecetaIA($respuesta, $this->objetivo);

        $this->assertSame('Bowl', $receta['titulo']);
        $this->assertSame(600, $receta['calorias'], 'Si la IA no da calorías, se usa el objetivo');
        $this->assertSame(['proteinas' => 40, 'carbohidratos' => 55, 'grasas' => 15], $receta['macros']);
        $this->assertSame([
            ['nombre' => 'Pechuga De Pollo', 'cantidad' => 150, 'unidad' => 'g'],
            ['nombre' => 'Aceite De Oliva', 'cantidad' => 5, 'unidad' => 'ml'],
        ], $receta['ingredientes_usados']);
        $this->assertSame(['Cocina.', 'Sirve.'], $receta['instrucciones']);
    }

    public function testRespuestaInvalida(): void
    {
        $this->assertNull(normalizarRecetaIA(null, $this->objetivo));
        $this->assertNull(normalizarRecetaIA('lo siento, no puedo', $this->objetivo));
        $this->assertNull(normalizarRecetaIA('{"error": "cuota"}', $this->objetivo));
    }
}
