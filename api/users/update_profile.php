<?php
/**
 * PUT /api/users/me
 *
 * Actualiza el perfil del usuario autenticado.
 * Requiere autenticación.
 *
 * Body: { "name"?, "phone"?, "dni"?, "zone"?, "address"? }
 * Respuesta: { "success": true, "data": { id, name, email, phone, dni, role, center_id, zone, address } }
 */

$user = authRequired();

$body = getJsonBody();
$name    = $body['name'] ?? null;
$phone   = $body['phone'] ?? null;
$dni     = $body['dni'] ?? null;
$zone    = $body['zone'] ?? null;
$address = $body['address'] ?? null;

$pdo = getDB();

// ── Actualizar perfil ──
$stmt = $pdo->prepare(
    'UPDATE users SET name = ?, phone = ?, dni = ?, zone = ?, address = ?, updated_at = NOW() WHERE id = ?'
);
$stmt->execute([$name, $phone, $dni, $zone, $address, $user['id']]);

// ── Retornar perfil actualizado ──
$stmt = $pdo->prepare(
    'SELECT id, name, email, phone, dni, role, center_id, zone, address FROM users WHERE id = ?'
);
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();

jsonSuccess($profile);
