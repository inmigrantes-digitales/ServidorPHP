<?php
/**
 * Implementación de JWT (JSON Web Token) con HMAC-SHA256.
 * Sin dependencias externas — compatible con hosting compartido sin Composer.
 *
 * Payload estándar: { id, role, email, iat, exp }
 */

/**
 * Codifica una cadena en Base64 URL-safe (sin padding).
 */
function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Decodifica una cadena Base64 URL-safe.
 */
function base64UrlDecode(string $data): string
{
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Genera un token JWT para un usuario dado.
 *
 * @param array $user Debe contener al menos 'id' y 'role'. 'email' es opcional.
 * @return string Token JWT firmado.
 */
function generateToken(array $user): string
{
    $header = base64UrlEncode(json_encode([
        'alg' => 'HS256',
        'typ' => 'JWT'
    ]));

    $now = time();
    $payload = base64UrlEncode(json_encode([
        'id'    => $user['id'],
        'role'  => $user['role'],
        'email' => $user['email'] ?? null,
        'iat'   => $now,
        'exp'   => $now + JWT_EXPIRATION,
    ]));

    $signature = base64UrlEncode(
        hash_hmac('sha256', "$header.$payload", JWT_SECRET, true)
    );

    return "$header.$payload.$signature";
}

/**
 * Verifica y decodifica un token JWT.
 *
 * @param string $token Token JWT a verificar.
 * @return array|null Payload decodificado o null si es inválido/expirado.
 */
function verifyToken(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$header, $payload, $signature] = $parts;

    // Verificar firma
    $expectedSignature = base64UrlEncode(
        hash_hmac('sha256', "$header.$payload", JWT_SECRET, true)
    );

    if (!hash_equals($expectedSignature, $signature)) {
        return null;
    }

    // Decodificar payload
    $data = json_decode(base64UrlDecode($payload), true);
    if (!$data) {
        return null;
    }

    // Verificar expiración
    if (isset($data['exp']) && $data['exp'] < time()) {
        return null;
    }

    return $data;
}
