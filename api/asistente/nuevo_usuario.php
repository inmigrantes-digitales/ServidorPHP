<?php
/**
 * GET /api/asistente/nuevoUsuario
 *
 * Endpoint para usuarios no autenticados que necesitan registrarse.
 * Utiliza runAgent con el agente "formulario" para recopilar datos.
 *
 * No requiere autenticación.
 * Query params: ?sessionId=xxx&message=xxx
 *
 * NOTA: Esta funcionalidad está marcada como WIP (Work In Progress)
 * en el backend original de Node.js. Se mantiene la misma funcionalidad parcial.
 */

$baseDir = dirname(__DIR__, 2);
require_once $baseDir . '/config/database.php';
require_once $baseDir . '/utils/response.php';
require_once $baseDir . '/utils/validation.php';
require_once $baseDir . '/ia/json_extractor.php';
require_once $baseDir . '/ia/llm_client.php';
require_once $baseDir . '/ia/session_manager.php';
require_once $baseDir . '/ia/run_agent.php';
require_once $baseDir . '/prompts/prompt_formulario.php';
require_once $baseDir . '/prompts/prompt_identidad.php';

$sessionId = $_GET['sessionId'] ?? '';
$message   = $_GET['message'] ?? '';

if (empty($sessionId) || empty($message)) {
    jsonError('sessionId y message son requeridos', 400);
}

// Cargar sesión
$session = getAISession($sessionId);

// Agregar mensaje del usuario al historial
addToHistory($session, 'user', $message);

// Log para debugging
error_log("[{$sessionId}] Datos del formulario antes de procesar: " . json_encode($session['formData']));

// Ejecutar agente
$result = runAgent([
    'agentType'       => 'formulario',
    'userMessage'     => $message,
    'userContext'     => [],
    'sessionContext'  => $session['formData'],
    'history'         => $session['history'],
    'model'           => [
        'provider'    => !empty(GROQ_API_KEY) ? 'groq' : 'gemini',
        'name'        => !empty(GROQ_API_KEY) ? 'llama-3.1-8b-instant' : 'gemini-2.0-flash-exp',
        'temperature' => 0.7,
    ],
    'stream'  => false,
    'onToken' => null,
]);

// Si el agente retornó error
if (empty($result['ok'])) {
    // Guardar sesión de todas formas
    saveAISession($sessionId, $session);
    $httpCode = ($result['error'] ?? '') === 'RATE_LIMIT' ? 429 : 500;
    http_response_code($httpCode);
    echo json_encode(['message' => $result], JSON_UNESCAPED_UNICODE);
    exit;
}

// Agregar respuesta del asistente al historial
addToHistory($session, 'assistant', $result['assistant_message'] ?? '');

// Merge de datos: mantener anteriores, actualizar con nuevos
if (!empty($result['data']) && is_array($result['data'])) {
    $previous = $session['formData'];
    foreach ($result['data'] as $key => $value) {
        if ($value !== null && $value !== '') {
            $previous[$key] = $value;
        }
    }
    $session['formData'] = $previous;
}

// Si el proceso terminó, intentar guardar (WIP)
if (!empty($result['flags']['process_finished'])) {
    error_log("[{$sessionId}] Formulario finalizado para sesión no autenticada: " . json_encode($session['formData']));
}

// Guardar sesión
saveAISession($sessionId, $session);

jsonSuccess($result);
