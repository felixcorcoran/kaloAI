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
    <p class="k-eyebrow">Legal</p>
    <h1 class="mt-2 font-display text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">Privacidad de tus datos</h1>
    <p class="mt-4 text-lg leading-relaxed text-gray-600">
        Tu información de salud y alimentación es muy personal. Aquí explicamos qué datos guarda KaloAI, para qué los usa y cómo puedes pedir que se borren.
    </p>

    <div class="mt-10 space-y-6">
        <section class="k-card k-card-pad">
            <h2 class="k-section-title">Qué datos guardamos</h2>
            <ul class="mt-4 grid gap-4 sm:grid-cols-2">
                <li class="k-subtle rounded-xl p-4">
                    <p class="font-medium text-gray-900">Cuenta</p>
                    <p class="mt-1 text-sm text-gray-600">Nombre, correo y contraseña. La contraseña se guarda cifrada con un hash: nadie puede leerla.</p>
                </li>
                <li class="k-subtle rounded-xl p-4">
                    <p class="font-medium text-gray-900">Perfil nutricional</p>
                    <p class="mt-1 text-sm text-gray-600">Fecha de nacimiento, altura, peso, actividad, objetivo, alergias e intolerancias, recetas, plan, despensa y progreso.</p>
                </li>
            </ul>
        </section>

        <section class="k-card k-card-pad">
            <h2 class="k-section-title">Cómo usamos la inteligencia artificial</h2>
            <p class="mt-3 text-sm leading-relaxed text-gray-600">
                Para crear una receta, KaloAI envía a Google Gemini tu objetivo de calorías y macros, los ingredientes de tu despensa y los alimentos que no puedes tomar.
                <strong class="text-gray-900">No se envían tu nombre ni tu correo.</strong> No vendemos tus datos ni los cedemos con fines comerciales.
            </p>
        </section>

        <section class="k-card k-card-pad">
            <h2 class="k-section-title">Tus derechos</h2>
            <p class="mt-3 text-sm leading-relaxed text-gray-600">
                Según el RGPD puedes acceder a tus datos, corregirlos o pedir que se eliminen. Los de tu perfil los puedes corregir tú desde la aplicación;
                para borrar la cuenta y todos sus datos, escríbenos y lo hacemos.
            </p>
            <a href="contacto.php" class="k-btn k-btn-secondary mt-5">Pedir la eliminación de mis datos <?= icono('arrow-right') ?></a>
        </section>
    </div>
</div>

<?php include_once __DIR__ . '/../views/templates/footer.php'; ?>
