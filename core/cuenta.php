<?php
/**
 * CUENTA: descargar los datos propios y borrar la cuenta.
 * El borrado lo usan tanto la propia persona (desde su perfil) como el
 * administrador (views/admin/borrar_usuario.php).
 */

/**
 * Todo lo que KaloAI guarda de una persona, listo para descargar en JSON.
 * Nunca incluye el hash de la contraseña.
 */
function exportarDatosCuenta(PDO $pdo, int $userId): array {
    $filas = function (string $sql, array $params = []) use ($pdo): array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    $cuenta = $filas("SELECT u.correo, r.nombre AS rol, u.fecha_creacion FROM usuario u JOIN rol r ON r.id_rol = u.id_rol WHERE u.id_usuario = ?", [$userId]);
    $perfil = $filas("SELECT nombre, fecha_nacimiento, genero, altura_cm, peso_kg, nivel_actividad, objetivo, tdee_calorias,
                             calorias_netas_objetivo, proteinas_objetivo_g, carbohidratos_objetivo_g, grasas_objetivo_g, comensales,
                             resumen_semanal
                      FROM perfil WHERE id_usuario = ?", [$userId]);

    $recetas = $filas("SELECT id_receta, titulo, tipo_comida, descripcion, calorias_por_racion, proteinas_g_por_racion,
                              carbohidratos_g_por_racion, grasas_g_por_racion, generado_ia, favorita, fecha_creacion
                       FROM receta WHERE id_usuario_creador = ? ORDER BY id_receta", [$userId]);
    $ingredientes = [];
    foreach ($filas("SELECT d.id_receta, d.nombre_ingrediente, d.cantidad, d.unidad FROM detalle_receta d
                     JOIN receta r ON r.id_receta = d.id_receta WHERE r.id_usuario_creador = ?", [$userId]) as $i) {
        $ingredientes[$i['id_receta']][] = ['nombre' => $i['nombre_ingrediente'], 'cantidad' => (float) $i['cantidad'], 'unidad' => $i['unidad']];
    }
    foreach ($recetas as &$receta) {
        $receta['ingredientes'] = $ingredientes[$receta['id_receta']] ?? [];
        unset($receta['id_receta']);
    }
    unset($receta);

    $planes = [];
    foreach ($filas("SELECT ps.fecha_inicio_semana, cp.dia_semana, cp.momento_comida, cp.raciones, r.titulo
                     FROM plan_semanal ps JOIN comida_planificada cp ON cp.id_plan = ps.id_plan JOIN receta r ON r.id_receta = cp.id_receta
                     WHERE ps.id_usuario = ? ORDER BY ps.fecha_inicio_semana, cp.dia_semana", [$userId]) as $c) {
        $planes[$c['fecha_inicio_semana']][] = ['dia' => DIAS_SEMANA[(int) $c['dia_semana']] ?? $c['dia_semana'],
                                               'momento' => $c['momento_comida'], 'receta' => $c['titulo'], 'raciones' => (float) $c['raciones']];
    }

    $plantillas = [];
    foreach ($filas("SELECT p.nombre, pc.dia_semana, pc.momento_comida, pc.raciones, r.titulo
                     FROM plantilla_semana p JOIN plantilla_comida pc ON pc.id_plantilla = p.id_plantilla JOIN receta r ON r.id_receta = pc.id_receta
                     WHERE p.id_usuario = ? ORDER BY p.nombre, pc.dia_semana", [$userId]) as $c) {
        $plantillas[$c['nombre']][] = ['dia' => DIAS_SEMANA[(int) $c['dia_semana']] ?? $c['dia_semana'],
                                       'momento' => $c['momento_comida'], 'receta' => $c['titulo'], 'raciones' => (float) $c['raciones']];
    }

    return [
        'exportado' => date('c'),
        'aplicacion' => 'KaloAI',
        'cuenta' => $cuenta[0] ?? null,
        'perfil' => $perfil[0] ?? null,
        'alergias_y_preferencias' => $filas("SELECT nombre_ingrediente, nota FROM ingrediente_prohibido WHERE id_usuario = ?", [$userId]),
        'progreso' => $filas("SELECT fecha, peso_kg, cintura_cm, nota FROM progreso WHERE id_usuario = ? ORDER BY fecha", [$userId]),
        'recetas' => $recetas,
        'planes_semanales' => $planes,
        'comidas_registradas' => $filas("SELECT fecha, momento_comida, descripcion, raciones, calorias, proteinas_g, carbohidratos_g, grasas_g
                                         FROM registro_comida WHERE id_usuario = ? ORDER BY fecha, id_registro", [$userId]),
        'plantillas_de_semana' => $plantillas,
        'mensajes_con_el_nutricionista' => array_map(fn($m) => ['fecha' => $m['fecha'], 'de' => $m['de_paciente'] ? 'yo' : 'nutricionista', 'texto' => $m['texto']],
            $filas("SELECT fecha, de_paciente, texto FROM nota_paciente WHERE id_paciente = ? ORDER BY fecha, id_nota", [$userId])),
        'despensa' => $filas("SELECT nombre_ingrediente, cantidad, unidad, fecha_actualizacion FROM inventario WHERE id_usuario = ? ORDER BY nombre_ingrediente", [$userId]),
    ];
}

/** ¿Es la única cuenta de administración activa? (no se puede borrar) */
function esUltimoAdministrador(PDO $pdo, int $userId): bool {
    $stmt = $pdo->prepare("SELECT id_rol FROM usuario WHERE id_usuario = ?");
    $stmt->execute([$userId]);
    if ((int) $stmt->fetchColumn() !== 1) return false;
    return (int) $pdo->query("SELECT COUNT(*) FROM usuario WHERE id_rol = 1 AND activo = 1")->fetchColumn() <= 1;
}

/**
 * Borra una cuenta con todos sus datos y deja constancia en el log de
 * actividad. Devuelve false si la cuenta no existe.
 *
 * Las claves foráneas borran en cascada perfil, despensa, alergias, progreso,
 * planes (y sus comidas), registro de comidas y solicitudes, y desvinculan a
 * sus pacientes si era nutricionista. Sus recetas se borran aparte porque la
 * clave foránea las dejaría sin dueño.
 */
function borrarCuenta(PDO $pdo, int $userId, string $detalleLog): bool {
    $existe = $pdo->prepare("SELECT 1 FROM usuario WHERE id_usuario = ?");
    $existe->execute([$userId]);
    if (!$existe->fetchColumn()) return false;

    $pdo->beginTransaction();
    try {
        $stmtRecetas = $pdo->prepare("SELECT id_receta FROM receta WHERE id_usuario_creador = ?");
        $stmtRecetas->execute([$userId]);
        $idsRecetas = $stmtRecetas->fetchAll(PDO::FETCH_COLUMN);

        // A mano por si la base de datos aún no tiene la migración 001
        $pdo->prepare("DELETE FROM solicitudes_vinculacion WHERE id_nutricionista = ? OR id_paciente = ?")->execute([$userId, $userId]);
        $pdo->prepare("DELETE FROM usuario WHERE id_usuario = ?")->execute([$userId]);

        if ($idsRecetas) {
            $marcas = implode(',', array_fill(0, count($idsRecetas), '?'));
            // Por si alguna receta suya seguía en el plan de otra persona
            $pdo->prepare("DELETE FROM comida_planificada WHERE id_receta IN ($marcas)")->execute($idsRecetas);
            $pdo->prepare("DELETE FROM receta WHERE id_receta IN ($marcas)")->execute($idsRecetas);
        }

        $pdo->prepare("INSERT INTO logs_actividad (detalle) VALUES (?)")->execute([$detalleLog]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
