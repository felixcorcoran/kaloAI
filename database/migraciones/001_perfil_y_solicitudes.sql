-- Migración 001 · para la base de datos de producción ya existente
-- Ejecútala una vez desde phpMyAdmin (pestaña SQL). No borra ningún dato.

-- 1. El registro crea el perfil sin fecha de nacimiento ni género: deben admitir NULL.
ALTER TABLE perfil
  MODIFY fecha_nacimiento DATE DEFAULT NULL,
  MODIFY genero VARCHAR(50) DEFAULT NULL;

-- Perfiles creados en modo no estricto con valores vacíos: se dejan en NULL.
UPDATE perfil SET fecha_nacimiento = NULL WHERE fecha_nacimiento = '0000-00-00';
UPDATE perfil SET genero = NULL WHERE genero = '';

-- 2. Al borrar a un nutricionista se borran también sus solicitudes.
ALTER TABLE solicitudes_vinculacion DROP FOREIGN KEY solicitudes_vinculacion_ibfk_1;
ALTER TABLE solicitudes_vinculacion
  ADD CONSTRAINT solicitudes_vinculacion_ibfk_1 FOREIGN KEY (id_nutricionista)
  REFERENCES usuario (id_usuario) ON DELETE CASCADE;

-- 3. Los roles que usa el código (solo inserta los que falten).
INSERT IGNORE INTO rol (id_rol, nombre) VALUES (1, 'administrador'), (2, 'nutricionista'), (3, 'usuario');
