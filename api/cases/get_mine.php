<?php
/**
 * GET /api/cases/mine
 *
 * Retorna los casos del usuario consultante autenticado.
 * Requiere autenticación.
 *
 * Respuesta: { "success": true, "data": [ {...}, ... ] }
 */

$user = authRequired();

$pdo = getDB();
$stmt = $pdo->prepare(
    'SELECT * FROM cases WHERE consultante_id = ? ORDER BY created_at DESC'
);
$stmt->execute([$user['id']]);
$cases = $stmt->fetchAll();

jsonSuccess($cases);
