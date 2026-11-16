<?php

use PHPUnit\Framework\TestCase;

/** Validación del formulario de contacto (sin base de datos). */
final class ContactoTest extends TestCase
{
    private array $bueno = ['nombre' => '  Ana   López ', 'email' => 'ana@ejemplo.test', 'mensaje' => "Hola,\r\n¿cómo cambio mi objetivo?"];

    public function testDatosValidosSeLimpian(): void
    {
        $this->assertSame(['nombre' => 'Ana López', 'correo' => 'ana@ejemplo.test', 'mensaje' => "Hola,\n¿cómo cambio mi objetivo?"], validarContacto($this->bueno));
    }

    public function testRechazaDatosNoValidos(): void
    {
        $this->assertArrayHasKey('error', validarContacto(['nombre' => ''] + $this->bueno));
        $this->assertArrayHasKey('error', validarContacto(['email' => 'no-es-correo'] + $this->bueno));
        $this->assertArrayHasKey('error', validarContacto(['mensaje' => 'corto'] + $this->bueno));
        $this->assertArrayHasKey('error', validarContacto(['mensaje' => str_repeat('a', CONTACTO_MAX_MENSAJE + 1)] + $this->bueno));
    }
}
