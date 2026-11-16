<?php

use PHPUnit\Framework\TestCase;

/** Cálculos de semanas del plan (sin base de datos). */
final class SemanasTest extends TestCase
{
    public function testLunesDeCualquierDia(): void
    {
        $this->assertSame('2026-11-09', lunesDe('2026-11-09'));
        $this->assertSame('2026-11-09', lunesDe('2026-11-15'), 'Domingo');
        $this->assertSame('2026-12-28', lunesDe('2027-01-01'), 'Cambio de año');
    }

    public function testSemanaValidaAcotaYCorrige(): void
    {
        $hoy = '2026-11-15';
        $this->assertSame('2026-11-09', semanaValida(null, $hoy));
        $this->assertSame('2026-11-09', semanaValida('no-es-fecha', $hoy));
        $this->assertSame('2026-11-09', semanaValida('2026-02-31', $hoy), 'Fecha imposible');
        $this->assertSame('2026-11-16', semanaValida('2026-11-19', $hoy), 'Se lleva al lunes');
        $this->assertSame('2027-01-04', semanaValida('2030-01-01', $hoy), 'Como mucho 8 semanas adelante');
        $this->assertSame('2024-11-11', semanaValida('2000-01-03', $hoy), 'Como mucho dos años atrás');
    }

    public function testMoverseEntreSemanasYDias(): void
    {
        $this->assertSame('2026-11-02', semanaRelativa('2026-11-09', -1));
        $this->assertSame('2027-01-04', semanaRelativa('2026-12-28', 1));
        $this->assertSame('2026-11-15', fechaDelDia('2026-11-09', 6));
    }

    public function testNombreDeLaSemana(): void
    {
        $hoy = '2026-11-15';
        $this->assertSame('Esta semana', nombreSemana('2026-11-09', $hoy));
        $this->assertSame('La semana que viene', nombreSemana('2026-11-16', $hoy));
        $this->assertSame('La semana pasada', nombreSemana('2026-11-02', $hoy));
        $this->assertSame('26 oct – 1 nov', nombreSemana('2026-10-26', $hoy));
        $this->assertSame('12 – 18 oct', nombreSemana('2026-10-12', $hoy));
        $this->assertSame('29 dic – 4 ene 2026', nombreSemana('2025-12-29', $hoy), 'Otro año');
    }
}
