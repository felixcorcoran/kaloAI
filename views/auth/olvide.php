<?php
/**
 * VISTA: HE OLVIDADO MI CONTRASEÑA - KaloAI
 * Pide el correo y, si hay una cuenta, envía un enlace para cambiar la
 * contraseña (core/contrasena.php). La respuesta es siempre la misma, exista
 * o no la cuenta, para no revelar qué correos están registrados.
 */

session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php'; // incluye la comprobación del token CSRF
require_once __DIR__ . '/../../core/contrasena.php';

if (isset($_SESSION['user_id'])) {
    redirigirPorRol($_SESSION['user_role_id']);
}

$enviado = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = trim((string) ($_POST['email'] ?? ''));
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $error = 'Escribe un correo electrónico válido.';
    } else {
        try {
            solicitarRestablecimiento(connectDB(), $correo, BASE_URL);
        } catch (Throwable $e) {
            error_log('Error al pedir el cambio de contraseña: ' . $e->getMessage());
        }
        $enviado = true;
    }
}

$pageTitle = "KaloAI | Recuperar la contraseña";
include_once __DIR__ . '/../templates/header.php';
?>

<div class="k-container grid min-h-[calc(100vh-12rem)] items-stretch gap-10 py-10 lg:grid-cols-2 lg:py-14">
    <div class="mx-auto flex w-full max-w-sm flex-col justify-center">
        <a href="login.php" class="k-back"><?= icono('chevron-left', 'h-4 w-4') ?>Volver a entrar</a>
        <div class="mb-8">
            <h1 class="k-title">¿Has olvidado tu contraseña?</h1>
            <p class="k-subtitle">Escribe el correo de tu cuenta y te enviaremos un enlace para elegir otra.</p>
        </div>

        <?php if ($enviado): ?>
            <div class="k-card k-card-pad">
                <span class="k-icon-tile"><?= icono('mail') ?></span>
                <p class="mt-4 font-medium k-strong">Revisa tu correo</p>
                <p class="mt-1 text-sm k-muted">Si hay una cuenta con <strong class="k-strong"><?= e($correo) ?></strong>, te acabamos de enviar un enlace. Caduca en <?= MINUTOS_VALIDEZ_ENLACE ?> minutos. Mira también en la carpeta de spam.</p>
                <a href="login.php" class="k-btn k-btn-secondary mt-5 w-full">Volver a entrar</a>
            </div>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="k-alert k-alert-error mb-4" role="alert"><?= icono('alert') ?><p><?= e($error) ?></p></div>
            <?php endif; ?>
            <form method="POST" action="olvide.php" class="k-card k-card-pad space-y-4">
                <?= campoCsrf() ?>
                <div>
                    <label for="email" class="k-label">Correo electrónico</label>
                    <input id="email" type="email" name="email" required autocomplete="email" class="k-input" placeholder="nombre@correo.com" value="<?= e($_POST['email'] ?? '') ?>" autofocus>
                </div>
                <button type="submit" class="k-btn k-btn-primary w-full"><?= icono('mail') ?>Enviarme el enlace</button>
            </form>
        <?php endif; ?>
    </div>
    <?php include __DIR__ . '/../templates/auth_lateral.php'; ?>
</div>

<?php include_once __DIR__ . '/../templates/footer.php'; ?>
