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
 * Valida campos obligatorios del formulario de registro de caso (IA).
 *
 * @param array $formData Datos del formulario.
 * @return array ['missing' => [...], 'errors' => [...], 'isValid' => bool]
 */
function validateRequiredFormFields(array $formData): array
{
    $missing = [];
    $errors = [];

    if (empty($formData['name']) || !isValidName((string)$formData['name'])) {
        $missing[] = 'name';
        $errors['name'] = 'Debe incluir nombre y apellido';
    }

    if (empty($formData['dni']) || !isValidDni((string)$formData['dni'])) {
        $missing[] = 'dni';
        $errors['dni'] = 'Debe ser un DNI válido (al menos 7 dígitos)';
    }

    if (empty($formData['address']) || !isValidAddress((string)$formData['address'])) {
        $missing[] = 'address';
        $errors['address'] = 'Debe incluir calle y número';
    }

    if (empty($formData['description']) || strlen(trim((string)$formData['description'])) < 10) {
        $missing[] = 'description';
        $errors['description'] = 'Debe describir el problema (al menos 10 caracteres)';
    }

    if (empty($formData['phone']) || !isValidPhone((string)$formData['phone'])) {
        $missing[] = 'phone';
        $errors['phone'] = 'Debe ser un teléfono válido (al menos 8 dígitos)';
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
