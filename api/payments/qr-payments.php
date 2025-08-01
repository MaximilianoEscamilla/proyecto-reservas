<?php
/**
 * API de Pagos QR - ReservaFácil
 * 
 * Este archivo maneja pagos mediante códigos QR
 * Incluye generación QR, validación de pagos y notificaciones en tiempo real
 * 
 * @author ReservaFácil Development Team
 * @version 1.0
 * @since 2024-07-30
 */

// Configuración de headers para CORS y JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Incluir archivos de configuración
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../auth/verify.php';

/**
 * Clase principal para gestión de pagos QR
 */
class QRPaymentsManager {
    private $db;
    private $config;
    private $user_id;
    private $user_type;
    
    // Configuración de QR
    private $qr_expiration_minutes = 10;
    private $max_qr_amount = 500000; // ARS
    private $min_qr_amount = 100; // ARS
    
    public function __construct() {
        $this->db = new Database();
        $this->config = new Config();
        
        // Verificar autenticación JWT
        $auth = new JWTVerifier();
        $token_data = $auth->verifyToken();
        
        if (!$token_data) {
            $this->sendError('Token inválido o expirado', 401);
        }
        
        $this->user_id = $token_data['user_id'];
        $this->user_type = $token_data['user_type'] ?? 'customer';
    }
    
    /**
     * Procesar la solicitud según el método HTTP
     */
    public function handleRequest() {
        try {
            $method = $_SERVER['REQUEST_METHOD'];
            $action = $_GET['action'] ?? '';
            
            switch ($method) {
                case 'GET':
                    $this->handleGet($action);
                    break;
                case 'POST':
                    $this->handlePost($action);
                    break;
                case 'PUT':
                    $this->handlePut($action);
                    break;
                case 'DELETE':
                    $this->handleDelete($action);
                    break;
                default:
                    $this->sendError('Método no permitido', 405);
            }
        } catch (Exception $e) {
            $this->logError('Error en handleRequest: ' . $e->getMessage());
            $this->sendError('Error interno del servidor', 500);
        }
    }
    
    /**
     * Manejar solicitudes GET
     */
    private function handleGet($action) {
        switch ($action) {
            case 'generate':
                $this->generateQRCode();
                break;
            case 'status':
                $this->getQRStatus();
                break;
            case 'list':
                $this->getQRPaymentsList();
                break;
            case 'validate':
                $this->validateQRCode();
                break;
            case 'banks':
                $this->getSupportedBanks();
                break;
            default:
                $this->getQRPaymentsList();
        }
    }
    
    /**
     * Manejar solicitudes POST
     */
    private function handlePost($action) {
        switch ($action) {
            case 'create':
                $this->createQRPayment();
                break;
            case 'process':
                $this->processQRPayment();
                break;
            case 'confirm':
                $this->confirmQRPayment();
                break;
            case 'cancel':
                $this->cancelQRPayment();
                break;
            default:
                $this->sendError('Acción no válida', 400);
        }
    }
    
    /**
     * Manejar solicitudes PUT
     */
    private function handlePut($action) {
        switch ($action) {
            case 'update':
                $this->updateQRPayment();
                break;
            case 'extend':
                $this->extendQRExpiration();
                break;
            default:
                $this->sendError('Acción no válida', 400);
        }
    }
    
    /**
     * Manejar solicitudes DELETE
     */
    private function handleDelete($action) {
        switch ($action) {
            case 'cancel':
                $this->cancelQRPayment();
                break;
            default:
                $this->sendError('Acción no válida', 400);
        }
    }
    
    /**
     * Generar código QR para pago
     */
    private function generateQRCode() {
        try {
            $amount = (float)($_GET['amount'] ?? 0);
            $plan_type = $_GET['plan_type'] ?? '';
            $description = $_GET['description'] ?? '';
            
            // Validaciones
            if ($amount <= 0) {
                $this->sendError('Monto requerido', 400);
            }
            
            if ($amount < $this->min_qr_amount) {
                $this->sendError("Monto mínimo: $" . number_format($this->min_qr_amount, 2), 400);
            }
            
            if ($amount > $this->max_qr_amount) {
                $this->sendError("Monto máximo: $" . number_format($this->max_qr_amount, 2), 400);
            }
            
            if (empty($plan_type)) {
                $this->sendError('Tipo de plan requerido', 400);
            }
            
            // Obtener información del usuario
            $user = $this->getUserById($this->user_id);
            if (!$user) {
                $this->sendError('Usuario no encontrado', 404);
            }
            
            // Generar código QR único
            $qr_code = $this->generateUniqueQRCode();
            $expires_at = date('Y-m-d H:i:s', strtotime("+{$this->qr_expiration_minutes} minutes"));
            
            // Crear registro de pago QR
            $qr_payment_id = $this->createQRPaymentRecord($qr_code, $amount, $plan_type, $description, $expires_at);
            
            // Generar datos para el QR
            $qr_data = $this->generateQRData($qr_code, $amount, $description, $user);
            
            // Crear imagen QR
            $qr_image_url = $this->createQRImage($qr_data, $qr_code);
            
            // Log de generación
            $this->logActivity('qr_generated', $qr_payment_id, "QR generado por monto: $" . number_format($amount, 2));
            
            $this->sendSuccess([
                'qr_payment_id' => $qr_payment_id,
                'qr_code' => $qr_code,
                'qr_data' => $qr_data,
                'qr_image_url' => $qr_image_url,
                'amount' => $amount,
                'currency' => 'ARS',
                'expires_at' => $expires_at,
                'expires_in_minutes' => $this->qr_expiration_minutes,
                'status' => 'pending'
            ]);
            
        } catch (Exception $e) {
            $this->logError('Error en generateQRCode: ' . $e->getMessage());
            $this->sendError('Error al generar código QR', 500);
        }
    }
    
    /**
     * Crear pago QR
     */
    private function createQRPayment() {
        try {
            $input = $this->getInputData();
            
            // Validar campos requeridos
            $required_fields = ['amount', 'plan_type'];
            foreach ($required_fields as $field) {
                if (empty($input[$field])) {
                    $this->sendError("Campo requerido: {$field}", 400);
                }
            }
            
            $amount = (float)$input['amount'];
            $plan_type = $input['plan_type'];
            $description = $input['description'] ?? "Pago Plan " . ucfirst($plan_type);
            $callback_url = $input['callback_url'] ?? '';
            
            // Validaciones de monto
            if ($amount < $this->min_qr_amount || $amount > $this->max_qr_amount) {
                $this->sendError("Monto debe estar entre $" . number_format($this->min_qr_amount, 2) . " y $" . number_format($this->max_qr_amount, 2), 400);
            }
            
            // Obtener usuario
            $user = $this->getUserById($this->user_id);
            if (!$user) {
                $this->sendError('Usuario no encontrado', 404);
            }
            
            // Verificar que no tenga QR pendientes
            $pending_qr = $this->getPendingQRByUser($this->user_id);
            if ($pending_qr) {
                // Cancelar QR pendiente anterior
                $this->cancelQRPaymentById($pending_qr['id']);
            }
            
            // Generar nuevo QR
            $qr_code = $this->generateUniqueQRCode();
            $expires_at = date('Y-m-d H:i:s', strtotime("+{$this->qr_expiration_minutes} minutes"));
            
            // Crear registro
            $qr_payment_id = $this->createQRPaymentRecord($qr_code, $amount, $plan_type, $description, $expires_at, $callback_url);
            
            // Generar datos QR
            $qr_data = $this->generateQRData($qr_code, $amount, $description, $user);
            
            // Crear imagen QR
            $qr_image_url = $this->createQRImage($qr_data, $qr_code);
            
            // Integración con MercadoPago QR si está configurado
            $mp_qr_data = null;
            if ($this->config->get('payments.mercadopago.qr_enabled')) {
                try {
                    $mp_qr_data = $this->createMercadoPagoQR($qr_code, $amount, $description, $user);
                } catch (Exception $e) {
                    $this->logError('Error creando QR en MercadoPago: ' . $e->getMessage());
                }
            }
            
            // Log de creación
            $this->logActivity('qr_payment_created', $qr_payment_id, "Pago QR creado por: $" . number_format($amount, 2));
            
            $this->sendSuccess([
                'qr_payment_id' => $qr_payment_id,
                'qr_code' => $qr_code,
                'qr_data' => $qr_data,
                'qr_image_url' => $qr_image_url,
                'mercadopago_qr' => $mp_qr_data,
                'amount' => $amount,
                'currency' => 'ARS',
                'description' => $description,
                'expires_at' => $expires_at,
                'status' => 'pending',
                'instructions' => 'Escanea el código QR con tu app bancaria para completar el pago'
            ], 201);
            
        } catch (Exception $e) {
            $this->logError('Error en createQRPayment: ' . $e->getMessage());
            $this->sendError('Error al crear pago QR', 500);
        }
    }
    
    /**
     * Obtener estado del pago QR
     */
    private function getQRStatus() {
        try {
            $qr_code = $_GET['qr_code'] ?? '';
            $qr_payment_id = $_GET['qr_payment_id'] ?? '';
            
            if (empty($qr_code) && empty($qr_payment_id)) {
                $this->sendError('Código QR o ID de pago requerido', 400);
            }
            
            // Buscar pago QR
            $qr_payment = null;
            if (!empty($qr_code)) {
                $qr_payment = $this->getQRPaymentByCode($qr_code);
            } else {
                $qr_payment = $this->getQRPaymentById($qr_payment_id);
            }
            
            if (!$qr_payment) {
                $this->sendError('Pago QR no encontrado', 404);
            }
            
            // Verificar permisos
            if ($qr_payment['user_id'] !== $this->user_id && $this->user_type !== 'admin') {
                $this->sendError('Sin permisos para ver este pago', 403);
            }
            
            // Verificar expiración
            $is_expired = strtotime($qr_payment['expires_at']) < time();
            if ($is_expired && $qr_payment['status'] === 'pending') {
                $this->updateQRPaymentStatus($qr_payment['id'], 'expired');
                $qr_payment['status'] = 'expired';
            }
            
            // Calcular tiempo restante
            $time_remaining = $is_expired ? 0 : strtotime($qr_payment['expires_at']) - time();
            
            $this->sendSuccess([
                'qr_payment_id' => $qr_payment['id'],
                'qr_code' => $qr_payment['qr_code'],
                'amount' => (float)$qr_payment['amount'],
                'currency' => $qr_payment['currency'],
                'status' => $qr_payment['status'],
                'description' => $qr_payment['description'],
                'plan_type' => $qr_payment['plan_type'],
                'created_at' => $qr_payment['created_at'],
                'expires_at' => $qr_payment['expires_at'],
                'processed_at' => $qr_payment['processed_at'],
                'is_expired' => $is_expired,
                'time_remaining_seconds' => max(0, $time_remaining),
                'qr_image_url' => $this->getQRImageUrl($qr_payment['qr_code'])
            ]);
            
        } catch (Exception $e) {
            $this->logError('Error en getQRStatus: ' . $e->getMessage());
            $this->sendError('Error al obtener estado del QR', 500);
        }
    }
    
    /**
     * Procesar pago QR
     */
    private function processQRPayment() {
        try {
            $input = $this->getInputData();
            
            $qr_code = $input['qr_code'] ?? '';
            $bank_transaction_id = $input['bank_transaction_id'] ?? '';
            $payment_proof = $input['payment_proof'] ?? '';
            
            if (empty($qr_code)) {
                $this->sendError('Código QR requerido', 400);
            }
            
            // Buscar pago QR
            $qr_payment = $this->getQRPaymentByCode($qr_code);
            if (!$qr_payment) {
                $this->sendError('Código QR no válido', 404);
            }
            
            // Verificar estado
            if ($qr_payment['status'] !== 'pending') {
                $this->sendError('El código QR ya fue procesado o expiró', 400);
            }
            
            // Verificar expiración
            if (strtotime($qr_payment['expires_at']) < time()) {
                $this->updateQRPaymentStatus($qr_payment['id'], 'expired');
                $this->sendError('El código QR ha expirado', 400);
            }
            
            // Simular procesamiento bancario (en producción integrar con APIs bancarias)
            $processing_result = $this->simulateBankProcessing($qr_payment, $bank_transaction_id);
            
            if ($processing_result['success']) {
                // Actualizar estado a procesando
                $this->updateQRPaymentStatus($qr_payment['id'], 'processing', $bank_transaction_id);
                
                // En un escenario real, aquí se esperaría confirmación bancaria
                // Por ahora, simular confirmación inmediata
                $this->confirmQRPaymentById($qr_payment['id'], $processing_result);
                
                $this->sendSuccess([
                    'status' => 'success',
                    'message' => 'Pago procesado exitosamente',
                    'qr_payment_id' => $qr_payment['id'],
                    'transaction_id' => $processing_result['transaction_id'],
                    'amount' => $qr_payment['amount']
                ]);
            } else {
                // Actualizar estado a rechazado
                $this->updateQRPaymentStatus($qr_payment['id'], 'rejected', null, $processing_result['error']);
                
                $this->sendError('Pago rechazado: ' . $processing_result['error'], 400);
            }
            
        } catch (Exception $e) {
            $this->logError('Error en processQRPayment: ' . $e->getMessage());
            $this->sendError('Error al procesar pago QR', 500);
        }
    }
    
    /**
     * Confirmar pago QR
     */
    private function confirmQRPayment() {
        try {
            $input = $this->getInputData();
            
            $qr_payment_id = $input['qr_payment_id'] ?? '';
            $bank_transaction_id = $input['bank_transaction_id'] ?? '';
            $confirmation_code = $input['confirmation_code'] ?? '';
            
            if (empty($qr_payment_id)) {
                $this->sendError('ID de pago QR requerido', 400);
            }
            
            $qr_payment = $this->getQRPaymentById($qr_payment_id);
            if (!$qr_payment) {
                $this->sendError('Pago QR no encontrado', 404);
            }
            
            // Verificar permisos (solo admin o sistema pueden confirmar)
            if ($this->user_type !== 'admin' && $this->user_type !== 'system') {
                $this->sendError('Sin permisos para confirmar pagos', 403);
            }
            
            if ($qr_payment['status'] !== 'processing') {
                $this->sendError('Solo se pueden confirmar pagos en procesamiento', 400);
            }
            
            // Confirmar pago
            $confirmation_data = [
                'bank_transaction_id' => $bank_transaction_id,
                'confirmation_code' => $confirmation_code,
                'confirmed_at' => date('Y-m-d H:i:s'),
                'confirmed_by' => $this->user_id
            ];
            
            $this->confirmQRPaymentById($qr_payment_id, $confirmation_data);
            
            $this->sendSuccess([
                'status' => 'success',
                'message' => 'Pago confirmado exitosamente',
                'qr_payment_id' => $qr_payment_id
            ]);
            
        } catch (Exception $e) {
            $this->logError('Error en confirmQRPayment: ' . $e->getMessage());
            $this->sendError('Error al confirmar pago QR', 500);
        }
    }
    
    /**
     * Obtener lista de pagos QR
     */
    private function getQRPaymentsList() {
        try {
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $status = $_GET['status'] ?? 'all';
            $date_from = $_GET['date_from'] ?? '';
            $date_to = $_GET['date_to'] ?? '';
            
            $offset = ($page - 1) * $limit;
            
            // Query base
            $query = "SELECT qp.*, u.name as user_name, u.email as user_email
                     FROM qr_payments qp
                     JOIN users u ON qp.user_id = u.id
                     WHERE 1=1";
            
            $params = [];
            
            // Filtros según tipo de usuario
            if ($this->user_type !== 'admin') {
                $query .= " AND qp.user_id = ?";
                $params[] = $this->user_id;
            }
            
            // Filtros adicionales
            if ($status !== 'all') {
                $query .= " AND qp.status = ?";
                $params[] = $status;
            }
            
            if (!empty($date_from)) {
                $query .= " AND DATE(qp.created_at) >= ?";
                $params[] = $date_from;
            }
            
            if (!empty($date_to)) {
                $query .= " AND DATE(qp.created_at) <= ?";
                $params[] = $date_to;
            }
            
            $query .= " ORDER BY qp.created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->db->prepare($query);
            $qr_payments = $stmt->execute($params)->fetchAll();
            
            // Obtener total para paginación
            $count_query = str_replace('SELECT qp.*, u.name as user_name, u.email as user_email', 'SELECT COUNT(*) as total', $query);
            $count_query = preg_replace('/ORDER BY.*/', '', $count_query);
            $count_query = preg_replace('/LIMIT.*/', '', $count_query);
            
            $count_params = array_slice($params, 0, -2);
            $count_stmt = $this->db->prepare($count_query);
            $total = $count_stmt->execute($count_params)->fetch()['total'];
            
            // Procesar pagos QR
            foreach ($qr_payments as &$qr_payment) {
                $qr_payment['amount'] = (float)$qr_payment['amount'];
                $qr_payment['is_expired'] = strtotime($qr_payment['expires_at']) < time();
                $qr_payment['qr_image_url'] = $this->getQRImageUrl($qr_payment['qr_code']);
                $qr_payment['time_ago'] = $this->getTimeAgo($qr_payment['created_at']);
            }
            
            $this->sendSuccess([
                'qr_payments' => $qr_payments,
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => ceil($total / $limit),
                    'total_records' => (int)$total,
                    'limit' => $limit
                ]
            ]);
            
        } catch (Exception $e) {
            $this->logError('Error en getQRPaymentsList: ' . $e->getMessage());
            $this->sendError('Error al obtener lista de pagos QR', 500);
        }
    }
<!-- 
====================================
SECCIÓN COMPLETADA: API de Pagos QR - Funciones Principales
ARCHIVO: api/payments/qr-payments.php
LÍNEAS: 500
PROGRESO: 1/2 secciones completadas
====================================
SEGUIR AQUÍ
====================================
-->

/**
     * Validar código QR
     */
    private function validateQRCode() {
        try {
            $qr_code = $_GET['qr_code'] ?? '';
            
            if (empty($qr_code)) {
                $this->sendError('Código QR requerido', 400);
            }
            
            $qr_payment = $this->getQRPaymentByCode($qr_code);
            
            $is_valid = false;
            $reason = '';
            
            if (!$qr_payment) {
                $reason = 'Código QR no encontrado';
            } elseif ($qr_payment['status'] !== 'pending') {
                $reason = 'Código QR ya procesado o cancelado';
            } elseif (strtotime($qr_payment['expires_at']) < time()) {
                $reason = 'Código QR expirado';
                $this->updateQRPaymentStatus($qr_payment['id'], 'expired');
            } else {
                $is_valid = true;
                $reason = 'Código QR válido';
            }
            
            $response = [
                'valid' => $is_valid,
                'reason' => $reason
            ];
            
            if ($is_valid && $qr_payment) {
                $response['qr_payment'] = [
                    'id' => $qr_payment['id'],
                    'amount' => (float)$qr_payment['amount'],
                    'currency' => $qr_payment['currency'],
                    'description' => $qr_payment['description'],
                    'expires_at' => $qr_payment['expires_at'],
                    'time_remaining' => strtotime($qr_payment['expires_at']) - time()
                ];
            }
            
            $this->sendSuccess($response);
            
        } catch (Exception $e) {
            $this->logError('Error en validateQRCode: ' . $e->getMessage());
            $this->sendError('Error al validar código QR', 500);
        }
    }
    
    /**
     * Obtener bancos soportados
     */
    private function getSupportedBanks() {
        try {
            $banks = [
                [
                    'id' => 'banco_nacion',
                    'name' => 'Banco de la Nación Argentina',
                    'logo' => '/assets/images/banks/bna.png',
                    'qr_supported' => true,
                    'app_name' => 'BNA+'
                ],
                [
                    'id' => 'banco_provincia',
                    'name' => 'Banco de la Provincia de Buenos Aires',
                    'logo' => '/assets/images/banks/bapro.png',
                    'qr_supported' => true,
                    'app_name' => 'BAPRO'
                ],
                [
                    'id' => 'santander',
                    'name' => 'Banco Santander',
                    'logo' => '/assets/images/banks/santander.png',
                    'qr_supported' => true,
                    'app_name' => 'Santander Argentina'
                ],
                [
                    'id' => 'galicia',
                    'name' => 'Banco Galicia',
                    'logo' => '/assets/images/banks/galicia.png',
                    'qr_supported' => true,
                    'app_name' => 'Galicia Move'
                ],
                [
                    'id' => 'bbva',
                    'name' => 'BBVA Argentina',
                    'logo' => '/assets/images/banks/bbva.png',
                    'qr_supported' => true,
                    'app_name' => 'BBVA Argentina'
                ],
                [
                    'id' => 'macro',
                    'name' => 'Banco Macro',
                    'logo' => '/assets/images/banks/macro.png',
                    'qr_supported' => true,
                    'app_name' => 'Macro Móvil'
                ],
                [
                    'id' => 'mercadopago',
                    'name' => 'Mercado Pago',
                    'logo' => '/assets/images/banks/mercadopago.png',
                    'qr_supported' => true,
                    'app_name' => 'Mercado Pago'
                ]
            ];
            
            $this->sendSuccess([
                'banks' => $banks,
                'total_banks' => count($banks),
                'qr_enabled_banks' => count(array_filter($banks, function($bank) {
                    return $bank['qr_supported'];
                }))
            ]);
            
        } catch (Exception $e) {
            $this->logError('Error en getSupportedBanks: ' . $e->getMessage());
            $this->sendError('Error al obtener bancos soportados', 500);
        }
    }
    
    /**
     * Cancelar pago QR
     */
    private function cancelQRPayment() {
        try {
            $qr_payment_id = $_GET['qr_payment_id'] ?? '';
            $input = $this->getInputData();
            $reason = $input['reason'] ?? 'Cancelado por usuario';
            
            if (empty($qr_payment_id)) {
                $this->sendError('ID de pago QR requerido', 400);
            }
            
            $qr_payment = $this->getQRPaymentById($qr_payment_id);
            if (!$qr_payment) {
                $this->sendError('Pago QR no encontrado', 404);
            }
            
            // Verificar permisos
            if ($qr_payment['user_id'] !== $this->user_id && $this->user_type !== 'admin') {
                $this->sendError('Sin permisos para cancelar este pago', 403);
            }
            
            if (!in_array($qr_payment['status'], ['pending', 'processing'])) {
                $this->sendError('No se puede cancelar este pago en su estado actual', 400);
            }
            
            // Cancelar pago
            $this->updateQRPaymentStatus($qr_payment['id'], 'cancelled', null, $reason);
            
            $this->logActivity('qr_payment_cancelled', $qr_payment['id'], "Pago QR cancelado: {$reason}");
            
            $this->sendSuccess([
                'status' => 'success',
                'message' => 'Pago QR cancelado exitosamente',
                'qr_payment_id' => $qr_payment['id']
            ]);
            
        } catch (Exception $e) {
            $this->logError('Error en cancelQRPayment: ' . $e->getMessage());
            $this->sendError('Error al cancelar pago QR', 500);
        }
    }
    
    /**
     * Extender expiración del QR
     */
    private function extendQRExpiration() {
        try {
            $qr_payment_id = $_GET['qr_payment_id'] ?? '';
            $input = $this->getInputData();
            $additional_minutes = (int)($input['additional_minutes'] ?? 10);
            
            if (empty($qr_payment_id)) {
                $this->sendError('ID de pago QR requerido', 400);
            }
            
            if ($additional_minutes < 1 || $additional_minutes > 30) {
                $this->sendError('Tiempo adiconal debe estar entre 1 y 30 minutos', 400);
            }
            
            $qr_payment = $this->getQRPaymentById($qr_payment_id);
            if (!$qr_payment) {
                $this->sendError('Pago QR no encontrado', 404);
            }
            
            // Verificar permisos
            if ($qr_payment['user_id'] !== $this->user_id && $this->user_type !== 'admin') {
                $this->sendError('Sin permisos para extender este pago', 403);
            }
            
            if ($qr_payment['status'] !== 'pending') {
                $this->sendError('Solo se puede extender pagos pendientes', 400);
            }
            
            // Calcular nueva fecha de expiración
            $current_expiry = strtotime($qr_payment['expires_at']);
            $new_expiry = max($current_expiry, time()) + ($additional_minutes * 60);
            $new_expiry_date = date('Y-m-d H:i:s', $new_expiry);
            
            // Actualizar expiración
            $stmt = $this->db->prepare("
                UPDATE qr_payments 
                SET expires_at = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$new_expiry_date, $qr_payment_id]);
            
            $this->logActivity('qr_expiration_extended', $qr_payment_id, "Expiración extendida {$additional_minutes} minutos");
            
            $this->sendSuccess([
                'status' => 'success',
                'message' => 'Expiración extendida exitosamente',
                'new_expires_at' => $new_expiry_date,
                'additional_minutes' => $additional_minutes
            ]);
            
        } catch (Exception $e) {
            $this->logError('Error en extendQRExpiration: ' . $e->getMessage());
            $this->sendError('Error al extender expiración', 500);
        }
    }
    
    // MÉTODOS AUXILIARES PRIVADOS
    
    /**
     * Obtener usuario por ID
     */
    private function getUserById($id) {
        $stmt = $this->db->prepare("
            SELECT id, name, email, phone, business_name, plan_type
            FROM users 
            WHERE id = ? AND status = 'active'
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    /**
     * Generar código QR único
     */
    private function generateUniqueQRCode() {
        do {
            $qr_code = 'QR' . date('Ymd') . '_' . strtoupper(substr(md5(uniqid()), 0, 10));
            $exists = $this->getQRPaymentByCode($qr_code);
        } while ($exists);
        
        return $qr_code;
    }
    
    /**
     * Crear registro de pago QR
     */
    private function createQRPaymentRecord($qr_code, $amount, $plan_type, $description, $expires_at, $callback_url = '') {
        $stmt = $this->db->prepare("
            INSERT INTO qr_payments (
                user_id, qr_code, amount, currency, plan_type, description,
                status, expires_at, callback_url, created_at, updated_at
            ) VALUES (?, ?, ?, 'ARS', ?, ?, 'pending', ?, ?, NOW(), NOW())
        ");
        
        $stmt->execute([
            $this->user_id, $qr_code, $amount, $plan_type, 
            $description, $expires_at, $callback_url
        ]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Generar datos para el código QR
     */
    private function generateQRData($qr_code, $amount, $description, $user) {
        return json_encode([
            'version' => '1.0',
            'qr_code' => $qr_code,
            'amount' => $amount,
            'currency' => 'ARS',
            'description' => $description,
            'merchant' => [
                'name' => 'ReservaFácil',
                'id' => 'RF_' . $user['id'],
                'email' => $user['email']
            ],
            'expires_at' => date('c', strtotime("+{$this->qr_expiration_minutes} minutes")),
            'callback_url' => $this->config->get('app.url') . '/api/payments/qr-payments.php?action=process'
        ], JSON_UNESCAPED_UNICODE);
    }
    
    /**
     * Crear imagen QR
     */
    private function createQRImage($qr_data, $qr_code) {
        // En producción, usar una librería como QR Code Generator
        // Por ahora, retornar URL simulada
        $qr_directory = $this->config->get('storage.qr_path', '/storage/qr/');
        
        if (!is_dir($qr_directory)) {
            mkdir($qr_directory, 0755, true);
        }
        
        $filename = $qr_code . '.png';
        $file_path = $qr_directory . $filename;
        
        // Simular creación de imagen QR (en producción usar librería real)
        $image_content = $this->generateQRImageContent($qr_data);
        file_put_contents($file_path, $image_content);
        
        return $this->config->get('app.url') . '/storage/qr/' . $filename;
    }
    
    /**
     * Generar contenido de imagen QR simulado
     */
    private function generateQRImageContent($qr_data) {
        // En producción, usar librería como chillerlan/php-qrcode
        // Por ahora, crear imagen simple
        $image = imagecreate(200, 200);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        
        // Fondo blanco
        imagefill($image, 0, 0, $white);
        
        // Patrón QR simulado
        for ($i = 0; $i < 20; $i++) {
            for ($j = 0; $j < 20; $j++) {
                if (rand(0, 1)) {
                    imagefilledrectangle($image, $i*10, $j*10, ($i+1)*10, ($j+1)*10, $black);
                }
            }
        }
        
        ob_start();
        imagepng($image);
        $content = ob_get_contents();
        ob_end_clean();
        imagedestroy($image);
        
        return $content;
    }
    
    /**
     * Obtener pago QR por código
     */
    private function getQRPaymentByCode($qr_code) {
        $stmt = $this->db->prepare("
            SELECT * FROM qr_payments WHERE qr_code = ?
        ");
        $stmt->execute([$qr_code]);
        return $stmt->fetch();
    }
    
    /**
     * Obtener pago QR por ID
     */
    private function getQRPaymentById($id) {
        $stmt = $this->db->prepare("
            SELECT * FROM qr_payments WHERE id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    /**
     * Obtener QR pendiente por usuario
     */
    private function getPendingQRByUser($user_id) {
        $stmt = $this->db->prepare("
            SELECT * FROM qr_payments 
            WHERE user_id = ? AND status = 'pending' AND expires_at > NOW()
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetch();
    }
    
    /**
     * Actualizar estado de pago QR
     */
    private function updateQRPaymentStatus($id, $status, $bank_transaction_id = null, $notes = null) {
        $stmt = $this->db->prepare("
            UPDATE qr_payments 
            SET status = ?, bank_transaction_id = ?, notes = ?, 
                processed_at = CASE WHEN ? IN ('completed', 'rejected', 'cancelled') THEN NOW() ELSE processed_at END,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$status, $bank_transaction_id, $notes, $status, $id]);
    }
    
    /**
     * Simular procesamiento bancario
     */
    private function simulateBankProcessing($qr_payment, $bank_transaction_id) {
        // En producción, integrar con APIs bancarias reales
        // Por ahora, simular respuesta
        
        $success_rate = 0.85; // 85% de éxito
        $is_successful = (rand(1, 100) / 100) <= $success_rate;
        
        if ($is_successful) {
            return [
                'success' => true,
                'transaction_id' => $bank_transaction_id ?: 'TXN_' . time() . '_' . rand(1000, 9999),
                'bank_reference' => 'REF_' . strtoupper(substr(md5(uniqid()), 0, 8)),
                'processed_at' => date('Y-m-d H:i:s')
            ];
        } else {
            $errors = [
                'Fondos insuficientes',
                'Error de comunicación bancaria',
                'Transacción rechazada por el banco',
                'Límite diario excedido',
                'Cuenta bloqueada temporalmente'
            ];
            
            return [
                'success' => false,
                'error' => $errors[array_rand($errors)]
            ];
        }
    }
    
    /**
     * Confirmar pago QR por ID
     */
    private function confirmQRPaymentById($qr_payment_id, $confirmation_data) {
        // Actualizar estado a completado
        $this->updateQRPaymentStatus($qr_payment_id, 'completed', $confirmation_data['bank_transaction_id'] ?? null);
        
        // Obtener datos del pago
        $qr_payment = $this->getQRPaymentById($qr_payment_id);
        
        // Actualizar plan del usuario
        $this->updateUserPlan($qr_payment['user_id'], $qr_payment['plan_type']);
        
        // Enviar notificaciones
        $this->sendPaymentNotifications($qr_payment, 'completed');
        
        // Log de confirmación
        $this->logActivity('qr_payment_confirmed', $qr_payment_id, 'Pago QR confirmado exitosamente');
    }
    
    /**
     * Actualizar plan del usuario
     */
    private function updateUserPlan($user_id, $plan_type) {
        $expiration_date = date('Y-m-d H:i:s', strtotime('+1 month'));
        
        $stmt = $this->db->prepare("
            UPDATE users 
            SET plan_type = ?, plan_expires = ?, status = 'active', updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$plan_type, $expiration_date, $user_id]);
    }
    
    /**
     * Enviar notificaciones de pago
     */
    private function sendPaymentNotifications($qr_payment, $status) {
        $user = $this->getUserById($qr_payment['user_id']);
        
        if ($user && !empty($user['email'])) {
            // Crear notificación de email
            $this->createNotification([
                'user_id' => $user['id'],
                'type' => 'email',
                'category' => 'payment',
                'recipient' => $user['email'],
                'subject' => 'Pago QR ' . ($status === 'completed' ? 'confirmado' : 'actualizado'),
                'template' => 'qr_payment_' . $status,
                'template_data' => [
                    'user_name' => $user['name'],
                    'amount' => $qr_payment['amount'],
                    'plan_type' => $qr_payment['plan_type'],
                    'qr_code' => $qr_payment['qr_code']
                ]
            ]);
        }
    }
    
    /**
     * Crear notificación
     */
    private function createNotification($data) {
        $stmt = $this->db->prepare("
            INSERT INTO notifications (
                user_id, type, category, recipient, subject, template, template_data, 
                status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        
        $stmt->execute([
            $data['user_id'], $data['type'], $data['category'], $data['recipient'],
            $data['subject'], $data['template'], json_encode($data['template_data'])
        ]);
    }
    
    /**
     * Crear QR en MercadoPago
     */
    private function createMercadoPagoQR($qr_code, $amount, $description, $user) {
        // Implementar integración con MercadoPago QR API
        // Por ahora retornar estructura simulada
        return [
            'qr_data' => 'https://www.mercadopago.com/instore/merchant/qr/' . $qr_code,
            'in_store_order_id' => 'MP_' . $qr_code,
            'status' => 'active'
        ];
    }
    
    /**
     * Cancelar pago QR por ID
     */
    private function cancelQRPaymentById($id) {
        $this->updateQRPaymentStatus($id, 'cancelled', null, 'Cancelado automáticamente por nuevo QR');
    }
    
    /**
     * Obtener URL de imagen QR
     */
    private function getQRImageUrl($qr_code) {
        return $this->config->get('app.url') . '/storage/qr/' . $qr_code . '.png';
    }
    
    /**
     * Calcular tiempo transcurrido
     */
    private function getTimeAgo($datetime) {
        $time = time() - strtotime($datetime);
        
        if ($time < 60) return 'Hace ' . $time . ' segundos';
        if ($time < 3600) return 'Hace ' . floor($time / 60) . ' minutos';
        if ($time < 86400) return 'Hace ' . floor($time / 3600) . ' horas';
        return 'Hace ' . floor($time / 86400) . ' días';
    }
    
    /**
     * Obtener datos de entrada JSON
     */
    private function getInputData() {
        $input = file_get_contents('php://input');
        return json_decode($input, true) ?? [];
    }
    
    /**
     * Enviar respuesta exitosa
     */
    private function sendSuccess($data, $status_code = 200) {
        http_response_code($status_code);
        echo json_encode([
            'success' => true,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * Enviar respuesta de error
     */
    private function sendError($message, $status_code = 400) {
        http_response_code($status_code);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'error_code' => $status_code,
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * Registrar actividad
     */
    private function logActivity($action, $qr_payment_id, $description) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO activity_logs (
                    user_id, action, resource_type, resource_id, description, 
                    ip_address, user_agent, created_at
                ) VALUES (?, ?, 'qr_payment', ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $this->user_id, $action, $qr_payment_id, $description,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            error_log("Error logging activity: " . $e->getMessage());
        }
    }
    
    /**
     * Registrar errores
     */
    private function logError($message) {
        $log_message = "[QR PAYMENTS API] " . date('Y-m-d H:i:s') . " - USER_ID: {$this->user_id} - " . $message;
        error_log($log_message);
        
        if ($this->config->get('environment') === 'production') {
            // Enviar a sistema de monitoreo en producción
        }
    }
}

// Inicializar y procesar solicitud
try {
    $qr_payments_manager = new QRPaymentsManager();
    $qr_payments_manager->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error interno del servidor',
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    
    error_log("[QR PAYMENTS API FATAL] " . date('Y-m-d H:i:s') . " - " . $e->getMessage());
}
?>
<!-- 
====================================
SECCIÓN COMPLETADA: API de Pagos QR - Sección 2 Final (Métodos Auxiliares Completa)
ARCHIVO: api/payments/qr-payments.php
LÍNEAS: 500
PROGRESO: 2/2 secciones completadas - ARCHIVO TERMINADO
====================================
SEGUIR AQUÍ
====================================
-->