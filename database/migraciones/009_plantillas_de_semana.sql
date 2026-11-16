-- Migración 009 · plantillas de semana
--
-- La persona guarda una semana que le ha ido bien («Semana de entreno»,
-- «Semana ligera») y la aplica después a otra semana en un clic. Una plantilla
-- es una copia de las comidas (receta, día, momento y raciones), no un enlace
-- a la semana original: cambiar esa semana no cambia la plantilla.
-- Si se borra una receta, desaparece también de las plantillas.

CREATE TABLE IF NOT EXISTS plantilla_semana (
  id_plantilla INT NOT NULL AUTO_INCREMENT,
  id_usuario INT NOT NULL,
  nombre VARCHAR(60) NOT NULL,
  fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_plantilla),
  KEY fk_plantilla_usuario (id_usuario),
  CONSTRAINT fk_plantilla_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plantilla_comida (
  id_plantilla_comida INT NOT NULL AUTO_INCREMENT,
  id_plantilla INT NOT NULL,
  id_receta INT NOT NULL,
  dia_semana TINYINT UNSIGNED NOT NULL COMMENT '0 = lunes ... 6 = domingo',
  momento_comida ENUM('Desayuno','Media Mañana','Almuerzo','Merienda','Cena') NOT NULL,
  raciones DECIMAL(6,2) NOT NULL DEFAULT 1.00,
  PRIMARY KEY (id_plantilla_comida),
  UNIQUE KEY uq_plantilla_hueco (id_plantilla, dia_semana, momento_comida),
  KEY fk_plantilla_receta (id_receta),
  CONSTRAINT fk_plantilla_comida_plantilla FOREIGN KEY (id_plantilla) REFERENCES plantilla_semana (id_plantilla) ON DELETE CASCADE,
  CONSTRAINT fk_plantilla_comida_receta FOREIGN KEY (id_receta) REFERENCES receta (id_receta) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
