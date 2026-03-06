<?php
/**
 * GET /api/asistente/stream
 *
 * Endpoint principal del asistente conversacional con streaming SSE.
 * Implementa dos modos:
 *   1. Recepcionista: Identifica si el usuario es nuevo o existente.
 *   2. Formulario: Recopila datos para crear usuario y caso.
 *
 * Query params: ?sessionId=xxx&message=xxx
 *
 * Portado desde: src/controllers/test.chatStream.controller.js
 */

// ── Dependencias ──
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

// ── Parámetros ──
$sessionId = $_GET['sessionId'] ?? '';
$message   = $_GET['message'] ?? '';

if (empty($sessionId) || empty($message)) {
    jsonError('sessionId y message son requeridos', 400);
}

// ── Configurar SSE ──
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Nginx: desactivar buffering

// Desactivar buffering de PHP para streaming real
if (ob_get_level()) ob_end_clean();
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);
set_time_limit(120);

/**
 * Envía un evento SSE al cliente.
 */
function sendSSE(string $data): void
{
    echo "data: {$data}\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

// ── Cargar o crear sesión ──
$session = getAISession($sessionId);

// Agregar mensaje del usuario al historial
addToHistory($session, 'user', $message);

try {
    // ── Determinar modo actual ──
    $mode = $session['mode'] ?? 'recepcionista';

    if ($mode === 'recepcionista') {
        handleRecepcionistaMode($session, $message, $sessionId);
    } else {
        handleFormularioMode($session, $message, $sessionId);
    }
} catch (Exception $e) {
    error_log("Error en asistente SSE: " . $e->getMessage());
    sendSSE('__JSON__START__' . json_encode([
        'assistant_message'  => 'Disculpe, hubo un error interno. Por favor, intente nuevamente.',
        'update_json'        => new stdClass(),
        'missing_info'       => [],
        'need_confirmation'  => false,
        'user_confirmed'     => false,
        'process_finished'   => false,
        'form_summary'       => null,
    ], JSON_UNESCAPED_UNICODE));
}

// ── Guardar sesión actualizada ──
saveAISession($sessionId, $session);
exit;

/* ============================================================
   MODO RECEPCIONISTA
   ============================================================ */
function handleRecepcionistaMode(array &$session, string $message, string $sessionId): void
{
    $prompt = getPromptIdentidadUsuario();

    // Construir prompt completo con historial y datos actuales
    $fullPrompt = $prompt . "\n\nHistorial de la conversación:\n"
        . json_encode(array_slice($session['history'], -10), JSON_UNESCAPED_UNICODE)
        . "\n\nDatos recopilados hasta ahora:\n"
        . json_encode($session['userData'] ?? new stdClass(), JSON_UNESCAPED_UNICODE)
        . "\n\nNuevo mensaje del usuario:\n\"{$message}\""
        . "\n\nINSTRUCCIONES:\n- Analiza el mensaje y determina si el usuario es nuevo o tiene cuenta.\n"
        . "- Si el usuario proporciona DNI, verifica en tu respuesta si parece válido.\n"
        . "- Responde siempre con el JSON especificado en el prompt.";

    $messages = [
        ['role' => 'system', 'content' => $prompt],
        ['role' => 'user',   'content' => $fullPrompt],
    ];

    // Llamar al LLM con streaming
    $fullText = '';
    try {
        $fullText = callLLM([
            'messages' => $messages,
            'model'    => [
                'provider'    => !empty(GROQ_API_KEY) ? 'groq' : 'gemini',
                'name'        => !empty(GROQ_API_KEY) ? 'llama-3.1-8b-instant' : 'gemini-2.0-flash-exp',
                'temperature' => 0.7,
            ],
            'stream'  => true,
            'onToken' => function (string $text) {
                sendSSE($text);
            },
        ]);
    } catch (RuntimeException $e) {
        error_log("Error LLM recepcionista: " . $e->getMessage());
        $fallback = [
            'assistant_message' => 'Disculpe, hubo un error. ¿Podría repetir su mensaje?',
            'data'              => ['dni' => null, 'description' => null],
            'status'            => ['is_new_user' => false, 'has_dni' => false, 'has_description' => false],
            'action_needed'     => 'continue',
            'process_finished'  => false,
        ];
        sendSSE('__JSON__START__' . json_encode($fallback, JSON_UNESCAPED_UNICODE));
        return;
    }

    // Parsear respuesta JSON
    $parsed = extractFirstJSON($fullText);

    if (!$parsed) {
        $fallback = [
            'assistant_message' => 'Disculpe, hubo un error. ¿Podría repetir su mensaje?',
            'data'              => ['dni' => null, 'description' => null],
            'status'            => ['is_new_user' => false, 'has_dni' => false, 'has_description' => false],
            'action_needed'     => 'continue',
            'process_finished'  => false,
        ];
        sendSSE('__JSON__START__' . json_encode($fallback, JSON_UNESCAPED_UNICODE));
        return;
    }

    // Normalizar estructura
    if (empty($parsed['assistant_message'])) $parsed['assistant_message'] = 'Disculpe, no pude procesar su mensaje.';
    if (empty($parsed['data'])) $parsed['data'] = ['dni' => null, 'description' => null];
    if (empty($parsed['status'])) $parsed['status'] = ['is_new_user' => false, 'has_dni' => false, 'has_description' => false];
    if (empty($parsed['action_needed'])) $parsed['action_needed'] = 'continue';
    if (!isset($parsed['process_finished'])) $parsed['process_finished'] = false;

    // Actualizar datos de sesión
    if (!empty($parsed['data']['dni'])) {
        $session['userData'] = $session['userData'] ?? [];
        $session['userData']['dni'] = $parsed['data']['dni'];
    }
    if (!empty($parsed['data']['description'])) {
        $session['userData'] = $session['userData'] ?? [];
        $session['userData']['description'] = $parsed['data']['description'];
    }

    // Manejar acciones
    if ($parsed['action_needed'] === 'register_new_user') {
        // Usuario nuevo → cambiar a modo formulario
        $session['mode'] = 'formulario';
        $parsed['assistant_message'] .= "\n\nAhora necesito algunos datos para crear su cuenta. Empecemos...";
        $parsed['mode_changed'] = true;
        $parsed['new_mode'] = 'formulario';

    } elseif ($parsed['action_needed'] === 'save_problem') {
        // Usuario existente con DNI + descripción → verificar BD y guardar
        $dni         = $parsed['data']['dni'] ?? null;
        $description = $parsed['data']['description'] ?? null;

        if ($dni && $description) {
            $existingUser = checkUserExistsByDni($dni);

            if ($existingUser) {
                try {
                    $caseId = saveUserProblemToDB($existingUser['id'], $description, $sessionId);
                    $parsed['assistant_message'] = "¡Perfecto {$existingUser['name']}! He registrado su consulta sobre: \"{$description}\". En breve un facilitador se pondrá en contacto con usted para ayudarlo. ¡Que tenga un excelente día!";
                    $parsed['process_finished'] = true;
                    $session['userData']['userId'] = $existingUser['id'];
                } catch (Exception $e) {
                    error_log("Error guardando problema: " . $e->getMessage());
                    $parsed['assistant_message'] = 'Disculpe, hubo un error al guardar su consulta. Por favor, intente nuevamente.';
                    $parsed['action_needed'] = 'continue';
                    $parsed['process_finished'] = false;
                }
            } else {
                // Usuario no encontrado → derivar a formulario
                $parsed['assistant_message'] = 'No encontré su cuenta en nuestro sistema. Necesito crearle una cuenta primero. Por favor, proporcione sus datos...';
                $session['mode'] = 'formulario';
                $parsed['mode_changed'] = true;
                $parsed['new_mode'] = 'formulario';
                $parsed['action_needed'] = 'register_new_user';
                $parsed['process_finished'] = false;
            }
        }
    } elseif (!empty($parsed['status']['has_dni']) && !empty($parsed['data']['dni'])) {
        // Tenemos DNI → verificar en BD
        $existingUser = checkUserExistsByDni($parsed['data']['dni']);
        if ($existingUser) {
            $session['userData'] = array_merge($session['userData'] ?? [], [
                'userId' => $existingUser['id'],
                'name'   => $existingUser['name'],
            ]);
        } else {
            $parsed['assistant_message'] = 'No encontré su cuenta en nuestro sistema. ¿Es la primera vez que usa nuestro servicio?';
            $parsed['status']['is_new_user'] = true;
        }
    }

    addToHistory($session, 'assistant', $parsed['assistant_message']);
    sendSSE('__JSON__START__' . json_encode($parsed, JSON_UNESCAPED_UNICODE));
}

/* ============================================================
   MODO FORMULARIO
   ============================================================ */
function handleFormularioMode(array &$session, string $message, string $sessionId): void
{
    // Manejar confirmación pendiente
    if (!empty($session['awaitingConfirmation'])) {
        $confirmation = detectUserConfirmation($message);

        if ($confirmation === true) {
            // Usuario confirmó → guardar y finalizar
            $response = [
                'assistant_message'  => '¡Perfecto! Gracias por confirmar. Sus datos han sido cargados correctamente. En breve un facilitador se pondrá en contacto con usted para ayudarlo con su consulta. ¡Que tenga un hermoso día!',
                'update_json'        => new stdClass(),
                'missing_info'       => [],
                'need_confirmation'  => false,
                'user_confirmed'     => true,
                'process_finished'   => true,
                'form_summary'       => $session['formData'],
            ];

            addToHistory($session, 'assistant', $response['assistant_message']);
            sendSSE('__JSON__START__' . json_encode($response, JSON_UNESCAPED_UNICODE));

            $session['awaitingConfirmation'] = false;

            // Guardar en BD
            try {
                saveFormDataToDB($session['formData'], $sessionId);
            } catch (Exception $e) {
                error_log("Error guardando formulario: " . $e->getMessage());
            }

            return;
        } elseif ($confirmation === false) {
            // Usuario rechazó → continuar recopilando
            $session['awaitingConfirmation'] = false;
        }
    }

    // Validar campos actuales
    $validation = validateRequiredFormFields($session['formData']);

    // Construir prompt con contexto
    $prompt = getPromptFormulario() . "\n\n"
        . "Historial de la conversación:\n"
        . json_encode(array_slice($session['history'], -10), JSON_UNESCAPED_UNICODE)
        . "\n\nEstado actual del formulario:\n"
        . json_encode($session['formData'], JSON_UNESCAPED_UNICODE)
        . "\n\nCampos faltantes detectados: "
        . (count($validation['missing']) > 0 ? json_encode($validation['missing']) : 'NINGUNO - Formulario completo')
        . "\n\nNuevo mensaje del usuario:\n\"{$message}\""
        . "\n\nINSTRUCCIONES ESPECÍFICAS:\n"
        . "- Analiza el mensaje del usuario y extrae la información relevante.\n"
        . "- Si el formulario está completo, marca 'need_confirmation' como true y muestra resumen.\n"
        . "- Actualiza solo los campos que se mencionen en el mensaje del usuario.\n"
        . "- Responde siempre con un JSON válido.";

    $messages = [
        ['role' => 'system', 'content' => getPromptFormulario()],
        ['role' => 'user',   'content' => $prompt],
    ];

    // Llamar al LLM con streaming
    $fullText = '';
    try {
        $fullText = callLLM([
            'messages' => $messages,
            'model'    => [
                'provider'    => !empty(GROQ_API_KEY) ? 'groq' : 'gemini',
                'name'        => !empty(GROQ_API_KEY) ? 'llama-3.1-8b-instant' : 'gemini-2.0-flash-exp',
                'temperature' => 0.7,
            ],
            'stream'  => true,
            'onToken' => function (string $text) {
                sendSSE($text);
            },
        ]);
    } catch (RuntimeException $e) {
        error_log("Error LLM formulario: " . $e->getMessage());
        sendSSE('__JSON__START__' . json_encode([
            'assistant_message'  => 'Disculpe, hubo un error al interpretar la respuesta. ¿Podría repetir su mensaje?',
            'update_json'        => new stdClass(),
            'missing_info'       => $validation['missing'],
            'need_confirmation'  => false,
            'user_confirmed'     => false,
            'process_finished'   => false,
            'form_summary'       => null,
        ], JSON_UNESCAPED_UNICODE));
        return;
    }

    // Parsear respuesta
    $parsed = extractFirstJSON($fullText);

    if (!$parsed) {
        sendSSE('__JSON__START__' . json_encode([
            'assistant_message'  => 'Disculpe, hubo un error al interpretar la respuesta. ¿Podría repetir su mensaje?',
            'update_json'        => new stdClass(),
            'missing_info'       => $validation['missing'],
            'need_confirmation'  => false,
            'user_confirmed'     => false,
            'process_finished'   => false,
            'form_summary'       => null,
        ], JSON_UNESCAPED_UNICODE));
        return;
    }

    // Normalizar estructura
    if (empty($parsed['assistant_message'])) $parsed['assistant_message'] = 'Disculpe, no pude procesar su mensaje correctamente.';
    if (!isset($parsed['update_json'])) $parsed['update_json'] = [];
    if (!isset($parsed['missing_info'])) $parsed['missing_info'] = [];
    if (!isset($parsed['need_confirmation'])) $parsed['need_confirmation'] = false;
    if (!isset($parsed['user_confirmed'])) $parsed['user_confirmed'] = false;
    if (!isset($parsed['process_finished'])) $parsed['process_finished'] = false;

    // Actualizar formData con los nuevos datos
    if (!empty($parsed['update_json']) && is_array($parsed['update_json'])) {
        $session['formData'] = array_merge($session['formData'], $parsed['update_json']);
    }
    // También manejar current_data (formato runAgent)
    if (!empty($parsed['current_data']) && is_array($parsed['current_data'])) {
        foreach ($parsed['current_data'] as $key => $value) {
            if ($value !== null && $value !== '') {
                $session['formData'][$key] = $value;
            }
        }
    }

    // Revalidar después de actualizar
    $newValidation = validateRequiredFormFields($session['formData']);

    // Si formulario completo → forzar confirmación
    if ($newValidation['isValid'] && !$session['awaitingConfirmation'] && !$parsed['need_confirmation']) {
        $parsed['need_confirmation'] = true;
        $parsed['missing_info'] = [];
        $parsed['form_summary'] = $session['formData'];
        $session['awaitingConfirmation'] = true;
    }

    if ($parsed['need_confirmation'] && empty($parsed['form_summary'])) {
        $parsed['form_summary'] = $session['formData'];
        $session['awaitingConfirmation'] = true;
    }

    if (!$parsed['need_confirmation'] && !$parsed['process_finished']) {
        $parsed['missing_info'] = $newValidation['missing'];
    }

    addToHistory($session, 'assistant', $parsed['assistant_message']);
    sendSSE('__JSON__START__' . json_encode($parsed, JSON_UNESCAPED_UNICODE));
}

/* ============================================================
   FUNCIONES DE BASE DE DATOS
   ============================================================ */

/**
 * Busca un usuario por DNI (o por los últimos dígitos del teléfono como fallback).
 */
function checkUserExistsByDni(string $dni): ?array
{
    $pdo = getDB();
    $dniClean = preg_replace('/\D/', '', $dni);

    if (strlen($dniClean) < 7) {
        return null;
    }

    // Buscar por campo DNI (si existe) o por teléfono como fallback
    // Primero intentar por DNI directo
    $stmt = $pdo->prepare('SELECT id, name, phone, email, role FROM users WHERE dni = ? LIMIT 1');
    $stmt->execute([$dniClean]);
    $user = $stmt->fetch();

    if ($user) {
        return $user;
    }

    // Fallback: buscar por los últimos 8 dígitos del teléfono
    $lastDigits = substr($dniClean, -8);
    $stmt = $pdo->prepare('SELECT id, name, phone, email, role FROM users WHERE phone LIKE ? LIMIT 1');
    $stmt->execute(['%' . $lastDigits]);
    return $stmt->fetch() ?: null;
}

/**
 * Guarda un problema para un usuario existente (crea un caso en la BD).
 */
function saveUserProblemToDB(int $userId, string $description, string $sessionId): int
{
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "INSERT INTO cases (consultante_id, description, input_method, status, created_at)
         VALUES (?, ?, 'texto', 'ingresado', NOW())"
    );
    $stmt->execute([$userId, $description]);
    $caseId = (int)$pdo->lastInsertId();

    error_log("[{$sessionId}] Problema guardado - Usuario ID: {$userId}, Caso ID: {$caseId}");
    return $caseId;
}

/**
 * Guarda los datos completos del formulario: crea o actualiza usuario + crea caso.
 */
function saveFormDataToDB(array $formData, string $sessionId): array
{
    $pdo = getDB();

    $phoneClean = !empty($formData['phone']) ? preg_replace('/\D/', '', $formData['phone']) : null;
    $name       = $formData['name'] ?? null;
    $email      = $formData['email'] ?? null;
    $zone       = $formData['zone'] ?? null;
    $address    = $formData['address'] ?? null;
    $description = $formData['description'] ?? '';

    // Verificar si el usuario ya existe por teléfono
    $consultanteId = null;
    if ($phoneClean) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE phone = ? LIMIT 1');
        $stmt->execute([$phoneClean]);
        $existing = $stmt->fetch();

        if ($existing) {
            $consultanteId = $existing['id'];
            // Actualizar datos
            $stmt = $pdo->prepare(
                'UPDATE users SET name = ?, email = ?, zone = ?, address = ?, updated_at = NOW() WHERE id = ?'
            );
            $stmt->execute([$name, $email, $zone, $address, $consultanteId]);
        }
    }

    if (!$consultanteId) {
        // Crear usuario nuevo
        $stmt = $pdo->prepare(
            "INSERT INTO users (name, phone, email, zone, address, role, created_at)
             VALUES (?, ?, ?, ?, ?, 'consultante', NOW())"
        );
        $stmt->execute([$name, $phoneClean, $email, $zone, $address]);
        $consultanteId = (int)$pdo->lastInsertId();
    }

    // Crear caso
    $stmt = $pdo->prepare(
        "INSERT INTO cases (consultante_id, problem_type_id, description, input_method, status, created_at)
         VALUES (?, ?, ?, 'texto', 'ingresado', NOW())"
    );
    $stmt->execute([$consultanteId, 1, $description]); // problem_type_id = 1 como default
    $caseId = (int)$pdo->lastInsertId();

    error_log("[{$sessionId}] Formulario guardado - Usuario ID: {$consultanteId}, Caso ID: {$caseId}");

    return ['success' => true, 'userId' => $consultanteId, 'caseId' => $caseId];
}
