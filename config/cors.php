<?php
/**
 * Configuración de headers CORS para permitir requests cross-origin.
 * Llamar esta función al inicio de cada request (se hace desde index.php).
 */
function applyCORS(): void
{
    header('Access-Control-Allow-Origin: ' . CORS_ORIGIN);
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Credentials: true');
    header('Content-Type: application/json; charset=utf-8');

    // Responder inmediatamente a preflight requests (OPTIONS)
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}
