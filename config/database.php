<?php
/**
 * Configuración de conexión a la base de datos MySQL usando PDO.
 * 
 * Credenciales: Editar las constantes según tu entorno de hosting.
 * En producción, este archivo debería estar protegido o fuera del directorio público.
 */

// ── Credenciales de la base de datos ──
define('DB_HOST', 'localhost');
define('DB_NAME', 'acceso_senior');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ── Clave secreta para JWT ──
define('JWT_SECRET', 'CAMBIAR_ESTA_CLAVE_EN_PRODUCCION');
define('JWT_EXPIRATION', 60 * 60 * 24 * 7); // 7 días en segundos

// ── API Keys para servicios de IA (dejar vacío si no se usa) ──
define('GROQ_API_KEY', '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');
define('GEMINI_API_KEY', 'AIzaSyDfNNLRdTm6t2MDsnCww5xzUDRCmyQqucI');

// ── CORS ──
define('CORS_ORIGIN', '*'); // En producción: 'https://tudominio.com'

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
