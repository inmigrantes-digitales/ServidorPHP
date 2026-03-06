<?php
/**
 * POST /api/problem-types
 *
 * Crea un nuevo tipo de problema.
 * TODO: Agregar autenticación en producción.
 *
 * Body: { "name", "description"? }
 * Respuesta: { "success": true, "data": { "id": ... } }
 */

$body = getJsonBody();
$name        = trim($body['name'] ?? '');
$description = trim($body['description'] ?? '');

if (empty($name)) {
    jsonError('El nombre del tipo de problema es requerido', 400);
}

$pdo = getDB();
$stmt = $pdo->prepare(
    'INSERT INTO problem_types (name, description) VALUES (?, ?)'
);
$stmt->execute([$name, $description ?: null]);
$typeId = $pdo->lastInsertId();

jsonSuccess(['id' => (int)$typeId], 201);
