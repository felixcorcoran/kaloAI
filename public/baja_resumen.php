<?php
/**
 * VISTA PÚBLICA: DEJAR DE RECIBIR EL RESUMEN SEMANAL - KaloAI
 * Se llega desde el enlace del propio correo, sin iniciar sesión. La baja se
 * confirma con un botón (POST): algunos programas de correo abren los
 * enlaces para comprobarlos y no deben dar de baja a nadie sin querer.
 */

$pageTitle = "KaloAI | Resumen semanal";
$noIndexar = true;
session_start();

require_once __DIR__ . '/../core/configuracion.php';
require_once __DIR__ . '/../core/utilidades.php'; // incluye la comprobación del token CSRF

header('Referrer-Policy: no-referrer');
$token = (string) ($_POST['t'] ?? $_GET['t'] ?? '');
$hecho = false;
$valido = tokenListaValido($token);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valido) {
    try {
        $hecho = darDeBajaResumen(connectDB(), $token);
        $valido = $hecho;
    } catch (Throwable $e) {
        error_log('Error al dar de baja el resumen semanal: ' . $e->getMessage());
        $valido = false;
    }
}

include_once __DIR__ . '/../views/templates/header.php';
?>

<div class="k-container max-w-lg py-16">
    <div class="k-card k-card-pad">
        <?php if ($hecho): ?>
            <span class="k-icon-tile"><?= icono('check') ?></span>
            <h1 class="k-section-title mt-4">Hecho: no te enviaremos más resúmenes</h1>
            <p class="mt-1 text-sm k-muted">Si cambias de idea, actívalo otra vez desde tu perfil, en «Tu cuenta».</p>
            <a href="<?= e(BASE_URL) ?>views/user/perfil.php#cuenta" class="k-btn k-btn-secondary mt-6">Ir a mi perfil</a>
        <?php elseif (!$valido): ?>
            <span class="k-icon-tile k-tile-amber"><?= icono('alert') ?></span>
            <h1 class="k-section-title mt-4">Este enlace no es válido</h1>
            <p class="mt-1 text-sm k-muted">Puedes desactivar el resumen desde tu perfil, en «Tu cuenta».</p>
            <a href="<?= e(BASE_URL) ?>views/user/perfil.php#cuenta" class="k-btn k-btn-secondary mt-6">Ir a mi perfil</a>
        <?php else: ?>
            <span class="k-icon-tile k-tile-indigo"><?= icono('mail') ?></span>
            <h1 class="k-section-title mt-4">¿Dejar de recibir el resumen semanal?</h1>
            <p class="mt-1 text-sm k-muted">No te llegará más el correo de los lunes. Tu cuenta y tus datos no cambian.</p>
            <form method="POST" action="baja_resumen.php" class="mt-6">
                <?= campoCsrf() ?>
                <input type="hidden" name="t" value="<?= e($token) ?>">
                <button type="submit" class="k-btn k-btn-primary">Dejar de recibirlo</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php include_once __DIR__ . '/../views/templates/footer.php'; ?>
