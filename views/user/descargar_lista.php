<?php
/**
 * LÓGICA: GENERACIÓN DE PDF DE COMPRA - KaloAI
 * Este script calcula la diferencia entre los ingredientes necesarios para el 
 * plan semanal y el inventario actual del usuario, generando un PDF descargable.
 */

session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php';
require_once __DIR__ . '/../../core/recetas.php';

// Carga de Dompdf vía Composer (Asegúrate de haber ejecutado 'composer require dompdf/dompdf')
require_once __DIR__ . '/../../vendor/autoload.php'; 

use Dompdf\Dompdf;
use Dompdf\Options;

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$pdo = connectDB();

/* ==========================================================================
   1. CÁLCULO DE NECESIDADES VS. INVENTARIO
   ========================================================================== */

// Semana de la lista: la actual o la que se pida (?semana=AAAA-MM-DD)
$semana = semanaValida($_GET['semana'] ?? null);
['lista' => $listaCompra, 'comensales' => $comensales] = listaCompraDeSemana($pdo, (int) $userId, $semana);

/* ==========================================================================
   2. CONSTRUCCIÓN DEL HTML PARA EL PDF
   ========================================================================== */

if (empty($listaCompra)) {
    die("¡Genial! Tienes todo lo necesario en tu despensa.");
}

$html = '
<html>
<head>
<style>
    body { font-family: "Helvetica", sans-serif; color: #1a1a1a; padding: 30px; }
    .header { text-align: center; border-bottom: 3px solid #0d9488; padding-bottom: 15px; margin-bottom: 25px; }
    .header h1 { color: #0d9488; text-transform: uppercase; letter-spacing: 2px; margin: 0; }
    .item { border-bottom: 1px solid #e5e7eb; padding: 12px 0; clear: both; }
    .qty { float: right; font-weight: bold; background: #f3f4f6; padding: 4px 10px; border-radius: 5px; }
    .cb { display: inline-block; width: 16px; height: 16px; border: 2px solid #0d9488; margin-right: 12px; vertical-align: middle; border-radius: 3px; }
    .name { vertical-align: middle; font-weight: 600; font-size: 14px; }
    .seccion { margin: 16px 0 6px; font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; color: #0d9488; }
</style>
</head>
<body>
    <div class="header">
        <h1>Compra KaloAI</h1>
        <p>' . htmlspecialchars(nombreSemana($semana), ENT_QUOTES, 'UTF-8') . ' (semana del ' . date('d/m', strtotime($semana)) . ') · generada el ' . date('d/m/Y') . '</p>
    </div>';

// Agrupada por secciones del súper (los emojis no se ven bien en el PDF: solo el nombre)
foreach (agruparPorSeccion($listaCompra) as $seccion => $productos) {
    $html .= '<div class="seccion">' . htmlspecialchars($seccion) . '</div>';
    foreach ($productos as $item) {
        $html .= '
    <div class="item">
        <span class="cb"></span>
        <span class="name">' . htmlspecialchars($item['nombre']) . '</span>
        <span class="qty">' . (float)round($item['comprar'], 2) . ' ' . e($item['unidad']) . '</span>
    </div>';
    }
}

$html .= '</body></html>';

/* ==========================================================================
   3. RENDERIZADO Y STREAMING DEL PDF
   ========================================================================== */
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A5', 'portrait'); // Formato A5: ideal para llevar en el móvil o imprimir pequeño
$dompdf->render();

// Forzamos la descarga del archivo
$dompdf->stream("Lista_KaloAI_" . date('d_m_Y') . ".pdf", ["Attachment" => true]);