<?php
/**
 * Orquestador de agentes de IA.
 *
 * Selecciona el prompt adecuado, construye los mensajes para el LLM,
 * llama al modelo, parsea la respuesta JSON, y normaliza el resultado.
 *
 * Replica la lógica de src/ia/runAgent.js del backend Node.js.
 */

// Dependencias (deben estar ya incluidas por index.php o el endpoint que llama)
// require_once: json_extractor.php, llm_client.php, prompts

/**
 * Ejecuta un agente de IA con los parámetros dados.
 *
 * @param array $params [
 *   'agentType'      => 'recepcionista' | 'formulario',
 *   'userMessage'    => string,
 *   'userContext'     => array (datos del usuario si existe),
 *   'sessionContext'  => array (formData actual de la sesión),
 *   'history'         => array (historial de mensajes),
 *   'model'           => ['provider' => 'groq'|'gemini', 'name' => '...', 'temperature' => 0.7],
 *   'stream'          => bool,
 *   'onToken'         => callable|null,
 * ]
 * @return array Resultado normalizado:
 *   Si éxito: ['ok' => true, 'assistant_message' => ..., 'data' => ..., 'missing_info' => [...], 'flags' => [...]]
 *   Si error: ['ok' => false, 'error' => ..., 'assistant_message' => ..., 'data' => null]
 */
function runAgent(array $params): array
{
    $agentType      = $params['agentType'];
    $userMessage    = $params['userMessage'];
    $sessionContext = $params['sessionContext'] ?? [];
    $history        = $params['history'] ?? [];
    $model          = $params['model'];
    $stream         = $params['stream'] ?? false;
    $onToken        = $params['onToken'] ?? null;

    // 1. Obtener prompt del sistema según el tipo de agente
    $prompts = getPrompts();
    if (!isset($prompts[$agentType])) {
        return [
            'ok'                => false,
            'error'             => 'INVALID_AGENT',
            'assistant_message' => "Tipo de agente no soportado: $agentType",
            'data'              => null,
        ];
    }

    // 2. Construir system prompt
    // Para el formulario, usar la versión con datos actuales (como promptFormularioOld en Node.js)
    $currentData = $sessionContext['current_data'] ?? $sessionContext;
    $systemPrompt = getPromptFormularioWithData($currentData);

    // 3. Construir mensajes para el LLM
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
    ];

    // Agregar últimos 10 mensajes del historial
    $recentHistory = array_slice($history, -10);
    foreach ($recentHistory as $msg) {
        $messages[] = [
            'role'    => $msg['role'],
            'content' => $msg['content'],
        ];
    }

    // Agregar mensaje del usuario
    $messages[] = ['role' => 'user', 'content' => $userMessage];

    // 4. Llamar al LLM
    try {
        $rawResponse = callLLM([
            'messages' => $messages,
            'model'    => $model,
            'stream'   => $stream,
            'onToken'  => $onToken,
        ]);
    } catch (RuntimeException $e) {
        $errorType = 'API_ERROR';
        if (stripos($e->getMessage(), 'rate limit') !== false || stripos($e->getMessage(), 'Límite') !== false) {
            $errorType = 'RATE_LIMIT';
        } elseif (stripos($e->getMessage(), 'autenticación') !== false || stripos($e->getMessage(), 'API key') !== false) {
            $errorType = 'AUTH_ERROR';
        } elseif (stripos($e->getMessage(), 'conexión') !== false) {
            $errorType = 'NETWORK_ERROR';
        }

        $friendlyMessages = [
            'RATE_LIMIT'    => 'Disculpe, estamos recibiendo muchas solicitudes. Por favor, espere un momento e intente nuevamente.',
            'AUTH_ERROR'    => 'Disculpe, hay un problema de configuración con el servicio. Por favor, contacte al administrador.',
            'NETWORK_ERROR' => 'Disculpe, hay un problema de conexión. Por favor, verifique su internet e intente nuevamente.',
            'API_ERROR'     => 'Disculpe, hubo un error al procesar su solicitud. Por favor, intente nuevamente.',
        ];

        return [
            'ok'                => false,
            'error'             => $errorType,
            'errorMessage'      => $e->getMessage(),
            'assistant_message' => $friendlyMessages[$errorType] ?? $friendlyMessages['API_ERROR'],
            'data'              => null,
            'retryable'         => in_array($errorType, ['RATE_LIMIT', 'NETWORK_ERROR']),
        ];
    }

    // 5. Parsear JSON de la respuesta
    $parsed = extractFirstJSON($rawResponse);

    if ($parsed === null) {
        error_log("[runAgent] No se pudo parsear JSON. Respuesta: " . substr($rawResponse, 0, 200));
        return [
            'ok'                => false,
            'error'             => 'INVALID_JSON',
            'assistant_message' => 'Disculpe, no pude entender correctamente la respuesta. Por favor, intente reformular su mensaje.',
            'data'              => null,
        ];
    }

    // 6. Normalizar y retornar
    return [
        'ok'                => true,
        'assistant_message' => $parsed['assistant_message'] ?? '',
        'data'              => $parsed['current_data'] ?? [],
        'missing_info'      => $parsed['missing_info'] ?? [],
        'flags'             => [
            'need_confirmation' => !empty($parsed['need_confirmation']),
            'process_finished'  => !empty($parsed['process_finished']),
            'user_confirmed'    => !empty($parsed['user_confirmed']),
        ],
    ];
}
