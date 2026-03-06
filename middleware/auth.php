<?php
/**
 * Middleware de autenticación JWT.
 *
 * Lee el header Authorization: Bearer <token>, verifica el JWT,
 * y retorna los datos del usuario decodificados.
 *
 * Si el token no es válido o falta, envía error JSON y termina la ejecución.
 *
 * @return array Datos del usuario decodificados del JWT (id, role, email).
 */
function authRequired(): array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    // Algunos hostings no pasan Authorization directamente — intentar alternativas
    if (empty($header) && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
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
