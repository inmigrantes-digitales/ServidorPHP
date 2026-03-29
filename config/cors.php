<?php
/**
 * Configuración de headers CORS para permitir requests cross-origin.
 * Llamar esta función al inicio de cada request (se hace desde index.php).
 */
function applyCORS(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $configuredOrigins = array_values(array_filter(array_map('trim', explode(',', CORS_ORIGIN))));
    $allowCredentials = strtolower(CORS_ALLOW_CREDENTIALS) === 'true';

    // Si CORS_ORIGIN="*", reflejamos Origin para compatibilidad con credenciales.
    $isWildcard = count($configuredOrigins) === 1 && $configuredOrigins[0] === '*';
    if ($isWildcard) {
        if ($origin !== '') {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        } else {
            header('Access-Control-Allow-Origin: *');
        }
    } elseif ($origin !== '' && in_array($origin, $configuredOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Authorization, X-Requested-With');
    header('Access-Control-Max-Age: 86400');

    if ($allowCredentials) {
        header('Access-Control-Allow-Credentials: true');
    }

    header('Content-Type: application/json; charset=utf-8');

    // Responder inmediatamente a preflight requests (OPTIONS)
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
