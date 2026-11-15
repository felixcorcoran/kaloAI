<?php
/**
 * VISTA: REGISTRO DE USUARIOS - KaloAI
 * Gestiona la creación de nuevas cuentas, validando duplicados y 
 * asegurando la integridad de los datos en múltiples tablas (Transacciones).
 */

// Configuración de la vista y metadatos
$isAuth = true; 
$pageTitle = "KaloAI | Crear cuenta";
$message = ''; 
$messageType = 'success'; 

session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php';

/* ==========================================================================
   CONTROL DE ACCESO
   ========================================================================== */
// Si el usuario ya tiene una sesión iniciada, no debería registrarse de nuevo.
if (isset($_SESSION['user_id'])) {
    redirect('../dashboard.php');
}

/* ==========================================================================
   PROCESAMIENTO DEL REGISTRO (POST)
   ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    
    /* 1. SANITIZACIÓN Y CAPTURA */
    // Limpiamos los inputs para evitar ataques XSS o inyecciones básicas.
    $email = limpiarInput($_POST['email'] ?? '');
    $nombre = limpiarInput($_POST['nombre'] ?? ''); 
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    
    /* 2. VALIDACIÓN DE REGLAS DE NEGOCIO */
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "El formato del correo electrónico no es válido.";
    }
    if (empty($nombre)) {
        $errors[] = "El nombre es obligatorio.";
    }
    if (strlen($password) < 8) {
        $errors[] = "La contraseña debe tener al menos 8 caracteres.";
    }
    if ($password !== $passwordConfirm) {
        $errors[] = "Las contraseñas no coinciden.";
    }

    if (empty($errors)) {
        try {
            $pdo = connectDB();
            
            /* 3. VERIFICAR DUPLICADOS */
            // Comprobamos si el email ya existe para evitar errores de clave única en la BD.
            $stmt = $pdo->prepare("SELECT id_usuario FROM usuario WHERE correo = ?");
            $stmt->execute([$email]);
            
            if ($stmt->fetch()) {
                $errors[] = "Este correo electrónico ya está registrado. Intenta iniciar sesión.";
            } else {
                /* 4. SEGURIDAD DE CONTRASEÑA */
                // Nunca guardamos contraseñas en texto plano. Usamos BCRYPT mediante hashContrasena().
                $hashedPassword = hashContrasena($password);
                
                /* 5. PROCESO DE ALTA (TRANSACCIÓN) */
                // Usamos transacciones porque si falla la creación del perfil, 
                // debemos deshacer la creación del usuario para no dejar datos huérfanos.
                $pdo->beginTransaction();

                // A) Insertar en la tabla 'usuario' (Por defecto Rol 3: Usuario)
                $stmt = $pdo->prepare("INSERT INTO usuario (correo, contrasena_hash, id_rol, fecha_creacion) VALUES (?, ?, 3, NOW())");
                $stmt->execute([$email, $hashedPassword]);
                
                // Obtenemos el ID generado para vincularlo al perfil
                $newUserId = $pdo->lastInsertId();

                // B) Crear el perfil vinculado con el nombre del usuario
                $stmtPerfil = $pdo->prepare("INSERT INTO perfil (id_usuario, nombre) VALUES (?, ?)");
                $stmtPerfil->execute([$newUserId, $nombre]);

                // Si todo ha ido bien, confirmamos los cambios permanentemente
                $pdo->commit();
                
                $message = "¡Registro exitoso! Ya puedes iniciar sesión.";
                $messageType = 'success';
                
                // Limpiamos variables para que no se muestren en el formulario tras el éxito
                $email = $nombre = "";
            }
        } catch (Exception $e) {
            /* 6. GESTIÓN DE FALLOS */
            // Si algo falla, el rollback asegura que la base de datos vuelva a su estado original.
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Error crítico en registro: " . $e->getMessage());
            $errors[] = "Hubo un problema técnico. Por favor, inténtalo más tarde.";
        }
    }
    
    // Consolidación de errores para mostrar en la UI
    if (!empty($errors)) {
        $message = "<ul><li>" . implode("</li><li>", array_map('e', $errors)) . "</li></ul>";
        $messageType = 'error';
    }
}

// Carga del componente de cabecera 
include_once __DIR__ . '/../templates/header.php'; 
?>

<div class="k-container grid min-h-[calc(100vh-12rem)] items-stretch gap-10 py-10 lg:grid-cols-2 lg:py-14">
    <div class="mx-auto flex w-full max-w-md flex-col justify-center">
        <div class="mb-8">
            <h1 class="k-title">Crea tu cuenta</h1>
            <p class="k-subtitle">Gratis. Luego completas tu perfil y KaloAI calcula tus objetivos.</p>
        </div>

        <?php if ($message): ?>
            <div class="k-alert k-alert-<?= $messageType === 'success' ? 'ok' : 'error' ?> mb-4" role="alert">
                <?= icono($messageType === 'success' ? 'check' : 'alert') ?>
                <div><?= $message /* texto fijo o errores ya escapados */ ?>
                    <?php if ($messageType === 'success'): ?> <a href="login.php" class="font-semibold underline">Entrar</a><?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" action="registro.php" class="k-card k-card-pad space-y-4">
            <?= campoCsrf() ?>
            <div>
                <label for="nombre" class="k-label">Nombre</label>
                <input id="nombre" name="nombre" type="text" required maxlength="50" autocomplete="name" class="k-input" value="<?= e($nombre ?? '') ?>">
            </div>
            <div>
                <label for="email" class="k-label">Correo electrónico</label>
                <input id="email" name="email" type="email" required autocomplete="email" class="k-input" placeholder="nombre@correo.com" value="<?= e($email ?? '') ?>">
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="password" class="k-label">Contraseña</label>
                    <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password" class="k-input">
                </div>
                <div>
                    <label for="password_confirm" class="k-label">Repítela</label>
                    <input id="password_confirm" name="password_confirm" type="password" required minlength="8" autocomplete="new-password" class="k-input">
                </div>
            </div>
            <p class="k-hint !mt-1">Mínimo 8 caracteres.</p>
            <button type="submit" class="k-btn k-btn-primary w-full">Crear cuenta</button>
            <p class="text-center text-xs k-muted">Al registrarte aceptas la <a href="<?= BASE_URL ?>public/privacidad.php" class="underline">política de privacidad</a>.</p>
        </form>

        <p class="mt-6 text-sm k-muted">
            ¿Ya tienes cuenta? <a href="login.php" class="k-link">Entra</a>
        </p>
    </div>
    <?php include __DIR__ . '/../templates/auth_lateral.php'; ?>
</div>

<?php include_once __DIR__ . '/../templates/footer.php'; ?>
