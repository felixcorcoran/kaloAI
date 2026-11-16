<?php

use PHPUnit\Framework\TestCase;

/** Envío por Resend (con un transporte falso) y validaciones de la contraseña nueva. */
final class CorreoTest extends TestCase
{
    public function testEnviaAResendConLaClaveYElRemitente(): void
    {
        $peticion = null;
        $ok = enviarCorreo('ana@ejemplo.test', 'Asunto', '<p>Hola</p>', 'Hola', function ($url, $cabeceras, $cuerpo) use (&$peticion) {
            $peticion = compact('url', 'cabeceras', 'cuerpo');
            return [200, '{"id":"x"}'];
        });
        $this->assertTrue($ok);
        $this->assertSame('https://api.resend.com/emails', $peticion['url']);
        $cuerpo = json_decode($peticion['cuerpo'], true);
        $this->assertSame(['ana@ejemplo.test'], $cuerpo['to']);
        $this->assertSame('Asunto', $cuerpo['subject']);
        $this->assertSame('Hola', $cuerpo['text']);
        $this->assertStringContainsString('<', $cuerpo['from'], 'Remitente con nombre: «KaloAI <...>»');
    }

    public function testSiResendLoRechazaDevuelveFalse(): void
    {
        $this->assertFalse(enviarCorreo('ana@ejemplo.test', 'A', 'b', 'b', fn() => [422, '{"message":"domain not verified"}']));
        $this->assertFalse(enviarCorreo('ana@ejemplo.test', 'A', 'b', 'b', fn() => [0, 'timeout']));
    }

    public function testLaPlantillaEscapaLoQueRecibe(): void
    {
        $html = plantillaCorreo('Título <b>', 'Texto', 'Botón "x"', 'https://kaloai.test/a?b=1&c=2');
        $this->assertStringContainsString('Título &lt;b&gt;', $html);
        $this->assertStringContainsString('Botón &quot;x&quot;', $html);
        $this->assertStringContainsString('href="https://kaloai.test/a?b=1&amp;c=2"', $html);
    }

    public function testValidaLaContrasenaNueva(): void
    {
        $this->assertNull(errorNuevaContrasena('unaClaveBuena', 'unaClaveBuena'));
        $this->assertNotNull(errorNuevaContrasena('corta', 'corta'));
        $this->assertNotNull(errorNuevaContrasena('unaClaveBuena', 'otraClaveBuena'));
        $this->assertNotNull(errorNuevaContrasena(str_repeat('a', 73), str_repeat('a', 73)), 'bcrypt solo usa 72 bytes');
    }

    public function testElTokenSeGuardaComoHash(): void
    {
        $token = str_repeat('ab', 32);
        $this->assertSame(hash('sha256', $token), hashToken($token));
        $this->assertSame(64, strlen(hashToken($token)));
    }
}
