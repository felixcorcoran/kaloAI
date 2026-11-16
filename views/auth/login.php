<?php
/**
 * VISTA: ACCESO AL SISTEMA (LOGIN) - KaloAI
 * Gestiona la autenticación de usuarios, validación de credenciales
 * y redirección estratégica según el rol del usuario.
 */

session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php';

/* ==========================================================================
   CONTROL DE SESIÓN ACTIVA
   ========================================================================== */
// Si el usuario ya está logueado, lo enviamos directamente a su panel 
// para evitar que vea el formulario de login innecesariamente.
if (isset($_SESSION['user_id'])) {
    redirigirPorRol($_SESSION['user_role_id']);
}

$error = '';

/* ==========================================================================
   PROCESAMIENTO DEL FORMULARIO (POST)
   ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validación básica de campos vacíos
    if (!empty($email) && !empty($password)) {
        try {
            $pdo = connectDB();
            
            /* 1. BÚSQUEDA DEL USUARIO */
            // Obtenemos el hash de la contraseña y el estado de la cuenta.
            $stmt = $pdo->prepare("SELECT id_usuario, contrasena_hash, id_rol, activo FROM usuario WHERE correo = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            /* 2. VERIFICACIÓN DE CREDENCIALES */
            // password_verify compara el texto plano con el hash seguro de la BD.
            if ($user && password_verify($password, $user['contrasena_hash'])) {
            
                /* 3. VERIFICACIÓN DE ESTADO (SEGURIDAD) */
                // Si el administrador ha marcado 'activo = 0', impedimos el acceso.
                if ($user['activo'] == 0) {
                    $error = "🚫 Esta cuenta ha sido suspendida. Contacta con soporte.";
                } else {
                    /* 4. LOGIN EXITOSO */
                    // Regeneramos el ID de sesión para prevenir ataques de fijación de sesión.
                    session_regenerate_id(true);

                    // Almacenamos datos clave en la superglobal $_SESSION
                    $_SESSION['user_id'] = $user['id_usuario'];
                    $_SESSION['user_role_id'] = $user['id_rol'];

                    // Redirigir según el rol (Admin -> Panel Admin, Usuario -> Dashboard, etc.)
                    redirigirPorRol($user['id_rol']);
                }
            } else {
                // Error genérico por seguridad (no indicar si el email existe o no)
                $error = "Correo o contraseña incorrectos.";
            }
        } catch (PDOException $e) {
            $error = "Error técnico: No se pudo conectar con el servicio de autenticación.";
        }
    } else {
        $error = "Por favor, rellena todos los campos.";
    }
}


$pageTitle = "KaloAI | Entrar";
include_once __DIR__ . '/../templates/header.php';
?>

<div class="k-container grid min-h-[calc(100vh-12rem)] items-stretch gap-10 py-10 lg:grid-cols-2 lg:py-14">
    <div class="mx-auto flex w-full max-w-sm flex-col justify-center">
        <div class="mb-8">
            <h1 class="k-title">Hola de nuevo</h1>
            <p class="k-subtitle">Entra para ver tu plan de hoy.</p>
        </div>

        <?php if (isset($_GET['cuenta_borrada'])): ?>
            <div class="k-alert k-alert-ok mb-4"><?= icono('check') ?><p>Tu cuenta y todos tus datos se han borrado. Gracias por usar KaloAI.</p></div>
        <?php endif; ?>
        <?php if (isset($_GET['restablecida'])): ?>
            <div class="k-alert k-alert-ok mb-4"><?= icono('check') ?><p>Contraseña cambiada. Ya puedes entrar con la nueva.</p></div>
        <?php endif; ?>
        <?php if (isset($_GET['loggedout'])): ?>
            <div class="k-alert k-alert-ok mb-4"><?= icono('check') ?><p>Has cerrado sesión.</p></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="k-alert k-alert-error mb-4" role="alert"><?= icono('alert') ?><p><?= e($error) ?></p></div>
        <?php endif; ?>

        <form method="POST" action="" class="k-card k-card-pad space-y-4">
            <?= campoCsrf() ?>
            <div>
                <label for="email" class="k-label">Correo electrónico</label>
                <input id="email" type="email" name="email" required autocomplete="email" class="k-input" placeholder="nombre@correo.com" value="<?= e($_POST['email'] ?? '') ?>">
            </div>
            <div>
                <div class="flex items-baseline justify-between gap-3">
                    <label for="password" class="k-label">Contraseña</label>
                    <a href="olvide.php" class="k-link text-xs">¿La has olvidado?</a>
                </div>
                <input id="password" type="password" name="password" required autocomplete="current-password" class="k-input">
            </div>
            <button type="submit" class="k-btn k-btn-primary w-full">Entrar</button>
        </form>

        <p class="mt-6 text-sm k-muted">
            ¿No tienes cuenta? <a href="registro.php" class="k-link">Crea una gratis</a>
        </p>
    </div>
    <?php include __DIR__ . '/../templates/auth_lateral.php'; ?>
</div>

<?php include_once __DIR__ . '/../templates/footer.php'; ?>
