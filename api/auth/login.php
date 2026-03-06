<?php
/**
 * POST /api/auth/login
 *
 * Autentica un usuario con email y contraseña.
 * Retorna un token JWT y los datos del usuario.
 *
 * Body: { "email": "...", "password": "..." }
 * Respuesta: { "success": true, "data": { "token": "...", "user": {...} } }
 */

$body = getJsonBody();
$email    = trim($body['email'] ?? '');
$password = $body['password'] ?? '';

// ── Validación ──
if (empty($email) || empty($password)) {
    jsonError('Email y password son requeridos', 400);
}

$pdo = getDB();

// ── Buscar usuario por email ──
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    jsonError('Usuario no encontrado', 404);
}

// ── Verificar contraseña ──
// password_verify() de PHP es compatible con hashes bcrypt generados por Node.js
if (!password_verify($password, $user['password_hash'])) {
    jsonError('Contraseña incorrecta', 401);
}

// ── Generar token JWT ──
$token = generateToken($user);

// ── Ocultar campo sensible ──
unset($user['password_hash']);

jsonSuccess([
    'token' => $token,
    'user'  => $user,
]);
