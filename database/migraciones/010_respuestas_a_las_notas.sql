-- Migración 010 · el paciente responde a las notas de su nutricionista
--
-- Las notas pasan a ser una conversación sencilla: de_paciente = 1 marca los
-- mensajes que escribe el paciente. id_nutricionista sigue siendo el
-- nutricionista de la conversación (el destinatario, en ese caso), y «leida»
-- indica si lo ha leído quien lo recibe.

ALTER TABLE nota_paciente ADD COLUMN IF NOT EXISTS de_paciente TINYINT(1) NOT NULL DEFAULT 0 AFTER texto;
ALTER TABLE nota_paciente ADD KEY IF NOT EXISTS idx_nota_sin_leer (id_nutricionista, de_paciente, leida);
