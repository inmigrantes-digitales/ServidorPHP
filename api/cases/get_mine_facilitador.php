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
    'SELECT c.*, u.name, u.email, u.phone, u.zone
    FROM cases c
    LEFT JOIN users u ON c.consultante_id = u.id
    WHERE c.facilitator_id = ? AND c.center_id = ?
    ORDER BY c.created_at DESC'
);
$stmt->execute([$user['id'], $user['center_id']]);
$cases = $stmt->fetchAll();

jsonSuccess($cases);
