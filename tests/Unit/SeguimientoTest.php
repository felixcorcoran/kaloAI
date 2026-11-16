<?php

use PHPUnit\Framework\TestCase;

/** Resumen de lo que come un paciente (sin base de datos). */
final class SeguimientoTest extends TestCase
{
    public function testResumenDeLosUltimosDias(): void
    {
        $r = resumenConsumo(['2026-11-14' => 1800, '2026-11-16' => '1400', '2026-10-01' => 9999], '2026-11-16', 3);
        $this->assertSame(['2026-11-14' => 1800.0, '2026-11-15' => null, '2026-11-16' => 1400.0], $r['dias']);
        $this->assertSame(2, $r['con_registro']);
        $this->assertSame(1600.0, $r['media'], 'La media solo cuenta los días apuntados');
        $this->assertNull(resumenConsumo([], '2026-11-16', 7)['media']);
    }
}
