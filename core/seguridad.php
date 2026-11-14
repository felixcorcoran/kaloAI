<?php

/**
 * SEGURIDAD COMÚN - KaloAI
 *
 * - Token CSRF: cada sesión tiene un token aleatorio que todo formulario y toda
 *   petición fetch deben devolver. Así otra web no puede enviar acciones en
 *   nombre de alguien que tenga la sesión abierta en KaloAI.
 * - e(): escapa texto al mostrarlo en HTML. Los datos se guardan tal cual en la
 *   base de datos y se escapan solo al pintarlos.
 *
 * utilidades.php carga este archivo y llama a exigirCsrfEnPost(), de modo que
 * cualquier POST queda protegido sin tener que acordarse en cada página.
 */

/** Escapa un valor para mostrarlo dentro de HTML (texto o atributo). */
function e($valor): string {
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Convierte un valor a JSON para incrustarlo en HTML: dentro de <script> o en un
 * atributo con comillas simples, como onclick='...'. Escapa < > & ' y las
 * comillas de los textos, para que "</script>" o un apóstrofo en el título de
 * una receta no rompan la página.
 * En un atributo con comillas dobles, envuélvelo además en e(): las comillas
 * que delimitan las claves del JSON siguen siendo comillas dobles.
 */
function json_html($valor): string {
    return json_encode($valor, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?: 'null';
}

/** Devuelve el token CSRF de la sesión actual y lo crea si aún no existe. */
function csrfToken(): string {
    if (session_status() !== PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli') {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Campo oculto para incluir dentro de cada <form method="POST">. */
function campoCsrf(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

/** Comprueba un token recibido contra el de la sesión (comparación de tiempo constante). */
function csrfValido(?string $recibido): bool {
    $esperado = $_SESSION['csrf_token'] ?? '';
    return is_string($recibido) && $esperado !== '' && hash_equals($esperado, $recibido);
}

/**
 * Corta cualquier petición POST sin un token válido. El token llega en el
 * campo csrf_token (formularios) o en la cabecera X-CSRF-Token (fetch).
 */
function exigirCsrfEnPost(): void {
    if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $recibido = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!csrfValido($recibido)) {
        http_response_code(403);
        $esJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || isset($_SERVER['HTTP_X_CSRF_TOKEN']);
        if ($esJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'La sesión ha caducado. Recarga la página.']);
        } else {
            echo 'La sesión ha caducado o el formulario no es válido. <a href="javascript:history.back()">Vuelve atrás</a>, recarga la página e inténtalo de nuevo.';
        }
        exit;
    }
}
