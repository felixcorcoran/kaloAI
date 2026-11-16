<?php
/**
 * SEGUIMIENTO DE PACIENTES (para el nutricionista)
 * Ficha con la evolución del paciente sin entrar en su cuenta, y notas que el
 * paciente lee en su panel.
 */
require_once __DIR__ . '/recetas.php';
require_once __DIR__ . '/registro.php';

const NOTA_MAX_CARACTERES = 1000;

/** ¿Este paciente está vinculado a este nutricionista? */
function esPacienteDe(PDO $pdo, int $nutriId, int $pacienteId): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM perfil WHERE id_usuario = ? AND id_nutricionista = ?");
    $stmt->execute([$pacienteId, $nutriId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Días con algo apuntado entre las últimas N fechas, y calorías de cada día.
 * Devuelve ['dias' => ['Y-m-d' => kcal|null, ...], 'con_registro' => n]. Función pura
 * a partir de lo consumido por fecha.
 */
function resumenConsumo(array $consumoPorFecha, string $hoy, int $dias): array {
    $serie = [];
    $fin = new DateTimeImmutable($hoy);
    for ($i = $dias - 1; $i >= 0; $i--) {
        $f = $fin->modify("-$i days")->format('Y-m-d');
        $serie[$f] = isset($consumoPorFecha[$f]) ? (float) $consumoPorFecha[$f] : null;
    }
    $conRegistro = array_filter($serie, fn($k) => $k !== null);
    return [
        'dias' => $serie,
        'con_registro' => count($conRegistro),
        'media' => $conRegistro ? array_sum($conRegistro) / count($conRegistro) : null,
    ];
}

/** Todo lo que se muestra en la ficha del paciente. */
function datosSeguimiento(PDO $pdo, int $pacienteId, ?string $hoy = null): array {
    $hoy = $hoy ?? date('Y-m-d');
    $consulta = function (string $sql, array $p) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s; };

    $perfil = $consulta("SELECT p.*, u.correo, u.fecha_creacion FROM perfil p JOIN usuario u ON u.id_usuario = p.id_usuario WHERE p.id_usuario = ?", [$pacienteId])->fetch(PDO::FETCH_ASSOC) ?: [];
    $progreso = $consulta("SELECT fecha, peso_kg, cintura_cm, nota FROM progreso WHERE id_usuario = ? ORDER BY fecha", [$pacienteId])->fetchAll(PDO::FETCH_ASSOC);

    $desde = (new DateTimeImmutable($hoy))->modify('-13 days')->format('Y-m-d');
    $consumo = resumenConsumo(caloriasConsumidasPorDia($pdo, $pacienteId, $desde, $hoy), $hoy, 14);

    $lunes = lunesDe($hoy);
    $plan = array_fill(0, 7, 0.0);
    foreach ($consulta("SELECT cp.dia_semana, SUM(r.calorias_por_racion * cp.raciones) AS kcal FROM comida_planificada cp
                        JOIN receta r ON r.id_receta = cp.id_receta JOIN plan_semanal ps ON ps.id_plan = cp.id_plan
                        WHERE ps.id_usuario = ? AND ps.fecha_inicio_semana = ? GROUP BY cp.dia_semana", [$pacienteId, $lunes])->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $plan[(int) $f['dia_semana']] = (float) $f['kcal'];
    }
    $comidasPlan = (int) $consulta("SELECT COUNT(*) FROM comida_planificada cp JOIN plan_semanal ps ON ps.id_plan = cp.id_plan
                                    WHERE ps.id_usuario = ? AND ps.fecha_inicio_semana = ?", [$pacienteId, $lunes])->fetchColumn();

    $recientes = $consulta("SELECT fecha, momento_comida, descripcion, calorias, id_receta FROM registro_comida
                            WHERE id_usuario = ? ORDER BY fecha DESC, id_registro DESC LIMIT 8", [$pacienteId])->fetchAll(PDO::FETCH_ASSOC);
    $restricciones = $consulta("SELECT nombre_ingrediente, nota FROM ingrediente_prohibido WHERE id_usuario = ? ORDER BY nombre_ingrediente", [$pacienteId])->fetchAll(PDO::FETCH_ASSOC);

    return compact('perfil', 'progreso', 'consumo', 'plan', 'comidasPlan', 'recientes', 'restricciones', 'lunes');
}

/** Valida y guarda una nota. Devuelve el mensaje de error o null. */
function crearNota(PDO $pdo, int $nutriId, int $pacienteId, string $texto): ?string {
    $texto = trim(preg_replace("/\r\n?/", "\n", $texto));
    if ($texto === '') return 'Escribe la nota.';
    if (mb_strlen($texto, 'UTF-8') > NOTA_MAX_CARACTERES) return 'La nota es demasiado larga (máximo ' . NOTA_MAX_CARACTERES . ' caracteres).';
    if (!esPacienteDe($pdo, $nutriId, $pacienteId)) return 'Esta persona no es tu paciente.';
    $pdo->prepare("INSERT INTO nota_paciente (id_nutricionista, id_paciente, texto) VALUES (?, ?, ?)")->execute([$nutriId, $pacienteId, $texto]);
    return null;
}

/** Borra una nota escrita por este nutricionista (no las respuestas del paciente). */
function borrarNota(PDO $pdo, int $nutriId, int $idNota): bool {
    $stmt = $pdo->prepare("DELETE FROM nota_paciente WHERE id_nota = ? AND id_nutricionista = ? AND de_paciente = 0");
    $stmt->execute([$idNota, $nutriId]);
    return $stmt->rowCount() > 0;
}

/**
 * Mensajes entre el paciente y sus nutricionistas, del más nuevo al más
 * antiguo. «autor» es el nombre de quien lo escribe y «de_paciente» dice si
 * lo ha escrito el propio paciente.
 */
function notasDelPaciente(PDO $pdo, int $pacienteId, int $limite = 20): array {
    $stmt = $pdo->prepare("SELECT n.id_nota, n.texto, n.leida, n.fecha, n.id_nutricionista, n.de_paciente,
                                  CASE WHEN n.de_paciente = 1 THEN COALESCE(pp.nombre, 'Paciente')
                                       ELSE COALESCE(pn.nombre, 'Tu nutricionista') END AS autor
                           FROM nota_paciente n
                           LEFT JOIN perfil pn ON pn.id_usuario = n.id_nutricionista
                           LEFT JOIN perfil pp ON pp.id_usuario = n.id_paciente
                           WHERE n.id_paciente = ? ORDER BY n.fecha DESC, n.id_nota DESC LIMIT " . max(1, min(200, $limite)));
    $stmt->execute([$pacienteId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Marca como leídas las notas que el paciente ha recibido. */
function marcarNotasLeidas(PDO $pdo, int $pacienteId): void {
    $pdo->prepare("UPDATE nota_paciente SET leida = 1 WHERE id_paciente = ? AND de_paciente = 0 AND leida = 0")->execute([$pacienteId]);
}

/** Notas del nutricionista que el paciente aún no ha leído. */
function notasSinLeerDelPaciente(PDO $pdo, int $pacienteId): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM nota_paciente WHERE id_paciente = ? AND de_paciente = 0 AND leida = 0");
    $stmt->execute([$pacienteId]);
    return (int) $stmt->fetchColumn();
}

/** Nutricionista actual del paciente (id) o null. */
function nutricionistaDe(PDO $pdo, int $pacienteId): ?int {
    $stmt = $pdo->prepare("SELECT id_nutricionista FROM perfil WHERE id_usuario = ?");
    $stmt->execute([$pacienteId]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

/**
 * El paciente responde a su nutricionista actual. Devuelve el mensaje de error o null.
 * Como mucho un mensaje cada 10 segundos, para que un doble clic no lo duplique.
 */
function responderNota(PDO $pdo, int $pacienteId, string $texto): ?string {
    $texto = trim(preg_replace("/\r\n?/", "\n", $texto));
    if ($texto === '') return 'Escribe tu respuesta.';
    if (mb_strlen($texto, 'UTF-8') > NOTA_MAX_CARACTERES) return 'El mensaje es demasiado largo (máximo ' . NOTA_MAX_CARACTERES . ' caracteres).';
    $nutriId = nutricionistaDe($pdo, $pacienteId);
    if (!$nutriId) return 'Ya no tienes nutricionista: no hay a quién enviarlo.';

    $reciente = $pdo->prepare("SELECT 1 FROM nota_paciente WHERE id_paciente = ? AND de_paciente = 1 AND texto = ?
                               AND fecha > (CURRENT_TIMESTAMP - INTERVAL 10 SECOND)");
    $reciente->execute([$pacienteId, $texto]);
    if ($reciente->fetchColumn()) return null; // el mismo mensaje enviado dos veces

    $pdo->prepare("INSERT INTO nota_paciente (id_nutricionista, id_paciente, texto, de_paciente) VALUES (?, ?, ?, 1)")
        ->execute([$nutriId, $pacienteId, $texto]);
    return null;
}

/** El paciente borra uno de sus propios mensajes. */
function borrarRespuesta(PDO $pdo, int $pacienteId, int $idNota): bool {
    $stmt = $pdo->prepare("DELETE FROM nota_paciente WHERE id_nota = ? AND id_paciente = ? AND de_paciente = 1");
    $stmt->execute([$idNota, $pacienteId]);
    return $stmt->rowCount() > 0;
}

/** Marca como leídas las respuestas de un paciente a este nutricionista. */
function marcarRespuestasLeidas(PDO $pdo, int $nutriId, int $pacienteId): void {
    $pdo->prepare("UPDATE nota_paciente SET leida = 1 WHERE id_nutricionista = ? AND id_paciente = ? AND de_paciente = 1 AND leida = 0")
        ->execute([$nutriId, $pacienteId]);
}

/** Respuestas sin leer por paciente: [id_paciente => n]. */
function respuestasSinLeer(PDO $pdo, int $nutriId): array {
    $stmt = $pdo->prepare("SELECT id_paciente, COUNT(*) FROM nota_paciente
                           WHERE id_nutricionista = ? AND de_paciente = 1 AND leida = 0 GROUP BY id_paciente");
    $stmt->execute([$nutriId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
}
