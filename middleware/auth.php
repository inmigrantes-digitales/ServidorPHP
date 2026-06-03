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

/**
 * Carga el usuario autenticado desde la base de datos para obtener
 * campos actualizados como center_id y validar que el usuario siga activo.
 *
 * @param array $jwtUser Datos decodificados del JWT.
 * @return array Usuario actual desde la tabla users.
 */
function loadAuthenticatedUser(array $jwtUser): array
{
    if (empty($jwtUser['id'])) {
        jsonError('Token inválido: usuario no identificado', 403);
    }

    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT id, name, email, phone, dni, role, center_id, zone, address FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$jwtUser['id']]);
    $dbUser = $stmt->fetch();

    if (!$dbUser) {
        jsonError('Usuario no encontrado', 404);
    }

    return $dbUser;
}
