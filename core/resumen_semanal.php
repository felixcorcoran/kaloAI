<?php
/**
 * RESUMEN SEMANAL POR CORREO
 *
 * Cada lunes, a quien lo haya activado en su perfil le llega un correo con su
 * semana anterior: lo que apuntó frente a su objetivo, el cambio de peso y un
 * enlace para preparar la semana que empieza.
 *
 * InfinityFree no tiene tareas programadas y bloquea las peticiones que no
 * vienen de un navegador, así que no se puede llamar a una dirección desde
 * fuera. Los envíos se hacen «a ratos»: a partir del lunes a las 7:00, cada
 * visita a la web envía unos pocos resúmenes pendientes cuando ya ha
 * respondido a la persona (comprobarResumenSemanal). Una vez enviados todos,
 * un archivo pequeño lo apunta y las visitas siguientes no hacen nada más.
 */
require_once __DIR__ . '/correo.php';
require_once __DIR__ . '/recetas.php';
require_once __DIR__ . '/registro.php';

const MARCA_RESUMEN = __DIR__ . '/.resumen_semanal';
const RESUMENES_POR_VISITA = 3;
const RESUMEN_HORA_INICIO = 7;

/**
 * Cifras de una semana (de lunes a domingo) para el resumen. Devuelve null si
 * la persona no tiene perfil.
 */
function datosResumenSemanal(PDO $pdo, int $userId, string $lunes): ?array {
    $stmt = $pdo->prepare("SELECT nombre, calorias_netas_objetivo FROM perfil WHERE id_usuario = ?");
    $stmt->execute([$userId]);
    $perfil = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$perfil) return null;

    $domingo = fechaDelDia($lunes, 6);
    $consumo = caloriasConsumidasPorDia($pdo, $userId, $lunes, $domingo);

    // Último peso de la semana y último de antes, para la diferencia
    $peso = $pdo->prepare("SELECT peso_kg FROM progreso WHERE id_usuario = ? AND peso_kg IS NOT NULL AND fecha BETWEEN ? AND ? ORDER BY fecha DESC LIMIT 1");
    $peso->execute([$userId, $lunes, $domingo]);
    $pesoSemana = $peso->fetchColumn();
    $antes = $pdo->prepare("SELECT peso_kg FROM progreso WHERE id_usuario = ? AND peso_kg IS NOT NULL AND fecha < ? ORDER BY fecha DESC LIMIT 1");
    $antes->execute([$userId, $lunes]);
    $pesoAntes = $antes->fetchColumn();

    $siguiente = semanaRelativa($lunes, 1);
    $plan = $pdo->prepare("SELECT COUNT(*) FROM comida_planificada cp JOIN plan_semanal ps ON ps.id_plan = cp.id_plan
                           WHERE ps.id_usuario = ? AND ps.fecha_inicio_semana = ?");
    $plan->execute([$userId, $siguiente]);

    return [
        'nombre'           => (string) $perfil['nombre'],
        'lunes'            => $lunes,
        'objetivo'         => (int) ($perfil['calorias_netas_objetivo'] ?? 0),
        'consumo'          => $consumo,
        'peso'             => $pesoSemana !== false ? (float) $pesoSemana : null,
        'peso_anterior'    => $pesoAntes !== false ? (float) $pesoAntes : null,
        'comidas_siguiente'=> (int) $plan->fetchColumn(),
    ];
}

/**
 * Asunto, HTML y texto del correo a partir de las cifras. Función pura.
 * @return array{asunto: string, html: string, texto: string}
 */
function construirCorreoResumen(array $d, string $baseUrl, string $urlBaja): array {
    $e = fn($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
    $num = fn(float $n, int $dec = 0) => number_format($n, $dec, ',', '.');
    $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $ini = new DateTimeImmutable($d['lunes']);
    $fin = $ini->modify('+6 days');
    $rango = $ini->format('j') . ($ini->format('n') !== $fin->format('n') ? ' ' . $meses[$ini->format('n') - 1] : '')
           . ' al ' . $fin->format('j') . ' ' . $meses[$fin->format('n') - 1];
    $nombre = trim(explode(' ', trim($d['nombre']))[0]);

    $dias = count($d['consumo']);
    $media = $dias ? array_sum($d['consumo']) / $dias : 0;
    $objetivo = (int) $d['objetivo'];
    $enObjetivo = $objetivo > 0 ? count(array_filter($d['consumo'], fn($k) => $k >= $objetivo * 0.9 && $k <= $objetivo * 1.1)) : 0;

    // Frases (las mismas para el HTML y para el texto)
    $filas = [];
    if ($dias === 0) {
        $filas[] = ['Comidas', 'No apuntaste ninguna comida. Marcar «Hecho» en «Hoy» lleva un segundo.'];
    } else {
        $filas[] = ['Comidas', "Apuntaste $dias de 7 días."];
        if ($objetivo > 0) {
            $dif = $media - $objetivo;
            $valoracion = abs($dif) <= $objetivo * 0.1 ? 'dentro de tu objetivo' : ($dif > 0 ? 'por encima de tu objetivo' : 'por debajo de tu objetivo');
            $filas[] = ['Calorías', 'Media de ' . $num($media) . ' kcal al día (' . $valoracion . ' de ' . $num($objetivo) . ').'];
            $filas[] = ['En objetivo', $enObjetivo . ($enObjetivo === 1 ? ' día' : ' días') . ' a menos de un 10 % de tu objetivo.'];
        } else {
            $filas[] = ['Calorías', 'Media de ' . $num($media) . ' kcal al día. Completa tu perfil para compararlo con tu objetivo.'];
        }
    }
    if ($d['peso'] !== null) {
        $textoPeso = $num($d['peso'], 1) . ' kg';
        if ($d['peso_anterior'] !== null) {
            $cambio = $d['peso'] - $d['peso_anterior'];
            $textoPeso .= abs($cambio) < 0.05 ? ' (igual que antes)' : ' (' . ($cambio > 0 ? '+' : '−') . $num(abs($cambio), 1) . ' kg)';
        }
        $filas[] = ['Peso', $textoPeso . '.'];
    } else {
        $filas[] = ['Peso', 'No te pesaste. Puedes apuntarlo desde «Hoy».'];
    }
    $planificadas = (int) $d['comidas_siguiente'];
    $filas[] = ['Esta semana', $planificadas
        ? "Tienes $planificadas " . ($planificadas === 1 ? 'comida planificada' : 'comidas planificadas') . ' de 35.'
        : 'Aún no has planificado nada.'];

    $asunto = $dias
        ? "Tu semana en KaloAI: $dias de 7 días apuntados"
        : 'Tu semana en KaloAI: empieza la siguiente con buen pie';
    $boton = $planificadas >= 21 ? 'Ver mi semana' : 'Preparar mi semana';
    $urlPlan = rtrim($baseUrl, '/') . '/views/user/planificacion_semanal.php';

    $tabla = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:16px;border-collapse:collapse;">';
    foreach ($filas as [$etiqueta, $texto]) {
        $tabla .= '<tr><td style="padding:10px 12px 10px 0;border-top:1px solid #e4e4e7;font-size:13px;color:#71717a;white-space:nowrap;vertical-align:top;">' . $e($etiqueta)
                . '</td><td style="padding:10px 0;border-top:1px solid #e4e4e7;font-size:15px;color:#18181b;">' . $e($texto) . '</td></tr>';
    }
    $tabla .= '</table>';

    $saludo = $nombre !== '' ? "Hola, $nombre. " : 'Hola. ';
    $parrafo = $e($saludo) . 'Así fue tu semana del ' . $e($rango) . ':' . $tabla;
    $nota = 'Recibes este correo porque activaste el resumen semanal en tu perfil. '
          . '<a href="' . $e($urlBaja) . '" style="color:#71717a;">Dejar de recibirlo</a>.';

    $texto = $saludo . "Así fue tu semana del $rango:\n\n";
    foreach ($filas as [$etiqueta, $t]) $texto .= "· $etiqueta: $t\n";
    $texto .= "\n$boton: $urlPlan\n\nRecibes este correo porque activaste el resumen semanal en tu perfil. Dejar de recibirlo: $urlBaja\n";

    return [
        'asunto' => $asunto,
        'html'   => plantillaCorreo('Tu semana', $parrafo, $boton, $urlPlan, $nota),
        'texto'  => $texto,
    ];
}

/** Activa o desactiva el resumen. Al activarlo crea el token del enlace de baja si no lo tenía. */
function cambiarResumenSemanal(PDO $pdo, int $userId, bool $activar): void {
    if ($activar) {
        $pdo->prepare("UPDATE perfil SET resumen_semanal = 1, token_baja = COALESCE(token_baja, ?),
                       ultimo_resumen = COALESCE(ultimo_resumen, ?) WHERE id_usuario = ?")
            // La semana pasada cuenta como ya resumida: el primero llega el lunes que viene
            ->execute([bin2hex(random_bytes(16)), semanaRelativa(lunesDe(), -1), $userId]);
    } else {
        $pdo->prepare("UPDATE perfil SET resumen_semanal = 0 WHERE id_usuario = ?")->execute([$userId]);
    }
}

/** Baja desde el enlace del correo. Devuelve true si el token era de alguien. */
function darDeBajaResumen(PDO $pdo, string $token): bool {
    if (!tokenListaValido($token)) return false; // mismo formato: 32 caracteres hexadecimales
    $stmt = $pdo->prepare("UPDATE perfil SET resumen_semanal = 0 WHERE token_baja = ?");
    $stmt->execute([$token]);
    $existe = $pdo->prepare("SELECT 1 FROM perfil WHERE token_baja = ?");
    $existe->execute([$token]);
    return (bool) $existe->fetchColumn();
}

/**
 * Envía hasta $limite resúmenes pendientes de la semana que empieza en
 * $lunesResumido. Devuelve ['enviados' => n, 'pendientes' => n] (pendientes
 * después de este lote).
 */
function enviarResumenesPendientes(PDO $pdo, string $baseUrl, string $lunesResumido, int $limite, ?callable $transporte = null): array {
    $pendientes = "FROM perfil p JOIN usuario u ON u.id_usuario = p.id_usuario
                   WHERE p.resumen_semanal = 1 AND u.activo = 1 AND p.token_baja IS NOT NULL
                     AND (p.ultimo_resumen IS NULL OR p.ultimo_resumen < ?)";
    $stmt = $pdo->prepare("SELECT p.id_usuario, u.correo, p.token_baja $pendientes ORDER BY p.id_usuario LIMIT " . max(1, $limite));
    $stmt->execute([$lunesResumido]);
    $enviados = 0;
    $fallidos = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $datos = datosResumenSemanal($pdo, (int) $fila['id_usuario'], $lunesResumido);
        if ($datos === null) continue;
        $urlBaja = rtrim($baseUrl, '/') . '/public/baja_resumen.php?t=' . $fila['token_baja'];
        $correo = construirCorreoResumen($datos, $baseUrl, $urlBaja);
        $ok = enviarCorreo($fila['correo'], $correo['asunto'], $correo['html'], $correo['texto'], $transporte);
        $ok ? $enviados++ : $fallidos++;
        // Un intento por persona y semana, aunque falle: así un correo que no
        // existe no se reintenta en cada visita. Perder un resumen no es grave.
        $pdo->prepare("UPDATE perfil SET ultimo_resumen = ? WHERE id_usuario = ?")->execute([$lunesResumido, $fila['id_usuario']]);
    }
    $quedan = $pdo->prepare("SELECT COUNT(*) $pendientes");
    $quedan->execute([$lunesResumido]);
    return ['enviados' => $enviados, 'fallidos' => $fallidos, 'pendientes' => (int) $quedan->fetchColumn()];
}

/**
 * ¿Toca enviar? Desde el lunes a las 7:00 hasta que se hayan enviado todos los
 * de esa semana. Devuelve el lunes de la semana a resumir, o null. Función pura.
 */
function semanaPorResumir(DateTimeImmutable $ahora, ?string $marca): ?string {
    $lunesActual = lunesDe($ahora->format('Y-m-d'));
    if ($marca === $lunesActual) return null;
    if ($ahora->format('Y-m-d') === $lunesActual && (int) $ahora->format('G') < RESUMEN_HORA_INICIO) return null;
    return semanaRelativa($lunesActual, -1);
}

/**
 * Comprobación de cada visita a la web: barata si no toca (lee un archivo
 * pequeño). Si toca, envía unos pocos resúmenes cuando la página ya se ha
 * entregado, para no hacer esperar a nadie.
 */
function comprobarResumenSemanal(): void {
    if (PHP_SAPI === 'cli' || !function_exists('connectDB') || configCorreo('RESEND_API_KEY') === '') return;
    $marca = @file_get_contents(MARCA_RESUMEN);
    $lunesResumido = semanaPorResumir(new DateTimeImmutable(), $marca === false ? null : trim($marca));
    if ($lunesResumido === null) return;

    register_shutdown_function(function () use ($lunesResumido) {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        try {
            $pdo = connectDB();
            // Una sola visita a la vez; las demás siguen sin esperar
            if ((int) $pdo->query("SELECT GET_LOCK('kaloai_resumen', 0)")->fetchColumn() !== 1) return;
            try {
                $r = enviarResumenesPendientes($pdo, BASE_URL, $lunesResumido, RESUMENES_POR_VISITA);
                if ($r['pendientes'] === 0) {
                    @file_put_contents(MARCA_RESUMEN, semanaRelativa($lunesResumido, 1), LOCK_EX);
                }
                if ($r['enviados'] || $r['fallidos']) {
                    error_log("KaloAI: resúmenes semanales enviados: {$r['enviados']}, fallidos: {$r['fallidos']}, pendientes: {$r['pendientes']}");
                }
            } finally {
                $pdo->query("SELECT RELEASE_LOCK('kaloai_resumen')");
            }
        } catch (Throwable $e) {
            error_log('KaloAI: error al enviar los resúmenes semanales: ' . $e->getMessage());
        }
    });
}
