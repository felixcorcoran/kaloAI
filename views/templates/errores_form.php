<?php
/**
 * PLANTILLA: ERRORES DE UN FORMULARIO
 * Muestra (una sola vez) los errores que un controlador dejó en
 * $_SESSION['errores_form'] antes de redirigir de vuelta al formulario.
 */
$erroresFormulario = $_SESSION['errores_form'] ?? [];
unset($_SESSION['errores_form'], $_SESSION['post_data']);
if ($erroresFormulario): ?>
    <div class="mb-8 p-5 rounded-[2rem] font-bold border-2 bg-red-50 border-red-100 text-red-700" role="alert">
        <ul class="list-disc pl-5 space-y-1 text-sm">
            <?php foreach ($erroresFormulario as $errorFormulario): ?>
                <li><?= e($errorFormulario) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
