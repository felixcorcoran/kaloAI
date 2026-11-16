<?php
/**
 * VISTA: PRIVACIDAD - KaloAI
 * Documento legal sobre el tratamiento de datos personales.
 */

$pageTitle = "KaloAI | Política de Privacidad";
session_start();

require_once __DIR__ . '/../core/utilidades.php'; 

// Carga del componente de cabecera 
include_once __DIR__ . '/../views/templates/header.php'; 
?>

<div class="k-container max-w-3xl py-12 sm:py-16">
    <span class="k-pill">Legal</span>
    <h1 class="mt-2 font-display text-3xl font-bold tracking-tight k-strong sm:text-4xl">Privacidad de tus datos</h1>
    <p class="mt-4 text-lg leading-relaxed k-muted">
        Tu información de salud y alimentación es muy personal. Aquí explicamos qué datos guarda KaloAI, para qué los usa y cómo puedes pedir que se borren.
    </p>

    <div class="mt-10 space-y-6">
        <section class="k-card k-card-pad">
            <h2 class="k-section-title">Qué datos guardamos</h2>
            <ul class="mt-4 grid gap-4 sm:grid-cols-2">
                <li class="k-subtle rounded-xl p-4">
                    <p class="font-medium k-strong">Cuenta</p>
                    <p class="mt-1 text-sm k-muted">Nombre, correo y contraseña. La contraseña se guarda cifrada con un hash: nadie puede leerla.</p>
                </li>
                <li class="k-subtle rounded-xl p-4">
                    <p class="font-medium k-strong">Perfil nutricional</p>
                    <p class="mt-1 text-sm k-muted">Fecha de nacimiento, altura, peso, actividad, objetivo, alergias e intolerancias, recetas, plan, despensa y progreso.</p>
                </li>
            </ul>
        </section>

        <section class="k-card k-card-pad">
            <h2 class="k-section-title">Cómo usamos la inteligencia artificial</h2>
            <p class="mt-3 text-sm leading-relaxed k-muted">
                Para crear una receta, KaloAI envía a Google Gemini tu objetivo de calorías y macros, los ingredientes de tu despensa y los alimentos que no puedes tomar.
                <strong class="k-strong">No se envían tu nombre ni tu correo.</strong> No vendemos tus datos ni los cedemos con fines comerciales.
            </p>
            <p class="mt-3 text-sm leading-relaxed k-muted">
                Si añades productos a la despensa con una <strong class="k-strong">foto del tique</strong>, esa foto se envía a Google Gemini para leerla y
                después se descarta: KaloAI no la guarda. Antes de enviarla, comprueba que no salgan datos que no quieras compartir (por ejemplo, los de una tarjeta).
            </p>
        </section>

        <section class="k-card k-card-pad">
            <h2 class="k-section-title">Lo que eliges compartir</h2>
            <ul class="mt-4 space-y-3 text-sm leading-relaxed k-muted">
                <li><strong class="k-strong">Lista de la compra compartida:</strong> quien tenga el enlace ve tu lista de la compra y tu nombre de pila, nada más. Puedes dejar de compartirla cuando quieras y el enlace deja de funcionar.</li>
                <li><strong class="k-strong">Mensajes con tu nutricionista:</strong> los ve solo tu nutricionista.</li>
                <li><strong class="k-strong">Resumen semanal por correo:</strong> solo si lo activas en tu perfil. Cada correo trae un enlace para darte de baja.</li>
            </ul>
        </section>

        <section class="k-card k-card-pad">
            <h2 class="k-section-title">Tus derechos</h2>
            <p class="mt-3 text-sm leading-relaxed k-muted">
                Según el RGPD puedes acceder a tus datos, corregirlos o pedir que se eliminen. Los de tu perfil los puedes corregir tú desde la aplicación;
                para borrar la cuenta y todos sus datos, escríbenos y lo hacemos.
            </p>
            <a href="contacto.php" class="k-btn k-btn-secondary mt-5">Pedir la eliminación de mis datos <?= icono('arrow-right') ?></a>
        </section>
    </div>
</div>

<?php include_once __DIR__ . '/../views/templates/footer.php'; ?>
