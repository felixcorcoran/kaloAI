<?php

use PHPUnit\Framework\TestCase;

/** core/seguridad.php: escapado, JSON incrustado y token CSRF. */
final class SeguridadTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testEEscapaHtmlYComillas(): void
    {
        $this->assertSame('&lt;b&gt;O&#039;Brien &amp; &quot;Co&quot;&lt;/b&gt;', e('<b>O\'Brien & "Co"</b>'));
        $this->assertSame('', e(null));
        $this->assertSame('12.5', e(12.5));
    }

    public function testJsonHtmlNoCierraElScript(): void
    {
        $json = json_html(['nota' => '</script><script>alert(1)</script>']);
        $this->assertStringNotContainsString('</script>', $json);
        $this->assertSame(['nota' => '</script><script>alert(1)</script>'], json_decode($json, true));
    }

    public function testJsonHtmlEsSeguroEnAtributoConComillasSimples(): void
    {
        // El título con apóstrofo rompía onclick='abrirReceta(...)'
        $json = json_html(['titulo' => "Tortilla d'Ana"]);
        $this->assertStringNotContainsString("'", $json);
        $this->assertSame("Tortilla d'Ana", json_decode($json, true)['titulo']);
    }

    public function testTokenCsrfEsEstableYAleatorio(): void
    {
        $token = csrfToken();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertSame($token, csrfToken(), 'El token no cambia dentro de la sesión');

        $_SESSION = [];
        $this->assertNotSame($token, csrfToken(), 'Cada sesión tiene su propio token');
    }

    public function testValidacionDelToken(): void
    {
        $token = csrfToken();
        $this->assertTrue(csrfValido($token));
        $this->assertFalse(csrfValido('otro'));
        $this->assertFalse(csrfValido(''));
        $this->assertFalse(csrfValido(null));

        $_SESSION = [];
        $this->assertFalse(csrfValido(''), 'Sin token en la sesión no vale ningún valor');
    }

    public function testCampoCsrf(): void
    {
        $this->assertSame('<input type="hidden" name="csrf_token" value="' . csrfToken() . '">', campoCsrf());
    }
}
