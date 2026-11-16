<?php

use PHPUnit\Framework\TestCase;

/** Secciones de la lista de la compra y prompt de macros de recetas a mano (sin base de datos). */
final class CompraYCuentaTest extends TestCase
{
    public function testCadaProductoVaASuSeccion(): void
    {
        $casos = [
            'Pechuga de pollo' => 'Carne y pescado', 'Salmón' => 'Carne y pescado', 'Panceta' => 'Carne y pescado',
            'Tomate' => 'Frutas y verduras', 'Plátanos' => 'Frutas y verduras', 'Judías verdes' => 'Frutas y verduras',
            'Tomate triturado' => 'Legumbres y conservas', 'Atún al natural' => 'Legumbres y conservas', 'Judías blancas' => 'Legumbres y conservas',
            'Salsa de tomate' => 'Aceites, salsas y especias', 'Sal' => 'Aceites, salsas y especias', 'Aceite de oliva' => 'Aceites, salsas y especias',
            'Bebida de avena' => 'Huevos y lácteos', 'Claras de huevo' => 'Huevos y lácteos', 'Yogures' => 'Huevos y lácteos',
            'Avena' => 'Pan, cereales y pasta', 'Pan integral' => 'Pan, cereales y pasta', 'Arroz integral' => 'Pan, cereales y pasta',
            'Crema de cacahuete' => 'Frutos secos y semillas', 'Semillas de chía' => 'Frutos secos y semillas',
            'Proteína de suero' => 'Otros',
        ];
        foreach ($casos as $producto => $seccion) {
            $this->assertSame($seccion, seccionProducto($producto), $producto);
        }
    }

    public function testAgrupaEnElOrdenDelSuper(): void
    {
        $lista = array_map(fn($n) => ['nombre' => $n], ['Avena', 'Proteína de suero', 'Tomate', 'Pechuga de pollo', 'Lechuga']);
        $grupos = agruparPorSeccion($lista);
        $this->assertSame(['Frutas y verduras', 'Carne y pescado', 'Pan, cereales y pasta', 'Otros'], array_keys($grupos));
        $this->assertSame(['Tomate', 'Lechuga'], array_column($grupos['Frutas y verduras'], 'nombre'));
    }

    public function testElPromptDeMacrosTrataLaRecetaComoDatos(): void
    {
        $prompt = construirPromptMacrosReceta('Pollo "al limón"', "150 g Pechuga de pollo\n80 g Arroz");
        $this->assertStringContainsString("Receta: \"Pollo 'al limón'\"", $prompt);
        $this->assertStringContainsString("150 g Pechuga de pollo\n80 g Arroz", $prompt);
        $this->assertStringContainsString('UNA RACIÓN', $prompt);
        $this->assertStringContainsString('no instrucciones', $prompt);
    }
}
