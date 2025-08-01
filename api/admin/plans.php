<?php
/**
 * Sistema de Reservas - Panel de Administrador
 * API REST para Gestión de Planes y Facturación
 * 
 * Funcionalidades:
 * - CRUD completo de planes
 * - Gestión de suscripciones
 * - Procesamiento de pagos
 * - Reportes de ingresos
 * - Notificaciones de expiración
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

// Clase principal para gestión de planes
class PlanManager {
    private $conn;
    private $plans_table = "plans";
    private $users_table = "users";
    private $subscriptions_table = "subscriptions";
    private $payments_table = "payments";
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    // Validar token JWT
    private function validateToken($token) {
        $admin_credentials = base64_encode('lmitrion230:Lelouch426753.');
        return $token === "Bearer " . $admin_credentials;
    }
    
    // Registrar actividad en logs
    private function logActivity($action, $description, $plan_id = null, $metadata = []) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO admin_logs (action, description, user, user_id, metadata, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $action,
                $description,
                'lmitrion230',
                $plan_id,
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
    
    // Obtener todos los planes
    public function getPlans($filters = []) {
        try {
            $page = isset($filters['page']) ? max(1, intval($filters['page'])) : 1;
            $per_page = isset($filters['per_page']) ? min(100, max(1, intval($filters['per_page']))) : 25;
            $offset = ($page - 1) * $per_page;
            
            // Construir query base
            $query = "SELECT p.*, 
                     (SELECT COUNT(*) FROM " . $this->users_table . " WHERE plan = p.slug AND status = 'active') as active_subscribers,
                     (SELECT COUNT(*) FROM " . $this->users_table . " WHERE plan = p.slug) as total_subscribers
                     FROM " . $this->plans_table . " p WHERE 1=1";
            $count_query = "SELECT COUNT(*) as total FROM " . $this->plans_table . " WHERE 1=1";
            $params = [];
            
            // Aplicar filtros
            if (!empty($filters['status'])) {
                $query .= " AND p.status = ?";
                $count_query .= " AND status = ?";
                $params[] = $filters['status'];
            }
            
            if (!empty($filters['search'])) {
                $search_condition = " AND (p.name LIKE ? OR p.slug LIKE ?)";
                $query .= $search_condition;
                $count_query .= $search_condition;
                $search_term = '%' . $filters['search'] . '%';
                $params[] = $search_term;
                $params[] = $search_term;
            }
            
            // Ordenamiento
            $order_by = isset($filters['order_by']) ? $filters['order_by'] : 'price';
            $order_dir = isset($filters['order_dir']) && strtoupper($filters['order_dir']) === 'DESC' ? 'DESC' : 'ASC';
            
            $allowed_columns = ['id', 'name', 'price', 'status', 'created_at'];
            if (!in_array($order_by, $allowed_columns)) {
                $order_by = 'price';
            }
            
            $query .= " ORDER BY p.$order_by $order_dir LIMIT $per_page OFFSET $offset";
            
            // Ejecutar consulta principal
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $plans = $stmt->fetchAll();
            
            // Obtener total de registros
            $count_stmt = $this->conn->prepare($count_query);
            $count_stmt->execute($params);
            $total_records = $count_stmt->fetch()['total'];
            
            // Formatear datos de planes
            foreach ($plans as &$plan) {
                $plan['created_at'] = date('c', strtotime($plan['created_at']));
                $plan['price'] = floatval($plan['price']);
                $plan['features'] = $plan['features'] ? json_decode($plan['features'], true) : [];
                $plan['max_reservations'] = intval($plan['max_reservations']);
                $plan['max_locations'] = intval($plan['max_locations']);
                $plan['active_subscribers'] = intval($plan['active_subscribers']);
                $plan['total_subscribers'] = intval($plan['total_subscribers']);
                
                // Calcular MRR (Monthly Recurring Revenue) para este plan
                $plan['mrr'] = $plan['active_subscribers'] * $plan['price'];
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
            
            $this->logActivity('plans_list', 'Lista de planes consultada', null, $filters);
            
            return $this->jsonResponse(true, $plans, 'Planes obtenidos exitosamente', $pagination);
            
        } catch(Exception $e) {
            error_log("Error obteniendo planes: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Obtener estadísticas de planes
    public function getPlanStats() {
        try {
            $stats = [];
            
            // MRR total (Monthly Recurring Revenue)
            $stmt = $this->conn->prepare("
                SELECT p.slug, p.name, p.price, COUNT(u.id) as subscribers
                FROM " . $this->plans_table . " p
                LEFT JOIN " . $this->users_table . " u ON p.slug = u.plan AND u.status = 'active'
                WHERE p.status = 'active'
                GROUP BY p.id, p.slug, p.name, p.price
            ");
            $stmt->execute();
            $plan_revenues = $stmt->fetchAll();
            
            $total_mrr = 0;
            $plan_breakdown = [];
            
            foreach ($plan_revenues as $plan) {
                $plan_mrr = floatval($plan['price']) * intval($plan['subscribers']);
                $total_mrr += $plan_mrr;
                
                $plan_breakdown[] = [
                    'plan' => $plan['name'],
                    'slug' => $plan['slug'],
                    'price' => floatval($plan['price']),
                    'subscribers' => intval($plan['subscribers']),
                    'mrr' => $plan_mrr
                ];
            }
            
            $stats['mrr'] = [
                'total' => $total_mrr,
                'by_plan' => $plan_breakdown
            ];
            
            // Suscripciones activas por plan
            $stmt = $this->conn->prepare("
                SELECT plan, COUNT(*) as count 
                FROM " . $this->users_table . " 
                WHERE status = 'active' 
                GROUP BY plan
            ");
            $stmt->execute();
            $active_subs = $stmt->fetchAll();
            
            $stats['active_subscriptions'] = [];
            foreach ($active_subs as $sub) {
                $stats['active_subscriptions'][$sub['plan']] = intval($sub['count']);
            }
            
            // Planes próximos a expirar (próximos 30 días)
            $stmt = $this->conn->prepare("
                SELECT plan, COUNT(*) as count 
                FROM " . $this->users_table . " 
                WHERE expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                AND status = 'active'
                GROUP BY plan
            ");
            $stmt->execute();
            $expiring_soon = $stmt->fetchAll();
            
            $stats['expiring_soon'] = [];
            $total_expiring = 0;
            foreach ($expiring_soon as $exp) {
                $count = intval($exp['count']);
                $stats['expiring_soon'][$exp['plan']] = $count;
                $total_expiring += $count;
            }
            $stats['expiring_soon']['total'] = $total_expiring;
            
            // Nuevas suscripciones este mes
            $stmt = $this->conn->prepare("
                SELECT plan, COUNT(*) as count 
                FROM " . $this->users_table . " 
                WHERE YEAR(created_at) = YEAR(CURDATE()) 
                AND MONTH(created_at) = MONTH(CURDATE())
                GROUP BY plan
            ");
            $stmt->execute();
            $new_this_month = $stmt->fetchAll();
            
            $stats['new_subscriptions_this_month'] = [];
            foreach ($new_this_month as $new_sub) {
                $stats['new_subscriptions_this_month'][$new_sub['plan']] = intval($new_sub['count']);
            }
            
            // Tasa de conversión (usuarios que cambiaron de free a paid)
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) as conversions 
                FROM admin_logs 
                WHERE action = 'plan_changed' 
                AND JSON_EXTRACT(metadata, '$.old_plan') = 'free'
                AND JSON_EXTRACT(metadata, '$.new_plan') != 'free'
                AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ");
            $stmt->execute();
            $conversions = $stmt->fetch();
            $stats['conversion_rate'] = [
                'conversions_last_30_days' => intval($conversions['conversions']),
            ];
            
            $this->logActivity('plan_stats_viewed', 'Estadísticas de planes consultadas');
            
            return $this->jsonResponse(true, $stats, 'Estadísticas obtenidas exitosamente');
            
        } catch(Exception $e) {
            error_log("Error obteniendo estadísticas de planes: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Crear nuevo plan
    public function createPlan($data) {
        try {
            // Validar datos requeridos
            $required_fields = ['name', 'slug', 'price'];
            foreach ($required_fields as $field) {
                if (empty($data[$field])) {
                    return $this->jsonResponse(false, null, "El campo $field es requerido", null, 400);
                }
            }
            
            // Validar precio
            if (!is_numeric($data['price']) || floatval($data['price']) < 0) {
                return $this->jsonResponse(false, null, 'El precio debe ser un número válido', null, 400);
            }
            
            // Validar slug único
            $stmt = $this->conn->prepare("SELECT id FROM " . $this->plans_table . " WHERE slug = ?");
            $stmt->execute([$data['slug']]);
            if ($stmt->fetch()) {
                return $this->jsonResponse(false, null, 'El slug ya existe', null, 409);
            }
            
            // Validar características (features)
            $features = [];
            if (isset($data['features']) && is_array($data['features'])) {
                $features = $data['features'];
            }
            
            // Preparar datos para inserción
            $max_reservations = isset($data['max_reservations']) ? max(0, intval($data['max_reservations'])) : 0;
            $max_locations = isset($data['max_locations']) ? max(0, intval($data['max_locations'])) : 1;
            $status = isset($data['status']) && in_array($data['status'], ['active', 'inactive']) 
                ? $data['status'] : 'active';
            
            // Insertar plan
            $stmt = $this->conn->prepare("
                INSERT INTO " . $this->plans_table . " 
                (name, slug, price, features, max_reservations, max_locations, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['name'],
                $data['slug'],
                floatval($data['price']),
                json_encode($features),
                $max_reservations,
                $max_locations,
                $status
            ]);
            
            $plan_id = $this->conn->lastInsertId();
            
            $this->logActivity('plan_created', "Plan creado: {$data['name']} ({$data['slug']})", $plan_id, $data);
            
            // Obtener el plan creado
            return $this->getPlanById($plan_id);
            
        } catch(Exception $e) {
            error_log("Error creando plan: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Obtener plan específico por ID
    public function getPlanById($id) {
        try {
            $stmt = $this->conn->prepare("
                SELECT p.*, 
                (SELECT COUNT(*) FROM " . $this->users_table . " WHERE plan = p.slug AND status = 'active') as active_subscribers,
                (SELECT COUNT(*) FROM " . $this->users_table . " WHERE plan = p.slug) as total_subscribers
                FROM " . $this->plans_table . " p WHERE p.id = ?
            ");
            $stmt->execute([$id]);
            $plan = $stmt->fetch();
            
            if (!$plan) {
                return $this->jsonResponse(false, null, 'Plan no encontrado', null, 404);
            }
            
            // Formatear datos
            $plan['created_at'] = date('c', strtotime($plan['created_at']));
            $plan['price'] = floatval($plan['price']);
            $plan['features'] = $plan['features'] ? json_decode($plan['features'], true) : [];
            $plan['max_reservations'] = intval($plan['max_reservations']);
            $plan['max_locations'] = intval($plan['max_locations']);
            $plan['active_subscribers'] = intval($plan['active_subscribers']);
            $plan['total_subscribers'] = intval($plan['total_subscribers']);
            $plan['mrr'] = $plan['active_subscribers'] * $plan['price'];
            
            // Obtener usuarios recientes en este plan
            $users_stmt = $this->conn->prepare("
                SELECT id, name, email, status, created_at, expires_at
                FROM " . $this->users_table . " 
                WHERE plan = ? 
                ORDER BY created_at DESC 
                LIMIT 10
            ");
            $users_stmt->execute([$plan['slug']]);
            $plan['recent_subscribers'] = $users_stmt->fetchAll();
            
            $this->logActivity('plan_view', "Plan {$plan['name']} consultado", $id);
            
            return $this->jsonResponse(true, $plan, 'Plan obtenido exitosamente');
            
        } catch(Exception $e) {
            error_log("Error obteniendo plan: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
}

// Inicializar datos mock de planes si la tabla está vacía
function initializeMockPlans($conn) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM plans");
        $stmt->execute();
        $count = $stmt->fetch()['count'];
        
        if ($count == 0) {
            $mock_plans = [
                [
                    'name' => 'Plan Gratuito',
                    'slug' => 'free',
                    'price' => 0.00,
                    'features' => [
                        'Hasta 50 reservas por mes',
                        '1 ubicación',
                        'Soporte por email',
                        'Panel básico'
                    ],
                    'max_reservations' => 50,
                    'max_locations' => 1,
                    'status' => 'active'
                ],
                [
                    'name' => 'Plan Básico',
                    'slug' => 'basic',
                    'price' => 29.99,
                    'features' => [
                        'Hasta 200 reservas por mes',
                        '2 ubicaciones',
                        'Soporte prioritario',
                        'Reportes básicos',
                        'Integración calendario'
                    ],
                    'max_reservations' => 200,
                    'max_locations' => 2,
                    'status' => 'active'
                ],
                [
                    'name' => 'Plan Premium',
                    'slug' => 'premium',
                    'price' => 79.99,
                    'features' => [
                        'Hasta 1000 reservas por mes',
                        '5 ubicaciones',
                        'Soporte 24/7',
                        'Reportes avanzados',
                        'API completa',
                        'Personalización avanzada'
                    ],
                    'max_reservations' => 1000,
                    'max_locations' => 5,
                    'status' => 'active'
                ],
                [
                    'name' => 'Plan Enterprise',
                    'slug' => 'enterprise',
                    'price' => 199.99,
                    'features' => [
                        'Reservas ilimitadas',
                        'Ubicaciones ilimitadas',
                        'Soporte dedicado',
                        'Reportes personalizados',
                        'API completa',
                        'White label',
                        'Integración personalizada'
                    ],
                    'max_reservations' => 999999,
                    'max_locations' => 999,
                    'status' => 'active'
                ]
            ];
            
            foreach ($mock_plans as $plan) {
                $stmt = $conn->prepare("
                    INSERT INTO plans 
                    (name, slug, price, features, max_reservations, max_locations, status, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $plan['name'], $plan['slug'], $plan['price'], 
                    json_encode($plan['features']), $plan['max_reservations'], 
                    $plan['max_locations'], $plan['status']
                ]);
            }
        }
    } catch(Exception $e) {
        error_log("Error inicializando planes mock: " . $e->getMessage());
    }
}

// <--- seguir aqui --->

// Continuación de plans.php - Parte 2 de 3
// Métodos adicionales de la clase PlanManager

    // Actualizar plan existente
    public function updatePlan($id, $data) {
        try {
            // Verificar que el plan existe
            $stmt = $this->conn->prepare("SELECT * FROM " . $this->plans_table . " WHERE id = ?");
            $stmt->execute([$id]);
            $existing_plan = $stmt->fetch();
            
            if (!$existing_plan) {
                return $this->jsonResponse(false, null, 'Plan no encontrado', null, 404);
            }
            
            // Validar slug único si se está actualizando
            if (isset($data['slug']) && $data['slug'] !== $existing_plan['slug']) {
                $stmt = $this->conn->prepare("SELECT id FROM " . $this->plans_table . " WHERE slug = ? AND id != ?");
                $stmt->execute([$data['slug'], $id]);
                if ($stmt->fetch()) {
                    return $this->jsonResponse(false, null, 'El slug ya existe', null, 409);
                }
            }
            
            // Preparar campos a actualizar
            $update_fields = [];
            $params = [];
            
            $allowed_fields = ['name', 'slug', 'price', 'max_reservations', 'max_locations', 'status'];
            
            foreach ($allowed_fields as $field) {
                if (isset($data[$field])) {
                    if ($field === 'price' && (!is_numeric($data[$field]) || floatval($data[$field]) < 0)) {
                        return $this->jsonResponse(false, null, 'El precio debe ser un número válido', null, 400);
                    }
                    
                    if ($field === 'status' && !in_array($data[$field], ['active', 'inactive'])) {
                        return $this->jsonResponse(false, null, 'Status inválido', null, 400);
                    }
                    
                    $update_fields[] = "$field = ?";
                    $params[] = $field === 'price' ? floatval($data[$field]) : $data[$field];
                }
            }
            
            // Manejar features por separado
            if (isset($data['features']) && is_array($data['features'])) {
                $update_fields[] = "features = ?";
                $params[] = json_encode($data['features']);
            }
            
            if (empty($update_fields)) {
                return $this->jsonResponse(false, null, 'No hay campos para actualizar', null, 400);
            }
            
            // Ejecutar actualización
            $params[] = $id;
            $query = "UPDATE " . $this->plans_table . " SET " . implode(', ', $update_fields) . " WHERE id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            $this->logActivity('plan_updated', "Plan {$existing_plan['name']} actualizado", $id, $data);
            
            // Retornar plan actualizado
            return $this->getPlanById($id);
            
        } catch(Exception $e) {
            error_log("Error actualizando plan: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Eliminar plan
    public function deletePlan($id) {
        try {
            // Verificar que el plan existe
            $stmt = $this->conn->prepare("SELECT name, slug FROM " . $this->plans_table . " WHERE id = ?");
            $stmt->execute([$id]);
            $plan = $stmt->fetch();
            
            if (!$plan) {
                return $this->jsonResponse(false, null, 'Plan no encontrado', null, 404);
            }
            
            // Verificar si hay usuarios con este plan
            $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM " . $this->users_table . " WHERE plan = ?");
            $stmt->execute([$plan['slug']]);
            $users_count = $stmt->fetch()['count'];
            
            if ($users_count > 0) {
                return $this->jsonResponse(false, null, "No se puede eliminar el plan. Hay $users_count usuarios activos con este plan", null, 400);
            }
            
            // Eliminar plan
            $stmt = $this->conn->prepare("DELETE FROM " . $this->plans_table . " WHERE id = ?");
            $stmt->execute([$id]);
            
            $this->logActivity('plan_deleted', "Plan eliminado: {$plan['name']} ({$plan['slug']})", $id);
            
            return $this->jsonResponse(true, null, 'Plan eliminado exitosamente');
            
        } catch(Exception $e) {
            error_log("Error eliminando plan: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Obtener suscripciones activas
    public function getSubscriptions($filters = []) {
        try {
            $page = isset($filters['page']) ? max(1, intval($filters['page'])) : 1;
            $per_page = isset($filters['per_page']) ? min(100, max(1, intval($filters['per_page']))) : 25;
            $offset = ($page - 1) * $per_page;
            
            // Query base
            $query = "SELECT u.id, u.name, u.owner, u.email, u.plan, u.status, u.revenue, 
                     u.created_at as subscription_start, u.expires_at, u.last_login,
                     p.name as plan_name, p.price as plan_price
                     FROM " . $this->users_table . " u
                     LEFT JOIN " . $this->plans_table . " p ON u.plan = p.slug
                     WHERE 1=1";
            
            $count_query = "SELECT COUNT(*) as total FROM " . $this->users_table . " u WHERE 1=1";
            $params = [];
            
            // Aplicar filtros
            if (!empty($filters['plan'])) {
                $query .= " AND u.plan = ?";
                $count_query .= " AND plan = ?";
                $params[] = $filters['plan'];
            }
            
            if (!empty($filters['status'])) {
                $query .= " AND u.status = ?";
                $count_query .= " AND status = ?";
                $params[] = $filters['status'];
            }
            
            if (!empty($filters['search'])) {
                $search_condition = " AND (u.name LIKE ? OR u.owner LIKE ? OR u.email LIKE ?)";
                $query .= $search_condition;
                $count_query .= $search_condition;
                $search_term = '%' . $filters['search'] . '%';
                $params[] = $search_term;
                $params[] = $search_term;
                $params[] = $search_term;
            }
            
            // Filtro de expiración
            if (!empty($filters['expiring'])) {
                $days = intval($filters['expiring']);
                $query .= " AND u.expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)";
                $count_query .= " AND expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)";
                $params[] = $days;
            }
            
            // Ordenamiento
            $query .= " ORDER BY u.expires_at ASC LIMIT $per_page OFFSET $offset";
            
            // Ejecutar consultas
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $subscriptions = $stmt->fetchAll();
            
            $count_stmt = $this->conn->prepare($count_query);
            $count_stmt->execute($params);
            $total_records = $count_stmt->fetch()['total'];
            
            // Formatear datos
            foreach ($subscriptions as &$sub) {
                $sub['subscription_start'] = date('c', strtotime($sub['subscription_start']));
                $sub['expires_at'] = $sub['expires_at'] ? date('c', strtotime($sub['expires_at'])) : null;
                $sub['last_login'] = $sub['last_login'] ? date('c', strtotime($sub['last_login'])) : null;
                $sub['revenue'] = floatval($sub['revenue']);
                $sub['plan_price'] = floatval($sub['plan_price']);
                
                // Calcular días hasta expiración
                if ($sub['expires_at']) {
                    $expires_timestamp = strtotime($sub['expires_at']);
                    $current_timestamp = time();
                    $sub['days_until_expiry'] = max(0, ceil(($expires_timestamp - $current_timestamp) / 86400));
                    $sub['is_expiring_soon'] = $sub['days_until_expiry'] <= 30;
                } else {
                    $sub['days_until_expiry'] = null;
                    $sub['is_expiring_soon'] = false;
                }
            }
            
            $pagination = [
                'current_page' => $page,
                'per_page' => $per_page,
                'total_records' => intval($total_records),
                'total_pages' => ceil($total_records / $per_page)
            ];
            
            $this->logActivity('subscriptions_list', 'Lista de suscripciones consultada', null, $filters);
            
            return $this->jsonResponse(true, $subscriptions, 'Suscripciones obtenidas exitosamente', $pagination);
            
        } catch(Exception $e) {
            error_log("Error obteniendo suscripciones: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Obtener suscripciones próximas a expirar
    public function getExpiringSubscriptions($days = 30) {
        try {
            $stmt = $this->conn->prepare("
                SELECT u.id, u.name, u.owner, u.email, u.plan, u.expires_at, u.revenue,
                       p.name as plan_name, p.price as plan_price,
                       DATEDIFF(u.expires_at, CURDATE()) as days_until_expiry
                FROM " . $this->users_table . " u
                LEFT JOIN " . $this->plans_table . " p ON u.plan = p.slug
                WHERE u.expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
                AND u.status = 'active'
                ORDER BY u.expires_at ASC
            ");
            
            $stmt->execute([$days]);
            $expiring = $stmt->fetchAll();
            
            // Formatear datos
            foreach ($expiring as &$sub) {
                $sub['expires_at'] = date('c', strtotime($sub['expires_at']));
                $sub['revenue'] = floatval($sub['revenue']);
                $sub['plan_price'] = floatval($sub['plan_price']);
                $sub['days_until_expiry'] = intval($sub['days_until_expiry']);
                
                // Categorizar por urgencia
                if ($sub['days_until_expiry'] <= 7) {
                    $sub['urgency'] = 'critical';
                } elseif ($sub['days_until_expiry'] <= 15) {
                    $sub['urgency'] = 'high';
                } else {
                    $sub['urgency'] = 'medium';
                }
            }
            
            // Estadísticas de expiración
            $stats = [
                'total_expiring' => count($expiring),
                'critical' => count(array_filter($expiring, fn($s) => $s['urgency'] === 'critical')),
                'high' => count(array_filter($expiring, fn($s) => $s['urgency'] === 'high')),
                'medium' => count(array_filter($expiring, fn($s) => $s['urgency'] === 'medium')),
                'potential_revenue_loss' => array_sum(array_column($expiring, 'plan_price'))
            ];
            
            $this->logActivity('expiring_subscriptions_viewed', "Suscripciones próximas a expirar consultadas ($days días)");
            
            return $this->jsonResponse(true, [
                'subscriptions' => $expiring,
                'stats' => $stats
            ], 'Suscripciones próximas a expirar obtenidas exitosamente');
            
        } catch(Exception $e) {
            error_log("Error obteniendo suscripciones próximas a expirar: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Procesar pago de plan
    public function processPayment($data) {
        try {
            // Validar datos requeridos
            $required_fields = ['user_id', 'plan_slug', 'amount', 'payment_method'];
            foreach ($required_fields as $field) {
                if (empty($data[$field])) {
                    return $this->jsonResponse(false, null, "El campo $field es requerido", null, 400);
                }
            }
            
            // Verificar que el usuario existe
            $stmt = $this->conn->prepare("SELECT * FROM " . $this->users_table . " WHERE id = ?");
            $stmt->execute([$data['user_id']]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return $this->jsonResponse(false, null, 'Usuario no encontrado', null, 404);
            }
            
            // Verificar que el plan existe
            $stmt = $this->conn->prepare("SELECT * FROM " . $this->plans_table . " WHERE slug = ?");
            $stmt->execute([$data['plan_slug']]);
            $plan = $stmt->fetch();
            
            if (!$plan) {
                return $this->jsonResponse(false, null, 'Plan no encontrado', null, 404);
            }
            
            // Validar monto
            if (floatval($data['amount']) !== floatval($plan['price'])) {
                return $this->jsonResponse(false, null, 'El monto no coincide con el precio del plan', null, 400);
            }
            
            $this->conn->beginTransaction();
            
            try {
                // Simular procesamiento de pago (en producción integrar con Stripe, PayPal, etc.)
                $payment_id = 'pay_' . uniqid();
                $payment_status = 'completed'; // Simular pago exitoso
                
                // Registrar pago en tabla de pagos (crear si no existe)
                $this->createPaymentRecord($payment_id, $data, $payment_status);
                
                // Actualizar plan del usuario
                $current_expires = $user['expires_at'];
                $new_expires = null;
                
                if ($data['plan_slug'] !== 'free') {
                    // Si el plan actual no ha expirado, extender desde la fecha actual
                    $base_date = ($current_expires && strtotime($current_expires) > time()) ? 
                                $current_expires : date('Y-m-d');
                    $new_expires = date('Y-m-d', strtotime('+1 month', strtotime($base_date)));
                }
                
                $stmt = $this->conn->prepare("
                    UPDATE " . $this->users_table . " 
                    SET plan = ?, expires_at = ?, status = 'active', revenue = ?
                    WHERE id = ?
                ");
                $stmt->execute([$data['plan_slug'], $new_expires, $plan['price'], $data['user_id']]);
                
                // Actualizar revenue total
                $stmt = $this->conn->prepare("
                    UPDATE " . $this->users_table . " 
                    SET total_revenue = total_revenue + ?
                    WHERE id = ?
                ");
                $stmt->execute([$plan['price'], $data['user_id']]);
                
                $this->conn->commit();
                
                $this->logActivity('payment_processed', "Pago procesado para {$user['name']}: {$plan['name']}", $data['user_id'], [
                    'payment_id' => $payment_id,
                    'amount' => $data['amount'],
                    'plan' => $data['plan_slug'],
                    'payment_method' => $data['payment_method']
                ]);
                
                return $this->jsonResponse(true, [
                    'payment_id' => $payment_id,
                    'status' => $payment_status,
                    'user_id' => $data['user_id'],
                    'plan' => $data['plan_slug'],
                    'amount' => floatval($data['amount']),
                    'expires_at' => $new_expires
                ], 'Pago procesado exitosamente');
                
            } catch(Exception $e) {
                $this->conn->rollBack();
                throw $e;
            }
            
        } catch(Exception $e) {
            error_log("Error procesando pago: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error procesando el pago', null, 500);
        }
    }
    
    // Crear registro de pago
    private function createPaymentRecord($payment_id, $data, $status) {
        try {
            // Crear tabla de pagos si no existe
            $this->conn->exec("
                CREATE TABLE IF NOT EXISTS payments (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    payment_id VARCHAR(100) UNIQUE NOT NULL,
                    user_id INT NOT NULL,
                    plan_slug VARCHAR(50) NOT NULL,
                    amount DECIMAL(10,2) NOT NULL,
                    payment_method VARCHAR(50) NOT NULL,
                    status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
                    transaction_data JSON,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id)
                )
            ");
            
            $stmt = $this->conn->prepare("
                INSERT INTO payments 
                (payment_id, user_id, plan_slug, amount, payment_method, status, transaction_data) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $transaction_data = [
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'metadata' => $data['metadata'] ?? null
            ];
            
            $stmt->execute([
                $payment_id,
                $data['user_id'],
                $data['plan_slug'],
                $data['amount'],
                $data['payment_method'],
                $status,
                json_encode($transaction_data)
            ]);
            
        } catch(Exception $e) {
            error_log("Error creando registro de pago: " . $e->getMessage());
            throw $e;
        }
    }
    
    // Obtener reportes de ingresos
    public function getRevenueReport($period = 'monthly', $year = null, $month = null) {
        try {
            $year = $year ?: date('Y');
            $month = $month ?: date('m');
            
            if ($period === 'daily') {
                // Reporte diario del mes actual
                $stmt = $this->conn->prepare("
                    SELECT DATE(created_at) as date, 
                           SUM(amount) as revenue,
                           COUNT(*) as transactions,
                           AVG(amount) as avg_transaction
                    FROM payments 
                    WHERE status = 'completed' 
                    AND YEAR(created_at) = ? 
                    AND MONTH(created_at) = ?
                    GROUP BY DATE(created_at)
                    ORDER BY date ASC
                ");
                $stmt->execute([$year, $month]);
                
            } elseif ($period === 'monthly') {
                // Reporte mensual del año
                $stmt = $this->conn->prepare("
                    SELECT MONTH(created_at) as month,
                           YEAR(created_at) as year,
                           SUM(amount) as revenue,
                           COUNT(*) as transactions,
                           AVG(amount) as avg_transaction
                    FROM payments 
                    WHERE status = 'completed' 
                    AND YEAR(created_at) = ?
                    GROUP BY YEAR(created_at), MONTH(created_at)
                    ORDER BY month ASC
                ");
                $stmt->execute([$year]);
                
            } else { // yearly
                // Reporte anual
                $stmt = $this->conn->prepare("
                    SELECT YEAR(created_at) as year,
                           SUM(amount) as revenue,
                           COUNT(*) as transactions,
                           AVG(amount) as avg_transaction
                    FROM payments 
                    WHERE status = 'completed'
                    GROUP BY YEAR(created_at)
                    ORDER BY year DESC
                    LIMIT 5
                ");
                $stmt->execute();
            }
            
            $report_data = $stmt->fetchAll();
            
            // Formatear datos
            foreach ($report_data as &$row) {
                $row['revenue'] = floatval($row['revenue']);
                $row['transactions'] = intval($row['transactions']);
                $row['avg_transaction'] = floatval($row['avg_transaction']);
            }
            
            // Calcular totales
            $total_revenue = array_sum(array_column($report_data, 'revenue'));
            $total_transactions = array_sum(array_column($report_data, 'transactions'));
            
            $summary = [
                'period' => $period,
                'year' => intval($year),
                'month' => $period === 'daily' ? intval($month) : null,
                'total_revenue' => $total_revenue,
                'total_transactions' => $total_transactions,
                'avg_transaction' => $total_transactions > 0 ? $total_revenue / $total_transactions : 0,
                'data' => $report_data
            ];
            
            $this->logActivity('revenue_report_viewed', "Reporte de ingresos consultado: $period");
            
            return $this->jsonResponse(true, $summary, 'Reporte de ingresos obtenido exitosamente');
            
        } catch(Exception $e) {
            error_log("Error obteniendo reporte de ingresos: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Obtener pagos fallidos
    public function getFailedPayments($filters = []) {
        try {
            $page = isset($filters['page']) ? max(1, intval($filters['page'])) : 1;
            $per_page = isset($filters['per_page']) ? min(100, max(1, intval($filters['per_page']))) : 25;
            $offset = ($page - 1) * $per_page;
            
            $query = "SELECT p.*, u.name as user_name, u.email as user_email, pl.name as plan_name
                     FROM payments p
                     LEFT JOIN " . $this->users_table . " u ON p.user_id = u.id
                     LEFT JOIN " . $this->plans_table . " pl ON p.plan_slug = pl.slug
                     WHERE p.status = 'failed'";
            
            $count_query = "SELECT COUNT(*) as total FROM payments WHERE status = 'failed'";
            $params = [];
            
            // Aplicar filtros de fecha
            if (!empty($filters['date_from'])) {
                $query .= " AND p.created_at >= ?";
                $count_query .= " AND created_at >= ?";
                $params[] = $filters['date_from'] . ' 00:00:00';
            }
            
            if (!empty($filters['date_to'])) {
                $query .= " AND p.created_at <= ?";
                $count_query .= " AND created_at <= ?";
                $params[] = $filters['date_to'] . ' 23:59:59';
            }
            
            $query .= " ORDER BY p.created_at DESC LIMIT $per_page OFFSET $offset";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $failed_payments = $stmt->fetchAll();
            
            $count_stmt = $this->conn->prepare($count_query);
            $count_stmt->execute($params);
            $total_records = $count_stmt->fetch()['total'];
            
            // Formatear datos
            foreach ($failed_payments as &$payment) {
                $payment['created_at'] = date('c', strtotime($payment['created_at']));
                $payment['amount'] = floatval($payment['amount']);
                $payment['transaction_data'] = $payment['transaction_data'] ? json_decode($payment['transaction_data'], true) : null;
            }
            
            $pagination = [
                'current_page' => $page,
                'per_page' => $per_page,
                'total_records' => intval($total_records),
                'total_pages' => ceil($total_records / $per_page)
            ];
            
            return $this->jsonResponse(true, $failed_payments, 'Pagos fallidos obtenidos exitosamente', $pagination);
            
        } catch(Exception $e) {
            error_log("Error obteniendo pagos fallidos: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }

// <--- seguir aqui --->

// Continuación de plans.php - Parte 3 de 3
// Métodos finales de la clase PlanManager

    // Promocionar usuarios a un plan específico
    public function promoteUsersToplan($plan_slug, $user_ids, $params = []) {
        try {
            // Validar plan
            $stmt = $this->conn->prepare("SELECT * FROM " . $this->plans_table . " WHERE slug = ?");
            $stmt->execute([$plan_slug]);
            $plan = $stmt->fetch();
            
            if (!$plan) {
                return $this->jsonResponse(false, null, 'Plan no encontrado', null, 404);
            }
            
            // Validar IDs de usuarios
            if (empty($user_ids) || !is_array($user_ids)) {
                return $this->jsonResponse(false, null, 'Se requiere una lista de IDs de usuarios', null, 400);
            }
            
            if (count($user_ids) > 50) {
                return $this->jsonResponse(false, null, 'Máximo 50 usuarios por promoción masiva', null, 400);
            }
            
            $extend_months = isset($params['extend_months']) ? max(1, intval($params['extend_months'])) : 1;
            $discount = isset($params['discount']) ? min(100, max(0, floatval($params['discount']))) : 0;
            $free_trial = isset($params['free_trial']) && $params['free_trial'] === true;
            
            $this->conn->beginTransaction();
            
            $results = [
                'success' => [],
                'failed' => [],
                'total' => count($user_ids)
            ];
            
            foreach ($user_ids as $user_id) {
                try {
                    // Verificar usuario
                    $stmt = $this->conn->prepare("SELECT name, email, plan FROM " . $this->users_table . " WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $user = $stmt->fetch();
                    
                    if (!$user) {
                        $results['failed'][] = ['user_id' => $user_id, 'error' => 'Usuario no encontrado'];
                        continue;
                    }
                    
                    // Calcular nueva fecha de expiración
                    $expires_at = null;
                    if ($plan_slug !== 'free') {
                        $expires_at = date('Y-m-d', strtotime("+{$extend_months} months"));
                    }
                    
                    // Calcular precio (con descuento si aplica)
                    $final_price = $free_trial ? 0 : ($plan['price'] * (100 - $discount) / 100);
                    
                    // Actualizar usuario
                    $stmt = $this->conn->prepare("
                        UPDATE " . $this->users_table . " 
                        SET plan = ?, expires_at = ?, status = 'active', revenue = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$plan_slug, $expires_at, $final_price, $user_id]);
                    
                    // Registrar promoción como pago si no es trial gratuito
                    if (!$free_trial && $final_price > 0) {
                        $promotion_id = 'promo_' . uniqid();
                        $this->createPaymentRecord($promotion_id, [
                            'user_id' => $user_id,
                            'plan_slug' => $plan_slug,
                            'amount' => $final_price,
                            'payment_method' => 'promotion',
                            'metadata' => [
                                'original_price' => $plan['price'],
                                'discount' => $discount,
                                'promotion_type' => 'bulk_upgrade'
                            ]
                        ], 'completed');
                    }
                    
                    $results['success'][] = [
                        'user_id' => $user_id,
                        'user_name' => $user['name'],
                        'old_plan' => $user['plan'],
                        'new_plan' => $plan_slug,
                        'final_price' => $final_price,
                        'expires_at' => $expires_at
                    ];
                    
                } catch(Exception $e) {
                    $results['failed'][] = [
                        'user_id' => $user_id,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            $this->conn->commit();
            
            $this->logActivity('bulk_promotion', "Promoción masiva a plan $plan_slug", null, [
                'plan_slug' => $plan_slug,
                'user_ids' => $user_ids,
                'results' => $results,
                'params' => $params
            ]);
            
            $message = "Promoción completada. Exitosos: " . count($results['success']) . ", Fallidos: " . count($results['failed']);
            
            return $this->jsonResponse(true, $results, $message);
            
        } catch(Exception $e) {
            $this->conn->rollBack();
            error_log("Error en promoción masiva: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Obtener métricas de conversión
    public function getConversionMetrics($period_days = 30) {
        try {
            $metrics = [];
            
            // Conversiones de free a paid
            $stmt = $this->conn->prepare("
                SELECT 
                    JSON_EXTRACT(metadata, '$.old_plan') as from_plan,
                    JSON_EXTRACT(metadata, '$.new_plan') as to_plan,
                    COUNT(*) as conversions
                FROM admin_logs 
                WHERE action = 'plan_changed' 
                AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                AND JSON_EXTRACT(metadata, '$.old_plan') = 'free'
                AND JSON_EXTRACT(metadata, '$.new_plan') != 'free'
                GROUP BY to_plan
            ");
            $stmt->execute([$period_days]);
            $free_to_paid = $stmt->fetchAll();
            
            $metrics['free_to_paid'] = [];
            foreach ($free_to_paid as $conversion) {
                $metrics['free_to_paid'][trim($conversion['to_plan'], '"')] = intval($conversion['conversions']);
            }
            
            // Upgrades entre planes pagos
            $stmt = $this->conn->prepare("
                SELECT 
                    JSON_EXTRACT(metadata, '$.old_plan') as from_plan,
                    JSON_EXTRACT(metadata, '$.new_plan') as to_plan,
                    COUNT(*) as upgrades
                FROM admin_logs 
                WHERE action = 'plan_changed' 
                AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                AND JSON_EXTRACT(metadata, '$.old_plan') != 'free'
                AND JSON_EXTRACT(metadata, '$.new_plan') != 'free'
                GROUP BY from_plan, to_plan
            ");
            $stmt->execute([$period_days]);
            $plan_upgrades = $stmt->fetchAll();
            
            $metrics['plan_upgrades'] = [];
            foreach ($plan_upgrades as $upgrade) {
                $from = trim($upgrade['from_plan'], '"');
                $to = trim($upgrade['to_plan'], '"');
                $metrics['plan_upgrades'][] = [
                    'from' => $from,
                    'to' => $to,
                    'count' => intval($upgrade['upgrades'])
                ];
            }
            
            // Downgrades y cancelaciones
            $stmt = $this->conn->prepare("
                SELECT 
                    JSON_EXTRACT(metadata, '$.old_plan') as from_plan,
                    JSON_EXTRACT(metadata, '$.new_plan') as to_plan,
                    COUNT(*) as downgrades
                FROM admin_logs 
                WHERE action = 'plan_changed' 
                AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                AND JSON_EXTRACT(metadata, '$.new_plan') = 'free'
                GROUP BY from_plan
            ");
            $stmt->execute([$period_days]);
            $downgrades = $stmt->fetchAll();
            
            $metrics['downgrades'] = [];
            foreach ($downgrades as $downgrade) {
                $metrics['downgrades'][trim($downgrade['from_plan'], '"')] = intval($downgrade['downgrades']);
            }
            
            // Tasa de renovación
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) as renewals
                FROM payments 
                WHERE status = 'completed' 
                AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                AND user_id IN (
                    SELECT user_id FROM payments p2 
                    WHERE p2.status = 'completed' 
                    AND p2.created_at < payments.created_at
                )
            ");
            $stmt->execute([$period_days]);
            $renewals = $stmt->fetch();
            
            $metrics['renewal_rate'] = intval($renewals['renewals']);
            
            // Churn rate (usuarios que expiraron y no renovaron)
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) as churned
                FROM " . $this->users_table . "
                WHERE expires_at BETWEEN DATE_SUB(CURDATE(), INTERVAL ? DAY) AND CURDATE()
                AND status = 'expired'
                AND id NOT IN (
                    SELECT user_id FROM payments 
                    WHERE status = 'completed' 
                    AND created_at >= expires_at
                )
            ");
            $stmt->execute([$period_days]);
            $churned = $stmt->fetch();
            
            $metrics['churn_rate'] = intval($churned['churned']);
            
            $metrics['period_days'] = $period_days;
            $metrics['generated_at'] = date('c');
            
            return $this->jsonResponse(true, $metrics, 'Métricas de conversión obtenidas exitosamente');
            
        } catch(Exception $e) {
            error_log("Error obteniendo métricas de conversión: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Obtener análisis de retención
    public function getRetentionAnalysis() {
        try {
            $analysis = [];
            
            // Retención por cohortes (usuarios por mes de registro)
            $stmt = $this->conn->prepare("
                SELECT 
                    YEAR(created_at) as year,
                    MONTH(created_at) as month,
                    COUNT(*) as total_users,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users,
                    SUM(CASE WHEN plan != 'free' THEN 1 ELSE 0 END) as paid_users
                FROM " . $this->users_table . "
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                GROUP BY YEAR(created_at), MONTH(created_at)
                ORDER BY year DESC, month DESC
            ");
            $stmt->execute();
            $cohorts = $stmt->fetchAll();
            
            $analysis['cohorts'] = [];
            foreach ($cohorts as $cohort) {
                $retention_rate = $cohort['total_users'] > 0 ? 
                    ($cohort['active_users'] / $cohort['total_users']) * 100 : 0;
                
                $analysis['cohorts'][] = [
                    'period' => $cohort['year'] . '-' . str_pad($cohort['month'], 2, '0', STR_PAD_LEFT),
                    'total_users' => intval($cohort['total_users']),
                    'active_users' => intval($cohort['active_users']),
                    'paid_users' => intval($cohort['paid_users']),
                    'retention_rate' => round($retention_rate, 2)
                ];
            }
            
            // Análisis de vida del cliente (Customer Lifetime Value aproximado)
            $stmt = $this->conn->prepare("
                SELECT 
                    plan,
                    AVG(total_revenue) as avg_revenue,
                    AVG(DATEDIFF(COALESCE(expires_at, CURDATE()), created_at)) as avg_lifespan_days
                FROM " . $this->users_table . "
                WHERE plan != 'free'
                GROUP BY plan
            ");
            $stmt->execute();
            $clv_data = $stmt->fetchAll();
            
            $analysis['customer_lifetime_value'] = [];
            foreach ($clv_data as $clv) {
                $analysis['customer_lifetime_value'][] = [
                    'plan' => $clv['plan'],
                    'avg_revenue' => floatval($clv['avg_revenue']),
                    'avg_lifespan_days' => intval($clv['avg_lifespan_days']),
                    'estimated_clv' => floatval($clv['avg_revenue']) * (intval($clv['avg_lifespan_days']) / 30)
                ];
            }
            
            return $this->jsonResponse(true, $analysis, 'Análisis de retención obtenido exitosamente');
            
        } catch(Exception $e) {
            error_log("Error obteniendo análisis de retención: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
}

// Crear tablas adicionales si no existen
function createAdditionalTables($conn) {
    try {
        // Tabla de pagos (si no fue creada anteriormente)
        $conn->exec("
            CREATE TABLE IF NOT EXISTS payments (
                id INT PRIMARY KEY AUTO_INCREMENT,
                payment_id VARCHAR(100) UNIQUE NOT NULL,
                user_id INT NOT NULL,
                plan_slug VARCHAR(50) NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                payment_method VARCHAR(50) NOT NULL,
                status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
                transaction_data JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_status (status),
                INDEX idx_created_at (created_at)
            )
        ");
        
        // Tabla de promociones
        $conn->exec("
            CREATE TABLE IF NOT EXISTS promotions (
                id INT PRIMARY KEY AUTO_INCREMENT,
                code VARCHAR(50) UNIQUE NOT NULL,
                name VARCHAR(255) NOT NULL,
                discount_percent DECIMAL(5,2) DEFAULT 0,
                discount_amount DECIMAL(10,2) DEFAULT 0,
                valid_from DATE NOT NULL,
                valid_until DATE NOT NULL,
                max_uses INT DEFAULT NULL,
                used_count INT DEFAULT 0,
                applicable_plans JSON,
                status ENUM('active', 'inactive', 'expired') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
    } catch(Exception $e) {
        error_log("Error creando tablas adicionales: " . $e->getMessage());
    }
}

// Inicializar datos mock de pagos
function initializeMockPayments($conn) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM payments");
        $stmt->execute();
        $count = $stmt->fetch()['count'];
        
        if ($count == 0) {
            // Obtener algunos usuarios para crear pagos mock
            $stmt = $conn->prepare("SELECT id, plan FROM users WHERE plan != 'free' LIMIT 5");
            $stmt->execute();
            $users = $stmt->fetchAll();
            
            // Obtener precios de planes
            $stmt = $conn->prepare("SELECT slug, price FROM plans");
            $stmt->execute();
            $plan_prices = [];
            while ($row = $stmt->fetch()) {
                $plan_prices[$row['slug']] = $row['price'];
            }
            
            foreach ($users as $user) {
                if (isset($plan_prices[$user['plan']])) {
                    // Crear algunos pagos históricos
                    for ($i = 1; $i <= 3; $i++) {
                        $payment_id = 'pay_mock_' . $user['id'] . '_' . $i;
                        $date = date('Y-m-d H:i:s', strtotime("-{$i} months"));
                        
                        $stmt = $conn->prepare("
                            INSERT INTO payments 
                            (payment_id, user_id, plan_slug, amount, payment_method, status, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        
                        $stmt->execute([
                            $payment_id,
                            $user['id'],
                            $user['plan'],
                            $plan_prices[$user['plan']],
                            'credit_card',
                            'completed',
                            $date
                        ]);
                    }
                }
            }
        }
    } catch(Exception $e) {
        error_log("Error inicializando pagos mock: " . $e->getMessage());
    }
}

// Rate limiting para plans (reutilizar de users.php)
class PlanRateLimiter {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    public function isAllowed($ip, $limit = 150, $window = 3600) {
        try {
            $stmt = $this->conn->prepare("DELETE FROM rate_limits WHERE created_at < ?");
            $stmt->execute([date('Y-m-d H:i:s', time() - $window)]);
            
            $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM rate_limits WHERE ip_address = ? AND created_at > ?");
            $stmt->execute([$ip, date('Y-m-d H:i:s', time() - $window)]);
            $current_count = $stmt->fetch()['count'];
            
            if ($current_count >= $limit) {
                return false;
            }
            
            $stmt = $this->conn->prepare("INSERT INTO rate_limits (ip_address, created_at) VALUES (?, NOW())");
            $stmt->execute([$ip]);
            
            return true;
            
        } catch(Exception $e) {
            return true;
        }
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
    
    // Crear tablas adicionales
    createAdditionalTables($db);
    
    // Rate limiting
    $rate_limiter = new PlanRateLimiter($db);
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    if (!$rate_limiter->isAllowed($client_ip, 150, 3600)) {
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
    
    $plan_manager = new PlanManager($db);
    
    if (!$plan_manager->validateToken($auth_header)) {
        http_response_code(401);
        echo json_encode([
            'success' => false, 
            'message' => 'Token de autorización inválido',
            'timestamp' => date('c')
        ]);
        exit();
    }
    
    // Inicializar datos mock
    initializeMockPlans($db);
    initializeMockPayments($db);
    
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
            if (count($path_parts) === 1 && $path_parts[0] === 'plans') {
                // GET /plans - Listar planes
                $plan_manager->getPlans($query_params);
                
            } elseif (count($path_parts) === 2 && $path_parts[0] === 'plans' && $path_parts[1] === 'stats') {
                // GET /plans/stats - Estadísticas de planes
                $plan_manager->getPlanStats();
                
            } elseif (count($path_parts) === 2 && $path_parts[0] === 'plans' && is_numeric($path_parts[1])) {
                // GET /plans/{id} - Obtener plan específico
                $plan_manager->getPlanById($path_parts[1]);
                
            } elseif (count($path_parts) === 1 && $path_parts[0] === 'subscriptions') {
                // GET /subscriptions - Listar suscripciones
                $plan_manager->getSubscriptions($query_params);
                
            } elseif (count($path_parts) === 2 && $path_parts[0] === 'subscriptions' && $path_parts[1] === 'expiring') {
                // GET /subscriptions/expiring - Suscripciones próximas a expirar
                $days = isset($query_params['days']) ? intval($query_params['days']) : 30;
                $plan_manager->getExpiringSubscriptions($days);
                
            } elseif (count($path_parts) === 2 && $path_parts[0] === 'billing' && $path_parts[1] === 'revenue') {
                // GET /billing/revenue - Reportes de ingresos
                $period = $query_params['period'] ?? 'monthly';
                $year = $query_params['year'] ?? null;
                $month = $query_params['month'] ?? null;
                $plan_manager->getRevenueReport($period, $year, $month);
                
            } elseif (count($path_parts) === 2 && $path_parts[0] === 'billing' && $path_parts[1] === 'failed-payments') {
                // GET /billing/failed-payments - Pagos fallidos
                $plan_manager->getFailedPayments($query_params);
                
            } elseif (count($path_parts) === 2 && $path_parts[0] === 'analytics' && $path_parts[1] === 'conversions') {
                // GET /analytics/conversions - Métricas de conversión
                $period_days = isset($query_params['period_days']) ? intval($query_params['period_days']) : 30;
                $plan_manager->getConversionMetrics($period_days);
                
            } elseif (count($path_parts) === 2 && $path_parts[0] === 'analytics' && $path_parts[1] === 'retention') {
                // GET /analytics/retention - Análisis de retención
                $plan_manager->getRetentionAnalysis();
                
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Endpoint no encontrado']);
            }
            break;
            
        case 'POST':
            if (count($path_parts) === 1 && $path_parts[0] === 'plans') {
                // POST /plans - Crear plan
                $plan_manager->createPlan($input);
                
            } elseif (count($path_parts) === 2 && $path_parts[0] === 'billing' && $path_parts[1] === 'process-payment') {
                // POST /billing/process-payment - Procesar pago
                $plan_manager->processPayment($input);
                
            } elseif (count($path_parts) === 3 && $path_parts[0] === 'plans' && is_numeric($path_parts[1]) && $path_parts[2] === 'promote-users') {
                // POST /plans/{id}/promote-users - Promocionar usuarios a plan
                $plan_slug = $input['plan_slug'] ?? '';
                $user_ids = $input['user_ids'] ?? [];
                $params = $input['params'] ?? [];
                $plan_manager->promoteUsersToplan($plan_slug, $user_ids, $params);
                
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Endpoint no encontrado']);
            }
            break;
            
        case 'PUT':
            if (count($path_parts) === 2 && $path_parts[0] === 'plans' && is_numeric($path_parts[1])) {
                // PUT /plans/{id} - Actualizar plan
                $plan_manager->updatePlan($path_parts[1], $input);
                
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Endpoint no encontrado']);
            }
            break;
            
        case 'DELETE':
            if (count($path_parts) === 2 && $path_parts[0] === 'plans' && is_numeric($path_parts[1])) {
                // DELETE /plans/{id} - Eliminar plan
                $plan_manager->deletePlan($path_parts[1]);
                
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
    error_log("Error general en plans.php: " . $e->getMessage());
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
 * === GESTIÓN DE PLANES ===
 * GET /plans - Listar planes con filtros
 * Parámetros: page, per_page, status, search, order_by, order_dir
 * 
 * GET /plans/{id} - Obtener plan específico
 * 
 * GET /plans/stats - Estadísticas de planes (MRR, suscripciones, etc.)
 * 
 * POST /plans - Crear nuevo plan
 * Body: {name, slug, price, features[], max_reservations, max_locations, status?}
 * 
 * PUT /plans/{id} - Actualizar plan
 * Body: campos a actualizar
 * 
 * DELETE /plans/{id} - Eliminar plan
 * 
 * === SUSCRIPCIONES ===
 * GET /subscriptions - Listar suscripciones activas
 * Parámetros: page, per_page, plan, status, search, expiring
 * 
 * GET /subscriptions/expiring - Suscripciones próximas a expirar
 * Parámetros: days (default: 30)
 * 
 * === FACTURACIÓN ===
 * POST /billing/process-payment - Procesar pago de plan
 * Body: {user_id, plan_slug, amount, payment_method, metadata?}
 * 
 * GET /billing/revenue - Reportes de ingresos
 * Parámetros: period (daily|monthly|yearly), year?, month?
 * 
 * GET /billing/failed-payments - Pagos fallidos
 * Parámetros: page, per_page, date_from, date_to
 * 
 * === PROMOCIONES ===
 * POST /plans/{id}/promote-users - Promocionar usuarios a plan específico
 * Body: {plan_slug, user_ids[], params: {extend_months?, discount?, free_trial?}}
 * 
 * === ANALÍTICAS ===
 * GET /analytics/conversions - Métricas de conversión
 * Parámetros: period_days (default: 30)
 * 
 * GET /analytics/retention - Análisis de retención y Customer Lifetime Value
 */

?>

// <--- FIN DEL ARCHIVO plans.php 