<?php

use PHPUnit\Framework\TestCase;

/** core/migraciones.php: lectura de los archivos SQL y coherencia con schema.sql. */
final class MigracionesTest extends TestCase
{
    public function testDivideEnSentenciasYQuitaComentarios(): void
    {
        $sql = "-- comentario\nALTER TABLE a\n  MODIFY b INT;\n\n-- otro\nUPDATE a SET c = 'x' WHERE d = ''''; \nINSERT INTO a VALUES (1)";
        $this->assertSame([
            "ALTER TABLE a\n  MODIFY b INT",
            "UPDATE a SET c = 'x' WHERE d = ''''",
            "INSERT INTO a VALUES (1)",
        ], sentenciasSql($sql));
    }

    public function testLasMigracionesEstanNumeradasYEnOrden(): void
    {
        $nombres = migracionesDisponibles();
        $this->assertNotEmpty($nombres);
        foreach ($nombres as $nombre) {
            $this->assertMatchesRegularExpression('/^\d{3}_[a-z0-9_]+\.sql$/', $nombre);
        }
    }

    public function testSchemaSqlApuntaTodasLasMigracionesComoAplicadas(): void
    {
        // Una base de datos nueva se crea con schema.sql, que ya incluye todos los
        // cambios: si falta una migración en su lista, se aplicaría encima otra vez
        $schema = file_get_contents(__DIR__ . '/../../database/schema.sql');
        foreach (migracionesDisponibles() as $nombre) {
            $this->assertStringContainsString("('$nombre')", $schema, "Añade $nombre a la lista del final de database/schema.sql");
        }
    }
}
