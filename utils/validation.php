<?php
/**
 * Funciones de validación reutilizables para inputs de la API.
 */

/**
 * Valida que un email tenga formato correcto.
 */
function isValidEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Valida que un teléfono tenga al menos 8 dígitos.
 */
function isValidPhone(string $phone): bool
{
    $digits = preg_replace('/\D/', '', $phone);
    return strlen($digits) >= 8;
}

/**
 * Valida que un DNI tenga al menos 7 dígitos numéricos.
 */
function isValidDni(string $dni): bool
{
    $digits = preg_replace('/\D/', '', $dni);
    return strlen($digits) >= 7;
}

/**
 * Valida que un nombre tenga al menos 2 palabras.
 */
function isValidName(string $name): bool
{
    $words = preg_split('/\s+/', trim($name));
    return count($words) >= 2;
}

/**
 * Valida que una dirección tenga al menos 5 caracteres.
 */
function isValidAddress(string $address): bool
{
    return strlen(trim($address)) >= 5;
}

/**
 * Valida campos obligatorios para registro de usuario nuevo.
 * Usa los nombres de campos del prompt unificado (español).
 *
 * @param array $userData Datos del usuario de la sesión.
 * @return array ['missing' => [...], 'errors' => [...], 'isValid' => bool]
 */
function validateUserFields(array $userData): array
{
    $missing = [];
    $errors = [];

    if (empty($userData['nombre']) || !isValidName((string)$userData['nombre'])) {
        $missing[] = 'nombre';
        $errors['nombre'] = 'Debe incluir nombre y apellido';
    }

    if (empty($userData['dni']) || !isValidDni((string)$userData['dni'])) {
        $missing[] = 'dni';
        $errors['dni'] = 'Debe ser un DNI válido (al menos 7 dígitos)';
    }

    if (empty($userData['telefono']) || !isValidPhone((string)$userData['telefono'])) {
        $missing[] = 'telefono';
        $errors['telefono'] = 'Debe ser un teléfono válido (al menos 8 dígitos)';
    }

    return [
        'missing' => $missing,
        'errors'  => $errors,
        'isValid' => count($missing) === 0,
    ];
}

/**
 * Valida que haya una descripción de problema válida.
 *
 * @param array $problemData Datos del problema de la sesión.
 * @return array ['missing' => [...], 'errors' => [...], 'isValid' => bool]
 */
function validateProblemFields(array $problemData): array
{
    $missing = [];
    $errors = [];

    if (empty($problemData['descripcion']) || strlen(trim((string)$problemData['descripcion'])) < 10) {
        $missing[] = 'descripcion';
        $errors['descripcion'] = 'Debe describir el problema (al menos 10 caracteres)';
    }

    return [
        'missing' => $missing,
        'errors'  => $errors,
        'isValid' => count($missing) === 0,
    ];
}

/**
 * Detecta si el mensaje del usuario es una confirmación, un rechazo, o indeterminado.
 *
 * @param string $message Mensaje del usuario.
 * @return bool|null true = confirma, false = rechaza, null = indeterminado.
 */
function detectUserConfirmation(string $message): ?bool
{
    $lower = mb_strtolower(trim($message));

    $confirmations = [
        'sí', 'si', 'yes', 'correcto', 'está bien', 'esta bien',
        'está correcto', 'esta correcto', 'confirmo', 'de acuerdo',
        'ok', 'okay', 'perfecto', 'bien', 'vale'
    ];
    $rejections = [
        'no', 'incorrecto', 'está mal', 'esta mal', 'mal',
        'error', 'corregir', 'cambiar'
    ];

    foreach ($confirmations as $word) {
        if (mb_strpos($lower, $word) !== false) {
            return true;
        }
    }
    foreach ($rejections as $word) {
        if (mb_strpos($lower, $word) !== false) {
            return false;
        }
    }

    return null;
}
