<?php
/**
 * Middleware de autenticación JWT.
 *
 * En hosting con HTTP Basic Auth (Ferozo/Donweb), Apache consume el header
 * Authorization antes de que llegue a PHP. Por eso se aceptan dos fuentes:
 *
 *   1. X-Authorization: Bearer <token>   ← recomendado cuando Basic Auth está activo
 *   2. Authorization: Bearer <token>      ← para entornos sin Basic Auth
 *
 * Si el token no es válido o falta, envía error JSON y termina la ejecución.
 *
 * @return array Datos del usuario decodificados del JWT (id, role, email).
 */
function authRequired(): array
{
    $header = '';

    // 1. Prioridad: X-Authorization (evita conflicto con Basic Auth de Apache)
    $xAuth = $_SERVER['HTTP_X_AUTHORIZATION'] ?? '';
    if (!empty($xAuth)) {
        $header = $xAuth;
    }

    // 2. Authorization estándar (funciona en entornos sin Basic Auth)
    if (empty($header)) {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    }

    // 3. Fallback: apache_request_headers() en algunos hostings
    if (empty($header) && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['X-Authorization']
            ?? $headers['x-authorization']
            ?? $headers['Authorization']
            ?? $headers['authorization']
            ?? '';
    }

    // Extraer el token del formato "Bearer <token>"
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        jsonError('Token requerido', 401);
    }

    $token = $matches[1];
    $decoded = verifyToken($token);

    if ($decoded === null) {
        jsonError('Token inválido o expirado', 403);
    }

    return $decoded;
}
