<?php
/**
 * GET /api/cases/mine/admin
 *
 * Retorna TODOS los casos del sistema (vista administrador).
 * Requiere autenticación + rol admin.
 *
 * Respuesta: { "success": true, "data": [ {...}, ... ] }
 */

$user = authRequired();
requireRole($user, 'admin');

$pdo = getDB();
$stmt = $pdo->query(
	"SELECT
		c.*,
		consultante.name AS consultante_name,
		consultante.email AS consultante_email,
		consultante.phone AS consultante_phone,
		consultante.dni AS consultante_dni,
		facilitator.name AS facilitator_name,
		facilitator.email AS facilitator_email,
		facilitator.phone AS facilitator_phone,
		facilitator.dni AS facilitator_dni,
		ce.name AS center_name,
		ce.address AS center_address,
		ce.zone AS center_zone,
		pt.name AS problem_type_name,
		pt.description AS problem_type_description
	 FROM cases c
	 LEFT JOIN users consultante ON consultante.id = c.consultante_id
	 LEFT JOIN users facilitator ON facilitator.id = c.facilitator_id
	 LEFT JOIN centers ce ON ce.id = c.center_id
	 LEFT JOIN problem_types pt ON pt.id = c.problem_type_id
	 ORDER BY c.created_at DESC"
);
$cases = $stmt->fetchAll();

jsonSuccess($cases);
