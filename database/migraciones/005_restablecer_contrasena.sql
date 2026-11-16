-- Migración 005 · recuperar la contraseña por correo
--
-- Cada fila es un enlace de «He olvidado mi contraseña». Se guarda solo el
-- hash SHA-256 del token (el token en claro solo viaja en el correo), caduca
-- en una hora y sirve una única vez.

CREATE TABLE IF NOT EXISTS restablecimiento_contrasena (
  id_restablecimiento INT NOT NULL AUTO_INCREMENT,
  id_usuario INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expira DATETIME NOT NULL,
  usado TINYINT(1) NOT NULL DEFAULT 0,
  fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_restablecimiento),
  UNIQUE KEY uq_restablecimiento_token (token_hash),
  KEY idx_restablecimiento_usuario (id_usuario, fecha_creacion),
  CONSTRAINT fk_restablecimiento_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
