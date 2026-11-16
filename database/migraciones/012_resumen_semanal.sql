-- Migración 012 · resumen semanal por correo
--
-- Cada lunes, quien lo active en su perfil recibe un correo con su semana:
-- lo que comió frente a su objetivo, el cambio de peso y un enlace para
-- preparar la nueva semana. Está desactivado por defecto (lo activa cada
-- persona) y cada correo lleva un enlace para darse de baja sin iniciar sesión.
--
-- ultimo_resumen: lunes de la última semana resumida (evita enviarlo dos veces).
-- token_baja: identifica a la persona en el enlace de baja.

ALTER TABLE perfil ADD COLUMN IF NOT EXISTS resumen_semanal TINYINT(1) NOT NULL DEFAULT 0 AFTER comensales;
ALTER TABLE perfil ADD COLUMN IF NOT EXISTS ultimo_resumen DATE DEFAULT NULL AFTER resumen_semanal;
ALTER TABLE perfil ADD COLUMN IF NOT EXISTS token_baja CHAR(32) DEFAULT NULL AFTER ultimo_resumen;
ALTER TABLE perfil ADD UNIQUE KEY IF NOT EXISTS uq_perfil_token_baja (token_baja);
