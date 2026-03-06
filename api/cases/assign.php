<?php
/**
 * POST /api/cases/{id}/assign
 *
 * Asigna un caso al facilitador autenticado.
 * Cambia el status a 'proceso' y registra assigned_at.
 * Requiere autenticación + rol facilitador.
 *
 * Respuesta: { "success": true, "data": { "message": "Caso asignado" } }
 */

$user = authRequired();
requireRole($user, 'facilitador');

// $routeParams['id'] es inyectado por el router (index.php)
$caseId = $routeParams['id'] ?? null;
if (!$caseId || !is_numeric($caseId)) {
    jsonError('ID de caso inválido', 400);
}

$pdo = getDB();

// ── Verificar que el caso existe y está disponible ──
$stmt = $pdo->prepare('SELECT id, status, facilitator_id FROM cases WHERE id = ?');
$stmt->execute([$caseId]);
$case = $stmt->fetch();

if (!$case) {
    jsonError('Caso no encontrado', 404);
}

if ($case['facilitator_id'] !== null) {
    jsonError('El caso ya tiene un facilitador asignado', 409);
}

// ── Asignar facilitador ──
$stmt = $pdo->prepare(
    "UPDATE cases SET facilitator_id = ?, assigned_at = NOW(), status = 'proceso' WHERE id = ?"
);
$stmt->execute([$user['id'], $caseId]);

// ── Registrar en case_history ──
$stmtHistory = $pdo->prepare(
    'INSERT INTO case_history (case_id, user_id, action, previous_value, new_value, comment, created_at)
     VALUES (?, ?, "caso_asignado", ?, ?, "Facilitador tomó el caso", NOW())'
);
$stmtHistory->execute([
    $caseId,
    $user['id'],
    json_encode(['status' => $case['status'], 'facilitator_id' => null]),
    json_encode(['status' => 'proceso', 'facilitator_id' => $user['id']])
]);

jsonSuccess(['message' => 'Caso asignado']);
