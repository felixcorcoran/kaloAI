-- Migración 002 · deshace el escapado HTML guardado en la base de datos
--
-- Antes, limpiarInput() convertía el texto a HTML antes de guardarlo
-- ("Pan & aceite" se guardaba como "Pan &amp; aceite"). Ahora el texto se
-- guarda tal cual y se escapa al mostrarlo, así que los datos antiguos se
-- devuelven a su forma original. Ejecútala una vez, después de subir el código
-- nuevo. Si una fila no tiene entidades, no cambia.
--
-- &amp; va el último para no convertir "&amp;lt;" en "<".

UPDATE perfil SET nombre =
  REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(nombre, '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&#039;', ''''), '&amp;', '&');

UPDATE usuario SET correo =
  REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(correo, '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&#039;', ''''), '&amp;', '&');

UPDATE inventario SET
  nombre_ingrediente = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(nombre_ingrediente, '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&#039;', ''''), '&amp;', '&'),
  unidad             = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(unidad, '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&#039;', ''''), '&amp;', '&');

UPDATE ingrediente_prohibido SET nombre_ingrediente =
  REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(nombre_ingrediente, '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&#039;', ''''), '&amp;', '&');

UPDATE progreso SET nota =
  REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(nota, '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&#039;', ''''), '&amp;', '&')
  WHERE nota IS NOT NULL;
