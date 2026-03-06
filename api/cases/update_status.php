<?php
/**
 * PUT /api/cases/{id}/status
 *
 * Actualiza el estado de un caso.
 * Si el nuevo status es 'ingresado', se limpia el facilitator_id (devolver a la cola).
 * Requiere autenticación + rol facilitador o admin.
 *
 * Body: { "status": "ingresado|asignado|proceso|resuelto|cerrado|escalado" }
 * Respuesta: { "success": true, "data": { "message": "Estado actualizado" } }
 */

$user = authRequired();
requireRole($user, 'facilitador', 'admin');

$caseId = $routeParams['id'] ?? null;
if (!$caseId || !is_numeric($caseId)) {
    jsonError('ID de caso inválido', 400);
}

$body = getJsonBody();
$newStatus = trim($body['status'] ?? '');

$allowedStatuses = ['ingresado', 'asignado', 'proceso', 'resuelto', 'cerrado', 'escalado'];
if (!in_array($newStatus, $allowedStatuses, true)) {
    jsonError('Estado no válido. Estados permitidos: ' . implode(', ', $allowedStatuses), 400);
}

$pdo = getDB();

// ── Obtener estado anterior ──
$stmt = $pdo->prepare('SELECT id, status, facilitator_id FROM cases WHERE id = ?');
$stmt->execute([$caseId]);
$case = $stmt->fetch();

if (!$case) {
    jsonError('Caso no encontrado', 404);
}

$previousStatus = $case['status'];

// ── Actualizar estado ──
if ($newStatus === 'ingresado') {
    // Devolver a la cola: limpiar facilitator_id
    $stmt = $pdo->prepare(
        'UPDATE cases SET status = ?, facilitator_id = NULL, updated_at = NOW() WHERE id = ?'
    );
    $stmt->execute([$newStatus, $caseId]);
} elseif ($newStatus === 'resuelto') {
    $stmt = $pdo->prepare(
        'UPDATE cases SET status = ?, resolved_at = NOW(), updated_at = NOW() WHERE id = ?'
    );
    $stmt->execute([$newStatus, $caseId]);
} elseif ($newStatus === 'cerrado') {
    $stmt = $pdo->prepare(
        'UPDATE cases SET status = ?, closed_at = NOW(), updated_at = NOW() WHERE id = ?'
    );
    $stmt->execute([$newStatus, $caseId]);
} else {
    $stmt = $pdo->prepare(
        'UPDATE cases SET status = ?, updated_at = NOW() WHERE id = ?'
    );
    $stmt->execute([$newStatus, $caseId]);
}

// ── Registrar en case_history ──
$stmtHistory = $pdo->prepare(
    'INSERT INTO case_history (case_id, user_id, action, previous_value, new_value, comment, created_at)
     VALUES (?, ?, "cambio_estado", ?, ?, ?, NOW())'
);
$stmtHistory->execute([
    $caseId,
    $user['id'],
    json_encode(['status' => $previousStatus]),
    json_encode(['status' => $newStatus]),
    "Estado cambiado de '{$previousStatus}' a '{$newStatus}'"
]);

jsonSuccess(['message' => 'Estado actualizado']);
