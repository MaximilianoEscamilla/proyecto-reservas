<?php
/**
 * API de Notificaciones SMS - Sistema Empresarial Completo
 * Soporta múltiples proveedores, campañas masivas y análisis avanzado
 * 
 * Funcionalidades:
 * - Múltiples proveedores (Twilio, MessageBird, Amazon SNS, Vonage)
 * - Envío individual y masivo con rate limiting inteligente
 * - Templates de SMS con personalización avanzada
 * - Campañas programadas con segmentación
 * - Números cortos y largos con configuración automática
 * - Webhooks para estados de entrega y respuestas
 * - Analytics detallados con métricas de engagement
 * - Gestión de listas de contactos con importación
 * - Validación de números internacionales
 * - Costos por mensaje y límites de gasto
 * - Respuestas automáticas y chatbots básicos
 */

require_once '../config/database.php';
require_once '../config/jwt_helper.php';
require_once '../utils/security.php';
require_once '../utils/validation.php';
require_once '../utils/logger.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

class SMSAPI {
    private $conn;
    private $logger;
    private $security;
    private $validator;
    
    // Configuración de proveedores
    private $providers = [
        'twilio' => [
            'name' => 'Twilio',
            'api_url' => 'https://api.twilio.com/2010-04-01',
            'rate_limit' => 1000, // mensajes por segundo
            'supports_unicode' => true,
            'supports_delivery_reports' => true,
            'max_length' => 1600,
            'cost_per_sms' => 0.0075
        ],
        'messagebird' => [
            'name' => 'MessageBird',
            'api_url' => 'https://rest.messagebird.com',
            'rate_limit' => 1000,
            'supports_unicode' => true,
            'supports_delivery_reports' => true,
            'max_length' => 1377,
            'cost_per_sms' => 0.065
        ],
        'sns' => [
            'name' => 'Amazon SNS',
            'api_url' => 'https://sns.us-east-1.amazonaws.com',
            'rate_limit' => 500,
            'supports_unicode' => true,
            'supports_delivery_reports' => true,
            'max_length' => 1600,
            'cost_per_sms' => 0.0075
        ],
        'vonage' => [
            'name' => 'Vonage (Nexmo)',
            'api_url' => 'https://rest.nexmo.com',
            'rate_limit' => 1000,
            'supports_unicode' => true,
            'supports_delivery_reports' => true,
            'max_length' => 1600,
            'cost_per_sms' => 0.0072
        ]
    ];
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->logger = new Logger('sms_api');
        $this->security = new Security();
        $this->validator = new Validator();
        
        $this->initializeTables();
    }
    
    private function initializeTables() {
        // Tabla principal de configuración SMS
        $query = "CREATE TABLE IF NOT EXISTS sms_config (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            provider ENUM('twilio', 'messagebird', 'sns', 'vonage') DEFAULT 'twilio',
            name VARCHAR(100) NOT NULL,
            account_sid VARCHAR(200),
            api_key TEXT NOT NULL,
            api_secret TEXT,
            sender_id VARCHAR(20),
            webhook_url VARCHAR(500),
            webhook_secret VARCHAR(100),
            is_active BOOLEAN DEFAULT true,
            daily_limit INT DEFAULT 1000,
            monthly_limit INT DEFAULT 10000,
            cost_per_sms DECIMAL(8,6) DEFAULT 0.007500,
            balance DECIMAL(10,2) DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )";
        $this->conn->exec($query);
        
        // Tabla de templates SMS
        $query = "CREATE TABLE IF NOT EXISTS sms_templates (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            config_id INT NOT NULL,
            name VARCHAR(200) NOT NULL,
            content TEXT NOT NULL,
            variables JSON,
            category VARCHAR(100),
            is_active BOOLEAN DEFAULT true,
            usage_count INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (config_id) REFERENCES sms_config(id) ON DELETE CASCADE
        )";
        $this->conn->exec($query);
        
        // Tabla de listas de contactos SMS
        $query = "CREATE TABLE IF NOT EXISTS sms_contact_lists (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            name VARCHAR(200) NOT NULL,
            description TEXT,
            total_contacts INT DEFAULT 0,
            active_contacts INT DEFAULT 0,
            tags JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )";
        $this->conn->exec($query);
        
        // Tabla de contactos SMS
        $query = "CREATE TABLE IF NOT EXISTS sms_contacts (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            list_id INT NOT NULL,
            phone_number VARCHAR(20) NOT NULL,
            country_code VARCHAR(5),
            first_name VARCHAR(100),
            last_name VARCHAR(100),
            status ENUM('active', 'unsubscribed', 'bounced', 'invalid') DEFAULT 'active',
            custom_fields JSON,
            source VARCHAR(100),
            opt_in_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            opt_out_date TIMESTAMP NULL,
            last_activity_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (list_id) REFERENCES sms_contact_lists(id) ON DELETE CASCADE,
            UNIQUE KEY unique_contact (list_id, phone_number)
        )";
        $this->conn->exec($query);
        
        // Tabla de campañas SMS
        $query = "CREATE TABLE IF NOT EXISTS sms_campaigns (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            config_id INT NOT NULL,
            template_id INT,
            list_id INT NOT NULL,
            name VARCHAR(200) NOT NULL,
            message TEXT NOT NULL,
            status ENUM('draft', 'scheduled', 'sending', 'sent', 'paused', 'cancelled') DEFAULT 'draft',
            scheduled_at TIMESTAMP NULL,
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            total_recipients INT DEFAULT 0,
            sent_count INT DEFAULT 0,
            delivered_count INT DEFAULT 0,
            failed_count INT DEFAULT 0,
            replied_count INT DEFAULT 0,
            unsubscribed_count INT DEFAULT 0,
            segment_conditions JSON,
            send_rate_limit INT DEFAULT 10,
            estimated_cost DECIMAL(10,4) DEFAULT 0.0000,
            actual_cost DECIMAL(10,4) DEFAULT 0.0000,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (config_id) REFERENCES sms_config(id) ON DELETE CASCADE,
            FOREIGN KEY (template_id) REFERENCES sms_templates(id) ON DELETE SET NULL,
            FOREIGN KEY (list_id) REFERENCES sms_contact_lists(id) ON DELETE CASCADE
        )";
        $this->conn->exec($query);
        
        // Tabla de SMS enviados
        $query = "CREATE TABLE IF NOT EXISTS sms_sends (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            config_id INT NOT NULL,
            campaign_id INT,
            template_id INT,
            contact_id INT,
            phone_number VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            message_id VARCHAR(200),
            status ENUM('queued', 'sent', 'delivered', 'failed', 'undelivered') DEFAULT 'queued',
            error_code VARCHAR(10),
            error_message TEXT,
            parts_count INT DEFAULT 1,
            cost DECIMAL(8,6) DEFAULT 0.000000,
            sent_at TIMESTAMP NULL,
            delivered_at TIMESTAMP NULL,
            failed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (config_id) REFERENCES sms_config(id) ON DELETE CASCADE,
            FOREIGN KEY (campaign_id) REFERENCES sms_campaigns(id) ON DELETE CASCADE,
            FOREIGN KEY (template_id) REFERENCES sms_templates(id) ON DELETE SET NULL,
            FOREIGN KEY (contact_id) REFERENCES sms_contacts(id) ON DELETE SET NULL,
            INDEX idx_phone_number (phone_number),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        )";
        $this->conn->exec($query);
        
        // Tabla de SMS recibidos (respuestas)
        $query = "CREATE TABLE IF NOT EXISTS sms_received (
            id INT PRIMARY KEY AUTO_INCREMENT,
            config_id INT NOT NULL,
            phone_number VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            message_id VARCHAR(200),
            received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_processed BOOLEAN DEFAULT false,
            auto_reply_sent BOOLEAN DEFAULT false,
            original_send_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (config_id) REFERENCES sms_config(id) ON DELETE CASCADE,
            FOREIGN KEY (original_send_id) REFERENCES sms_sends(id) ON DELETE SET NULL,
            INDEX idx_phone_number (phone_number),
            INDEX idx_received_at (received_at)
        )";
        $this->conn->exec($query);
        
        // Tabla de respuestas automáticas SMS
        $query = "CREATE TABLE IF NOT EXISTS sms_auto_replies (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            config_id INT NOT NULL,
            trigger_keyword VARCHAR(100),
            trigger_type ENUM('keyword', 'any_message', 'first_message') DEFAULT 'keyword',
            response_message TEXT NOT NULL,
            is_active BOOLEAN DEFAULT true,
            usage_count INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (config_id) REFERENCES sms_config(id) ON DELETE CASCADE
        )";
        $this->conn->exec($query);
        
        // Tabla de números telefónicos
        $query = "CREATE TABLE IF NOT EXISTS sms_phone_numbers (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            config_id INT NOT NULL,
            phone_number VARCHAR(20) NOT NULL,
            country_code VARCHAR(5),
            number_type ENUM('local', 'toll_free', 'short_code') DEFAULT 'local',
            capabilities JSON,
            monthly_cost DECIMAL(8,2) DEFAULT 0.00,
            is_active BOOLEAN DEFAULT true,
            purchased_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (config_id) REFERENCES sms_config(id) ON DELETE CASCADE,
            UNIQUE KEY unique_number (config_id, phone_number)
        )";
        $this->conn->exec($query);
        
        $this->logger->info("SMS tables initialized successfully");
    }
    
    public function handleRequest() {
        try {
            $method = $_SERVER['REQUEST_METHOD'];
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $pathParts = explode('/', trim($path, '/'));
            
            // Webhook de SMS recibidos
            if ($method === 'POST' && end($pathParts) === 'webhook') {
                return $this->handleWebhook();
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
            $this->logger->error("Error in SMS API: " . $e->getMessage());
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
                $this->getContactLists($user['id']);
                break;
            case 'contacts':
                $this->getContacts($user['id']);
                break;
            case 'campaigns':
                $this->getCampaigns($user['id']);
                break;
            case 'sends':
                $this->getSends($user['id']);
                break;
            case 'received':
                $this->getReceivedMessages($user['id']);
                break;
            case 'analytics':
                $this->getAnalytics($user['id']);
                break;
            case 'phone-numbers':
                $this->getPhoneNumbers($user['id']);
                break;
            case 'validate-number':
                $this->validatePhoneNumber($user['id']);
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
                $this->sendSMS($user['id'], $data);
                break;
            case 'send-bulk':
                $this->sendBulkSMS($user['id'], $data);
                break;
            case 'templates':
                $this->createTemplate($user['id'], $data);
                break;
            case 'lists':
                $this->createContactList($user['id'], $data);
                break;
            case 'contacts':
                $this->addContact($user['id'], $data);
                break;
            case 'campaigns':
                $this->createCampaign($user['id'], $data);
                break;
            case 'start-campaign':
                $this->startCampaign($user['id'], $data);
                break;
            case 'auto-replies':
                $this->createAutoReply($user['id'], $data);
                break;
            case 'import-contacts':
                $this->importContacts($user['id'], $data);
                break;
            case 'purchase-number':
                $this->purchasePhoneNumber($user['id'], $data);
                break;
            default:
                http_response_code(404);
                echo json_encode(['error' => 'Endpoint no encontrado']);
        }
    }
    
    // Gestión de configuración
    private function getConfiguration($userId) {
        $query = "SELECT * FROM sms_config WHERE user_id = ? ORDER BY created_at DESC";
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
        }
        
        echo json_encode([
            'success' => true,
            'configurations' => $configs,
            'providers' => $this->providers
        ]);
    }
    
    private function createConfiguration($userId, $data) {
        $validation = $this->validator->validate($data, [
            'provider' => 'required|in:twilio,messagebird,sns,vonage',
            'name' => 'required|string|min:1|max:100',
            'api_key' => 'required|string',
            'sender_id' => 'string|max:20'
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
            
            $query = "INSERT INTO sms_config (
                user_id, provider, name, account_sid, api_key, api_secret,
                sender_id, webhook_url, webhook_secret, daily_limit, monthly_limit, cost_per_sms
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                $userId,
                $data['provider'],
                $data['name'],
                $data['account_sid'] ?? null,
                $data['api_key'],
                $data['api_secret'] ?? null,
                $data['sender_id'] ?? null,
                $data['webhook_url'] ?? null,
                $data['webhook_secret'] ?? null,
                $data['daily_limit'] ?? 1000,
                $data['monthly_limit'] ?? 10000,
                $data['cost_per_sms'] ?? $this->providers[$data['provider']]['cost_per_sms']
            ]);
            
            $configId = $this->conn->lastInsertId();
            
            $this->logger->info("SMS configuration created", [
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
            $this->logger->error("Error creating SMS config: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error al crear configuración']);
        }
    }
    
    // Envío de SMS
    private function sendSMS($userId, $data) {
        $validation = $this->validator->validate($data, [
            'config_id' => 'required|integer',
            'phone_number' => 'required|string',
            'message' => 'required|string|min:1|max:1600'
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
            
            // Validar y normalizar número de teléfono
            $phoneNumber = $this->normalizePhoneNumber($data['phone_number']);
            if (!$phoneNumber['valid']) {
                http_response_code(400);
                echo json_encode(['error' => 'Número de teléfono inválido', 'details' => $phoneNumber['error']]);
                return;
            }
            
            // Verificar límites diarios
            if (!$this->checkDailyLimit($config['id'])) {
                http_response_code(429);
                echo json_encode(['error' => 'Límite diario de SMS excedido']);
                return;
            }
            
            // Calcular costo y partes del mensaje
            $messageInfo = $this->analyzeMessage($data['message']);
            $estimatedCost = $messageInfo['parts'] * $config['cost_per_sms'];
            
            // Verificar balance si está configurado
            if ($config['balance'] > 0 && $config['balance'] < $estimatedCost) {
                http_response_code(402);
                echo json_encode(['error' => 'Balance insuficiente']);
                return;
            }
            
            // Crear registro en base de datos
            $sendId = $this->createSendRecord($userId, [
                'config_id' => $data['config_id'],
                'phone_number' => $phoneNumber['formatted'],
                'message' => $data['message'],
                'parts_count' => $messageInfo['parts'],
                'cost' => $estimatedCost
            ]);
            
            // Enviar SMS según el proveedor
            $result = $this->sendSMSByProvider($config, $phoneNumber['formatted'], $data['message']);
            
            if ($result['success']) {
                // Actualizar registro con ID del proveedor
                $this->updateSendStatus($sendId, 'sent', $result['message_id'], $result['cost'] ?? $estimatedCost);
                
                // Descontar del balance
                if ($config['balance'] > 0) {
                    $this->updateBalance($config['id'], -($result['cost'] ?? $estimatedCost));
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => 'SMS enviado exitosamente',
                    'send_id' => $sendId,
                    'message_id' => $result['message_id'],
                    'parts' => $messageInfo['parts'],
                    'cost' => $result['cost'] ?? $estimatedCost
                ]);
            } else {
                $this->updateSendStatus($sendId, 'failed', null, 0, $result['error']);
                
                http_response_code(400);
                echo json_encode([
                    'error' => 'Error al enviar SMS',
                    'details' => $result['error']
                ]);
            }
            
        } catch (Exception $e) {
            $this->logger->error("Error sending SMS: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno al enviar SMS']);
        }
    }

// seguir aqui

private function sendBulkSMS($userId, $data) {
        $validation = $this->validator->validate($data, [
            'config_id' => 'required|integer',
            'phone_numbers' => 'required|array|min:1|max:1000',
            'message' => 'required|string|min:1|max:1600',
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
                'total' => count($data['phone_numbers']),
                'sent' => 0,
                'failed' => 0,
                'total_cost' => 0,
                'details' => []
            ];
            
            foreach ($data['phone_numbers'] as $phoneData) {
                $phoneNumber = is_array($phoneData) ? $phoneData['phone_number'] : $phoneData;
                
                // Validar número
                $normalizedPhone = $this->normalizePhoneNumber($phoneNumber);
                if (!$normalizedPhone['valid']) {
                    $results['failed']++;
                    $results['details'][] = [
                        'phone_number' => $phoneNumber,
                        'status' => 'failed',
                        'error' => 'Número inválido: ' . $normalizedPhone['error']
                    ];
                    continue;
                }
                
                // Verificar límite diario
                if (!$this->checkDailyLimit($config['id'])) {
                    $results['failed']++;
                    $results['details'][] = [
                        'phone_number' => $phoneNumber,
                        'status' => 'failed',
                        'error' => 'Daily limit exceeded'
                    ];
                    continue;
                }
                
                // Personalizar mensaje si hay datos adicionales
                $personalizedMessage = $this->personalizeMessage($data['message'], $phoneData);
                
                $messageInfo = $this->analyzeMessage($personalizedMessage);
                $estimatedCost = $messageInfo['parts'] * $config['cost_per_sms'];
                
                // Verificar balance
                if ($config['balance'] > 0 && $config['balance'] < $estimatedCost) {
                    $results['failed']++;
                    $results['details'][] = [
                        'phone_number' => $phoneNumber,
                        'status' => 'failed',
                        'error' => 'Insufficient balance'
                    ];
                    continue;
                }
                
                $sendId = $this->createSendRecord($userId, [
                    'config_id' => $data['config_id'],
                    'phone_number' => $normalizedPhone['formatted'],
                    'message' => $personalizedMessage,
                    'template_id' => $data['template_id'] ?? null,
                    'parts_count' => $messageInfo['parts'],
                    'cost' => $estimatedCost
                ]);
                
                $result = $this->sendSMSByProvider($config, $normalizedPhone['formatted'], $personalizedMessage);
                
                if ($result['success']) {
                    $actualCost = $result['cost'] ?? $estimatedCost;
                    $this->updateSendStatus($sendId, 'sent', $result['message_id'], $actualCost);
                    
                    if ($config['balance'] > 0) {
                        $this->updateBalance($config['id'], -$actualCost);
                    }
                    
                    $results['sent']++;
                    $results['total_cost'] += $actualCost;
                    $results['details'][] = [
                        'phone_number' => $phoneNumber,
                        'status' => 'sent',
                        'send_id' => $sendId,
                        'cost' => $actualCost
                    ];
                } else {
                    $this->updateSendStatus($sendId, 'failed', null, 0, $result['error']);
                    $results['failed']++;
                    $results['details'][] = [
                        'phone_number' => $phoneNumber,
                        'status' => 'failed',
                        'error' => $result['error']
                    ];
                }
                
                // Rate limiting entre mensajes
                usleep(100000); // 0.1 segundos
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Envío masivo completado',
                'results' => $results
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error in bulk SMS send: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error en envío masivo']);
        }
    }
    
    // Gestión de templates
    private function getTemplates($userId) {
        $configId = $_GET['config_id'] ?? null;
        $category = $_GET['category'] ?? null;
        
        $query = "SELECT t.*, c.name as config_name 
                  FROM sms_templates t 
                  JOIN sms_config c ON t.config_id = c.id 
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
        
        $query .= " ORDER BY t.usage_count DESC, t.created_at DESC";
        
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
            'content' => 'required|string|min:1|max:1600',
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
            $variables = $this->extractTemplateVariables($data['content']);
            
            $query = "INSERT INTO sms_templates (
                user_id, config_id, name, content, variables, category
            ) VALUES (?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                $userId,
                $data['config_id'],
                $data['name'],
                $data['content'],
                json_encode($variables),
                $data['category'] ?? 'general'
            ]);
            
            $templateId = $this->conn->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'message' => 'Template creado exitosamente',
                'template_id' => $templateId,
                'variables' => $variables
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error creating SMS template: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error al crear template']);
        }
    }
    
    // Gestión de listas de contactos
    private function getContactLists($userId) {
        $query = "SELECT l.*, 
                  (SELECT COUNT(*) FROM sms_contacts c WHERE c.list_id = l.id AND c.status = 'active') as active_count,
                  (SELECT COUNT(*) FROM sms_contacts c WHERE c.list_id = l.id) as total_count
                  FROM sms_contact_lists l 
                  WHERE l.user_id = ? 
                  ORDER BY l.created_at DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$userId]);
        $lists = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'contact_lists' => $lists
        ]);
    }
    
    private function createContactList($userId, $data) {
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
            $query = "INSERT INTO sms_contact_lists (user_id, name, description, tags) 
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
                'message' => 'Lista de contactos creada exitosamente',
                'list_id' => $listId
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error creating contact list: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error al crear lista']);
        }
    }
    
    private function addContact($userId, $data) {
        $validation = $this->validator->validate($data, [
            'list_id' => 'required|integer',
            'phone_number' => 'required|string',
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
            $listQuery = "SELECT id FROM sms_contact_lists WHERE id = ? AND user_id = ?";
            $listStmt = $this->conn->prepare($listQuery);
            $listStmt->execute([$data['list_id'], $userId]);
            
            if (!$listStmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Lista no encontrada']);
                return;
            }
            
            // Validar y normalizar número
            $phoneNumber = $this->normalizePhoneNumber($data['phone_number']);
            if (!$phoneNumber['valid']) {
                http_response_code(400);
                echo json_encode(['error' => 'Número de teléfono inválido', 'details' => $phoneNumber['error']]);
                return;
            }
            
            // Verificar si el contacto ya existe
            $checkQuery = "SELECT id, status FROM sms_contacts WHERE list_id = ? AND phone_number = ?";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->execute([$data['list_id'], $phoneNumber['formatted']]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                if ($existing['status'] === 'active') {
                    http_response_code(409);
                    echo json_encode(['error' => 'El contacto ya existe y está activo']);
                    return;
                } else {
                    // Reactivar contacto
                    $updateQuery = "UPDATE sms_contacts SET 
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
                        'message' => 'Contacto reactivado exitosamente',
                        'contact_id' => $existing['id']
                    ]);
                    return;
                }
            }
            
            // Crear nuevo contacto
            $query = "INSERT INTO sms_contacts (
                user_id, list_id, phone_number, country_code, first_name, last_name, custom_fields, source
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                $userId,
                $data['list_id'],
                $phoneNumber['formatted'],
                $phoneNumber['country_code'],
                $data['first_name'] ?? null,
                $data['last_name'] ?? null,
                json_encode($data['custom_fields'] ?? []),
                $data['source'] ?? 'manual'
            ]);
            
            $contactId = $this->conn->lastInsertId();
            
            // Actualizar contador de la lista
            $this->updateListCounts($data['list_id']);
            
            echo json_encode([
                'success' => true,
                'message' => 'Contacto añadido exitosamente',
                'contact_id' => $contactId
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error adding contact: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error al añadir contacto']);
        }
    }
    
    // Gestión de campañas
    private function createCampaign($userId, $data) {
        $validation = $this->validator->validate($data, [
            'config_id' => 'required|integer',
            'list_id' => 'required|integer',
            'name' => 'required|string|min:1|max:200',
            'message' => 'required|string|min:1|max:1600',
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
            $listQuery = "SELECT id, total_contacts FROM sms_contact_lists WHERE id = ? AND user_id = ?";
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
            
            // Calcular costo estimado
            $messageInfo = $this->analyzeMessage($data['message']);
            $estimatedCost = $recipientCount * $messageInfo['parts'] * $config['cost_per_sms'];
            
            $query = "INSERT INTO sms_campaigns (
                user_id, config_id, template_id, list_id, name, message,
                scheduled_at, total_recipients, segment_conditions, send_rate_limit, estimated_cost
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                $userId,
                $data['config_id'],
                $data['template_id'] ?? null,
                $data['list_id'],
                $data['name'],
                $data['message'],
                $data['scheduled_at'] ?? null,
                $recipientCount,
                json_encode($data['segment_conditions'] ?? []),
                $data['send_rate_limit'] ?? 10,
                $estimatedCost
            ]);
            
            $campaignId = $this->conn->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'message' => 'Campaña creada exitosamente',
                'campaign_id' => $campaignId,
                'estimated_recipients' => $recipientCount,
                'estimated_cost' => $estimatedCost
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error creating SMS campaign: " . $e->getMessage());
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
                      FROM sms_campaigns c 
                      JOIN sms_config conf ON c.config_id = conf.id 
                      JOIN sms_contact_lists l ON c.list_id = l.id 
                      WHERE c.id = ? AND c.user_id = ? AND c.status = 'draft'";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$data['campaign_id'], $userId]);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$campaign) {
                http_response_code(404);
                echo json_encode(['error' => 'Campaña no encontrada o ya iniciada']);
                return;
            }
            
            // Verificar balance
            if ($campaign['balance'] > 0 && $campaign['balance'] < $campaign['estimated_cost']) {
                http_response_code(402);
                echo json_encode(['error' => 'Balance insuficiente para la campaña']);
                return;
            }
            
            // Actualizar estado a 'sending'
            $updateQuery = "UPDATE sms_campaigns SET status = 'sending', started_at = NOW() WHERE id = ?";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->execute([$data['campaign_id']]);
            
            // Procesar campaña en background
            $this->processSMSCampaign($campaign);
            
            echo json_encode([
                'success' => true,
                'message' => 'Campaña iniciada exitosamente',
                'campaign_id' => $data['campaign_id']
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error starting SMS campaign: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error al iniciar campaña']);
        }
    }
    
    private function processSMSCampaign($campaign) {
        // Obtener contactos de la lista aplicando segmentación
        $contacts = $this->getCampaignRecipients($campaign['list_id'], 
                                               json_decode($campaign['segment_conditions'], true) ?? []);
        
        $sentCount = 0;
        $failedCount = 0;
        $totalCost = 0;
        $rateLimit = $campaign['send_rate_limit'];
        $delayBetweenSMS = 60 / $rateLimit; // segundos entre SMS
        
        foreach ($contacts as $contact) {
            try {
                // Verificar límite diario
                if (!$this->checkDailyLimit($campaign['config_id'])) {
                    sleep(60); // Esperar hasta el próximo día
                    continue;
                }
                
                // Personalizar mensaje
                $personalizedMessage = $this->personalizeMessage($campaign['message'], $contact);
                
                // Crear registro de envío
                $messageInfo = $this->analyzeMessage($personalizedMessage);
                $estimatedCost = $messageInfo['parts'] * $campaign['cost_per_sms'];
                
                $sendId = $this->createSendRecord($campaign['user_id'], [
                    'config_id' => $campaign['config_id'],
                    'campaign_id' => $campaign['id'],
                    'template_id' => $campaign['template_id'],
                    'contact_id' => $contact['id'],
                    'phone_number' => $contact['phone_number'],
                    'message' => $personalizedMessage,
                    'parts_count' => $messageInfo['parts'],
                    'cost' => $estimatedCost
                ]);
                
                // Enviar SMS
                $result = $this->sendSMSByProvider($campaign, $contact['phone_number'], $personalizedMessage);
                
                if ($result['success']) {
                    $actualCost = $result['cost'] ?? $estimatedCost;
                    $this->updateSendStatus($sendId, 'sent', $result['message_id'], $actualCost);
                    
                    if ($campaign['balance'] > 0) {
                        $this->updateBalance($campaign['config_id'], -$actualCost);
                    }
                    
                    $sentCount++;
                    $totalCost += $actualCost;
                } else {
                    $this->updateSendStatus($sendId, 'failed', null, 0, $result['error']);
                    $failedCount++;
                }
                
                // Rate limiting
                sleep($delayBetweenSMS);
                
            } catch (Exception $e) {
                $this->logger->error("Error in SMS campaign send: " . $e->getMessage());
                $failedCount++;
            }
        }
        
        // Actualizar estadísticas de la campaña
        $updateQuery = "UPDATE sms_campaigns SET 
                        sent_count = ?, failed_count = ?, actual_cost = ?, 
                        status = 'sent', completed_at = NOW()
                        WHERE id = ?";
        $updateStmt = $this->conn->prepare($updateQuery);
        $updateStmt->execute([$sentCount, $failedCount, $totalCost, $campaign['id']]);
        
        $this->logger->info("SMS Campaign completed", [
            'campaign_id' => $campaign['id'],
            'sent' => $sentCount,
            'failed' => $failedCount,
            'cost' => $totalCost
        ]);
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
                COUNT(*) as total_sms,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_sms,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_sms,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_sms,
                SUM(CASE WHEN status = 'undelivered' THEN 1 ELSE 0 END) as undelivered_sms,
                SUM(parts_count) as total_parts,
                SUM(cost) as total_cost,
                AVG(cost) as avg_cost_per_sms
                FROM sms_sends 
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
            $deliveryRate = $stats['sent_sms'] > 0 ? 
                round(($stats['delivered_sms'] / $stats['sent_sms']) * 100, 2) : 0;
            $failureRate = $stats['total_sms'] > 0 ? 
                round(($stats['failed_sms'] / $stats['total_sms']) * 100, 2) : 0;
            
            // Estadísticas por día
            $dailyQuery = "SELECT 
                DATE(created_at) as date,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(cost) as daily_cost
                FROM sms_sends 
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
            
            // Top países por entregas
            $countryQuery = "SELECT 
                country_code, COUNT(*) as count,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(cost) as total_cost
                FROM sms_sends s
                LEFT JOIN sms_contacts c ON s.contact_id = c.id
                WHERE s.user_id = ? AND s.created_at BETWEEN ? AND ?";
            
            $countryParams = [$userId, $dateFrom, $dateTo];
            
            if ($configId) {
                $countryQuery .= " AND s.config_id = ?";
                $countryParams[] = $configId;
            }
            
            $countryQuery .= " GROUP BY country_code ORDER BY count DESC LIMIT 10";
            
            $countryStmt = $this->conn->prepare($countryQuery);
            $countryStmt->execute($countryParams);
            $countryStats = $countryStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Campañas más exitosas
            $campaignsQuery = "SELECT 
                c.id, c.name, c.status, c.total_recipients, c.sent_count,
                c.delivered_count, c.failed_count, c.actual_cost,
                c.created_at, c.started_at, c.completed_at,
                CASE WHEN c.sent_count > 0 THEN 
                    ROUND((c.delivered_count / c.sent_count) * 100, 2) 
                ELSE 0 END as delivery_rate
                FROM sms_campaigns c 
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
            
            // Códigos de error más comunes
            $errorQuery = "SELECT 
                error_code, error_message, COUNT(*) as count
                FROM sms_sends 
                WHERE user_id = ? AND status = 'failed' 
                AND created_at BETWEEN ? AND ?
                AND error_code IS NOT NULL";
            
            $errorParams = [$userId, $dateFrom, $dateTo];
            
            if ($configId) {
                $errorQuery .= " AND config_id = ?";
                $errorParams[] = $configId;
            }
            
            $errorQuery .= " GROUP BY error_code, error_message ORDER BY count DESC LIMIT 10";
            
            $errorStmt = $this->conn->prepare($errorQuery);
            $errorStmt->execute($errorParams);
            $errorStats = $errorStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'analytics' => [
                    'overview' => [
                        'total_sms' => intval($stats['total_sms']),
                        'sent_sms' => intval($stats['sent_sms']),
                        'delivered_sms' => intval($stats['delivered_sms']),
                        'failed_sms' => intval($stats['failed_sms']),
                        'undelivered_sms' => intval($stats['undelivered_sms']),
                        'total_parts' => intval($stats['total_parts']),
                        'total_cost' => floatval($stats['total_cost']),
                        'avg_cost_per_sms' => floatval($stats['avg_cost_per_sms'])
                    ],
                    'rates' => [
                        'delivery_rate' => $deliveryRate,
                        'failure_rate' => $failureRate
                    ],
                    'daily_stats' => $dailyStats,
                    'country_stats' => $countryStats,
                    'top_campaigns' => $topCampaigns,
                    'error_stats' => $errorStats
                ]
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error getting SMS analytics: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error obteniendo analytics']);
        }
    }
    
    // Validación de números telefónicos
    private function validatePhoneNumber($userId) {
        $phoneNumber = $_GET['phone_number'] ?? null;
        
        if (!$phoneNumber) {
            http_response_code(400);
            echo json_encode(['error' => 'Número de teléfono requerido']);
            return;
        }
        
        try {
            $validation = $this->normalizePhoneNumber($phoneNumber);
            
            if ($validation['valid']) {
                // Información adicional del número
                $numberInfo = $this->getPhoneNumberInfo($validation['formatted']);
                
                echo json_encode([
                    'success' => true,
                    'valid' => true,
                    'formatted' => $validation['formatted'],
                    'country_code' => $validation['country_code'],
                    'national_format' => $validation['national_format'],
                    'international_format' => $validation['international_format'],
                    'number_type' => $numberInfo['type'],
                    'carrier' => $numberInfo['carrier'],
                    'country_name' => $numberInfo['country_name']
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'valid' => false,
                    'error' => $validation['error']
                ]);
            }
            
        } catch (Exception $e) {
            $this->logger->error("Error validating phone number: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error validando número']);
        }
    }
    
    // Manejo de webhooks
    private function handleWebhook() {
        $payload = file_get_contents('php://input');
        $headers = getallheaders();
        
        $this->logger->info("SMS webhook received", ['payload' => $payload]);
        
        // Detectar proveedor por headers o URL
        $provider = $this->detectWebhookProvider($headers, $_GET);
        
        try {
            switch ($provider) {
                case 'twilio':
                    $this->processTwilioWebhook($payload, $headers);
                    break;
                case 'messagebird':
                    $this->processMessageBirdWebhook($payload, $headers);
                    break;
                case 'sns':
                    $this->processSNSWebhook($payload, $headers);
                    break;
                case 'vonage':
                    $this->processVonageWebhook($payload, $headers);
                    break;
                default:
                    $this->logger->warning("Unknown SMS webhook provider");
            }
            
            echo json_encode(['success' => true]);
            
        } catch (Exception $e) {
            $this->logger->error("Error processing SMS webhook: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Webhook processing failed']);
        }
    }
    
    private function processTwilioWebhook($payload, $headers) {
        parse_str($payload, $data);
        
        // Verificar si es un mensaje recibido o estado de entrega
        if (isset($data['Body']) && isset($data['From'])) {
            // Mensaje recibido
            $this->processReceivedSMS([
                'phone_number' => $data['From'],
                'message' => $data['Body'],
                'message_id' => $data['MessageSid'] ?? null,
                'provider' => 'twilio'
            ]);
        } elseif (isset($data['MessageStatus']) && isset($data['MessageSid'])) {
            // Estado de entrega
            $this->processDeliveryStatus([
                'message_id' => $data['MessageSid'],
                'status' => $data['MessageStatus'],
                'error_code' => $data['ErrorCode'] ?? null,
                'error_message' => $data['ErrorMessage'] ?? null
            ]);
        }
    }
    
    private function processMessageBirdWebhook($payload, $headers) {
        $data = json_decode($payload, true);
        
        if (isset($data['type'])) {
            switch ($data['type']) {
                case 'message.delivered':
                case 'message.failed':
                    $this->processDeliveryStatus([
                        'message_id' => $data['message']['id'],
                        'status' => $data['type'] === 'message.delivered' ? 'delivered' : 'failed',
                        'error_code' => $data['message']['errors'][0]['code'] ?? null,
                        'error_message' => $data['message']['errors'][0]['description'] ?? null
                    ]);
                    break;
                    
                case 'message.received':
                    $this->processReceivedSMS([
                        'phone_number' => $data['message']['originator'],
                        'message' => $data['message']['body'],
                        'message_id' => $data['message']['id'],
                        'provider' => 'messagebird'
                    ]);
                    break;
            }
        }
    }
    
    private function processReceivedSMS($data) {
        try {
            // Buscar configuración por número o proveedor
            $configQuery = "SELECT * FROM sms_config WHERE provider = ? AND is_active = true LIMIT 1";
            $configStmt = $this->conn->prepare($configQuery);
            $configStmt->execute([$data['provider']]);
            $config = $configStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$config) {
                $this->logger->warning("No active config found for provider: " . $data['provider']);
                return;
            }
            
            // Verificar si ya existe el mensaje
            $checkQuery = "SELECT id FROM sms_received WHERE message_id = ? AND config_id = ?";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->execute([$data['message_id'], $config['id']]);
            
            if ($checkStmt->fetch()) {
                return; // Mensaje ya procesado
            }
            
            // Buscar envío original si es una respuesta
            $originalSendId = $this->findOriginalSend($config['id'], $data['phone_number']);
            
            // Guardar mensaje recibido
            $insertQuery = "INSERT INTO sms_received (
                config_id, phone_number, message, message_id, original_send_id
            ) VALUES (?, ?, ?, ?, ?)";
            
            $insertStmt = $this->conn->prepare($insertQuery);
            $insertStmt->execute([
                $config['id'],
                $data['phone_number'],
                $data['message'],
                $data['message_id'],
                $originalSendId
            ]);
            
            $receivedId = $this->conn->lastInsertId();
            
            // Actualizar contador de respuestas en campaña si aplica
            if ($originalSendId) {
                $this->updateCampaignReplyCount($originalSendId);
            }
            
            // Procesar respuestas automáticas
            $this->processAutoReplies($config, $data['phone_number'], $data['message']);
            
            $this->logger->info("SMS received processed", [
                'config_id' => $config['id'],
                'phone_number' => $data['phone_number'],
                'received_id' => $receivedId
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error processing received SMS: " . $e->getMessage());
        }
    }
    
    private function processDeliveryStatus($data) {
        try {
            // Buscar envío por message_id
            $query = "SELECT id, status FROM sms_sends WHERE message_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$data['message_id']]);
            $send = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$send) {
                $this->logger->warning("Send not found for message_id: " . $data['message_id']);
                return;
            }
            
            // Mapear estados
            $statusMap = [
                'delivered' => 'delivered',
                'failed' => 'failed',
                'undelivered' => 'undelivered',
                'sent' => 'sent'
            ];
            
            $newStatus = $statusMap[$data['status']] ?? $data['status'];
            
            // Actualizar estado
            $updateQuery = "UPDATE sms_sends SET status = ?";
            $params = [$newStatus];
            
            if ($newStatus === 'delivered') {
                $updateQuery .= ", delivered_at = NOW()";
            } elseif ($newStatus === 'failed') {
                $updateQuery .= ", failed_at = NOW(), error_code = ?, error_message = ?";
                $params[] = $data['error_code'];
                $params[] = $data['error_message'];
            }
            
            $updateQuery .= " WHERE id = ?";
            $params[] = $send['id'];
            
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->execute($params);
            
            // Actualizar estadísticas de campaña
            $this->updateCampaignDeliveryStats($send['id'], $newStatus);
            
            $this->logger->info("SMS delivery status updated", [
                'send_id' => $send['id'],
                'old_status' => $send['status'],
                'new_status' => $newStatus
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error processing delivery status: " . $e->getMessage());
        }
    }
    
    // Funciones auxiliares
    private function normalizePhoneNumber($phoneNumber) {
        // Limpiar número
        $cleaned = preg_replace('/[^0-9+]/', '', $phoneNumber);
        
        // Validaciones básicas
        if (empty($cleaned)) {
            return ['valid' => false, 'error' => 'Número vacío'];
        }
        
        if (strlen($cleaned) < 7) {
            return ['valid' => false, 'error' => 'Número muy corto'];
        }
        
        if (strlen($cleaned) > 15) {
            return ['valid' => false, 'error' => 'Número muy largo'];
        }
        
        // Agregar + si no lo tiene y parece internacional
        if (!str_starts_with($cleaned, '+') && strlen($cleaned) > 10) {
            $cleaned = '+' . $cleaned;
        }
        
        // Detectar código de país
        $countryCode = $this->detectCountryCode($cleaned);
        
        return [
            'valid' => true,
            'formatted' => $cleaned,
            'country_code' => $countryCode,
            'national_format' => $this->formatNational($cleaned, $countryCode),
            'international_format' => $cleaned
        ];
    }
    
    private function detectCountryCode($phoneNumber) {
        $number = ltrim($phoneNumber, '+');
        
        // Códigos de país más comunes
        $countryCodes = [
            '1' => 'US',    // Estados Unidos/Canadá
            '34' => 'ES',   // España
            '33' => 'FR',   // Francia
            '44' => 'GB',   // Reino Unido
            '49' => 'DE',   // Alemania
            '39' => 'IT',   // Italia
            '54' => 'AR',   // Argentina
            '55' => 'BR',   // Brasil
            '52' => 'MX',   // México
            '57' => 'CO',   // Colombia
        ];
        
        foreach ($countryCodes as $code => $country) {
            if (str_starts_with($number, $code)) {
                return $country;
            }
        }
        
        return 'XX'; // Desconocido
    }
    
    private function formatNational($phoneNumber, $countryCode) {
        $number = ltrim($phoneNumber, '+');
        
        // Formato específico por país (simplificado)
        switch ($countryCode) {
            case 'US':
                if (str_starts_with($number, '1') && strlen($number) === 11) {
                    return '(' . substr($number, 1, 3) . ') ' . substr($number, 4, 3) . '-' . substr($number, 7);
                }
                break;
            case 'ES':
                if (str_starts_with($number, '34') && strlen($number) === 11) {
                    return substr($number, 2, 3) . ' ' . substr($number, 5, 3) . ' ' . substr($number, 8);
                }
                break;
        }
        
        return $phoneNumber; // Devolver formato original si no se puede formatear
    }
    
    private function getPhoneNumberInfo($phoneNumber) {
        // Información básica del número (simplificada)
        return [
            'type' => 'mobile', // mobile, landline, voip, etc.
            'carrier' => 'Unknown',
            'country_name' => $this->getCountryName($this->detectCountryCode($phoneNumber))
        ];
    }
    
    private function getCountryName($countryCode) {
        $countries = [
            'US' => 'United States',
            'ES' => 'Spain',
            'FR' => 'France',
            'GB' => 'United Kingdom',
            'DE' => 'Germany',
            'IT' => 'Italy',
            'AR' => 'Argentina',
            'BR' => 'Brazil',
            'MX' => 'Mexico',
            'CO' => 'Colombia'
        ];
        
        return $countries[$countryCode] ?? 'Unknown';
    }
    
    private function analyzeMessage($message) {
        $length = mb_strlen($message, 'UTF-8');
        
        // Verificar si contiene caracteres especiales (GSM 7-bit vs UCS-2)
        $isUnicode = !mb_check_encoding($message, 'ASCII') || 
                     preg_match('/[^\x00-\x7F]/', $message);
        
        if ($isUnicode) {
            // UCS-2: 70 caracteres por parte
            $parts = ceil($length / 70);
        } else {
            // GSM 7-bit: 160 caracteres por parte
            $parts = ceil($length / 160);
        }
        
        return [
            'length' => $length,
            'parts' => max(1, $parts),
            'encoding' => $isUnicode ? 'UCS-2' : 'GSM 7-bit'
        ];
    }
    
    private function personalizeMessage($message, $contact) {
        if (!is_array($contact)) {
            return $message;
        }
        
        $replacements = [
            '{{first_name}}' => $contact['first_name'] ?? '',
            '{{last_name}}' => $contact['last_name'] ?? '',
            '{{phone_number}}' => $contact['phone_number'] ?? '',
            '{{full_name}}' => trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''))
        ];
        
        // Variables personalizadas
        if (isset($contact['custom_fields'])) {
            $customFields = is_string($contact['custom_fields']) ? 
                json_decode($contact['custom_fields'], true) : $contact['custom_fields'];
            
            if (is_array($customFields)) {
                foreach ($customFields as $key => $value) {
                    $replacements["{{custom_{$key}}}"] = $value;
                }
            }
        }
        
        return str_replace(array_keys($replacements), array_values($replacements), $message);
    }
    
    private function extractTemplateVariables($content) {
        preg_match_all('/\{\{([^}]+)\}\}/', $content, $matches);
        return array_unique($matches[1]);
    }

// seguir aqui

// Envío de SMS por proveedor
    private function sendSMSByProvider($config, $phoneNumber, $message) {
        switch ($config['provider']) {
            case 'twilio':
                return $this->sendViaTwilio($config, $phoneNumber, $message);
            case 'messagebird':
                return $this->sendViaMessageBird($config, $phoneNumber, $message);
            case 'sns':
                return $this->sendViaSNS($config, $phoneNumber, $message);
            case 'vonage':
                return $this->sendViaVonage($config, $phoneNumber, $message);
            default:
                return ['success' => false, 'error' => 'Proveedor no soportado'];
        }
    }
    
    private function sendViaTwilio($config, $phoneNumber, $message) {
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$config['account_sid']}/Messages.json";
        
        $postData = [
            'From' => $config['sender_id'],
            'To' => $phoneNumber,
            'Body' => $message
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "{$config['account_sid']}:{$config['api_key']}");
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 201) {
            $responseData = json_decode($response, true);
            return [
                'success' => true,
                'message_id' => $responseData['sid'],
                'cost' => floatval($responseData['price'] ?? $config['cost_per_sms'])
            ];
        }
        
        $errorData = json_decode($response, true);
        return [
            'success' => false,
            'error' => $errorData['message'] ?? "HTTP {$httpCode}"
        ];
    }
    
    private function sendViaMessageBird($config, $phoneNumber, $message) {
        $url = "https://rest.messagebird.com/messages";
        $headers = [
            "Authorization: AccessKey {$config['api_key']}",
            "Content-Type: application/json"
        ];
        
        $payload = [
            'originator' => $config['sender_id'],
            'recipients' => [$phoneNumber],
            'body' => $message
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 201) {
            $responseData = json_decode($response, true);
            return [
                'success' => true,
                'message_id' => $responseData['id'],
                'cost' => floatval($responseData['price']['amount'] ?? $config['cost_per_sms'])
            ];
        }
        
        $errorData = json_decode($response, true);
        return [
            'success' => false,
            'error' => $errorData['errors'][0]['description'] ?? "HTTP {$httpCode}"
        ];
    }
    
    private function sendViaSNS($config, $phoneNumber, $message) {
        // AWS SNS implementation usando cURL
        $region = 'us-east-1';
        $service = 'sns';
        $host = "sns.{$region}.amazonaws.com";
        
        $payload = [
            'Action' => 'Publish',
            'Version' => '2010-03-31',
            'PhoneNumber' => $phoneNumber,
            'Message' => $message
        ];
        
        $queryString = http_build_query($payload);
        
        // AWS Signature Version 4
        $timestamp = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        
        $canonicalRequest = "POST\n/\n\nhost:{$host}\nx-amz-date:{$timestamp}\n\nhost;x-amz-date\n" . hash('sha256', $queryString);
        $stringToSign = "AWS4-HMAC-SHA256\n{$timestamp}\n{$date}/{$region}/{$service}/aws4_request\n" . hash('sha256', $canonicalRequest);
        
        $signingKey = $this->getAWSSignatureKey($config['api_secret'], $date, $region, $service);
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
                'message_id' => $messageId,
                'cost' => $config['cost_per_sms']
            ];
        }
        
        return [
            'success' => false,
            'error' => "AWS SNS error: HTTP {$httpCode}"
        ];
    }
    
    private function sendViaVonage($config, $phoneNumber, $message) {
        $url = "https://rest.nexmo.com/sms/json";
        
        $postData = [
            'api_key' => $config['api_key'],
            'api_secret' => $config['api_secret'],
            'from' => $config['sender_id'],
            'to' => $phoneNumber,
            'text' => $message
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $responseData = json_decode($response, true);
            
            if ($responseData['messages'][0]['status'] === '0') {
                return [
                    'success' => true,
                    'message_id' => $responseData['messages'][0]['message-id'],
                    'cost' => floatval($responseData['messages'][0]['message-price'] ?? $config['cost_per_sms'])
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $responseData['messages'][0]['error-text'] ?? 'Unknown Vonage error'
                ];
            }
        }
        
        return [
            'success' => false,
            'error' => "HTTP {$httpCode}"
        ];
    }
    
    // Respuestas automáticas
    private function processAutoReplies($config, $phoneNumber, $message) {
        try {
            // Obtener respuestas automáticas activas
            $query = "SELECT * FROM sms_auto_replies 
                      WHERE config_id = ? AND is_active = true 
                      ORDER BY trigger_type, id";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$config['id']]);
            $autoReplies = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($autoReplies as $autoReply) {
                $shouldReply = false;
                
                switch ($autoReply['trigger_type']) {
                    case 'keyword':
                        $keywords = explode(',', strtolower($autoReply['trigger_keyword']));
                        $messageLower = strtolower(trim($message));
                        
                        foreach ($keywords as $keyword) {
                            $keyword = trim($keyword);
                            if ($messageLower === $keyword || strpos($messageLower, $keyword) === 0) {
                                $shouldReply = true;
                                break;
                            }
                        }
                        break;
                        
                    case 'any_message':
                        $shouldReply = true;
                        break;
                        
                    case 'first_message':
                        // Verificar si es el primer mensaje de este número
                        $firstMsgQuery = "SELECT COUNT(*) as count FROM sms_received 
                                          WHERE config_id = ? AND phone_number = ?";
                        $firstMsgStmt = $this->conn->prepare($firstMsgQuery);
                        $firstMsgStmt->execute([$config['id'], $phoneNumber]);
                        $msgCount = $firstMsgStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($msgCount['count'] <= 1) {
                            $shouldReply = true;
                        }
                        break;
                }
                
                if ($shouldReply) {
                    $this->sendAutoReply($config, $phoneNumber, $autoReply);
                    
                    // Incrementar contador de uso
                    $updateQuery = "UPDATE sms_auto_replies SET usage_count = usage_count + 1 WHERE id = ?";
                    $updateStmt = $this->conn->prepare($updateQuery);
                    $updateStmt->execute([$autoReply['id']]);
                    
                    break; // Solo enviar una respuesta automática
                }
            }
            
        } catch (Exception $e) {
            $this->logger->error("Error processing auto replies: " . $e->getMessage());
        }
    }
    
    private function sendAutoReply($config, $phoneNumber, $autoReply) {
        try {
            // Verificar límite diario
            if (!$this->checkDailyLimit($config['id'])) {
                return;
            }
            
            $messageInfo = $this->analyzeMessage($autoReply['response_message']);
            $estimatedCost = $messageInfo['parts'] * $config['cost_per_sms'];
            
            // Verificar balance
            if ($config['balance'] > 0 && $config['balance'] < $estimatedCost) {
                return;
            }
            
            // Crear registro de envío
            $sendId = $this->createSendRecord($config['user_id'], [
                'config_id' => $config['id'],
                'phone_number' => $phoneNumber,
                'message' => $autoReply['response_message'],
                'parts_count' => $messageInfo['parts'],
                'cost' => $estimatedCost
            ]);
            
            // Enviar SMS
            $result = $this->sendSMSByProvider($config, $phoneNumber, $autoReply['response_message']);
            
            if ($result['success']) {
                $actualCost = $result['cost'] ?? $estimatedCost;
                $this->updateSendStatus($sendId, 'sent', $result['message_id'], $actualCost);
                
                if ($config['balance'] > 0) {
                    $this->updateBalance($config['id'], -$actualCost);
                }
                
                // Marcar que se envió respuesta automática
                $updateQuery = "UPDATE sms_received 
                                SET auto_reply_sent = true 
                                WHERE config_id = ? AND phone_number = ? 
                                ORDER BY received_at DESC LIMIT 1";
                $updateStmt = $this->conn->prepare($updateQuery);
                $updateStmt->execute([$config['id'], $phoneNumber]);
                
                $this->logger->info("Auto reply sent", [
                    'config_id' => $config['id'],
                    'phone_number' => $phoneNumber,
                    'auto_reply_id' => $autoReply['id']
                ]);
            }
            
        } catch (Exception $e) {
            $this->logger->error("Error sending auto reply: " . $e->getMessage());
        }
    }
    
    // Funciones auxiliares finales
    private function validateProviderConfig($data) {
        switch ($data['provider']) {
            case 'twilio':
                if (empty($data['account_sid']) || empty($data['api_key'])) {
                    return ['valid' => false, 'message' => 'Account SID y Auth Token de Twilio requeridos'];
                }
                return $this->validateTwilioConfig($data);
                
            case 'messagebird':
                if (empty($data['api_key'])) {
                    return ['valid' => false, 'message' => 'API Key de MessageBird requerida'];
                }
                return $this->validateMessageBirdConfig($data);
                
            case 'sns':
                if (empty($data['api_key']) || empty($data['api_secret'])) {
                    return ['valid' => false, 'message' => 'Access Key y Secret Key de AWS SNS requeridos'];
                }
                return ['valid' => true];
                
            case 'vonage':
                if (empty($data['api_key']) || empty($data['api_secret'])) {
                    return ['valid' => false, 'message' => 'API Key y API Secret de Vonage requeridos'];
                }
                return ['valid' => true];
                
            default:
                return ['valid' => false, 'message' => 'Proveedor no soportado'];
        }
    }
    
    private function validateTwilioConfig($data) {
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$data['account_sid']}.json";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "{$data['account_sid']}:{$data['api_key']}");
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            return ['valid' => true];
        }
        
        return ['valid' => false, 'message' => 'Credenciales de Twilio inválidas'];
    }
    
    private function validateMessageBirdConfig($data) {
        $url = "https://rest.messagebird.com/balance";
        $headers = ["Authorization: AccessKey {$data['api_key']}"];
        
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
        
        return ['valid' => false, 'message' => 'API Key de MessageBird inválida'];
    }
    
    private function getConfigById($userId, $configId) {
        $query = "SELECT * FROM sms_config WHERE id = ? AND user_id = ? AND is_active = true";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$configId, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    private function checkDailyLimit($configId) {
        $query = "SELECT daily_limit FROM sms_config WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$configId]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) return false;
        
        // Contar SMS enviados hoy
        $countQuery = "SELECT COUNT(*) as count FROM sms_sends 
                       WHERE config_id = ? AND status IN ('sent', 'delivered') 
                       AND DATE(created_at) = CURDATE()";
        $countStmt = $this->conn->prepare($countQuery);
        $countStmt->execute([$configId]);
        $result = $countStmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['count'] < $config['daily_limit'];
    }
    
    private function createSendRecord($userId, $data) {
        $query = "INSERT INTO sms_sends (
            user_id, config_id, campaign_id, template_id, contact_id,
            phone_number, message, parts_count, cost
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            $userId,
            $data['config_id'],
            $data['campaign_id'] ?? null,
            $data['template_id'] ?? null,
            $data['contact_id'] ?? null,
            $data['phone_number'],
            $data['message'],
            $data['parts_count'] ?? 1,
            $data['cost'] ?? 0
        ]);
        
        return $this->conn->lastInsertId();
    }
    
    private function updateSendStatus($sendId, $status, $messageId = null, $cost = null, $error = null) {
        $query = "UPDATE sms_sends SET status = ?";
        $params = [$status];
        
        if ($messageId) {
            $query .= ", message_id = ?";
            $params[] = $messageId;
        }
        
        if ($cost !== null) {
            $query .= ", cost = ?";
            $params[] = $cost;
        }
        
        if ($error) {
            $query .= ", error_message = ?";
            $params[] = $error;
        }
        
        if ($status === 'sent') {
            $query .= ", sent_at = NOW()";
        } elseif ($status === 'delivered') {
            $query .= ", delivered_at = NOW()";
        } elseif ($status === 'failed') {
            $query .= ", failed_at = NOW()";
        }
        
        $query .= " WHERE id = ?";
        $params[] = $sendId;
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
    }
    
    private function updateBalance($configId, $amount) {
        $query = "UPDATE sms_config SET balance = balance + ? WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$amount, $configId]);
    }
    
    private function detectWebhookProvider($headers, $getParams) {
        // Detectar por headers específicos
        foreach ($headers as $key => $value) {
            $key = strtolower($key);
            if (strpos($key, 'twilio') !== false) return 'twilio';
            if (strpos($key, 'messagebird') !== false) return 'messagebird';
            if (strpos($key, 'amazon') !== false || strpos($key, 'aws') !== false) return 'sns';
            if (strpos($key, 'vonage') !== false || strpos($key, 'nexmo') !== false) return 'vonage';
        }
        
        // Detectar por parámetros GET
        if (isset($getParams['provider'])) {
            return $getParams['provider'];
        }
        
        return 'unknown';
    }
    
    private function getAWSSignatureKey($key, $dateStamp, $regionName, $serviceName) {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $key, true);
        $kRegion = hash_hmac('sha256', $regionName, $kDate, true);
        $kService = hash_hmac('sha256', $serviceName, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        return $kSigning;
    }
    
    private function findOriginalSend($configId, $phoneNumber) {
        $query = "SELECT id FROM sms_sends 
                  WHERE config_id = ? AND phone_number = ? 
                  ORDER BY created_at DESC LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$configId, $phoneNumber]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['id'] : null;
    }
    
    private function updateCampaignReplyCount($sendId) {
        $query = "UPDATE sms_campaigns c 
                  JOIN sms_sends s ON c.id = s.campaign_id 
                  SET c.replied_count = c.replied_count + 1 
                  WHERE s.id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$sendId]);
    }
    
    private function updateCampaignDeliveryStats($sendId, $status) {
        $field = null;
        switch ($status) {
            case 'delivered':
                $field = 'delivered_count';
                break;
            case 'failed':
            case 'undelivered':
                $field = 'failed_count';
                break;
        }
        
        if ($field) {
            $query = "UPDATE sms_campaigns c 
                      JOIN sms_sends s ON c.id = s.campaign_id 
                      SET c.{$field} = c.{$field} + 1 
                      WHERE s.id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$sendId]);
        }
    }
    
    private function countCampaignRecipients($listId, $segmentConditions) {
        $query = "SELECT COUNT(*) as count FROM sms_contacts WHERE list_id = ? AND status = 'active'";
        $params = [$listId];
        
        // Aplicar condiciones de segmentación si existen
        if (!empty($segmentConditions)) {
            foreach ($segmentConditions as $condition) {
                // Implementar lógica de filtros según necesidades
                // Por ejemplo: país, custom fields, etc.
            }
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['count'];
    }
    
    private function getCampaignRecipients($listId, $segmentConditions) {
        $query = "SELECT * FROM sms_contacts WHERE list_id = ? AND status = 'active'";
        $params = [$listId];
        
        // Aplicar condiciones de segmentación si existen
        if (!empty($segmentConditions)) {
            foreach ($segmentConditions as $condition) {
                // Implementar lógica de filtros
            }
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function updateListCounts($listId) {
        $query = "UPDATE sms_contact_lists SET 
                  total_contacts = (SELECT COUNT(*) FROM sms_contacts WHERE list_id = ?),
                  active_contacts = (SELECT COUNT(*) FROM sms_contacts WHERE list_id = ? AND status = 'active')
                  WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$listId, $listId, $listId]);
    }
}

// Inicializar y procesar la solicitud
try {
    $api = new SMSAPI();
    $api->handleRequest();
} catch (Exception $e) {
    error_log("SMS API Fatal Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error fatal del servidor',
        'message' => 'Por favor contacte al administrador'
    ]);
}
?>