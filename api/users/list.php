<?php
/**
 * GET /api/users
 *
 * Lista usuarios del sistema.
 * - admin: ve todos los usuarios
 * - centro: ve solo usuarios de su centro
 *
 * Respuesta: { "success": true, "data": [ {...}, ... ] }
 */

$jwtUser = authRequired();
$authUser = loadAuthenticatedUser($jwtUser);
requireRole($authUser, 'admin', 'centro');

$pdo = getDB();

$sql =
    'SELECT
        u.id,
        u.name,
        u.email,
        u.phone,
        u.dni,
        u.role,
        u.center_id,
        u.zone,
        u.address,
        u.created_at,
        c.name AS center_name,
        c.zone AS center_zone
     FROM users u
     LEFT JOIN centers c ON c.id = u.center_id';

if ($authUser['role'] === 'centro') {
    if (empty($authUser['center_id'])) {
        jsonSuccess([]);
    }

    $stmt = $pdo->prepare($sql . ' WHERE u.center_id = ? ORDER BY u.created_at DESC');
    $stmt->execute([$authUser['center_id']]);
} else {
    $stmt = $pdo->query($sql . ' ORDER BY u.created_at DESC');
}

$users = $stmt->fetchAll();

jsonSuccess($users);
