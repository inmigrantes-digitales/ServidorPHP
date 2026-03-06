<?php
/**
 * GET /api/cases/mine/facilitador
 *
 * Retorna los casos asignados al facilitador autenticado.
 * Requiere autenticación + rol facilitador.
 *
 * Respuesta: { "success": true, "data": [ {...}, ... ] }
 */

$user = authRequired();
requireRole($user, 'facilitador');

$pdo = getDB();
$stmt = $pdo->prepare(
    'SELECT * FROM cases WHERE facilitator_id = ? ORDER BY created_at DESC'
);
$stmt->execute([$user['id']]);
$cases = $stmt->fetchAll();

jsonSuccess($cases);
