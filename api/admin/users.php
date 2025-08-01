<?php
/**
 * Sistema de Reservas - Panel de Administrador
 * API REST para Gestión de Usuarios
 * 
 * Funcionalidades:
 * - CRUD completo de usuarios
 * - Gestión de planes y suscripciones
 * - Suspensión y activación de cuentas
 * - Logs de actividad
 * - Exportación de datos
 * 
 * @author Sistema de Reservas
 * @version 1.0
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Configuración de base de datos
class Database {
    private $host = 'localhost';
    private $db_name = 'reservas_system';
    private $username = 'root';
    private $password = '';
    private $conn;
    
    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch(PDOException $e) {
            error_log("Error de conexión: " . $e->getMessage());
        }
        return $this->conn;
    }
}

// Clase principal para gestión de usuarios
class UserManager {
    private $conn;
    private $table_name = "users";
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    // Validar token JWT
    private function validateToken($token) {
        // Para simplicidad, usamos una validación básica
        // En producción usar una librería JWT completa
        $admin_credentials = base64_encode('lmitrion230:Lelouch426753.');
        return $token === "Bearer " . $admin_credentials;
    }
    
    // Registrar actividad en logs
    private function logActivity($action, $description, $user_id = null, $metadata = []) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO admin_logs (action, description, user, user_id, metadata, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $action,
                $description,
                'lmitrion230',
                $user_id,
                json_encode($metadata),
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
        } catch(Exception $e) {
            error_log("Error logging activity: " . $e->getMessage());
        }
    }
    
    // Respuesta JSON estructurada
    private function jsonResponse($success, $data = null, $message = '', $pagination = null, $status_code = 200) {
        http_response_code($status_code);
        
        $response = [
            'success' => $success,
            'message' => $message,
            'timestamp' => date('c')
        ];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        if ($pagination !== null) {
            $response['pagination'] = $pagination;
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Obtener todos los usuarios con filtros y paginación
    public function getUsers($filters = []) {
        try {
            $page = isset($filters['page']) ? max(1, intval($filters['page'])) : 1;
            $per_page = isset($filters['per_page']) ? min(100, max(1, intval($filters['per_page']))) : 25;
            $offset = ($page - 1) * $per_page;
            
            // Construir query base
            $query = "SELECT * FROM " . $this->table_name . " WHERE 1=1";
            $count_query = "SELECT COUNT(*) as total FROM " . $this->table_name . " WHERE 1=1";
            $params = [];
            
            // Aplicar filtros
            if (!empty($filters['search'])) {
                $search_condition = " AND (name LIKE ? OR owner LIKE ? OR email LIKE ?)";
                $query .= $search_condition;
                $count_query .= $search_condition;
                $search_term = '%' . $filters['search'] . '%';
                $params[] = $search_term;
                $params[] = $search_term;
                $params[] = $search_term;
            }
            
            if (!empty($filters['plan'])) {
                $query .= " AND plan = ?";
                $count_query .= " AND plan = ?";
                $params[] = $filters['plan'];
            }
            
            if (!empty($filters['status'])) {
                $query .= " AND status = ?";
                $count_query .= " AND status = ?";
                $params[] = $filters['status'];
            }
            
            if (!empty($filters['region'])) {
                $query .= " AND region LIKE ?";
                $count_query .= " AND region LIKE ?";
                $params[] = '%' . $filters['region'] . '%';
            }
            
            // Ordenamiento
            $order_by = isset($filters['order_by']) ? $filters['order_by'] : 'created_at';
            $order_dir = isset($filters['order_dir']) && strtoupper($filters['order_dir']) === 'ASC' ? 'ASC' : 'DESC';
            
            $allowed_columns = ['id', 'name', 'email', 'plan', 'status', 'created_at', 'revenue', 'reservations'];
            if (!in_array($order_by, $allowed_columns)) {
                $order_by = 'created_at';
            }
            
            $query .= " ORDER BY $order_by $order_dir LIMIT $per_page OFFSET $offset";
            
            // Ejecutar consulta principal
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $users = $stmt->fetchAll();
            
            // Obtener total de registros
            $count_stmt = $this->conn->prepare($count_query);
            $count_stmt->execute($params);
            $total_records = $count_stmt->fetch()['total'];
            
            // Formatear datos de usuarios
            foreach ($users as &$user) {
                $user['created_at'] = date('c', strtotime($user['created_at']));
                $user['last_login'] = $user['last_login'] ? date('c', strtotime($user['last_login'])) : null;
                $user['expires_at'] = $user['expires_at'] ? date('c', strtotime($user['expires_at'])) : null;
                $user['revenue'] = floatval($user['revenue']);
                $user['total_revenue'] = floatval($user['total_revenue']);
                $user['monthly_growth'] = floatval($user['monthly_growth']);
                $user['reservations'] = intval($user['reservations']);
            }
            
            // Información de paginación
            $pagination = [
                'current_page' => $page,
                'per_page' => $per_page,
                'total_records' => intval($total_records),
                'total_pages' => ceil($total_records / $per_page),
                'has_next' => $page < ceil($total_records / $per_page),
                'has_prev' => $page > 1
            ];
            
            $this->logActivity('users_list', 'Lista de usuarios consultada', null, $filters);
            
            return $this->jsonResponse(true, $users, 'Usuarios obtenidos exitosamente', $pagination);
            
        } catch(Exception $e) {
            error_log("Error obteniendo usuarios: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Obtener usuario específico por ID
    public function getUserById($id) {
        try {
            $stmt = $this->conn->prepare("SELECT * FROM " . $this->table_name . " WHERE id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return $this->jsonResponse(false, null, 'Usuario no encontrado', null, 404);
            }
            
            // Formatear datos
            $user['created_at'] = date('c', strtotime($user['created_at']));
            $user['last_login'] = $user['last_login'] ? date('c', strtotime($user['last_login'])) : null;
            $user['expires_at'] = $user['expires_at'] ? date('c', strtotime($user['expires_at'])) : null;
            $user['revenue'] = floatval($user['revenue']);
            $user['total_revenue'] = floatval($user['total_revenue']);
            $user['monthly_growth'] = floatval($user['monthly_growth']);
            $user['reservations'] = intval($user['reservations']);
            
            // Obtener logs recientes del usuario
            $logs_stmt = $this->conn->prepare("
                SELECT action, description, created_at 
                FROM admin_logs 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT 10
            ");
            $logs_stmt->execute([$id]);
            $user['recent_logs'] = $logs_stmt->fetchAll();
            
            $this->logActivity('user_view', "Usuario $id consultado", $id);
            
            return $this->jsonResponse(true, $user, 'Usuario obtenido exitosamente');
            
        } catch(Exception $e) {
            error_log("Error obteniendo usuario: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Crear nuevo usuario
    public function createUser($data) {
        try {
            // Validar datos requeridos
            $required_fields = ['name', 'owner', 'email'];
            foreach ($required_fields as $field) {
                if (empty($data[$field])) {
                    return $this->jsonResponse(false, null, "El campo $field es requerido", null, 400);
                }
            }
            
            // Validar email
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return $this->jsonResponse(false, null, 'Email inválido', null, 400);
            }
            
            // Verificar si el email ya existe
            $stmt = $this->conn->prepare("SELECT id FROM " . $this->table_name . " WHERE email = ?");
            $stmt->execute([$data['email']]);
            if ($stmt->fetch()) {
                return $this->jsonResponse(false, null, 'El email ya está registrado', null, 409);
            }
            
            // Preparar datos para inserción
            $plan = isset($data['plan']) && in_array($data['plan'], ['free', 'basic', 'premium', 'enterprise']) 
                ? $data['plan'] : 'free';
            $status = isset($data['status']) && in_array($data['status'], ['active', 'inactive', 'suspended', 'expired']) 
                ? $data['status'] : 'active';
            
            $expires_at = null;
            if ($plan !== 'free') {
                $expires_at = date('Y-m-d', strtotime('+1 month'));
            }
            
            // Insertar usuario
            $stmt = $this->conn->prepare("
                INSERT INTO " . $this->table_name . " 
                (name, owner, email, phone, plan, status, region, expires_at, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['name'],
                $data['owner'],
                $data['email'],
                $data['phone'] ?? null,
                $plan,
                $status,
                $data['region'] ?? null,
                $expires_at,
                $data['notes'] ?? null
            ]);
            
            $user_id = $this->conn->lastInsertId();
            
            $this->logActivity('user_created', "Usuario creado: {$data['name']} ({$data['email']})", $user_id, $data);
            
            // Obtener el usuario creado
            return $this->getUserById($user_id);
            
        } catch(Exception $e) {
            error_log("Error creando usuario: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
}

// Inicializar datos mock si la tabla está vacía
function initializeMockData($conn) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users");
        $stmt->execute();
        $count = $stmt->fetch()['count'];
        
        if ($count == 0) {
            $mock_users = [
                [
                    'name' => 'Restaurante El Sabor',
                    'owner' => 'María González',
                    'email' => 'maria@elsabor.com',
                    'phone' => '+34 912 345 678',
                    'plan' => 'premium',
                    'status' => 'active',
                    'region' => 'Madrid, España',
                    'revenue' => 2850.00,
                    'total_revenue' => 15420.00,
                    'reservations' => 234,
                    'monthly_growth' => 12.5,
                    'expires_at' => date('Y-m-d', strtotime('+2 months')),
                    'last_login' => date('Y-m-d H:i:s', strtotime('-2 hours')),
                    'notes' => 'Cliente premium con excelente historial de pagos'
                ],
                [
                    'name' => 'Café Central',
                    'owner' => 'Juan Pérez',
                    'email' => 'juan@cafecentral.com',
                    'phone' => '+34 915 678 901',
                    'plan' => 'basic',
                    'status' => 'active',
                    'region' => 'Barcelona, España',
                    'revenue' => 890.00,
                    'total_revenue' => 4560.00,
                    'reservations' => 89,
                    'monthly_growth' => 8.2,
                    'expires_at' => date('Y-m-d', strtotime('+15 days')),
                    'last_login' => date('Y-m-d H:i:s', strtotime('-1 day')),
                    'notes' => 'Necesita renovar pronto'
                ]
            ];
            
            foreach ($mock_users as $user) {
                $stmt = $conn->prepare("
                    INSERT INTO users 
                    (name, owner, email, phone, plan, status, region, revenue, total_revenue, 
                     reservations, monthly_growth, expires_at, last_login, notes, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $user['name'], $user['owner'], $user['email'], $user['phone'],
                    $user['plan'], $user['status'], $user['region'], $user['revenue'],
                    $user['total_revenue'], $user['reservations'], $user['monthly_growth'],
                    $user['expires_at'], $user['last_login'], $user['notes']
                ]);
            }
        }
    } catch(Exception $e) {
        error_log("Error inicializando datos mock: " . $e->getMessage());
    }
}

// <--- seguir aqui --->

// Continuación de users.php - Parte 2 de 4
// Métodos adicionales de la clase UserManager

    // Actualizar usuario existente
    public function updateUser($id, $data) {
        try {
            // Verificar que el usuario existe
            $stmt = $this->conn->prepare("SELECT * FROM " . $this->table_name . " WHERE id = ?");
            $stmt->execute([$id]);
            $existing_user = $stmt->fetch();
            
            if (!$existing_user) {
                return $this->jsonResponse(false, null, 'Usuario no encontrado', null, 404);
            }
            
            // Validar email si se está actualizando
            if (isset($data['email']) && $data['email'] !== $existing_user['email']) {
                if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                    return $this->jsonResponse(false, null, 'Email inválido', null, 400);
                }
                
                // Verificar si el nuevo email ya existe
                $stmt = $this->conn->prepare("SELECT id FROM " . $this->table_name . " WHERE email = ? AND id != ?");
                $stmt->execute([$data['email'], $id]);
                if ($stmt->fetch()) {
                    return $this->jsonResponse(false, null, 'El email ya está registrado', null, 409);
                }
            }
            
            // Preparar campos a actualizar
            $update_fields = [];
            $params = [];
            
            $allowed_fields = ['name', 'owner', 'email', 'phone', 'region', 'notes', 'revenue', 'total_revenue', 'reservations', 'monthly_growth'];
            
            foreach ($allowed_fields as $field) {
                if (isset($data[$field])) {
                    $update_fields[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }
            
            // Validar y actualizar plan si se proporciona
            if (isset($data['plan'])) {
                if (in_array($data['plan'], ['free', 'basic', 'premium', 'enterprise'])) {
                    $update_fields[] = "plan = ?";
                    $params[] = $data['plan'];
                    
                    // Actualizar fecha de expiración según el plan
                    if ($data['plan'] === 'free') {
                        $update_fields[] = "expires_at = NULL";
                    } else {
                        $update_fields[] = "expires_at = ?";
                        $params[] = date('Y-m-d', strtotime('+1 month'));
                    }
                } else {
                    return $this->jsonResponse(false, null, 'Plan inválido', null, 400);
                }
            }
            
            // Validar y actualizar status si se proporciona
            if (isset($data['status'])) {
                if (in_array($data['status'], ['active', 'inactive', 'suspended', 'expired'])) {
                    $update_fields[] = "status = ?";
                    $params[] = $data['status'];
                } else {
                    return $this->jsonResponse(false, null, 'Status inválido', null, 400);
                }
            }
            
            if (empty($update_fields)) {
                return $this->jsonResponse(false, null, 'No hay campos para actualizar', null, 400);
            }
            
            // Ejecutar actualización
            $params[] = $id;
            $query = "UPDATE " . $this->table_name . " SET " . implode(', ', $update_fields) . " WHERE id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            $this->logActivity('user_updated', "Usuario $id actualizado", $id, $data);
            
            // Retornar usuario actualizado
            return $this->getUserById($id);
            
        } catch(Exception $e) {
            error_log("Error actualizando usuario: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Eliminar usuario
    public function deleteUser($id) {
        try {
            // Verificar que el usuario existe
            $stmt = $this->conn->prepare("SELECT name, email FROM " . $this->table_name . " WHERE id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return $this->jsonResponse(false, null, 'Usuario no encontrado', null, 404);
            }
            
            // Eliminar usuario
            $stmt = $this->conn->prepare("DELETE FROM " . $this->table_name . " WHERE id = ?");
            $stmt->execute([$id]);
            
            $this->logActivity('user_deleted', "Usuario eliminado: {$user['name']} ({$user['email']})", $id);
            
            return $this->jsonResponse(true, null, 'Usuario eliminado exitosamente');
            
        } catch(Exception $e) {
            error_log("Error eliminando usuario: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Suspender usuario
    public function suspendUser($id, $reason = '') {
        try {
            // Verificar que el usuario existe y no está ya suspendido
            $stmt = $this->conn->prepare("SELECT name, email, status FROM " . $this->table_name . " WHERE id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return $this->jsonResponse(false, null, 'Usuario no encontrado', null, 404);
            }
            
            if ($user['status'] === 'suspended') {
                return $this->jsonResponse(false, null, 'El usuario ya está suspendido', null, 400);
            }
            
            // Suspender usuario
            $stmt = $this->conn->prepare("UPDATE " . $this->table_name . " SET status = 'suspended' WHERE id = ?");
            $stmt->execute([$id]);
            
            // Actualizar notas con el motivo de suspensión
            if (!empty($reason)) {
                $stmt = $this->conn->prepare("UPDATE " . $this->table_name . " SET notes = CONCAT(COALESCE(notes, ''), '\n[SUSPENDIDO] ', ?, ' - ', NOW()) WHERE id = ?");
                $stmt->execute([$reason, $id]);
            }
            
            $this->logActivity('user_suspended', "Usuario suspendido: {$user['name']} - Motivo: $reason", $id, ['reason' => $reason]);
            
            return $this->jsonResponse(true, null, 'Usuario suspendido exitosamente');
            
        } catch(Exception $e) {
            error_log("Error suspendiendo usuario: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Activar usuario
    public function activateUser($id) {
        try {
            // Verificar que el usuario existe
            $stmt = $this->conn->prepare("SELECT name, email, status FROM " . $this->table_name . " WHERE id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return $this->jsonResponse(false, null, 'Usuario no encontrado', null, 404);
            }
            
            if ($user['status'] === 'active') {
                return $this->jsonResponse(false, null, 'El usuario ya está activo', null, 400);
            }
            
            // Activar usuario
            $stmt = $this->conn->prepare("UPDATE " . $this->table_name . " SET status = 'active' WHERE id = ?");
            $stmt->execute([$id]);
            
            // Agregar nota de activación
            $stmt = $this->conn->prepare("UPDATE " . $this->table_name . " SET notes = CONCAT(COALESCE(notes, ''), '\n[ACTIVADO] ', NOW()) WHERE id = ?");
            $stmt->execute([$id]);
            
            $this->logActivity('user_activated', "Usuario activado: {$user['name']}", $id);
            
            return $this->jsonResponse(true, null, 'Usuario activado exitosamente');
            
        } catch(Exception $e) {
            error_log("Error activando usuario: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Cambiar plan de usuario
    public function changePlan($id, $new_plan, $extend_months = 1) {
        try {
            // Validar plan
            if (!in_array($new_plan, ['free', 'basic', 'premium', 'enterprise'])) {
                return $this->jsonResponse(false, null, 'Plan inválido', null, 400);
            }
            
            // Verificar que el usuario existe
            $stmt = $this->conn->prepare("SELECT name, email, plan FROM " . $this->table_name . " WHERE id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return $this->jsonResponse(false, null, 'Usuario no encontrado', null, 404);
            }
            
            // Calcular nueva fecha de expiración
            $expires_at = null;
            if ($new_plan !== 'free') {
                $extends_months = max(1, intval($extend_months));
                $expires_at = date('Y-m-d', strtotime("+{$extends_months} months"));
            }
            
            // Actualizar plan
            $stmt = $this->conn->prepare("
                UPDATE " . $this->table_name . " 
                SET plan = ?, expires_at = ?, status = 'active' 
                WHERE id = ?
            ");
            $stmt->execute([$new_plan, $expires_at, $id]);
            
            // Agregar nota del cambio
            $stmt = $this->conn->prepare("UPDATE " . $this->table_name . " SET notes = CONCAT(COALESCE(notes, ''), '\n[PLAN CAMBIADO] De ', ?, ' a ', ?, ' - ', NOW()) WHERE id = ?");
            $stmt->execute([$user['plan'], $new_plan, $id]);
            
            $this->logActivity('plan_changed', "Plan cambiado para {$user['name']}: {$user['plan']} → $new_plan", $id, [
                'old_plan' => $user['plan'],
                'new_plan' => $new_plan,
                'extend_months' => $extend_months
            ]);
            
            return $this->jsonResponse(true, null, "Plan cambiado exitosamente a $new_plan");
            
        } catch(Exception $e) {
            error_log("Error cambiando plan: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Extender plan de usuario
    public function extendPlan($id, $months = 1) {
        try {
            // Verificar que el usuario existe
            $stmt = $this->conn->prepare("SELECT name, email, plan, expires_at FROM " . $this->table_name . " WHERE id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return $this->jsonResponse(false, null, 'Usuario no encontrado', null, 404);
            }
            
            if ($user['plan'] === 'free') {
                return $this->jsonResponse(false, null, 'No se puede extender un plan gratuito', null, 400);
            }
            
            // Calcular nueva fecha de expiración
            $extend_months = max(1, intval($months));
            $current_expires = $user['expires_at'] ? $user['expires_at'] : date('Y-m-d');
            
            // Si ya expiró, extender desde hoy, sino desde la fecha actual de expiración
            $base_date = strtotime($current_expires) > time() ? $current_expires : date('Y-m-d');
            $new_expires = date('Y-m-d', strtotime("+{$extend_months} months", strtotime($base_date)));
            
            // Actualizar fecha de expiración
            $stmt = $this->conn->prepare("UPDATE " . $this->table_name . " SET expires_at = ?, status = 'active' WHERE id = ?");
            $stmt->execute([$new_expires, $id]);
            
            // Agregar nota de extensión
            $stmt = $this->conn->prepare("UPDATE " . $this->table_name . " SET notes = CONCAT(COALESCE(notes, ''), '\n[PLAN EXTENDIDO] ', ?, ' meses hasta ', ?, ' - ', NOW()) WHERE id = ?");
            $stmt->execute([$extend_months, $new_expires, $id]);
            
            $this->logActivity('plan_extended', "Plan extendido para {$user['name']}: {$extend_months} meses", $id, [
                'extend_months' => $extend_months,
                'new_expires' => $new_expires
            ]);
            
            return $this->jsonResponse(true, ['new_expires_at' => $new_expires], "Plan extendido exitosamente por {$extend_months} meses");
            
        } catch(Exception $e) {
            error_log("Error extendiendo plan: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Enviar mensaje a usuario
    public function sendMessage($id, $subject, $message, $priority = 'normal') {
        try {
            // Verificar que el usuario existe
            $stmt = $this->conn->prepare("SELECT name, email FROM " . $this->table_name . " WHERE id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return $this->jsonResponse(false, null, 'Usuario no encontrado', null, 404);
            }
            
            // Validar prioridad
            if (!in_array($priority, ['low', 'normal', 'high', 'urgent'])) {
                $priority = 'normal';
            }
            
            // Simular envío de mensaje (en producción integrar con servicio de email)
            $message_data = [
                'to' => $user['email'],
                'to_name' => $user['name'],
                'subject' => $subject,
                'message' => $message,
                'priority' => $priority,
                'sent_at' => date('c'),
                'from' => 'admin@reservas-system.com'
            ];
            
            // Registrar mensaje en logs
            $this->logActivity('message_sent', "Mensaje enviado a {$user['name']}: $subject", $id, $message_data);
            
            // Agregar nota al usuario
            $stmt = $this->conn->prepare("UPDATE " . $this->table_name . " SET notes = CONCAT(COALESCE(notes, ''), '\n[MENSAJE ENVIADO] ', ?, ' - ', NOW()) WHERE id = ?");
            $stmt->execute([$subject, $id]);
            
            return $this->jsonResponse(true, $message_data, 'Mensaje enviado exitosamente');
            
        } catch(Exception $e) {
            error_log("Error enviando mensaje: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }

// <--- seguir aqui --->

// Continuación de users.php - Parte 3 de 4
// Métodos adicionales de la clase UserManager

    // Obtener logs específicos del usuario
    public function getUserLogs($id, $filters = []) {
        try {
            // Verificar que el usuario existe
            $stmt = $this->conn->prepare("SELECT name FROM " . $this->table_name . " WHERE id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return $this->jsonResponse(false, null, 'Usuario no encontrado', null, 404);
            }
            
            $page = isset($filters['page']) ? max(1, intval($filters['page'])) : 1;
            $per_page = isset($filters['per_page']) ? min(100, max(1, intval($filters['per_page']))) : 50;
            $offset = ($page - 1) * $per_page;
            
            // Construir query base
            $query = "SELECT * FROM admin_logs WHERE user_id = ?";
            $count_query = "SELECT COUNT(*) as total FROM admin_logs WHERE user_id = ?";
            $params = [$id];
            
            // Aplicar filtros adicionales
            if (!empty($filters['action'])) {
                $query .= " AND action LIKE ?";
                $count_query .= " AND action LIKE ?";
                $params[] = '%' . $filters['action'] . '%';
            }
            
            if (!empty($filters['date_from'])) {
                $query .= " AND created_at >= ?";
                $count_query .= " AND created_at >= ?";
                $params[] = $filters['date_from'] . ' 00:00:00';
            }
            
            if (!empty($filters['date_to'])) {
                $query .= " AND created_at <= ?";
                $count_query .= " AND created_at <= ?";
                $params[] = $filters['date_to'] . ' 23:59:59';
            }
            
            // Ordenamiento y paginación
            $query .= " ORDER BY created_at DESC LIMIT $per_page OFFSET $offset";
            
            // Ejecutar consultas
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $logs = $stmt->fetchAll();
            
            $count_stmt = $this->conn->prepare($count_query);
            $count_stmt->execute($params);
            $total_records = $count_stmt->fetch()['total'];
            
            // Formatear logs
            foreach ($logs as &$log) {
                $log['created_at'] = date('c', strtotime($log['created_at']));
                $log['metadata'] = $log['metadata'] ? json_decode($log['metadata'], true) : null;
            }
            
            $pagination = [
                'current_page' => $page,
                'per_page' => $per_page,
                'total_records' => intval($total_records),
                'total_pages' => ceil($total_records / $per_page)
            ];
            
            $this->logActivity('user_logs_viewed', "Logs consultados para usuario {$user['name']}", $id, $filters);
            
            return $this->jsonResponse(true, $logs, 'Logs obtenidos exitosamente', $pagination);
            
        } catch(Exception $e) {
            error_log("Error obteniendo logs de usuario: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Exportar usuarios
    public function exportUsers($format = 'csv', $filters = []) {
        try {
            // Validar formato
            if (!in_array($format, ['csv', 'json', 'xlsx'])) {
                return $this->jsonResponse(false, null, 'Formato de exportación inválido', null, 400);
            }
            
            // Obtener usuarios sin paginación
            $query = "SELECT * FROM " . $this->table_name . " WHERE 1=1";
            $params = [];
            
            // Aplicar mismos filtros que en getUsers()
            if (!empty($filters['search'])) {
                $query .= " AND (name LIKE ? OR owner LIKE ? OR email LIKE ?)";
                $search_term = '%' . $filters['search'] . '%';
                $params[] = $search_term;
                $params[] = $search_term;
                $params[] = $search_term;
            }
            
            if (!empty($filters['plan'])) {
                $query .= " AND plan = ?";
                $params[] = $filters['plan'];
            }
            
            if (!empty($filters['status'])) {
                $query .= " AND status = ?";
                $params[] = $filters['status'];
            }
            
            if (!empty($filters['region'])) {
                $query .= " AND region LIKE ?";
                $params[] = '%' . $filters['region'] . '%';
            }
            
            $query .= " ORDER BY created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $users = $stmt->fetchAll();
            
            // Formatear datos para exportación
            $export_data = [];
            foreach ($users as $user) {
                $export_data[] = [
                    'ID' => $user['id'],
                    'Nombre' => $user['name'],
                    'Propietario' => $user['owner'],
                    'Email' => $user['email'],
                    'Teléfono' => $user['phone'],
                    'Plan' => ucfirst($user['plan']),
                    'Estado' => ucfirst($user['status']),
                    'Región' => $user['region'],
                    'Ingresos Mensuales' => $user['revenue'],
                    'Ingresos Totales' => $user['total_revenue'],
                    'Reservas' => $user['reservations'],
                    'Crecimiento (%)' => $user['monthly_growth'],
                    'Fecha Creación' => date('Y-m-d H:i:s', strtotime($user['created_at'])),
                    'Fecha Expiración' => $user['expires_at'],
                    'Último Login' => $user['last_login'] ? date('Y-m-d H:i:s', strtotime($user['last_login'])) : 'Nunca',
                    'Notas' => $user['notes']
                ];
            }
            
            $filename = "usuarios_export_" . date('Y-m-d_H-i-s');
            
            if ($format === 'csv') {
                $csv_content = $this->generateCSV($export_data);
                $this->logActivity('users_exported', "Usuarios exportados en CSV ({$format})", null, [
                    'format' => $format,
                    'total_records' => count($users),
                    'filters' => $filters
                ]);
                
                return $this->jsonResponse(true, [
                    'filename' => $filename . '.csv',
                    'content' => base64_encode($csv_content),
                    'mime_type' => 'text/csv',
                    'total_records' => count($users)
                ], 'Exportación CSV generada exitosamente');
                
            } elseif ($format === 'json') {
                $json_content = json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                
                $this->logActivity('users_exported', "Usuarios exportados en JSON", null, [
                    'format' => $format,
                    'total_records' => count($users),
                    'filters' => $filters
                ]);
                
                return $this->jsonResponse(true, [
                    'filename' => $filename . '.json',
                    'content' => base64_encode($json_content),
                    'mime_type' => 'application/json',
                    'total_records' => count($users)
                ], 'Exportación JSON generada exitosamente');
            }
            
        } catch(Exception $e) {
            error_log("Error exportando usuarios: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Generar contenido CSV
    private function generateCSV($data) {
        if (empty($data)) {
            return '';
        }
        
        $output = fopen('php://temp', 'r+');
        
        // Escribir encabezados
        fputcsv($output, array_keys($data[0]));
        
        // Escribir datos
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        
        rewind($output);
        $csv_content = stream_get_contents($output);
        fclose($output);
        
        return $csv_content;
    }
    
    // Acciones masivas (bulk actions)
    public function bulkAction($action, $user_ids, $params = []) {
        try {
            // Validar acción
            $allowed_actions = ['activate', 'suspend', 'delete', 'change_plan', 'send_message'];
            if (!in_array($action, $allowed_actions)) {
                return $this->jsonResponse(false, null, 'Acción inválida', null, 400);
            }
            
            // Validar IDs de usuarios
            if (empty($user_ids) || !is_array($user_ids)) {
                return $this->jsonResponse(false, null, 'Se requiere una lista de IDs de usuarios', null, 400);
            }
            
            // Limitar cantidad de usuarios por operación masiva
            if (count($user_ids) > 100) {
                return $this->jsonResponse(false, null, 'Máximo 100 usuarios por operación masiva', null, 400);
            }
            
            $this->conn->beginTransaction();
            
            $results = [
                'success' => [],
                'failed' => [],
                'total' => count($user_ids)
            ];
            
            foreach ($user_ids as $user_id) {
                try {
                    switch ($action) {
                        case 'activate':
                            $this->activateUserBulk($user_id);
                            $results['success'][] = $user_id;
                            break;
                            
                        case 'suspend':
                            $reason = $params['reason'] ?? 'Suspensión masiva';
                            $this->suspendUserBulk($user_id, $reason);
                            $results['success'][] = $user_id;
                            break;
                            
                        case 'delete':
                            $this->deleteUserBulk($user_id);
                            $results['success'][] = $user_id;
                            break;
                            
                        case 'change_plan':
                            if (empty($params['plan'])) {
                                throw new Exception('Plan requerido para cambio masivo');
                            }
                            $this->changePlanBulk($user_id, $params['plan']);
                            $results['success'][] = $user_id;
                            break;
                            
                        case 'send_message':
                            if (empty($params['subject']) || empty($params['message'])) {
                                throw new Exception('Asunto y mensaje requeridos');
                            }
                            $this->sendMessageBulk($user_id, $params['subject'], $params['message'], $params['priority'] ?? 'normal');
                            $results['success'][] = $user_id;
                            break;
                    }
                } catch(Exception $e) {
                    $results['failed'][] = [
                        'user_id' => $user_id,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            $this->conn->commit();
            
            $this->logActivity('bulk_action', "Acción masiva ejecutada: $action", null, [
                'action' => $action,
                'user_ids' => $user_ids,
                'results' => $results,
                'params' => $params
            ]);
            
            $message = "Acción masiva completada. Exitosos: " . count($results['success']) . ", Fallidos: " . count($results['failed']);
            
            return $this->jsonResponse(true, $results, $message);
            
        } catch(Exception $e) {
            $this->conn->rollBack();
            error_log("Error en acción masiva: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Métodos auxiliares para acciones masivas
    private function activateUserBulk($id) {
        $stmt = $this->conn->prepare("UPDATE " . $this->table_name . " SET status = 'active' WHERE id = ?");
        $stmt->execute([$id]);
    }
    
    private function suspendUserBulk($id, $reason) {
        $stmt = $this->conn->prepare("UPDATE " . $this->table_name . " SET status = 'suspended' WHERE id = ?");
        $stmt->execute([$id]);
        
        if (!empty($reason)) {
            $stmt = $this->conn->prepare("UPDATE " . $this->table_name . " SET notes = CONCAT(COALESCE(notes, ''), '\n[SUSPENDIDO MASIVO] ', ?, ' - ', NOW()) WHERE id = ?");
            $stmt->execute([$reason, $id]);
        }
    }
    
    private function deleteUserBulk($id) {
        $stmt = $this->conn->prepare("DELETE FROM " . $this->table_name . " WHERE id = ?");
        $stmt->execute([$id]);
    }
    
    private function changePlanBulk($id, $new_plan) {
        $expires_at = null;
        if ($new_plan !== 'free') {
            $expires_at = date('Y-m-d', strtotime('+1 month'));
        }
        
        $stmt = $this->conn->prepare("UPDATE " . $this->table_name . " SET plan = ?, expires_at = ?, status = 'active' WHERE id = ?");
        $stmt->execute([$new_plan, $expires_at, $id]);
    }
    
    private function sendMessageBulk($id, $subject, $message, $priority) {
        // Simular envío de mensaje masivo
        $stmt = $this->conn->prepare("UPDATE " . $this->table_name . " SET notes = CONCAT(COALESCE(notes, ''), '\n[MENSAJE MASIVO] ', ?, ' - ', NOW()) WHERE id = ?");
        $stmt->execute([$subject, $id]);
    }
    
    // Obtener estadísticas rápidas de usuarios
    public function getUserStats() {
        try {
            $stats = [];
            
            // Total de usuarios
            $stmt = $this->conn->prepare("SELECT COUNT(*) as total FROM " . $this->table_name);
            $stmt->execute();
            $stats['total_users'] = intval($stmt->fetch()['total']);
            
            // Usuarios por estado
            $stmt = $this->conn->prepare("SELECT status, COUNT(*) as count FROM " . $this->table_name . " GROUP BY status");
            $stmt->execute();
            $status_counts = $stmt->fetchAll();
            $stats['by_status'] = [];
            foreach ($status_counts as $status) {
                $stats['by_status'][$status['status']] = intval($status['count']);
            }
            
            // Usuarios por plan
            $stmt = $this->conn->prepare("SELECT plan, COUNT(*) as count FROM " . $this->table_name . " GROUP BY plan");
            $stmt->execute();
            $plan_counts = $stmt->fetchAll();
            $stats['by_plan'] = [];
            foreach ($plan_counts as $plan) {
                $stats['by_plan'][$plan['plan']] = intval($plan['count']);
            }
            
            // Ingresos totales
            $stmt = $this->conn->prepare("SELECT SUM(revenue) as monthly_revenue, SUM(total_revenue) as total_revenue FROM " . $this->table_name);
            $stmt->execute();
            $revenue = $stmt->fetch();
            $stats['revenue'] = [
                'monthly' => floatval($revenue['monthly_revenue']),
                'total' => floatval($revenue['total_revenue'])
            ];
            
            // Usuarios registrados hoy
            $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM " . $this->table_name . " WHERE DATE(created_at) = CURDATE()");
            $stmt->execute();
            $stats['registered_today'] = intval($stmt->fetch()['count']);
            
            // Planes próximos a expirar (30 días)
            $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM " . $this->table_name . " WHERE expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
            $stmt->execute();
            $stats['expiring_soon'] = intval($stmt->fetch()['count']);
            
            return $this->jsonResponse(true, $stats, 'Estadísticas obtenidas exitosamente');
            
        } catch(Exception $e) {
            error_log("Error obteniendo estadísticas: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
}

// <--- seguir aqui --->

// Continuación de users.php - Parte 4 de 4
// Manejo de rutas HTTP y procesamiento de requests

// Función para completar datos mock adicionales
function addAdditionalMockUsers($conn) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users");
        $stmt->execute();
        $count = $stmt->fetch()['count'];
        
        if ($count < 8) {
            $additional_users = [
                [
                    'name' => 'Pizzería Bella Italia',
                    'owner' => 'Giuseppe Romano',
                    'email' => 'giuseppe@bellaitalia.com',
                    'phone' => '+34 913 456 789',
                    'plan' => 'enterprise',
                    'status' => 'active',
                    'region' => 'Valencia, España',
                    'revenue' => 4250.00,
                    'total_revenue' => 28900.00,
                    'reservations' => 456,
                    'monthly_growth' => 18.7,
                    'expires_at' => date('Y-m-d', strtotime('+6 months')),
                    'last_login' => date('Y-m-d H:i:s', strtotime('-30 minutes')),
                    'notes' => 'Cliente enterprise con múltiples ubicaciones'
                ],
                [
                    'name' => 'Marisquería El Puerto',
                    'owner' => 'Carmen Ruiz',
                    'email' => 'carmen@elpuerto.com',
                    'phone' => '+34 956 123 456',
                    'plan' => 'premium',
                    'status' => 'active',
                    'region' => 'Cádiz, España',
                    'revenue' => 1980.00,
                    'total_revenue' => 12450.00,
                    'reservations' => 189,
                    'monthly_growth' => 7.3,
                    'expires_at' => date('Y-m-d', strtotime('+3 months')),
                    'last_login' => date('Y-m-d H:i:s', strtotime('-4 hours')),
                    'notes' => 'Especializada en mariscos frescos'
                ],
                [
                    'name' => 'Tapas y Más',
                    'owner' => 'Pedro Jiménez',
                    'email' => 'pedro@tapasymas.com',
                    'phone' => '+34 954 789 012',
                    'plan' => 'basic',
                    'status' => 'active',
                    'region' => 'Sevilla, España',
                    'revenue' => 720.00,
                    'total_revenue' => 3890.00,
                    'reservations' => 67,
                    'monthly_growth' => 4.8,
                    'expires_at' => date('Y-m-d', strtotime('+1 month')),
                    'last_login' => date('Y-m-d H:i:s', strtotime('-2 days')),
                    'notes' => 'Bar de tapas tradicional'
                ],
                [
                    'name' => 'Asador La Brasa',
                    'owner' => 'Miguel Fernández',
                    'email' => 'miguel@labrasa.com',
                    'phone' => '+34 985 654 321',
                    'plan' => 'premium',
                    'status' => 'suspended',
                    'region' => 'Asturias, España',
                    'revenue' => 0.00,
                    'total_revenue' => 8760.00,
                    'reservations' => 234,
                    'monthly_growth' => -2.1,
                    'expires_at' => date('Y-m-d', strtotime('-15 days')),
                    'last_login' => date('Y-m-d H:i:s', strtotime('-1 month')),
                    'notes' => 'Suspendido por falta de pago. Contactar para reactivación.'
                ],
                [
                    'name' => 'Veggie Garden',
                    'owner' => 'Ana López',
                    'email' => 'ana@veggiegarden.com',
                    'phone' => '+34 934 567 890',
                    'plan' => 'basic',
                    'status' => 'active',
                    'region' => 'Barcelona, España',
                    'revenue' => 1120.00,
                    'total_revenue' => 5670.00,
                    'reservations' => 98,
                    'monthly_growth' => 15.2,
                    'expires_at' => date('Y-m-d', strtotime('+2 months')),
                    'last_login' => date('Y-m-d H:i:s', strtotime('-6 hours')),
                    'notes' => 'Restaurante vegano en crecimiento'
                ],
                [
                    'name' => 'El Rincón del Abuelo',
                    'owner' => 'Francisco García',
                    'email' => 'francisco@rinconabuelo.com',
                    'phone' => '+34 925 345 678',
                    'plan' => 'free',
                    'status' => 'active',
                    'region' => 'Toledo, España',
                    'revenue' => 0.00,
                    'total_revenue' => 0.00,
                    'reservations' => 23,
                    'monthly_growth' => 0.0,
                    'expires_at' => null,
                    'last_login' => date('Y-m-d H:i:s', strtotime('-1 week')),
                    'notes' => 'Usuario recién registrado, plan gratuito'
                ]
            ];
            
            foreach ($additional_users as $user) {
                $stmt = $conn->prepare("
                    INSERT INTO users 
                    (name, owner, email, phone, plan, status, region, revenue, total_revenue, 
                     reservations, monthly_growth, expires_at, last_login, notes, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW() - INTERVAL FLOOR(RAND() * 180) DAY)
                ");
                
                $stmt->execute([
                    $user['name'], $user['owner'], $user['email'], $user['phone'],
                    $user['plan'], $user['status'], $user['region'], $user['revenue'],
                    $user['total_revenue'], $user['reservations'], $user['monthly_growth'],
                    $user['expires_at'], $user['last_login'], $user['notes']
                ]);
            }
        }
    } catch(Exception $e) {
        error_log("Error agregando usuarios mock adicionales: " . $e->getMessage());
    }
}

// Rate limiting simple
class RateLimiter {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    public function isAllowed($ip, $limit = 100, $window = 3600) {
        try {
            // Limpiar registros antiguos
            $stmt = $this->conn->prepare("DELETE FROM rate_limits WHERE created_at < ?");
            $stmt->execute([date('Y-m-d H:i:s', time() - $window)]);
            
            // Contar requests actuales
            $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM rate_limits WHERE ip_address = ? AND created_at > ?");
            $stmt->execute([$ip, date('Y-m-d H:i:s', time() - $window)]);
            $current_count = $stmt->fetch()['count'];
            
            if ($current_count >= $limit) {
                return false;
            }
            
            // Registrar request actual
            $stmt = $this->conn->prepare("INSERT INTO rate_limits (ip_address, created_at) VALUES (?, NOW())");
            $stmt->execute([$ip]);
            
            return true;
            
        } catch(Exception $e) {
            // En caso de error, permitir el request
            return true;
        }
    }
}

// Crear tabla de rate limiting si no existe
function createRateLimitTable($conn) {
    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS rate_limits (
                id INT PRIMARY KEY AUTO_INCREMENT,
                ip_address VARCHAR(45) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ip_time (ip_address, created_at)
            )
        ");
    } catch(Exception $e) {
        error_log("Error creando tabla rate_limits: " . $e->getMessage());
    }
}

// Procesamiento principal de requests
try {
    // Inicializar base de datos
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error de conexión a base de datos']);
        exit();
    }
    
    // Crear tabla de rate limiting
    createRateLimitTable($db);
    
    // Rate limiting
    $rate_limiter = new RateLimiter($db);
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    if (!$rate_limiter->isAllowed($client_ip, 200, 3600)) {
        http_response_code(429);
        echo json_encode([
            'success' => false, 
            'message' => 'Demasiadas peticiones. Intente más tarde.',
            'timestamp' => date('c')
        ]);
        exit();
    }
    
    // Validar autenticación
    $headers = getallheaders();
    $auth_header = isset($headers['Authorization']) ? $headers['Authorization'] : 
                  (isset($headers['authorization']) ? $headers['authorization'] : '');
    
    $user_manager = new UserManager($db);
    
    if (!$user_manager->validateToken($auth_header)) {
        http_response_code(401);
        echo json_encode([
            'success' => false, 
            'message' => 'Token de autorización inválido',
            'timestamp' => date('c')
        ]);
        exit();
    }
    
    // Inicializar datos mock
    initializeMockData($db);
    addAdditionalMockUsers($db);
    
    // Obtener método HTTP y ruta
    $method = $_SERVER['REQUEST_METHOD'];
    $request_uri = $_SERVER['REQUEST_URI'];
    $path = parse_url($request_uri, PHP_URL_PATH);
    $path_parts = explode('/', trim($path, '/'));
    
    // Obtener datos del request
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $query_params = $_GET;
    
    // Router principal
    switch ($method) {
        case 'GET':
            if (count($path_parts) === 1 && $path_parts[0] === 'users') {
                // GET /users - Listar usuarios
                $user_manager->getUsers($query_params);
                
            } elseif (count($path_parts) === 2 && $path_parts[0] === 'users' && is_numeric($path_parts[1])) {
                // GET /users/{id} - Obtener usuario específico
                $user_manager->getUserById($path_parts[1]);
                
            } elseif (count($path_parts) === 3 && $path_parts[0] === 'users' && is_numeric($path_parts[1]) && $path_parts[2] === 'logs') {
                // GET /users/{id}/logs - Obtener logs del usuario
                $user_manager->getUserLogs($path_parts[1], $query_params);
                
            } elseif (count($path_parts) === 2 && $path_parts[0] === 'users' && $path_parts[1] === 'export') {
                // GET /users/export - Exportar usuarios
                $format = $query_params['format'] ?? 'csv';
                $user_manager->exportUsers($format, $query_params);
                
            } elseif (count($path_parts) === 2 && $path_parts[0] === 'users' && $path_parts[1] === 'stats') {
                // GET /users/stats - Estadísticas de usuarios
                $user_manager->getUserStats();
                
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Endpoint no encontrado']);
            }
            break;
            
        case 'POST':
            if (count($path_parts) === 1 && $path_parts[0] === 'users') {
                // POST /users - Crear usuario
                $user_manager->createUser($input);
                
            } elseif (count($path_parts) === 3 && $path_parts[0] === 'users' && is_numeric($path_parts[1]) && $path_parts[2] === 'suspend') {
                // POST /users/{id}/suspend - Suspender usuario
                $reason = $input['reason'] ?? '';
                $user_manager->suspendUser($path_parts[1], $reason);
                
            } elseif (count($path_parts) === 3 && $path_parts[0] === 'users' && is_numeric($path_parts[1]) && $path_parts[2] === 'activate') {
                // POST /users/{id}/activate - Activar usuario
                $user_manager->activateUser($path_parts[1]);
                
            } elseif (count($path_parts) === 3 && $path_parts[0] === 'users' && is_numeric($path_parts[1]) && $path_parts[2] === 'change-plan') {
                // POST /users/{id}/change-plan - Cambiar plan
                $new_plan = $input['plan'] ?? '';
                $extend_months = $input['extend_months'] ?? 1;
                $user_manager->changePlan($path_parts[1], $new_plan, $extend_months);
                
            } elseif (count($path_parts) === 3 && $path_parts[0] === 'users' && is_numeric($path_parts[1]) && $path_parts[2] === 'extend-plan') {
                // POST /users/{id}/extend-plan - Extender plan
                $months = $input['months'] ?? 1;
                $user_manager->extendPlan($path_parts[1], $months);
                
            } elseif (count($path_parts) === 3 && $path_parts[0] === 'users' && is_numeric($path_parts[1]) && $path_parts[2] === 'message') {
                // POST /users/{id}/message - Enviar mensaje
                $subject = $input['subject'] ?? '';
                $message = $input['message'] ?? '';
                $priority = $input['priority'] ?? 'normal';
                $user_manager->sendMessage($path_parts[1], $subject, $message, $priority);
                
            } elseif (count($path_parts) === 2 && $path_parts[0] === 'users' && $path_parts[1] === 'bulk-action') {
                // POST /users/bulk-action - Acciones masivas
                $action = $input['action'] ?? '';
                $user_ids = $input['user_ids'] ?? [];
                $params = $input['params'] ?? [];
                $user_manager->bulkAction($action, $user_ids, $params);
                
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Endpoint no encontrado']);
            }
            break;
            
        case 'PUT':
            if (count($path_parts) === 2 && $path_parts[0] === 'users' && is_numeric($path_parts[1])) {
                // PUT /users/{id} - Actualizar usuario
                $user_manager->updateUser($path_parts[1], $input);
                
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Endpoint no encontrado']);
            }
            break;
            
        case 'DELETE':
            if (count($path_parts) === 2 && $path_parts[0] === 'users' && is_numeric($path_parts[1])) {
                // DELETE /users/{id} - Eliminar usuario
                $user_manager->deleteUser($path_parts[1]);
                
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Endpoint no encontrado']);
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode([
                'success' => false, 
                'message' => 'Método HTTP no permitido',
                'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE']
            ]);
            break;
    }
    
} catch(Exception $e) {
    error_log("Error general en users.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'timestamp' => date('c')
    ]);
}

/**
 * DOCUMENTACIÓN DE ENDPOINTS
 * 
 * Autenticación requerida: Bearer token con credenciales de admin
 * 
 * GET /users - Listar usuarios con filtros
 * Parámetros: page, per_page, search, plan, status, region, order_by, order_dir
 * 
 * GET /users/{id} - Obtener usuario específico
 * 
 * GET /users/{id}/logs - Obtener logs del usuario
 * Parámetros: page, per_page, action, date_from, date_to
 * 
 * GET /users/export - Exportar usuarios
 * Parámetros: format (csv|json), filtros de búsqueda
 * 
 * GET /users/stats - Estadísticas rápidas
 * 
 * POST /users - Crear nuevo usuario
 * Body: {name, owner, email, phone?, plan?, status?, region?, notes?}
 * 
 * PUT /users/{id} - Actualizar usuario
 * Body: campos a actualizar
 * 
 * DELETE /users/{id} - Eliminar usuario
 * 
 * POST /users/{id}/suspend - Suspender usuario
 * Body: {reason?}
 * 
 * POST /users/{id}/activate - Activar usuario
 * 
 * POST /users/{id}/change-plan - Cambiar plan
 * Body: {plan, extend_months?}
 * 
 * POST /users/{id}/extend-plan - Extender plan
 * Body: {months}
 * 
 * POST /users/{id}/message - Enviar mensaje
 * Body: {subject, message, priority?}
 * 
 * POST /users/bulk-action - Acciones masivas
 * Body: {action, user_ids[], params?}
 */

?>

// <--- FIN DEL ARCHIVO users.php 