<?php
/**
 * Acceso Senior Backend — PHP Router Principal
 * 
 * Punto de entrada único. Todas las solicitudes pasan por aquí
 * gracias al .htaccess con mod_rewrite.
 */

// ─── Configuración de errores ────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// ─── Dependencias compartidas ────────────────────────────────
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/cors.php';
require_once __DIR__ . '/utils/response.php';
require_once __DIR__ . '/utils/jwt.php';
require_once __DIR__ . '/utils/validation.php';
require_once __DIR__ . '/middleware/auth.php';
require_once __DIR__ . '/middleware/role.php';

// ─── CORS (incluye manejo de OPTIONS preflight) ──────────────
applyCORS();

// ─── Parsear URI y método ────────────────────────────────────
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$method     = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Remover query string para comparar solo el path
$path = parse_url($requestUri, PHP_URL_PATH);

// Remover prefijo de subdirectorio solo cuando SCRIPT_NAME realmente apunta a index.php.
// En el servidor embebido de PHP (-S ... index.php), SCRIPT_NAME puede venir con la URL solicitada.
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$scriptNameNormalized = str_replace('\\', '/', $scriptName);

if (
    $scriptNameNormalized !== ''
    && preg_match('#/index\.php$#', $scriptNameNormalized)
) {
    $scriptDir = dirname($scriptNameNormalized);
    if ($scriptDir !== '/' && $scriptDir !== '.') {
        if (strpos($path, $scriptDir) === 0) {
            $path = '/' . ltrim(substr($path, strlen($scriptDir)), '/');
        }
    }
}

// Normalizar: siempre empieza con /, sin trailing slash (excepto raíz)
$path = '/' . trim($path, '/');

// Si la app está desplegada dentro de /api, al remover el subdirectorio
// el path queda como /problem-types, /auth/login, etc. Internamente todas
// las rutas están definidas con prefijo /api, así que lo restauramos.
if ($path !== '/' && !str_starts_with($path, '/api/')) {
    $path = '/api' . $path;
}

// Variable global para parámetros de ruta extraídos
$routeParams = [];

// ─── Definición de rutas ─────────────────────────────────────
// Formato: [método, patrón_regex, archivo_php]
// Los grupos con nombre (?P<name>...) se inyectan en $routeParams
$routes = [
    // ── Raíz ──
    ['GET', '#^/$#', null], // manejado inline

    // ── Auth ──
    ['POST', '#^/api/auth/login$#',    'api/auth/login.php'],
    ['POST', '#^/api/auth/register$#', 'api/auth/register.php'],

    // ── Cases (rutas estáticas primero, luego las dinámicas) ──
    ['POST', '#^/api/cases$#',                                       'api/cases/create.php'],
    ['GET',  '#^/api/cases/mine/facilitador$#',                      'api/cases/get_mine_facilitador.php'],
    ['GET',  '#^/api/cases/mine/admin$#',                            'api/cases/get_mine_admin.php'],
    ['GET',  '#^/api/cases/mine$#',                                  'api/cases/get_mine.php'],
    ['GET',  '#^/api/cases/available$#',                              'api/cases/get_available.php'],
    ['POST', '#^/api/cases/(?P<id>\d+)/assign$#',                    'api/cases/assign.php'],
    ['PUT',  '#^/api/cases/(?P<id>\d+)/status$#',                    'api/cases/update_status.php'],
    ['GET',  '#^/api/cases/(?P<id>\d+)$#',                           'api/cases/get_by_id.php'],

    // ── Users ──
    ['GET', '#^/api/users/me$#', 'api/users/get_profile.php'],
    ['PUT', '#^/api/users/me$#', 'api/users/update_profile.php'],

    // ── Centers ──
    ['GET',  '#^/api/centers$#', 'api/centers/list.php'],
    ['POST', '#^/api/centers$#', 'api/centers/create.php'],

    // ── Problem Types (URL con guión para compatibilidad con Node.js) ──
    ['GET',  '#^/api/problem-types$#', 'api/problem_types/list.php'],
    ['POST', '#^/api/problem-types$#', 'api/problem_types/create.php'],

    // ── Asistente IA ──
    ['GET',  '#^/api/asistente/stream$#',        'api/asistente/stream.php'],
    ['GET',  '#^/api/asistente/soporte$#',        'api/asistente/soporte.php'],
    ['GET',  '#^/api/asistente/nuevoUsuario$#',   'api/asistente/nuevo_usuario.php'],

    // ── Proxy ──
    ['POST', '#^/api/proxy$#', 'api/proxy.php'],
];

// ─── Buscar ruta coincidente ─────────────────────────────────
$matched = false;

foreach ($routes as [$routeMethod, $pattern, $file]) {
    if ($method !== $routeMethod) {
        continue;
    }
    if (preg_match($pattern, $path, $matches)) {
        $matched = true;

        // Extraer parámetros con nombre del regex
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $routeParams[$key] = $value;
            }
        }

        // Ruta raíz — respuesta inline
        if ($file === null) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'      => true,
                'service' => 'Acceso Senior Backend (PHP)',
                'version' => '2.1.1',
            ]);
            exit;
        }

        // Incluir el archivo del endpoint
        $filePath = __DIR__ . '/' . $file;
        if (file_exists($filePath)) {
            require $filePath;
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'Endpoint file not found: ' . $file,
            ]);
        }
        exit;
    }
}

// ─── Ruta no encontrada ──────────────────────────────────────
if (!$matched) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => 'Ruta no encontrada',
        'path'    => $path,
        'method'  => $method,
    ]);
    exit;
}
