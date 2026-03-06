<?php
/**
 * GET /api/centers
 *
 * Lista todos los centros comunitarios.
 * No requiere autenticación.
 *
 * Respuesta: { "success": true, "data": [ {...}, ... ] }
 */

$pdo = getDB();
$stmt = $pdo->query('SELECT * FROM centers ORDER BY name');
$centers = $stmt->fetchAll();

jsonSuccess($centers);
