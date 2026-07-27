<?php
/**
 * POST /api/centers
 *
 * Crea un nuevo centro comunitario.
 * Requiere autenticación + rol admin.
 *
 * Body: { "name", "address"?, "zone"? }
 * Respuesta: { "success": true, "data": { "id": ... } }
 */

$user = authRequired();
requireRole($user, 'admin');

$body = getJsonBody();
$name    = trim($body['name'] ?? '');
$address = trim($body['address'] ?? '');
$zone    = trim($body['zone'] ?? '');

if (empty($name)) {
    jsonError('El nombre del centro es requerido', 400);
}

$pdo = getDB();
$stmt = $pdo->prepare(
    'INSERT INTO centers (name, address, zone, created_at) VALUES (?, ?, ?, NOW())'
);
$stmt->execute([$name, $address ?: null, $zone ?: null]);
$centerId = $pdo->lastInsertId();

jsonSuccess(['id' => (int)$centerId], 201);
