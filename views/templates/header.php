<?php
/**
 * PLANTILLA: CABECERA - KaloAI
 * Metadatos, tema claro/oscuro y navegación.
 *
 * Dos modos:
 * - Aplicación (sesión iniciada y página dentro de views/): cabecera con la
 *   navegación de las secciones según el rol; en móvil, fila deslizable.
 * - Pública (portada, contacto, privacidad, registro): cabecera de la web.
 */

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../core/seguridad.php';
require_once __DIR__ . '/iconos.php';

$appName = "KaloAI";
$userName = 'Usuario';

if (isset($_SESSION['user_id'])) {
    try {
        require_once __DIR__ . '/../../core/configuracion.php';
        $stmt = connectDB()->prepare("SELECT nombre FROM perfil WHERE id_usuario = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $nombrePerfil = $stmt->fetchColumn();
        if (!empty($nombrePerfil)) {
            $userName = $nombrePerfil;
        }
    } catch (\Exception $e) {
        error_log("Error en header: " . $e->getMessage());
    }
}

// Modo gestión: un nutricionista dentro de la cuenta de un paciente
$impersonando = isset($_SESSION['is_impersonating']) && $_SESSION['is_impersonating'] === true;
$inicial = mb_strtoupper(mb_substr($userName, 0, 1, 'UTF-8'), 'UTF-8');

$rol = (int) ($_SESSION['user_role_id'] ?? 0);
$scriptActual = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$modoApp = isset($_SESSION['user_id']) && str_contains($scriptActual, '/views/');
$paginaActual = basename($scriptActual);

// Panel de inicio de cada rol
$urlInicio = BASE_URL . match ($rol) {
    1 => 'views/admin/dashboard_admin.php',
    2 => 'views/nutri/dashboard_nutri.php',
    default => 'views/dashboard.php',
};

// Secciones de la navegación: [texto, icono, ruta, páginas que la marcan como activa]
$seccionesUsuario = [
    ['Hoy',      'home',     'views/dashboard.php',                  ['dashboard.php']],
    ['Plan',     'calendar', 'views/user/planificacion_semanal.php', ['planificacion_semanal.php']],
    ['Recetas',  'book',     'views/user/mis_recetas.php',           ['mis_recetas.php', 'detalle_receta.php', 'crear_receta.php', 'editar_receta.php', 'generar_receta.php']],
    ['Despensa', 'package',  'views/user/inventario.php',            ['inventario.php']],
    ['Compra',   'cart',     'views/user/lista_compra.php',          ['lista_compra.php']],
    ['Progreso', 'trend-up', 'views/user/progreso.php',              ['progreso.php']],
    ['Perfil',   'user',     'views/user/perfil.php',                ['perfil.php']],
];
// Paneles de trabajo de administración y nutricionista
$seccionesEquipo = match ($rol) {
    1 => [
        ['Usuarios',  'shield', 'views/admin/dashboard_admin.php', ['dashboard_admin.php']],
        ['Pacientes', 'users',  'views/nutri/dashboard_nutri.php', ['dashboard_nutri.php']],
    ],
    2 => [
        ['Pacientes', 'users', 'views/nutri/dashboard_nutri.php', ['dashboard_nutri.php']],
    ],
    default => [],
};
// Grupos de la navegación: [título del grupo, secciones]. Todos los roles
// tienen su propio plan; admin y nutricionista, además, sus paneles.
$gruposNavegacion = $seccionesEquipo
    ? [['Trabajo', $seccionesEquipo], ['Mi plan', $seccionesUsuario]]
    : [[null, $seccionesUsuario]];
$nombreRol = [1 => 'Administración', 2 => 'Nutricionista', 3 => 'Usuario'][$rol] ?? '';

$pintarEnlace = function (array $seccion) use ($paginaActual) {
    [$texto, $icono, $ruta, $paginas] = $seccion;
    $activa = in_array($paginaActual, $paginas, true);
    echo '<a href="' . e(BASE_URL . $ruta) . '" class="k-nav-link"' . ($activa ? ' aria-current="page"' : '') . '>'
        . icono($icono) . '<span>' . e($texto) . '</span></a>';
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Token CSRF para las peticiones que hace main.js (fetch y formularios creados al vuelo) -->
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    <title><?= e($pageTitle ?? $appName) ?></title>
    <?php include __DIR__ . '/tema.php'; ?>

    <meta name="description" content="Recetas creadas con IA según tus calorías, objetivos e intolerancias, con plan semanal, inventario y lista de la compra. Para usuarios y nutricionistas.">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="KaloAI">
    <meta property="og:title" content="KaloAI | Planificación Alimentaria Inteligente">
    <meta property="og:description" content="Recetas creadas con IA según tus calorías, objetivos e intolerancias, con plan semanal, inventario y lista de la compra.">
    <meta property="og:url" content="https://kaloai.infinityfreeapp.com/">
    <meta property="og:image" content="https://kaloai.infinityfreeapp.com/images/og.jpg">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">

    <!-- App instalable (PWA) e iconos -->
    <link rel="manifest" href="<?= BASE_URL ?>manifest.json">
    <meta name="theme-color" content="#0c0c0e">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="KaloAI">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>public/icons/favicon-32.png">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>public/icons/apple-touch-icon.png">

    <!-- Tipografías: DM Sans (aplicación) e Inter / Plus Jakarta Sans (estilos propios de la portada) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=Inter:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="<?= BASE_URL ?>public/css/style.css">
    <!-- Tailwind compilado (npm run build:css): después de style.css, como hacía el CDN -->
    <link rel="stylesheet" href="<?= BASE_URL ?>public/css/tailwind.css?v=<?= @filemtime(__DIR__ . '/../../public/css/tailwind.css') ?>">
</head>
<body class="<?= $modoApp ? 'k-app' : 'bg-gray-50' ?> flex min-h-screen flex-col antialiased">
    <!-- Modo oscuro de las clases de Tailwind de la portada (después de Tailwind para ganarle) -->
    <link rel="stylesheet" href="<?= BASE_URL ?>public/css/oscuro.css">

    <?php if ($modoApp): ?>
        <!-- BARRA LATERAL (escritorio) -->
        <aside class="k-sidebar" aria-label="Navegación">
            <a href="<?= e($urlInicio) ?>" class="flex h-16 shrink-0 items-center px-5">
                <img src="<?= BASE_URL ?>images/logo.png" class="h-8 w-auto" alt="KaloAI">
            </a>
            <nav class="flex-1 overflow-y-auto px-3 pb-4">
                <?php foreach ($gruposNavegacion as [$tituloGrupo, $seccionesGrupo]): ?>
                    <?php if ($tituloGrupo): ?><p class="k-nav-group"><?= e($tituloGrupo) ?></p><?php endif; ?>
                    <div class="flex flex-col gap-0.5"><?php foreach ($seccionesGrupo as $seccion) $pintarEnlace($seccion); ?></div>
                <?php endforeach; ?>
            </nav>
            <div class="space-y-1 border-t p-3" style="border-color: var(--k-border);">
                <button type="button" class="js-tema k-nav-link w-full" aria-label="Cambiar a modo oscuro">
                    <?= icono('moon', 'kalo-luna') ?><?= icono('sun', 'kalo-sol') ?><span class="js-tema-texto">Modo oscuro</span>
                </button>
                <div class="flex items-center gap-3 rounded-xl px-2 py-2">
                    <span class="k-avatar" aria-hidden="true"><?= e($inicial) ?></span>
                    <a href="<?= BASE_URL ?>views/user/perfil.php" class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium k-strong"><?= e($userName) ?></span>
                        <span class="block truncate text-xs k-muted"><?= e($nombreRol) ?></span>
                    </a>
                    <a href="<?= BASE_URL ?>views/auth/logout.php" class="k-btn k-btn-ghost k-btn-icon" title="Cerrar sesión" aria-label="Cerrar sesión"><?= icono('log-out') ?></a>
                </div>
            </div>
        </aside>

        <div class="k-with-sidebar flex min-h-screen flex-col">
            <?php if ($impersonando): ?>
                <!-- Modo gestión: el nutricionista está dentro de la cuenta de un paciente -->
                <div class="sticky top-0 z-50 border-b border-amber-500/30 bg-amber-400 text-amber-950">
                    <div class="k-container flex h-11 items-center justify-between gap-4 text-sm">
                        <p class="flex min-w-0 items-center gap-2 font-medium">
                            <?= icono('eye', 'h-4 w-4 shrink-0') ?>
                            <span class="truncate">Estás gestionando la cuenta de <strong class="font-semibold"><?= e($userName) ?></strong></span>
                        </p>
                        <form method="POST" action="<?= BASE_URL ?>views/nutri/salir_paciente.php" class="m-0 shrink-0">
                            <?= campoCsrf() ?>
                            <button type="submit" class="rounded-full bg-amber-950 px-3.5 py-1.5 text-xs font-medium text-amber-50 transition hover:bg-amber-900">Finalizar gestión</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- CABECERA EN MÓVIL Y TABLETA -->
            <header class="sticky <?= $impersonando ? 'top-11' : 'top-0' ?> z-40 border-b backdrop-blur-xl lg:hidden" style="background-color: color-mix(in srgb, var(--k-bg) 85%, transparent); border-color: var(--k-border);">
                <div class="k-container flex h-14 items-center gap-3">
                    <a href="<?= e($urlInicio) ?>" class="shrink-0"><img src="<?= BASE_URL ?>images/logo.png" class="h-7 w-auto" alt="KaloAI"></a>
                    <div class="ml-auto flex items-center gap-1">
                        <button type="button" class="js-tema k-btn k-btn-ghost k-btn-icon" aria-label="Cambiar a modo oscuro">
                            <?= icono('moon', 'kalo-luna') ?><?= icono('sun', 'kalo-sol') ?>
                        </button>
                        <a href="<?= BASE_URL ?>views/user/perfil.php" class="k-avatar h-8 w-8 text-xs" aria-label="Mi perfil"><?= e($inicial) ?></a>
                        <a href="<?= BASE_URL ?>views/auth/logout.php" class="k-btn k-btn-ghost k-btn-icon" aria-label="Cerrar sesión"><?= icono('log-out') ?></a>
                    </div>
                </div>
                <nav class="-mt-px overflow-x-auto border-t" style="border-color: var(--k-border);" aria-label="Secciones">
                    <div class="k-container flex w-max min-w-full gap-1 py-2">
                        <?php foreach ($gruposNavegacion as [, $seccionesGrupo]) foreach ($seccionesGrupo as $seccion) $pintarEnlace($seccion); ?>
                    </div>
                </nav>
            </header>
    <?php else: ?>
        <!-- CABECERA PÚBLICA -->
        <header class="sticky <?= $impersonando ? 'top-11' : 'top-0' ?> z-40 border-b border-gray-900/5 bg-white/75 backdrop-blur-xl backdrop-saturate-150">
            <nav class="container mx-auto flex items-center justify-between gap-4 px-5 py-3.5 sm:px-6" aria-label="Principal">
                <a href="<?= BASE_URL ?>index.php" class="shrink-0 rounded-xl transition-opacity hover:opacity-80 focus:outline-none focus-visible:ring-4 focus-visible:ring-teal-200">
                    <img src="<?= BASE_URL ?>images/logo.png" class="h-9 w-auto sm:h-10" alt="KaloAI">
                </a>

                <div class="flex items-center gap-2 sm:gap-3">
                    <button type="button" class="js-tema grid h-11 w-11 place-items-center rounded-full text-gray-500 transition-colors hover:bg-teal-50 hover:text-teal-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-teal-100" aria-label="Cambiar a modo oscuro" title="Modo oscuro">
                        <?= icono('moon', 'kalo-luna h-5 w-5') ?><?= icono('sun', 'kalo-sol h-5 w-5') ?>
                    </button>

                    <?php if (isset($_SESSION['user_id'])): ?>
                        <a href="<?= e($urlInicio) ?>" class="inline-flex items-center gap-2 rounded-full bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white shadow-md shadow-teal-600/25 transition hover:bg-teal-700">
                            Ir a mi panel <?= icono('arrow-right', 'h-4 w-4') ?>
                        </a>
                    <?php else: ?>
                        <a href="<?= BASE_URL ?>views/auth/login.php" class="rounded-full px-4 py-2.5 text-sm font-semibold text-gray-600 transition-colors hover:bg-teal-50 hover:text-teal-700">Entrar</a>
                        <a href="<?= BASE_URL ?>views/auth/registro.php" class="rounded-full bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white shadow-md shadow-teal-600/25 transition hover:-translate-y-px hover:bg-teal-700">Crear cuenta</a>
                    <?php endif; ?>
                </div>
            </nav>
        </header>
    <?php endif; ?>

    <script>
        // Botones de tema: alternan claro / oscuro y lo recuerdan (views/templates/tema.php)
        (function () {
            var raiz = document.documentElement;
            var botones = document.querySelectorAll('.js-tema');
            var rotular = function () {
                var oscuro = raiz.classList.contains('dark');
                botones.forEach(function (b) {
                    b.setAttribute('aria-label', oscuro ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro');
                    var texto = b.querySelector('.js-tema-texto');
                    if (texto) texto.textContent = oscuro ? 'Modo claro' : 'Modo oscuro';
                });
            };
            rotular();
            botones.forEach(function (b) {
                b.addEventListener('click', function () {
                    var oscuro = raiz.classList.toggle('dark');
                    try { localStorage.setItem('kalo-tema', oscuro ? 'oscuro' : 'claro'); } catch (e) {}
                    rotular();
                });
            });
        })();
    </script>

    <main class="flex-grow">
