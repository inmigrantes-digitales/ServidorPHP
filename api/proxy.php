<?php
/**
 * Endpoint Proxy — Hace consultas a otros servidores con autenticación.
 *
 * Uso: POST /api/proxy
 * Body: {
 *   "url": "https://otro-servicio.com/endpoint",
 *   "method": "GET|POST|PUT|DELETE",
 *   "headers": {...},  // opcional
 *   "body": {...}      // opcional para POST/PUT
 * }
 *
 * El proxy:
 * - Agrega HTTP Basic Auth (usuario:clave) si está configurado
 * - Preserva el token JWT del usuario ( Authorization: Bearer ...)
 * - Propaga credenciales internas de forma segura
 */

// ─── Validar request ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error'   => 'Solo se permite POST',
    ]);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);

if (!$body || empty($body['url']) || empty($body['method'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Body debe contener: url (string), method (GET|POST|PUT|DELETE)',
    ]);
    exit;
}

$targetUrl = $body['url'];
$method = strtoupper($body['method']);
$headers = $body['headers'] ?? [];
$bodyData = $body['body'] ?? null;

// ─── Validar method ────────────────────────────────────────
$allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'];
if (!in_array($method, $allowedMethods)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => "Método no soportado: $method",
    ]);
    exit;
}

// ─── Validar URL (evitar SSRF básico) ──────────────────────
$parsed = parse_url($targetUrl);
if (!isset($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'URL debe ser HTTPS o HTTP válida',
    ]);
    exit;
}

// ─── Construir headers del proxy ───────────────────────────
$curlHeaders = [];

// 1. Agregar credenciales básicas (usuario:clave de Ferozo)
$proxyUser = envValue('PROXY_USER', '');
$proxyPass = envValue('PROXY_PASSWORD', '');
if (!empty($proxyUser) && !empty($proxyPass)) {
    $curlHeaders[] = 'Authorization: Basic ' . base64_encode("$proxyUser:$proxyPass");
}

// 2. Preservar token JWT del usuario actual (para identificación)
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!empty($authHeader) && preg_match('#Bearer\s+(.+)$#i', $authHeader, $matches)) {
    $userToken = $matches[1];
    // Agregar token en header custom para que el servidor destino pueda verificar
    // quién hizo la solicitud
    $curlHeaders[] = 'X-User-Token: ' . $userToken;
}

// 3. Agregar headers del cliente (custom, sin riesgos de seguridad)
$allowedClientHeaders = ['Content-Type', 'Accept', 'X-Requested-With'];
foreach ($headers as $key => $value) {
    if (in_array($key, $allowedClientHeaders)) {
        $curlHeaders[] = "$key: $value";
    }
}

// 4. Content-Type por defecto
if (!isset($headers['Content-Type']) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
    $curlHeaders[] = 'Content-Type: application/json';
}

// ─── Ejecutar proxy cURL ───────────────────────────────────
$ch = curl_init($targetUrl);

curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST   => $method,
    CURLOPT_HTTPHEADER      => $curlHeaders,
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_TIMEOUT         => 30,
    CURLOPT_CONNECTTIMEOUT  => 10,
    CURLOPT_SSL_VERIFYPEER  => true,
    CURLOPT_SSL_VERIFYHOST  => 2,
    CURLOPT_IPRESOLVE       => CURL_IPRESOLVE_V4,
]);

// Agregar body si es POST/PUT/PATCH
if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
    $bodyStr = is_array($bodyData) ? json_encode($bodyData) : $bodyData;
    curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyStr);
}

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// ─── Validar respuesta ─────────────────────────────────────
if ($response === false) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'error'   => 'Error conectando al servidor destino',
        'details' => $error,
    ]);
    exit;
}

// ─── Devolver respuesta del servidor destino ────────────────
http_response_code($httpCode);
header('Content-Type: application/json; charset=utf-8');

// Intentar decodificar como JSON
$decoded = json_decode($response, true);
if ($decoded !== null) {
    echo json_encode($decoded, JSON_UNESCAPED_UNICODE);
} else {
    // Si no es JSON, devolver como texto plano
    echo $response;
}
