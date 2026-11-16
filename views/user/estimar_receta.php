<?php
/**
 * LÓGICA: CALCULAR CON IA LOS MACROS DE UNA RECETA ESCRITA A MANO - KaloAI
 * Recibe por POST (fetch, con el token CSRF en la cabecera) el título y los
 * ingredientes, y responde en JSON las calorías y macros por ración.
 */

session_start();
require_once __DIR__ . '/../../core/configuracion.php';
require_once __DIR__ . '/../../core/utilidades.php'; // incluye la comprobación del token CSRF
require_once __DIR__ . '/../../core/registro.php';
require_once __DIR__ . '/../../core/consultas_ia.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Inicia sesión para usar esta función.']);
    exit;
}

$titulo = trim((string) ($_POST['titulo'] ?? ''));
$ingredientes = trim((string) ($_POST['ingredientes'] ?? ''));
if ($ingredientes === '') {
    echo json_encode(['success' => false, 'error' => 'Escribe primero los ingredientes, uno por línea y con su cantidad.']);
    exit;
}

$estimacion = normalizarEstimacion(preguntarGemini(construirPromptMacrosReceta($titulo, $ingredientes)));
echo json_encode($estimacion
    ? ['success' => true] + $estimacion
    : ['success' => false, 'error' => 'La IA no ha podido calcularlo. Inténtalo de nuevo o escribe las cifras a mano.']);
