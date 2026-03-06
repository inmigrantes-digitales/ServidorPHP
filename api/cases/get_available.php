<?php
/**
 * GET /api/cases/available
 *
 * Retorna los casos disponibles (sin facilitador asignado, status = 'ingresado').
 * Requiere autenticación + rol facilitador.
 *
 * Respuesta: { "success": true, "data": [ {...}, ... ] }
 */

$user = authRequired();
requireRole($user, 'facilitador');

$pdo = getDB();
$stmt = $pdo->query(
    "SELECT * FROM cases WHERE facilitator_id IS NULL AND status = 'ingresado' ORDER BY created_at ASC"
);
$cases = $stmt->fetchAll();

jsonSuccess($cases);
