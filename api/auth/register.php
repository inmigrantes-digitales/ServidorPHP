<?php
/**
 * POST /api/auth/register
 *
 * Registra un nuevo usuario en el sistema.
 *
 * Body: { "name", "email", "password", "phone", "dni"?, "role"?, "center_id"?, "zone"? }
 * Respuesta: { "success": true, "data": { id, name, email, phone, dni, role, center_id, zone } }
 */

$body = getJsonBody();

$name      = trim($body['name'] ?? '');
$email     = trim($body['email'] ?? '');
$password  = $body['password'] ?? '';
$phone     = trim($body['phone'] ?? '');
$dni       = trim($body['dni'] ?? '');
$role      = $body['role'] ?? 'consultante';
$centerId  = $body['center_id'] ?? null;
$zone      = $body['zone'] ?? null;

// ── Validación ──
if (empty($email) || empty($password) || empty($phone)) {
    jsonError('Faltan campos requeridos: email, password, phone', 400);
}

if (!isValidEmail($email)) {
    jsonError('Formato de email inválido', 400);
}

// Validar que el rol sea uno de los permitidos
$allowedRoles = ['consultante', 'facilitador', 'centro', 'admin'];
if (!in_array($role, $allowedRoles, true)) {
    jsonError('Rol no válido', 400);
}

$pdo = getDB();

// ── Verificar que no exista un usuario con el mismo email o teléfono ──
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? OR phone = ? LIMIT 1');
$stmt->execute([$email, $phone]);

if ($stmt->fetch()) {
    jsonError('Ya existe un usuario con ese email o teléfono', 409);
}

// ── Hash de contraseña (bcrypt, compatible con Node.js) ──
$passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

// ── Insertar usuario ──
$stmt = $pdo->prepare(
    'INSERT INTO users (name, email, phone, dni, password_hash, role, center_id, zone, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
);
$stmt->execute([$name, $email, $phone, $dni ?: null, $passwordHash, $role, $centerId, $zone]);
$userId = $pdo->lastInsertId();

// ── Retornar datos del usuario creado (sin password_hash) ──
$stmt = $pdo->prepare(
    'SELECT id, name, email, phone, dni, role, center_id, zone FROM users WHERE id = ?'
);
$stmt->execute([$userId]);
$newUser = $stmt->fetch();

jsonSuccess($newUser, 201);
