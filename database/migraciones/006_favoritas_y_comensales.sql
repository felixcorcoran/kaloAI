-- Migración 006 · recetas favoritas y comensales
--
-- receta.favorita: la persona marca sus recetas favoritas (salen primero al
-- elegir una receta para el plan y tienen su propio filtro).
-- perfil.comensales: para cuántas personas se cocina en casa. La lista de la
-- compra multiplica las cantidades; las calorías siguen siendo las de una ración.

ALTER TABLE receta ADD COLUMN IF NOT EXISTS favorita TINYINT(1) NOT NULL DEFAULT 0 AFTER generado_ia;
ALTER TABLE perfil ADD COLUMN IF NOT EXISTS comensales TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER objetivo;
