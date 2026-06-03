<?php
/**
 * GET /api/cases/mine/facilitador
 *
 * Retorna los casos asignados al facilitador autenticado.
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
    'SELECT * FROM cases WHERE facilitator_id = ? AND center_id = ? ORDER BY created_at DESC'
);
$stmt->execute([$user['id'], $user['center_id']]);
$cases = $stmt->fetchAll();

jsonSuccess($cases);
