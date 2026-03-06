<?php
/**
 * GET /api/problem-types
 *
 * Lista todos los tipos de problemas.
 * No requiere autenticación.
 *
 * Respuesta: { "success": true, "data": [ {...}, ... ] }
 */

$pdo = getDB();
$stmt = $pdo->query('SELECT * FROM problem_types ORDER BY name');
$types = $stmt->fetchAll();

jsonSuccess($types);
