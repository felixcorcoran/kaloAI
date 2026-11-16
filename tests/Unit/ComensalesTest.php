<?php

use PHPUnit\Framework\TestCase;

/** Lista de la compra para varias personas (sin base de datos). */
final class ComensalesTest extends TestCase
{
    public function testMultiplicaLasRacionesDeLaLista(): void
    {
        $filas = [['nombre_ingrediente' => 'Arroz', 'cantidad' => 80, 'unidad' => 'g', 'raciones' => 1],
                  ['nombre_ingrediente' => 'Pollo', 'cantidad' => 150, 'unidad' => 'g', 'raciones' => '2.00']];
        $this->assertSame($filas, multiplicarRaciones($filas, 1), 'Para una persona no cambia nada');
        $tres = multiplicarRaciones($filas, 3);
        $this->assertSame([3.0, 6.0], array_column($tres, 'raciones'));

        $lista = calcularListaCompra($tres, [['nombre_ingrediente' => 'Arroz', 'cantidad' => 100, 'unidad' => 'g']]);
        $this->assertSame([140.0, 900.0], array_column($lista, 'comprar'), '240 g de arroz menos los 100 g de la despensa');
    }
}
