<?php
/**
 * API de Notificaciones WhatsApp - Sistema Empresarial Completo
 * Soporta múltiples proveedores, templates, multimedia y automatización
 * 
 * Funcionalidades:
 * - Múltiples proveedores (WhatsApp Business API, Twilio, etc.)
 * - Templates aprobados y personalizados
 * - Envío de multimedia (imágenes, documentos, audio)
 * - Campañas masivas con rate limiting
 * - Webhooks para estados de entrega
 * - Chat interactivo y respuestas automáticas
 * - Integración con CRM y sistema de tickets
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

class WhatsAppAPI {
    private $conn;
    private $logger;
    private $security;
    private $validator;
    
    // Configuración de proveedores
    private $providers = [
        'whatsapp_business' => [
            'name' => 'WhatsApp Business API',
            'base_url' => 'https://graph.facebook.com/v18.0',
            'rate_limit' => 1000, // mensajes por hora
            'supports_templates' => true,
            'supports_media' => true
        ],
        'twilio' => [
            'name' => 'Twilio WhatsApp',
            'base_url' => 'https://api.twilio.com/2010-04-01',
            'rate_limit' => 1000,
            'supports_templates' => true,
            'supports_media' => true
        ],
        'baileys' => [
            'name' => 'Baileys (Multi-device)',
            'base_url' => 'http://localhost:3000/api',
            'rate_limit' => 200,
            'supports_templates' => false,
            'supports_media' => true
        ]
    ];
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->logger = new Logger('whatsapp_api');
        $this->security = new Security();
        $this->validator = new Validator();
        
        $this->initializeTables();
    }
    
    private function initializeTables() {
        // Tabla principal de configuración WhatsApp
        $query = "CREATE TABLE IF NOT EXISTS whatsapp_config (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            provider ENUM('whatsapp_business', 'twilio', 'baileys') DEFAULT 'whatsapp_business',
            phone_number VARCHAR(20) NOT NULL,
            phone_number_id VARCHAR(100),
            access_token TEXT,
            webhook_url VARCHAR(500),
            webhook_verify_token VARCHAR(100),
            business_account_id VARCHAR(100),
            app_id VARCHAR(100),
            app_secret VARCHAR(100),
            is_active BOOLEAN DEFAULT true,
            rate_limit_per_hour INT DEFAULT 1000,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_user_phone (user_id, phone_number)
        )";
        $this->conn->exec($query);
        
        // Tabla de mensajes enviados
        $query = "CREATE TABLE IF NOT EXISTS whatsapp_messages (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            config_id INT NOT NULL,
            message_id VARCHAR(100),
            recipient VARCHAR(20) NOT NULL,
            message_type ENUM('text', 'template', 'image', 'document', 'audio', 'video', 'location', 'contact') DEFAULT 'text',
            content TEXT,
            template_name VARCHAR(100),
            template_language VARCHAR(10),
            template_parameters JSON,
            media_url VARCHAR(500),
            media_caption TEXT,
            status ENUM('pending', 'sent', 'delivered', 'read', 'failed') DEFAULT 'pending',
            error_message TEXT,
            campaign_id INT,
            conversation_id VARCHAR(100),
            reply_to_message_id VARCHAR(100),
            scheduled_at TIMESTAMP NULL,
            sent_at TIMESTAMP NULL,
            delivered_at TIMESTAMP NULL,
            read_at TIMESTAMP NULL,
            cost DECIMAL(10,4) DEFAULT 0.0000,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (config_id) REFERENCES whatsapp_config(id) ON DELETE CASCADE,
            INDEX idx_recipient (recipient),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        )";
        $this->conn->exec($query);
        
        // Tabla de templates aprobados
        $query = "CREATE TABLE IF NOT EXISTS whatsapp_templates (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            config_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            language VARCHAR(10) DEFAULT 'es',
            category ENUM('AUTHENTICATION', 'MARKETING', 'UTILITY') DEFAULT 'UTILITY',
            status ENUM('pending', 'approved', 'rejected', 'disabled') DEFAULT 'pending',
            header_type ENUM('TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT') NULL,
            header_text VARCHAR(60),
            header_media_url VARCHAR(500),
            body_text TEXT NOT NULL,
            footer_text VARCHAR(60),
            buttons JSON,
            parameters JSON,
            rejection_reason TEXT,
            external_template_id VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (config_id) REFERENCES whatsapp_config(id) ON DELETE CASCADE,
            UNIQUE KEY unique_template (config_id, name, language)
        )";
        $this->conn->exec($query);
        
        // Tabla de campañas masivas
        $query = "CREATE TABLE IF NOT EXISTS whatsapp_campaigns (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            config_id INT NOT NULL,
            name VARCHAR(200) NOT NULL,
            description TEXT,
            template_id INT,
            recipient_list JSON,
            total_recipients INT DEFAULT 0,
            sent_count INT DEFAULT 0,
            delivered_count INT DEFAULT 0,
            failed_count INT DEFAULT 0,
            status ENUM('draft', 'scheduled', 'sending', 'completed', 'paused', 'failed') DEFAULT 'draft',
            scheduled_at TIMESTAMP NULL,
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            rate_limit_per_minute INT DEFAULT 10,
            personalization_data JSON,
            total_cost DECIMAL(10,2) DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (config_id) REFERENCES whatsapp_config(id) ON DELETE CASCADE,
            FOREIGN KEY (template_id) REFERENCES whatsapp_templates(id) ON DELETE SET NULL
        )";
        $this->conn->exec($query);
        
        // Tabla de conversaciones y chats
        $query = "CREATE TABLE IF NOT EXISTS whatsapp_conversations (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            config_id INT NOT NULL,
            conversation_id VARCHAR(100) NOT NULL,
            recipient VARCHAR(20) NOT NULL,
            contact_name VARCHAR(200),
            contact_profile_url VARCHAR(500),
            status ENUM('active', 'closed', 'archived') DEFAULT 'active',
            last_message_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            assigned_to INT,
            tags JSON,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (config_id) REFERENCES whatsapp_config(id) ON DELETE CASCADE,
            FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
            UNIQUE KEY unique_conversation (config_id, recipient)
        )";
        $this->conn->exec($query);
        
        // Tabla de mensajes recibidos (webhooks)
        $query = "CREATE TABLE IF NOT EXISTS whatsapp_received_messages (
            id INT PRIMARY KEY AUTO_INCREMENT,
            config_id INT NOT NULL,
            message_id VARCHAR(100) NOT NULL,
            conversation_id VARCHAR(100),
            sender VARCHAR(20) NOT NULL,
            sender_name VARCHAR(200),
            message_type ENUM('text', 'image', 'document', 'audio', 'video', 'location', 'contact') DEFAULT 'text',
            content TEXT,
            media_url VARCHAR(500),
            media_caption TEXT,
            location_latitude DECIMAL(10, 8),
            location_longitude DECIMAL(11, 8),
            contact_data JSON,
            reply_to_message_id VARCHAR(100),
            timestamp_received TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            processed BOOLEAN DEFAULT false,
            auto_reply_sent BOOLEAN DEFAULT false,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (config_id) REFERENCES whatsapp_config(id) ON DELETE CASCADE,
            UNIQUE KEY unique_message (config_id, message_id)
        )";
        $this->conn->exec($query);
        
        // Tabla de respuestas automáticas
        $query = "CREATE TABLE IF NOT EXISTS whatsapp_auto_replies (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            config_id INT NOT NULL,
            trigger_type ENUM('keyword', 'first_message', 'business_hours', 'fallback') DEFAULT 'keyword',
            trigger_value VARCHAR(200),
            message_type ENUM('text', 'template') DEFAULT 'text',
            response_text TEXT,
            template_id INT,
            is_active BOOLEAN DEFAULT true,
            business_hours_start TIME,
            business_hours_end TIME,
            business_days JSON,
            priority INT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (config_id) REFERENCES whatsapp_config(id) ON DELETE CASCADE,
            FOREIGN KEY (template_id) REFERENCES whatsapp_templates(id) ON DELETE SET NULL
        )";
        $this->conn->exec($query);
        
        $this->logger->info("WhatsApp tables initialized successfully");
    }
    
    public function handleRequest() {
        try {
            $method = $_SERVER['REQUEST_METHOD'];
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $pathParts = explode('/', trim($path, '/'));
            
            // Webhook verification para WhatsApp Business API
            if ($method === 'GET' && isset($_GET['hub_mode']) && $_GET['hub_mode'] === 'subscribe') {
                return $this->verifyWebhook();
            }
            
            // Webhook de mensajes recibidos
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
            $this->logger->error("Error in WhatsApp API: " . $e->getMessage());
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
            case 'messages':
                $this->getMessages($user['id']);
                break;
            case 'templates':
                $this->getTemplates($user['id']);
                break;
            case 'campaigns':
                $this->getCampaigns($user['id']);
                break;
            case 'conversations':
                $this->getConversations($user['id']);
                break;
            case 'analytics':
                $this->getAnalytics($user['id']);
                break;
            case 'qr':
                $this->generateQRCode($user['id']);
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
                $this->sendMessage($user['id'], $data);
                break;
            case 'send-bulk':
                $this->sendBulkMessages($user['id'], $data);
                break;
            case 'templates':
                $this->createTemplate($user['id'], $data);
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
            case 'upload-media':
                $this->uploadMedia($user['id']);
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
            case 'campaigns':
                $this->updateCampaign($user['id'], $id, $data);
                break;
            case 'conversations':
                $this->updateConversation($user['id'], $id, $data);
                break;
            default:
                http_response_code(404);
                echo json_encode(['error' => 'Endpoint no encontrado']);
        }
    }
    
    // Gestión de configuración
    private function getConfiguration($userId) {
        $query = "SELECT * FROM whatsapp_config WHERE user_id = ? ORDER BY created_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$userId]);
        $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Ocultar tokens sensibles
        foreach ($configs as &$config) {
            if ($config['access_token']) {
                $config['access_token'] = substr($config['access_token'], 0, 10) . '...';
            }
            if ($config['app_secret']) {
                $config['app_secret'] = '***hidden***';
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
            'provider' => 'required|in:whatsapp_business,twilio,baileys',
            'phone_number' => 'required|string|min:10|max:20',
            'access_token' => 'required_if:provider,whatsapp_business,twilio',
            'phone_number_id' => 'required_if:provider,whatsapp_business'
        ]);
        
        if (!$validation['valid']) {
            http_response_code(400);
            echo json_encode(['error' => 'Datos inválidos', 'details' => $validation['errors']]);
            return;
        }
        
        try {
            // Verificar que no exista una configuración para este número
            $checkQuery = "SELECT id FROM whatsapp_config WHERE user_id = ? AND phone_number = ?";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->execute([$userId, $data['phone_number']]);
            
            if ($checkStmt->fetch()) {
                http_response_code(409);
                echo json_encode(['error' => 'Ya existe una configuración para este número']);
                return;
            }
            
            // Validar configuración con el proveedor
            $isValid = $this->validateProviderConfig($data);
            if (!$isValid['valid']) {
                http_response_code(400);
                echo json_encode(['error' => 'Configuración inválida', 'details' => $isValid['message']]);
                return;
            }
            
            $query = "INSERT INTO whatsapp_config (
                user_id, provider, phone_number, phone_number_id, access_token,
                webhook_url, webhook_verify_token, business_account_id, app_id, app_secret,
                rate_limit_per_hour
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                $userId,
                $data['provider'],
                $data['phone_number'],
                $data['phone_number_id'] ?? null,
                $data['access_token'] ?? null,
                $data['webhook_url'] ?? null,
                $data['webhook_verify_token'] ?? null,
                $data['business_account_id'] ?? null,
                $data['app_id'] ?? null,
                $data['app_secret'] ?? null,
                $data['rate_limit_per_hour'] ?? $this->providers[$data['provider']]['rate_limit']
            ]);
            
            $configId = $this->conn->lastInsertId();
            
            $this->logger->info("WhatsApp configuration created", [
                'user_id' => $userId,
                'config_id' => $configId,
                'provider' => $data['provider'],
                'phone_number' => $data['phone_number']
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Configuración creada exitosamente',
                'config_id' => $configId
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error creating WhatsApp config: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error al crear configuración']);
        }
    }
    
    // Envío de mensajes
    private function sendMessage($userId, $data) {
        $validation = $this->validator->validate($data, [
            'config_id' => 'required|integer',
            'recipient' => 'required|string|min:10|max:20',
            'message_type' => 'required|in:text,template,image,document,audio,video',
            'content' => 'required_if:message_type,text',
            'template_name' => 'required_if:message_type,template',
            'media_url' => 'required_if:message_type,image,document,audio,video'
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
            
            // Verificar rate limiting
            if (!$this->checkRateLimit($config['id'])) {
                http_response_code(429);
                echo json_encode(['error' => 'Límite de mensajes por hora excedido']);
                return;
            }
            
            // Crear registro en base de datos
            $messageId = $this->createMessageRecord($userId, $data);
            
            // Enviar mensaje según el proveedor
            $result = $this->sendMessageByProvider($config, $data);
            
            if ($result['success']) {
                // Actualizar registro con ID del proveedor
                $this->updateMessageStatus($messageId, 'sent', $result['provider_message_id']);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Mensaje enviado exitosamente',
                    'message_id' => $messageId,
                    'provider_message_id' => $result['provider_message_id']
                ]);
            } else {
                $this->updateMessageStatus($messageId, 'failed', null, $result['error']);
                
                http_response_code(400);
                echo json_encode([
                    'error' => 'Error al enviar mensaje',
                    'details' => $result['error']
                ]);
            }
            
        } catch (Exception $e) {
            $this->logger->error("Error sending WhatsApp message: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno al enviar mensaje']);
        }
    }
    
    private function sendBulkMessages($userId, $data) {
        $validation = $this->validator->validate($data, [
            'config_id' => 'required|integer',
            'recipients' => 'required|array|min:1|max:1000',
            'message_type' => 'required|in:text,template',
            'content' => 'required_if:message_type,text',
            'template_name' => 'required_if:message_type,template'
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
                // Rate limiting por mensaje
                if (!$this->checkRateLimit($config['id'])) {
                    $results['failed']++;
                    $results['details'][] = [
                        'recipient' => $recipient,
                        'status' => 'failed',
                        'error' => 'Rate limit exceeded'
                    ];
                    continue;
                }
                
                $messageData = array_merge($data, ['recipient' => $recipient]);
                $messageId = $this->createMessageRecord($userId, $messageData);
                
                $result = $this->sendMessageByProvider($config, $messageData);
                
                if ($result['success']) {
                    $this->updateMessageStatus($messageId, 'sent', $result['provider_message_id']);
                    $results['sent']++;
                    $results['details'][] = [
                        'recipient' => $recipient,
                        'status' => 'sent',
                        'message_id' => $messageId
                    ];
                } else {
                    $this->updateMessageStatus($messageId, 'failed', null, $result['error']);
                    $results['failed']++;
                    $results['details'][] = [
                        'recipient' => $recipient,
                        'status' => 'failed',
                        'error' => $result['error']
                    ];
                }
                
                // Pausa entre mensajes para respetar rate limits
                usleep(100000); // 0.1 segundos
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
    private function createTemplate($userId, $data) {
        $validation = $this->validator->validate($data, [
            'config_id' => 'required|integer',
            'name' => 'required|string|min:1|max:100',
            'language' => 'required|string|size:2',
            'category' => 'required|in:AUTHENTICATION,MARKETING,UTILITY',
            'body_text' => 'required|string|min:1|max:1024'
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
            
            $query = "INSERT INTO whatsapp_templates (
                user_id, config_id, name, language, category, header_type,
                header_text, header_media_url, body_text, footer_text, buttons, parameters
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                $userId,
                $data['config_id'],
                $data['name'],
                $data['language'],
                $data['category'],
                $data['header_type'] ?? null,
                $data['header_text'] ?? null,
                $data['header_media_url'] ?? null,
                $data['body_text'],
                $data['footer_text'] ?? null,
                json_encode($data['buttons'] ?? []),
                json_encode($data['parameters'] ?? [])
            ]);
            
            $templateId = $this->conn->lastInsertId();
            
            // Enviar template para aprobación al proveedor
            $this->submitTemplateForApproval($config, $templateId, $data);
            
            echo json_encode([
                'success' => true,
                'message' => 'Template creado y enviado para aprobación',
                'template_id' => $templateId
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error creating template: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error al crear template']);
        }
    }
    
    // Manejo de webhooks
    private function verifyWebhook() {
        $hubMode = $_GET['hub_mode'] ?? '';
        $hubChallenge = $_GET['hub_challenge'] ?? '';
        $hubVerifyToken = $_GET['hub_verify_token'] ?? '';
        
        // Buscar configuración con este verify token
        $query = "SELECT id FROM whatsapp_config WHERE webhook_verify_token = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$hubVerifyToken]);
        
        if ($stmt->fetch() && $hubMode === 'subscribe') {
            echo $hubChallenge;
            $this->logger->info("Webhook verified successfully");
        } else {
            http_response_code(403);
            echo "Verification failed";
        }
    }
    
    private function handleWebhook() {
        $payload = file_get_contents('php://input');
        $data = json_decode($payload, true);
        
        $this->logger->info("Webhook received", ['payload' => $payload]);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            return;
        }
        
        // Procesar según el tipo de webhook
            if (isset($data['entry'])) {
                foreach ($data['entry'] as $entry) {
                    if (isset($entry['changes'])) {
                        foreach ($entry['changes'] as $change) {
                            if ($change['field'] === 'messages') {
                                $this->processMessageWebhook($change['value']);
                            }
                        }
                    }
                }
            }
            
            echo json_encode(['success' => true]);
            
        } catch (Exception $e) {
            $this->logger->error("Error processing webhook: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Webhook processing failed']);
        }
    }
    
    private function processMessageWebhook($value) {
        // Procesar mensajes recibidos
        if (isset($value['messages'])) {
            foreach ($value['messages'] as $message) {
                $this->processReceivedMessage($value['metadata'], $message);
            }
        }
        
        // Procesar estados de mensajes enviados
        if (isset($value['statuses'])) {
            foreach ($value['statuses'] as $status) {
                $this->processMessageStatus($status);
            }
        }
    }
    
    private function processReceivedMessage($metadata, $message) {
        try {
            // Buscar configuración por phone_number_id
            $phoneNumberId = $metadata['phone_number_id'];
            $query = "SELECT * FROM whatsapp_config WHERE phone_number_id = ? AND is_active = true";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$phoneNumberId]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$config) {
                $this->logger->warning("No config found for phone number ID: " . $phoneNumberId);
                return;
            }
            
            // Verificar si el mensaje ya fue procesado
            $checkQuery = "SELECT id FROM whatsapp_received_messages WHERE config_id = ? AND message_id = ?";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->execute([$config['id'], $message['id']]);
            
            if ($checkStmt->fetch()) {
                return; // Mensaje ya procesado
            }
            
            // Extraer información del mensaje
            $sender = $message['from'];
            $messageType = $message['type'];
            $content = null;
            $mediaUrl = null;
            $mediaCaption = null;
            $locationLat = null;
            $locationLng = null;
            $contactData = null;
            
            switch ($messageType) {
                case 'text':
                    $content = $message['text']['body'];
                    break;
                case 'image':
                    $mediaUrl = $message['image']['id'];
                    $mediaCaption = $message['image']['caption'] ?? null;
                    break;
                case 'document':
                    $mediaUrl = $message['document']['id'];
                    $mediaCaption = $message['document']['caption'] ?? null;
                    break;
                case 'audio':
                    $mediaUrl = $message['audio']['id'];
                    break;
                case 'video':
                    $mediaUrl = $message['video']['id'];
                    $mediaCaption = $message['video']['caption'] ?? null;
                    break;
                case 'location':
                    $locationLat = $message['location']['latitude'];
                    $locationLng = $message['location']['longitude'];
                    break;
                case 'contacts':
                    $contactData = json_encode($message['contacts']);
                    break;
            }
            
            // Guardar mensaje recibido
            $insertQuery = "INSERT INTO whatsapp_received_messages (
                config_id, message_id, sender, sender_name, message_type, content,
                media_url, media_caption, location_latitude, location_longitude,
                contact_data, reply_to_message_id, timestamp_received
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?))";
            
            $insertStmt = $this->conn->prepare($insertQuery);
            $insertStmt->execute([
                $config['id'],
                $message['id'],
                $sender,
                $message['profile']['name'] ?? null,
                $messageType,
                $content,
                $mediaUrl,
                $mediaCaption,
                $locationLat,
                $locationLng,
                $contactData,
                $message['context']['id'] ?? null,
                $message['timestamp']
            ]);
            
            // Crear o actualizar conversación
            $this->updateConversationFromMessage($config['id'], $sender, $message);
            
            // Procesar respuestas automáticas
            $this->processAutoReplies($config, $sender, $content, $messageType);
            
            $this->logger->info("Received message processed", [
                'config_id' => $config['id'],
                'message_id' => $message['id'],
                'sender' => $sender,
                'type' => $messageType
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error processing received message: " . $e->getMessage());
        }
    }
    
    private function processMessageStatus($status) {
        try {
            $messageId = $status['id'];
            $newStatus = $status['status']; // sent, delivered, read, failed
            $timestamp = $status['timestamp'];
            
            // Actualizar estado en base de datos
            $query = "UPDATE whatsapp_messages SET status = ?";
            $params = [$newStatus];
            
            switch ($newStatus) {
                case 'sent':
                    $query .= ", sent_at = FROM_UNIXTIME(?)";
                    $params[] = $timestamp;
                    break;
                case 'delivered':
                    $query .= ", delivered_at = FROM_UNIXTIME(?)";
                    $params[] = $timestamp;
                    break;
                case 'read':
                    $query .= ", read_at = FROM_UNIXTIME(?)";
                    $params[] = $timestamp;
                    break;
                case 'failed':
                    $query .= ", error_message = ?";
                    $params[] = $status['errors'][0]['title'] ?? 'Unknown error';
                    break;
            }
            
            $query .= " WHERE message_id = ?";
            $params[] = $messageId;
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            $this->logger->info("Message status updated", [
                'message_id' => $messageId,
                'status' => $newStatus
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error processing message status: " . $e->getMessage());
        }
    }
    
    private function updateConversationFromMessage($configId, $sender, $message) {
        // Buscar conversación existente
        $query = "SELECT id FROM whatsapp_conversations WHERE config_id = ? AND recipient = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$configId, $sender]);
        $conversation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($conversation) {
            // Actualizar conversación existente
            $updateQuery = "UPDATE whatsapp_conversations SET 
                contact_name = COALESCE(?, contact_name),
                last_message_at = NOW(),
                status = 'active'
                WHERE id = ?";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->execute([
                $message['profile']['name'] ?? null,
                $conversation['id']
            ]);
        } else {
            // Crear nueva conversación
            $insertQuery = "INSERT INTO whatsapp_conversations (
                user_id, config_id, conversation_id, recipient, contact_name, 
                contact_profile_url, last_message_at
            ) VALUES (
                (SELECT user_id FROM whatsapp_config WHERE id = ?),
                ?, ?, ?, ?, ?, NOW()
            )";
            $insertStmt = $this->conn->prepare($insertQuery);
            $insertStmt->execute([
                $configId,
                $configId,
                $sender . '_' . $configId,
                $sender,
                $message['profile']['name'] ?? null,
                $message['profile']['picture'] ?? null
            ]);
        }
    }
    
    private function processAutoReplies($config, $sender, $content, $messageType) {
        if ($messageType !== 'text' || !$content) {
            return;
        }
        
        // Obtener respuestas automáticas activas
        $query = "SELECT * FROM whatsapp_auto_replies 
                  WHERE config_id = ? AND is_active = true 
                  ORDER BY priority DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$config['id']]);
        $autoReplies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($autoReplies as $autoReply) {
            $shouldReply = false;
            
            switch ($autoReply['trigger_type']) {
                case 'keyword':
                    $keywords = explode(',', strtolower($autoReply['trigger_value']));
                    $contentLower = strtolower($content);
                    foreach ($keywords as $keyword) {
                        if (strpos($contentLower, trim($keyword)) !== false) {
                            $shouldReply = true;
                            break;
                        }
                    }
                    break;
                    
                case 'first_message':
                    // Verificar si es el primer mensaje de esta conversación
                    $firstMsgQuery = "SELECT COUNT(*) as count FROM whatsapp_received_messages 
                                      WHERE config_id = ? AND sender = ?";
                    $firstMsgStmt = $this->conn->prepare($firstMsgQuery);
                    $firstMsgStmt->execute([$config['id'], $sender]);
                    $msgCount = $firstMsgStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($msgCount['count'] <= 1) {
                        $shouldReply = true;
                    }
                    break;
                    
                case 'business_hours':
                    $currentTime = date('H:i:s');
                    $currentDay = date('w'); // 0=Sunday, 6=Saturday
                    $businessDays = json_decode($autoReply['business_days'], true) ?? [];
                    
                    if (!in_array($currentDay, $businessDays)) {
                        $shouldReply = true;
                    } elseif ($currentTime < $autoReply['business_hours_start'] || 
                             $currentTime > $autoReply['business_hours_end']) {
                        $shouldReply = true;
                    }
                    break;
                    
                case 'fallback':
                    // Solo si no se activó ninguna otra respuesta automática
                    $shouldReply = true;
                    break;
            }
            
            if ($shouldReply) {
                $this->sendAutoReply($config, $sender, $autoReply);
                break; // Solo enviar una respuesta automática
            }
        }
    }
    
    private function sendAutoReply($config, $recipient, $autoReply) {
        try {
            $messageData = [
                'config_id' => $config['id'],
                'recipient' => $recipient,
                'message_type' => $autoReply['message_type']
            ];
            
            if ($autoReply['message_type'] === 'text') {
                $messageData['content'] = $autoReply['response_text'];
            } else {
                $messageData['template_name'] = $autoReply['template_name'];
            }
            
            // Crear registro del mensaje
            $messageId = $this->createMessageRecord($config['user_id'], $messageData);
            
            // Enviar mensaje
            $result = $this->sendMessageByProvider($config, $messageData);
            
            if ($result['success']) {
                $this->updateMessageStatus($messageId, 'sent', $result['provider_message_id']);
                
                // Marcar que se envió respuesta automática
                $updateQuery = "UPDATE whatsapp_received_messages 
                                SET auto_reply_sent = true 
                                WHERE config_id = ? AND sender = ? 
                                ORDER BY timestamp_received DESC LIMIT 1";
                $updateStmt = $this->conn->prepare($updateQuery);
                $updateStmt->execute([$config['id'], $recipient]);
                
                $this->logger->info("Auto reply sent", [
                    'config_id' => $config['id'],
                    'recipient' => $recipient,
                    'auto_reply_id' => $autoReply['id']
                ]);
            }
            
        } catch (Exception $e) {
            $this->logger->error("Error sending auto reply: " . $e->getMessage());
        }
    }
    
    // Métodos auxiliares para proveedores
    private function validateProviderConfig($data) {
        switch ($data['provider']) {
            case 'whatsapp_business':
                return $this->validateWhatsAppBusinessConfig($data);
            case 'twilio':
                return $this->validateTwilioConfig($data);
            case 'baileys':
                return $this->validateBaileysConfig($data);
            default:
                return ['valid' => false, 'message' => 'Proveedor no soportado'];
        }
    }
    
    private function validateWhatsAppBusinessConfig($data) {
        if (empty($data['access_token']) || empty($data['phone_number_id'])) {
            return ['valid' => false, 'message' => 'Token de acceso y Phone Number ID requeridos'];
        }
        
        // Verificar token con Facebook Graph API
        $url = "https://graph.facebook.com/v18.0/{$data['phone_number_id']}";
        $headers = [
            "Authorization: Bearer {$data['access_token']}",
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
            $responseData = json_decode($response, true);
            if (isset($responseData['display_phone_number'])) {
                return ['valid' => true, 'phone_number' => $responseData['display_phone_number']];
            }
        }
        
        return ['valid' => false, 'message' => 'Credenciales inválidas para WhatsApp Business API'];
    }
    
    private function validateTwilioConfig($data) {
        // Implementar validación de Twilio
        // Por ahora retornar válido si tiene los campos básicos
        if (empty($data['access_token'])) {
            return ['valid' => false, 'message' => 'Auth Token de Twilio requerido'];
        }
        
        return ['valid' => true];
    }
    
    private function validateBaileysConfig($data) {
        // Para Baileys, verificar que el servidor local esté disponible
        $url = "http://localhost:3000/api/status";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            return ['valid' => true];
        }
        
        return ['valid' => false, 'message' => 'Servidor Baileys no disponible'];
    }
    
    private function sendMessageByProvider($config, $data) {
        switch ($config['provider']) {
            case 'whatsapp_business':
                return $this->sendViaWhatsAppBusiness($config, $data);
            case 'twilio':
                return $this->sendViaTwilio($config, $data);
            case 'baileys':
                return $this->sendViaBaileys($config, $data);
            default:
                return ['success' => false, 'error' => 'Proveedor no soportado'];
        }
    }
    
    private function sendViaWhatsAppBusiness($config, $data) {
        $url = "https://graph.facebook.com/v18.0/{$config['phone_number_id']}/messages";
        $headers = [
            "Authorization: Bearer {$config['access_token']}",
            "Content-Type: application/json"
        ];
        
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $data['recipient']
        ];
        
        switch ($data['message_type']) {
            case 'text':
                $payload['type'] = 'text';
                $payload['text'] = ['body' => $data['content']];
                break;
                
            case 'template':
                $payload['type'] = 'template';
                $payload['template'] = [
                    'name' => $data['template_name'],
                    'language' => ['code' => $data['template_language'] ?? 'es']
                ];
                
                if (!empty($data['template_parameters'])) {
                    $payload['template']['components'] = $data['template_parameters'];
                }
                break;
                
            case 'image':
            case 'document':
            case 'audio':
            case 'video':
                $payload['type'] = $data['message_type'];
                $payload[$data['message_type']] = ['link' => $data['media_url']];
                
                if (!empty($data['media_caption']) && in_array($data['message_type'], ['image', 'video', 'document'])) {
                    $payload[$data['message_type']]['caption'] = $data['media_caption'];
                }
                break;
        }
        
        return $this->makeHttpRequest($url, $headers, $payload);
    }
    
    private function sendViaTwilio($config, $data) {
        // Implementar envío via Twilio WhatsApp API
        $accountSid = $config['app_id']; // Twilio Account SID
        $authToken = $config['access_token']; // Twilio Auth Token
        $fromNumber = 'whatsapp:' . $config['phone_number'];
        $toNumber = 'whatsapp:' . $data['recipient'];
        
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";
        
        $postData = [
            'From' => $fromNumber,
            'To' => $toNumber
        ];
        
        if ($data['message_type'] === 'text') {
            $postData['Body'] = $data['content'];
        } elseif ($data['message_type'] === 'template') {
            $postData['ContentSid'] = $data['template_name'];
            if (!empty($data['template_parameters'])) {
                $postData['ContentVariables'] = json_encode($data['template_parameters']);
            }
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "{$accountSid}:{$authToken}");
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 201) {
            $responseData = json_decode($response, true);
            return [
                'success' => true,
                'provider_message_id' => $responseData['sid']
            ];
        }
        
        $errorData = json_decode($response, true);
        return [
            'success' => false,
            'error' => $errorData['message'] ?? 'Error desconocido de Twilio'
        ];
    }
    
    private function sendViaBaileys($config, $data) {
        // Implementar envío via Baileys (servidor Node.js local)
        $url = "http://localhost:3000/api/send-message";
        $headers = ['Content-Type: application/json'];
        
        $payload = [
            'session' => $config['phone_number'],
            'to' => $data['recipient'],
            'type' => $data['message_type']
        ];
        
        switch ($data['message_type']) {
            case 'text':
                $payload['message'] = $data['content'];
                break;
            case 'image':
            case 'document':
            case 'audio':
            case 'video':
                $payload['media'] = $data['media_url'];
                if (!empty($data['media_caption'])) {
                    $payload['caption'] = $data['media_caption'];
                }
                break;
        }
        
        return $this->makeHttpRequest($url, $headers, $payload);
    }
    
    private function makeHttpRequest($url, $headers, $payload) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
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
                'provider_message_id' => $responseData['id'] ?? $responseData['message_id'] ?? uniqid()
            ];
        }
        
        $errorData = json_decode($response, true);
        return [
            'success' => false,
            'error' => $errorData['error']['message'] ?? $errorData['message'] ?? "HTTP {$httpCode}"
        ];
    }

// seguir aqui
// Métodos auxiliares para gestión de mensajes
    private function createMessageRecord($userId, $data) {
        $query = "INSERT INTO whatsapp_messages (
            user_id, config_id, recipient, message_type, content, template_name,
            template_language, template_parameters, media_url, media_caption,
            campaign_id, conversation_id, scheduled_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            $userId,
            $data['config_id'],
            $data['recipient'],
            $data['message_type'],
            $data['content'] ?? null,
            $data['template_name'] ?? null,
            $data['template_language'] ?? 'es',
            json_encode($data['template_parameters'] ?? []),
            $data['media_url'] ?? null,
            $data['media_caption'] ?? null,
            $data['campaign_id'] ?? null,
            $data['conversation_id'] ?? null,
            $data['scheduled_at'] ?? null
        ]);
        
        return $this->conn->lastInsertId();
    }
    
    private function updateMessageStatus($messageId, $status, $providerMessageId = null, $errorMessage = null) {
        $query = "UPDATE whatsapp_messages SET status = ?";
        $params = [$status];
        
        if ($providerMessageId) {
            $query .= ", message_id = ?";
            $params[] = $providerMessageId;
        }
        
        if ($errorMessage) {
            $query .= ", error_message = ?";
            $params[] = $errorMessage;
        }
        
        if ($status === 'sent') {
            $query .= ", sent_at = NOW()";
        }
        
        $query .= " WHERE id = ?";
        $params[] = $messageId;
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
    }
    
    private function checkRateLimit($configId) {
        // Obtener límite de configuración
        $query = "SELECT rate_limit_per_hour FROM whatsapp_config WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$configId]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) return false;
        
        // Contar mensajes enviados en la última hora
        $countQuery = "SELECT COUNT(*) as count FROM whatsapp_messages 
                       WHERE config_id = ? AND status IN ('sent', 'delivered', 'read') 
                       AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        $countStmt = $this->conn->prepare($countQuery);
        $countStmt->execute([$configId]);
        $result = $countStmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['count'] < $config['rate_limit_per_hour'];
    }
    
    private function getConfigById($userId, $configId) {
        $query = "SELECT * FROM whatsapp_config WHERE id = ? AND user_id = ? AND is_active = true";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$configId, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Gestión de campañas
    private function createCampaign($userId, $data) {
        $validation = $this->validator->validate($data, [
            'config_id' => 'required|integer',
            'name' => 'required|string|min:1|max:200',
            'template_id' => 'required|integer',
            'recipient_list' => 'required|array|min:1|max:10000'
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
            
            // Verificar que el template existe y está aprobado
            $templateQuery = "SELECT * FROM whatsapp_templates 
                              WHERE id = ? AND user_id = ? AND status = 'approved'";
            $templateStmt = $this->conn->prepare($templateQuery);
            $templateStmt->execute([$data['template_id'], $userId]);
            $template = $templateStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$template) {
                http_response_code(404);
                echo json_encode(['error' => 'Template no encontrado o no aprobado']);
                return;
            }
            
            $query = "INSERT INTO whatsapp_campaigns (
                user_id, config_id, name, description, template_id, recipient_list,
                total_recipients, scheduled_at, rate_limit_per_minute, personalization_data
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                $userId,
                $data['config_id'],
                $data['name'],
                $data['description'] ?? null,
                $data['template_id'],
                json_encode($data['recipient_list']),
                count($data['recipient_list']),
                $data['scheduled_at'] ?? null,
                $data['rate_limit_per_minute'] ?? 10,
                json_encode($data['personalization_data'] ?? [])
            ]);
            
            $campaignId = $this->conn->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'message' => 'Campaña creada exitosamente',
                'campaign_id' => $campaignId
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
            $query = "SELECT c.*, t.name as template_name, t.language, conf.* 
                      FROM whatsapp_campaigns c 
                      JOIN whatsapp_templates t ON c.template_id = t.id 
                      JOIN whatsapp_config conf ON c.config_id = conf.id 
                      WHERE c.id = ? AND c.user_id = ? AND c.status = 'draft'";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$data['campaign_id'], $userId]);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$campaign) {
                http_response_code(404);
                echo json_encode(['error' => 'Campaña no encontrada o ya iniciada']);
                return;
            }
            
            // Actualizar estado a 'sending'
            $updateQuery = "UPDATE whatsapp_campaigns SET status = 'sending', started_at = NOW() WHERE id = ?";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->execute([$data['campaign_id']]);
            
            // Procesar en background
            $this->processCampaignInBackground($campaign);
            
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
    
    private function processCampaignInBackground($campaign) {
        // En un entorno de producción, esto se haría con colas de trabajo
        // Por ahora, procesamos en tiempo real con rate limiting
        
        $recipients = json_decode($campaign['recipient_list'], true);
        $personalizationData = json_decode($campaign['personalization_data'], true) ?? [];
        $rateLimitDelay = 60 / $campaign['rate_limit_per_minute']; // segundos entre mensajes
        
        $sentCount = 0;
        $failedCount = 0;
        
        foreach ($recipients as $recipient) {
            try {
                // Verificar rate limit global
                if (!$this->checkRateLimit($campaign['config_id'])) {
                    sleep(60); // Esperar 1 minuto si se excede el límite
                    continue;
                }
                
                // Preparar datos del mensaje
                $messageData = [
                    'config_id' => $campaign['config_id'],
                    'recipient' => $recipient,
                    'message_type' => 'template',
                    'template_name' => $campaign['template_name'],
                    'template_language' => $campaign['language'],
                    'campaign_id' => $campaign['id']
                ];
                
                // Aplicar personalización si está disponible
                if (isset($personalizationData[$recipient])) {
                    $messageData['template_parameters'] = $personalizationData[$recipient];
                }
                
                // Crear registro del mensaje
                $messageId = $this->createMessageRecord($campaign['user_id'], $messageData);
                
                // Enviar mensaje
                $result = $this->sendMessageByProvider($campaign, $messageData);
                
                if ($result['success']) {
                    $this->updateMessageStatus($messageId, 'sent', $result['provider_message_id']);
                    $sentCount++;
                } else {
                    $this->updateMessageStatus($messageId, 'failed', null, $result['error']);
                    $failedCount++;
                }
                
                // Rate limiting
                sleep($rateLimitDelay);
                
            } catch (Exception $e) {
                $this->logger->error("Error in campaign message: " . $e->getMessage());
                $failedCount++;
            }
        }
        
        // Actualizar estadísticas de la campaña
        $updateQuery = "UPDATE whatsapp_campaigns SET 
                        sent_count = ?, failed_count = ?, status = 'completed', completed_at = NOW()
                        WHERE id = ?";
        $updateStmt = $this->conn->prepare($updateQuery);
        $updateStmt->execute([$sentCount, $failedCount, $campaign['id']]);
        
        $this->logger->info("Campaign completed", [
            'campaign_id' => $campaign['id'],
            'sent' => $sentCount,
            'failed' => $failedCount
        ]);
    }
    
    private function getCampaigns($userId) {
        $query = "SELECT c.*, t.name as template_name, conf.phone_number 
                  FROM whatsapp_campaigns c 
                  LEFT JOIN whatsapp_templates t ON c.template_id = t.id 
                  LEFT JOIN whatsapp_config conf ON c.config_id = conf.id 
                  WHERE c.user_id = ? 
                  ORDER BY c.created_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$userId]);
        $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'campaigns' => $campaigns
        ]);
    }
    
    // Gestión de templates
    private function getTemplates($userId) {
        $configId = $_GET['config_id'] ?? null;
        
        $query = "SELECT t.*, c.phone_number 
                  FROM whatsapp_templates t 
                  JOIN whatsapp_config c ON t.config_id = c.id 
                  WHERE t.user_id = ?";
        $params = [$userId];
        
        if ($configId) {
            $query .= " AND t.config_id = ?";
            $params[] = $configId;
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
    
    private function submitTemplateForApproval($config, $templateId, $data) {
        // Enviar template para aprobación según el proveedor
        switch ($config['provider']) {
            case 'whatsapp_business':
                $this->submitWhatsAppBusinessTemplate($config, $templateId, $data);
                break;
            case 'twilio':
                $this->submitTwilioTemplate($config, $templateId, $data);
                break;
            default:
                // Para otros proveedores, marcar como aprobado automáticamente
                $updateQuery = "UPDATE whatsapp_templates SET status = 'approved' WHERE id = ?";
                $updateStmt = $this->conn->prepare($updateQuery);
                $updateStmt->execute([$templateId]);
        }
    }
    
    private function submitWhatsAppBusinessTemplate($config, $templateId, $data) {
        $url = "https://graph.facebook.com/v18.0/{$config['business_account_id']}/message_templates";
        $headers = [
            "Authorization: Bearer {$config['access_token']}",
            "Content-Type: application/json"
        ];
        
        $payload = [
            'name' => $data['name'],
            'language' => $data['language'],
            'category' => $data['category'],
            'components' => []
        ];
        
        // Header component
        if (!empty($data['header_type'])) {
            $headerComponent = [
                'type' => 'HEADER',
                'format' => $data['header_type']
            ];
            
            if ($data['header_type'] === 'TEXT') {
                $headerComponent['text'] = $data['header_text'];
            } else {
                $headerComponent['example'] = [
                    'header_handle' => [$data['header_media_url']]
                ];
            }
            
            $payload['components'][] = $headerComponent;
        }
        
        // Body component
        $payload['components'][] = [
            'type' => 'BODY',
            'text' => $data['body_text']
        ];
        
        // Footer component
        if (!empty($data['footer_text'])) {
            $payload['components'][] = [
                'type' => 'FOOTER',
                'text' => $data['footer_text']
            ];
        }
        
        // Buttons component
        if (!empty($data['buttons'])) {
            $payload['components'][] = [
                'type' => 'BUTTONS',
                'buttons' => $data['buttons']
            ];
        }
        
        $result = $this->makeHttpRequest($url, $headers, $payload);
        
        if ($result['success']) {
            $updateQuery = "UPDATE whatsapp_templates SET 
                            status = 'pending', external_template_id = ? WHERE id = ?";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->execute([$result['provider_message_id'], $templateId]);
        } else {
            $updateQuery = "UPDATE whatsapp_templates SET 
                            status = 'rejected', rejection_reason = ? WHERE id = ?";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->execute([$result['error'], $templateId]);
        }
    }
    
    private function submitTwilioTemplate($config, $templateId, $data) {
        // Implementar envío de template a Twilio
        // Por ahora marcar como aprobado
        $updateQuery = "UPDATE whatsapp_templates SET status = 'approved' WHERE id = ?";
        $updateStmt = $this->conn->prepare($updateQuery);
        $updateStmt->execute([$templateId]);
    }
    
    // Analytics y reportes
    private function getAnalytics($userId) {
        $configId = $_GET['config_id'] ?? null;
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateTo = $_GET['date_to'] ?? date('Y-m-d');
        
        // Estadísticas generales
        $statsQuery = "SELECT 
            COUNT(*) as total_messages,
            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_messages,
            SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_messages,
            SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read_messages,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_messages,
            SUM(cost) as total_cost
            FROM whatsapp_messages 
            WHERE user_id = ? AND created_at BETWEEN ? AND ?";
        
        $params = [$userId, $dateFrom, $dateTo];
        
        if ($configId) {
            $statsQuery .= " AND config_id = ?";
            $params[] = $configId;
        }
        
        $statsStmt = $this->conn->prepare($statsQuery);
        $statsStmt->execute($params);
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
        
        // Estadísticas por día
        $dailyQuery = "SELECT 
            DATE(created_at) as date,
            COUNT(*) as messages,
            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM whatsapp_messages 
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
        
        // Top conversaciones
        $conversationsQuery = "SELECT 
            recipient,
            contact_name,
            COUNT(*) as message_count,
            MAX(last_message_at) as last_activity
            FROM whatsapp_conversations 
            WHERE user_id = ?";
        
        $conversationsParams = [$userId];
        
        if ($configId) {
            $conversationsQuery .= " AND config_id = ?";
            $conversationsParams[] = $configId;
        }
        
        $conversationsQuery .= " GROUP BY recipient ORDER BY message_count DESC LIMIT 10";
        
        $conversationsStmt = $this->conn->prepare($conversationsQuery);
        $conversationsStmt->execute($conversationsParams);
        $topConversations = $conversationsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Estadísticas de campañas
        $campaignsQuery = "SELECT 
            id, name, status, total_recipients, sent_count, failed_count,
            created_at, started_at, completed_at
            FROM whatsapp_campaigns 
            WHERE user_id = ?";
        
        $campaignsParams = [$userId];
        
        if ($configId) {
            $campaignsQuery .= " AND config_id = ?";
            $campaignsParams[] = $configId;
        }
        
        $campaignsQuery .= " ORDER BY created_at DESC LIMIT 5";
        
        $campaignsStmt = $this->conn->prepare($campaignsQuery);
        $campaignsStmt->execute($campaignsParams);
        $recentCampaigns = $campaignsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'analytics' => [
                'general_stats' => $stats,
                'daily_stats' => $dailyStats,
                'top_conversations' => $topConversations,
                'recent_campaigns' => $recentCampaigns,
                'delivery_rate' => $stats['total_messages'] > 0 ? 
                    round(($stats['delivered_messages'] / $stats['total_messages']) * 100, 2) : 0,
                'read_rate' => $stats['delivered_messages'] > 0 ? 
                    round(($stats['read_messages'] / $stats['delivered_messages']) * 100, 2) : 0
            ]
        ]);
    }
    
    // Gestión de conversaciones
    private function getConversations($userId) {
        $configId = $_GET['config_id'] ?? null;
        $status = $_GET['status'] ?? 'active';
        $page = intval($_GET['page'] ?? 1);
        $limit = intval($_GET['limit'] ?? 20);
        $offset = ($page - 1) * $limit;
        
        $query = "SELECT c.*, 
            (SELECT COUNT(*) FROM whatsapp_received_messages rm 
             WHERE rm.config_id = c.config_id AND rm.sender = c.recipient) as received_count,
            (SELECT COUNT(*) FROM whatsapp_messages m 
             WHERE m.config_id = c.config_id AND m.recipient = c.recipient) as sent_count
            FROM whatsapp_conversations c 
            WHERE c.user_id = ? AND c.status = ?";
        
        $params = [$userId, $status];
        
        if ($configId) {
            $query .= " AND c.config_id = ?";
            $params[] = $configId;
        }
        
        $query .= " ORDER BY c.last_message_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Contar total para paginación
        $countQuery = "SELECT COUNT(*) as total FROM whatsapp_conversations c 
                       WHERE c.user_id = ? AND c.status = ?";
        $countParams = [$userId, $status];
        
        if ($configId) {
            $countQuery .= " AND c.config_id = ?";
            $countParams[] = $configId;
        }
        
        $countStmt = $this->conn->prepare($countQuery);
        $countStmt->execute($countParams);
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        echo json_encode([
            'success' => true,
            'conversations' => $conversations,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ]);
    }
    
    private function updateConversation($userId, $conversationId, $data) {
        $allowedFields = ['status', 'assigned_to', 'tags', 'notes'];
        $updateFields = [];
        $params = [];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateFields[] = "{$field} = ?";
                $params[] = is_array($data[$field]) ? json_encode($data[$field]) : $data[$field];
            }
        }
        
        if (empty($updateFields)) {
            http_response_code(400);
            echo json_encode(['error' => 'No hay campos para actualizar']);
            return;
        }
        
        $params[] = $conversationId;
        $params[] = $userId;
        
        $query = "UPDATE whatsapp_conversations SET " . implode(', ', $updateFields) . 
                 " WHERE id = ? AND user_id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Conversación actualizada']);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Conversación no encontrada']);
        }
    }
    
    // Obtener mensajes de conversación
    private function getMessages($userId) {
        $configId = $_GET['config_id'] ?? null;
        $recipient = $_GET['recipient'] ?? null;
        $campaignId = $_GET['campaign_id'] ?? null;
        $page = intval($_GET['page'] ?? 1);
        $limit = intval($_GET['limit'] ?? 50);
        $offset = ($page - 1) * $limit;
        
        if (!$configId) {
            http_response_code(400);
            echo json_encode(['error' => 'config_id es requerido']);
            return;
        }
        
        // Mensajes enviados
        $sentQuery = "SELECT 'sent' as direction, id, recipient as contact, message_type, 
                      content, template_name, media_url, media_caption, status, 
                      created_at as timestamp, sent_at, delivered_at, read_at, error_message
                      FROM whatsapp_messages 
                      WHERE config_id = ? AND user_id = ?";
        $sentParams = [$configId, $userId];
        
        if ($recipient) {
            $sentQuery .= " AND recipient = ?";
            $sentParams[] = $recipient;
        }
        
        if ($campaignId) {
            $sentQuery .= " AND campaign_id = ?";
            $sentParams[] = $campaignId;
        }
        
        // Mensajes recibidos
        $receivedQuery = "SELECT 'received' as direction, id, sender as contact, message_type,
                          content, null as template_name, media_url, media_caption, 
                          'received' as status, timestamp_received as timestamp,
                          null as sent_at, null as delivered_at, null as read_at, null as error_message
                          FROM whatsapp_received_messages 
                          WHERE config_id = ?";
        $receivedParams = [$configId];
        
        if ($recipient) {
            $receivedQuery .= " AND sender = ?";
            $receivedParams[] = $recipient;
        }
        
        // Combinar y ordenar
        $unionQuery = "({$sentQuery}) UNION ({$receivedQuery}) 
                       ORDER BY timestamp DESC LIMIT ? OFFSET ?";
        
        $allParams = array_merge($sentParams, $receivedParams, [$limit, $offset]);
        
        $stmt = $this->conn->prepare($unionQuery);
        $stmt->execute($allParams);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'messages' => $messages
        ]);
    }

// seguir aqui
// Subida de multimedia
    private function uploadMedia($userId) {
        if (!isset($_FILES['media']) || $_FILES['media']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'No se recibió archivo o hubo error en la subida']);
            return;
        }
        
        $configId = $_POST['config_id'] ?? null;
        if (!$configId) {
            http_response_code(400);
            echo json_encode(['error' => 'config_id es requerido']);
            return;
        }
        
        $config = $this->getConfigById($userId, $configId);
        if (!$config) {
            http_response_code(404);
            echo json_encode(['error' => 'Configuración no encontrada']);
            return;
        }
        
        try {
            $file = $_FILES['media'];
            $allowedTypes = [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                'video/mp4', 'video/3gpp',
                'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/aac',
                'application/pdf', 'application/msword', 
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            ];
            
            if (!in_array($file['type'], $allowedTypes)) {
                http_response_code(400);
                echo json_encode(['error' => 'Tipo de archivo no permitido']);
                return;
            }
            
            // Verificar tamaño (máximo 16MB para WhatsApp)
            $maxSize = 16 * 1024 * 1024; // 16MB
            if ($file['size'] > $maxSize) {
                http_response_code(400);
                echo json_encode(['error' => 'Archivo muy grande. Máximo 16MB permitido']);
                return;
            }
            
            // Crear directorio si no existe
            $uploadDir = '../uploads/whatsapp/' . $userId . '/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Generar nombre único
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = uniqid('media_') . '.' . $extension;
            $filepath = $uploadDir . $filename;
            
            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                http_response_code(500);
                echo json_encode(['error' => 'Error al guardar archivo']);
                return;
            }
            
            // Subir a proveedor según configuración
            $result = $this->uploadMediaToProvider($config, $filepath, $file['type']);
            
            if ($result['success']) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Archivo subido exitosamente',
                    'media_id' => $result['media_id'],
                    'media_url' => $result['media_url'],
                    'local_path' => $filepath
                ]);
            } else {
                // Eliminar archivo local si falla la subida al proveedor
                unlink($filepath);
                http_response_code(400);
                echo json_encode(['error' => $result['error']]);
            }
            
        } catch (Exception $e) {
            $this->logger->error("Error uploading media: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno al subir archivo']);
        }
    }
    
    private function uploadMediaToProvider($config, $filepath, $mimeType) {
        switch ($config['provider']) {
            case 'whatsapp_business':
                return $this->uploadToWhatsAppBusiness($config, $filepath, $mimeType);
            case 'twilio':
                return $this->uploadToTwilio($config, $filepath, $mimeType);
            case 'baileys':
                return $this->uploadToBaileys($config, $filepath, $mimeType);
            default:
                return ['success' => false, 'error' => 'Proveedor no soportado'];
        }
    }
    
    private function uploadToWhatsAppBusiness($config, $filepath, $mimeType) {
        $url = "https://graph.facebook.com/v18.0/{$config['phone_number_id']}/media";
        $headers = [
            "Authorization: Bearer {$config['access_token']}"
        ];
        
        $postFields = [
            'messaging_product' => 'whatsapp',
            'file' => new CURLFile($filepath, $mimeType),
            'type' => $mimeType
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => "cURL error: {$error}"];
        }
        
        if ($httpCode === 200) {
            $responseData = json_decode($response, true);
            return [
                'success' => true,
                'media_id' => $responseData['id'],
                'media_url' => "https://graph.facebook.com/v18.0/{$responseData['id']}"
            ];
        }
        
        $errorData = json_decode($response, true);
        return [
            'success' => false,
            'error' => $errorData['error']['message'] ?? "HTTP {$httpCode}"
        ];
    }
    
    private function uploadToTwilio($config, $filepath, $mimeType) {
        // Para Twilio, retornar la URL local ya que maneja URLs directamente
        $baseUrl = $_SERVER['HTTP_HOST'];
        $relativePath = str_replace('../', '', $filepath);
        
        return [
            'success' => true,
            'media_id' => basename($filepath),
            'media_url' => "https://{$baseUrl}/{$relativePath}"
        ];
    }
    
    private function uploadToBaileys($config, $filepath, $mimeType) {
        // Para Baileys, subir al servidor Node.js
        $url = "http://localhost:3000/api/upload-media";
        $headers = [];
        
        $postFields = [
            'session' => $config['phone_number'],
            'file' => new CURLFile($filepath, $mimeType)
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $responseData = json_decode($response, true);
            return [
                'success' => true,
                'media_id' => $responseData['id'],
                'media_url' => $responseData['url']
            ];
        }
        
        return ['success' => false, 'error' => 'Error uploading to Baileys server'];
    }
    
    // Generación de código QR para conexión
    private function generateQRCode($userId) {
        $configId = $_GET['config_id'] ?? null;
        if (!$configId) {
            http_response_code(400);
            echo json_encode(['error' => 'config_id es requerido']);
            return;
        }
        
        $config = $this->getConfigById($userId, $configId);
        if (!$config) {
            http_response_code(404);
            echo json_encode(['error' => 'Configuración no encontrada']);
            return;
        }
        
        // Solo aplicable para Baileys
        if ($config['provider'] !== 'baileys') {
            http_response_code(400);
            echo json_encode(['error' => 'QR Code solo disponible para Baileys']);
            return;
        }
        
        try {
            $url = "http://localhost:3000/api/qr-code";
            $headers = ['Content-Type: application/json'];
            $payload = ['session' => $config['phone_number']];
            
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
            
            if ($httpCode === 200) {
                $responseData = json_decode($response, true);
                echo json_encode([
                    'success' => true,
                    'qr_code' => $responseData['qr'],
                    'status' => $responseData['status'] ?? 'disconnected'
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Error obteniendo QR code']);
            }
            
        } catch (Exception $e) {
            $this->logger->error("Error generating QR code: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error interno generando QR']);
        }
    }
    
    // Gestión de respuestas automáticas
    private function createAutoReply($userId, $data) {
        $validation = $this->validator->validate($data, [
            'config_id' => 'required|integer',
            'trigger_type' => 'required|in:keyword,first_message,business_hours,fallback',
            'message_type' => 'required|in:text,template',
            'response_text' => 'required_if:message_type,text'
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
            
            $query = "INSERT INTO whatsapp_auto_replies (
                user_id, config_id, trigger_type, trigger_value, message_type,
                response_text, template_id, business_hours_start, business_hours_end,
                business_days, priority
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                $userId,
                $data['config_id'],
                $data['trigger_type'],
                $data['trigger_value'] ?? null,
                $data['message_type'],
                $data['response_text'] ?? null,
                $data['template_id'] ?? null,
                $data['business_hours_start'] ?? null,
                $data['business_hours_end'] ?? null,
                json_encode($data['business_days'] ?? []),
                $data['priority'] ?? 1
            ]);
            
            $autoReplyId = $this->conn->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'message' => 'Respuesta automática creada exitosamente',
                'auto_reply_id' => $autoReplyId
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error creating auto reply: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error al crear respuesta automática']);
        }
    }
    
    // Métodos de configuración y utilidades
    private function updateConfiguration($userId, $configId, $data) {
        if (!$configId) {
            http_response_code(400);
            echo json_encode(['error' => 'ID de configuración requerido']);
            return;
        }
        
        $config = $this->getConfigById($userId, $configId);
        if (!$config) {
            http_response_code(404);
            echo json_encode(['error' => 'Configuración no encontrada']);
            return;
        }
        
        try {
            $allowedFields = [
                'webhook_url', 'webhook_verify_token', 'rate_limit_per_hour', 'is_active'
            ];
            
            $updateFields = [];
            $params = [];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateFields[] = "{$field} = ?";
                    $params[] = $data[$field];
                }
            }
            
            if (empty($updateFields)) {
                http_response_code(400);
                echo json_encode(['error' => 'No hay campos para actualizar']);
                return;
            }
            
            $params[] = $configId;
            $params[] = $userId;
            
            $query = "UPDATE whatsapp_config SET " . implode(', ', $updateFields) . 
                     ", updated_at = NOW() WHERE id = ? AND user_id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            echo json_encode([
                'success' => true,
                'message' => 'Configuración actualizada exitosamente'
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error updating config: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error al actualizar configuración']);
        }
    }
    
    private function updateTemplate($userId, $templateId, $data) {
        if (!$templateId) {
            http_response_code(400);
            echo json_encode(['error' => 'ID de template requerido']);
            return;
        }
        
        try {
            // Verificar que el template pertenece al usuario
            $checkQuery = "SELECT id FROM whatsapp_templates WHERE id = ? AND user_id = ?";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->execute([$templateId, $userId]);
            
            if (!$checkStmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Template no encontrado']);
                return;
            }
            
            $allowedFields = [
                'header_text', 'body_text', 'footer_text', 'buttons', 'parameters'
            ];
            
            $updateFields = [];
            $params = [];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateFields[] = "{$field} = ?";
                    $params[] = is_array($data[$field]) ? json_encode($data[$field]) : $data[$field];
                }
            }
            
            if (empty($updateFields)) {
                http_response_code(400);
                echo json_encode(['error' => 'No hay campos para actualizar']);
                return;
            }
            
            $params[] = $templateId;
            
            $query = "UPDATE whatsapp_templates SET " . implode(', ', $updateFields) . 
                     ", updated_at = NOW() WHERE id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            echo json_encode([
                'success' => true,
                'message' => 'Template actualizado exitosamente'
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error updating template: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error al actualizar template']);
        }
    }
    
    private function updateCampaign($userId, $campaignId, $data) {
        if (!$campaignId) {
            http_response_code(400);
            echo json_encode(['error' => 'ID de campaña requerido']);
            return;
        }
        
        try {
            // Verificar que la campaña pertenece al usuario y está en estado draft
            $checkQuery = "SELECT id, status FROM whatsapp_campaigns WHERE id = ? AND user_id = ?";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->execute([$campaignId, $userId]);
            $campaign = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$campaign) {
                http_response_code(404);
                echo json_encode(['error' => 'Campaña no encontrada']);
                return;
            }
            
            if ($campaign['status'] !== 'draft') {
                http_response_code(400);
                echo json_encode(['error' => 'Solo se pueden editar campañas en borrador']);
                return;
            }
            
            $allowedFields = [
                'name', 'description', 'scheduled_at', 'rate_limit_per_minute',
                'recipient_list', 'personalization_data'
            ];
            
            $updateFields = [];
            $params = [];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateFields[] = "{$field} = ?";
                    if (in_array($field, ['recipient_list', 'personalization_data'])) {
                        $params[] = json_encode($data[$field]);
                        if ($field === 'recipient_list') {
                            $updateFields[] = "total_recipients = ?";
                            $params[] = count($data[$field]);
                        }
                    } else {
                        $params[] = $data[$field];
                    }
                }
            }
            
            if (empty($updateFields)) {
                http_response_code(400);
                echo json_encode(['error' => 'No hay campos para actualizar']);
                return;
            }
            
            $params[] = $campaignId;
            
            $query = "UPDATE whatsapp_campaigns SET " . implode(', ', $updateFields) . 
                     ", updated_at = NOW() WHERE id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            echo json_encode([
                'success' => true,
                'message' => 'Campaña actualizada exitosamente'
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error updating campaign: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error al actualizar campaña']);
        }
    }
    
    // Métodos de eliminación
    private function handleDelete($pathParts, $user) {
        $action = $pathParts[3] ?? '';
        $id = $pathParts[4] ?? null;
        
        switch ($action) {
            case 'config':
                $this->deleteConfiguration($user['id'], $id);
                break;
            case 'templates':
                $this->deleteTemplate($user['id'], $id);
                break;
            case 'campaigns':
                $this->deleteCampaign($user['id'], $id);
                break;
            case 'auto-replies':
                $this->deleteAutoReply($user['id'], $id);
                break;
            default:
                http_response_code(404);
                echo json_encode(['error' => 'Endpoint no encontrado']);
        }
    }
    
    private function deleteConfiguration($userId, $configId) {
        if (!$configId) {
            http_response_code(400);
            echo json_encode(['error' => 'ID de configuración requerido']);
            return;
        }
        
        try {
            $query = "DELETE FROM whatsapp_config WHERE id = ? AND user_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$configId, $userId]);
            
            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Configuración eliminada']);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Configuración no encontrada']);
            }
        } catch (Exception $e) {
            $this->logger->error("Error deleting config: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error al eliminar configuración']);
        }
    }
    
    private function deleteTemplate($userId, $templateId) {
        if (!$templateId) {
            http_response_code(400);
            echo json_encode(['error' => 'ID de template requerido']);
            return;
        }
        
        try {
            $query = "DELETE FROM whatsapp_templates WHERE id = ? AND user_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$templateId, $userId]);
            
            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Template eliminado']);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Template no encontrado']);
            }
        } catch (Exception $e) {
            $this->logger->error("Error deleting template: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error al eliminar template']);
        }
    }
    
    private function deleteCampaign($userId, $campaignId) {
        if (!$campaignId) {
            http_response_code(400);
            echo json_encode(['error' => 'ID de campaña requerido']);
            return;
        }
        
        try {
            // Solo permitir eliminar campañas en draft
            $query = "DELETE FROM whatsapp_campaigns 
                      WHERE id = ? AND user_id = ? AND status = 'draft'";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$campaignId, $userId]);
            
            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Campaña eliminada']);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Campaña no encontrada o no se puede eliminar']);
            }
        } catch (Exception $e) {
            $this->logger->error("Error deleting campaign: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error al eliminar campaña']);
        }
    }
    
    private function deleteAutoReply($userId, $autoReplyId) {
        if (!$autoReplyId) {
            http_response_code(400);
            echo json_encode(['error' => 'ID de respuesta automática requerido']);
            return;
        }
        
        try {
            $query = "DELETE FROM whatsapp_auto_replies WHERE id = ? AND user_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$autoReplyId, $userId]);
            
            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Respuesta automática eliminada']);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Respuesta automática no encontrada']);
            }
        } catch (Exception $e) {
            $this->logger->error("Error deleting auto reply: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Error al eliminar respuesta automática']);
        }
    }
}

// Inicializar y procesar la solicitud
try {
    $api = new WhatsAppAPI();
    $api->handleRequest();
} catch (Exception $e) {
    error_log("WhatsApp API Fatal Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error fatal del servidor',
        'message' => 'Por favor contacte al administrador'
    ]);
}
?>