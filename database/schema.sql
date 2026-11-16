-- KaloAI · Esquema de la base de datos (MySQL 8 / MariaDB 10.6+)
--
-- Parte del volcado de producción, con dos cambios respecto a él
-- (para una base ya existente, aplica database/migraciones/001_perfil_y_solicitudes.sql):
--   1. perfil.fecha_nacimiento y perfil.genero admiten NULL: el registro crea el
--      perfil solo con el nombre y el resto se rellena después en "Mi perfil".
--   2. solicitudes_vinculacion se borra en cascada también al borrar al nutricionista.
--
-- Uso: mysql -u root -p kaloai_db < database/schema.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS migraciones, comida_planificada, detalle_receta, ingrediente_prohibido, inventario,
    logs_actividad, mensaje_contacto, nota_paciente, perfil, plan_semanal, progreso, receta, registro_comida, restablecimiento_contrasena, rol, solicitudes_vinculacion, usuario;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE rol (
  id_rol INT NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(50) NOT NULL,
  PRIMARY KEY (id_rol),
  UNIQUE KEY nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Los ids de rol están fijados en el código (1 admin, 2 nutricionista, 3 usuario)
INSERT INTO rol (id_rol, nombre) VALUES (1, 'administrador'), (2, 'nutricionista'), (3, 'usuario');

CREATE TABLE usuario (
  id_usuario INT NOT NULL AUTO_INCREMENT,
  id_rol INT NOT NULL,
  correo VARCHAR(255) NOT NULL,
  contrasena_hash VARCHAR(255) NOT NULL,
  fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  activo TINYINT(1) DEFAULT 1,
  PRIMARY KEY (id_usuario),
  UNIQUE KEY correo (correo),
  KEY fk_usuario_rol (id_rol),
  CONSTRAINT fk_usuario_rol FOREIGN KEY (id_rol) REFERENCES rol (id_rol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE perfil (
  id_perfil INT NOT NULL AUTO_INCREMENT,
  id_usuario INT NOT NULL,
  id_nutricionista INT DEFAULT NULL,
  nombre VARCHAR(100) NOT NULL,
  fecha_nacimiento DATE DEFAULT NULL,
  genero VARCHAR(50) DEFAULT NULL,
  altura_cm SMALLINT UNSIGNED DEFAULT NULL,
  peso_kg DECIMAL(6,2) DEFAULT NULL,
  nivel_actividad ENUM('sedentario','ligero','moderado','activo','muy_activo') DEFAULT NULL,
  objetivo ENUM('perdida_rapida','perdida_moderada','perdida_grasa','mantenimiento','ganancia_muscular','recomposicion_corporal','rendimiento_deportivo') NOT NULL DEFAULT 'mantenimiento',
  comensales TINYINT UNSIGNED NOT NULL DEFAULT 1,
  tdee_calorias INT DEFAULT NULL,
  proteinas_objetivo_g INT DEFAULT NULL,
  carbohidratos_objetivo_g INT DEFAULT NULL,
  grasas_objetivo_g INT DEFAULT NULL,
  calorias_netas_objetivo INT DEFAULT NULL,
  fecha_actualizacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_perfil),
  UNIQUE KEY id_usuario (id_usuario),
  KEY fk_perfil_nutricionista (id_nutricionista),
  CONSTRAINT fk_perfil_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario) ON DELETE CASCADE,
  CONSTRAINT fk_perfil_nutricionista FOREIGN KEY (id_nutricionista) REFERENCES usuario (id_usuario) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ingrediente_prohibido (
  id_ingrediente_prohibido INT NOT NULL AUTO_INCREMENT,
  id_usuario INT NOT NULL,
  nombre_ingrediente VARCHAR(200) NOT NULL,
  nota VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id_ingrediente_prohibido),
  UNIQUE KEY uq_ing_prohibido (id_usuario, nombre_ingrediente),
  CONSTRAINT fk_ing_prohibido_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventario (
  id_inventario INT NOT NULL AUTO_INCREMENT,
  id_usuario INT NOT NULL,
  nombre_ingrediente VARCHAR(200) NOT NULL,
  cantidad DECIMAL(10,3) DEFAULT 0.000,
  unidad VARCHAR(50) DEFAULT 'unidad',
  fecha_actualizacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_inventario),
  KEY fk_inventario_usuario (id_usuario),
  CONSTRAINT fk_inventario_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE logs_actividad (
  id_log INT NOT NULL AUTO_INCREMENT,
  detalle TEXT DEFAULT NULL,
  fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_log)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE receta (
  id_receta INT NOT NULL AUTO_INCREMENT,
  id_usuario_creador INT DEFAULT NULL,
  titulo VARCHAR(200) NOT NULL,
  tipo_comida ENUM('Desayuno','Almuerzo','Cena','Snack','Postre') DEFAULT 'Almuerzo',
  descripcion TEXT DEFAULT NULL,
  calorias_por_racion INT DEFAULT NULL,
  proteinas_g_por_racion DECIMAL(7,2) DEFAULT NULL,
  carbohidratos_g_por_racion DECIMAL(7,2) DEFAULT NULL,
  grasas_g_por_racion DECIMAL(7,2) DEFAULT NULL,
  generado_ia TINYINT(1) DEFAULT 1,
  favorita TINYINT(1) NOT NULL DEFAULT 0,
  fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_receta),
  KEY fk_receta_creador (id_usuario_creador),
  CONSTRAINT fk_receta_creador FOREIGN KEY (id_usuario_creador) REFERENCES usuario (id_usuario) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE detalle_receta (
  id_receta INT NOT NULL,
  nombre_ingrediente VARCHAR(200) NOT NULL,
  cantidad DECIMAL(10,3) DEFAULT 0.000,
  unidad VARCHAR(50) DEFAULT 'unidad',
  PRIMARY KEY (id_receta, nombre_ingrediente),
  CONSTRAINT fk_detalle_receta FOREIGN KEY (id_receta) REFERENCES receta (id_receta) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE plan_semanal (
  id_plan INT NOT NULL AUTO_INCREMENT,
  id_usuario INT NOT NULL,
  fecha_inicio_semana DATE NOT NULL,
  fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_plan),
  UNIQUE KEY uq_usuario_semana (id_usuario, fecha_inicio_semana),
  CONSTRAINT fk_plan_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE comida_planificada (
  id_comida_planificada INT NOT NULL AUTO_INCREMENT,
  id_plan INT NOT NULL,
  id_receta INT NOT NULL,
  dia_semana TINYINT UNSIGNED NOT NULL COMMENT '0 = lunes ... 6 = domingo',
  momento_comida ENUM('Desayuno','Media Mañana','Almuerzo','Merienda','Cena') NOT NULL,
  raciones DECIMAL(6,2) DEFAULT 1.00,
  PRIMARY KEY (id_comida_planificada),
  KEY fk_comida_plan (id_plan),
  KEY fk_comida_receta (id_receta),
  CONSTRAINT fk_comida_plan FOREIGN KEY (id_plan) REFERENCES plan_semanal (id_plan) ON DELETE CASCADE,
  CONSTRAINT fk_comida_receta FOREIGN KEY (id_receta) REFERENCES receta (id_receta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE progreso (
  id_progreso INT NOT NULL AUTO_INCREMENT,
  id_usuario INT NOT NULL,
  fecha DATE NOT NULL,
  peso_kg DECIMAL(6,2) DEFAULT NULL,
  cintura_cm DECIMAL(6,2) DEFAULT NULL,
  nota VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id_progreso),
  UNIQUE KEY uq_progreso_usuario_fecha (id_usuario, fecha),
  CONSTRAINT fk_progreso_usuario FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE solicitudes_vinculacion (
  id_solicitud INT NOT NULL AUTO_INCREMENT,
  id_nutricionista INT NOT NULL,
  id_paciente INT NOT NULL,
  estado ENUM('pendiente','aceptada','rechazada') DEFAULT 'pendiente',
  fecha_solicitud TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_solicitud),
  KEY id_nutricionista (id_nutricionista),
  KEY solicitudes_vinculacion_ibfk_2 (id_paciente),
  CONSTRAINT solicitudes_vinculacion_ibfk_1 FOREIGN KEY (id_nutricionista) REFERENCES usuario (id_usuario) ON DELETE CASCADE,
  CONSTRAINT solicitudes_vinculacion_ibfk_2 FOREIGN KEY (id_paciente) REFERENCES usuario (id_usuario) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lo que se come de verdad cada día (migración 003)
CREATE TABLE registro_comida (
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

-- Enlaces para recuperar la contraseña (migración 005)
CREATE TABLE restablecimiento_contrasena (
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

-- Notas del nutricionista a su paciente (migración 007)
CREATE TABLE nota_paciente (
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

-- Mensajes del formulario de contacto (migración 008)
CREATE TABLE mensaje_contacto (
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

-- Registro de migraciones (core/migraciones.php). Este esquema ya incluye los
-- cambios de las que se listan aquí, así que se apuntan como aplicadas.
CREATE TABLE migraciones (
  nombre VARCHAR(190) NOT NULL,
  aplicada_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO migraciones (nombre) VALUES
  ('001_perfil_y_solicitudes.sql'),
  ('002_texto_sin_escapar.sql'),
  ('003_registro_comida.sql'),
  ('004_un_plan_por_semana.sql'),
  ('005_restablecer_contrasena.sql'),
  ('006_favoritas_y_comensales.sql'),
  ('007_notas_del_nutricionista.sql'),
  ('008_mensajes_de_contacto.sql');
