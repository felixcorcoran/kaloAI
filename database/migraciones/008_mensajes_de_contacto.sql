-- Migración 008 · mensajes del formulario de contacto
--
-- Antes el formulario no enviaba nada. Ahora cada mensaje se guarda aquí y el
-- administrador lo ve en su panel (y, si está configurado, le llega por correo).

CREATE TABLE IF NOT EXISTS mensaje_contacto (
  id_mensaje INT NOT NULL AUTO_INCREMENT,
  id_usuario INT DEFAULT NULL,
  nombre VARCHAR(100) NOT NULL,
  correo VARCHAR(255) NOT NULL,
  mensaje TEXT NOT NULL,
  leido TINYINT(1) NOT NULL DEFAULT 0,
  fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_mensaje),
  KEY idx_mensaje_leido (leido, fecha),
  KEY fk_mensaje_usuario (id_usuario),
  CONSTRAINT fk_mensaje_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
