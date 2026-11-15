<?php
/**
 * PLANTILLA: PANEL DECORATIVO DE LOGIN Y REGISTRO
 * Una vista previa de cómo es KaloAI por dentro (datos de ejemplo, no reales).
 * Solo se ve en pantallas grandes.
 */
?>
<aside class="relative hidden overflow-hidden rounded-3xl border p-8 lg:flex lg:flex-col" style="border-color: var(--k-border); background: radial-gradient(120% 80% at 100% 0%, var(--k-brand-soft), transparent 60%), var(--k-surface);" aria-hidden="true">
    <span class="k-pill self-start"><?= icono('sparkles') ?>Planificación alimentaria con IA</span>
    <p class="mt-5 max-w-sm font-display text-3xl font-semibold leading-tight tracking-tight k-strong">Tu semana de comidas, cuadrada con tus objetivos.</p>
    <p class="mt-3 max-w-sm text-sm k-muted">Menús, recetas y lista de la compra a partir de lo que tienes en casa y de lo que no puedes comer.</p>

    <div class="mt-auto space-y-3 pt-10">
        <div class="k-card p-5">
            <div class="flex items-center justify-between"><p class="k-stat-label">Calorías de hoy</p><span class="k-icon-tile h-8 w-8 rounded-lg k-tile-orange"><?= icono('flame', 'h-4 w-4') ?></span></div>
            <p class="mt-2 flex items-baseline gap-2"><span class="k-stat-value">1.420</span><span class="text-sm k-muted">/ 1.787 kcal</span></p>
            <div class="k-track mt-3 h-2"><div class="k-bar" style="width: 79%"></div></div>
            <div class="mt-4 grid grid-cols-3 gap-2 text-xs">
                <?php foreach ([['k-macro-p', 'Proteína', '118 g', 76], ['k-macro-c', 'Carbos', '150 g', 84], ['k-macro-f', 'Grasa', '41 g', 82]] as [$clase, $nombre, $valor, $pct]): ?>
                    <div class="<?= $clase ?>">
                        <p class="flex items-center gap-1.5 k-muted"><span class="k-macro-dot"></span><?= $nombre ?></p>
                        <p class="mt-1 font-semibold tabular-nums k-strong"><?= $valor ?></p>
                        <div class="k-track mt-1.5 h-1"><div class="k-bar k-macro-bar" style="width: <?= $pct ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div class="k-card flex items-center gap-3 p-4">
                <span class="k-icon-tile h-9 w-9 k-tile-indigo"><?= icono('calendar', 'h-4 w-4') ?></span>
                <div><p class="text-xs k-muted">Plan semanal</p><p class="text-sm font-semibold k-strong">28 / 35 comidas</p></div>
            </div>
            <div class="k-card flex items-center gap-3 p-4">
                <span class="k-icon-tile h-9 w-9"><?= icono('trend-down', 'h-4 w-4') ?></span>
                <div><p class="text-xs k-muted">Peso</p><p class="text-sm font-semibold k-strong">−2,3 kg</p></div>
            </div>
        </div>
    </div>
</aside>
