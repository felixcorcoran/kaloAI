<?php
/**
 * VISTA: ELEGIR UNA CONTRASEÑA NUEVA - KaloAI
 * Se llega desde el enlace del correo (?token=...). Si el token es válido,
 * se elige una contraseña nueva; el enlace deja de servir al usarse.
 */

session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php'; // incluye la comprobación del token CSRF
require_once __DIR__ . '/../../core/contrasena.php';

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$pdo = connectDB();
$valido = usuarioDelToken($pdo, $token) !== null;
$error = '';

if ($valido && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $nueva = (string) ($_POST['password'] ?? '');
    $error = errorNuevaContrasena($nueva, (string) ($_POST['password_confirm'] ?? '')) ?? '';
    if ($error === '') {
        if (restablecerContrasena($pdo, $token, $nueva)) {
            // Por si había una sesión abierta en este navegador
            $_SESSION = [];
            header('Location: login.php?restablecida=1');
            exit;
        }
        $valido = false;
    }
}

$pageTitle = "KaloAI | Nueva contraseña";
include_once __DIR__ . '/../templates/header.php';
?>

<div class="k-container grid min-h-[calc(100vh-12rem)] items-stretch gap-10 py-10 lg:grid-cols-2 lg:py-14">
    <div class="mx-auto flex w-full max-w-sm flex-col justify-center">
        <?php if (!$valido): ?>
            <div class="k-card k-card-pad">
                <span class="k-icon-tile k-tile-danger"><?= icono('alert') ?></span>
                <h1 class="k-title mt-4 text-2xl">Este enlace ya no sirve</h1>
                <p class="mt-2 text-sm k-muted">Ha caducado o ya se ha usado. Pide otro y úsalo en menos de <?= MINUTOS_VALIDEZ_ENLACE ?> minutos.</p>
                <a href="olvide.php" class="k-btn k-btn-primary mt-5 w-full">Pedir otro enlace</a>
            </div>
        <?php else: ?>
            <div class="mb-8">
                <h1 class="k-title">Elige una contraseña nueva</h1>
                <p class="k-subtitle">Al guardarla podrás entrar con ella; el enlace dejará de funcionar.</p>
            </div>
            <?php if ($error): ?>
                <div class="k-alert k-alert-error mb-4" role="alert"><?= icono('alert') ?><p><?= e($error) ?></p></div>
            <?php endif; ?>
            <form method="POST" action="restablecer.php" class="k-card k-card-pad space-y-4">
                <?= campoCsrf() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div>
                    <label for="password" class="k-label">Contraseña nueva</label>
                    <input id="password" name="password" type="password" required minlength="8" maxlength="72" autocomplete="new-password" class="k-input" autofocus>
                    <p class="k-hint">Mínimo 8 caracteres.</p>
                </div>
                <div>
                    <label for="password_confirm" class="k-label">Repítela</label>
                    <input id="password_confirm" name="password_confirm" type="password" required minlength="8" maxlength="72" autocomplete="new-password" class="k-input">
                </div>
                <button type="submit" class="k-btn k-btn-primary w-full"><?= icono('lock') ?>Guardar contraseña</button>
            </form>
        <?php endif; ?>
    </div>
    <?php include __DIR__ . '/../templates/auth_lateral.php'; ?>
</div>

<?php include_once __DIR__ . '/../templates/footer.php'; ?>
