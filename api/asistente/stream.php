<?php
/**
 * GET /api/asistente/stream
 *
 * Endpoint principal del asistente conversacional con streaming SSE.
 * Usa un solo agente LLM que maneja todo el flujo:
 *   - Identificación de usuario (DNI)
 *   - Registro de usuario nuevo
 *   - Creación de ticket/caso
 *
 * El campo "action" en la respuesta JSON del LLM determina qué
 * operación de base de datos ejecuta el backend.
 *
 * Query params: ?sessionId=xxx&message=xxx
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
require_once $baseDir . '/prompts/prompt.php';

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
header('X-Accel-Buffering: no');

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
    // ── Manejar confirmación pendiente ──
    if (!empty($session['awaitingConfirmation'])) {
        $confirmation = detectUserConfirmation($message);

        if ($confirmation === true) {
            // Usuario confirmó → ejecutar la acción pendiente
            $result = executeConfirmedAction($session, $sessionId);
            addToHistory($session, 'assistant', $result['assistant']['message']);
            sendSSE('__JSON__START__' . json_encode($result, JSON_UNESCAPED_UNICODE));
            $session['awaitingConfirmation'] = false;
            $session['lastAction'] = 'finish';
            saveAISession($sessionId, $session);
            exit;
        } elseif ($confirmation === false) {
            // Usuario rechazó → continuar recopilando
            $session['awaitingConfirmation'] = false;
        }
        // null = indeterminado → dejar que el LLM interprete
    }

    // ── Construir contexto para el prompt ──
    $promptContext = [
        'userData'    => $session['userData'] ?? [],
        'problemData' => $session['problemData'] ?? [],
        'dbUser'      => $session['dbUser'] ?? null,
    ];

    // ── Llamar al LLM con streaming ──
    // Excluir el último mensaje del historial (es el actual) para no duplicarlo
    $historyForLLM = array_slice($session['history'], 0, -1);

    $result = runAgent([
        'userMessage'    => $message,
        'sessionContext' => $promptContext,
        'history'        => $historyForLLM,
        'model'          => [
            'provider'    => !empty(GROQ_API_KEY) ? 'groq' : 'gemini',
            'name'        => !empty(GROQ_API_KEY) ? 'llama-3.1-8b-instant' : GEMINI_MODEL,
            'temperature' => 0.4,
        ],
        'stream'  => true,
        'onToken' => function (string $text) {
            sendSSE($text);
        },
    ]);

    $parsed = $result['parsed'];

    // ── Actualizar datos de sesión con lo que extrajo el LLM ──
    $update = $parsed['data']['update'] ?? [];
    mergeSessionData($session, $update);

    // ── Dispatch de acciones de BD ──
    $action = $parsed['action'] ?? 'ask_dni';
    $parsed = handleAction($action, $parsed, $session, $sessionId);

    // ── Guardar estado ──
    $session['lastAction'] = $action;
    addToHistory($session, 'assistant', $parsed['assistant']['message']);
    sendSSE('__JSON__START__' . json_encode($parsed, JSON_UNESCAPED_UNICODE));

} catch (Exception $e) {
    error_log("Error en asistente SSE: " . $e->getMessage());
    $fallback = buildFallbackResponse('Disculpe, hubo un error interno. Por favor, intente nuevamente.');
    sendSSE('__JSON__START__' . json_encode($fallback, JSON_UNESCAPED_UNICODE));
}

// ── Guardar sesión actualizada ──
saveAISession($sessionId, $session);
exit;

/* ============================================================
   DISPATCH DE ACCIONES
   ============================================================ */

/**
 * Ejecuta la acción indicada por el LLM contra la base de datos.
 */
function handleAction(string $action, array $parsed, array &$session, string $sessionId): array
{
    switch ($action) {
        case 'check_user':
            return handleCheckUser($parsed, $session);

        case 'register_user':
        case 'update_user_data':
            // Solo acumular datos — ya se hizo en mergeSessionData
            return $parsed;

        case 'confirm_data':
            $session['awaitingConfirmation'] = true;
            // Incluir resumen de datos en la respuesta
            $parsed['data']['summary'] = buildDataSummary($session);
            return $parsed;

        case 'create_ticket':
            return handleCreateTicket($parsed, $session, $sessionId);

        case 'finish':
            $parsed['process']['suggest_finish'] = true;
            return $parsed;

        case 'ask_dni':
        case 'ask_problem':
        default:
            return $parsed;
    }
}

/**
 * Busca un usuario por DNI en la base de datos.
 */
function handleCheckUser(array $parsed, array &$session): array
{
    $dni = $parsed['data']['update']['dni'] ?? $session['userData']['dni'] ?? null;

    if (empty($dni)) {
        $parsed['assistant']['message'] = 'Disculpe, no pude identificar su número de DNI. ¿Podría indicármelo nuevamente?';
        $parsed['action'] = 'ask_dni';
        return $parsed;
    }

    $dniClean = preg_replace('/\D/', '', $dni);
    if (strlen($dniClean) < 7) {
        $parsed['assistant']['message'] = 'El DNI que ingresó no parece válido. Debe tener entre 7 y 8 dígitos. ¿Podría verificarlo?';
        $parsed['action'] = 'ask_dni';
        $parsed['validation']['invalid_fields'][] = 'dni';
        return $parsed;
    }

    // Guardar DNI limpio en sesión
    $session['userData']['dni'] = $dniClean;

    // Buscar en BD
    $dbUser = findUserByDni($dniClean);

    if ($dbUser) {
        // Usuario encontrado
        $session['dbUser'] = $dbUser;
        $parsed['assistant']['message'] = "¡Bienvenido/a de nuevo, {$dbUser['name']}! ¿En qué puedo ayudarle hoy? Cuénteme su problema o consulta.";
        $parsed['action'] = 'ask_problem';
        $parsed['data']['update']['dni'] = $dniClean;
        $parsed['data']['update']['nombre'] = $dbUser['name'];
    } else {
        // Usuario no encontrado
        $session['dbUser'] = null;
        $parsed['assistant']['message'] = 'No encontré una cuenta con ese DNI en nuestro sistema. No se preocupe, vamos a registrarlo. ¿Podría decirme su nombre completo (nombre y apellido)?';
        $parsed['action'] = 'register_user';
        $parsed['validation']['missing_fields'] = ['nombre', 'telefono'];
    }

    return $parsed;
}

/**
 * Crea el ticket/caso en la base de datos.
 */
function handleCreateTicket(array $parsed, array &$session, string $sessionId): array
{
    $dbUser      = $session['dbUser'] ?? null;
    $userData    = $session['userData'] ?? [];
    $problemData = $session['problemData'] ?? [];
    $descripcion = $problemData['descripcion'] ?? $parsed['data']['update']['descripcion'] ?? null;

    try {
        if ($dbUser && !empty($dbUser['id'])) {
            // Usuario existente → solo crear caso
            $caseId = saveExistingUserCase((int)$dbUser['id'], $descripcion, $sessionId);
            $nombre = $dbUser['name'] ?? 'estimado/a';
        } else {
            // Usuario nuevo → crear usuario + caso
            $result = saveNewUserAndCase($userData, $descripcion, $sessionId);
            $caseId = $result['caseId'];
            $session['dbUser'] = ['id' => $result['userId']];
            $nombre = $userData['nombre'] ?? 'estimado/a';
        }

        $parsed['assistant']['message'] = "¡Listo, {$nombre}! Su consulta ha sido registrada exitosamente (N° {$caseId}). "
            . "En breve, un facilitador se pondrá en contacto con usted para ayudarlo. "
            . "¡Que tenga un excelente día!";
        $parsed['action'] = 'finish';
        $parsed['process']['suggest_finish'] = true;

    } catch (Exception $e) {
        error_log("[{$sessionId}] Error creando ticket: " . $e->getMessage());
        $parsed['assistant']['message'] = 'Disculpe, hubo un error al registrar su consulta. ¿Podría intentar nuevamente?';
        $parsed['action'] = 'ask_problem';
        $parsed['process']['can_continue'] = true;
    }

    return $parsed;
}

/**
 * Ejecuta la acción cuando el usuario confirma datos pendientes.
 */
function executeConfirmedAction(array &$session, string $sessionId): array
{
    $lastAction = $session['lastAction'] ?? 'confirm_data';
    $userData    = $session['userData'] ?? [];
    $problemData = $session['problemData'] ?? [];
    $descripcion = $problemData['descripcion'] ?? '';
    $dbUser      = $session['dbUser'] ?? null;

    try {
        if ($dbUser && !empty($dbUser['id'])) {
            $caseId = saveExistingUserCase((int)$dbUser['id'], $descripcion, $sessionId);
            $nombre = $dbUser['name'] ?? $userData['nombre'] ?? 'estimado/a';
        } else {
            $result = saveNewUserAndCase($userData, $descripcion, $sessionId);
            $caseId = $result['caseId'];
            $session['dbUser'] = ['id' => $result['userId']];
            $nombre = $userData['nombre'] ?? 'estimado/a';
        }

        return buildFinalResponse($nombre, $caseId);

    } catch (Exception $e) {
        error_log("[{$sessionId}] Error en confirmación: " . $e->getMessage());
        $response = buildFallbackResponse('Disculpe, hubo un error al guardar sus datos. ¿Podría intentar nuevamente?');
        $response['action'] = 'confirm_data';
        return $response;
    }
}

/* ============================================================
   FUNCIONES AUXILIARES
   ============================================================ */

/**
 * Merge de datos del LLM hacia la sesión (sin sobreescribir con null).
 */
function mergeSessionData(array &$session, array $update): void
{
    // Campos de usuario
    $userFields = ['dni', 'nombre', 'telefono', 'email'];
    foreach ($userFields as $field) {
        if (!empty($update[$field])) {
            $session['userData'][$field] = $update[$field];
        }
    }

    // Campos de problema
    $problemFields = ['descripcion', 'categoria'];
    foreach ($problemFields as $field) {
        if (!empty($update[$field])) {
            $session['problemData'][$field] = $update[$field];
        }
    }
}

/**
 * Construye un resumen de datos para confirmación.
 */
function buildDataSummary(array $session): array
{
    return [
        'usuario'  => $session['userData'] ?? [],
        'problema' => $session['problemData'] ?? [],
        'dbUser'   => $session['dbUser'] ?? null,
    ];
}

/**
 * Construye la respuesta final exitosa.
 */
function buildFinalResponse(string $nombre, int $caseId): array
{
    $response = buildFallbackResponse(
        "¡Listo, {$nombre}! Su consulta ha sido registrada exitosamente (N° {$caseId}). "
        . "En breve, un facilitador se pondrá en contacto con usted para ayudarlo. "
        . "¡Que tenga un excelente día!"
    );
    $response['action'] = 'finish';
    $response['process']['suggest_finish'] = true;
    return $response;
}

/* ============================================================
   FUNCIONES DE BASE DE DATOS
   ============================================================ */

/**
 * Busca un usuario por DNI en la base de datos.
 */
function findUserByDni(string $dniClean): ?array
{
    $pdo = getDB();

    $stmt = $pdo->prepare('SELECT id, name, phone, email, role, dni FROM users WHERE dni = ? LIMIT 1');
    $stmt->execute([$dniClean]);
    $user = $stmt->fetch();

    return $user ?: null;
}

/**
 * Crea un caso para un usuario existente.
 */
function saveExistingUserCase(int $userId, ?string $description, string $sessionId): int
{
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "INSERT INTO cases (consultante_id, description, input_method, status, created_at)
         VALUES (?, ?, 'texto', 'ingresado', NOW())"
    );
    $stmt->execute([$userId, $description ?? '']);
    $caseId = (int)$pdo->lastInsertId();

    // Registrar en historial de caso
    $stmt = $pdo->prepare(
        "INSERT INTO case_history (case_id, user_id, action, comment, created_at)
         VALUES (?, ?, 'caso_creado', 'Caso creado via asistente IA', NOW())"
    );
    $stmt->execute([$caseId, $userId]);

    error_log("[{$sessionId}] Caso creado - Usuario ID: {$userId}, Caso ID: {$caseId}");
    return $caseId;
}

/**
 * Crea un usuario nuevo + caso en una transacción.
 * Mapea campos del prompt (español) a columnas de BD (inglés).
 */
function saveNewUserAndCase(array $userData, ?string $description, string $sessionId): array
{
    $pdo = getDB();

    // Mapeo de campos: prompt → BD
    $name     = $userData['nombre'] ?? null;
    $dni      = !empty($userData['dni']) ? preg_replace('/\D/', '', $userData['dni']) : null;
    $phone    = !empty($userData['telefono']) ? preg_replace('/\D/', '', $userData['telefono']) : null;
    $email    = $userData['email'] ?? null;

    $pdo->beginTransaction();

    try {
        // Verificar si usuario ya existe por DNI o teléfono
        $consultanteId = null;

        if ($dni) {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE dni = ? LIMIT 1');
            $stmt->execute([$dni]);
            $existing = $stmt->fetch();
            if ($existing) {
                $consultanteId = $existing['id'];
                // Actualizar datos existentes
                $stmt = $pdo->prepare('UPDATE users SET name = ?, phone = ?, email = ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$name, $phone, $email, $consultanteId]);
            }
        }

        if (!$consultanteId && $phone) {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE phone = ? LIMIT 1');
            $stmt->execute([$phone]);
            $existing = $stmt->fetch();
            if ($existing) {
                $consultanteId = $existing['id'];
                $stmt = $pdo->prepare('UPDATE users SET name = ?, dni = ?, email = ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$name, $dni, $email, $consultanteId]);
            }
        }

        if (!$consultanteId) {
            // Crear usuario nuevo
            $stmt = $pdo->prepare(
                "INSERT INTO users (name, dni, phone, email, role, created_at)
                 VALUES (?, ?, ?, ?, 'consultante', NOW())"
            );
            $stmt->execute([$name, $dni, $phone, $email]);
            $consultanteId = (int)$pdo->lastInsertId();
        }

        // Crear caso
        $stmt = $pdo->prepare(
            "INSERT INTO cases (consultante_id, description, input_method, status, created_at)
             VALUES (?, ?, 'texto', 'ingresado', NOW())"
        );
        $stmt->execute([$consultanteId, $description ?? '']);
        $caseId = (int)$pdo->lastInsertId();

        // Historial del caso
        $stmt = $pdo->prepare(
            "INSERT INTO case_history (case_id, user_id, action, comment, created_at)
             VALUES (?, ?, 'caso_creado', 'Caso creado via asistente IA (usuario nuevo)', NOW())"
        );
        $stmt->execute([$caseId, $consultanteId]);

        $pdo->commit();

        error_log("[{$sessionId}] Usuario nuevo + caso creados - Usuario ID: {$consultanteId}, Caso ID: {$caseId}");
        return ['userId' => $consultanteId, 'caseId' => $caseId];

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
