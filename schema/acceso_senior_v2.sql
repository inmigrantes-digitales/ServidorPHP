-- ============================================================
-- Acceso Senior — Schema SQL v2 (Mejorado para PHP Backend)
-- ============================================================
-- Compatible con MySQL 5.7+ y MariaDB 10.x
-- Mejoras respecto al schema original:
--   1. Campo 'dni' agregado a tabla 'users'
--   2. Índice UNIQUE en users.email (parcial, permite NULL)
--   3. Índice UNIQUE en users.phone
--   4. Índice en cases.status y cases.created_at
--   5. problem_type_id nullable en cases (IA a veces crea sin tipo)
--   6. Tabla ai_sessions para persistir sesiones de IA (opcional)
--   7. Foreign keys completas con acciones ON DELETE
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- ============================================================
-- Base de datos
-- ============================================================
CREATE DATABASE IF NOT EXISTS `acceso_senior`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `acceso_senior`;

-- ============================================================
-- Tabla: centers (Centros comunitarios)
-- ============================================================
CREATE TABLE IF NOT EXISTS `centers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL COMMENT 'Nombre del centro comunitario',
  `address` VARCHAR(255) DEFAULT NULL COMMENT 'Dirección física del centro',
  `zone` VARCHAR(100) DEFAULT NULL COMMENT 'Zona o barrio del centro',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Centros comunitarios donde se atienden consultas presencialmente';

-- ============================================================
-- Tabla: users (Usuarios del sistema)
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) DEFAULT NULL COMMENT 'Nombre completo del usuario',
  `dni` VARCHAR(20) DEFAULT NULL COMMENT 'Documento Nacional de Identidad (solo números)',
  `phone` VARCHAR(50) NOT NULL COMMENT 'Teléfono de contacto',
  `email` VARCHAR(255) DEFAULT NULL COMMENT 'Correo electrónico (opcional para consultantes)',
  `password_hash` VARCHAR(255) DEFAULT NULL COMMENT 'Hash bcrypt de la contraseña',
  `role` ENUM('consultante','facilitador','centro','admin') DEFAULT 'consultante'
    COMMENT 'Rol del usuario en el sistema',
  `center_id` INT(11) DEFAULT NULL COMMENT 'Centro comunitario asociado (si aplica)',
  `zone` VARCHAR(100) DEFAULT NULL COMMENT 'Zona o barrio del usuario',
  `address` VARCHAR(255) DEFAULT NULL COMMENT 'Dirección del usuario',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_phone` (`phone`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_dni` (`dni`),
  KEY `idx_users_role` (`role`),
  KEY `fk_users_center` (`center_id`),
  CONSTRAINT `fk_users_center` FOREIGN KEY (`center_id`)
    REFERENCES `centers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Usuarios del sistema: consultantes, facilitadores, centros y administradores';

-- ============================================================
-- Tabla: problem_types (Categorías de problemas)
-- ============================================================
CREATE TABLE IF NOT EXISTS `problem_types` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL COMMENT 'Nombre del tipo de problema',
  `description` TEXT DEFAULT NULL COMMENT 'Descripción detallada del tipo de problema',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Categorías de problemas que pueden registrar los usuarios';

-- ============================================================
-- Tabla: cases (Consultas/Casos registrados)
-- ============================================================
CREATE TABLE IF NOT EXISTS `cases` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `consultante_id` INT(11) DEFAULT NULL COMMENT 'Usuario que registra la consulta',
  `facilitator_id` INT(11) DEFAULT NULL COMMENT 'Facilitador asignado al caso',
  `center_id` INT(11) DEFAULT NULL COMMENT 'Centro comunitario donde se registró',
  `problem_type_id` INT(11) DEFAULT NULL COMMENT 'Tipo de problema (puede ser NULL si la IA no lo asigna)',
  `description` TEXT DEFAULT NULL COMMENT 'Descripción del problema reportado',
  `input_method` ENUM('voz','texto','centro') NOT NULL DEFAULT 'texto'
    COMMENT 'Método de ingreso de la consulta',
  `status` ENUM('ingresado','asignado','proceso','resuelto','cerrado','escalado') DEFAULT 'ingresado'
    COMMENT 'Estado actual del caso en su ciclo de vida',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha de creación del caso',
  `assigned_at` DATETIME DEFAULT NULL COMMENT 'Fecha de asignación a facilitador',
  `resolved_at` DATETIME DEFAULT NULL COMMENT 'Fecha de resolución',
  `closed_at` DATETIME DEFAULT NULL COMMENT 'Fecha de cierre definitivo',
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cases_status` (`status`),
  KEY `idx_cases_created` (`created_at`),
  KEY `idx_cases_consultante` (`consultante_id`),
  KEY `idx_cases_facilitator` (`facilitator_id`),
  KEY `fk_cases_center` (`center_id`),
  KEY `fk_cases_problem_type` (`problem_type_id`),
  CONSTRAINT `fk_cases_consultante` FOREIGN KEY (`consultante_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_cases_facilitator` FOREIGN KEY (`facilitator_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_cases_center` FOREIGN KEY (`center_id`)
    REFERENCES `centers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_cases_problem_type` FOREIGN KEY (`problem_type_id`)
    REFERENCES `problem_types` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Consultas/casos registrados por personas mayores';

-- ============================================================
-- Tabla: case_history (Historial de acciones sobre casos)
-- ============================================================
CREATE TABLE IF NOT EXISTS `case_history` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `case_id` INT(11) NOT NULL COMMENT 'Caso al que pertenece esta entrada',
  `user_id` INT(11) DEFAULT NULL COMMENT 'Usuario que realizó la acción',
  `action` VARCHAR(100) DEFAULT NULL COMMENT 'Tipo de acción realizada (ej: caso_creado, caso_asignado, cambio_estado)',
  `previous_value` JSON DEFAULT NULL COMMENT 'Valor anterior (JSON)',
  `new_value` JSON DEFAULT NULL COMMENT 'Valor nuevo (JSON)',
  `comment` TEXT DEFAULT NULL COMMENT 'Comentario descriptivo de la acción',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_history_case` (`case_id`),
  KEY `idx_history_user` (`user_id`),
  KEY `idx_history_created` (`created_at`),
  CONSTRAINT `fk_history_case` FOREIGN KEY (`case_id`)
    REFERENCES `cases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_history_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Registro de todas las acciones realizadas sobre cada caso (trazabilidad)';

-- ============================================================
-- Tabla: ai_sessions (Sesiones de IA conversacional — opcional)
-- ============================================================
-- Esta tabla permite persistir sesiones de IA en la base de datos
-- en lugar de archivos temporales. Es opcional pero recomendado
-- para entornos con múltiples servidores o restricciones de filesystem.
-- ============================================================
CREATE TABLE IF NOT EXISTS `ai_sessions` (
  `id` VARCHAR(100) NOT NULL COMMENT 'ID de sesión (generado por el cliente)',
  `data` JSON NOT NULL COMMENT 'Datos de la sesión (historial, formData, modo, etc.)',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sessions_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sesiones de IA conversacional (alternativa a archivos temporales)';

-- ============================================================
-- Datos iniciales de ejemplo
-- ============================================================

-- Tipos de problemas comunes
INSERT INTO `problem_types` (`id`, `name`, `description`) VALUES
  (1, 'Ayuda trámite', 'Ayuda para trámites en línea'),
  (2, 'Cuenta/Password', 'Recupero o creación de cuentas'),
  (3, 'Uso de dispositivo', 'Ayuda con el uso del celular, tablet o computadora'),
  (4, 'Aplicaciones', 'Instalación o uso de aplicaciones'),
  (5, 'Otro', 'Otro tipo de problema no listado')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Centro de ejemplo
INSERT INTO `centers` (`id`, `name`, `address`, `zone`, `created_at`) VALUES
  (1, 'Centro de Ayuda Comunitario', 'Cabildo 1124', 'Liniers', NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Usuarios de ejemplo (passwords: "123456" hasheados con bcrypt cost=10)
-- NOTA: Los hashes bcrypt de Node.js son compatibles con PHP password_verify()
INSERT INTO `users` (`id`, `name`, `phone`, `email`, `dni`, `password_hash`, `role`, `center_id`, `zone`, `created_at`) VALUES
  (2, 'Pepe García', '1164821749', 'pepe@gmail.com', '12345678',
   '$2b$10$RlYmmHYZk8PiIOq6f0aUFOg0bZZdhYZJx4Ry9J7aQXch350esKVOS',
   'consultante', 1, 'Liniers', NOW()),
  (3, 'Juan Administrador', '1164821449', 'juan@gmail.com', '98765432',
   '$2b$10$ZXddMt7qJZDQMPaULpNATuCiFKPrQpZ28F7VON9SyKcsrwt2jwpma',
   'admin', 1, 'Liniers', NOW()),
  (4, 'Juan Facilitador', '1164821419', 'juan_facilitador@gmail.com', '87654321',
   '$2b$10$9XeI0DZMLItyoQPUm7tsFu5rvbZhlS7jmzAOLjZQcTFem6rYecqKm',
   'facilitador', 1, 'Liniers', NOW()),
  (5, 'María López', '1166554433', 'maria@gmail.com', '11111111',
   '$2b$10$RlYmmHYZk8PiIOq6f0aUFOg0bZZdhYZJx4Ry9J7aQXch350esKVOS',
   'consultante', 1, 'Caballito', NOW()),
  (6, 'Carlos Martínez', '1177992288', 'carlos@gmail.com', '22222222',
   '$2b$10$RlYmmHYZk8PiIOq6f0aUFOg0bZZdhYZJx4Ry9J7aQXch350esKVOS',
   'consultante', 1, 'Flores', NOW()),
  (7, 'Facilitador Pedro', '1188334455', 'pedro_fac@gmail.com', '33333333',
   '$2b$10$9XeI0DZMLItyoQPUm7tsFu5rvbZhlS7jmzAOLjZQcTFem6rYecqKm',
   'facilitador', 1, 'Centro', NOW()),
  (8, 'Facilitador Rosa', '1155667788', 'rosa_fac@gmail.com', '44444444',
   '$2b$10$9XeI0DZMLItyoQPUm7tsFu5rvbZhlS7jmzAOLjZQcTFem6rYecqKm',
   'facilitador', 1, 'La Boca', NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Casos de ejemplo con diferentes estados y histórico
INSERT INTO `cases` (`id`, `consultante_id`, `facilitator_id`, `center_id`, `problem_type_id`, `description`, `input_method`, `status`, `created_at`, `assigned_at`, `resolved_at`, `closed_at`) VALUES
  -- Caso 1: Ingresado (sin asignar)
  (1, 2, NULL, 1, 2, 'No sé cómo crear una cuenta en un banco online', 'centro', 'ingresado', 
   DATE_SUB(NOW(), INTERVAL 5 DAY), NULL, NULL, NULL),
  
  -- Caso 2: Asignado (en proceso)
  (2, 5, 4, 1, 3, 'No puedo usar mi celular para conectarme a Wi-Fi', 'texto', 'proceso', 
   DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY), NULL, NULL),
  
  -- Caso 3: Resuelto hace poco
  (3, 6, 7, 1, 4, 'Necesito ayuda para instalar WhatsApp', 'centro', 'resuelto', 
   DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 9 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), NULL),
  
  -- Caso 4: Cerrado (histórico antiguo)
  (4, 2, 4, 1, 1, 'Consulta sobre tramitar DNI por internet', 'voz', 'cerrado', 
   DATE_SUB(NOW(), INTERVAL 30 DAY), DATE_SUB(NOW(), INTERVAL 29 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY), 
   DATE_SUB(NOW(), INTERVAL 3 DAY)),
  
  -- Caso 5: Ingresado nuevo
  (5, 5, NULL, 1, 5, 'Otro tipo de problema que no sé cómo categorizar', 'texto', 'ingresado', 
   NOW(), NULL, NULL, NULL),
  
  -- Caso 6: Asignado (reciente)
  (6, 6, 8, 1, 2, 'Recuperar contraseña de correo electrónico', 'centro', 'asignado', 
   DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY), NULL, NULL),
  
  -- Caso 7: En proceso
  (7, 2, 7, 1, 3, 'Problema al usar aplicación de banca móvil', 'voz', 'proceso', 
   DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY), NULL, NULL),
  
  -- Caso 8: Resuelto (antiguo)
  (8, 5, 4, 1, 4, 'Instalación de aplicación de videollamadas', 'centro', 'cerrado', 
   DATE_SUB(NOW(), INTERVAL 45 DAY), DATE_SUB(NOW(), INTERVAL 44 DAY), DATE_SUB(NOW(), INTERVAL 40 DAY),
   DATE_SUB(NOW(), INTERVAL 38 DAY)),
  
  -- Caso 9: Escalado
  (9, 6, 7, 1, 1, 'Problema complejo con trámite gubernamental', 'voz', 'escalado', 
   DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY), NULL, NULL),
  
  -- Caso 10: Cerrado (reciente)
  (10, 2, 8, 1, 3, 'Ayuda para conectarse a internet por cable', 'texto', 'cerrado', 
   DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY),
   DATE_SUB(NOW(), INTERVAL 1 DAY))
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- Actualizaciones de casos. Agregar historial de cambios para algunos casos.
INSERT INTO `case_history` (`case_id`, `user_id`, `action`, `previous_value`, `new_value`, `comment`, `created_at`) VALUES
  -- Historial del caso 2 (asignación)
  (2, 4, 'caso_asignado', 
   JSON_OBJECT('facilitator_id', NULL, 'status', 'ingresado'),
   JSON_OBJECT('facilitator_id', 4, 'status', 'asignado'),
   'Caso asignado a Juan Facilitador', DATE_SUB(NOW(), INTERVAL 3 DAY)),
  
  -- Historial del caso 2 (cambio de estado a proceso)
  (2, 4, 'cambio_estado', 
   JSON_OBJECT('status', 'asignado'),
   JSON_OBJECT('status', 'proceso'),
   'Facilitador comenzó a trabajar en el caso', DATE_SUB(NOW(), INTERVAL 2 DAY)),
  
  -- Historial del caso 3 (creación)
  (3, NULL, 'caso_creado',
   NULL,
   JSON_OBJECT('consultante_id', 6, 'problem_type_id', 4, 'status', 'ingresado'),
   'Caso creado por consultante', DATE_SUB(NOW(), INTERVAL 10 DAY)),
  
  -- Historial del caso 3 (asignación)
  (3, 7, 'caso_asignado',
   JSON_OBJECT('facilitator_id', NULL),
   JSON_OBJECT('facilitator_id', 7),
   'Caso asignado a Facilitador Pedro', DATE_SUB(NOW(), INTERVAL 9 DAY)),
  
  -- Historial del caso 3 (resolución)
  (3, 7, 'cambio_estado',
   JSON_OBJECT('status', 'proceso'),
   JSON_OBJECT('status', 'resuelto'),
   'Caso resuelto exitosamente', DATE_SUB(NOW(), INTERVAL 1 DAY)),
  
  -- Historial del caso 4 (antiguo, cerrado)
  (4, 4, 'caso_asignado',
   JSON_OBJECT('facilitator_id', NULL, 'status', 'ingresado'),
   JSON_OBJECT('facilitator_id', 4, 'status', 'asignado'),
   'Caso asignado', DATE_SUB(NOW(), INTERVAL 29 DAY)),
  
  (4, 4, 'cambio_estado',
   JSON_OBJECT('status', 'asignado'),
   JSON_OBJECT('status', 'resuelto'),
   'Caso resuelto', DATE_SUB(NOW(), INTERVAL 5 DAY)),
  
  (4, 4, 'cambio_estado',
   JSON_OBJECT('status', 'resuelto'),
   JSON_OBJECT('status', 'cerrado'),
   'Caso cerrado', DATE_SUB(NOW(), INTERVAL 3 DAY)),
  
  -- Historial del caso 8 (antiguo cerrado)
  (8, 4, 'caso_asignado',
   JSON_OBJECT('facilitator_id', NULL),
   JSON_OBJECT('facilitator_id', 4),
   'Asignado a Juan Facilitador', DATE_SUB(NOW(), INTERVAL 44 DAY)),
  
  (8, 4, 'cambio_estado',
   JSON_OBJECT('status', 'asignado'),
   JSON_OBJECT('status', 'resuelto'),
   'Caso resuelto', DATE_SUB(NOW(), INTERVAL 40 DAY)),
  
  (8, NULL, 'cambio_estado',
   JSON_OBJECT('status', 'resuelto'),
   JSON_OBJECT('status', 'cerrado'),
   'Cierre automático', DATE_SUB(NOW(), INTERVAL 38 DAY))
ON DUPLICATE KEY UPDATE `comment` = VALUES(`comment`);

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
