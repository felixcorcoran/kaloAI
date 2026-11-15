-- KaloAI · Datos de ejemplo para desarrollo local (todo inventado)
-- Cárgalos después de schema.sql: mysql -u root -p kaloai_db < database/datos_demo.sql
--
-- Las tres cuentas tienen la contraseña: kaloai1234
--   admin@kaloai.test    administración
--   nutri@kaloai.test    nutricionista, con Laura como paciente
--   laura@kaloai.test    usuaria con perfil, inventario, recetas y plan
--   marcos@kaloai.test   usuario recién registrado (perfil vacío) con una invitación pendiente

SET NAMES utf8mb4;

INSERT INTO usuario (id_usuario, id_rol, correo, contrasena_hash) VALUES
  (1, 1, 'admin@kaloai.test',  '$2y$10$ABvjAwfI9TLPYUpe1jZEHu7DmQ3JMDvP6fdxCL1WD1h91PJGguPai'),
  (2, 2, 'nutri@kaloai.test',  '$2y$10$ABvjAwfI9TLPYUpe1jZEHu7DmQ3JMDvP6fdxCL1WD1h91PJGguPai'),
  (3, 3, 'laura@kaloai.test',  '$2y$10$ABvjAwfI9TLPYUpe1jZEHu7DmQ3JMDvP6fdxCL1WD1h91PJGguPai'),
  (4, 3, 'marcos@kaloai.test', '$2y$10$ABvjAwfI9TLPYUpe1jZEHu7DmQ3JMDvP6fdxCL1WD1h91PJGguPai');

INSERT INTO perfil (id_usuario, id_nutricionista, nombre, fecha_nacimiento, genero, altura_cm, peso_kg,
                    nivel_actividad, objetivo, tdee_calorias, proteinas_objetivo_g, carbohidratos_objetivo_g,
                    grasas_objetivo_g, calorias_netas_objetivo) VALUES
  (1, NULL, 'Ana Admin',      NULL, NULL, NULL, NULL, NULL, 'mantenimiento', NULL, NULL, NULL, NULL, NULL),
  (2, NULL, 'Nuria Nutri',    NULL, NULL, NULL, NULL, NULL, 'mantenimiento', NULL, NULL, NULL, NULL, NULL),
  (3, 2,    'Laura Martín',   '1995-04-12', 'F', 168, 64.50, 'moderado', 'perdida_grasa', 2137, 156, 179, 50, 1787),
  (4, NULL, 'Marcos Ruiz',    NULL, NULL, NULL, NULL, NULL, 'mantenimiento', NULL, NULL, NULL, NULL, NULL);

INSERT INTO ingrediente_prohibido (id_usuario, nombre_ingrediente, nota) VALUES
  (3, 'Cacahuete', 'Alergia (Riesgo Alto)'),
  (3, 'Lactosa',   'Intolerancia (Riesgo Medio)');

INSERT INTO inventario (id_usuario, nombre_ingrediente, cantidad, unidad) VALUES
  (3, 'Pechuga De Pollo', 500, 'g'),
  (3, 'Arroz Integral',   1,   'kg'),
  (3, 'Avena',            400, 'g'),
  (3, 'Aceite De Oliva',  750, 'ml'),
  (3, 'Huevo',            6,   'unidad');

INSERT INTO receta (id_receta, id_usuario_creador, titulo, tipo_comida, descripcion, calorias_por_racion,
                    proteinas_g_por_racion, carbohidratos_g_por_racion, grasas_g_por_racion, generado_ia) VALUES
  (1, 3, 'Porridge de avena y plátano', 'Desayuno',
   '1. Calienta la bebida vegetal.\n2. Añade la avena y remueve 5 minutos.\n3. Sirve con el plátano en rodajas.',
   380, 14.00, 62.00, 8.00, 1),
  (2, 3, 'Pollo con arroz integral y verduras', 'Almuerzo',
   '1. Cuece el arroz.\n2. Saltea el pollo en dados con el aceite.\n3. Añade las verduras y mezcla con el arroz.',
   620, 48.00, 70.00, 14.00, 1),
  (3, 3, 'Tortilla francesa con ensalada', 'Cena',
   '1. Bate los huevos y cuájalos en la sartén.\n2. Acompaña con la ensalada aliñada.',
   410, 26.00, 12.00, 28.00, 0);

INSERT INTO detalle_receta (id_receta, nombre_ingrediente, cantidad, unidad) VALUES
  (1, 'Avena', 60, 'g'), (1, 'Plátano', 100, 'g'), (1, 'Bebida De Avena', 200, 'ml'),
  (2, 'Pechuga De Pollo', 150, 'g'), (2, 'Arroz Integral', 80, 'g'), (2, 'Calabacín', 120, 'g'),
  (2, 'Aceite De Oliva', 10, 'ml'),
  (3, 'Huevo', 3, 'unidad'), (3, 'Lechuga', 80, 'g'), (3, 'Tomate', 100, 'g'),
  (3, 'Aceite De Oliva', 10, 'ml');

-- Un plan por persona y semana; la fecha es el lunes de esa semana.
-- Laura tiene el de esta semana (1) y el de la semana pasada (2).
INSERT INTO plan_semanal (id_plan, id_usuario, fecha_inicio_semana) VALUES
  (1, 3, DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)),
  (2, 3, DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 7 DAY));

-- dia_semana: 0 = lunes ... 6 = domingo. Rellena toda la semana para que "Hoy" siempre tenga datos.
INSERT INTO comida_planificada (id_plan, id_receta, dia_semana, momento_comida, raciones) VALUES
  (1, 1, 0, 'Desayuno', 1), (1, 2, 0, 'Almuerzo', 1), (1, 3, 0, 'Cena', 1),
  (1, 1, 1, 'Desayuno', 1), (1, 2, 1, 'Almuerzo', 1), (1, 3, 1, 'Cena', 1),
  (1, 1, 2, 'Desayuno', 1), (1, 2, 2, 'Almuerzo', 1), (1, 3, 2, 'Cena', 1),
  (1, 1, 3, 'Desayuno', 1), (1, 2, 3, 'Almuerzo', 1), (1, 3, 3, 'Cena', 1),
  (1, 1, 4, 'Desayuno', 1), (1, 2, 4, 'Almuerzo', 1), (1, 3, 4, 'Cena', 1),
  (1, 1, 5, 'Desayuno', 1), (1, 2, 5, 'Almuerzo', 1),
  (1, 1, 6, 'Desayuno', 1), (1, 3, 6, 'Cena', 1);

-- La semana pasada: desayuno, almuerzo y cena de lunes a viernes
INSERT INTO comida_planificada (id_plan, id_receta, dia_semana, momento_comida, raciones) VALUES
  (2, 1, 0, 'Desayuno', 1), (2, 2, 0, 'Almuerzo', 1), (2, 3, 0, 'Cena', 1),
  (2, 1, 1, 'Desayuno', 1), (2, 2, 1, 'Almuerzo', 1), (2, 3, 1, 'Cena', 1),
  (2, 1, 2, 'Desayuno', 1), (2, 2, 2, 'Almuerzo', 1), (2, 3, 2, 'Cena', 1),
  (2, 1, 3, 'Desayuno', 1), (2, 2, 3, 'Almuerzo', 1), (2, 3, 3, 'Cena', 1),
  (2, 1, 4, 'Desayuno', 1), (2, 2, 4, 'Almuerzo', 1), (2, 3, 4, 'Cena', 1);

INSERT INTO progreso (id_usuario, fecha, peso_kg, cintura_cm, nota) VALUES
  (3, DATE_SUB(CURDATE(), INTERVAL 28 DAY), 66.80, 78.0, 'Empiezo el plan'),
  (3, DATE_SUB(CURDATE(), INTERVAL 21 DAY), 66.10, 77.5, NULL),
  (3, DATE_SUB(CURDATE(), INTERVAL 14 DAY), 65.40, 76.8, NULL),
  (3, DATE_SUB(CURDATE(), INTERVAL 7 DAY),  64.90, 76.2, 'Más energía en los entrenos'),
  (3, CURDATE(),                            64.50, 75.9, NULL);

-- Lo que Laura comió la semana pasada (lunes a jueves; el viernes se saltó la cena)
INSERT INTO registro_comida (id_usuario, fecha, momento_comida, id_receta, descripcion, calorias, proteinas_g, carbohidratos_g, grasas_g) VALUES
  (3, DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 7 DAY), 'Desayuno', 1, 'Porridge de avena y plátano', 380, 14, 62, 8),
  (3, DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 7 DAY), 'Almuerzo', 2, 'Pollo con arroz integral y verduras', 620, 48, 70, 14),
  (3, DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 7 DAY), 'Cena',     3, 'Tortilla francesa con ensalada', 410, 26, 12, 28),
  (3, DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 6 DAY), 'Desayuno', 1, 'Porridge de avena y plátano', 380, 14, 62, 8),
  (3, DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 6 DAY), 'Almuerzo', 2, 'Pollo con arroz integral y verduras', 620, 48, 70, 14),
  (3, DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 6 DAY), 'Merienda', NULL, 'Tostada con aguacate', 260, 6, 24, 16),
  (3, DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 6 DAY), 'Cena',     3, 'Tortilla francesa con ensalada', 410, 26, 12, 28),
  (3, DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 5 DAY), 'Desayuno', 1, 'Porridge de avena y plátano', 380, 14, 62, 8),
  (3, DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 5 DAY), 'Almuerzo', NULL, 'Menú del día en el trabajo', 950, 40, 105, 38),
  (3, DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 5 DAY), 'Cena',     3, 'Tortilla francesa con ensalada', 410, 26, 12, 28),
  (3, DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 4 DAY), 'Desayuno', 1, 'Porridge de avena y plátano', 380, 14, 62, 8),
  (3, DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 4 DAY), 'Almuerzo', 2, 'Pollo con arroz integral y verduras', 620, 48, 70, 14),
  (3, DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 4 DAY), 'Cena',     3, 'Tortilla francesa con ensalada', 410, 26, 12, 28),
  (3, DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 3 DAY), 'Desayuno', 1, 'Porridge de avena y plátano', 380, 14, 62, 8),
  (3, DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 3 DAY), 'Almuerzo', 2, 'Pollo con arroz integral y verduras', 620, 48, 70, 14);

-- Lo que Laura ha comido de verdad los últimos días (y el desayuno de hoy)
INSERT INTO registro_comida (id_usuario, fecha, momento_comida, id_receta, descripcion, calorias, proteinas_g, carbohidratos_g, grasas_g) VALUES
  (3, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'Desayuno', 1, 'Porridge de avena y plátano', 380, 14, 62, 8),
  (3, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'Almuerzo', 2, 'Pollo con arroz integral y verduras', 620, 48, 70, 14),
  (3, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'Cena',     3, 'Tortilla francesa con ensalada', 410, 26, 12, 28),
  (3, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Desayuno', 1, 'Porridge de avena y plátano', 380, 14, 62, 8),
  (3, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Almuerzo', 2, 'Pollo con arroz integral y verduras', 620, 48, 70, 14),
  (3, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Cena',     NULL, 'Pizza con amigos', 850, 32, 95, 36),
  (3, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Desayuno', 1, 'Porridge de avena y plátano', 380, 14, 62, 8),
  (3, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Almuerzo', 2, 'Pollo con arroz integral y verduras', 620, 48, 70, 14),
  (3, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Merienda', NULL, 'Yogur griego con nueces', 220, 12, 9, 15),
  (3, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Cena',     3, 'Tortilla francesa con ensalada', 410, 26, 12, 28),
  (3, CURDATE(),                           'Desayuno', 1, 'Porridge de avena y plátano', 380, 14, 62, 8);

INSERT INTO solicitudes_vinculacion (id_nutricionista, id_paciente, estado) VALUES
  (2, 3, 'aceptada'),
  (2, 4, 'pendiente');

INSERT INTO logs_actividad (detalle) VALUES ('Base de datos de ejemplo cargada');
