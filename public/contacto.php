<?php
/**
 * VISTA: CONTACTO - KaloAI
 * El mensaje se guarda para el administrador (core/contacto.php) y, si está
 * configurado, también le llega por correo.
 */

$pageTitle = "KaloAI | Contacto";
session_start();

require_once __DIR__ . '/../core/configuracion.php';
require_once __DIR__ . '/../core/utilidades.php'; // incluye la comprobación del token CSRF
require_once __DIR__ . '/../core/contacto.php';

$enviado = false;
$error = '';
$datos = ['nombre' => '', 'email' => '', 'mensaje' => ''];

// Si hay sesión, se rellenan nombre y correo
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = connectDB()->prepare("SELECT p.nombre, u.correo FROM usuario u LEFT JOIN perfil p ON p.id_usuario = u.id_usuario WHERE u.id_usuario = ?");
        $stmt->execute([$_SESSION['user_id']]);
        if ($fila = $stmt->fetch()) $datos = ['nombre' => (string) $fila['nombre'], 'email' => (string) $fila['correo'], 'mensaje' => ''];
    } catch (Throwable $e) { /* el formulario funciona igual sin rellenar */ }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $datos = ['nombre' => (string) ($_POST['nombre'] ?? ''), 'email' => (string) ($_POST['email'] ?? ''), 'mensaje' => (string) ($_POST['mensaje'] ?? '')];
    $limpio = validarContacto($_POST);
    if (($_POST['web'] ?? '') !== '') {
        // Campo trampa invisible: solo lo rellenan los robots. Se finge el envío.
        $enviado = true;
    } elseif (isset($limpio['error'])) {
        $error = $limpio['error'];
    } elseif (time() - (int) ($_SESSION['ultimo_contacto'] ?? 0) < 60) {
        $error = 'Acabas de enviarnos un mensaje. Espera un minuto antes de mandar otro.';
    } else {
        try {
            guardarContacto(connectDB(), $limpio, isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null);
            $_SESSION['ultimo_contacto'] = time();
            $enviado = true;
        } catch (Throwable $e) {
            error_log('Error al guardar un mensaje de contacto: ' . $e->getMessage());
            $error = 'No se ha podido enviar el mensaje. Inténtalo de nuevo en un rato.';
        }
    }
}

include_once __DIR__ . '/../views/templates/header.php';
?>

<div class="k-container max-w-5xl py-12 sm:py-16">
    <div class="grid gap-10 md:grid-cols-2">
        <div>
            <span class="k-pill">Contacto</span>
            <h1 class="mt-2 font-display text-3xl font-bold tracking-tight k-strong sm:text-4xl">¿En qué podemos ayudarte?</h1>
            <p class="mt-4 text-lg leading-relaxed k-muted">Dudas sobre tu plan, un problema con la cuenta o una petición sobre tus datos: escríbenos y te respondemos.</p>
            <ul class="mt-8 space-y-3">
                <li class="k-card flex items-start gap-4 p-5">
                    <span class="k-icon-tile"><?= icono('clock') ?></span>
                    <span><span class="block font-semibold k-strong">Respondemos en 1–2 días laborables</span><span class="block text-sm k-muted">Te escribimos al correo que nos dejes.</span></span>
                </li>
                <li class="k-card flex items-start gap-4 p-5">
                    <span class="k-icon-tile k-tile-indigo"><?= icono('users') ?></span>
                    <span><span class="block font-semibold k-strong">¿Eres nutricionista?</span><span class="block text-sm k-muted">Cuéntanos cuántos pacientes llevas y te ayudamos a empezar con KaloAI.</span></span>
                </li>
                <li class="k-card flex items-start gap-4 p-5">
                    <span class="k-icon-tile k-tile-amber"><?= icono('shield') ?></span>
                    <span><span class="block font-semibold k-strong">Tus datos</span><span class="block text-sm k-muted">Desde tu perfil puedes descargarlos o borrar tu cuenta cuando quieras.</span></span>
                </li>
            </ul>
        </div>

        <?php if ($enviado): ?>
            <div class="k-card k-card-pad self-start" role="status">
                <span class="k-icon-tile"><?= icono('check') ?></span>
                <h2 class="k-section-title mt-4">¡Mensaje enviado!</h2>
                <p class="mt-1 text-sm k-muted">Gracias por escribirnos. Te responderemos a <strong class="k-strong"><?= e($datos['email']) ?></strong> lo antes posible.</p>
                <a href="<?= BASE_URL ?><?= isset($_SESSION['user_id']) ? 'views/dashboard.php' : 'index.php' ?>" class="k-btn k-btn-secondary mt-5">Volver</a>
            </div>
        <?php else: ?>
            <form action="contacto.php" method="POST" class="k-card k-card-pad space-y-4 self-start">
                <?= campoCsrf() ?>
                <?php if ($error): ?>
                    <div class="k-alert k-alert-error" role="alert"><?= icono('alert') ?><p><?= e($error) ?></p></div>
                <?php endif; ?>
                <!-- Campo trampa para robots: invisible para las personas -->
                <div class="hidden" aria-hidden="true"><label>Web <input type="text" name="web" tabindex="-1" autocomplete="off"></label></div>
                <div>
                    <label for="nombre" class="k-label">Nombre</label>
                    <input id="nombre" type="text" name="nombre" required maxlength="100" class="k-input" autocomplete="name" value="<?= e($datos['nombre']) ?>">
                </div>
                <div>
                    <label for="email" class="k-label">Correo electrónico</label>
                    <input id="email" type="email" name="email" required class="k-input" autocomplete="email" placeholder="nombre@correo.com" value="<?= e($datos['email']) ?>">
                </div>
                <div>
                    <label for="mensaje" class="k-label">Mensaje</label>
                    <textarea id="mensaje" name="mensaje" rows="5" required minlength="10" maxlength="<?= CONTACTO_MAX_MENSAJE ?>" class="k-input"><?= e($datos['mensaje']) ?></textarea>
                </div>
                <button type="submit" class="k-btn k-btn-primary w-full"><?= icono('mail') ?>Enviar mensaje</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php include_once __DIR__ . '/../views/templates/footer.php'; ?>
