<?php
/**
 * GET /api/cases/{id}
 *
 * Retorna un caso específico por su ID.
 * Requiere autenticación.
 *
 * Respuesta: { "success": true, "data": { ... } }
 */

$user = authRequired();

$caseId = $routeParams['id'] ?? null;
if (!$caseId || !is_numeric($caseId)) {
    jsonError('ID de caso inválido', 400);
}

$pdo = getDB();
$stmt = $pdo->prepare('SELECT * FROM cases WHERE id = ?');
$stmt->execute([$caseId]);
$case = $stmt->fetch();

if (!$case) {
    jsonError('Caso no encontrado', 404);
}

jsonSuccess($case);
