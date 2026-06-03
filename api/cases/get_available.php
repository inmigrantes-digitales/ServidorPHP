<?php
/**
 * GET /api/cases/available
 *
 * Retorna los casos disponibles (sin facilitador asignado, status = 'ingresado').
 * Requiere autenticación + rol facilitador.
 *
 * Respuesta: { "success": true, "data": [ {...}, ... ] }
 */

$jwtUser = authRequired();
$user = loadAuthenticatedUser($jwtUser);
requireRole($user, 'facilitador');

if (empty($user['center_id'])) {
    jsonSuccess([]);
}

$pdo = getDB();
$stmt = $pdo->prepare(
    "SELECT * FROM cases WHERE facilitator_id IS NULL AND status = 'ingresado' AND center_id = ? ORDER BY created_at ASC"
);
$stmt->execute([$user['center_id']]);
$cases = $stmt->fetchAll();

jsonSuccess($cases);
