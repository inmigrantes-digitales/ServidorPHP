<?php
/**
 * Gestor de sesiones de IA.
 *
 * En PHP no hay estado en memoria entre requests (a diferencia de Node.js).
 * Las sesiones de IA se persisten como archivos JSON temporales.
 *
 * Estructura de sesión:
 * {
 *   "history": [ {"role": "user|assistant", "content": "..."} ],
 *   "formData": { "name": "...", ... },
 *   "awaitingConfirmation": false,
 *   "mode": "recepcionista|formulario",
 *   "userData": null | { "id": ..., "name": ..., "dni": ..., "description": ... },
 *   "updated_at": 1234567890
 * }
 */

// Directorio donde se guardan los archivos de sesión
define('AI_SESSIONS_DIR', __DIR__ . '/../storage/ai_sessions');

/**
 * Inicializa el directorio de sesiones si no existe.
 */
function initSessionsDir(): void
{
    if (!is_dir(AI_SESSIONS_DIR)) {
        mkdir(AI_SESSIONS_DIR, 0755, true);
    }
}

/**
 * Genera la ruta del archivo de sesión para un sessionId dado.
 * Se sanitiza el sessionId para evitar directory traversal.
 */
function getSessionFilePath(string $sessionId): string
{
    // Sanitizar sessionId: solo permitir alfanuméricos, guiones y underscores
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $sessionId);
    if (empty($safe)) {
        $safe = 'default';
    }
    return AI_SESSIONS_DIR . '/' . $safe . '.json';
}

/**
 * Obtiene una sesión de IA por su ID.
 * Si no existe, retorna la estructura por defecto.
 *
 * @param string $sessionId ID de la sesión.
 * @return array Datos de la sesión.
 */
function getAISession(string $sessionId): array
{
    initSessionsDir();
    $path = getSessionFilePath($sessionId);

    if (file_exists($path)) {
        $content = file_get_contents($path);
        $data = json_decode($content, true);
        if (is_array($data)) {
            return $data;
        }
    }

    // Sesión por defecto
    return [
        'history'              => [],
        'formData'             => [],
        'awaitingConfirmation' => false,
        'mode'                 => 'recepcionista',
        'userData'             => null,
        'updated_at'           => time(),
    ];
}

/**
 * Guarda una sesión de IA.
 *
 * @param string $sessionId ID de la sesión.
 * @param array  $data      Datos de la sesión.
 */
function saveAISession(string $sessionId, array $data): void
{
    initSessionsDir();
    $path = getSessionFilePath($sessionId);
    $data['updated_at'] = time();
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

/**
 * Elimina una sesión de IA.
 */
function deleteAISession(string $sessionId): void
{
    $path = getSessionFilePath($sessionId);
    if (file_exists($path)) {
        unlink($path);
    }
}

/**
 * Agrega un mensaje al historial de la sesión.
 * Mantiene un máximo de 20 mensajes (los más recientes).
 *
 * @param array  &$session Referencia a la sesión.
 * @param string $role     'user' o 'assistant'.
 * @param string $content  Contenido del mensaje.
 */
function addToHistory(array &$session, string $role, string $content): void
{
    $session['history'][] = ['role' => $role, 'content' => $content];

    // Mantener máximo 20 mensajes
    if (count($session['history']) > 20) {
        $session['history'] = array_slice($session['history'], -20);
    }
}

/**
 * Limpia sesiones expiradas (más de 2 horas sin actividad).
 * Llamar periódicamente o en un cron.
 */
function cleanExpiredSessions(int $maxAge = 7200): void
{
    initSessionsDir();
    $files = glob(AI_SESSIONS_DIR . '/*.json');
    $now = time();

    foreach ($files as $file) {
        if (($now - filemtime($file)) > $maxAge) {
            unlink($file);
        }
    }
}
