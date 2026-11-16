-- Migración 003 · registro de lo que se come de verdad
--
-- Cada fila es una comida tomada un día concreto: una del plan marcada como
-- «Hecho» (con id_receta) o algo fuera del plan escrito a mano. Las calorías
-- y los macros se copian al registrar, así el historial no cambia si luego se
-- edita o se borra la receta.

CREATE TABLE IF NOT EXISTS registro_comida (
  id_registro INT NOT NULL AUTO_INCREMENT,
  id_usuario INT NOT NULL,
  fecha DATE NOT NULL,
  momento_comida ENUM('Desayuno','Media Mañana','Almuerzo','Merienda','Cena') NOT NULL,
  id_receta INT DEFAULT NULL,
  descripcion VARCHAR(200) NOT NULL,
  raciones DECIMAL(6,2) NOT NULL DEFAULT 1.00,
  calorias INT NOT NULL DEFAULT 0,
  proteinas_g DECIMAL(6,1) NOT NULL DEFAULT 0,
  carbohidratos_g DECIMAL(6,1) NOT NULL DEFAULT 0,
  grasas_g DECIMAL(6,1) NOT NULL DEFAULT 0,
  fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_registro),
  KEY idx_registro_usuario_fecha (id_usuario, fecha),
  KEY fk_registro_receta (id_receta),
  CONSTRAINT fk_registro_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario) ON DELETE CASCADE,
  CONSTRAINT fk_registro_receta FOREIGN KEY (id_receta) REFERENCES receta (id_receta) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
