<?php
/**
 * Cliente cURL para comunicarse con APIs de LLM (Groq y Gemini).
 *
 * Soporta:
 *   - Llamadas no-streaming (respuesta completa)
 *   - Llamadas streaming (SSE, chunk por chunk)
 *   - Retry con backoff exponencial para errores recuperables
 *   - Clasificación de errores (rate limit, auth, server, network)
 */

/**
 * Clasifica un error de cURL/API para decidir si es recuperable.
 *
 * @param int    $httpCode    Código HTTP de respuesta.
 * @param string $errorMsg    Mensaje de error.
 * @param string $provider    'groq' o 'gemini'.
 * @return array ['type', 'message', 'retryable', 'statusCode']
 */
function classifyLLMError(int $httpCode, string $errorMsg, string $provider): array
{
    if ($httpCode === 429 || stripos($errorMsg, 'rate limit') !== false) {
        return [
            'type'       => 'RATE_LIMIT',
            'message'    => "Límite de solicitudes alcanzado para $provider.",
            'retryable'  => true,
            'statusCode' => 429,
        ];
    }
    if ($httpCode === 401 || $httpCode === 403 || stripos($errorMsg, 'API key') !== false) {
        return [
            'type'       => 'AUTH_ERROR',
            'message'    => "Error de autenticación con $provider. Verifique su API key.",
            'retryable'  => false,
            'statusCode' => $httpCode ?: 401,
        ];
    }
    if ($httpCode >= 500 && $httpCode < 600) {
        return [
            'type'       => 'SERVER_ERROR',
            'message'    => "Error del servidor de $provider. Intente nuevamente más tarde.",
            'retryable'  => true,
            'statusCode' => $httpCode,
        ];
    }
    if ($httpCode === 400 || stripos($errorMsg, 'invalid') !== false) {
        return [
            'type'       => 'BAD_REQUEST',
            'message'    => "Solicitud inválida a $provider: $errorMsg",
            'retryable'  => false,
            'statusCode' => 400,
        ];
    }
    if (stripos($errorMsg, 'Could not resolve') !== false || stripos($errorMsg, 'timed out') !== false) {
        return [
            'type'       => 'NETWORK_ERROR',
            'message'    => "Error de conexión con $provider.",
            'retryable'  => true,
            'statusCode' => 0,
        ];
    }

    return [
        'type'       => 'UNKNOWN_ERROR',
        'message'    => "Error al comunicarse con $provider: $errorMsg",
        'retryable'  => false,
        'statusCode' => $httpCode ?: 0,
    ];
}

/**
 * Llama a la API de Groq (compatible con formato OpenAI).
 *
 * @param array    $messages    Array de mensajes [{role, content}, ...]
 * @param string   $model       Nombre del modelo (e.g., 'llama-3.1-8b-instant')
 * @param float    $temperature Temperatura de generación.
 * @param bool     $stream      Si true, usa streaming y llama a $onToken por cada chunk.
 * @param callable|null $onToken Callback para streaming: function(string $text): void
 * @return string Texto completo generado.
 * @throws RuntimeException En caso de error no recuperable.
 */
function callGroq(array $messages, string $model, float $temperature = 0.7, bool $stream = false, ?callable $onToken = null): string
{
    if (empty(GROQ_API_KEY)) {
        throw new RuntimeException('GROQ_API_KEY no configurada');
    }

    $url = 'https://api.groq.com/openai/v1/chat/completions';
    $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => $temperature,
        'stream'      => $stream,
    ];

    return callOpenAICompatible($url, GROQ_API_KEY, $payload, $stream, $onToken, 'groq');
}

/**
 * Llama a la API de Gemini (Google Generative AI REST API).
 *
 * @param array    $messages    Array de mensajes [{role, content}, ...]
 * @param string   $model       Nombre del modelo (e.g., 'gemini-2.0-flash-exp')
 * @param float    $temperature Temperatura de generación.
 * @param bool     $stream      Si true, usa streaming.
 * @param callable|null $onToken Callback para streaming.
 * @return string Texto completo generado.
 * @throws RuntimeException En caso de error no recuperable.
 */
function callGemini(array $messages, string $model, float $temperature = 0.7, bool $stream = false, ?callable $onToken = null): string
{
    if (empty(GEMINI_API_KEY)) {
        throw new RuntimeException('GEMINI_API_KEY no configurada');
    }

    $candidates = [$model];

    // Muchos modelos experimentales terminan en "-exp" y pueden no estar disponibles.
    if (str_ends_with($model, '-exp')) {
        $candidates[] = substr($model, 0, -4);
    }

    // Fallback estable para evitar 404 por modelo no encontrado.
    $candidates[] = 'gemini-1.5-flash';
    $candidates = array_values(array_unique(array_filter($candidates)));

    $lastException = null;
    $lastIndex = count($candidates) - 1;

    foreach ($candidates as $index => $candidateModel) {
        try {
            return callGeminiRequest($messages, $candidateModel, $temperature, $stream, $onToken);
        } catch (RuntimeException $e) {
            $lastException = $e;

            // Reintentar solo para "model not found" (HTTP 404).
            if (stripos($e->getMessage(), 'HTTP 404') !== false && $index < $lastIndex) {
                error_log("[Gemini] Modelo no encontrado: {$candidateModel}. Reintentando con fallback...");
                continue;
            }

            throw $e;
        }
    }

    throw $lastException ?? new RuntimeException('Error desconocido al llamar a Gemini');
}

/**
 * Ejecuta una llamada Gemini con un modelo específico.
 */
function callGeminiRequest(array $messages, string $model, float $temperature = 0.7, bool $stream = false, ?callable $onToken = null): string
{
    if (empty(GEMINI_API_KEY)) {
        throw new RuntimeException('GEMINI_API_KEY no configurada');
    }

    // El streaming nativo de Gemini puede llegar en fragmentos JSON parciales y
    // terminar duplicando texto al parsearlo manualmente. Para estabilidad,
    // pedimos respuesta completa y la reenviamos como un unico chunk SSE.
    if ($stream && $onToken !== null) {
        $fullText = callGeminiRequest($messages, $model, $temperature, false, null);
        if ($fullText !== '') {
            $onToken($fullText);
        }
        return $fullText;
    }

    // Convertir formato OpenAI a formato Gemini
    $systemInstruction = '';
    $contents = [];

    foreach ($messages as $msg) {
        if ($msg['role'] === 'system') {
            $systemInstruction .= $msg['content'] . "\n\n";
        } else {
            $role = ($msg['role'] === 'assistant') ? 'model' : 'user';
            $contents[] = [
                'role'  => $role,
                'parts' => [['text' => $msg['content']]],
            ];
        }
    }

    if (empty($contents)) {
        throw new RuntimeException('No hay mensajes para enviar a Gemini.');
    }

    $endpoint = $stream ? 'streamGenerateContent' : 'generateContent';
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:{$endpoint}?key=" . GEMINI_API_KEY;

    $payload = ['contents' => $contents];
    if (!empty($systemInstruction)) {
        $payload['systemInstruction'] = [
            'parts' => [['text' => trim($systemInstruction)]]
        ];
    }
    $payload['generationConfig'] = ['temperature' => $temperature];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    if ($stream && $onToken !== null) {
        // Streaming: leer respuesta chunk por chunk
        $fullText = '';
        $buffer = '';
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$fullText, &$buffer, $onToken) {
            $buffer .= $data;

            // Gemini streaming envía un array JSON — parsear fragmentos
            // Intentar extraer textos del buffer
            if (preg_match_all('/"text"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $buffer, $matches)) {
                foreach ($matches[1] as $text) {
                    $decoded = stripcslashes($text);
                    if (!empty($decoded)) {
                        $fullText .= $decoded;
                        $onToken($decoded);
                    }
                }
                // Limpiar buffer: mantener solo lo que no se pudo parsear
                $lastPos = strrpos($buffer, '"text"');
                if ($lastPos !== false) {
                    $buffer = substr($buffer, $lastPos);
                }
            }

            return strlen($data);
        });

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 400 || !empty($error)) {
            $errorInfo = classifyLLMError($httpCode, $error ?: "HTTP $httpCode", 'gemini');
            throw new RuntimeException($errorInfo['message']);
        }

        return $fullText;
    }

    // No streaming: obtener respuesta completa
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode >= 400 || $response === false) {
        $errorInfo = classifyLLMError($httpCode, $error ?: ($response ?: "HTTP $httpCode"), 'gemini');
        throw new RuntimeException($errorInfo['message']);
    }

    $data = json_decode($response, true);
    return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
}

/**
 * Función genérica para APIs compatibles con formato OpenAI (Groq).
 *
 * @param string        $url      URL del endpoint.
 * @param string        $apiKey   API key para autenticación.
 * @param array         $payload  Body del request.
 * @param bool          $stream   Si true, usa streaming.
 * @param callable|null $onToken  Callback para streaming.
 * @param string        $provider Nombre del proveedor (para mensajes de error).
 * @return string Texto completo generado.
 */
function callOpenAICompatible(string $url, string $apiKey, array $payload, bool $stream, ?callable $onToken, string $provider): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    if ($stream && $onToken !== null) {
        // Streaming: leer líneas SSE
        $fullText = '';
        $buffer = '';
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$fullText, &$buffer, $onToken) {
            $buffer .= $data;

            // Procesar líneas SSE completas
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = trim($line);

                if (empty($line) || $line === 'data: [DONE]') {
                    continue;
                }

                if (strpos($line, 'data: ') === 0) {
                    $json = json_decode(substr($line, 6), true);
                    $delta = $json['choices'][0]['delta']['content'] ?? null;
                    if ($delta !== null) {
                        $fullText .= $delta;
                        $onToken($delta);
                    }
                }
            }

            return strlen($data);
        });

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 400 || !empty($error)) {
            $errorInfo = classifyLLMError($httpCode, $error ?: "HTTP $httpCode", $provider);
            throw new RuntimeException($errorInfo['message']);
        }

        return $fullText;
    }

    // No streaming
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode >= 400 || $response === false) {
        $body = $response ?: $error ?: "HTTP $httpCode";
        $errorInfo = classifyLLMError($httpCode, $body, $provider);
        throw new RuntimeException($errorInfo['message']);
    }

    $data = json_decode($response, true);
    return $data['choices'][0]['message']['content'] ?? '';
}

/**
 * Función principal unificada: llama al LLM correcto según la configuración del modelo.
 *
 * @param array $params [
 *   'messages'    => [...],
 *   'model'       => ['provider' => 'groq'|'gemini', 'name' => '...', 'temperature' => 0.7],
 *   'stream'      => bool,
 *   'onToken'     => callable|null,
 *   'maxRetries'  => int (default 2),
 * ]
 * @return string Texto completo generado.
 * @throws RuntimeException Si todos los reintentos fallan.
 */
function callLLM(array $params): string
{
    $messages    = $params['messages'];
    $model       = $params['model'];
    $stream      = $params['stream'] ?? false;
    $onToken     = $params['onToken'] ?? null;
    $maxRetries  = $params['maxRetries'] ?? 2;
    $temperature = $model['temperature'] ?? 0.7;

    $lastException = null;

    for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
        try {
            switch ($model['provider']) {
                case 'groq':
                    return callGroq($messages, $model['name'], $temperature, $stream, $onToken);
                case 'gemini':
                    return callGemini($messages, $model['name'], $temperature, $stream, $onToken);
                default:
                    throw new RuntimeException("Proveedor IA no soportado: {$model['provider']}");
            }
        } catch (RuntimeException $e) {
            $lastException = $e;
            $errorInfo = classifyLLMError(0, $e->getMessage(), $model['provider']);

            // Si no es recuperable o es el último intento, lanzar
            if (!$errorInfo['retryable'] || $attempt === $maxRetries) {
                throw $e;
            }

            // Backoff exponencial: 1s, 2s, 4s
            $delay = (int)(1000000 * pow(2, $attempt)); // microsegundos
            error_log("[LLM] Intento " . ($attempt + 1) . "/" . ($maxRetries + 1) . " falló. Reintentando...");
            usleep($delay);
        }
    }

    throw $lastException ?? new RuntimeException('Error desconocido al llamar LLM');
}
