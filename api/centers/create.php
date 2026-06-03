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
$latitude = isset($body['latitude']) && $body['latitude'] !== '' ? (float)$body['latitude'] : null;
$longitude = isset($body['longitude']) && $body['longitude'] !== '' ? (float)$body['longitude'] : null;

if (empty($name)) {
    jsonError('El nombre del centro es requerido', 400);
}

if ($latitude !== null && ($latitude < -90 || $latitude > 90)) {
    jsonError('Latitud inválida', 400);
}

if ($longitude !== null && ($longitude < -180 || $longitude > 180)) {
    jsonError('Longitud inválida', 400);
}

$pdo = getDB();
$stmt = $pdo->prepare(
    'INSERT INTO centers (name, address, zone, latitude, longitude, created_at) VALUES (?, ?, ?, ?, ?, NOW())'
);
$stmt->execute([$name, $address ?: null, $zone ?: null, $latitude, $longitude]);
$centerId = $pdo->lastInsertId();

jsonSuccess(['id' => (int)$centerId], 201);
