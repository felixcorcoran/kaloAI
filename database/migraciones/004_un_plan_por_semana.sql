-- Migración 004 · un plan por semana
--
-- Hasta ahora cada persona tenía un único plan que se sobrescribía, y la app
-- mostraba juntas las comidas de todos sus planes. Desde ahora hay un plan por
-- semana (fecha_inicio_semana = lunes de esa semana; la tabla ya impide dos
-- planes de la misma persona para la misma semana), así se puede volver a
-- semanas pasadas y preparar las siguientes.
--
-- Para no cambiar lo que cada persona ve hoy:
--   1. Las comidas de todos sus planes se juntan en el primero.
--   2. Los demás planes, ya vacíos, se borran.
--   3. Ese plan pasa a ser el de la semana actual.

UPDATE comida_planificada cp
JOIN plan_semanal ps ON ps.id_plan = cp.id_plan
JOIN (SELECT id_usuario, MIN(id_plan) AS id_primero FROM plan_semanal GROUP BY id_usuario) p ON p.id_usuario = ps.id_usuario
SET cp.id_plan = p.id_primero
WHERE cp.id_plan <> p.id_primero;

DELETE ps FROM plan_semanal ps
JOIN (SELECT id_usuario, MIN(id_plan) AS id_primero FROM plan_semanal GROUP BY id_usuario) p ON p.id_usuario = ps.id_usuario
WHERE ps.id_plan <> p.id_primero;

UPDATE plan_semanal SET fecha_inicio_semana = DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY);
