<?php
/**
 * POST /api/cases
 *
 * Crea un nuevo caso (consulta) en el sistema.
 * No requiere autenticación (personas mayores pueden registrar sin cuenta).
 *
 * Body: { "consultante_id", "facilitator_id"?, "center_id"?, "problem_type_id", "description", "input_method"? }
 * Respuesta: { "success": true, "data": { "id": ... } }
 */

$body = getJsonBody();

$consultanteId  = $body['consultante_id'] ?? null;
$facilitatorId  = $body['facilitator_id'] ?? null;
$centerId       = $body['center_id'] ?? null;
$problemTypeId  = $body['problem_type_id'] ?? null;
$description    = trim($body['description'] ?? '');
$inputMethod    = $body['input_method'] ?? 'texto';

// ── Validación básica ──
if (empty($description)) {
    jsonError('La descripción es requerida', 400);
}

$allowedMethods = ['voz', 'texto', 'centro'];
if (!in_array($inputMethod, $allowedMethods, true)) {
    $inputMethod = 'texto';
}

$pdo = getDB();

$assignedCenter = null;

if (empty($centerId) && !empty($consultanteId)) {
    $fallbackStmt = $pdo->prepare(
        'SELECT c.id, c.name FROM users u LEFT JOIN centers c ON c.id = u.center_id WHERE u.id = ? LIMIT 1'
    );
    $fallbackStmt->execute([$consultanteId]);
    $fallbackCenter = $fallbackStmt->fetch();
    if (!empty($fallbackCenter['id'])) {
        $centerId = (int)$fallbackCenter['id'];
        $assignedCenter = [
            'id' => (int)$fallbackCenter['id'],
            'name' => $fallbackCenter['name'] ?? null,
            'source' => 'user_center',
        ];
    }
}

if ($centerId && !$assignedCenter) {
    $manualCenterStmt = $pdo->prepare('SELECT id, name FROM centers WHERE id = ? LIMIT 1');
    $manualCenterStmt->execute([$centerId]);
    $manualCenter = $manualCenterStmt->fetch();
    if ($manualCenter) {
        $assignedCenter = [
            'id' => (int)$manualCenter['id'],
            'name' => $manualCenter['name'] ?? null,
            'source' => 'manual',
        ];
    }
}

$stmt = $pdo->prepare(
    'INSERT INTO cases
     (consultante_id, facilitator_id, center_id, problem_type_id, description, input_method, status, created_at)
     VALUES (?, ?, ?, ?, ?, ?, "ingresado", NOW())'
);
$stmt->execute([$consultanteId, $facilitatorId, $centerId, $problemTypeId, $description, $inputMethod]);
$caseId = $pdo->lastInsertId();

// ── Registrar en case_history ──
$stmtHistory = $pdo->prepare(
    'INSERT INTO case_history (case_id, user_id, action, new_value, comment, created_at)
     VALUES (?, ?, "caso_creado", ?, "Caso creado en el sistema", NOW())'
);
$stmtHistory->execute([
    $caseId,
    $consultanteId,
    json_encode(['status' => 'ingresado'])
]);

jsonSuccess([
    'id' => (int)$caseId,
    'center_assigned' => [
        'id' => $assignedCenter['id'] ?? null,
        'name' => $assignedCenter['name'] ?? null,
        'source' => $assignedCenter['source'] ?? null,
    ],
], 201);
