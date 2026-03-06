<?php
/**
 * Configuración de conexión a la base de datos MySQL usando PDO.
 *
 * Carga variables desde entorno real y, opcionalmente, desde un archivo .env
 * en la raíz del proyecto para facilitar despliegues en hosting compartido.
 */

/**
 * Carga un archivo .env simple (KEY=VALUE) si existe.
 * No sobrescribe variables ya definidas en el entorno del servidor.
 */
function loadEnvFile(string $envPath): void
{
    if (!is_file($envPath) || !is_readable($envPath)) {
        return;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $parts = explode('=', $trimmed, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);

        // Quita comillas simples o dobles alrededor del valor
        if (
            strlen($value) >= 2
            && ((str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'")))
        ) {
            $value = substr($value, 1, -1);
        }

        if ($key === '') {
            continue;
        }

        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

/**
 * Obtiene una variable de entorno con fallback.
 */
function envValue(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }

    return $value;
}

loadEnvFile(dirname(__DIR__) . '/.env');

// ── Credenciales de la base de datos ──
define('DB_HOST', envValue('DB_HOST', 'localhost'));
define('DB_NAME', envValue('DB_NAME', 'acceso_senior'));
define('DB_USER', envValue('DB_USER', 'root'));
define('DB_PASS', envValue('DB_PASS', ''));
define('DB_CHARSET', envValue('DB_CHARSET', 'utf8mb4'));

// ── Clave secreta para JWT ──
define('JWT_SECRET', envValue('JWT_SECRET', 'CAMBIAR_ESTA_CLAVE_EN_PRODUCCION'));
define('JWT_EXPIRATION', (int) envValue('JWT_EXPIRATION', (string) (60 * 60 * 24 * 7))); // 7 días en segundos

// ── API Keys para servicios de IA (dejar vacío si no se usa) ──
define('GROQ_API_KEY', envValue('GROQ_API_KEY', ''));
define('GEMINI_API_KEY', envValue('GEMINI_API_KEY', ''));

// ── CORS ──
define('CORS_ORIGIN', envValue('CORS_ORIGIN', '*')); // En producción: 'https://tudominio.com'

/**
 * Obtiene una conexión PDO a la base de datos.
 * Usa singleton para reutilizar la conexión dentro de una misma request.
 *
 * @return PDO
 */
function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // Prepared statements reales
        ];

        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }

    return $pdo;
}
