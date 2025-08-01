<?php
/**
 * API de Notificaciones Email - Sistema Empresarial Completo
 * Soporta múltiples proveedores, templates HTML, campañas y análisis avanzado
 * 
 * Funcionalidades:
 * - Múltiples proveedores (SendGrid, Mailgun, Amazon SES, SMTP)
 * - Templates HTML responsive con editor visual
 * - Campañas masivas con segmentación avanzada
 * - Análisis de entregabilidad y engagement
 * - A/B Testing para optimización
 * - Listas de suscriptores con gestión automática
 * - Webhooks para eventos de email
 * - Autenticación SPF/DKIM/DMARC
 * - Anti-spam y reputación de dominio
 */

require_once '../config/database.php';
require_once '../config/jwt_helper.php';
require_once '../utils/security.php';
require_once '../utils/validation.php';
require_once '../utils/logger.php';
require_once '../vendor/autoload.php'; // Para librerías de email

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

class EmailAPI {
    private $conn;
    private $logger;
    private $security;
    private $validator;
    
    // Configuración de proveedores
    private $providers = [
        'sendgrid' => [
            'name' => 'SendGrid',
            'api_url' => 'https://api.sendgrid.com/v3',
            'rate_limit' => 1000000, // emails por día
            'supports_templates' => true,
            'supports_analytics' => true,
            'max_recipients' => 1000
        ],
        'mailgun' => [
            'name' => 'Mailgun',
            'api_url' => 'https://api.mailgun.net/v3',
            'rate_limit' => 100000,
            'supports_templates' => true,
            'supports_analytics' => true,
            'max_recipients' => 1000
        ],
        'ses' => [
            'name' => 'Amazon SES',
            'api_url' => 'https://email.us-east-1.amazonaws.com',
            'rate_limit' => 200, // por segundo (configurable)
            'supports_templates' => true,
            'supports_analytics' => true,
            'max_recipients' => 50
        ],
        'smtp' => [
            'name' => 'SMTP Custom',
            'api_url' => null,
            'rate_limit' => 1000,
            'supports_templates' => false,
            'supports_analytics' => false,
            'max_recipients' => 100
        ]
    ];
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->logger = new Logger('email_api');
        $this->security = new Security();
        $this->validator = new Validator();
        
        $this->initializeTables();
    }
    
    private function initializeTables() {
        // Tabla principal de configuración de email
        $query = "CREATE TABLE IF NOT EXISTS email_config (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            provider ENUM('sendgrid', 'mailgun', 'ses', 'smtp') DEFAULT 'smtp',
            name VARCHAR(100) NOT NULL,
            api_key TEXT,
            api_secret TEXT,
            domain VARCHAR(100),
            smtp_host VARCHAR(100),
            smtp_port INT DEFAULT 587,
            smtp_username VARCHAR(200),
            smtp_password TEXT,
            smtp_encryption ENUM('tls', 'ssl', 'none') DEFAULT 'tls',
            from_email VARCHAR(200) NOT NULL,
            from_name VARCHAR(200),
            reply_to_email VARCHAR(200),
            bounce_email VARCHAR(200),
            webhook_url VARCHAR(500),
            webhook_secret VARCHAR(100),
            is_active BOOLEAN DEFAULT true,
            is_verified BOOLEAN DEFAULT false,
            daily_limit INT DEFAULT 1000,
            monthly_limit INT DEFAULT 10000,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )";
        $this->conn->exec($query);
        
        // Tabla de templates de email
        $query = "CREATE TABLE IF NOT EXISTS email_templates (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            config_id INT NOT NULL,
            name VARCHAR(200) NOT NULL,
            subject VARCHAR(500) NOT NULL,
            html_content LONGTEXT,
            text_content TEXT,
            template_variables JSON,
            category VARCHAR(100),
            is_active BOOLEAN DEFAULT true,
            preview_image_url VARCHAR(500),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (config_id) REFERENCES email_config(id) ON DELETE CASCADE
        )";
        $this->conn->exec($query);
        
        // Tabla de listas de suscriptores
        $query = "CREATE TABLE IF NOT EXISTS email_lists (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            name VARCHAR(200) NOT NULL,
            description TEXT,
            total_subscribers INT DEFAULT 0,
            active_subscribers INT DEFAULT 0,
            tags JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )";
        $this->conn->exec($query);
        
        // Tabla de suscriptores
        $query = "CREATE TABLE IF NOT EXISTS email_subscribers (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            list_id INT NOT NULL,
            email VARCHAR(200) NOT NULL,
            first_name VARCHAR(100),
            last_name VARCHAR(100),
            status ENUM('active', 'unsubscribed', 'bounced', 'spam_complaint') DEFAULT 'active',
            custom_fields JSON,
            source VARCHAR(100),
            subscribed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            unsubscribed_at TIMESTAMP NULL,
            bounce_count INT DEFAULT 0,
            last_activity_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (list_id) REFERENCES email_lists(id) ON DELETE CASCADE,
            UNIQUE KEY unique_subscriber (list_id, email)
        )";
        $this->conn->exec($query);
        
        // Tabla de campañas de email
        $query = "CREATE TABLE IF NOT EXISTS email_campaigns (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            config_id INT NOT NULL,
            template_id INT,
            list_id INT NOT NULL,
            name VARCHAR(200) NOT NULL,
            subject VARCHAR(500) NOT NULL,
            preheader VARCHAR(200),
            html_content LONGTEXT,
            text_content TEXT,
            status ENUM('draft', 'scheduled', 'sending', 'sent', 'paused', 'cancelled') DEFAULT 'draft',
            scheduled_at TIMESTAMP NULL,
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            total_recipients INT DEFAULT 0,
            sent_count INT DEFAULT 0,
            delivered_count INT DEFAULT 0,
            opened_count INT DEFAULT 0,
            clicked_count INT DEFAULT 0,
            bounced_count INT DEFAULT 0,
            unsubscribed_count INT DEFAULT 0,
            spam_count INT DEFAULT 0,
            is_ab_test BOOLEAN DEFAULT false,
            ab_test_percentage INT DEFAULT 10,
            ab_winner_criteria ENUM('open_rate', 'click_rate', 'conversion_rate'),
            ab_test_duration_hours INT DEFAULT 4,
            segment_conditions JSON,
            send_rate_limit INT DEFAULT 100,
            total_cost DECIMAL(10,4) DEFAULT 0.0000,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (config_id) REFERENCES email_config(id) ON DELETE CASCADE,
            FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE SET NULL,
            FOREIGN KEY (list_id) REFERENCES email_lists(id) ON DELETE CASCADE
        )";
        $this->conn->exec($query);
        
        // Tabla de emails enviados
        $query = "CREATE TABLE IF NOT EXISTS email_sends (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            config_id INT NOT NULL,
            campaign_id INT,
            template_id INT,
            subscriber_id INT,
            recipient_email VARCHAR(200) NOT NULL,
            subject VARCHAR(500),
            message_id VARCHAR(200),
            status ENUM('queued', 'sent', 'delivered', 'opened', 'clicked', 'bounced', 'spam', 'unsubscribed') DEFAULT 'queued',
            bounce_type ENUM('hard', 'soft', 'unknown'),
            bounce_reason TEXT,
            user_agent TEXT,
            ip_address VARCHAR(45),
            location_country VARCHAR(2),
            location_city VARCHAR(100),
            sent_at TIMESTAMP NULL,
            delivered_at TIMESTAMP NULL,
            opened_at TIMESTAMP NULL,
            first_opened_at TIMESTAMP NULL,
            clicked_at TIMESTAMP NULL,
            first_clicked_at TIMESTAMP NULL,
            unsubscribed_at TIMESTAMP NULL,
            cost DECIMAL(8,6) DEFAULT 0.000000,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (config_id) REFERENCES email_config(id) ON DELETE CASCADE,
            FOREIGN KEY (campaign_id) REFERENCES email_campaigns(id) ON DELETE CASCADE,
            FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE SET NULL,
            FOREIGN KEY (subscriber_id) REFERENCES email_subscribers(id) ON DELETE SET NULL,
            INDEX idx_recipient (recipient_email),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        )";
        $this->conn->exec($query);
        
        // Tabla de eventos de email (tracking detallado)
        $query = "CREATE TABLE IF NOT EXISTS email_events (
            id INT PRIMARY KEY AUTO_INCREMENT,
            send_id INT NOT NULL,
            event_type ENUM('sent', 'delivered', 'opened', 'clicked', 'bounced', 'spam', 'unsubscribed', 'deferred') NOT NULL,
            event_data JSON,
            user_agent TEXT,
            ip_address VARCHAR(45),
            clicked_url VARCHAR(1000),
            location_country VARCHAR(2),
            location_city VARCHAR(100),
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (send_id) REFERENCES email_sends(id) ON DELETE CASCADE,
            INDEX idx_send_id (send_id),
            INDEX idx_event_type (event_type),
            INDEX idx_timestamp (timestamp)
        )";
        $this->conn->exec($query);
        
        // Tabla de dominios y autenticación
        $query = "CREATE TABLE IF NOT EXISTS email_domains (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            config_id INT NOT NULL,
            domain VARCHAR(100) NOT NULL,
            spf_status ENUM('valid', 'invalid', 'pending') DEFAULT 'pending',
            dkim_status ENUM('valid', 'invalid', 'pending') DEFAULT 'pending',
            dmarc_status ENUM('valid', 'invalid', 'pending') DEFAULT 'pending',
            mx_status ENUM('valid', 'invalid', 'pending') DEFAULT 'pending',
            reputation_score INT DEFAULT 0,
            last_checked_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (config_id) REFERENCES email_config(id) ON DELETE CASCADE,
            UNIQUE KEY unique_domain (config_id, domain)
        )";
        $this->conn->exec($query);
        
        $this->logger->info("Email tables initialized successfully");
    }
    
    public function handleRequest() {
        try {
            $method = $_SERVER['REQUEST_METHOD'];
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $pathParts = explode('/', trim($path, '/'));
            
            // Webhook de eventos de email
            if ($method === 'POST' && end($pathParts) === 'webhook') {
                return $this->handleWebhook();
            }
            
            // Tracking de apertura de emails (pixel transparente)
            if ($method === 'GET' && $pathParts[3] === 'track' && $pathParts[4] === 'open') {
                return $this->trackEmailOpen($pathParts[5] ?? null);
            }
            
            // Tracking de clicks en enlaces
            if ($method === 'GET' && $pathParts[3] === 'track' && $pathParts[4] === 'click') {
                return $this->trackEmailClick($pathParts[5] ?? null);
            }
            
            // Página de desuscripción
            if ($method === 'GET' && $pathParts[3] === 'unsubscribe') {
                return $this->handleUnsubscribe($pathParts[4] ?? null);
            }
            
            // Autenticación requerida para otras operaciones
            $user = $this->security->validateSession();
            if (!$user) {
                http_response_code(401);
                echo json_encode(['error' => 'No autorizado']);
                return;
            }
            
            switch ($method) {
                case 'GET':
                    $this->handleGet($pathParts, $user);
                    break;
                case 'POST':
                    $this->handlePost($pathParts, $user);
                    break;
                case 'PUT':
                    $this->handlePut($pathParts, $user);
                    break;
                case 'DELETE':
                    $this->handleDelete($pathParts, $user);
                    break;
                default:
                    http_response_code(405);
                    echo json_encode(['error' => 'Método no permitido']);
            }
        } catch (Exception $e) {
            $this->logger->error("Error in Email API: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno del servidor']);
        }
    }
    
    private function handleGet($pathParts, $user) {
        $action = $pathParts[3] ?? '';
        
        switch ($action) {
            case 'config':
                $this->getConfiguration($user['id']);
                break;
            case 'templates':
                $this->getTemplates($user['id']);
                break;
            case 'lists':
                $this->getLists($user['id']);
                break;
            case 'subscribers':
                $this->getSubscribers($user['id']);
                break;
            case 'campaigns':
                $this->getCampaigns($user['id']);
                break;
            case 'sends':
                $this->getSends($user['id']);
                break;
            case 'analytics':
                $this->getAnalytics($user['id']);
                break;
            case 'domains':
                $this->getDomains($user['id']);
                break;
            case 'preview':
                $this->previewTemplate($user['id']);
                break;
            default:
                http_response_code(404);
                echo json_encode(['error' => 'Endpoint no encontrado']);
        }
    }
    
    private function handlePost($pathParts, $user) {
        $action = $pathParts[3] ?? '';
        $data = json_decode(file_get_contents('php://input'), true);
        
        switch ($action) {
            case 'config':
                $this->createConfiguration($user['id'], $data);
                break;
            case 'send':
                $this->sendEmail($user['id'], $data);
                break;
            case 'send-bulk':
                $this->sendBulkEmails($user['id'], $data);
                break;
            case 'templates':
                $this->createTemplate($user['id'], $data);
                break;
            case 'lists':
                $this->createList($user['id'], $data);
                break;
            case 'subscribers':
                $this->addSubscriber($user['id'], $data);
                break;
            case 'campaigns':
                $this->createCampaign($user['id'], $data);
                break;
            case 'start-campaign':
                $this->startCampaign($user['id'], $data);
                break;
            case 'test-send':
                $this->sendTestEmail($user['id'], $data);
                break;
            case 'verify-domain':
                $this->verifyDomain($user['id'], $data);
                break;
            case 'import-subscribers':
                $this->importSubscribers($user['id'], $data);
                break;
            default:
                http_response_code(404);
                echo json_encode(['error' => 'Endpoint no encontrado']);
        }
    }
    
    private function handlePut($pathParts, $user) {
        $action = $pathParts[3] ?? '';
        $id = $pathParts[4] ?? null;
        $data = json_decode(file_get_contents('php://input'), true);
        
        switch ($action) {
            case 'config':
                $this->updateConfiguration($user['id'], $id, $data);
                break;
            case 'templates':
                $this->updateTemplate($user['id'], $id, $data);
                break;
            case 'lists':
                $this->updateList($user['id'], $id, $data);
                break;
            case 'subscribers':
                $this->updateSubscriber($user['id'], $id, $data);
                break;
            case 'campaigns':
                $this->updateCampaign($user['id'], $id, $data);
                break;
            default:
                http_response_code(404);
                echo json_encode(['error' => 'Endpoint no encontrado']);
        }
    }
    
    // Gestión de configuración
    private function getConfiguration($userId) {
        $query = "SELECT * FROM email_config WHERE user_id = ? ORDER BY created_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$userId]);
        $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Ocultar credenciales sensibles
        foreach ($configs as &$config) {
            if ($config['api_key']) {
                $config['api_key'] = substr($config['api_key'], 0, 10) . '...';
            }
            if ($config['api_secret']) {
                $config['api_secret'] = '***hidden***';
            }
            if ($config['smtp_password']) {
                $config['smtp_password'] = '***hidden***';
            }
        }
        
        echo json_encode([
            'success' => true,
            'configurations' => $configs,
            'providers' => $this->providers
        ]);
    }
    
    private function createConfiguration($userId, $data) {
        $validation = $this->validator->validate($data, [
            'provider' => 'required|in:sendgrid,mailgun,ses,smtp',
            'name' => 'required|string|min:1|max:100',
            'from_email' => 'required|email',
            'from_name' => 'required|string|min:1|max:200'
        ]);
        
        if (!$validation['valid']) {
            http_response_code(400);
            echo json_encode(['error' => 'Datos inválidos', 'details' => $validation['errors']]);
            return;
        }
        
        try {
            // Validar configuración específica del proveedor
            $providerValidation = $this->validateProviderConfig($data);
            if (!$providerValidation['valid']) {
                http_response_code(400);
                echo json_encode(['error' => 'Configuración inválida', 'details' => $providerValidation['message']]);
                return;
            }
            
            $query = "INSERT INTO email_config (
                user_id, provider, name, api_key, api_secret, domain,
                smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption,
                from_email, from_name, reply_to_email, bounce_email,
                webhook_url, webhook_secret, daily_limit, monthly_limit
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                $userId,
                $data['provider'],
                $data['name'],
                $data['api_key'] ?? null,
                $data['api_secret'] ?? null,
                $data['domain'] ?? null,
                $data['smtp_host'] ?? null,
                $data['smtp_port'] ?? 587,
                $data['smtp_username'] ?? null,
                $data['smtp_password'] ?? null,
                $data['smtp_encryption'] ?? 'tls',
                $data['from_email'],
                $data['from_name'],
                $data['reply_to_email'] ?? $data['from_email'],
                $data['bounce_email'] ?? null,
                $data['webhook_url'] ?? null,
                $data['webhook_secret'] ?? null,
                $data['daily_limit'] ?? 1000,
                $data['monthly_limit'] ?? 10000
            ]);
            
            $configId = $this->conn->lastInsertId();
            
            // Crear dominio automáticamente si se especifica
            if (!empty($data['domain'])) {
                $this->createDomainRecord($userId, $configId, $data['domain']);
            }
            
            $this->logger->info("Email configuration created", [
                'user_id' => $userId,
                'config_id' => $configId,
                'provider' => $data['provider']
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Configuración creada exitosamente',
                'config_id' => $configId
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error creating email config: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error al crear configuración']);
        }
    }
    
    // Envío de emails
    private function sendEmail($userId, $data) {
        $validation = $this->validator->validate($data, [
            'config_id' => 'required|integer',
            'to' => 'required|email',
            'subject' => 'required|string|min:1|max:500',
            'html_content' => 'required|string',
            'text_content' => 'string'
        ]);
        
        if (!$validation['valid']) {
            http_response_code(400);
            echo json_encode(['error' => 'Datos inválidos', 'details' => $validation['errors']]);
            return;
        }
        
        try {
            // Obtener configuración
            $config = $this->getConfigById($userId, $data['config_id']);
            if (!$config) {
                http_response_code(404);
                echo json_encode(['error' => 'Configuración no encontrada']);
                return;
            }
            
            // Verificar límites diarios
            if (!$this->checkDailyLimit($config['id'])) {
                http_response_code(429);
                echo json_encode(['error' => 'Límite diario de emails excedido']);
                return;
            }
            
            // Crear registro en base de datos
            $sendId = $this->createSendRecord($userId, $data);
            
            // Procesar contenido (tracking, variables, etc.)
            $processedContent = $this->processEmailContent($data, $sendId);
            
            // Enviar email según el proveedor
            $result = $this->sendEmailByProvider($config, $data['to'], $data['subject'], $processedContent);
            
            if ($result['success']) {
                // Actualizar registro con ID del proveedor
                $this->updateSendStatus($sendId, 'sent', $result['message_id']);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Email enviado exitosamente',
                    'send_id' => $sendId,
                    'message_id' => $result['message_id']
                ]);
            } else {
                $this->updateSendStatus($sendId, 'bounced', null, $result['error']);
                
                http_response_code(400);
                echo json_encode([
                    'error' => 'Error al enviar email',
                    'details' => $result['error']
                ]);
            }
            
        } catch (Exception $e) {
            $this->logger->error("Error sending email: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno al enviar email']);
        }
    }

// seguir aqui
private function sendBulkEmails($userId, $data) {
        $validation = $this->validator->validate($data, [
            'config_id' => 'required|integer',
            'recipients' => 'required|array|min:1|max:1000',
            'subject' => 'required|string|min:1|max:500',
            'html_content' => 'required|string',
            'template_id' => 'integer'
        ]);
        
        if (!$validation['valid']) {
            http_response_code(400);
            echo json_encode(['error' => 'Datos inválidos', 'details' => $validation['errors']]);
            return;
        }
        
        try {
            $config = $this->getConfigById($userId, $data['config_id']);
            if (!$config) {
                http_response_code(404);
                echo json_encode(['error' => 'Configuración no encontrada']);
                return;
            }
            
            $results = [
                'total' => count($data['recipients']),
                'sent' => 0,
                'failed' => 0,
                'details' => []
            ];
            
            foreach ($data['recipients'] as $recipient) {
                // Verificar límite diario
                if (!$this->checkDailyLimit($config['id'])) {
                    $results['failed']++;
                    $results['details'][] = [
                        'email' => $recipient['email'],
                        'status' => 'failed',
                        'error' => 'Daily limit exceeded'
                    ];
                    continue;
                }
                
                // Personalizar contenido para cada destinatario
                $personalizedData = $this->personalizeContent($data, $recipient);
                
                $sendId = $this->createSendRecord($userId, [
                    'config_id' => $data['config_id'],
                    'to' => $recipient['email'],
                    'subject' => $personalizedData['subject'],
                    'template_id' => $data['template_id'] ?? null
                ]);
                
                $processedContent = $this->processEmailContent($personalizedData, $sendId);
                
                $result = $this->sendEmailByProvider(
                    $config, 
                    $recipient['email'], 
                    $personalizedData['subject'], 
                    $processedContent
                );
                
                if ($result['success']) {
                    $this->updateSendStatus($sendId, 'sent', $result['message_id']);
                    $results['sent']++;
                    $results['details'][] = [
                        'email' => $recipient['email'],
                        'status' => 'sent',
                        'send_id' => $sendId
                    ];
                } else {
                    $this->updateSendStatus($sendId, 'bounced', null, $result['error']);
                    $results['failed']++;
                    $results['details'][] = [
                        'email' => $recipient['email'],
                        'status' => 'failed',
                        'error' => $result['error']
                    ];
                }
                
                // Rate limiting entre emails
                usleep(50000); // 0.05 segundos
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Envío masivo completado',
                'results' => $results
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error in bulk send: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error en envío masivo']);
        }
    }
    
    // Gestión de templates
    private function getTemplates($userId) {
        $configId = $_GET['config_id'] ?? null;
        $category = $_GET['category'] ?? null;
        
        $query = "SELECT t.*, c.name as config_name 
                  FROM email_templates t 
                  JOIN email_config c ON t.config_id = c.id 
                  WHERE t.user_id = ? AND t.is_active = true";
        $params = [$userId];
        
        if ($configId) {
            $query .= " AND t.config_id = ?";
            $params[] = $configId;
        }
        
        if ($category) {
            $query .= " AND t.category = ?";
            $params[] = $category;
        }
        
        $query .= " ORDER BY t.created_at DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'templates' => $templates
        ]);
    }
    
    private function createTemplate($userId, $data) {
        $validation = $this->validator->validate($data, [
            'config_id' => 'required|integer',
            'name' => 'required|string|min:1|max:200',
            'subject' => 'required|string|min:1|max:500',
            'html_content' => 'required|string',
            'text_content' => 'string',
            'category' => 'string|max:100'
        ]);
        
        if (!$validation['valid']) {
            http_response_code(400);
            echo json_encode(['error' => 'Datos inválidos', 'details' => $validation['errors']]);
            return;
        }
        
        try {
            $config = $this->getConfigById($userId, $data['config_id']);
            if (!$config) {
                http_response_code(404);
                echo json_encode(['error' => 'Configuración no encontrada']);
                return;
            }
            
            // Extraer variables del template
            $variables = $this->extractTemplateVariables($data['html_content'], $data['subject']);
            
            // Generar imagen de preview
            $previewUrl = $this->generateTemplatePreview($data['html_content']);
            
            $query = "INSERT INTO email_templates (
                user_id, config_id, name, subject, html_content, text_content,
                template_variables, category, preview_image_url
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                $userId,
                $data['config_id'],
                $data['name'],
                $data['subject'],
                $data['html_content'],
                $data['text_content'] ?? strip_tags($data['html_content']),
                json_encode($variables),
                $data['category'] ?? 'general',
                $previewUrl
            ]);
            
            $templateId = $this->conn->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'message' => 'Template creado exitosamente',
                'template_id' => $templateId,
                'variables' => $variables
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error creating template: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error al crear template']);
        }
    }
    
    // Gestión de listas y suscriptores
    private function getLists($userId) {
        $query = "SELECT l.*, 
                  (SELECT COUNT(*) FROM email_subscribers s WHERE s.list_id = l.id AND s.status = 'active') as active_count,
                  (SELECT COUNT(*) FROM email_subscribers s WHERE s.list_id = l.id) as total_count
                  FROM email_lists l 
                  WHERE l.user_id = ? 
                  ORDER BY l.created_at DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$userId]);
        $lists = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'lists' => $lists
        ]);
    }
    
    private function createList($userId, $data) {
        $validation = $this->validator->validate($data, [
            'name' => 'required|string|min:1|max:200',
            'description' => 'string'
        ]);
        
        if (!$validation['valid']) {
            http_response_code(400);
            echo json_encode(['error' => 'Datos inválidos', 'details' => $validation['errors']]);
            return;
        }
        
        try {
            $query = "INSERT INTO email_lists (user_id, name, description, tags) 
                      VALUES (?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                $userId,
                $data['name'],
                $data['description'] ?? null,
                json_encode($data['tags'] ?? [])
            ]);
            
            $listId = $this->conn->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'message' => 'Lista creada exitosamente',
                'list_id' => $listId
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error creating list: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error al crear lista']);
        }
    }
    
    private function addSubscriber($userId, $data) {
        $validation = $this->validator->validate($data, [
            'list_id' => 'required|integer',
            'email' => 'required|email',
            'first_name' => 'string|max:100',
            'last_name' => 'string|max:100'
        ]);
        
        if (!$validation['valid']) {
            http_response_code(400);
            echo json_encode(['error' => 'Datos inválidos', 'details' => $validation['errors']]);
            return;
        }
        
        try {
            // Verificar que la lista pertenece al usuario
            $listQuery = "SELECT id FROM email_lists WHERE id = ? AND user_id = ?";
            $listStmt = $this->conn->prepare($listQuery);
            $listStmt->execute([$data['list_id'], $userId]);
            
            if (!$listStmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Lista no encontrada']);
                return;
            }
            
            // Verificar si el suscriptor ya existe
            $checkQuery = "SELECT id, status FROM email_subscribers WHERE list_id = ? AND email = ?";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->execute([$data['list_id'], $data['email']]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                if ($existing['status'] === 'active') {
                    http_response_code(409);
                    echo json_encode(['error' => 'El suscriptor ya existe y está activo']);
                    return;
                } else {
                    // Reactivar suscriptor
                    $updateQuery = "UPDATE email_subscribers SET 
                                    status = 'active', first_name = ?, last_name = ?,
                                    custom_fields = ?, source = ?, updated_at = NOW()
                                    WHERE id = ?";
                    $updateStmt = $this->conn->prepare($updateQuery);
                    $updateStmt->execute([
                        $data['first_name'] ?? null,
                        $data['last_name'] ?? null,
                        json_encode($data['custom_fields'] ?? []),
                        $data['source'] ?? 'manual',
                        $existing['id']
                    ]);
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Suscriptor reactivado exitosamente',
                        'subscriber_id' => $existing['id']
                    ]);
                    return;
                }
            }
            
            // Crear nuevo suscriptor
            $query = "INSERT INTO email_subscribers (
                user_id, list_id, email, first_name, last_name, custom_fields, source
            ) VALUES (?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                $userId,
                $data['list_id'],
                $data['email'],
                $data['first_name'] ?? null,
                $data['last_name'] ?? null,
                json_encode($data['custom_fields'] ?? []),
                $data['source'] ?? 'manual'
            ]);
            
            $subscriberId = $this->conn->lastInsertId();
            
            // Actualizar contador de la lista
            $this->updateListCounts($data['list_id']);
            
            echo json_encode([
                'success' => true,
                'message' => 'Suscriptor añadido exitosamente',
                'subscriber_id' => $subscriberId
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error adding subscriber: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error al añadir suscriptor']);
        }
    }
    
    // Gestión de campañas
    private function createCampaign($userId, $data) {
        $validation = $this->validator->validate($data, [
            'config_id' => 'required|integer',
            'list_id' => 'required|integer',
            'name' => 'required|string|min:1|max:200',
            'subject' => 'required|string|min:1|max:500',
            'html_content' => 'required|string',
            'template_id' => 'integer'
        ]);
        
        if (!$validation['valid']) {
            http_response_code(400);
            echo json_encode(['error' => 'Datos inválidos', 'details' => $validation['errors']]);
            return;
        }
        
        try {
            $config = $this->getConfigById($userId, $data['config_id']);
            if (!$config) {
                http_response_code(404);
                echo json_encode(['error' => 'Configuración no encontrada']);
                return;
            }
            
            // Verificar que la lista existe
            $listQuery = "SELECT id, total_subscribers FROM email_lists WHERE id = ? AND user_id = ?";
            $listStmt = $this->conn->prepare($listQuery);
            $listStmt->execute([$data['list_id'], $userId]);
            $list = $listStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$list) {
                http_response_code(404);
                echo json_encode(['error' => 'Lista no encontrada']);
                return;
            }
            
            // Contar destinatarios aplicando filtros de segmentación
            $recipientCount = $this->countCampaignRecipients($data['list_id'], $data['segment_conditions'] ?? []);
            
            $query = "INSERT INTO email_campaigns (
                user_id, config_id, template_id, list_id, name, subject, preheader,
                html_content, text_content, scheduled_at, total_recipients,
                is_ab_test, ab_test_percentage, ab_winner_criteria, ab_test_duration_hours,
                segment_conditions, send_rate_limit
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                $userId,
                $data['config_id'],
                $data['template_id'] ?? null,
                $data['list_id'],
                $data['name'],
                $data['subject'],
                $data['preheader'] ?? null,
                $data['html_content'],
                $data['text_content'] ?? strip_tags($data['html_content']),
                $data['scheduled_at'] ?? null,
                $recipientCount,
                $data['is_ab_test'] ?? false,
                $data['ab_test_percentage'] ?? 10,
                $data['ab_winner_criteria'] ?? 'open_rate',
                $data['ab_test_duration_hours'] ?? 4,
                json_encode($data['segment_conditions'] ?? []),
                $data['send_rate_limit'] ?? 100
            ]);
            
            $campaignId = $this->conn->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'message' => 'Campaña creada exitosamente',
                'campaign_id' => $campaignId,
                'estimated_recipients' => $recipientCount
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error creating campaign: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error al crear campaña']);
        }
    }
    
    private function startCampaign($userId, $data) {
        $validation = $this->validator->validate($data, [
            'campaign_id' => 'required|integer'
        ]);
        
        if (!$validation['valid']) {
            http_response_code(400);
            echo json_encode(['error' => 'Datos inválidos', 'details' => $validation['errors']]);
            return;
        }
        
        try {
            // Obtener campaña
            $query = "SELECT c.*, conf.*, l.name as list_name 
                      FROM email_campaigns c 
                      JOIN email_config conf ON c.config_id = conf.id 
                      JOIN email_lists l ON c.list_id = l.id 
                      WHERE c.id = ? AND c.user_id = ? AND c.status = 'draft'";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$data['campaign_id'], $userId]);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$campaign) {
                http_response_code(404);
                echo json_encode(['error' => 'Campaña no encontrada o ya iniciada']);
                return;
            }
            
            // Verificar límites
            if (!$this->checkCampaignLimits($campaign)) {
                http_response_code(429);
                echo json_encode(['error' => 'Límites de envío excedidos']);
                return;
            }
            
            // Actualizar estado a 'sending'
            $updateQuery = "UPDATE email_campaigns SET status = 'sending', started_at = NOW() WHERE id = ?";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->execute([$data['campaign_id']]);
            
            // Procesar campaña
            if ($campaign['is_ab_test']) {
                $this->processABTestCampaign($campaign);
            } else {
                $this->processRegularCampaign($campaign);
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Campaña iniciada exitosamente',
                'campaign_id' => $data['campaign_id']
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error starting campaign: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error al iniciar campaña']);
        }
    }
    
    private function processRegularCampaign($campaign) {
        // Obtener suscriptores de la lista aplicando segmentación
        $subscribers = $this->getCampaignRecipients($campaign['list_id'], 
                                                   json_decode($campaign['segment_conditions'], true) ?? []);
        
        $sentCount = 0;
        $failedCount = 0;
        $rateLimit = $campaign['send_rate_limit'];
        $delayBetweenEmails = 3600 / $rateLimit; // segundos entre emails
        
        foreach ($subscribers as $subscriber) {
            try {
                // Personalizar contenido
                $personalizedContent = $this->personalizeEmailContent($campaign, $subscriber);
                
                // Crear registro de envío
                $sendId = $this->createSendRecord($campaign['user_id'], [
                    'config_id' => $campaign['config_id'],
                    'campaign_id' => $campaign['id'],
                    'template_id' => $campaign['template_id'],
                    'subscriber_id' => $subscriber['id'],
                    'to' => $subscriber['email'],
                    'subject' => $personalizedContent['subject']
                ]);
                
                // Procesar contenido con tracking
                $processedContent = $this->processEmailContent($personalizedContent, $sendId);
                
                // Enviar email
                $result = $this->sendEmailByProvider(
                    $campaign, 
                    $subscriber['email'], 
                    $personalizedContent['subject'], 
                    $processedContent
                );
                
                if ($result['success']) {
                    $this->updateSendStatus($sendId, 'sent', $result['message_id']);
                    $sentCount++;
                } else {
                    $this->updateSendStatus($sendId, 'bounced', null, $result['error']);
                    $failedCount++;
                }
                
                // Rate limiting
                usleep($delayBetweenEmails * 1000000);
                
            } catch (Exception $e) {
                $this->logger->error("Error in campaign send: " . $e->getMessage());
                $failedCount++;
            }
        }
        
        // Actualizar estadísticas de la campaña
        $updateQuery = "UPDATE email_campaigns SET 
                        sent_count = ?, status = 'sent', completed_at = NOW()
                        WHERE id = ?";
        $updateStmt = $this->conn->prepare($updateQuery);
        $updateStmt->execute([$sentCount, $campaign['id']]);
        
        $this->logger->info("Campaign completed", [
            'campaign_id' => $campaign['id'],
            'sent' => $sentCount,
            'failed' => $failedCount
        ]);
    }
    
    private function processABTestCampaign($campaign) {
        // Implementar lógica de A/B testing
        $subscribers = $this->getCampaignRecipients($campaign['list_id'], 
                                                   json_decode($campaign['segment_conditions'], true) ?? []);
        
        $totalSubscribers = count($subscribers);
        $testGroupSize = intval($totalSubscribers * ($campaign['ab_test_percentage'] / 100));
        
        // Dividir en grupos A y B
        shuffle($subscribers);
        $groupA = array_slice($subscribers, 0, $testGroupSize / 2);
        $groupB = array_slice($subscribers, $testGroupSize / 2, $testGroupSize / 2);
        $remaining = array_slice($subscribers, $testGroupSize);
        
        // Enviar a grupos de prueba
        $this->sendToTestGroup($campaign, $groupA, 'A');
        $this->sendToTestGroup($campaign, $groupB, 'B');
        
        // Programar evaluación de ganador después del tiempo especificado
        $this->scheduleWinnerEvaluation($campaign['id'], $remaining, $campaign['ab_test_duration_hours']);
    }

// seguir aqui
// Analytics y reportes
    private function getAnalytics($userId) {
        $configId = $_GET['config_id'] ?? null;
        $campaignId = $_GET['campaign_id'] ?? null;
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateTo = $_GET['date_to'] ?? date('Y-m-d');
        
        try {
            // Estadísticas generales
            $statsQuery = "SELECT 
                COUNT(*) as total_emails,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_emails,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_emails,
                SUM(CASE WHEN status = 'opened' THEN 1 ELSE 0 END) as opened_emails,
                SUM(CASE WHEN status = 'clicked' THEN 1 ELSE 0 END) as clicked_emails,
                SUM(CASE WHEN status = 'bounced' THEN 1 ELSE 0 END) as bounced_emails,
                SUM(CASE WHEN status = 'unsubscribed' THEN 1 ELSE 0 END) as unsubscribed_emails,
                SUM(CASE WHEN status = 'spam' THEN 1 ELSE 0 END) as spam_emails,
                SUM(cost) as total_cost
                FROM email_sends 
                WHERE user_id = ? AND created_at BETWEEN ? AND ?";
            
            $params = [$userId, $dateFrom, $dateTo];
            
            if ($configId) {
                $statsQuery .= " AND config_id = ?";
                $params[] = $configId;
            }
            
            if ($campaignId) {
                $statsQuery .= " AND campaign_id = ?";
                $params[] = $campaignId;
            }
            
            $statsStmt = $this->conn->prepare($statsQuery);
            $statsStmt->execute($params);
            $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
            
            // Calcular tasas
            $deliveryRate = $stats['sent_emails'] > 0 ? 
                round(($stats['delivered_emails'] / $stats['sent_emails']) * 100, 2) : 0;
            $openRate = $stats['delivered_emails'] > 0 ? 
                round(($stats['opened_emails'] / $stats['delivered_emails']) * 100, 2) : 0;
            $clickRate = $stats['delivered_emails'] > 0 ? 
                round(($stats['clicked_emails'] / $stats['delivered_emails']) * 100, 2) : 0;
            $bounceRate = $stats['sent_emails'] > 0 ? 
                round(($stats['bounced_emails'] / $stats['sent_emails']) * 100, 2) : 0;
            $unsubscribeRate = $stats['delivered_emails'] > 0 ? 
                round(($stats['unsubscribed_emails'] / $stats['delivered_emails']) * 100, 2) : 0;
            
            // Estadísticas por día
            $dailyQuery = "SELECT 
                DATE(created_at) as date,
                COUNT(*) as emails,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = 'opened' THEN 1 ELSE 0 END) as opened,
                SUM(CASE WHEN status = 'clicked' THEN 1 ELSE 0 END) as clicked
                FROM email_sends 
                WHERE user_id = ? AND created_at BETWEEN ? AND ?";
            
            $dailyParams = [$userId, $dateFrom, $dateTo];
            
            if ($configId) {
                $dailyQuery .= " AND config_id = ?";
                $dailyParams[] = $configId;
            }
            
            $dailyQuery .= " GROUP BY DATE(created_at) ORDER BY date";
            
            $dailyStmt = $this->conn->prepare($dailyQuery);
            $dailyStmt->execute($dailyParams);
            $dailyStats = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Top campañas
            $campaignsQuery = "SELECT 
                c.id, c.name, c.status, c.total_recipients, c.sent_count,
                c.delivered_count, c.opened_count, c.clicked_count,
                c.created_at, c.started_at, c.completed_at,
                CASE WHEN c.delivered_count > 0 THEN 
                    ROUND((c.opened_count / c.delivered_count) * 100, 2) 
                ELSE 0 END as open_rate,
                CASE WHEN c.delivered_count > 0 THEN 
                    ROUND((c.clicked_count / c.delivered_count) * 100, 2) 
                ELSE 0 END as click_rate
                FROM email_campaigns c 
                WHERE c.user_id = ?";
            
            $campaignsParams = [$userId];
            
            if ($configId) {
                $campaignsQuery .= " AND c.config_id = ?";
                $campaignsParams[] = $configId;
            }
            
            $campaignsQuery .= " ORDER BY c.created_at DESC LIMIT 10";
            
            $campaignsStmt = $this->conn->prepare($campaignsQuery);
            $campaignsStmt->execute($campaignsParams);
            $topCampaigns = $campaignsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Dispositivos y clientes de email más usados
            $devicesQuery = "SELECT 
                SUBSTRING_INDEX(SUBSTRING_INDEX(user_agent, '(', 2), ')', 1) as device_info,
                COUNT(*) as count
                FROM email_events 
                WHERE event_type = 'opened' AND user_agent IS NOT NULL
                AND send_id IN (SELECT id FROM email_sends WHERE user_id = ?)
                GROUP BY device_info 
                ORDER BY count DESC LIMIT 10";
            
            $devicesStmt = $this->conn->prepare($devicesQuery);
            $devicesStmt->execute([$userId]);
            $deviceStats = $devicesStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Ubicaciones geográficas
            $locationQuery = "SELECT 
                location_country, location_city, COUNT(*) as count
                FROM email_events 
                WHERE event_type = 'opened' AND location_country IS NOT NULL
                AND send_id IN (SELECT id FROM email_sends WHERE user_id = ?)
                GROUP BY location_country, location_city 
                ORDER BY count DESC LIMIT 20";
            
            $locationStmt = $this->conn->prepare($locationQuery);
            $locationStmt->execute([$userId]);
            $locationStats = $locationStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'analytics' => [
                    'overview' => [
                        'total_emails' => intval($stats['total_emails']),
                        'sent_emails' => intval($stats['sent_emails']),
                        'delivered_emails' => intval($stats['delivered_emails']),
                        'opened_emails' => intval($stats['opened_emails']),
                        'clicked_emails' => intval($stats['clicked_emails']),
                        'bounced_emails' => intval($stats['bounced_emails']),
                        'unsubscribed_emails' => intval($stats['unsubscribed_emails']),
                        'spam_emails' => intval($stats['spam_emails']),
                        'total_cost' => floatval($stats['total_cost'])
                    ],
                    'rates' => [
                        'delivery_rate' => $deliveryRate,
                        'open_rate' => $openRate,
                        'click_rate' => $clickRate,
                        'bounce_rate' => $bounceRate,
                        'unsubscribe_rate' => $unsubscribeRate
                    ],
                    'daily_stats' => $dailyStats,
                    'top_campaigns' => $topCampaigns,
                    'device_stats' => $deviceStats,
                    'location_stats' => $locationStats
                ]
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error getting analytics: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error obteniendo analytics']);
        }
    }
    
    // Tracking de emails
    private function trackEmailOpen($sendId) {
        if (!$sendId) {
            // Devolver pixel transparente
            header('Content-Type: image/gif');
            echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
            return;
        }
        
        try {
            // Buscar envío
            $query = "SELECT id, status FROM email_sends WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$sendId]);
            $send = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($send) {
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
                $ipAddress = $this->getRealIpAddress();
                $location = $this->getLocationFromIP($ipAddress);
                
                // Registrar evento de apertura
                $eventQuery = "INSERT INTO email_events (
                    send_id, event_type, user_agent, ip_address, 
                    location_country, location_city
                ) VALUES (?, 'opened', ?, ?, ?, ?)";
                
                $eventStmt = $this->conn->prepare($eventQuery);
                $eventStmt->execute([
                    $sendId, $userAgent, $ipAddress,
                    $location['country'] ?? null,
                    $location['city'] ?? null
                ]);
                
                // Actualizar estado del envío si es la primera apertura
                if ($send['status'] !== 'opened') {
                    $updateQuery = "UPDATE email_sends SET 
                        status = 'opened', 
                        opened_at = NOW(),
                        first_opened_at = CASE WHEN first_opened_at IS NULL THEN NOW() ELSE first_opened_at END,
                        user_agent = ?, ip_address = ?,
                        location_country = ?, location_city = ?
                        WHERE id = ?";
                    
                    $updateStmt = $this->conn->prepare($updateQuery);
                    $updateStmt->execute([
                        $userAgent, $ipAddress,
                        $location['country'] ?? null,
                        $location['city'] ?? null,
                        $sendId
                    ]);
                    
                    // Actualizar contador de campaña si aplica
                    $this->updateCampaignStats($sendId, 'opened');
                }
            }
            
        } catch (Exception $e) {
            $this->logger->error("Error tracking email open: " . $e->getMessage());
        }
        
        // Devolver pixel transparente
        header('Content-Type: image/gif');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }
    
    private function trackEmailClick($sendId) {
        $url = $_GET['url'] ?? null;
        
        if (!$sendId || !$url) {
            http_response_code(400);
            echo "Invalid tracking parameters";
            return;
        }
        
        try {
            // Buscar envío
            $query = "SELECT id, status FROM email_sends WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$sendId]);
            $send = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($send) {
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
                $ipAddress = $this->getRealIpAddress();
                $location = $this->getLocationFromIP($ipAddress);
                
                // Registrar evento de click
                $eventQuery = "INSERT INTO email_events (
                    send_id, event_type, clicked_url, user_agent, ip_address,
                    location_country, location_city
                ) VALUES (?, 'clicked', ?, ?, ?, ?, ?)";
                
                $eventStmt = $this->conn->prepare($eventQuery);
                $eventStmt->execute([
                    $sendId, $url, $userAgent, $ipAddress,
                    $location['country'] ?? null,
                    $location['city'] ?? null
                ]);
                
                // Actualizar estado del envío si es el primer click
                if (!in_array($send['status'], ['clicked'])) {
                    $updateQuery = "UPDATE email_sends SET 
                        status = 'clicked',
                        clicked_at = NOW(),
                        first_clicked_at = CASE WHEN first_clicked_at IS NULL THEN NOW() ELSE first_clicked_at END
                        WHERE id = ?";
                    
                    $updateStmt = $this->conn->prepare($updateQuery);
                    $updateStmt->execute([$sendId]);
                    
                    // Actualizar contador de campaña si aplica
                    $this->updateCampaignStats($sendId, 'clicked');
                }
            }
            
            // Redirigir al URL original
            header("Location: " . urldecode($url));
            
        } catch (Exception $e) {
            $this->logger->error("Error tracking email click: " . $e->getMessage());
            http_response_code(500);
            echo "Error tracking click";
        }
    }
    
    // Manejo de desuscripciones
    private function handleUnsubscribe($token) {
        if (!$token) {
            http_response_code(400);
            echo "Token de desuscripción inválido";
            return;
        }
        
        try {
            // Decodificar token (debería contener send_id o subscriber_id)
            $data = json_decode(base64_decode($token), true);
            
            if (!$data || !isset($data['email'])) {
                http_response_code(400);
                echo "Token de desuscripción inválido";
                return;
            }
            
            // Marcar como desuscrito en todas las listas del usuario
            if (isset($data['send_id'])) {
                $sendQuery = "SELECT user_id, subscriber_id FROM email_sends WHERE id = ?";
                $sendStmt = $this->conn->prepare($sendQuery);
                $sendStmt->execute([$data['send_id']]);
                $sendInfo = $sendStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($sendInfo && $sendInfo['subscriber_id']) {
                    $updateQuery = "UPDATE email_subscribers SET 
                        status = 'unsubscribed', unsubscribed_at = NOW() 
                        WHERE id = ?";
                    $updateStmt = $this->conn->prepare($updateQuery);
                    $updateStmt->execute([$sendInfo['subscriber_id']]);
                    
                    // Registrar evento de desuscripción
                    $eventQuery = "INSERT INTO email_events (send_id, event_type) VALUES (?, 'unsubscribed')";
                    $eventStmt = $this->conn->prepare($eventQuery);
                    $eventStmt->execute([$data['send_id']]);
                    
                    // Actualizar estado del envío
                    $updateSendQuery = "UPDATE email_sends SET status = 'unsubscribed', unsubscribed_at = NOW() WHERE id = ?";
                    $updateSendStmt = $this->conn->prepare($updateSendQuery);
                    $updateSendStmt->execute([$data['send_id']]);
                }
            }
            
            // Mostrar página de confirmación
            echo $this->getUnsubscribeConfirmationPage($data['email']);
            
        } catch (Exception $e) {
            $this->logger->error("Error handling unsubscribe: " . $e->getMessage());
            http_response_code(500);
            echo "Error procesando desuscripción";
        }
    }
    
    // Gestión de dominios y autenticación
    private function getDomains($userId) {
        $query = "SELECT d.*, c.name as config_name 
                  FROM email_domains d 
                  JOIN email_config c ON d.config_id = c.id 
                  WHERE d.user_id = ? 
                  ORDER BY d.created_at DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$userId]);
        $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'domains' => $domains
        ]);
    }
    
    private function verifyDomain($userId, $data) {
        $validation = $this->validator->validate($data, [
            'config_id' => 'required|integer',
            'domain' => 'required|string'
        ]);
        
        if (!$validation['valid']) {
            http_response_code(400);
            echo json_encode(['error' => 'Datos inválidos', 'details' => $validation['errors']]);
            return;
        }
        
        try {
            $config = $this->getConfigById($userId, $data['config_id']);
            if (!$config) {
                http_response_code(404);
                echo json_encode(['error' => 'Configuración no encontrada']);
                return;
            }
            
            // Verificar registros DNS
            $verification = $this->verifyDomainDNS($data['domain']);
            
            // Crear o actualizar registro de dominio
            $domainQuery = "INSERT INTO email_domains (
                user_id, config_id, domain, spf_status, dkim_status, 
                dmarc_status, mx_status, last_checked_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                spf_status = VALUES(spf_status),
                dkim_status = VALUES(dkim_status),
                dmarc_status = VALUES(dmarc_status),
                mx_status = VALUES(mx_status),
                last_checked_at = NOW()";
            
            $domainStmt = $this->conn->prepare($domainQuery);
            $domainStmt->execute([
                $userId,
                $data['config_id'],
                $data['domain'],
                $verification['spf'],
                $verification['dkim'],
                $verification['dmarc'],
                $verification['mx']
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Verificación de dominio completada',
                'verification' => $verification
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error verifying domain: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error verificando dominio']);
        }
    }
    
    // Funciones auxiliares
    private function validateProviderConfig($data) {
        switch ($data['provider']) {
            case 'sendgrid':
                if (empty($data['api_key'])) {
                    return ['valid' => false, 'message' => 'API Key de SendGrid requerida'];
                }
                return $this->validateSendGridConfig($data);
                
            case 'mailgun':
                if (empty($data['api_key']) || empty($data['domain'])) {
                    return ['valid' => false, 'message' => 'API Key y dominio de Mailgun requeridos'];
                }
                return $this->validateMailgunConfig($data);
                
            case 'ses':
                if (empty($data['api_key']) || empty($data['api_secret'])) {
                    return ['valid' => false, 'message' => 'Access Key y Secret Key de AWS SES requeridos'];
                }
                return $this->validateSESConfig($data);
                
            case 'smtp':
                if (empty($data['smtp_host']) || empty($data['smtp_username']) || empty($data['smtp_password'])) {
                    return ['valid' => false, 'message' => 'Configuración SMTP incompleta'];
                }
                return $this->validateSMTPConfig($data);
                
            default:
                return ['valid' => false, 'message' => 'Proveedor no soportado'];
        }
    }
    
    private function validateSendGridConfig($data) {
        $url = "https://api.sendgrid.com/v3/user/profile";
        $headers = [
            "Authorization: Bearer {$data['api_key']}",
            "Content-Type: application/json"
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            return ['valid' => true];
        }
        
        return ['valid' => false, 'message' => 'API Key de SendGrid inválida'];
    }
    
    private function validateMailgunConfig($data) {
        $url = "https://api.mailgun.net/v3/domains/{$data['domain']}";
        $headers = [
            "Authorization: Basic " . base64_encode("api:{$data['api_key']}")
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            return ['valid' => true];
        }
        
        return ['valid' => false, 'message' => 'Credenciales de Mailgun inválidas'];
    }
    
    private function validateSESConfig($data) {
        // Validación básica para AWS SES
        if (strlen($data['api_key']) < 16 || strlen($data['api_secret']) < 32) {
            return ['valid' => false, 'message' => 'Credenciales de AWS SES inválidas'];
        }
        
        return ['valid' => true];
    }
    
    private function validateSMTPConfig($data) {
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $data['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $data['smtp_username'];
            $mail->Password = $data['smtp_password'];
            $mail->SMTPSecure = $data['smtp_encryption'] ?? 'tls';
            $mail->Port = $data['smtp_port'] ?? 587;
            $mail->Timeout = 10;
            
            // Intentar conectar
            $mail->smtpConnect();
            $mail->smtpClose();
            
            return ['valid' => true];
            
        } catch (Exception $e) {
            return ['valid' => false, 'message' => 'Error de conexión SMTP: ' . $e->getMessage()];
        }
    }
    
    private function getConfigById($userId, $configId) {
        $query = "SELECT * FROM email_config WHERE id = ? AND user_id = ? AND is_active = true";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$configId, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    private function checkDailyLimit($configId) {
        $query = "SELECT daily_limit FROM email_config WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$configId]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) return false;
        
        // Contar emails enviados hoy
        $countQuery = "SELECT COUNT(*) as count FROM email_sends 
                       WHERE config_id = ? AND status IN ('sent', 'delivered', 'opened', 'clicked') 
                       AND DATE(created_at) = CURDATE()";
        $countStmt = $this->conn->prepare($countQuery);
        $countStmt->execute([$configId]);
        $result = $countStmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['count'] < $config['daily_limit'];
    }
    
    private function createSendRecord($userId, $data) {
        $query = "INSERT INTO email_sends (
            user_id, config_id, campaign_id, template_id, subscriber_id,
            recipient_email, subject
        ) VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            $userId,
            $data['config_id'],
            $data['campaign_id'] ?? null,
            $data['template_id'] ?? null,
            $data['subscriber_id'] ?? null,
            $data['to'],
            $data['subject']
        ]);
        
        return $this->conn->lastInsertId();
    }
    
    private function updateSendStatus($sendId, $status, $messageId = null, $error = null) {
        $query = "UPDATE email_sends SET status = ?";
        $params = [$status];
        
        if ($messageId) {
            $query .= ", message_id = ?";
            $params[] = $messageId;
        }
        
        if ($error) {
            $query .= ", bounce_reason = ?";
            $params[] = $error;
        }
        
        if ($status === 'sent') {
            $query .= ", sent_at = NOW()";
        } elseif ($status === 'delivered') {
            $query .= ", delivered_at = NOW()";
        }
        
        $query .= " WHERE id = ?";
        $params[] = $sendId;
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
    }

// seguir aqui

// Envío de emails por proveedor
    private function sendEmailByProvider($config, $to, $subject, $content) {
        switch ($config['provider']) {
            case 'sendgrid':
                return $this->sendViaSendGrid($config, $to, $subject, $content);
            case 'mailgun':
                return $this->sendViaMailgun($config, $to, $subject, $content);
            case 'ses':
                return $this->sendViaSES($config, $to, $subject, $content);
            case 'smtp':
                return $this->sendViaSMTP($config, $to, $subject, $content);
            default:
                return ['success' => false, 'error' => 'Proveedor no soportado'];
        }
    }
    
    private function sendViaSendGrid($config, $to, $subject, $content) {
        $url = "https://api.sendgrid.com/v3/mail/send";
        $headers = [
            "Authorization: Bearer {$config['api_key']}",
            "Content-Type: application/json"
        ];
        
        $payload = [
            'personalizations' => [
                [
                    'to' => [['email' => $to]],
                    'subject' => $subject
                ]
            ],
            'from' => [
                'email' => $config['from_email'],
                'name' => $config['from_name']
            ],
            'content' => [
                [
                    'type' => 'text/html',
                    'value' => $content['html']
                ]
            ]
        ];
        
        if (!empty($content['text'])) {
            $payload['content'][] = [
                'type' => 'text/plain',
                'value' => $content['text']
            ];
        }
        
        if ($config['reply_to_email']) {
            $payload['reply_to'] = ['email' => $config['reply_to_email']];
        }
        
        return $this->makeHttpRequest($url, $headers, $payload, 'POST');
    }
    
    private function sendViaMailgun($config, $to, $subject, $content) {
        $url = "https://api.mailgun.net/v3/{$config['domain']}/messages";
        $headers = [
            "Authorization: Basic " . base64_encode("api:{$config['api_key']}")
        ];
        
        $postData = [
            'from' => "{$config['from_name']} <{$config['from_email']}>",
            'to' => $to,
            'subject' => $subject,
            'html' => $content['html']
        ];
        
        if (!empty($content['text'])) {
            $postData['text'] = $content['text'];
        }
        
        if ($config['reply_to_email']) {
            $postData['h:Reply-To'] = $config['reply_to_email'];
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $responseData = json_decode($response, true);
            return [
                'success' => true,
                'message_id' => $responseData['id'] ?? uniqid()
            ];
        }
        
        $errorData = json_decode($response, true);
        return [
            'success' => false,
            'error' => $errorData['message'] ?? "HTTP {$httpCode}"
        ];
    }
    
    private function sendViaSES($config, $to, $subject, $content) {
        // Implementación básica de AWS SES usando cURL
        $region = 'us-east-1'; // Configurable
        $service = 'ses';
        $host = "email.{$region}.amazonaws.com";
        
        $payload = [
            'Action' => 'SendEmail',
            'Version' => '2010-12-01',
            'Source' => "{$config['from_name']} <{$config['from_email']}>",
            'Destination.ToAddresses.member.1' => $to,
            'Message.Subject.Data' => $subject,
            'Message.Body.Html.Data' => $content['html']
        ];
        
        if (!empty($content['text'])) {
            $payload['Message.Body.Text.Data'] = $content['text'];
        }
        
        $queryString = http_build_query($payload);
        
        // AWS Signature Version 4
        $timestamp = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        
        $canonicalRequest = "POST\n/\n\nhost:{$host}\nx-amz-date:{$timestamp}\n\nhost;x-amz-date\n" . hash('sha256', $queryString);
        $stringToSign = "AWS4-HMAC-SHA256\n{$timestamp}\n{$date}/{$region}/{$service}/aws4_request\n" . hash('sha256', $canonicalRequest);
        
        $signingKey = $this->getSignatureKey($config['api_secret'], $date, $region, $service);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        
        $authorizationHeader = "AWS4-HMAC-SHA256 Credential={$config['api_key']}/{$date}/{$region}/{$service}/aws4_request,SignedHeaders=host;x-amz-date,Signature={$signature}";
        
        $headers = [
            "Host: {$host}",
            "X-Amz-Date: {$timestamp}",
            "Authorization: {$authorizationHeader}",
            "Content-Type: application/x-www-form-urlencoded"
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://{$host}/");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $queryString);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            preg_match('/<MessageId>(.*?)<\/MessageId>/', $response, $matches);
            $messageId = $matches[1] ?? uniqid();
            
            return [
                'success' => true,
                'message_id' => $messageId
            ];
        }
        
        return [
            'success' => false,
            'error' => "AWS SES error: HTTP {$httpCode}"
        ];
    }
    
    private function sendViaSMTP($config, $to, $subject, $content) {
        try {
            $mail = new PHPMailer(true);
            
            // Configuración del servidor
            $mail->isSMTP();
            $mail->Host = $config['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['smtp_username'];
            $mail->Password = $config['smtp_password'];
            $mail->SMTPSecure = $config['smtp_encryption'];
            $mail->Port = $config['smtp_port'];
            $mail->CharSet = 'UTF-8';
            
            // Remitente
            $mail->setFrom($config['from_email'], $config['from_name']);
            if ($config['reply_to_email']) {
                $mail->addReplyTo($config['reply_to_email']);
            }
            
            // Destinatario
            $mail->addAddress($to);
            
            // Contenido
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $content['html'];
            if (!empty($content['text'])) {
                $mail->AltBody = $content['text'];
            }
            
            $mail->send();
            
            return [
                'success' => true,
                'message_id' => $mail->getLastMessageID() ?? uniqid()
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => "SMTP Error: " . $e->getMessage()
            ];
        }
    }
    
    // Procesamiento de contenido
    private function processEmailContent($data, $sendId) {
        $htmlContent = $data['html_content'];
        $textContent = $data['text_content'] ?? strip_tags($htmlContent);
        
        // Añadir pixel de tracking de apertura
        $trackingPixel = $this->generateTrackingPixel($sendId);
        $htmlContent = str_replace('</body>', $trackingPixel . '</body>', $htmlContent);
        
        // Procesar enlaces para tracking de clicks
        $htmlContent = $this->processLinksForTracking($htmlContent, $sendId);
        
        // Añadir enlace de desuscripción
        $unsubscribeLink = $this->generateUnsubscribeLink($sendId, $data['to']);
        $htmlContent = str_replace('{{unsubscribe_url}}', $unsubscribeLink, $htmlContent);
        $textContent = str_replace('{{unsubscribe_url}}', $unsubscribeLink, $textContent);
        
        return [
            'html' => $htmlContent,
            'text' => $textContent
        ];
    }
    
    private function generateTrackingPixel($sendId) {
        $trackingUrl = "https://{$_SERVER['HTTP_HOST']}/api/notifications/email/track/open/{$sendId}";
        return "<img src=\"{$trackingUrl}\" width=\"1\" height=\"1\" border=\"0\" style=\"display:none;\">";
    }
    
    private function processLinksForTracking($htmlContent, $sendId) {
        // Buscar todos los enlaces href
        return preg_replace_callback('/href=["\']([^"\']+)["\']/i', function($matches) use ($sendId) {
            $originalUrl = $matches[1];
            
            // No trackear enlaces especiales
            if (strpos($originalUrl, 'mailto:') === 0 || 
                strpos($originalUrl, 'tel:') === 0 || 
                strpos($originalUrl, '#') === 0 ||
                strpos($originalUrl, 'unsubscribe') !== false) {
                return $matches[0];
            }
            
            $trackingUrl = "https://{$_SERVER['HTTP_HOST']}/api/notifications/email/track/click/{$sendId}?url=" . urlencode($originalUrl);
            return 'href="' . $trackingUrl . '"';
        }, $htmlContent);
    }
    
    private function generateUnsubscribeLink($sendId, $email) {
        $token = base64_encode(json_encode([
            'send_id' => $sendId,
            'email' => $email,
            'timestamp' => time()
        ]));
        
        return "https://{$_SERVER['HTTP_HOST']}/api/notifications/email/unsubscribe/{$token}";
    }
    
    // Manejo de webhooks
    private function handleWebhook() {
        $payload = file_get_contents('php://input');
        $headers = getallheaders();
        
        $this->logger->info("Email webhook received", ['payload' => $payload]);
        
        // Detectar proveedor por headers o URL
        $provider = $this->detectWebhookProvider($headers);
        
        try {
            switch ($provider) {
                case 'sendgrid':
                    $this->processSendGridWebhook($payload, $headers);
                    break;
                case 'mailgun':
                    $this->processMailgunWebhook($payload, $headers);
                    break;
                case 'ses':
                    $this->processSESWebhook($payload, $headers);
                    break;
                default:
                    $this->logger->warning("Unknown webhook provider");
            }
            
            echo json_encode(['success' => true]);
            
        } catch (Exception $e) {
            $this->logger->error("Error processing webhook: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Webhook processing failed']);
        }
    }
    
    private function processSendGridWebhook($payload, $headers) {
        $events = json_decode($payload, true);
        
        if (!is_array($events)) return;
        
        foreach ($events as $event) {
            $this->processEmailEvent([
                'message_id' => $event['sg_message_id'] ?? null,
                'event_type' => $event['event'],
                'timestamp' => $event['timestamp'],
                'email' => $event['email'],
                'reason' => $event['reason'] ?? null,
                'url' => $event['url'] ?? null,
                'user_agent' => $event['useragent'] ?? null,
                'ip' => $event['ip'] ?? null
            ]);
        }
    }
    
    private function processMailgunWebhook($payload, $headers) {
        parse_str($payload, $data);
        
        $this->processEmailEvent([
            'message_id' => $data['Message-Id'] ?? null,
            'event_type' => $data['event'],
            'timestamp' => $data['timestamp'],
            'email' => $data['recipient'],
            'reason' => $data['reason'] ?? null,
            'url' => $data['url'] ?? null,
            'user_agent' => $data['user-agent'] ?? null,
            'ip' => $data['ip'] ?? null
        ]);
    }
    
    private function processEmailEvent($eventData) {
        if (!$eventData['message_id']) return;
        
        // Buscar envío por message_id
        $query = "SELECT id FROM email_sends WHERE message_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$eventData['message_id']]);
        $send = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$send) return;
        
        $sendId = $send['id'];
        
        // Mapear eventos
        $statusMap = [
            'delivered' => 'delivered',
            'open' => 'opened',
            'click' => 'clicked',
            'bounce' => 'bounced',
            'dropped' => 'bounced',
            'spam' => 'spam',
            'unsubscribe' => 'unsubscribed'
        ];
        
        $status = $statusMap[$eventData['event_type']] ?? null;
        if (!$status) return;
        
        // Actualizar estado del envío
        $this->updateSendStatus($sendId, $status, null, $eventData['reason']);
        
        // Registrar evento detallado
        $eventQuery = "INSERT INTO email_events (
            send_id, event_type, event_data, user_agent, ip_address, clicked_url
        ) VALUES (?, ?, ?, ?, ?, ?)";
        
        $eventStmt = $this->conn->prepare($eventQuery);
        $eventStmt->execute([
            $sendId,
            $eventData['event_type'],
            json_encode($eventData),
            $eventData['user_agent'],
            $eventData['ip'],
            $eventData['url']
        ]);
        
        // Actualizar estadísticas de campaña
        $this->updateCampaignStats($sendId, $status);
    }
    
    // Funciones auxiliares finales
    private function personalizeContent($data, $recipient) {
        $content = $data['html_content'];
        $subject = $data['subject'];
        
        // Variables básicas
        $replacements = [
            '{{first_name}}' => $recipient['first_name'] ?? '',
            '{{last_name}}' => $recipient['last_name'] ?? '',
            '{{email}}' => $recipient['email'],
            '{{full_name}}' => trim(($recipient['first_name'] ?? '') . ' ' . ($recipient['last_name'] ?? ''))
        ];
        
        // Variables personalizadas
        if (isset($recipient['custom_fields'])) {
            $customFields = is_string($recipient['custom_fields']) ? 
                json_decode($recipient['custom_fields'], true) : $recipient['custom_fields'];
            
            if (is_array($customFields)) {
                foreach ($customFields as $key => $value) {
                    $replacements["{{custom_{$key}}}"] = $value;
                }
            }
        }
        
        return [
            'html_content' => str_replace(array_keys($replacements), array_values($replacements), $content),
            'subject' => str_replace(array_keys($replacements), array_values($replacements), $subject),
            'text_content' => $data['text_content'] ?? null
        ];
    }
    
    private function extractTemplateVariables($htmlContent, $subject) {
        preg_match_all('/\{\{([^}]+)\}\}/', $htmlContent . ' ' . $subject, $matches);
        return array_unique($matches[1]);
    }
    
    private function updateCampaignStats($sendId, $eventType) {
        $query = "SELECT campaign_id FROM email_sends WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$sendId]);
        $send = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$send || !$send['campaign_id']) return;
        
        $campaignId = $send['campaign_id'];
        
        switch ($eventType) {
            case 'delivered':
                $updateQuery = "UPDATE email_campaigns SET delivered_count = delivered_count + 1 WHERE id = ?";
                break;
            case 'opened':
                $updateQuery = "UPDATE email_campaigns SET opened_count = opened_count + 1 WHERE id = ?";
                break;
            case 'clicked':
                $updateQuery = "UPDATE email_campaigns SET clicked_count = clicked_count + 1 WHERE id = ?";
                break;
            case 'bounced':
                $updateQuery = "UPDATE email_campaigns SET bounced_count = bounced_count + 1 WHERE id = ?";
                break;
            case 'unsubscribed':
                $updateQuery = "UPDATE email_campaigns SET unsubscribed_count = unsubscribed_count + 1 WHERE id = ?";
                break;
            case 'spam':
                $updateQuery = "UPDATE email_campaigns SET spam_count = spam_count + 1 WHERE id = ?";
                break;
            default:
                return;
        }
        
        $updateStmt = $this->conn->prepare($updateQuery);
        $updateStmt->execute([$campaignId]);
    }
    
    private function getRealIpAddress() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'];
        }
    }
    
    private function getLocationFromIP($ip) {
        // Implementación básica usando servicio gratuito
        $url = "http://ip-api.com/json/{$ip}";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        if ($response) {
            $data = json_decode($response, true);
            if ($data && $data['status'] === 'success') {
                return [
                    'country' => $data['countryCode'],
                    'city' => $data['city']
                ];
            }
        }
        
        return ['country' => null, 'city' => null];
    }
    
    private function getUnsubscribeConfirmationPage($email) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <title>Desuscripción Exitosa</title>
            <style>
                body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
                .container { max-width: 600px; margin: 0 auto; }
                .success { color: #28a745; font-size: 24px; margin-bottom: 20px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='success'>✓ Desuscripción Exitosa</div>
                <p>Tu email <strong>{$email}</strong> ha sido desuscrito exitosamente.</p>
                <p>Ya no recibirás más emails de nuestras listas de correo.</p>
                <p>Si esto fue un error, puedes volver a suscribirte contactándonos.</p>
            </div>
        </body>
        </html>";
    }
    
    private function makeHttpRequest($url, $headers, $payload, $method = 'POST') {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => "cURL error: {$error}"];
        }
        
        if ($httpCode >= 200 && $httpCode < 300) {
            $responseData = json_decode($response, true);
            return [
                'success' => true,
                'message_id' => $responseData['id'] ?? $responseData['message_id'] ?? uniqid()
            ];
        }
        
        $errorData = json_decode($response, true);
        return [
            'success' => false,
            'error' => $errorData['errors'][0]['message'] ?? $errorData['message'] ?? "HTTP {$httpCode}"
        ];
    }
    
    private function detectWebhookProvider($headers) {
        foreach ($headers as $key => $value) {
            $key = strtolower($key);
            if (strpos($key, 'sendgrid') !== false) return 'sendgrid';
            if (strpos($key, 'mailgun') !== false) return 'mailgun';
            if (strpos($key, 'aws') !== false || strpos($key, 'amazon') !== false) return 'ses';
        }
        return 'unknown';
    }
    
    private function getSignatureKey($key, $dateStamp, $regionName, $serviceName) {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $key, true);
        $kRegion = hash_hmac('sha256', $regionName, $kDate, true);
        $kService = hash_hmac('sha256', $serviceName, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        return $kSigning;
    }
}

// Inicializar y procesar la solicitud
try {
    $api = new EmailAPI();
    $api->handleRequest();
} catch (Exception $e) {
    error_log("Email API Fatal Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error fatal del servidor',
        'message' => 'Por favor contacte al administrador'
    ]);
}
?>