<?php
/**
 * Funciones helper para enviar respuestas JSON estandarizadas.
 *
 * Formato exitoso:  { "success": true, "data": ... }
 * Formato error:    { "success": false, "error": "mensaje" }
 */

/**
 * Envía una respuesta JSON exitosa y termina la ejecución.
 *
 * @param mixed $data  Datos a incluir en la respuesta.
 * @param int   $code  Código HTTP (default 200).
 */
function jsonSuccess($data = null, int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Envía una respuesta JSON de error y termina la ejecución.
 *
 * @param string $message Mensaje de error.
 * @param int    $code    Código HTTP (default 400).
 */
function jsonError(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Lee el body JSON de la request y lo retorna como array asociativo.
 *
 * @return array Datos parseados del body.
 */
function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Obtiene un valor del body JSON o retorna un default.
 */
function getBodyParam(array $body, string $key, $default = null)
{
    return $body[$key] ?? $default;
}
