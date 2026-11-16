<?php
/**
 * PLANTILLA: FORMULARIO DE RECETA (crear y editar)
 * Variables de entrada:
 *   $receta           fila de la tabla receta, o null al crear
 *   $ingredientesText ingredientes uno por línea ("150 g Pechuga de pollo")
 * Si el guardado falló por validación, se rellenan con lo que se había enviado.
 */
$previo = $_SESSION['post_data'] ?? null;
$valor = function (string $campo, $porDefecto = '') use ($previo, $receta) {
    if (is_array($previo) && array_key_exists($campo, $previo)) return $previo[$campo];
    return $receta[$campo] ?? $porDefecto;
};
$descripcionActual = is_array($previo) ? ($previo['instrucciones'] ?? '') : ($receta['descripcion'] ?? '');
$ingredientesActual = is_array($previo) ? ($previo['ingredientes'] ?? '') : ($ingredientesText ?? '');
$tipoActual = mb_strtolower((string) $valor('tipo_comida', 'almuerzo'), 'UTF-8');
$numero = fn($v) => $v === '' || $v === null ? '' : rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
?>
<?php include __DIR__ . '/errores_form.php'; ?>

<form action="../../core/guardar_receta.php" method="POST" class="space-y-6">
    <?= campoCsrf() ?>
    <?php if (!empty($receta['id_receta'])): ?>
        <input type="hidden" name="id_receta" value="<?= (int) $receta['id_receta'] ?>">
    <?php endif; ?>

    <section class="k-card k-card-pad">
        <h2 class="k-section-title">Datos básicos</h2>
        <div class="mt-5 grid gap-5 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label for="titulo" class="k-label">Nombre de la receta</label>
                <input id="titulo" name="titulo" type="text" required maxlength="200" class="k-input" value="<?= e($valor('titulo')) ?>" placeholder="Ej. Ensalada de garbanzos">
            </div>
            <div>
                <label for="tipo_comida" class="k-label">Tipo de comida</label>
                <select id="tipo_comida" name="tipo_comida" class="k-input">
                    <?php foreach (['desayuno' => 'Desayuno', 'almuerzo' => 'Almuerzo', 'snack' => 'Snack', 'cena' => 'Cena', 'postre' => 'Postre'] as $clave => $texto): ?>
                        <option value="<?= $clave ?>" <?= $tipoActual === $clave ? 'selected' : '' ?>><?= $texto ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </section>

    <section class="k-card k-card-pad">
        <div class="grid gap-6 lg:grid-cols-2">
            <div>
                <label for="ingredientes" class="k-label">Ingredientes</label>
                <textarea id="ingredientes" name="ingredientes" rows="8" required class="k-input font-mono text-[13px]" placeholder="150 g Pechuga de pollo&#10;80 g Arroz integral&#10;2 huevos"><?= e($ingredientesActual) ?></textarea>
                <p class="k-hint">Uno por línea, con la cantidad delante. Así la lista de la compra sabrá cuánto comprar.</p>
            </div>
            <div>
                <label for="instrucciones" class="k-label">Preparación</label>
                <textarea id="instrucciones" name="instrucciones" rows="8" required class="k-input" placeholder="Un paso por línea"><?= e($descripcionActual) ?></textarea>
                <p class="k-hint">Escribe cada paso en una línea.</p>
            </div>
        </div>
    </section>

    <section class="k-card k-card-pad">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="k-section-title">Nutrición por ración</h2>
                <p class="mt-1 text-sm k-muted">¿No los sabes? La IA los calcula a partir de los ingredientes.</p>
            </div>
            <button type="button" id="btnMacrosReceta" onclick="estimarMacrosReceta()" class="k-btn k-btn-accent k-btn-sm"><?= icono('sparkles') ?>Calcular con IA</button>
        </div>
        <p id="macrosRecetaAviso" class="mt-3 text-xs k-muted" role="status" hidden></p>
        <div class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <?php foreach ([['calorias_por_racion', 'Calorías', 'kcal', ''],
                            ['proteinas_g_por_racion', 'Proteína', 'g', 'k-macro-p'],
                            ['carbohidratos_g_por_racion', 'Carbohidratos', 'g', 'k-macro-c'],
                            ['grasas_g_por_racion', 'Grasa', 'g', 'k-macro-f']] as [$campo, $etiqueta, $unidad, $clase]): ?>
                <div class="<?= $clase ?>">
                    <label for="<?= $campo ?>" class="k-label flex items-center gap-2"><?php if ($clase): ?><span class="k-macro-dot"></span><?php endif; ?><?= $etiqueta ?></label>
                    <div class="relative">
                        <input id="<?= $campo ?>" name="<?= $campo ?>" type="number" min="0" step="0.1" inputmode="decimal" class="k-input pr-12" value="<?= e($numero($valor($campo, 0))) ?>">
                        <span class="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-xs k-muted"><?= $unidad ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <a href="<?= !empty($receta['id_receta']) ? 'detalle_receta.php?id=' . (int) $receta['id_receta'] : 'mis_recetas.php' ?>" class="k-btn k-btn-secondary">Cancelar</a>
        <button type="submit" class="k-btn k-btn-primary"><?= icono('check') ?><?= !empty($receta['id_receta']) ? 'Guardar cambios' : 'Guardar receta' ?></button>
    </div>
</form>
