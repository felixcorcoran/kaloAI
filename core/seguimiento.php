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

/** Borra una nota escrita por este nutricionista. */
function borrarNota(PDO $pdo, int $nutriId, int $idNota): bool {
    $stmt = $pdo->prepare("DELETE FROM nota_paciente WHERE id_nota = ? AND id_nutricionista = ?");
    $stmt->execute([$idNota, $nutriId]);
    return $stmt->rowCount() > 0;
}

/** Notas recibidas por el paciente, de la más nueva a la más antigua, con el nombre de quien la escribe. */
function notasDelPaciente(PDO $pdo, int $pacienteId, int $limite = 20): array {
    $stmt = $pdo->prepare("SELECT n.id_nota, n.texto, n.leida, n.fecha, n.id_nutricionista, COALESCE(p.nombre, 'Tu nutricionista') AS autor
                           FROM nota_paciente n LEFT JOIN perfil p ON p.id_usuario = n.id_nutricionista
                           WHERE n.id_paciente = ? ORDER BY n.fecha DESC, n.id_nota DESC LIMIT " . max(1, min(100, $limite)));
    $stmt->execute([$pacienteId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Marca como leídas todas las notas del paciente. */
function marcarNotasLeidas(PDO $pdo, int $pacienteId): void {
    $pdo->prepare("UPDATE nota_paciente SET leida = 1 WHERE id_paciente = ? AND leida = 0")->execute([$pacienteId]);
}
