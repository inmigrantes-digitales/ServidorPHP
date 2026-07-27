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

// ── Limpieza oportunista de sesiones viejas ──
// No hay cron configurado en este entorno, así que en vez de depender de uno, se
// dispara con baja probabilidad en cada request (mismo patrón que usa PHP para el
// garbage collection de sus propias sesiones: session.gc_probability/gc_divisor).
// Es seguro perder archivos de sesión viejos: los datos permanentes del usuario están
// en `users`/`cases`, y las conversaciones que sí terminaron en un caso ya quedaron
// archivadas aparte en `ai_sessions` (ver archiveConversationForCase) antes de que
// esto las borre.
if (random_int(1, 100) === 1) {
    cleanExpiredSessions();
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
            // Usuario rechazó → ofrecer menú de qué corregir directamente desde el
            // backend (no depender del LLM para esto: como ningún dato se borra solo,
            // si se deja que la IA "interprete" el rechazo, los campos siguen completos
            // y el flujo vuelve a caer en confirm_data una y otra vez sin salida).
            $session['awaitingConfirmation'] = false;
            $result = buildCorrectionMenuResponse($session);
            addToHistory($session, 'assistant', $result['assistant']['message']);
            sendSSE('__JSON__START__' . json_encode($result, JSON_UNESCAPED_UNICODE));
            $session['lastAction'] = 'select_correction';
            saveAISession($sessionId, $session);
            exit;
        }
        // null = indeterminado → dejar que el LLM interprete
    }

    // ── Manejar selección de qué corregir (después de un "no" en confirm_data) ──
    if (($session['lastAction'] ?? null) === 'select_correction') {
        $result = handleCorrectionSelection($message, $session);
        addToHistory($session, 'assistant', $result['assistant']['message']);
        sendSSE('__JSON__START__' . json_encode($result, JSON_UNESCAPED_UNICODE));
        $session['lastAction'] = $result['action'] ?? 'select_correction';
        saveAISession($sessionId, $session);
        exit;
    }

    // ── Construir contexto para el prompt ──
    $promptContext = [
        'userData'    => $session['userData'] ?? [],
        'problemData' => $session['problemData'] ?? [],
        'dbUser'      => $session['dbUser'] ?? null,
        'userLookupDone' => !empty($session['userLookupDone']),
        'centers'     => getCentersForPrompt(),
        'problemTypes' => getProblemTypesForPrompt(),
    ];

    // ── Llamar al LLM ──
    // Excluir el último mensaje del historial (es el actual) para no duplicarlo
    $historyForLLM = array_slice($session['history'], 0, -1);

    // Nota: NO se transmiten tokens crudos en vivo al navegador. Este endpoint espera
    // JSON estructurado (no una charla libre), y el modelo económico usado a veces no
    // respeta esa instrucción y arranca escribiendo texto suelto. Si ese texto se
    // muestra en vivo antes de validarlo, el usuario ve una respuesta "fantasma" que
    // luego se contradice con la respuesta real (una vez validado el JSON, o tras el
    // reintento). Por eso esperamos la respuesta completa y validada antes de mostrar
    // nada; solo entonces se envía un único evento SSE con el mensaje final.
    $result = runAgent([
        'userMessage'    => $message,
        'sessionContext' => $promptContext,
        'history'        => $historyForLLM,
        'model'          => [
            'provider'    => !empty(GROQ_API_KEY) ? 'groq' : 'gemini',
            'name'        => !empty(GROQ_API_KEY) ? 'llama-3.1-8b-instant' : GEMINI_MODEL,
            'temperature' => 0.4,
        ],
        'stream'  => false,
        'onToken' => null,
    ]);

    $parsed = $result['parsed'];

    // Contexto: en qué dato puntual estamos de la etapa de registro (usuario nuevo).
    // Se usa para no confundir un teléfono con un DNI (ambos son 7-10 dígitos) y para
    // rescatar el nombre cuando el modelo no lo extrae correctamente.
    $regUserData = $session['userData'] ?? [];
    $awaitingNombreForRegistration = !empty($session['userLookupDone'])
        && empty($session['dbUser'])
        && empty($regUserData['nombre']);
    $awaitingPhoneForRegistration = !empty($session['userLookupDone'])
        && empty($session['dbUser'])
        && !empty($regUserData['nombre'])
        && empty($regUserData['telefono']);

    // Fallback defensivo: si el modelo no colocó DNI en data.update pero el usuario lo escribió,
    // extraerlo del mensaje para no perder el disparo de check_user.
    // Aceptamos 7-10 dígitos para capturar entradas mal formateadas y validarlas luego.
    // No aplica si en este paso puntual se está esperando un teléfono: también son
    // 7-10 dígitos y NO es un DNI nuevo, es la respuesta a "¿su teléfono?".
    if (
        !$awaitingPhoneForRegistration
        && empty($parsed['data']['update']['dni'])
        && preg_match('/\b(\d{7,10})\b/', $message, $dniMatch)
    ) {
        $parsed['data']['update']['dni'] = $dniMatch[1];
    }

    // Fallback simétrico: si estamos esperando el teléfono y el modelo no lo extrajo,
    // tomarlo directamente del mensaje cuando tiene forma de teléfono.
    if ($awaitingPhoneForRegistration && empty($parsed['data']['update']['telefono'])) {
        $phoneDigits = preg_replace('/\D/', '', $message);
        if (strlen($phoneDigits) >= 8) {
            $parsed['data']['update']['telefono'] = $phoneDigits;
        }
    }

    // Fallback defensivo para el nombre: el modelo a veces no logra extraerlo de un
    // mensaje simple como "Pedro Flores". Si estamos esperando el nombre y el mensaje
    // parece plausiblemente un nombre (solo letras/espacios, 2+ palabras), usarlo tal
    // cual — sacando antes frases de introducción comunes ("mi nombre es...", "me
    // llamo...", "soy...") para no guardar la frase entera como si fuera el nombre.
    if ($awaitingNombreForRegistration && empty($parsed['data']['update']['nombre'])) {
        $trimmedMsg = trim((string)preg_replace(
            '/^(mi\s+nombre\s+es|me\s+llamo|yo\s+soy|soy)\s+/ui',
            '',
            trim($message)
        ));
        $wordCount = $trimmedMsg === '' ? 0 : count(array_filter(preg_split('/\s+/u', $trimmedMsg)));
        if (
            $trimmedMsg !== ''
            && mb_strlen($trimmedMsg, 'UTF-8') <= 80
            && $wordCount >= 2
            && preg_match('/^[\p{L}\s.\'-]+$/u', $trimmedMsg)
        ) {
            $parsed['data']['update']['nombre'] = $trimmedMsg;
        }
    }

    // Fallback defensivo para la descripción del problema: el modelo a veces no logra
    // extraerla de un mensaje libre (igual que pasaba con nombre/teléfono). Si el turno
    // anterior fue justamente "ask_problem" (le acabamos de preguntar cuál es su problema)
    // y todavía no hay descripción, usar el mensaje tal cual si es lo bastante largo
    // para ser una descripción real.
    // OJO: no aplica si el mensaje en realidad es una consulta de estado ("¿cómo va mi
    // caso?", "quería saber el estado de mi caso") — eso no es la descripción de un
    // caso nuevo, es otra intención (detectConversationIntent la va a redirigir a
    // check_case_status más abajo). Guardarlo como descripción acá lo dejaría pegado
    // en la sesión y reaparecería más adelante como si fuera un caso nuevo real.
    if (
        ($session['lastAction'] ?? null) === 'ask_problem'
        && empty($session['problemData']['descripcion'] ?? null)
        && empty($parsed['data']['update']['descripcion'])
        && detectConversationIntent($message) !== 'check_case_status'
    ) {
        $trimmedProblem = trim($message);
        if (mb_strlen($trimmedProblem, 'UTF-8') >= 10) {
            $parsed['data']['update']['descripcion'] = $trimmedProblem;
        }
    }

    // ── Detectar cambio de identidad ──
    // Una vez identificado un usuario en la sesión, el resto del flujo evita volver a
    // pedir DNI o repetir el registro (a propósito, para no ser repetitivo). Pero eso
    // significa que si esta persona en realidad NO es quien ya se identificó (dispositivo
    // compartido, se equivocó de conversación, etc.), el sistema seguía arrastrando el
    // nombre/DNI/caso de la persona anterior indefinidamente. Si llega un DNI distinto al
    // ya verificado, o el usuario dice explícitamente que no es esa persona, se resetea la
    // identificación para volver a verificar desde cero.
    $newDniClean = !empty($parsed['data']['update']['dni'])
        ? preg_replace('/\D/', '', (string)$parsed['data']['update']['dni'])
        : null;
    $storedDniClean = !empty($session['userData']['dni'])
        ? preg_replace('/\D/', '', (string)$session['userData']['dni'])
        : null;

    $dniChanged = !empty($session['userLookupDone'])
        && !$awaitingPhoneForRegistration
        && $newDniClean
        && $storedDniClean
        && $newDniClean !== $storedDniClean;

    if ($dniChanged || detectIdentityMismatchIntent($message)) {
        $session['userData'] = $newDniClean ? ['dni' => $newDniClean] : [];
        $session['dbUser'] = null;
        $session['userLookupDone'] = false;
        $session['problemData'] = [];
        $session['awaitingConfirmation'] = false;

        if ($newDniClean) {
            // Ya tenemos un DNI nuevo: dejar que el flujo normal lo verifique ahora mismo.
            $parsed['data']['update'] = ['dni' => $newDniClean];
        } else {
            // Dijo que no es esa persona pero todavía no dio un DNI nuevo.
            $parsed['assistant']['message'] = 'Disculpe la confusión. ¿Podría indicarme su número de DNI, por favor?';
            $parsed['action'] = 'ask_dni';
            $parsed['data']['update'] = [];
        }
    }

    // Solo interpretar el mensaje como selección de centro cuando el turno anterior
    // fue justamente la pregunta de centro (ask_location). Aplica tanto a usuarios
    // nuevos como existentes, ya que el centro se pide para cada caso.
    // Evita falsos positivos con números sueltos (DNI, teléfono, edad, etc.) en otros pasos.
    if (
        empty($parsed['data']['update']['center_id'])
        && ($session['lastAction'] ?? null) === 'ask_location'
    ) {
        $centerFromText = inferCenterSelectionFromMessage($message, $parsed['data']['update'] ?? []);
        if (!empty($centerFromText)) {
            $parsed['data']['update'] = array_merge($parsed['data']['update'] ?? [], $centerFromText);
        }
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

    // Si el paso avanzó respecto al turno anterior (ej: ya se capturó el teléfono y
    // ahora corresponde pedir el centro), SIEMPRE se usa un mensaje propio y
    // garantizadamente coherente con la acción final — sin importar si el LLM había
    // propuesto esa misma acción o no. No alcanza con comparar "¿el backend tuvo que
    // corregir la acción?", porque el LLM puede proponer la acción correcta (ej.
    // ask_location) y aun así escribir un texto que quedó pensado para la pregunta
    // anterior (ej. seguir hablando del teléfono) — son dos campos del mismo JSON que
    // el modelo no siempre mantiene consistentes entre sí.
    //
    // Si en cambio seguimos en EL MISMO paso que el turno anterior (ej: seguimos
    // esperando el nombre), confiamos en el texto del LLM — esto es lo que permite que
    // responda de forma conversacional a una repregunta del usuario ("¿para qué me
    // registrás?") y retome el pedido del dato pendiente con sus propias palabras, en
    // vez de repetir siempre el mismo texto enlatado como si no hubiera escuchado nada.
    //
    // "register_user"/"update_user_data" piden en realidad DOS datos distintos
    // (nombre y luego teléfono) bajo la misma acción, así que comparar solo el nombre
    // de la acción no alcanza: hay que comparar también CUÁL de los dos falta, para
    // no confundir "seguimos pidiendo el nombre" con "ya se capturó el nombre y ahora
    // pedimos el teléfono" (este turno sí avanzó, aunque la acción siga diciendo lo mismo).
    $beforeSignature = stepSignature($session['lastAction'] ?? 'ask_dni', $regUserData, $session['dbUser'] ?? null);
    $afterSignature = stepSignature($action, $session['userData'] ?? [], $session['dbUser'] ?? null);
    $stayedOnSameStep = $afterSignature === $beforeSignature;

    if (!$stayedOnSameStep) {
        $parsed['assistant']['message'] = buildFallbackMessageForAction($action, $session);
    }

    $parsed['action'] = $action;
    $parsed = handleAction($action, $parsed, $session, $sessionId, $message);

    // handleAction (p.ej. handleCheckUser) puede reetiquetar la acción final;
    // usar esa versión para que lastAction refleje lo que realmente vio el usuario.
    $action = $parsed['action'] ?? $action;

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

        case 'ask_location':
            return handleAskLocation($parsed, $session);

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
        // Usuario encontrado. Primero hay que saber PARA QUÉ escribe (puede ser solo
        // una consulta de estado, una pregunta, etc.) — recién si de verdad quiere
        // cargar un caso nuevo tiene sentido preguntarle el centro (ver
        // reconcileActionWithSession y chooseCreateFlowAction, que piden la
        // descripción antes que el centro para un usuario ya existente).
        $session['dbUser'] = $dbUser;
        $parsed['data']['update']['dni'] = $dniClean;
        $parsed['data']['update']['nombre'] = $dbUser['name'];
        $parsed['assistant']['message'] = "¡Bienvenido/a de nuevo, {$dbUser['name']}! ¿En qué puedo ayudarle hoy?";
        $parsed['action'] = 'ask_problem';
    } else {
        // Usuario no encontrado
        $session['dbUser'] = null;
        $parsed['assistant']['message'] = 'Vamos a registrarlo. ¿Podría decirme su nombre completo (nombre y apellido)?';
        $parsed['action'] = 'register_user';
        $parsed['validation']['missing_fields'] = ['nombre', 'telefono', 'center_id'];
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
    $hasCenter = !empty($userData['center_id']) || !empty($userData['center_name']);
    $hasDescripcion = !empty($problemData['descripcion']);

    if ($action === 'check_case_status') {
        return $action;
    }

    if ($action === 'ask_location') {
        // Usuario nuevo: solo confiar en ask_location si ya completó nombre + telefono.
        // Evita saltar al pedido de centro antes de tiempo si el LLM se adelanta.
        if ($lookupDone && empty($dbUser) && (!$hasNombre || !$hasTelefono)) {
            return 'register_user';
        }
        // Usuario existente: el centro recién se pregunta una vez que sabemos que
        // quiere cargar un caso nuevo (ya hay una descripción). Si todavía no la
        // hay, no hay que adelantarse a pedir el centro sin saber para qué escribió.
        if (!empty($dbUser) && !$hasDescripcion) {
            return 'ask_problem';
        }
        // Si ya se eligió centro para este caso, no repetir el paso.
        if ($hasCenter) {
            if (!$hasDescripcion) {
                return 'ask_problem';
            }
            return 'confirm_data';
        }
        return $action;
    }

    // Si ya se verificó DNI y no se encontró usuario, NO volver a pedir DNI.
    // Usuario NUEVO en alta: primero nombre+telefono+centro (parte de su registro),
    // recién después el problema. Una vez completo todo, siempre pasar a confirmar,
    // sin importar qué acción stale siga proponiendo el LLM (si insiste en "ask_problem"
    // por confusión, no hay que quedarse trabado repitiendo la pregunta para siempre).
    if ($lookupDone && empty($dbUser)) {
        if (!$hasNombre || !$hasTelefono) {
            return 'register_user';
        }
        if (!$hasCenter) {
            return 'ask_location';
        }
        if (!$hasDescripcion) {
            return 'ask_problem';
        }
        return 'confirm_data';
    }

    // Usuario YA EXISTENTE: primero hay que saber en qué se lo puede ayudar (puede ser
    // solo una consulta de estado, una pregunta, etc. — no siempre implica cargar un
    // caso nuevo). Solo una vez que hay una descripción (o sea, sí quiere cargar un
    // caso) tiene sentido preguntarle el centro para ESE caso puntual.
    if (!empty($dbUser)) {
        if (!$hasDescripcion) {
            return 'ask_problem';
        }
        if (!$hasCenter) {
            return 'ask_location';
        }
        return 'confirm_data';
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
 * Detecta si el usuario está diciendo explícitamente que NO es la persona con la
 * que la sesión cree estar hablando (dispositivo compartido, confusión, etc.).
 */
function detectIdentityMismatchIntent(string $userMessage): bool
{
    $msg = mb_strtolower(trim($userMessage), 'UTF-8');
    if ($msg === '') {
        return false;
    }

    $patterns = [
        '/\bno soy\b/ui',
        '/\bsoy otra persona\b/ui',
        '/\bme equivoqu/ui',
        '/\bno me llamo\b/ui',
        '/\bese no soy yo\b/ui',
        '/\besa no soy yo\b/ui',
        '/\bno es mi nombre\b/ui',
        '/\bno es mi dni\b/ui',
        '/\bcambiar de usuario\b/ui',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $msg) === 1) {
            return true;
        }
    }

    return false;
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
    $hasCenter = !empty($userData['center_id']) || !empty($userData['center_name']);
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
        if (!$hasCenter) {
            return 'ask_location';
        }
        if (!$hasDescripcion) {
            return 'ask_problem';
        }
        return 'confirm_data';
    }

    // Usuario existente: primero la descripción del problema, y solo si sí va a
    // cargar un caso (ya hay descripción) se pide el centro para ese caso.
    if (!$hasDescripcion) {
        return 'ask_problem';
    }

    if (!$hasCenter) {
        return 'ask_location';
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
 * Fuerza una pregunta de centro con opciones explícitas para el usuario.
 */
function handleAskLocation(array $parsed, array $session): array
{
    $baseMessage = trim((string)($parsed['assistant']['message'] ?? ''));
    if ($baseMessage === '') {
        $baseMessage = 'Por favor, indíqueme qué centro de Acceso Senior le queda más cerca.';
    }
    $parsed['assistant']['message'] = $baseMessage;

    $parsed['validation']['missing_fields'] = array_values(array_unique(array_merge(
        $parsed['validation']['missing_fields'] ?? [],
        ['center_id']
    )));

    return attachCenterOptions($parsed);
}

/**
 * Adjunta el catálogo de centros como opciones seleccionables (botones en el frontend).
 */
function attachCenterOptions(array $parsed): array
{
    $centers = getCentersForPrompt();
    $parsed['data']['summary']['center_options'] = array_map(static function (array $c): array {
        return [
            'id' => $c['id'],
            'name' => $c['name'],
            'zone' => $c['zone'] ?? null,
        ];
    }, $centers);

    return $parsed;
}

/**
 * Normaliza texto para comparaciones tolerantes a acentos/mayúsculas
 * (minúsculas, sin tildes, solo alfanumérico y espacios simples).
 *
 * OJO: no usar iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', ...) para esto — en este
 * entorno (Windows) la transliteración de vocales acentuadas inserta un apóstrofo
 * en vez de solo sacar la tilde (p.ej. "teléfono" -> "tel'efono"), lo que rompe la
 * palabra en dos al filtrar caracteres no alfanuméricos después. Se reemplazan las
 * tildes a mano para que el resultado sea predecible sin importar la plataforma.
 */
function normalizeForMatch(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $value = mb_strtolower($value, 'UTF-8');
    $value = strtr($value, [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n',
    ]);
    $value = preg_replace('/[^a-z0-9\s]/', ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim($value);
}

/**
 * Convierte una selección textual de centro a center_id/name/zone.
 */
function inferCenterSelectionFromMessage(string $message, array $update): ?array
{
    $centers = getCentersForPrompt();
    if (empty($centers)) {
        return null;
    }

    $byId = [];
    foreach ($centers as $center) {
        $byId[(int)$center['id']] = $center;
    }

    if (!empty($update['center_id'])) {
        $id = (int)$update['center_id'];
        if (!empty($byId[$id])) {
            return [
                'center_id' => $id,
                'center_name' => $byId[$id]['name'],
                'zone' => $byId[$id]['zone'] ?? null,
            ];
        }
    }

    $messageNorm = normalizeForMatch($message);
    $centerNameNorm = normalizeForMatch($update['center_name'] ?? '');

    if (preg_match('/\b(?:opcion|opción|centro|id)?\s*(\d{1,4})\b/ui', $message, $m)) {
        $candidateId = (int)$m[1];
        if (!empty($byId[$candidateId])) {
            return [
                'center_id' => $candidateId,
                'center_name' => $byId[$candidateId]['name'],
                'zone' => $byId[$candidateId]['zone'] ?? null,
            ];
        }
    }

    foreach ($centers as $center) {
        $nameNorm = normalizeForMatch($center['name'] ?? '');
        $zoneNorm = normalizeForMatch($center['zone'] ?? '');

        if ($centerNameNorm !== '' && ($centerNameNorm === $nameNorm || str_contains($nameNorm, $centerNameNorm))) {
            return [
                'center_id' => (int)$center['id'],
                'center_name' => $center['name'],
                'zone' => $center['zone'] ?? null,
            ];
        }

        if ($nameNorm !== '' && str_contains($messageNorm, $nameNorm)) {
            return [
                'center_id' => (int)$center['id'],
                'center_name' => $center['name'],
                'zone' => $center['zone'] ?? null,
            ];
        }

        if ($zoneNorm !== '' && str_contains($messageNorm, $zoneNorm)) {
            return [
                'center_id' => (int)$center['id'],
                'center_name' => $center['name'],
                'zone' => $center['zone'] ?? null,
            ];
        }
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

    try {
        if ($dbUser && !empty($dbUser['id'])) {
            // Usuario existente → solo crear caso, con el centro elegido para este caso
            $preferredCenterId = !empty($userData['center_id']) ? (int)$userData['center_id'] : null;
            $preferredCenterName = $userData['center_name'] ?? null;
            $caseResult = saveExistingUserCase((int)$dbUser['id'], $descripcion, $problemTypeId, $sessionId, $preferredCenterId, $preferredCenterName);
            $caseId = (int)$caseResult['caseId'];
            $nombre = $dbUser['name'] ?? $userData['nombre'] ?? 'estimado/a';
            $dni = $dbUser['dni'] ?? $userData['dni'] ?? null;
        } else {
            // Usuario nuevo → crear usuario + caso
            $result = saveNewUserAndCase($userData, $descripcion, $problemTypeId, $sessionId);
            $caseId = (int)$result['caseId'];
            $session['dbUser'] = ['id' => $result['userId']];
            $nombre = $userData['nombre'] ?? 'estimado/a';
            $dni = $userData['dni'] ?? null;
            $caseResult = $result;
        }

        $centerName = $caseResult['centerName'] ?? null;
        $centerText = $centerName ? " Se asignó al centro {$centerName}." : '';

        archiveConversationForCase($caseId, $sessionId, $session);
        resetCaseSpecificSessionData($session);

        $parsed['assistant']['message'] = "¡Listo, {$nombre}! Su consulta ha sido registrada exitosamente (N° {$caseId})."
            . $centerText
            . " En breve, un facilitador se pondrá en contacto con usted para ayudarle."
            . " ¡Que tenga un excelente día!";
        $parsed['action'] = 'finish';
        $parsed['process']['suggest_finish'] = true;
        $parsed['data']['summary'] = [
            'case_id' => $caseId,
            'center_assigned' => [
                'id' => $caseResult['centerId'] ?? null,
                'name' => $centerName,
            ],
            'consultante' => [
                'dni' => $dni,
                'nombre' => $nombre,
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

    try {
        if ($dbUser && !empty($dbUser['id'])) {
            $preferredCenterId = !empty($userData['center_id']) ? (int)$userData['center_id'] : null;
            $preferredCenterName = $userData['center_name'] ?? null;
            $caseResult = saveExistingUserCase((int)$dbUser['id'], $descripcion, $problemTypeId, $sessionId, $preferredCenterId, $preferredCenterName);
            $caseId = (int)$caseResult['caseId'];
            $nombre = $dbUser['name'] ?? $userData['nombre'] ?? 'estimado/a';
            $dni = $dbUser['dni'] ?? $userData['dni'] ?? null;
        } else {
            $result = saveNewUserAndCase($userData, $descripcion, $problemTypeId, $sessionId);
            $caseId = (int)$result['caseId'];
            $session['dbUser'] = ['id' => $result['userId']];
            $nombre = $userData['nombre'] ?? 'estimado/a';
            $dni = $userData['dni'] ?? null;
            $caseResult = $result;
        }

        archiveConversationForCase($caseId, $sessionId, $session);
        resetCaseSpecificSessionData($session);

        return buildFinalResponse($nombre, $caseId, $caseResult['centerName'] ?? null, $caseResult['centerId'] ?? null, $dni);

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
    $userFields = ['dni', 'nombre', 'telefono', 'email', 'center_id', 'center_name', 'zone'];
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
 * Identifica con precisión "qué dato puntual se está pidiendo" para una acción dada.
 * Para la mayoría de las acciones alcanza con el nombre de la acción (cada una pide
 * un solo dato), pero "register_user"/"update_user_data" en la práctica piden DOS
 * datos distintos en secuencia (nombre, luego teléfono), así que ahí se distingue
 * cuál de los dos falta todavía.
 */
function stepSignature(string $action, array $userData, ?array $dbUser): string
{
    if (in_array($action, ['register_user', 'update_user_data'], true) && empty($dbUser)) {
        if (empty($userData['nombre'])) {
            return 'register_user:nombre';
        }
        if (empty($userData['telefono'])) {
            return 'register_user:telefono';
        }
        return 'register_user:done';
    }

    return $action;
}

/**
 * Genera un mensaje propio y consistente para una acción que el backend forzó
 * (distinta a la que había propuesto el LLM). El texto del LLM quedó pensado para
 * su acción original y ya no corresponde al paso real del flujo, así que se
 * reemplaza en vez de mostrarlo mezclado con la acción correcta.
 */
function buildFallbackMessageForAction(string $action, array $session): string
{
    $userData = $session['userData'] ?? [];
    $problemData = $session['problemData'] ?? [];
    $nombre = $userData['nombre'] ?? null;

    switch ($action) {
        case 'ask_dni':
            return 'Por favor, indíqueme su número de DNI (sin puntos ni espacios).';

        case 'register_user':
        case 'update_user_data':
            if (empty($userData['nombre'])) {
                return 'Vamos a registrarlo. ¿Podría decirme su nombre completo (nombre y apellido)?';
            }
            if (empty($userData['telefono'])) {
                return "Gracias, {$nombre}. ¿Podría indicarme un teléfono de contacto?";
            }
            return "Gracias, {$nombre}. ¿Podría confirmarme sus datos?";

        case 'ask_location':
            return $nombre
                ? "¿En qué centro de Acceso Senior le gustaría ser atendido, {$nombre}?"
                : '¿En qué centro de Acceso Senior le gustaría ser atendido?';

        case 'ask_problem':
            return $nombre
                ? "Cuénteme, {$nombre}: ¿cuál es el problema o consulta que necesita resolver?"
                : '¿Cuál es el problema o consulta que necesita resolver?';

        case 'confirm_data':
            // Redactado como una frase natural (no una lista de campo: valor), para
            // que se sienta como alguien repasando los datos en voz alta, no como
            // un formulario. Cada parte se arma solo si el dato existe (para un
            // usuario ya existente, por ejemplo, puede no haber teléfono porque no
            // se le vuelve a pedir el que ya tiene registrado).
            $confirmParts = [];
            if (!empty($userData['dni'])) {
                $confirmParts[] = "su DNI es {$userData['dni']}";
            }
            if (!empty($userData['telefono'])) {
                $confirmParts[] = "el teléfono de contacto es {$userData['telefono']}";
            }
            if (!empty($userData['center_name'])) {
                $confirmParts[] = "lo vamos a atender en el centro {$userData['center_name']}";
            }

            $msg = $nombre ? "Muy bien, {$nombre}, ya tengo todo lo que necesito." : 'Muy bien, ya tengo todo lo que necesito.';
            if ($confirmParts) {
                $msg .= ' Le confirmo: ' . implode(', ', $confirmParts) . '.';
            }
            if (!empty($problemData['descripcion'])) {
                $msg .= " Me contó que necesita ayuda con esto: \"{$problemData['descripcion']}\".";
            }
            $msg .= ' ¿Está todo correcto?';
            return $msg;

        default:
            return $nombre
                ? "Continuemos, {$nombre}: ¿podría darme más detalles, por favor?"
                : '¿Podría darme más detalles, por favor?';
    }
}

/**
 * Arma la respuesta cuando el usuario rechaza el resumen de confirm_data, ofreciendo
 * botones con los datos que se pueden corregir. Para un usuario ya existente en el
 * sistema no se ofrece corregir nombre/teléfono (son de su cuenta, no de este caso).
 */
function buildCorrectionMenuResponse(array $session): array
{
    $dbUser = $session['dbUser'] ?? null;

    $response = buildFallbackResponse('Sin problema. ¿Qué dato le gustaría corregir?');
    $response['action'] = 'select_correction';

    $options = [];
    if (empty($dbUser)) {
        $options[] = ['value' => 'nombre', 'label' => 'Nombre completo'];
        $options[] = ['value' => 'telefono', 'label' => 'Teléfono'];
    }
    $options[] = ['value' => 'center', 'label' => 'Centro'];
    $options[] = ['value' => 'descripcion', 'label' => 'Descripción del problema'];
    $options[] = ['value' => 'cancelar', 'label' => 'Cancelar, no quiero cargar un caso'];

    $response['data']['summary'] = ['correction_options' => $options];

    return $response;
}

/**
 * Interpreta la elección del usuario sobre qué dato corregir (viene del menú de
 * buildCorrectionMenuResponse) y limpia ese campo puntual en la sesión para que el
 * flujo normal (los mismos pasos de siempre) lo vuelva a pedir.
 */
function handleCorrectionSelection(string $message, array &$session): array
{
    $msgNorm = normalizeForMatch($message);
    $dbUser = $session['dbUser'] ?? null;

    if (str_contains($msgNorm, 'cancelar')) {
        // El usuario no quiere seguir cargando este caso. Se descarta todo lo propio
        // del caso (centro elegido + descripción) — nada de esto llegó a guardarse en
        // la base de datos todavía (solo se escribe al confirmar), así que "cancelar"
        // acá significa simplemente no volver a preguntar por estos datos y quedar
        // disponible para lo que necesite a continuación. Los datos de identidad ya
        // dados (dni, nombre, teléfono) se conservan para no pedirlos de nuevo.
        resetCaseSpecificSessionData($session);
        $response = buildFallbackResponse('Entendido, no se cargará ningún caso. ¿En qué más puedo ayudarle?');
        $response['action'] = 'ask_problem';
        return $response;
    }

    if (str_contains($msgNorm, 'centro')) {
        unset($session['userData']['center_id'], $session['userData']['center_name'], $session['userData']['zone']);
        $response = buildFallbackResponse(buildFallbackMessageForAction('ask_location', $session));
        $response['action'] = 'ask_location';
        return attachCenterOptions($response);
    }

    if (str_contains($msgNorm, 'descripcion') || str_contains($msgNorm, 'problema')) {
        $session['problemData'] = [];
        $response = buildFallbackResponse(buildFallbackMessageForAction('ask_problem', $session));
        $response['action'] = 'ask_problem';
        return $response;
    }

    if (empty($dbUser) && str_contains($msgNorm, 'nombre')) {
        unset($session['userData']['nombre']);
        $response = buildFallbackResponse('Vamos a corregirlo. ¿Cuál es su nombre completo (nombre y apellido)?');
        $response['action'] = 'register_user';
        return $response;
    }

    if (empty($dbUser) && str_contains($msgNorm, 'telefono')) {
        unset($session['userData']['telefono']);
        $response = buildFallbackResponse('¿Cuál es el teléfono correcto?');
        $response['action'] = 'register_user';
        return $response;
    }

    // No matcheó ninguna opción válida (o pidió corregir algo no editable por acá):
    // repetir el menú.
    $response = buildCorrectionMenuResponse($session);
    $response['assistant']['message'] = 'No entendí bien cuál corregir. Por favor, elija una opción:';
    return $response;
}

/**
 * Guarda una copia permanente de la conversación en la tabla `ai_sessions` de la
 * base de datos cuando un caso se crea con éxito. Es independiente del archivo de
 * sesión en storage/ai_sessions/ (que se borra por antigüedad más adelante, haya
 * tenido éxito o no) — así las conversaciones que sí terminaron en un caso quedan
 * a salvo en la base antes de que el archivo temporal desaparezca.
 *
 * Se guarda con un id derivado de sessionId + caseId (no el sessionId solo) porque
 * una misma conversación puede crear más de un caso (ver resetCaseSpecificSessionData
 * más abajo), y cada uno debe conservar su propia copia sin pisar la anterior.
 *
 * Si falla (problema de conexión, etc.) no debe interrumpir la creación del caso, que
 * ya se guardó correctamente en `cases` — solo se registra el error.
 */
function archiveConversationForCase(int $caseId, string $sessionId, array $session): void
{
    try {
        $pdo = getDB();
        $archiveId = substr($sessionId . '_case' . $caseId, 0, 100);

        $payload = json_encode([
            'case_id'     => $caseId,
            'session_id'  => $sessionId,
            'history'     => $session['history'] ?? [],
            'userData'    => $session['userData'] ?? [],
            'problemData' => $session['problemData'] ?? [],
            'dbUser'      => $session['dbUser'] ?? null,
        ], JSON_UNESCAPED_UNICODE);

        $stmt = $pdo->prepare(
            'INSERT INTO ai_sessions (id, data, created_at, updated_at)
             VALUES (?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = NOW()'
        );
        $stmt->execute([$archiveId, $payload]);
    } catch (Exception $e) {
        error_log("[{$sessionId}] No se pudo archivar la conversación del caso {$caseId}: " . $e->getMessage());
    }
}

/**
 * Limpia los datos propios del caso (centro elegido y descripción del problema)
 * después de crear un ticket, para que si el usuario carga otro caso en la misma
 * sesión de chat se le vuelva a preguntar el centro (puede querer uno distinto)
 * y el problema, en vez de reutilizar silenciosamente los del caso anterior.
 * Los datos de identidad (dni, nombre, telefono, email) se conservan.
 */
function resetCaseSpecificSessionData(array &$session): void
{
    unset(
        $session['userData']['center_id'],
        $session['userData']['center_name'],
        $session['userData']['zone']
    );
    $session['problemData'] = [];
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
function buildFinalResponse(string $nombre, int $caseId, ?string $centerName = null, ?int $centerId = null, ?string $dni = null): array
{
    $centerText = $centerName ? " Se asignó al centro {$centerName}." : '';
    $response = buildFallbackResponse(
        "¡Listo, {$nombre}! Su consulta ha sido registrada exitosamente (N° {$caseId})."
        . $centerText
        . " En breve, un facilitador se pondrá en contacto con usted para ayudarle. "
        . "¡Que tenga un excelente día!"
    );
    $response['action'] = 'finish';
    $response['process']['suggest_finish'] = true;
    $response['data']['summary'] = [
        'case_id' => $caseId,
        'center_assigned' => [
            'id' => $centerId,
            'name' => $centerName,
        ],
        'consultante' => [
            'dni' => $dni,
            'nombre' => $nombre,
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
 * El centro se resuelve priorizando la elección hecha para ESTE caso
 * (preferredCenterId/Name), ya que un mismo usuario puede elegir centros
 * distintos entre un caso y otro. No se sobreescribe el centro "de perfil"
 * del usuario (users.center_id): eso solo aplica en el alta inicial.
 */
function saveExistingUserCase(
    int $userId,
    ?string $description,
    ?int $problemTypeId,
    string $sessionId,
    ?int $preferredCenterId = null,
    ?string $preferredCenterName = null
): array {
    $pdo = getDB();
    $resolvedCenter = resolveCenterAssignment($pdo, $userId, $preferredCenterId, $preferredCenterName);

    $stmt = $pdo->prepare(
        "INSERT INTO cases
            (consultante_id, center_id, problem_type_id, description, input_method, status, created_at)
         VALUES (?, ?, ?, ?, 'texto', 'ingresado', NOW())"
    );
    $stmt->execute([
        $userId,
        $resolvedCenter['centerId'],
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
    ];
}

/**
 * Crea un usuario nuevo + caso en una transacción.
 * Mapea campos del prompt (español) a columnas de BD (inglés).
 */
function saveNewUserAndCase(array $userData, ?string $description, ?int $problemTypeId, string $sessionId): array
{
    $pdo = getDB();

    // Mapeo de campos: prompt → BD
    $name     = $userData['nombre'] ?? null;
    $dni      = !empty($userData['dni']) ? preg_replace('/\D/', '', $userData['dni']) : null;
    $phone    = !empty($userData['telefono']) ? preg_replace('/\D/', '', $userData['telefono']) : null;
    $email    = $userData['email'] ?? null;
    $preferredCenterId = !empty($userData['center_id']) ? (int)$userData['center_id'] : null;
    $preferredCenterName = $userData['center_name'] ?? null;

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

        $resolvedCenter = resolveCenterAssignment($pdo, $consultanteId ? (int)$consultanteId : null, $preferredCenterId, $preferredCenterName);
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
        $stmt = $pdo->prepare(
            "INSERT INTO cases
                (consultante_id, center_id, problem_type_id, description, input_method, status, created_at)
             VALUES (?, ?, ?, ?, 'texto', 'ingresado', NOW())"
        );
        $stmt->execute([
            $consultanteId,
            $resolvedCenter['centerId'],
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
 * Determina el centro a asignar, priorizando la elección explícita del usuario
 * para este caso, y si no hay ninguna, el centro ya asociado a su cuenta.
 */
function resolveCenterAssignment(PDO $pdo, ?int $consultanteId, ?int $preferredCenterId = null, ?string $preferredCenterName = null): array
{
    if (!empty($preferredCenterId)) {
        $stmt = $pdo->prepare('SELECT id, name, zone FROM centers WHERE id = ? LIMIT 1');
        $stmt->execute([$preferredCenterId]);
        $preferred = $stmt->fetch();
        if ($preferred) {
            return [
                'centerId' => (int)$preferred['id'],
                'centerName' => $preferred['name'] ?? null,
                'centerZone' => $preferred['zone'] ?? null,
                'source' => 'manual_center',
            ];
        }
    }

    if (!empty($preferredCenterName)) {
        $preferredName = mb_strtolower(trim((string)$preferredCenterName), 'UTF-8');
        $stmt = $pdo->query('SELECT id, name, zone FROM centers ORDER BY name ASC');
        foreach ($stmt->fetchAll() ?: [] as $center) {
            $centerName = mb_strtolower(trim((string)($center['name'] ?? '')), 'UTF-8');
            if ($preferredName !== '' && ($preferredName === $centerName || str_contains($centerName, $preferredName))) {
                return [
                    'centerId' => (int)$center['id'],
                    'centerName' => $center['name'] ?? null,
                    'centerZone' => $center['zone'] ?? null,
                    'source' => 'manual_center_name',
                ];
            }
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
                'source' => 'user_center',
            ];
        }
    }

    $fallback = $pdo->query('SELECT id, name, zone FROM centers ORDER BY id ASC LIMIT 1')->fetch();
    if ($fallback) {
        return [
            'centerId' => (int)$fallback['id'],
            'centerName' => $fallback['name'] ?? null,
            'centerZone' => $fallback['zone'] ?? null,
            'source' => 'fallback',
        ];
    }

    return [
        'centerId' => null,
        'centerName' => null,
        'centerZone' => null,
        'source' => null,
    ];
}

/**
 * Obtiene los centros disponibles para que el prompt pueda sugerir el más cercano.
 */
function getCentersForPrompt(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $pdo = getDB();
    $stmt = $pdo->query('SELECT id, name, address, zone FROM centers ORDER BY name ASC');
    $rows = $stmt->fetchAll() ?: [];

    $cache = array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'address' => (string)($row['address'] ?? ''),
            'zone' => (string)($row['zone'] ?? ''),
        ];
    }, $rows);

    return $cache;
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

    $cat = normalizeForMatch($categoria);
    $desc = normalizeForMatch($descripcion);

    // 1) Match directo por categoria contra name/description
    if ($cat !== '') {
        foreach ($types as $type) {
            $nameNorm = normalizeForMatch($type['name']);
            $descNorm = normalizeForMatch($type['description']);
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
            $nameNorm = normalizeForMatch($type['name']);
            if ($nameNorm !== '' && str_contains($desc, $nameNorm)) {
                return (int)$type['id'];
            }
        }
    }

    // 3) Fallback a "Otro" si existe
    foreach ($types as $type) {
        if (normalizeForMatch($type['name']) === 'otro') {
            return (int)$type['id'];
        }
    }

    return (int)$types[0]['id'];
}
