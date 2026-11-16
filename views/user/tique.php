<?php
/**
 * VISTA: AÑADIR A LA DESPENSA DESDE UNA FOTO DEL TIQUE - KaloAI
 *   POST accion=leer     sube la foto y la IA saca los productos (core/tique.php)
 *   GET                  revisión: la persona corrige, quita o añade antes de guardar
 *   POST accion=guardar  suma a la despensa los productos marcados
 * Los productos leídos esperan en la sesión hasta que se guardan o se descartan.
 */
$pageTitle = "KaloAI | Tique de la compra";

session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php'; // incluye la comprobación del token CSRF
require_once __DIR__ . '/../../core/recetas.php';
require_once __DIR__ . '/../../core/tique.php';

if (!isset($_SESSION['user_id'])) {
    redirect('../auth/login.php');
}
$userId = (int) $_SESSION['user_id'];
$accion = $_POST['accion'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'leer') {
    // Una lectura cada 15 segundos como mucho (cada una es una llamada a la IA)
    if (time() - (int) ($_SESSION['ultimo_tique'] ?? 0) < 15) {
        $_SESSION['message'] = 'Espera unos segundos antes de leer otro tique.';
        $_SESSION['messageType'] = 'error';
        redirect('inventario.php');
    }
    $foto = leerFotoTique($_FILES['foto'] ?? null);
    if (is_string($foto)) {
        $_SESSION['message'] = $foto;
        $_SESSION['messageType'] = 'error';
        redirect('inventario.php');
    }
    $_SESSION['ultimo_tique'] = time();
    $productos = normalizarProductosTique(preguntarGemini(construirPromptTique(), 60, $foto));
    unset($foto);
    if ($productos === null) {
        $_SESSION['message'] = 'La IA no ha podido leer el tique ahora mismo. Inténtalo en un rato o añade los productos a mano.';
        $_SESSION['messageType'] = 'error';
        redirect('inventario.php');
    }
    if (!$productos) {
        $_SESSION['message'] = 'No se ha encontrado ningún producto de comida en la foto. Prueba con otra más cerca y con buena luz.';
        $_SESSION['messageType'] = 'error';
        redirect('inventario.php');
    }
    $_SESSION['tique'] = $productos;
    redirect('tique.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'guardar') {
    $pdo = connectDB();
    $guardados = 0;
    $marcados = (array) ($_POST['incluir'] ?? []);
    $nombres = (array) ($_POST['nombre'] ?? []);
    $cantidades = (array) ($_POST['cantidad'] ?? []);
    $unidadesRecibidas = (array) ($_POST['unidad'] ?? []);
    try {
        $pdo->beginTransaction();
        foreach (array_slice(array_keys($nombres), 0, TIQUE_MAX_PRODUCTOS + 10) as $i) {
            if (!isset($marcados[$i])) continue;
            $nombre = mb_substr(limpiarInput((string) $nombres[$i]), 0, 100);
            $cantidad = (float) str_replace(',', '.', (string) ($cantidades[$i] ?? ''));
            $unidad = in_array($unidadesRecibidas[$i] ?? '', TIQUE_UNIDADES, true) ? $unidadesRecibidas[$i] : null;
            if ($nombre === '' || $unidad === null || $cantidad <= 0 || $cantidad > 100000) continue;
            sumarAlInventario($pdo, $userId, $nombre, $cantidad, $unidad);
            $guardados++;
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Error al guardar el tique en la despensa: ' . $e->getMessage());
        $_SESSION['message'] = 'No se ha podido guardar. Inténtalo de nuevo.';
        $_SESSION['messageType'] = 'error';
        redirect('tique.php');
    }
    unset($_SESSION['tique']);
    $_SESSION['message'] = $guardados
        ? 'Añadidos ' . $guardados . ($guardados === 1 ? ' producto' : ' productos') . ' a tu despensa. Si ya tenías alguno, se ha sumado.'
        : 'No se ha añadido nada.';
    $_SESSION['messageType'] = $guardados ? 'success' : 'error';
    redirect('inventario.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'descartar') {
    unset($_SESSION['tique']);
    redirect('inventario.php');
}

$productos = $_SESSION['tique'] ?? null;
if (!$productos) {
    redirect('inventario.php');
}
$unidades = ['g' => 'g', 'kg' => 'kg', 'ml' => 'ml', 'L' => 'L', 'unidad' => 'unidades'];
$mensajeError = ($_SESSION['messageType'] ?? '') === 'error' ? ($_SESSION['message'] ?? '') : '';
unset($_SESSION['message'], $_SESSION['messageType']);

include_once __DIR__ . '/../templates/header.php';
?>

<div class="k-container k-page max-w-3xl">
    <div class="k-page-head">
        <div>
            <span class="k-pill"><?= icono('camera') ?>Tique de la compra</span>
            <h1 class="k-title mt-3">Revisa lo que has comprado</h1>
            <p class="k-subtitle">La IA ha leído <?= count($productos) ?> <?= count($productos) === 1 ? 'producto' : 'productos' ?>. Corrige lo que haga falta y desmarca lo que no quieras guardar.</p>
        </div>
    </div>

    <?php if ($mensajeError): ?>
        <div class="k-alert k-alert-error mb-6" role="alert"><?= icono('alert') ?><p><?= e($mensajeError) ?></p></div>
    <?php endif; ?>

    <form method="POST" action="tique.php" class="k-card">
        <?= campoCsrf() ?>
        <input type="hidden" name="accion" value="guardar">
        <div class="k-card-head">
            <h2 class="k-section-title">Productos</h2>
            <button type="button" class="k-btn k-btn-ghost k-btn-sm" id="anadirFila"><?= icono('plus', 'h-4 w-4') ?>Añadir otro</button>
        </div>
        <ul class="k-list" id="filasTique">
            <?php foreach ($productos as $i => $p): ?>
                <li class="fila-tique grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-2 px-5 py-3 sm:grid-cols-[auto_minmax(0,1fr)_6rem_8.5rem] sm:px-6">
                    <input type="checkbox" name="incluir[<?= $i ?>]" value="1" checked class="h-5 w-5 accent-teal-600" aria-label="Guardar <?= e($p['nombre']) ?>">
                    <label class="min-w-0"><span class="sr-only">Nombre</span><input type="text" name="nombre[<?= $i ?>]" value="<?= e($p['nombre']) ?>" maxlength="100" required class="k-input"></label>
                    <div class="col-start-2 grid grid-cols-2 gap-2 sm:col-start-auto sm:contents">
                    <label><span class="sr-only">Cantidad</span><input type="text" inputmode="decimal" name="cantidad[<?= $i ?>]" value="<?= e(rtrim(rtrim(number_format((float) $p['cantidad'], 3, ',', ''), '0'), ',')) ?>" required class="k-input tabular-nums"></label>
                    <label><span class="sr-only">Unidad</span>
                        <select name="unidad[<?= $i ?>]" class="k-input">
                            <?php foreach ($unidades as $valor => $texto): ?><option value="<?= $valor ?>" <?= $p['unidad'] === $valor ? 'selected' : '' ?>><?= $texto ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="flex flex-col-reverse gap-2 border-t p-5 sm:flex-row sm:justify-end sm:p-6" style="border-color: var(--k-border);">
            <button type="submit" form="descartarTique" class="k-btn k-btn-ghost">Descartar</button>
            <button type="submit" class="k-btn k-btn-primary"><?= icono('package') ?>Guardar en la despensa</button>
        </div>
    </form>
    <form id="descartarTique" method="POST" action="tique.php" hidden><?= campoCsrf() ?><input type="hidden" name="accion" value="descartar"></form>
    <p class="mt-4 text-xs k-muted">Si ya tenías un producto en la despensa (con el mismo nombre y en peso, volumen o unidades), se suma a lo que había.</p>
</div>

<script>
    // «Añadir otro»: copia la última fila vacía (por si la IA se ha dejado algo)
    document.getElementById('anadirFila').addEventListener('click', () => {
        const lista = document.getElementById('filasTique');
        const modelo = lista.querySelector('.fila-tique:last-child');
        if (!modelo) return;
        const indice = Date.now() % 1000000 + 1000;
        const nueva = modelo.cloneNode(true);
        nueva.querySelectorAll('[name]').forEach(campo => {
            campo.name = campo.name.replace(/\[\d+\]/, '[' + indice + ']');
            if (campo.type === 'checkbox') { campo.checked = true; campo.setAttribute('aria-label', 'Guardar el producto nuevo'); }
            else if (campo.tagName === 'SELECT') campo.value = 'unidad';
            else campo.value = campo.inputMode === 'decimal' ? '1' : '';
        });
        lista.appendChild(nueva);
        nueva.querySelector('input[type=text]').focus();
    });
</script>

<?php include_once __DIR__ . '/../templates/footer.php'; ?>
