-- ====================================
-- SISTEMA DE RESERVAS DE SERVICIOS
-- DATABASE SCHEMA - ESQUEMA COMPLETO
-- Archivo: database/schema.sql
-- Sección 1 de 1 (500 líneas)
-- ====================================
-- Configuración inicial de la base de datos
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";
-- Configuración de caracteres UTF8
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */
;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */
;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */
;
/*!40101 SET NAMES utf8mb4 */
;
-- ====================================
-- TABLA: users (Usuarios propietarios)
-- ====================================
CREATE TABLE `users` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `uuid` varchar(36) NOT NULL,
    `email` varchar(255) NOT NULL,
    `password` varchar(255) NOT NULL,
    `first_name` varchar(100) NOT NULL,
    `last_name` varchar(100) NOT NULL,
    `phone` varchar(20) DEFAULT NULL,
    `business_name` varchar(255) DEFAULT NULL,
    `business_address` text DEFAULT NULL,
    `business_phone` varchar(20) DEFAULT NULL,
    `business_email` varchar(255) DEFAULT NULL,
    `business_cuit` varchar(20) DEFAULT NULL,
    `business_description` text DEFAULT NULL,
    `latitude` decimal(10, 8) DEFAULT NULL,
    `longitude` decimal(11, 8) DEFAULT NULL,
    `profile_image` varchar(500) DEFAULT NULL,
    `business_logo` varchar(500) DEFAULT NULL,
    `plan_type` enum('trial', 'basic', 'premium', 'enterprise') DEFAULT 'trial',
    `plan_expires` datetime DEFAULT NULL,
    `status` enum('active', 'inactive', 'suspended', 'pending') DEFAULT 'pending',
    `email_verified` tinyint(1) DEFAULT 0,
    `two_factor_enabled` tinyint(1) DEFAULT 0,
    `two_factor_secret` varchar(255) DEFAULT NULL,
    `trial_started` datetime DEFAULT NULL,
    `last_login` datetime DEFAULT NULL,
    `login_attempts` int(3) DEFAULT 0,
    `locked_until` datetime DEFAULT NULL,
    `emergency_contact_name` varchar(255) DEFAULT NULL,
    `emergency_contact_phone` varchar(20) DEFAULT NULL,
    `emergency_contact_email` varchar(255) DEFAULT NULL,
    `business_hours` json DEFAULT NULL,
    `social_media` json DEFAULT NULL,
    `settings` json DEFAULT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uuid` (`uuid`),
    UNIQUE KEY `email` (`email`),
    UNIQUE KEY `business_cuit` (`business_cuit`),
    KEY `idx_plan_type` (`plan_type`),
    KEY `idx_status` (`status`),
    KEY `idx_location` (`latitude`, `longitude`),
    KEY `idx_created_at` (`created_at`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- ====================================
-- TABLA: service_categories (Categorías de servicios)
-- ====================================
CREATE TABLE `service_categories` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(100) NOT NULL,
    `slug` varchar(100) NOT NULL,
    `description` text DEFAULT NULL,
    `icon` varchar(100) DEFAULT NULL,
    `color` varchar(7) DEFAULT '#667eea',
    `sort_order` int(11) DEFAULT 0,
    `is_active` tinyint(1) DEFAULT 1,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`),
    KEY `idx_active` (`is_active`),
    KEY `idx_sort` (`sort_order`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- ====================================
-- TABLA: services (Servicios ofrecidos)
-- ====================================
CREATE TABLE `services` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `uuid` varchar(36) NOT NULL,
    `user_id` int(11) NOT NULL,
    `category_id` int(11) NOT NULL,
    `name` varchar(255) NOT NULL,
    `description` text DEFAULT NULL,
    `price` decimal(10, 2) NOT NULL,
    `duration` int(11) NOT NULL COMMENT 'Duración en minutos',
    `max_capacity` int(11) DEFAULT 1,
    `advance_booking_days` int(11) DEFAULT 30,
    `min_advance_hours` int(11) DEFAULT 2,
    `cancellation_hours` int(11) DEFAULT 24,
    `buffer_time` int(11) DEFAULT 0 COMMENT 'Tiempo entre citas en minutos',
    `images` json DEFAULT NULL,
    `requirements` text DEFAULT NULL,
    `terms` text DEFAULT NULL,
    `is_active` tinyint(1) DEFAULT 1,
    `display_order` int(11) DEFAULT 0,
    `availability` json DEFAULT NULL COMMENT 'Horarios disponibles por día',
    `blocked_dates` json DEFAULT NULL COMMENT 'Fechas bloqueadas',
    `special_prices` json DEFAULT NULL COMMENT 'Precios especiales por fecha',
    `metadata` json DEFAULT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uuid` (`uuid`),
    KEY `fk_services_user` (`user_id`),
    KEY `fk_services_category` (`category_id`),
    KEY `idx_active` (`is_active`),
    KEY `idx_price` (`price`),
    KEY `idx_duration` (`duration`),
    CONSTRAINT `fk_services_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_services_category` FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- ====================================
-- TABLA: bookings (Reservas de turnos)
-- ====================================
CREATE TABLE `bookings` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `uuid` varchar(36) NOT NULL,
    `service_id` int(11) NOT NULL,
    `user_id` int(11) NOT NULL COMMENT 'Propietario del servicio',
    `customer_name` varchar(255) NOT NULL,
    `customer_email` varchar(255) NOT NULL,
    `customer_phone` varchar(20) NOT NULL,
    `booking_date` date NOT NULL,
    `booking_time` time NOT NULL,
    `end_time` time NOT NULL,
    `duration` int(11) NOT NULL COMMENT 'Duración en minutos',
    `participants` int(11) DEFAULT 1,
    `total_price` decimal(10, 2) NOT NULL,
    `deposit_required` decimal(10, 2) DEFAULT 0.00,
    `deposit_paid` decimal(10, 2) DEFAULT 0.00,
    `status` enum(
        'pending',
        'confirmed',
        'in_progress',
        'completed',
        'cancelled',
        'no_show'
    ) DEFAULT 'pending',
    `payment_status` enum('pending', 'partial', 'paid', 'refunded') DEFAULT 'pending',
    `payment_method` varchar(50) DEFAULT NULL,
    `payment_reference` varchar(255) DEFAULT NULL,
    `special_requests` text DEFAULT NULL,
    `cancellation_reason` text DEFAULT NULL,
    `cancelled_at` datetime DEFAULT NULL,
    `cancelled_by` enum('customer', 'provider', 'system') DEFAULT NULL,
    `reminder_sent` tinyint(1) DEFAULT 0,
    `confirmation_sent` tinyint(1) DEFAULT 0,
    `rating` tinyint(1) DEFAULT NULL COMMENT '1-5 estrellas',
    `review` text DEFAULT NULL,
    `review_date` datetime DEFAULT NULL,
    `internal_notes` text DEFAULT NULL,
    `metadata` json DEFAULT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uuid` (`uuid`),
    KEY `fk_bookings_service` (`service_id`),
    KEY `fk_bookings_user` (`user_id`),
    KEY `idx_booking_date` (`booking_date`),
    KEY `idx_booking_time` (`booking_time`),
    KEY `idx_status` (`status`),
    KEY `idx_payment_status` (`payment_status`),
    KEY `idx_customer_email` (`customer_email`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_bookings_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_bookings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- ====================================
-- TABLA: payments (Transacciones de pago)
-- ====================================
CREATE TABLE `payments` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `uuid` varchar(36) NOT NULL,
    `user_id` int(11) NOT NULL,
    `booking_id` int(11) DEFAULT NULL,
    `type` enum('subscription', 'booking', 'deposit', 'refund') NOT NULL,
    `amount` decimal(10, 2) NOT NULL,
    `currency` varchar(3) DEFAULT 'ARS',
    `status` enum(
        'pending',
        'processing',
        'completed',
        'failed',
        'cancelled',
        'refunded'
    ) DEFAULT 'pending',
    `payment_method` varchar(50) NOT NULL,
    `provider` varchar(50) NOT NULL COMMENT 'MercadoPago, QR, etc.',
    `provider_id` varchar(255) DEFAULT NULL,
    `provider_status` varchar(100) DEFAULT NULL,
    `provider_response` json DEFAULT NULL,
    `description` varchar(255) DEFAULT NULL,
    `reference_number` varchar(255) DEFAULT NULL,
    `webhook_received` tinyint(1) DEFAULT 0,
    `processed_at` datetime DEFAULT NULL,
    `expires_at` datetime DEFAULT NULL,
    `metadata` json DEFAULT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uuid` (`uuid`),
    KEY `fk_payments_user` (`user_id`),
    KEY `fk_payments_booking` (`booking_id`),
    KEY `idx_status` (`status`),
    KEY `idx_provider_id` (`provider_id`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_payments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_payments_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE
    SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- ====================================
-- TABLA: reviews (Calificaciones y reseñas)
-- ====================================
CREATE TABLE `reviews` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `booking_id` int(11) NOT NULL,
    `user_id` int(11) NOT NULL COMMENT 'Propietario del servicio',
    `service_id` int(11) NOT NULL,
    `customer_name` varchar(255) NOT NULL,
    `customer_email` varchar(255) NOT NULL,
    `rating` tinyint(1) NOT NULL COMMENT '1-5 estrellas',
    `review_text` text DEFAULT NULL,
    `service_rating` tinyint(1) DEFAULT NULL,
    `location_rating` tinyint(1) DEFAULT NULL,
    `staff_rating` tinyint(1) DEFAULT NULL,
    `value_rating` tinyint(1) DEFAULT NULL,
    `would_recommend` tinyint(1) DEFAULT NULL,
    `is_verified` tinyint(1) DEFAULT 1,
    `is_featured` tinyint(1) DEFAULT 0,
    `is_visible` tinyint(1) DEFAULT 1,
    `admin_response` text DEFAULT NULL,
    `admin_response_date` datetime DEFAULT NULL,
    `helpful_votes` int(11) DEFAULT 0,
    `total_votes` int(11) DEFAULT 0,
    `flagged_count` int(11) DEFAULT 0,
    `language` varchar(5) DEFAULT 'es',
    `ip_address` varchar(45) DEFAULT NULL,
    `user_agent` text DEFAULT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_booking_review` (`booking_id`),
    KEY `fk_reviews_user` (`user_id`),
    KEY `fk_reviews_service` (`service_id`),
    KEY `idx_rating` (`rating`),
    KEY `idx_visible` (`is_visible`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_reviews_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_reviews_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_reviews_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- ====================================
-- TABLA: notifications (Sistema de notificaciones)
-- ====================================
CREATE TABLE `notifications` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `uuid` varchar(36) NOT NULL,
    `user_id` int(11) DEFAULT NULL,
    `booking_id` int(11) DEFAULT NULL,
    `type` enum('email', 'sms', 'whatsapp', 'push', 'system') NOT NULL,
    `category` enum(
        'booking',
        'payment',
        'reminder',
        'emergency',
        'system',
        'marketing'
    ) NOT NULL,
    `recipient` varchar(255) NOT NULL,
    `subject` varchar(255) DEFAULT NULL,
    `message` text NOT NULL,
    `template` varchar(100) DEFAULT NULL,
    `template_data` json DEFAULT NULL,
    `status` enum(
        'pending',
        'sent',
        'delivered',
        'failed',
        'cancelled'
    ) DEFAULT 'pending',
    `provider` varchar(50) DEFAULT NULL,
    `provider_id` varchar(255) DEFAULT NULL,
    `provider_response` json DEFAULT NULL,
    `attempts` int(3) DEFAULT 0,
    `max_attempts` int(3) DEFAULT 3,
    `scheduled_at` datetime DEFAULT NULL,
    `sent_at` datetime DEFAULT NULL,
    `delivered_at` datetime DEFAULT NULL,
    `opened_at` datetime DEFAULT NULL,
    `clicked_at` datetime DEFAULT NULL,
    `error_message` text DEFAULT NULL,
    `priority` enum('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    `metadata` json DEFAULT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uuid` (`uuid`),
    KEY `fk_notifications_user` (`user_id`),
    KEY `fk_notifications_booking` (`booking_id`),
    KEY `idx_status` (`status`),
    KEY `idx_type` (`type`),
    KEY `idx_category` (`category`),
    KEY `idx_scheduled_at` (`scheduled_at`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE
    SET NULL,
        CONSTRAINT `fk_notifications_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE
    SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- ====================================
-- TABLA: system_updates (Actualizaciones del sistema)
-- ====================================
CREATE TABLE `system_updates` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `version` varchar(20) NOT NULL,
    `title` varchar(255) NOT NULL,
    `description` text DEFAULT NULL,
    `changelog` text DEFAULT NULL,
    `type` enum('major', 'minor', 'patch', 'hotfix') DEFAULT 'patch',
    `status` enum('development', 'testing', 'released', 'deprecated') DEFAULT 'development',
    `release_date` datetime DEFAULT NULL,
    `download_url` varchar(500) DEFAULT NULL,
    `installation_notes` text DEFAULT NULL,
    `compatibility` json DEFAULT NULL,
    `requires_restart` tinyint(1) DEFAULT 0,
    `auto_update` tinyint(1) DEFAULT 0,
    `force_update` tinyint(1) DEFAULT 0,
    `rollback_version` varchar(20) DEFAULT NULL,
    `created_by` varchar(100) DEFAULT 'System',
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `version` (`version`),
    KEY `idx_status` (`status`),
    KEY `idx_type` (`type`),
    KEY `idx_release_date` (`release_date`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- ====================================
-- TABLA: admin_logs (Logs del sistema)
-- ====================================
CREATE TABLE `admin_logs` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) DEFAULT NULL,
    `action` varchar(100) NOT NULL,
    `entity_type` varchar(50) DEFAULT NULL,
    `entity_id` int(11) DEFAULT NULL,
    `description` text DEFAULT NULL,
    `ip_address` varchar(45) DEFAULT NULL,
    `user_agent` text DEFAULT NULL,
    `request_data` json DEFAULT NULL,
    `response_data` json DEFAULT NULL,
    `severity` enum('info', 'warning', 'error', 'critical') DEFAULT 'info',
    `duration` int(11) DEFAULT NULL COMMENT 'Duración en milisegundos',
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_admin_logs_user` (`user_id`),
    KEY `idx_action` (`action`),
    KEY `idx_entity` (`entity_type`, `entity_id`),
    KEY `idx_severity` (`severity`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_admin_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE
    SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- ====================================
-- TABLA: emergency_contacts (Contactos de emergencia)
-- ====================================
CREATE TABLE `emergency_contacts` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `name` varchar(255) NOT NULL,
    `relationship` varchar(100) DEFAULT NULL,
    `phone` varchar(20) NOT NULL,
    `email` varchar(255) DEFAULT NULL,
    `is_primary` tinyint(1) DEFAULT 0,
    `can_access_business` tinyint(1) DEFAULT 0,
    `notification_types` json DEFAULT NULL,
    `notes` text DEFAULT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_emergency_contacts_user` (`user_id`),
    KEY `idx_primary` (`is_primary`),
    CONSTRAINT `fk_emergency_contacts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- ====================================
-- INSERCIÓN DE DATOS INICIALES
-- ====================================
-- Categorías de servicios por defecto
INSERT INTO `service_categories` (
        `name`,
        `slug`,
        `description`,
        `icon`,
        `color`,
        `sort_order`
    )
VALUES (
        'Belleza y Estética',
        'belleza-estetica',
        'Servicios de belleza, peluquería y estética',
        'fas fa-cut',
        '#ff6b6b',
        1
    ),
    (
        'Salud y Bienestar',
        'salud-bienestar',
        'Servicios médicos, terapias y bienestar',
        'fas fa-heartbeat',
        '#4ecdc4',
        2
    ),
    (
        'Fitness y Deporte',
        'fitness-deporte',
        'Gimnasios, entrenamientos personales y deportes',
        'fas fa-dumbbell',
        '#45b7d1',
        3
    ),
    (
        'Gastronomía',
        'gastronomia',
        'Restaurantes, cafeterías y servicios gastronómicos',
        'fas fa-utensils',
        '#f9ca24',
        4
    ),
    (
        'Automóvil',
        'automovil',
        'Servicios automotrices y mantenimiento vehicular',
        'fas fa-car',
        '#6c5ce7',
        5
    ),
    (
        'Hogar y Reparaciones',
        'hogar-reparaciones',
        'Servicios domésticos y reparaciones del hogar',
        'fas fa-tools',
        '#fd79a8',
        6
    ),
    (
        'Educación y Consultoría',
        'educacion-consultoria',
        'Clases particulares, consultoría y capacitación',
        'fas fa-graduation-cap',
        '#00b894',
        7
    ),
    (
        'Eventos y Entretenimiento',
        'eventos-entretenimiento',
        'Organización de eventos y entretenimiento',
        'fas fa-calendar-alt',
        '#e17055',
        8
    );
-- Actualización del sistema inicial
INSERT INTO `system_updates` (
        `version`,
        `title`,
        `description`,
        `type`,
        `status`,
        `release_date`,
        `created_by`
    )
VALUES (
        '1.0.0',
        'Lanzamiento inicial',
        'Primera versión del sistema de reservas',
        'major',
        'released',
        NOW(),
        'Sistema'
    );
-- Configuración de AUTO_INCREMENT
ALTER TABLE `users` AUTO_INCREMENT = 1001;
ALTER TABLE `service_categories` AUTO_INCREMENT = 101;
ALTER TABLE `services` AUTO_INCREMENT = 2001;
ALTER TABLE `bookings` AUTO_INCREMENT = 10001;
ALTER TABLE `payments` AUTO_INCREMENT = 50001;
ALTER TABLE `reviews` AUTO_INCREMENT = 30001;
ALTER TABLE `notifications` AUTO_INCREMENT = 100001;
ALTER TABLE `system_updates` AUTO_INCREMENT = 1;
ALTER TABLE `admin_logs` AUTO_INCREMENT = 1;
ALTER TABLE `emergency_contacts` AUTO_INCREMENT = 1;
-- Commit de la transacción
COMMIT;
-- Restauración de configuración
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */
;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */
;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */
;
-- ====================================
-- ÍNDICES ADICIONALES PARA OPTIMIZACIÓN
-- ====================================
-- Índices compuestos para consultas frecuentes
CREATE INDEX `idx_user_plan_status` ON `users` (`plan_type`, `status`);
CREATE INDEX `idx_service_user_active` ON `services` (`user_id`, `is_active`);
CREATE INDEX `idx_booking_date_status` ON `bookings` (`booking_date`, `status`);
CREATE INDEX `idx_booking_user_date` ON `bookings` (`user_id`, `booking_date`);
CREATE INDEX `idx_payment_user_status` ON `payments` (`user_id`, `status`);
CREATE INDEX `idx_notification_scheduled` ON `notifications` (`status`, `scheduled_at`);
-- Índices de texto completo para búsquedas
ALTER TABLE `users`
ADD FULLTEXT KEY `ft_business_search` (`business_name`, `business_description`);
ALTER TABLE `services`
ADD FULLTEXT KEY `ft_service_search` (`name`, `description`);
ALTER TABLE `reviews`
ADD FULLTEXT KEY `ft_review_search` (`review_text`);
-- ====================================
-- FIN DEL ESQUEMA DE BASE DE DATOS
-- ====================================
-- 
-- ====================================
-- SECCIÓN COMPLETADA: Esquema de Base de Datos
-- ARCHIVO: database/schema.sql
-- LÍNEAS: 500
-- PROGRESO: 1/41 archivos completados
-- ====================================
-- SEGUIR AQUÍ
-- ====================================