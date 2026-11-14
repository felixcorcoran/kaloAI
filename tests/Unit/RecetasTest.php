<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Funciones puras de core/recetas.php. */
final class RecetasTest extends TestCase
{
    public function testDiasYMomentos(): void
    {
        $this->assertSame(0, diaANumero('Lunes'));
        $this->assertSame(2, diaANumero('miércoles'));
        $this->assertSame(6, diaANumero('DOMINGO'));
        $this->assertNull(diaANumero('Funday'));
        $this->assertNull(diaANumero(null));

        $this->assertSame('Media Mañana', momentoValido('media mañana'));
        $this->assertNull(momentoValido("Cena'; DROP TABLE receta; --"));
    }

    public function testTipoDeRecetaSegunElMomento(): void
    {
        $this->assertSame('Snack', tipoRecetaParaMomento('Media Mañana'));
        $this->assertSame('Snack', tipoRecetaParaMomento('Merienda'));
        $this->assertSame('Cena', tipoRecetaParaMomento('cena'));
        $this->assertSame('Almuerzo', tipoRecetaParaMomento('algo raro'), 'Un valor fuera del ENUM no llega a la BD');
    }

    public function testDividirPasos(): void
    {
        $this->assertSame(['Cuece 1.5 tazas de arroz.', 'Sirve.'], dividirPasos("1. Cuece 1.5 tazas de arroz.\n2. Sirve."));
        // Recetas antiguas, todo en una línea
        $this->assertSame(['Bate los huevos.', 'Cuájalos.'], dividirPasos('Bate los huevos. Cuájalos.'));
        $this->assertSame([], dividirPasos(null));
    }

    public static function casosDeAlergias(): array
    {
        return [
            'nombre exacto'         => [['Cacahuete'], ['Cacahuete'], ['Cacahuete']],
            'plural'                => [['Cacahuetes tostados'], ['Cacahuete'], ['Cacahuete']],
            'sin tildes'            => [['Salsa de nueces'], ['Nuez'], ['Nuez']],
            'mayúsculas y tildes'   => [['CAMARÓN cocido'], ['camaron'], ['camaron']],
            'dentro de otro nombre' => [['Claras de huevo'], ['Huevo'], ['Huevo']],
            'no confunde palabras'  => [['Pechuga de pollo'], ['Pollo asado'], []],
            'sin restricciones'     => [['Leche'], [], []],
        ];
    }

    #[DataProvider('casosDeAlergias')]
    public function testDetectaAlimentosProhibidos(array $ingredientes, array $prohibidos, array $esperado): void
    {
        $filas = array_map(fn($n) => ['nombre' => $n, 'cantidad' => 1, 'unidad' => 'g'], $ingredientes);
        $this->assertSame($esperado, ingredientesProhibidosEn($filas, $prohibidos));
    }

    public function testUnificaIngredientesRepetidos(): void
    {
        // detalle_receta tiene clave (id_receta, nombre_ingrediente): un repetido rompía el guardado
        $resultado = unificarIngredientes([
            ['nombre' => 'Aceite De Oliva', 'cantidad' => 5, 'unidad' => 'ml'],
            ['nombre' => 'aceite de oliva', 'cantidad' => 5, 'unidad' => 'ml'],
            ['nombre' => 'Sal', 'cantidad' => null, 'unidad' => ''],
            ['nombre' => '   ', 'cantidad' => 1, 'unidad' => 'g'],
        ]);
        $this->assertSame([
            ['nombre' => 'Aceite De Oliva', 'cantidad' => 10.0, 'unidad' => 'ml'],
            ['nombre' => 'Sal', 'cantidad' => 0.0, 'unidad' => 'unidad'],
        ], $resultado);
    }

    public static function lineasDeIngredientes(): array
    {
        return [
            ['150 g Pechuga de pollo', 'Pechuga de pollo', 150.0, 'g'],
            ['1,5 kg de patatas', 'patatas', 1.5, 'kg'],
            ['2 huevos', 'huevos', 2.0, 'unidad'],
            ['3 cucharadas de miel', 'miel', 3.0, 'cda'],
            ['Sal', 'Sal', 1.0, 'unidad'],
        ];
    }

    #[DataProvider('lineasDeIngredientes')]
    public function testLeeLineasDeIngredientes(string $linea, string $nombre, float $cantidad, string $unidad): void
    {
        $this->assertSame(['nombre' => $nombre, 'cantidad' => $cantidad, 'unidad' => $unidad], parsearLineaIngrediente($linea));
    }

    public function testEditarYGuardarNoPierdeLasCantidades(): void
    {
        $fila = ['nombre_ingrediente' => 'Arroz integral', 'cantidad' => '80.000', 'unidad' => 'g'];
        $vuelta = parsearLineaIngrediente(formatearLineaIngrediente($fila));
        $this->assertSame(['nombre' => 'Arroz integral', 'cantidad' => 80.0, 'unidad' => 'g'], $vuelta);
    }

    public function testListaDeLaCompraConvierteUnidades(): void
    {
        $necesarios = [
            ['nombre_ingrediente' => 'Arroz Integral', 'cantidad' => 80, 'unidad' => 'g', 'raciones' => 7],  // 560 g
            ['nombre_ingrediente' => 'Leche', 'cantidad' => 200, 'unidad' => 'g', 'raciones' => 2],          // 400 ml
            ['nombre_ingrediente' => 'Calabacín', 'cantidad' => 120, 'unidad' => 'g', 'raciones' => 1],
            ['nombre_ingrediente' => 'Huevo', 'cantidad' => 3, 'unidad' => 'unidad', 'raciones' => 2],
        ];
        $inventario = [
            ['nombre_ingrediente' => 'arroz integral', 'cantidad' => 1, 'unidad' => 'kg'],
            ['nombre_ingrediente' => 'Leche', 'cantidad' => 0.25, 'unidad' => 'L'],
            ['nombre_ingrediente' => 'Huevo', 'cantidad' => 6, 'unidad' => 'unidad'],
        ];

        $lista = array_column(calcularListaCompra($necesarios, $inventario), null, 'nombre');

        $this->assertArrayNotHasKey('Arroz Integral', $lista, '1 kg cubre 560 g');
        $this->assertArrayNotHasKey('Huevo', $lista, '6 huevos cubren 6');
        $this->assertSame(['nombre' => 'Leche', 'total_recetas' => 400.0, 'en_despensa' => 250.0, 'comprar' => 150.0, 'unidad' => 'ml'], $lista['Leche']);
        $this->assertSame(120.0, $lista['Calabacín']['comprar']);
    }

    public function testUnidadBase(): void
    {
        $this->assertSame(['g', 1000.0], unidadBase('kg'));
        $this->assertSame(['ml', 1000.0], unidadBase('L'));
        $this->assertSame(['unidad', 1.0], unidadBase('u.'));
        $this->assertSame(['cda', 1.0], unidadBase('cda'));
    }
}
