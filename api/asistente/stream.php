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
$incomingLocation = extractIncomingLocation($_GET);

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

if (!empty($incomingLocation)) {
    $session['location'] = $incomingLocation;
}

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
        'userLookupDone' => !empty($session['userLookupDone']),
        'problemTypes' => getProblemTypesForPrompt(),
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

    // Fallback defensivo: si el modelo no colocó DNI en data.update pero el usuario lo escribió,
    // extraerlo del mensaje para no perder el disparo de check_user.
    // Aceptamos 7-10 dígitos para capturar entradas mal formateadas y validarlas luego.
    if (empty($parsed['data']['update']['dni']) && preg_match('/\b(\d{7,10})\b/', $message, $dniMatch)) {
        $parsed['data']['update']['dni'] = $dniMatch[1];
    }

    // ── Actualizar datos de sesión con lo que extrajo el LLM ──
    $update = $parsed['data']['update'] ?? [];
    mergeSessionData($session, $update);

    // ── Dispatch de acciones de BD ──
    $action = $parsed['action'] ?? 'ask_dni';

    // Ajuste de intención por heurística backend para evitar desvíos del modelo:
    // distinguir entre consulta de estado y carga de nuevo caso.
    $detectedIntent = detectConversationIntent($message);
    if ($detectedIntent === 'check_case_status') {
        $action = 'check_case_status';
    } elseif ($detectedIntent === 'create_ticket_flow' && $action === 'check_case_status') {
        $action = chooseCreateFlowAction($session);
    }

    $action = reconcileActionWithSession($action, $session, $parsed);
    $parsed['action'] = $action;
    $parsed = handleAction($action, $parsed, $session, $sessionId, $message);

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
function handleAction(string $action, array $parsed, array &$session, string $sessionId, string $userMessage): array
{
    switch ($action) {
        case 'check_user':
            return handleCheckUser($parsed, $session);

        case 'check_case_status':
            return handleCaseStatus($parsed, $session, $userMessage);

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
 * Consulta el estado del caso (por ID explícito o el más reciente del usuario).
 */
function handleCaseStatus(array $parsed, array &$session, string $userMessage): array
{
    $dbUser = $session['dbUser'] ?? null;
    $dni = $session['userData']['dni'] ?? null;

    // Si aún no tenemos usuario en sesión, intentar resolver por DNI ya capturado
    if (empty($dbUser) && !empty($dni)) {
        $dbUser = findUserByDni(preg_replace('/\D/', '', $dni));
        $session['dbUser'] = $dbUser ?: null;
        $session['userLookupDone'] = true;
    }

    if (empty($dbUser) || empty($dbUser['id'])) {
        $parsed['assistant']['message'] = 'Para consultar el estado de su caso necesito su DNI. ¿Podría indicármelo, por favor?';
        $parsed['action'] = 'ask_dni';
        $parsed['validation']['missing_fields'] = ['dni'];
        return $parsed;
    }

    $requestedCaseId = extractRequestedCaseId($userMessage);
    $case = findCaseForConsultante((int)$dbUser['id'], $requestedCaseId);

    if (!$case) {
        if ($requestedCaseId !== null) {
            $parsed['assistant']['message'] = "No encontré el caso N° {$requestedCaseId} asociado a su cuenta. Si desea, puedo informarle el estado de su caso más reciente.";
        } else {
            $parsed['assistant']['message'] = 'No encontré casos cargados para su cuenta todavía. Si lo desea, puedo ayudarle a crear una nueva consulta ahora mismo.';
        }
        $parsed['action'] = 'ask_problem';
        return $parsed;
    }

    $statusText = mapCaseStatusToHumanText((string)$case['status']);
    $facilitator = $case['facilitator_name'] ?? null;
    $problemType = $case['problem_type_name'] ?? 'Sin categoría';

    $msg = "El estado de su caso N° {$case['id']} es: {$statusText}.";
    if (!empty($facilitator)) {
        $msg .= " Está siendo atendido por {$facilitator}.";
    } elseif (($case['status'] ?? '') === 'ingresado') {
        $msg .= ' Aún no fue tomado por un facilitador, pero está en la cola de atención.';
    }

    $msg .= " Tipo de problema: {$problemType}.";
    $msg .= ' Si desea, también puedo ayudarle con una nueva consulta.';

    $parsed['assistant']['message'] = $msg;
    $parsed['action'] = 'check_case_status';
    $parsed['data']['summary'] = [
        'case_id' => (int)$case['id'],
        'status' => $case['status'],
        'facilitator' => $facilitator,
        'problem_type' => $problemType,
        'created_at' => $case['created_at'] ?? null,
    ];

    return $parsed;
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
    if (strlen($dniClean) < 7 || strlen($dniClean) > 8) {
        unset($session['userData']['dni']);
        $session['dbUser'] = null;
        $session['userLookupDone'] = false;
        $parsed['assistant']['message'] = 'El DNI que ingresó no parece válido. Debe tener entre 7 y 8 dígitos. ¿Podría verificarlo?';
        $parsed['action'] = 'ask_dni';
        $parsed['validation']['invalid_fields'][] = 'dni';
        return $parsed;
    }

    // Guardar DNI limpio en sesión
    $session['userData']['dni'] = $dniClean;
    $session['userLookupDone'] = true;

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
        $parsed['assistant']['message'] = 'Vamos a registrarlo. ¿Podría decirme su nombre completo (nombre y apellido)?';
        $parsed['action'] = 'register_user';
        $parsed['validation']['missing_fields'] = ['nombre', 'telefono'];
    }

    return $parsed;
}

/**
 * Evita retrocesos del flujo si el LLM pierde contexto.
 */
function reconcileActionWithSession(string $action, array $session, array $parsed): string
{
    $userData = $session['userData'] ?? [];
    $problemData = $session['problemData'] ?? [];
    $dbUser = $session['dbUser'] ?? null;
    $lookupDone = !empty($session['userLookupDone']);

    $hasDni = !empty($userData['dni']);
    $hasNombre = !empty($userData['nombre']);
    $hasTelefono = !empty($userData['telefono']);
    $hasDescripcion = !empty($problemData['descripcion']);

    if ($action === 'check_case_status') {
        return $action;
    }

    // Si ya se verificó DNI y no se encontró usuario, NO volver a pedir DNI.
    if ($lookupDone && empty($dbUser)) {
        if (!$hasNombre || !$hasTelefono) {
            return 'register_user';
        }
        if (!$hasDescripcion) {
            return 'ask_problem';
        }
        if ($action === 'ask_dni' || $action === 'check_user') {
            return 'confirm_data';
        }
    }

    // Si ya hay usuario encontrado, no tiene sentido pedir registro ni DNI.
    if (!empty($dbUser)) {
        if (!$hasDescripcion) {
            return 'ask_problem';
        }
        if (in_array($action, ['ask_dni', 'check_user', 'register_user', 'update_user_data'], true)) {
            return 'confirm_data';
        }
    }

    // Si tenemos DNI y nunca se verificó, forzar check_user sin depender del texto del LLM.
    if ($hasDni && !$lookupDone && !in_array($action, ['check_user', 'check_case_status'], true)) {
        return 'check_user';
    }

    return $action;
}

/**
 * Detecta la intención principal del mensaje para estabilizar el flujo.
 */
function detectConversationIntent(string $userMessage): ?string
{
    $msg = mb_strtolower(trim($userMessage), 'UTF-8');
    if ($msg === '') {
        return null;
    }

    $statusPatterns = [
        '/\bestado\b/ui',
        '/\bseguimiento\b/ui',
        '/\bc[oó]mo\s+va\b/ui',
        '/\bmi\s+caso\b/ui',
        '/\bcaso\s*(?:n(?:u|ú)mero|nro|n|#)?\s*\d+\b/ui',
        '/\bconsultar\b.*\bcaso\b/ui',
    ];

    foreach ($statusPatterns as $pattern) {
        if (preg_match($pattern, $msg) === 1) {
            return 'check_case_status';
        }
    }

    $createPatterns = [
        '/\bcargar\b.*\bcaso\b/ui',
        '/\bcrear\b.*\bcaso\b/ui',
        '/\bnuevo\s+caso\b/ui',
        '/\bcargar\s+ticket\b/ui',
        '/\bcrear\s+ticket\b/ui',
        '/\bquiero\s+pedir\s+ayuda\b/ui',
        '/\bnecesito\s+ayuda\b/ui',
    ];

    foreach ($createPatterns as $pattern) {
        if (preg_match($pattern, $msg) === 1) {
            return 'create_ticket_flow';
        }
    }

    return null;
}

/**
 * Selecciona una acción segura para el flujo de carga de caso.
 */
function chooseCreateFlowAction(array $session): string
{
    $userData = $session['userData'] ?? [];
    $problemData = $session['problemData'] ?? [];
    $dbUser = $session['dbUser'] ?? null;
    $lookupDone = !empty($session['userLookupDone']);

    $hasDni = !empty($userData['dni']);
    $hasNombre = !empty($userData['nombre']);
    $hasTelefono = !empty($userData['telefono']);
    $hasDescripcion = !empty($problemData['descripcion']);

    if (!$hasDni) {
        return 'ask_dni';
    }

    if (!$lookupDone) {
        return 'check_user';
    }

    if (empty($dbUser)) {
        if (!$hasNombre || !$hasTelefono) {
            return 'register_user';
        }
        if (!$hasDescripcion) {
            return 'ask_problem';
        }
        return 'confirm_data';
    }

    if (!$hasDescripcion) {
        return 'ask_problem';
    }

    return 'confirm_data';
}

/**
 * Extrae un numero de caso mencionado por el usuario.
 */
function extractRequestedCaseId(string $userMessage): ?int
{
    if (preg_match('/\bcaso\s*(?:n(?:u|ú)mero|nro|n|#)?\s*(\d+)\b/ui', $userMessage, $m)) {
        return (int)$m[1];
    }
    if (preg_match('/\b#\s*(\d+)\b/u', $userMessage, $m)) {
        return (int)$m[1];
    }
    return null;
}

/**
 * Busca un caso del consultante. Si se indica ID, valida pertenencia.
 */
function findCaseForConsultante(int $consultanteId, ?int $caseId = null): ?array
{
    $pdo = getDB();

    if ($caseId !== null) {
        $stmt = $pdo->prepare(
            'SELECT c.id, c.status, c.created_at, c.description, c.problem_type_id,
                    pt.name AS problem_type_name,
                    fac.name AS facilitator_name
             FROM cases c
             LEFT JOIN problem_types pt ON pt.id = c.problem_type_id
             LEFT JOIN users fac ON fac.id = c.facilitator_id
             WHERE c.id = ? AND c.consultante_id = ?
             LIMIT 1'
        );
        $stmt->execute([$caseId, $consultanteId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    $stmt = $pdo->prepare(
        'SELECT c.id, c.status, c.created_at, c.description, c.problem_type_id,
                pt.name AS problem_type_name,
                fac.name AS facilitator_name
         FROM cases c
         LEFT JOIN problem_types pt ON pt.id = c.problem_type_id
         LEFT JOIN users fac ON fac.id = c.facilitator_id
         WHERE c.consultante_id = ?
         ORDER BY c.created_at DESC
         LIMIT 1'
    );
    $stmt->execute([$consultanteId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Traduce estado técnico del caso a texto más claro para el usuario.
 */
function mapCaseStatusToHumanText(string $status): string
{
    $map = [
        'ingresado' => 'Ingresado (pendiente de asignación)',
        'asignado' => 'Asignado a un facilitador',
        'proceso' => 'En proceso de resolución',
        'resuelto' => 'Resuelto',
        'cerrado' => 'Cerrado',
        'escalado' => 'Escalado para atención especializada',
    ];

    return $map[$status] ?? ucfirst($status);
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
    $categoria   = $problemData['categoria'] ?? $parsed['data']['update']['categoria'] ?? null;
    $problemTypeId = resolveProblemTypeId($categoria, $descripcion);
    $location = $session['location'] ?? null;

    try {
        if ($dbUser && !empty($dbUser['id'])) {
            // Usuario existente → solo crear caso
            $caseResult = saveExistingUserCase((int)$dbUser['id'], $descripcion, $problemTypeId, $location, $sessionId);
            $caseId = (int)$caseResult['caseId'];
            $nombre = $dbUser['name'] ?? 'estimado/a';
        } else {
            // Usuario nuevo → crear usuario + caso
            $result = saveNewUserAndCase($userData, $descripcion, $problemTypeId, $location, $sessionId);
            $caseId = (int)$result['caseId'];
            $session['dbUser'] = ['id' => $result['userId']];
            $nombre = $userData['nombre'] ?? 'estimado/a';
            $caseResult = $result;
        }

        $centerName = $caseResult['centerName'] ?? null;
        $centerText = $centerName ? " Se asignó al centro {$centerName}." : '';

        $parsed['assistant']['message'] = "¡Listo, {$nombre}! Su consulta ha sido registrada exitosamente (N° {$caseId})."
            . $centerText
            . " En breve, un facilitador se pondrá en contacto con usted para ayudarlo."
            . " ¡Que tenga un excelente día!";
        $parsed['action'] = 'finish';
        $parsed['process']['suggest_finish'] = true;
        $parsed['data']['summary'] = [
            'case_id' => $caseId,
            'center_assigned' => [
                'id' => $caseResult['centerId'] ?? null,
                'name' => $centerName,
                'distance_km' => $caseResult['centerDistanceKm'] ?? null,
            ],
        ];

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
    $userData    = $session['userData'] ?? [];
    $problemData = $session['problemData'] ?? [];
    $descripcion = $problemData['descripcion'] ?? '';
    $categoria   = $problemData['categoria'] ?? null;
    $problemTypeId = resolveProblemTypeId($categoria, $descripcion);
    $dbUser      = $session['dbUser'] ?? null;
    $location    = $session['location'] ?? null;

    try {
        if ($dbUser && !empty($dbUser['id'])) {
            $caseResult = saveExistingUserCase((int)$dbUser['id'], $descripcion, $problemTypeId, $location, $sessionId);
            $caseId = (int)$caseResult['caseId'];
            $nombre = $dbUser['name'] ?? $userData['nombre'] ?? 'estimado/a';
        } else {
            $result = saveNewUserAndCase($userData, $descripcion, $problemTypeId, $location, $sessionId);
            $caseId = (int)$result['caseId'];
            $session['dbUser'] = ['id' => $result['userId']];
            $nombre = $userData['nombre'] ?? 'estimado/a';
            $caseResult = $result;
        }

        return buildFinalResponse($nombre, $caseId, $caseResult['centerName'] ?? null, $caseResult['centerId'] ?? null, $caseResult['centerDistanceKm'] ?? null);

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
function buildFinalResponse(string $nombre, int $caseId, ?string $centerName = null, ?int $centerId = null, ?float $centerDistanceKm = null): array
{
    $centerText = $centerName ? " Se asignó al centro {$centerName}." : '';
    $response = buildFallbackResponse(
        "¡Listo, {$nombre}! Su consulta ha sido registrada exitosamente (N° {$caseId})."
        . $centerText
        . " En breve, un facilitador se pondrá en contacto con usted para ayudarlo. "
        . "¡Que tenga un excelente día!"
    );
    $response['action'] = 'finish';
    $response['process']['suggest_finish'] = true;
    $response['data']['summary'] = [
        'case_id' => $caseId,
        'center_assigned' => [
            'id' => $centerId,
            'name' => $centerName,
            'distance_km' => $centerDistanceKm,
        ],
    ];
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
function saveExistingUserCase(int $userId, ?string $description, ?int $problemTypeId, ?array $location, string $sessionId): array
{
    $pdo = getDB();
    $resolvedCenter = resolveCenterAssignment($pdo, $userId, $location);

    if (!empty($resolvedCenter['centerId'])) {
        syncConsultanteLocationData($pdo, $userId, $resolvedCenter);
    }

    $lat = $location['latitude'] ?? null;
    $lng = $location['longitude'] ?? null;
    $acc = $location['accuracy'] ?? null;

    $stmt = $pdo->prepare(
        "INSERT INTO cases
            (consultante_id, center_id, user_latitude, user_longitude, location_accuracy_meters, problem_type_id, description, input_method, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'texto', 'ingresado', NOW())"
    );
    $stmt->execute([
        $userId,
        $resolvedCenter['centerId'],
        $lat,
        $lng,
        $acc,
        $problemTypeId,
        $description ?? '',
    ]);
    $caseId = (int)$pdo->lastInsertId();

    // Registrar en historial de caso
    $stmt = $pdo->prepare(
        "INSERT INTO case_history (case_id, user_id, action, comment, created_at)
         VALUES (?, ?, 'caso_creado', 'Caso creado via asistente IA', NOW())"
    );
    $stmt->execute([$caseId, $userId]);

    error_log("[{$sessionId}] Caso creado - Usuario ID: {$userId}, Caso ID: {$caseId}, Centro ID: " . ($resolvedCenter['centerId'] ?? 'null'));
    return [
        'caseId' => $caseId,
        'centerId' => $resolvedCenter['centerId'],
        'centerName' => $resolvedCenter['centerName'],
        'centerDistanceKm' => $resolvedCenter['distanceKm'],
    ];
}

/**
 * Crea un usuario nuevo + caso en una transacción.
 * Mapea campos del prompt (español) a columnas de BD (inglés).
 */
function saveNewUserAndCase(array $userData, ?string $description, ?int $problemTypeId, ?array $location, string $sessionId): array
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

        $resolvedCenter = resolveCenterAssignment($pdo, $consultanteId ? (int)$consultanteId : null, $location);
        $resolvedZone = $resolvedCenter['centerZone'] ?? null;

        if (!$consultanteId) {
            // Crear usuario nuevo
            $stmt = $pdo->prepare(
                "INSERT INTO users (name, dni, phone, email, role, center_id, zone, created_at)
                 VALUES (?, ?, ?, ?, 'consultante', ?, ?, NOW())"
            );
            $stmt->execute([$name, $dni, $phone, $email, $resolvedCenter['centerId'], $resolvedZone]);
            $consultanteId = (int)$pdo->lastInsertId();
        } elseif (!empty($resolvedCenter['centerId'])) {
            syncConsultanteLocationData($pdo, (int)$consultanteId, $resolvedCenter);
        }

        // Crear caso
        $lat = $location['latitude'] ?? null;
        $lng = $location['longitude'] ?? null;
        $acc = $location['accuracy'] ?? null;

        $stmt = $pdo->prepare(
            "INSERT INTO cases
                (consultante_id, center_id, user_latitude, user_longitude, location_accuracy_meters, problem_type_id, description, input_method, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'texto', 'ingresado', NOW())"
        );
        $stmt->execute([
            $consultanteId,
            $resolvedCenter['centerId'],
            $lat,
            $lng,
            $acc,
            $problemTypeId,
            $description ?? '',
        ]);
        $caseId = (int)$pdo->lastInsertId();

        // Historial del caso
        $stmt = $pdo->prepare(
            "INSERT INTO case_history (case_id, user_id, action, comment, created_at)
             VALUES (?, ?, 'caso_creado', 'Caso creado via asistente IA (usuario nuevo)', NOW())"
        );
        $stmt->execute([$caseId, $consultanteId]);

        $pdo->commit();

        error_log("[{$sessionId}] Usuario nuevo + caso creados - Usuario ID: {$consultanteId}, Caso ID: {$caseId}, Centro ID: " . ($resolvedCenter['centerId'] ?? 'null'));
        return [
            'userId' => $consultanteId,
            'caseId' => $caseId,
            'centerId' => $resolvedCenter['centerId'],
            'centerName' => $resolvedCenter['centerName'],
            'centerDistanceKm' => $resolvedCenter['distanceKm'],
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Sincroniza centro y zona del consultante usando el centro resuelto
 * a partir de la ubicación actual enviada por el frontend.
 */
function syncConsultanteLocationData(PDO $pdo, int $userId, array $resolvedCenter): void
{
    $stmt = $pdo->prepare(
        'UPDATE users
         SET center_id = ?, zone = ?, updated_at = NOW()
         WHERE id = ?'
    );
    $stmt->execute([
        $resolvedCenter['centerId'] ?? null,
        $resolvedCenter['centerZone'] ?? null,
        $userId,
    ]);
}

/**
 * Determina el centro a asignar priorizando cercanía por geolocalización.
 */
function resolveCenterAssignment(PDO $pdo, ?int $consultanteId, ?array $location): array
{
    $latitude = $location['latitude'] ?? null;
    $longitude = $location['longitude'] ?? null;

    if ($latitude !== null && $longitude !== null) {
        $nearest = findNearestCenterByCoordinates($pdo, (float)$latitude, (float)$longitude);
        if ($nearest) {
            return [
                'centerId' => (int)$nearest['id'],
                'centerName' => $nearest['name'] ?? null,
                'centerZone' => $nearest['zone'] ?? null,
                'distanceKm' => isset($nearest['distance_km']) ? round((float)$nearest['distance_km'], 2) : null,
            ];
        }
    }

    if (!empty($consultanteId)) {
        $stmt = $pdo->prepare(
            'SELECT c.id, c.name, c.zone
             FROM users u
             LEFT JOIN centers c ON c.id = u.center_id
             WHERE u.id = ?
             LIMIT 1'
        );
        $stmt->execute([$consultanteId]);
        $userCenter = $stmt->fetch();
        if (!empty($userCenter['id'])) {
            return [
                'centerId' => (int)$userCenter['id'],
                'centerName' => $userCenter['name'] ?? null,
                'centerZone' => $userCenter['zone'] ?? null,
                'distanceKm' => null,
            ];
        }
    }

    $fallback = $pdo->query('SELECT id, name, zone FROM centers ORDER BY id ASC LIMIT 1')->fetch();
    if ($fallback) {
        return [
            'centerId' => (int)$fallback['id'],
            'centerName' => $fallback['name'] ?? null,
            'centerZone' => $fallback['zone'] ?? null,
            'distanceKm' => null,
        ];
    }

    return [
        'centerId' => null,
        'centerName' => null,
        'centerZone' => null,
        'distanceKm' => null,
    ];
}

/**
 * Busca el centro más cercano en base a latitud/longitud.
 */
function findNearestCenterByCoordinates(PDO $pdo, float $latitude, float $longitude): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            c.id,
            c.name,
            c.zone,
            (
                6371 * ACOS(
                    COS(RADIANS(?)) * COS(RADIANS(c.latitude)) * COS(RADIANS(c.longitude) - RADIANS(?))
                    + SIN(RADIANS(?)) * SIN(RADIANS(c.latitude))
                )
            ) AS distance_km
         FROM centers c
         WHERE c.latitude IS NOT NULL AND c.longitude IS NOT NULL
         ORDER BY distance_km ASC
         LIMIT 1"
    );
    $stmt->execute([$latitude, $longitude, $latitude]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * Extrae y valida geolocalización enviada por el cliente.
 */
function extractIncomingLocation(array $query): ?array
{
    $hasLat = array_key_exists('latitude', $query);
    $hasLng = array_key_exists('longitude', $query);

    if (!$hasLat || !$hasLng) {
        return null;
    }

    $latitude = (float)$query['latitude'];
    $longitude = (float)$query['longitude'];
    $accuracy = array_key_exists('accuracy', $query) ? (float)$query['accuracy'] : null;

    if ($latitude < -90 || $latitude > 90) {
        return null;
    }

    if ($longitude < -180 || $longitude > 180) {
        return null;
    }

    if ($accuracy !== null && $accuracy < 0) {
        $accuracy = null;
    }

    return [
        'latitude' => $latitude,
        'longitude' => $longitude,
        'accuracy' => $accuracy,
    ];
}

/**
 * Devuelve los tipos de problema para que el prompt pueda categorizar.
 */
function getProblemTypesForPrompt(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $pdo = getDB();
    $stmt = $pdo->query('SELECT id, name, description FROM problem_types ORDER BY id ASC');
    $rows = $stmt->fetchAll() ?: [];

    $cache = array_map(function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'description' => (string)($row['description'] ?? ''),
        ];
    }, $rows);

    return $cache;
}

/**
 * Resuelve el ID de tipo de problema a partir de la categoría inferida o descripción.
 */
function resolveProblemTypeId(?string $categoria, ?string $descripcion): ?int
{
    $types = getProblemTypesForPrompt();
    if (empty($types)) {
        return null;
    }

    $normalize = static function (?string $value): string {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        $value = mb_strtolower($value, 'UTF-8');
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = preg_replace('/[^a-z0-9\s]/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return trim($value);
    };

    $cat = $normalize($categoria);
    $desc = $normalize($descripcion);

    // 1) Match directo por categoria contra name/description
    if ($cat !== '') {
        foreach ($types as $type) {
            $nameNorm = $normalize($type['name']);
            $descNorm = $normalize($type['description']);
            if ($cat === $nameNorm || ($nameNorm !== '' && str_contains($cat, $nameNorm)) || ($cat !== '' && str_contains($nameNorm, $cat))) {
                return (int)$type['id'];
            }
            if ($descNorm !== '' && str_contains($descNorm, $cat)) {
                return (int)$type['id'];
            }
        }
    }

    // 2) Match por palabras de la descripcion contra el nombre del tipo
    if ($desc !== '') {
        foreach ($types as $type) {
            $nameNorm = $normalize($type['name']);
            if ($nameNorm !== '' && str_contains($desc, $nameNorm)) {
                return (int)$type['id'];
            }
        }
    }

    // 3) Fallback a "Otro" si existe
    foreach ($types as $type) {
        if ($normalize($type['name']) === 'otro') {
            return (int)$type['id'];
        }
    }

    return (int)$types[0]['id'];
}
