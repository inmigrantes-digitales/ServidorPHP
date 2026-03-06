<?php
/**
 * GET /api/users/me
 *
 * Retorna el perfil del usuario autenticado (sin password_hash).
 * Requiere autenticación.
 *
 * Respuesta: { "success": true, "data": { id, name, email, phone, dni, role, center_id, zone, address } }
 */

$user = authRequired();

$pdo = getDB();
$stmt = $pdo->prepare(
    'SELECT id, name, email, phone, dni, role, center_id, zone, address FROM users WHERE id = ?'
);
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();

if (!$profile) {
    jsonError('Usuario no encontrado', 404);
}

jsonSuccess($profile);
