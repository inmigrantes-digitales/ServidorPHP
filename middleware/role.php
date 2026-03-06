<?php
/**
 * Middleware de verificación de roles.
 *
 * Verifica que el usuario autenticado tenga uno de los roles permitidos.
 * Debe llamarse DESPUÉS de authRequired().
 *
 * @param array  $user  Datos del usuario del JWT (retornados por authRequired).
 * @param string ...$roles Roles permitidos (e.g., 'admin', 'facilitador').
 */
function requireRole(array $user, string ...$roles): void
{
    if (empty($user['role']) || !in_array($user['role'], $roles, true)) {
        jsonError('Acceso denegado', 403);
    }
}
