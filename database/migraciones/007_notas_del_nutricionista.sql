-- Migración 007 · notas del nutricionista a su paciente
--
-- El nutricionista deja notas (consejos, ajustes del plan, ánimo) desde la
-- ficha del paciente; el paciente las ve en su panel «Hoy».

CREATE TABLE IF NOT EXISTS nota_paciente (
  id_nota INT NOT NULL AUTO_INCREMENT,
  id_nutricionista INT NOT NULL,
  id_paciente INT NOT NULL,
  texto TEXT NOT NULL,
  leida TINYINT(1) NOT NULL DEFAULT 0,
  fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_nota),
  KEY idx_nota_paciente (id_paciente, fecha),
  KEY fk_nota_nutricionista (id_nutricionista),
  CONSTRAINT fk_nota_nutricionista FOREIGN KEY (id_nutricionista) REFERENCES usuario (id_usuario) ON DELETE CASCADE,
  CONSTRAINT fk_nota_paciente FOREIGN KEY (id_paciente) REFERENCES usuario (id_usuario) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
