<?php
/**
 * GET /api/asistente/soporte
 *
 * Endpoint para usuarios autenticados que ya tienen cuenta.
 * Utiliza runAgent para recopilar la descripción del problema.
 *
 * Requiere autenticación.
 * Query params: ?sessionId=xxx&message=xxx
 *
 * NOTA: Esta funcionalidad está marcada como WIP (Work In Progress)
 * en el backend original de Node.js. Se mantiene la misma funcionalidad parcial.
 */

$baseDir = dirname(__DIR__, 2);
require_once $baseDir . '/config/database.php';
require_once $baseDir . '/utils/response.php';
require_once $baseDir . '/utils/jwt.php';
require_once $baseDir . '/middleware/auth.php';
require_once $baseDir . '/ia/json_extractor.php';
require_once $baseDir . '/ia/llm_client.php';
require_once $baseDir . '/ia/session_manager.php';
require_once $baseDir . '/ia/run_agent.php';
require_once $baseDir . '/prompts/prompt_formulario.php';
require_once $baseDir . '/prompts/prompt_identidad.php';

// Autenticación requerida
$user = authRequired();

$message   = $_GET['message'] ?? '';
$sessionId = $_GET['sessionId'] ?? ('user-' . $user['id']);

if (empty($message)) {
    jsonError('El mensaje es requerido', 400);
}

// Obtener datos del usuario de la BD
$pdo = getDB();
$stmt = $pdo->prepare('SELECT id, name, role FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$userContext = $stmt->fetch() ?: [];

// Ejecutar agente
$result = runAgent([
    'agentType'      => 'formulario',
    'userMessage'    => $message,
    'userContext'     => $userContext,
    'sessionContext'  => [],
    'history'         => [],
    'model'           => [
        'provider'    => !empty(GROQ_API_KEY) ? 'groq' : 'gemini',
        'name'        => !empty(GROQ_API_KEY) ? 'llama-3.1-8b-instant' : 'gemini-2.0-flash-exp',
        'temperature' => 0.7,
    ],
    'stream'  => false,
    'onToken' => null,
]);

// Si el proceso terminó, intentar guardar (WIP)
if (!empty($result['flags']['process_finished'])) {
    try {
        error_log("Formulario finalizado para usuario autenticado {$user['id']}: " . json_encode($result['data']));
    } catch (Exception $e) {
        error_log("Error al guardar datos del formulario para usuario autenticado: " . $e->getMessage());
    }
}

jsonSuccess($result);
