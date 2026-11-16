<?php
/**
 * VISTA: MENSAJES CON TU NUTRICIONISTA - KaloAI
 * Las notas que deja el nutricionista y las respuestas del paciente, como una
 * conversación. El nutricionista la ve en la ficha del paciente.
 * En modo gestión (el nutricionista dentro de la cuenta) se puede leer, pero
 * no escribir en nombre del paciente ni marcar nada como leído.
 */
$pageTitle = "KaloAI | Mensajes";

session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php'; // incluye la comprobación del token CSRF
require_once __DIR__ . '/../../core/seguimiento.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}
$userId = (int) $_SESSION['user_id'];
$gestion = !empty($_SESSION['is_impersonating']);
$pdo = connectDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($gestion) {
        $_SESSION['aviso_mensajes'] = ['error', 'En modo gestión no se puede escribir en nombre de tu paciente.'];
    } elseif (($_POST['accion'] ?? '') === 'borrar') {
        borrarRespuesta($pdo, $userId, (int) ($_POST['id_nota'] ?? 0));
        $_SESSION['aviso_mensajes'] = ['ok', 'Mensaje borrado.'];
    } else {
        $error = responderNota($pdo, $userId, (string) ($_POST['texto'] ?? ''));
        $_SESSION['aviso_mensajes'] = $error ? ['error', $error, (string) ($_POST['texto'] ?? '')] : ['ok', 'Enviado. Tu nutricionista lo verá en tu ficha.'];
    }
    header('Location: mensajes.php#escribir');
    exit;
}

$stmt = $pdo->prepare("SELECT p.nombre, u.correo FROM perfil yo JOIN usuario u ON u.id_usuario = yo.id_nutricionista
                       LEFT JOIN perfil p ON p.id_usuario = yo.id_nutricionista WHERE yo.id_usuario = ?");
$stmt->execute([$userId]);
$nutri = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

// De la más antigua a la más nueva, como en cualquier conversación
$mensajes = array_reverse(notasDelPaciente($pdo, $userId, 100));
if (!$gestion) marcarNotasLeidas($pdo, $userId);

$aviso = $_SESSION['aviso_mensajes'] ?? null;
unset($_SESSION['aviso_mensajes']);
$borrador = $aviso[2] ?? '';

include_once __DIR__ . '/../templates/header.php';
$nombreNutri = $nutri ? ($nutri['nombre'] ?: 'Tu nutricionista') : null;
?>

<div class="k-container k-page max-w-3xl">
    <div class="k-page-head">
        <div>
            <span class="k-pill"><?= icono('message') ?>Mensajes</span>
            <h1 class="k-title mt-3"><?= $nombreNutri ? 'Con ' . e(explode(' ', $nombreNutri)[0]) : 'Tu nutricionista' ?></h1>
            <p class="k-subtitle"><?= $nutri ? 'Sus notas y tus respuestas. Escríbele dudas sobre tu plan o cómo te va la semana.' : 'Aún no tienes nutricionista. Si trabajas con uno, pídele que te invite con tu correo.' ?></p>
        </div>
    </div>

    <?php if ($aviso): [$tipo, $texto] = $aviso; ?>
        <div class="k-alert k-alert-<?= $tipo === 'ok' ? 'ok' : 'error' ?> mb-6" role="status"><?= icono($tipo === 'ok' ? 'check' : 'alert') ?><p><?= e($texto) ?></p></div>
    <?php endif; ?>

    <section class="k-card" aria-label="Conversación">
        <div class="p-5 sm:p-6">
            <?php if (!$mensajes): ?>
                <p class="text-sm k-muted"><?= $nutri ? 'Todavía no os habéis escrito.' : 'No hay mensajes.' ?></p>
            <?php else: ?>
                <ol class="space-y-3">
                    <?php foreach ($mensajes as $m): $mio = (bool) $m['de_paciente']; ?>
                        <li class="flex <?= $mio ? 'justify-end' : 'justify-start' ?>">
                            <div class="max-w-[85%] min-w-0 rounded-2xl p-4 text-sm <?= $mio ? 'k-bubble-mia' : 'k-subtle' ?>">
                                <p class="text-xs font-medium k-muted"><?= $mio ? 'Tú' : e($m['autor']) ?> · <time datetime="<?= e($m['fecha']) ?>"><?= e(formatearFecha($m['fecha'])) ?></time></p>
                                <p class="mt-1 whitespace-pre-line break-words leading-relaxed k-strong"><?= e($m['texto']) ?></p>
                                <?php if ($mio && !$gestion): ?>
                                    <div class="mt-2 flex items-center justify-end gap-2 text-xs k-muted">
                                        <span><?= $m['leida'] ? 'Leído' : 'Sin leer' ?></span>
                                        <form method="POST" action="mensajes.php" class="m-0">
                                            <?= campoCsrf() ?>
                                            <input type="hidden" name="accion" value="borrar">
                                            <input type="hidden" name="id_nota" value="<?= (int) $m['id_nota'] ?>">
                                            <button type="submit" class="k-link" onclick="return confirm('¿Borrar este mensaje?')">Borrar</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>

        <?php if ($nutri && !$gestion): ?>
            <form id="escribir" method="POST" action="mensajes.php" class="scroll-mt-6 space-y-3 border-t p-5 sm:p-6" style="border-color: var(--k-border);">
                <?= campoCsrf() ?>
                <label for="textoRespuesta" class="k-label">Escribe a <?= e(explode(' ', $nombreNutri)[0]) ?></label>
                <textarea id="textoRespuesta" name="texto" rows="3" required maxlength="<?= NOTA_MAX_CARACTERES ?>" class="k-input" placeholder="Ej. Esta semana me cuesta llegar a la proteína en el desayuno, ¿qué puedo cambiar?"><?= e($borrador) ?></textarea>
                <div class="flex items-center justify-between gap-3">
                    <p class="k-hint m-0">Máximo <?= NOTA_MAX_CARACTERES ?> caracteres.</p>
                    <button type="submit" class="k-btn k-btn-primary"><?= icono('send', 'h-4 w-4') ?>Enviar</button>
                </div>
            </form>
        <?php elseif ($gestion): ?>
            <p class="border-t p-5 text-sm k-muted sm:p-6" style="border-color: var(--k-border);">Estás en modo gestión: puedes leer la conversación, pero para escribir usa la ficha del paciente.</p>
        <?php endif; ?>
    </section>
</div>

<?php include_once __DIR__ . '/../templates/footer.php'; ?>
