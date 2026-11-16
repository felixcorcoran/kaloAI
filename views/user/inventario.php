<?php
/**
 * GESTOR DE INVENTARIO - KaloAI
 * Permite al usuario llevar un control de sus existencias.
 * Incluye lógica de conversión de unidades y patrón PRG para evitar reenvíos de formulario.
 */

$pageTitle = "KaloAI | Inventario Doméstico";

session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php'; 

// 1. SEGURIDAD: Verificación de sesión activa
if (!isset($_SESSION['user_id'])) {
    redirect('../auth/login.php'); 
}

$userId = $_SESSION['user_id'];
$message = '';
$messageType = 'success';
$inventarioItems = [];
$pdo = connectDB();

/* ==========================================================
   CONFIGURACIÓN DE CONVERSIONES
   Se definen las unidades soportadas y se cargan los factores 
   matemáticos desde el core.
   ========================================================== */
$unidades = [
    'g' => 'Gramos (g)',
    'kg' => 'Kilos (kg)',
    'ml' => 'Mililitros (ml)',
    'L' => 'Litros (L)',
    'unidad' => 'Unidades',
];
$conversionMap = getDetallesConversion();


/* ==========================================================
   2. PROCESAMIENTO DE ACCIONES (POST)
   Maneja: Añadir (con suma inteligente), Editar y Eliminar.
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    $action = limpiarInput($_POST['action'] ?? '');
    $idInventario = filter_var($_POST['id_inventario'] ?? null, FILTER_VALIDATE_INT);

    try {
        if ($action === 'add' || $action === 'edit') {
            // Saneamiento de entradas numéricas y de texto
            $nombreIngrediente = limpiarInput($_POST['nombre_ingrediente'] ?? '');
            $cantidad = str_replace(',', '.', limpiarInput($_POST['cantidad'] ?? '')); 
            $cantidad = filter_var($cantidad, FILTER_VALIDATE_FLOAT);
            $unidad = limpiarInput($_POST['unidad'] ?? '');

            // Validación de integridad de datos
            if (empty($nombreIngrediente) || $cantidad === false || $cantidad <= 0 || !array_key_exists($unidad, $unidades)) {
                $errors[] = "Datos de ingrediente inválidos o incompletos.";
            }

            $newUnitDetails = $conversionMap[$unidad] ?? null;
            if (!$newUnitDetails) {
                 $errors[] = "Unidad de medida no reconocida.";
            }

            if (empty($errors)) {
                $fechaActualizacion = date('Y-m-d H:i:s'); 
                
                /* ------------------------------------------------------
                   LÓGICA DE SUMA INTELIGENTE (Acción 'add')
                   Evita duplicados como "Arroz (500g)" y "Arroz (1kg)"
                   ------------------------------------------------------ */
                if ($action === 'add') {
                    
                    $normalizedNameForSQL = normalizarString($nombreIngrediente);
                    $group = $newUnitDetails['group'];
                    
                    // BUSQUEDA POR SIMILITUD: Busca el ingrediente ignorando tildes y mayúsculas,
                    // asegurando que pertenezca al mismo grupo de medida (ej. no sumar 'huevos' con 'leche').
                    $sqlCheck = "SELECT id_inventario, cantidad, unidad, nombre_ingrediente 
                                 FROM inventario 
                                 WHERE id_usuario = ? 
                                 AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(nombre_ingrediente), 'á', 'a'), 'é', 'e'), 'í', 'i'), 'ó', 'o'), 'ú', 'u') = ?
                                 AND unidad IN (
                                     SELECT unit FROM (
                                         SELECT DISTINCT T1.unidad AS unit, 
                                            CASE 
                                                WHEN T1.unidad IN ('g', 'kg') THEN 'peso'
                                                WHEN T1.unidad IN ('ml', 'L') THEN 'volumen'
                                                ELSE 'conteo'
                                            END AS unit_group
                                         FROM inventario T1
                                     ) AS UnitGroups
                                     WHERE unit_group = ?
                                 )";
                    
                    $stmtCheck = $pdo->prepare($sqlCheck);
                    $stmtCheck->execute([$userId, $normalizedNameForSQL, $group]);
                    $existingItem = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                    if ($existingItem) {
                        // SI EXISTE: Convertir ambas cantidades a una "Unidad Base" para operar matemáticamente
                        $existingUnit = $existingItem['unidad'];
                        $existingUnitDetails = $conversionMap[$existingUnit];
                        
                        // Cantidad nueva -> Base
                        $addedQuantityInBase = $cantidad * $newUnitDetails['conversion_factor'];
                        
                        // Cantidad vieja -> Base
                        $existingQuantityInBase = $existingItem['cantidad'] * $existingUnitDetails['conversion_factor'];
                        
                        // Suma y reconversión a la unidad que ya estaba guardada en la DB
                        $newQuantityInBase = $existingQuantityInBase + $addedQuantityInBase;
                        $finalNewQuantity = $newQuantityInBase / $existingUnitDetails['conversion_factor'];
                        
                        $sqlUpdate = "UPDATE inventario SET cantidad = ?, fecha_actualizacion = ? 
                                      WHERE id_inventario = ? AND id_usuario = ?";
                        $stmtUpdate = $pdo->prepare($sqlUpdate);
                        $stmtUpdate->execute([$finalNewQuantity, $fechaActualizacion, $existingItem['id_inventario'], $userId]);
                        
                        $message = "Ingrediente {$existingItem['nombre_ingrediente']} actualizado con éxito.";

                    } else {
                        // SI NO EXISTE: Inserción limpia como nuevo registro
                        $sqlInsert = "INSERT INTO inventario (id_usuario, nombre_ingrediente, cantidad, unidad, fecha_actualizacion) 
                                VALUES (?, ?, ?, ?, ?)";
                        $stmtInsert = $pdo->prepare($sqlInsert);
                        $stmtInsert->execute([$userId, $nombreIngrediente, $cantidad, $unidad, $fechaActualizacion]);
                        $message = "Ingrediente '{$nombreIngrediente}' añadido.";
                    }

                } elseif ($action === 'edit' && $idInventario > 0) {
                    // EDICIÓN MANUAL: Reemplazo total de los valores seleccionados
                    $sql = "UPDATE inventario SET nombre_ingrediente = ?, cantidad = ?, unidad = ?, fecha_actualizacion = ? 
                            WHERE id_inventario = ? AND id_usuario = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$nombreIngrediente, $cantidad, $unidad, $fechaActualizacion, $idInventario, $userId]);
                    
                    if ($stmt->rowCount() > 0) {
                        $message = "Cambios guardados correctamente.";
                    } else {
                        throw new Exception("No se realizaron cambios o no tienes permiso.");
                    }
                }
            }
        } elseif ($action === 'delete' && $idInventario > 0) {
            // ELIMINACIÓN: Borrado físico del registro tras validar propiedad
            $sql = "DELETE FROM inventario WHERE id_inventario = ? AND id_usuario = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$idInventario, $userId]);

            if ($stmt->rowCount() > 0) {
                $message = "Ingrediente eliminado.";
            } else {
                throw new Exception("Error al eliminar el ingrediente.");
            }
        }
        
    } catch (\PDOException $e) {
        // Error técnico: al log, no a la pantalla
        error_log("Error en inventario: " . $e->getMessage());
        $errors[] = "No se pudo guardar el cambio. Inténtalo de nuevo.";
    } catch (\Exception $e) {
        $errors[] = $e->getMessage();
    }

    // Antes los errores se perdían y la página no avisaba de nada
    if (!empty($errors)) {
        $message = implode(' ', $errors);
        $messageType = 'error';
    }

    /* ------------------------------------------------------
       PATRÓN PRG (Post-Redirect-Get)
       Evita que si el usuario refresca la página, se 
       vuelva a insertar el ingrediente duplicando la cantidad.
       ------------------------------------------------------ */
    if (empty($errors)) {
        $_SESSION['message'] = $message;
        $_SESSION['messageType'] = $messageType;
        header("Location: inventario.php");
        exit;
    }
}

/* ==========================================================
   3. CARGA DE DATOS PARA LA VISTA
   Se ejecuta siempre al cargar la página (GET)
   ========================================================== */
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['messageType'];
    unset($_SESSION['message'], $_SESSION['messageType']);
}

try {
    $stmt = $pdo->prepare("SELECT * FROM inventario WHERE id_usuario = ? ORDER BY nombre_ingrediente ASC");
    $stmt->execute([$userId]);
    $inventarioItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    $message = "Error al conectar con el inventario.";
    $messageType = 'error';
}

// Qué ingredientes de la despensa usa el plan de esta semana
require_once __DIR__ . '/../../core/recetas.php';
$stmtPlan = $pdo->prepare("SELECT DISTINCT dr.nombre_ingrediente FROM comida_planificada cp
                           JOIN detalle_receta dr ON cp.id_receta = dr.id_receta
                           JOIN plan_semanal ps ON cp.id_plan = ps.id_plan
                           WHERE ps.id_usuario = ? AND ps.fecha_inicio_semana = ?");
$stmtPlan->execute([$userId, lunesDe()]);
$clavesPlan = array_flip(array_map(fn($n) => claveProducto((string) $n), $stmtPlan->fetchAll(PDO::FETCH_COLUMN)));
$enPlan = fn(array $item) => isset($clavesPlan[claveProducto((string) $item['nombre_ingrediente'])]);
$usadosEnPlan = count(array_filter($inventarioItems, $enPlan));
$porTipo = ['peso' => 0, 'volumen' => 0, 'conteo' => 0];
foreach ($inventarioItems as $it) {
    $porTipo[in_array($it['unidad'], ['g', 'kg'], true) ? 'peso' : (in_array($it['unidad'], ['ml', 'L'], true) ? 'volumen' : 'conteo')]++;
}
$ultimaActualizacion = $inventarioItems ? max(array_column($inventarioItems, 'fecha_actualizacion')) : null;

// Carga del componente de cabecera 
include_once __DIR__ . '/../templates/header.php'; 
?>

<div class="k-container k-page">
    <div class="k-page-head">
        <div>
            <span class="k-pill"><?= icono('package') ?>Tu cocina</span>
            <h1 class="k-title mt-3">Despensa</h1>
            <p class="k-subtitle">Lo que ya tienes en casa. La lista de la compra lo descuenta, y la IA lo usa al crear recetas.</p>
        </div>
        <button type="button" onclick="abrirModalInventario('add')" class="k-btn k-btn-primary"><?= icono('plus') ?>Añadir ingrediente</button>
    </div>

    <?php if ($message): ?>
        <div class="k-alert k-alert-<?= $messageType === 'success' ? 'ok' : 'error' ?> mb-6" role="alert">
            <?= icono($messageType === 'success' ? 'check' : 'alert') ?><p><?= e($message) ?></p>
        </div>
    <?php endif; ?>

    <!-- CIFRAS -->
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="k-card flex items-center gap-4 p-5">
            <span class="k-icon-tile"><?= icono('package') ?></span>
            <div><p class="k-stat-label">Ingredientes</p><p class="k-stat-value"><?= count($inventarioItems) ?></p></div>
        </div>
        <div class="k-card flex items-center gap-4 p-5">
            <span class="k-icon-tile k-tile-indigo"><?= icono('calendar') ?></span>
            <div><p class="k-stat-label">Los usa tu plan</p><p class="k-stat-value"><?= $usadosEnPlan ?><span class="k-stat-unit">de <?= count($inventarioItems) ?></span></p></div>
        </div>
        <div class="k-card flex items-center gap-4 p-5">
            <span class="k-icon-tile k-tile-amber"><?= icono('clock') ?></span>
            <div><p class="k-stat-label">Último cambio</p><p class="font-display text-lg font-semibold k-strong"><?= $ultimaActualizacion ? e(formatearFecha($ultimaActualizacion)) : '—' ?></p></div>
        </div>
    </div>

    <?php if (empty($inventarioItems)): ?>
        <div class="k-empty">
            <span class="k-empty-icon"><?= icono('package') ?></span>
            <p class="font-medium k-strong">Tu despensa está vacía</p>
            <p class="mt-1 max-w-sm text-sm k-muted">Añade lo que tienes en casa para que la lista de la compra solo te muestre lo que falta.</p>
            <button type="button" onclick="abrirModalInventario('add')" class="k-btn k-btn-primary mt-5"><?= icono('plus') ?>Añadir el primero</button>
        </div>
    <?php else: ?>
        <div class="grid gap-6 lg:grid-cols-3">
            <section class="k-card overflow-hidden lg:col-span-2">
                <div class="k-card-head">
                    <h2 class="k-section-title">Lo que tienes</h2>
                    <label class="k-search sm:w-64">
                        <?= icono('search') ?><span class="sr-only">Buscar ingrediente</span>
                        <input type="search" id="buscadorDespensa" class="k-input h-9" placeholder="Buscar ingrediente">
                    </label>
                </div>
                <table class="k-table">
                    <thead>
                        <tr>
                            <th>Ingrediente</th>
                            <th class="text-right">Cantidad</th>
                            <th class="hidden sm:table-cell">Actualizado</th>
                            <th class="w-24"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inventarioItems as $item):
                            $cantidad = (float) $item['cantidad']; ?>
                            <tr class="fila-despensa" data-buscar="<?= e(mb_strtolower($item['nombre_ingrediente'], 'UTF-8')) ?>">
                                <td>
                                    <div class="flex items-center gap-3">
                                        <span class="k-icon-tile hidden h-9 w-9 text-sm font-semibold sm:grid" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($item['nombre_ingrediente'], 0, 1))) ?></span>
                                        <div class="min-w-0">
                                            <p class="font-medium k-strong"><?= e($item['nombre_ingrediente']) ?></p>
                                            <?php if ($enPlan($item)): ?><span class="k-badge k-badge-brand mt-1">En tu plan</span><?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-right tabular-nums">
                                    <?= floor($cantidad) == $cantidad ? number_format($cantidad, 0, ',', '.') : number_format($cantidad, 2, ',', '.') ?>
                                    <span class="k-muted"><?= e($item['unidad'] === 'unidad' ? ($cantidad == 1 ? 'unidad' : 'unidades') : $item['unidad']) ?></span>
                                </td>
                                <td class="hidden text-sm k-muted sm:table-cell"><?= e(formatearFecha($item['fecha_actualizacion'])) ?></td>
                                <td>
                                    <div class="flex justify-end gap-1">
                                        <button type="button" onclick="abrirModalInventario('edit', <?= e(json_html($item)) ?>)" class="k-btn k-btn-ghost k-btn-icon" aria-label="Editar <?= e($item['nombre_ingrediente']) ?>"><?= icono('pencil') ?></button>
                                        <button type="button" onclick="abrirModalEliminarInventario(<?= (int) $item['id_inventario'] ?>, <?= e(json_html($item['nombre_ingrediente'])) ?>)" class="k-btn k-btn-ghost k-btn-icon k-btn-danger-text" aria-label="Eliminar <?= e($item['nombre_ingrediente']) ?>"><?= icono('trash') ?></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p id="sinIngredientes" class="px-6 py-8 text-center text-sm k-muted" hidden>Ningún ingrediente coincide con la búsqueda.</p>
            </section>

            <aside class="space-y-6">
                <section class="k-card k-card-pad">
                    <h2 class="k-section-title">Por tipo</h2>
                    <?php $totalItems = max(1, count($inventarioItems)); ?>
                    <ul class="mt-4 space-y-4">
                        <?php foreach ([['peso', 'Por peso', 'g / kg', 'k-macro-p'], ['volumen', 'Líquidos', 'ml / L', 'k-macro-c'], ['conteo', 'Por unidades', 'huevos, latas…', 'k-macro-f']] as [$clave, $texto, $pista, $clase]): ?>
                            <li class="<?= $clase ?>">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="flex items-center gap-2 k-strong"><span class="k-macro-dot"></span><?= $texto ?> <span class="text-xs k-muted"><?= $pista ?></span></span>
                                    <span class="tabular-nums k-muted"><?= $porTipo[$clave] ?></span>
                                </div>
                                <div class="k-track mt-2 h-1.5"><div class="k-bar k-macro-bar" style="width: <?= round($porTipo[$clave] / $totalItems * 100) ?>%"></div></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
                <section class="k-card k-card-pad">
                    <div class="flex items-start gap-3">
                        <span class="k-icon-tile k-tile-amber"><?= icono('lightbulb') ?></span>
                        <div>
                            <p class="font-medium k-strong">Mantenla al día</p>
                            <p class="mt-1 text-sm k-muted">Así la lista de la compra solo te muestra lo que falta. Al marcar algo como comprado, se suma aquí solo.</p>
                        </div>
                    </div>
                    <a href="generar_receta.php?modo=estricto" class="k-btn k-btn-accent mt-4 w-full"><?= icono('sparkles') ?>Cocinar con lo que tengo</a>
                </section>
            </aside>
        </div>
    <?php endif; ?>
</div>

<!-- MODAL: AÑADIR / EDITAR INGREDIENTE -->
<div id="itemModal" class="k-modal" hidden role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <div class="k-modal-backdrop"></div>
    <form id="itemForm" method="POST" action="inventario.php" class="k-modal-panel">
        <?= campoCsrf() ?>
        <input type="hidden" name="action" id="actionField" value="add">
        <input type="hidden" name="id_inventario" id="idInventarioField" value="">
        <button type="button" class="k-btn k-btn-ghost k-btn-icon k-modal-close" data-cerrar-modal aria-label="Cerrar"><?= icono('x') ?></button>
        <h2 id="modal-title" class="k-modal-title">Añadir ingrediente</h2>
        <p class="mt-1 text-sm k-muted">Si ya lo tienes en la despensa, la cantidad se suma.</p>

        <div class="mt-5 space-y-4">
            <div>
                <label for="nombre_ingrediente" class="k-label">Ingrediente</label>
                <input type="text" name="nombre_ingrediente" id="nombre_ingrediente" required maxlength="200" class="k-input" placeholder="Ej. Pechuga de pollo" data-autofocus>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="cantidad" class="k-label">Cantidad</label>
                    <input type="number" name="cantidad" id="cantidad" step="0.01" min="0.01" required class="k-input" placeholder="500" inputmode="decimal">
                </div>
                <div>
                    <label for="unidad" class="k-label">Unidad</label>
                    <select name="unidad" id="unidad" required class="k-input">
                        <option value="" disabled selected>Elige…</option>
                        <?php foreach ($unidades as $key => $label): ?>
                            <option value="<?= e($key) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="k-btn k-btn-secondary" data-cerrar-modal>Cancelar</button>
            <button type="submit" id="submitButton" class="k-btn k-btn-primary">Añadir</button>
        </div>
    </form>
</div>

<!-- MODAL: ELIMINAR INGREDIENTE -->
<div id="modalBorrarIngrediente" class="k-modal" hidden role="dialog" aria-modal="true" aria-labelledby="tituloBorrarIngrediente">
    <div class="k-modal-backdrop"></div>
    <div class="k-modal-panel max-w-sm">
        <h2 id="tituloBorrarIngrediente" class="k-modal-title">¿Quitar <span id="deleteItemName"></span> de la despensa?</h2>
        <p class="mt-2 text-sm k-muted">Volverá a aparecer en la lista de la compra si alguna receta del plan lo necesita.</p>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="k-btn k-btn-secondary" data-cerrar-modal>Cancelar</button>
            <button type="button" id="confirmDeleteButton" class="k-btn k-btn-danger" data-confirmar>Quitar</button>
        </div>
    </div>
</div>

<?php include_once __DIR__ . '/../templates/footer.php'; ?>
