-- Migración 011 · compartir la lista de la compra
--
-- Un enlace de solo lectura para otra persona de la casa: abre la lista de la
-- compra de esta semana (y de la siguiente) sin iniciar sesión. Cada persona
-- tiene como mucho un enlace; dejar de compartir lo borra y uno nuevo deja
-- inservible el anterior.
-- El token se guarda tal cual (no un hash) para poder volver a copiarlo desde
-- la lista: solo da acceso a leer la lista de la compra, nada más.

CREATE TABLE IF NOT EXISTS lista_compartida (
  id_usuario INT NOT NULL,
  token CHAR(32) NOT NULL,
  fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultima_visita TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id_usuario),
  UNIQUE KEY uq_lista_token (token),
  CONSTRAINT fk_lista_compartida_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
