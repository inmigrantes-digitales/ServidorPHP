<?php
/**
 * Orquestador del agente de IA (agente único).
 *
 * Construye los mensajes para el LLM usando el prompt unificado,
 * llama al modelo, parsea la respuesta JSON, y normaliza el resultado.
 *
 * Ya no hay múltiples agentes — un solo flujo maneja identificación,
 * registro y creación de tickets.
 */

/**
 * Ejecuta el agente de IA con los parámetros dados.
 *
 * @param array $params [
 *   'userMessage'    => string,
 *   'sessionContext'  => array (userData, problemData, dbUser de la sesión),
 *   'history'         => array (historial de mensajes),
 *   'model'           => ['provider' => 'groq'|'gemini', 'name' => '...', 'temperature' => 0.7],
 *   'stream'          => bool,
 *   'onToken'         => callable|null,
 * ]
 * @return array Resultado normalizado con la nueva estructura JSON.
 */
function runAgent(array $params): array
{
    $userMessage    = $params['userMessage'];
    $sessionContext = $params['sessionContext'] ?? [];
    $history        = $params['history'] ?? [];
    $model          = $params['model'];
    $stream         = $params['stream'] ?? false;
    $onToken        = $params['onToken'] ?? null;

    // 1. Construir system prompt con contexto inyectado
    $systemPrompt = getSystemPrompt($sessionContext);

    // 2. Construir mensajes para el LLM
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

    // Agregar mensaje del usuario con refuerzo de formato JSON
    $jsonReminder = "\n\nRECORDATORIO: Responde ÚNICAMENTE con un objeto JSON válido. Sin texto adicional, sin markdown, sin explicaciones. Solo el JSON.";
    $messages[] = ['role' => 'user', 'content' => $userMessage . $jsonReminder];

    // 3. Llamar al LLM
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
            'ok'    => false,
            'error' => $errorType,
            'parsed' => buildFallbackResponse($friendlyMessages[$errorType] ?? $friendlyMessages['API_ERROR']),
        ];
    }

    // 4. Parsear JSON de la respuesta
    $parsed = extractFirstJSON($rawResponse);

    // Si no se pudo parsear, reintentar UNA vez sin streaming
    if ($parsed === null) {
        error_log("[runAgent] JSON inválido en primer intento. Respuesta: " . substr($rawResponse, 0, 300));

        try {
            $retryResponse = callLLM([
                'messages'   => $messages,
                'model'      => $model,
                'stream'     => false,
                'onToken'    => null,
                'maxRetries' => 1,
            ]);
            $parsed = extractFirstJSON($retryResponse);
        } catch (RuntimeException $e) {
            error_log("[runAgent] Reintento también falló: " . $e->getMessage());
        }
    }

    if ($parsed === null) {
        error_log("[runAgent] No se pudo parsear JSON tras reintento. Respuesta original: " . substr($rawResponse, 0, 300));
        return [
            'ok'    => false,
            'error' => 'INVALID_JSON',
            'parsed' => buildFallbackResponse('Disculpe, no pude procesar su mensaje correctamente. ¿Podría intentar de nuevo?'),
        ];
    }

    // 5. Normalizar y retornar
    return [
        'ok'     => true,
        'parsed' => normalizeResponse($parsed),
    ];
}

/**
 * Normaliza la respuesta del LLM asegurando que todos los campos existan.
 */
function normalizeResponse(array $parsed): array
{
    // Asegurar estructura mínima
    $parsed['schema_version'] = $parsed['schema_version'] ?? '1.0';

    if (empty($parsed['assistant']['message'])) {
        $parsed['assistant'] = $parsed['assistant'] ?? [];
        $parsed['assistant']['message'] = $parsed['assistant']['message'] ?? 'Disculpe, no pude procesar su mensaje.';
        $parsed['assistant']['tone'] = $parsed['assistant']['tone'] ?? 'empatico';
    }

    $parsed['intent'] = $parsed['intent'] ?? ['primary' => null, 'secondary' => [], 'confidence' => 0.0];

    $parsed['data'] = $parsed['data'] ?? [];
    $parsed['data']['update'] = $parsed['data']['update'] ?? [
        'dni' => null, 'nombre' => null, 'telefono' => null,
        'email' => null, 'descripcion' => null, 'categoria' => null,
    ];
    $parsed['data']['summary'] = $parsed['data']['summary'] ?? null;

    $parsed['validation'] = $parsed['validation'] ?? ['missing_fields' => [], 'invalid_fields' => []];
    $parsed['process'] = $parsed['process'] ?? ['need_confirmation' => false, 'can_continue' => true, 'suggest_finish' => false];
    $parsed['handoff'] = $parsed['handoff'] ?? ['recommended' => false, 'target' => null, 'reason' => null];
    $parsed['meta'] = $parsed['meta'] ?? ['agent_type' => 'recepcionista', 'confidence' => 0.0, 'warnings' => []];
    $parsed['action'] = $parsed['action'] ?? 'ask_dni';

    return $parsed;
}

/**
 * Construye una respuesta JSON de fallback para errores.
 */
function buildFallbackResponse(string $message): array
{
    return [
        'schema_version' => '1.0',
        'assistant' => ['message' => $message, 'tone' => 'empatico'],
        'intent' => ['primary' => null, 'secondary' => [], 'confidence' => 0.0],
        'data' => [
            'update' => ['dni' => null, 'nombre' => null, 'telefono' => null, 'email' => null, 'descripcion' => null, 'categoria' => null],
            'summary' => null,
        ],
        'validation' => ['missing_fields' => [], 'invalid_fields' => []],
        'process' => ['need_confirmation' => false, 'can_continue' => true, 'suggest_finish' => false],
        'handoff' => ['recommended' => false, 'target' => null, 'reason' => null],
        'meta' => ['agent_type' => 'recepcionista', 'confidence' => 0.0, 'warnings' => []],
        'action' => 'ask_dni',
    ];
}
