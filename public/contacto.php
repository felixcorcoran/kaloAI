<?php
/**
 * VISTA: CONTACTO - KaloAI
 * Proporciona un canal directo de comunicación con soporte.
 */

$pageTitle = "KaloAI | Contacto";
session_start();

require_once __DIR__ . '/../core/utilidades.php'; 

// Carga del componente de cabecera 
include_once __DIR__ . '/../views/templates/header.php'; 
?>

<div class="k-container max-w-5xl py-12 sm:py-16">
    <div class="grid gap-10 md:grid-cols-2">
        <div>
            <span class="k-pill">Contacto</span>
            <h1 class="mt-2 font-display text-3xl font-bold tracking-tight k-strong sm:text-4xl">¿En qué podemos ayudarte?</h1>
            <p class="mt-4 text-lg leading-relaxed k-muted">Dudas sobre tu plan, un problema con la cuenta o una petición sobre tus datos: escríbenos y te respondemos.</p>
            <a href="mailto:soporte@kaloai.com" class="k-card k-card-link mt-8 flex items-center gap-4 p-5">
                <span class="k-icon-tile"><?= icono('mail') ?></span>
                <span>
                    <span class="block text-xs font-medium k-muted">Correo</span>
                    <span class="block font-semibold k-strong">soporte@kaloai.com</span>
                </span>
            </a>
        </div>

        <!-- PENDIENTE: este formulario todavía no envía el mensaje a ningún sitio -->
        <form action="#" method="POST" class="k-card k-card-pad space-y-4">
            <?= campoCsrf() ?>
            <div>
                <label for="nombre" class="k-label">Nombre</label>
                <input id="nombre" type="text" name="nombre" required class="k-input" autocomplete="name">
            </div>
            <div>
                <label for="email" class="k-label">Correo electrónico</label>
                <input id="email" type="email" name="email" required class="k-input" autocomplete="email" placeholder="nombre@correo.com">
            </div>
            <div>
                <label for="mensaje" class="k-label">Mensaje</label>
                <textarea id="mensaje" name="mensaje" rows="5" required class="k-input"></textarea>
            </div>
            <button type="submit" class="k-btn k-btn-primary w-full">Enviar mensaje</button>
        </form>
    </div>
</div>

<?php include_once __DIR__ . '/../views/templates/footer.php'; ?>
