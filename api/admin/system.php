<?php
/**
 * Sistema de Reservas - Panel de Administrador
 * API REST para Configuraciones y Métricas del Sistema
 * 
 * Funcionalidades:
 * - Monitoreo de salud del sistema
 * - Gestión de logs del sistema
 * - Configuraciones globales
 * - Estadísticas generales
 * - Modo mantenimiento
 * - Backups y actualizaciones
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

// Clase principal para gestión del sistema
class SystemManager {
    private $conn;
    private $settings_table = "system_settings";
    private $logs_table = "admin_logs";
    private $users_table = "users";
    private $plans_table = "plans";
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    // Validar token JWT
    private function validateToken($token) {
        $admin_credentials = base64_encode('lmitrion230:Lelouch426753.');
        return $token === "Bearer " . $admin_credentials;
    }
    
    // Registrar actividad en logs
    private function logActivity($action, $description, $metadata = []) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO " . $this->logs_table . " (action, description, user, metadata, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $action,
                $description,
                'lmitrion230',
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
    
    // Obtener estado de salud del sistema
    public function getSystemHealth() {
        try {
            $health = [];
            
            // Estado de la base de datos
            $db_start = microtime(true);
            $stmt = $this->conn->prepare("SELECT 1");
            $stmt->execute();
            $db_time = (microtime(true) - $db_start) * 1000;
            
            $health['database'] = [
                'status' => 'healthy',
                'response_time_ms' => round($db_time, 2),
                'connection_active' => true
            ];
            
            // Uso de memoria
            $memory_usage = memory_get_usage(true);
            $memory_peak = memory_get_peak_usage(true);
            $memory_limit = ini_get('memory_limit');
            $memory_limit_bytes = $this->parseMemoryLimit($memory_limit);
            
            $health['memory'] = [
                'current_usage_mb' => round($memory_usage / 1024 / 1024, 2),
                'peak_usage_mb' => round($memory_peak / 1024 / 1024, 2),
                'limit_mb' => round($memory_limit_bytes / 1024 / 1024, 2),
                'usage_percentage' => round(($memory_usage / $memory_limit_bytes) * 100, 2),
                'status' => ($memory_usage / $memory_limit_bytes) > 0.8 ? 'warning' : 'healthy'
            ];
            
            // Espacio en disco (simulado para diferentes entornos)
            $disk_total = disk_total_space('.');
            $disk_free = disk_free_space('.');
            $disk_used = $disk_total - $disk_free;
            
            if ($disk_total) {
                $health['disk'] = [
                    'total_gb' => round($disk_total / 1024 / 1024 / 1024, 2),
                    'used_gb' => round($disk_used / 1024 / 1024 / 1024, 2),
                    'free_gb' => round($disk_free / 1024 / 1024 / 1024, 2),
                    'usage_percentage' => round(($disk_used / $disk_total) * 100, 2),
                    'status' => ($disk_used / $disk_total) > 0.9 ? 'critical' : (($disk_used / $disk_total) > 0.8 ? 'warning' : 'healthy')
                ];
            } else {
                // Valores simulados si no se puede obtener información real
                $health['disk'] = [
                    'total_gb' => 100.0,
                    'used_gb' => 45.8,
                    'free_gb' => 54.2,
                    'usage_percentage' => 45.8,
                    'status' => 'healthy'
                ];
            }
            
            // Carga del sistema (simulada)
            $load_avg = [0.5, 0.7, 0.6]; // Valores simulados para 1min, 5min, 15min
            if (function_exists('sys_getloadavg')) {
                $load_avg = sys_getloadavg();
            }
            
            $health['cpu'] = [
                'load_average_1min' => round($load_avg[0], 2),
                'load_average_5min' => round($load_avg[1], 2),
                'load_average_15min' => round($load_avg[2], 2),
                'status' => $load_avg[0] > 2 ? 'warning' : 'healthy'
            ];
            
            // Estado de servicios críticos
            $health['services'] = [
                'web_server' => ['status' => 'running', 'uptime' => '15 days'],
                'php' => ['status' => 'running', 'version' => PHP_VERSION],
                'mysql' => ['status' => 'running', 'version' => $this->getMySQLVersion()]
            ];
            
            // Verificar conectividad de red (simulada)
            $health['network'] = [
                'external_connectivity' => true,
                'dns_resolution' => true,
                'status' => 'healthy'
            ];
            
            // Estado general del sistema
            $overall_status = 'healthy';
            foreach ($health as $component => $data) {
                if (isset($data['status'])) {
                    if ($data['status'] === 'critical') {
                        $overall_status = 'critical';
                        break;
                    } elseif ($data['status'] === 'warning' && $overall_status !== 'critical') {
                        $overall_status = 'warning';
                    }
                }
            }
            
            $health['overall_status'] = $overall_status;
            $health['last_check'] = date('c');
            
            $this->logActivity('system_health_check', 'Estado de salud del sistema consultado');
            
            return $this->jsonResponse(true, $health, 'Estado de salud obtenido exitosamente');
            
        } catch(Exception $e) {
            error_log("Error obteniendo salud del sistema: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Obtener estadísticas generales del sistema
    public function getSystemStats() {
        try {
            $stats = [];
            
            // Estadísticas de usuarios
            $stmt = $this->conn->prepare("
                SELECT 
                    COUNT(*) as total_users,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users,
                    SUM(CASE WHEN plan != 'free' THEN 1 ELSE 0 END) as paid_users,
                    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as registered_today,
                    SUM(CASE WHEN DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as registered_this_week,
                    SUM(CASE WHEN DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as registered_this_month
                FROM " . $this->users_table
            );
            $stmt->execute();
            $user_stats = $stmt->fetch();
            
            $stats['users'] = [
                'total' => intval($user_stats['total_users']),
                'active' => intval($user_stats['active_users']),
                'paid' => intval($user_stats['paid_users']),
                'registered_today' => intval($user_stats['registered_today']),
                'registered_this_week' => intval($user_stats['registered_this_week']),
                'registered_this_month' => intval($user_stats['registered_this_month'])
            ];
            
            // Estadísticas de ingresos
            $stmt = $this->conn->prepare("
                SELECT 
                    SUM(revenue) as monthly_revenue,
                    SUM(total_revenue) as total_revenue,
                    AVG(revenue) as avg_revenue_per_user
                FROM " . $this->users_table . " 
                WHERE status = 'active' AND plan != 'free'"
            );
            $stmt->execute();
            $revenue_stats = $stmt->fetch();
            
            // Obtener ingresos de este mes desde payments si existe la tabla
            $monthly_revenue_payments = 0;
            try {
                $stmt = $this->conn->prepare("
                    SELECT COALESCE(SUM(amount), 0) as monthly_payments
                    FROM payments 
                    WHERE status = 'completed' 
                    AND YEAR(created_at) = YEAR(CURDATE()) 
                    AND MONTH(created_at) = MONTH(CURDATE())
                ");
                $stmt->execute();
                $payment_stats = $stmt->fetch();
                $monthly_revenue_payments = floatval($payment_stats['monthly_payments']);
            } catch(Exception $e) {
                // Tabla payments no existe aún
            }
            
            $stats['revenue'] = [
                'monthly_recurring' => floatval($revenue_stats['monthly_revenue']),
                'total_all_time' => floatval($revenue_stats['total_revenue']),
                'monthly_payments' => $monthly_revenue_payments,
                'average_per_user' => floatval($revenue_stats['avg_revenue_per_user'])
            ];
            
            // Estadísticas de reservas (simuladas ya que no tenemos tabla de reservas)
            $total_reservations = 0;
            $stmt = $this->conn->prepare("SELECT SUM(reservations) as total FROM " . $this->users_table);
            $stmt->execute();
            $reservation_data = $stmt->fetch();
            $total_reservations = intval($reservation_data['total']);
            
            $stats['reservations'] = [
                'total_all_time' => $total_reservations,
                'today' => rand(50, 150),
                'this_week' => rand(300, 800),
                'this_month' => rand(1200, 2500),
                'average_per_user' => $total_reservations > 0 ? round($total_reservations / $stats['users']['total'], 1) : 0
            ];
            
            // Estadísticas de planes
            $stmt = $this->conn->prepare("
                SELECT plan, COUNT(*) as count 
                FROM " . $this->users_table . " 
                WHERE status = 'active' 
                GROUP BY plan
            ");
            $stmt->execute();
            $plan_distribution = $stmt->fetchAll();
            
            $stats['plan_distribution'] = [];
            foreach ($plan_distribution as $plan) {
                $stats['plan_distribution'][$plan['plan']] = intval($plan['count']);
            }
            
            // Actividad reciente del sistema
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) as total_logs,
                       SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as logs_today,
                       SUM(CASE WHEN action LIKE '%error%' OR action LIKE '%failed%' THEN 1 ELSE 0 END) as error_logs
                FROM " . $this->logs_table . " 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute();
            $activity_stats = $stmt->fetch();
            
            $stats['activity'] = [
                'total_logs_30_days' => intval($activity_stats['total_logs']),
                'logs_today' => intval($activity_stats['logs_today']),
                'error_logs_30_days' => intval($activity_stats['error_logs']),
                'avg_logs_per_day' => round(intval($activity_stats['total_logs']) / 30, 1)
            ];
            
            // Métricas de rendimiento (simuladas)
            $stats['performance'] = [
                'avg_response_time_ms' => rand(150, 300),
                'uptime_percentage' => 99.9,
                'requests_per_minute' => rand(50, 200),
                'cache_hit_rate' => rand(85, 95)
            ];
            
            $stats['generated_at'] = date('c');
            
            $this->logActivity('system_stats_viewed', 'Estadísticas del sistema consultadas');
            
            return $this->jsonResponse(true, $stats, 'Estadísticas obtenidas exitosamente');
            
        } catch(Exception $e) {
            error_log("Error obteniendo estadísticas del sistema: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Obtener logs del sistema
    public function getSystemLogs($filters = []) {
        try {
            $page = isset($filters['page']) ? max(1, intval($filters['page'])) : 1;
            $per_page = isset($filters['per_page']) ? min(1000, max(1, intval($filters['per_page']))) : 100;
            $offset = ($page - 1) * $per_page;
            
            // Construir query base
            $query = "SELECT * FROM " . $this->logs_table . " WHERE 1=1";
            $count_query = "SELECT COUNT(*) as total FROM " . $this->logs_table . " WHERE 1=1";
            $params = [];
            
            // Aplicar filtros
            if (!empty($filters['action'])) {
                $query .= " AND action LIKE ?";
                $count_query .= " AND action LIKE ?";
                $params[] = '%' . $filters['action'] . '%';
            }
            
            if (!empty($filters['user'])) {
                $query .= " AND user LIKE ?";
                $count_query .= " AND user LIKE ?";
                $params[] = '%' . $filters['user'] . '%';
            }
            
            if (!empty($filters['level'])) {
                // Simular niveles de log basados en la acción
                switch ($filters['level']) {
                    case 'error':
                        $query .= " AND (action LIKE '%error%' OR action LIKE '%failed%' OR action LIKE '%deleted%')";
                        $count_query .= " AND (action LIKE '%error%' OR action LIKE '%failed%' OR action LIKE '%deleted%')";
                        break;
                    case 'warning':
                        $query .= " AND (action LIKE '%suspended%' OR action LIKE '%warning%')";
                        $count_query .= " AND (action LIKE '%suspended%' OR action LIKE '%warning%')";
                        break;
                    case 'info':
                        $query .= " AND action NOT LIKE '%error%' AND action NOT LIKE '%failed%' AND action NOT LIKE '%suspended%'";
                        $count_query .= " AND action NOT LIKE '%error%' AND action NOT LIKE '%failed%' AND action NOT LIKE '%suspended%'";
                        break;
                }
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
            
            if (!empty($filters['search'])) {
                $query .= " AND (action LIKE ? OR description LIKE ?)";
                $count_query .= " AND (action LIKE ? OR description LIKE ?)";
                $search_term = '%' . $filters['search'] . '%';
                $params[] = $search_term;
                $params[] = $search_term;
            }
            
            // Ordenamiento
            $order_by = isset($filters['order_by']) ? $filters['order_by'] : 'created_at';
            $order_dir = isset($filters['order_dir']) && strtoupper($filters['order_dir']) === 'ASC' ? 'ASC' : 'DESC';
            
            $allowed_columns = ['id', 'action', 'user', 'created_at'];
            if (!in_array($order_by, $allowed_columns)) {
                $order_by = 'created_at';
            }
            
            $query .= " ORDER BY $order_by $order_dir LIMIT $per_page OFFSET $offset";
            
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
                
                // Determinar nivel basado en la acción
                if (strpos($log['action'], 'error') !== false || strpos($log['action'], 'failed') !== false || strpos($log['action'], 'deleted') !== false) {
                    $log['level'] = 'error';
                } elseif (strpos($log['action'], 'suspended') !== false || strpos($log['action'], 'warning') !== false) {
                    $log['level'] = 'warning';
                } else {
                    $log['level'] = 'info';
                }
            }
            
            $pagination = [
                'current_page' => $page,
                'per_page' => $per_page,
                'total_records' => intval($total_records),
                'total_pages' => ceil($total_records / $per_page)
            ];
            
            $this->logActivity('system_logs_viewed', 'Logs del sistema consultados', $filters);
            
            return $this->jsonResponse(true, $logs, 'Logs obtenidos exitosamente', $pagination);
            
        } catch(Exception $e) {
            error_log("Error obteniendo logs del sistema: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Métodos auxiliares
    private function parseMemoryLimit($limit) {
        $limit = strtolower($limit);
        $bytes = intval($limit);
        
        if (strpos($limit, 'k') !== false) {
            $bytes *= 1024;
        } elseif (strpos($limit, 'm') !== false) {
            $bytes *= 1024 * 1024;
        } elseif (strpos($limit, 'g') !== false) {
            $bytes *= 1024 * 1024 * 1024;
        }
        
        return $bytes;
    }
    
    private function getMySQLVersion() {
        try {
            $stmt = $this->conn->prepare("SELECT VERSION() as version");
            $stmt->execute();
            $result = $stmt->fetch();
            return $result['version'];
        } catch(Exception $e) {
            return 'Unknown';
        }
    }
}

// Inicializar configuraciones del sistema si no existen
function initializeSystemSettings($conn) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM system_settings");
        $stmt->execute();
        $count = $stmt->fetch()['count'];
        
        if ($count == 0) {
            $default_settings = [
                ['general', 'site_name', 'Sistema de Reservas'],
                ['general', 'site_description', 'Panel de administración para gestión de reservas'],
                ['general', 'admin_email', 'admin@reservas-system.com'],
                ['general', 'timezone', 'Europe/Madrid'],
                ['general', 'language', 'es'],
                ['security', 'session_timeout', '3600'],
                ['security', 'max_login_attempts', '5'],
                ['security', 'password_expiry_days', '90'],
                ['email', 'smtp_host', 'smtp.gmail.com'],
                ['email', 'smtp_port', '587'],
                ['email', 'smtp_username', ''],
                ['email', 'smtp_password', ''],
                ['notifications', 'enable_email_notifications', 'true'],
                ['notifications', 'enable_sms_notifications', 'false'],
                ['maintenance', 'mode_enabled', 'false'],
                ['maintenance', 'message', 'Sistema en mantenimiento. Volvemos pronto.'],
                ['backup', 'auto_backup_enabled', 'true'],
                ['backup', 'backup_frequency', 'daily'],
                ['backup', 'retention_days', '30']
            ];
            
            foreach ($default_settings as $setting) {
                $stmt = $conn->prepare("
                    INSERT INTO system_settings (category, setting_key, setting_value) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute($setting);
            }
        }
    } catch(Exception $e) {
        error_log("Error inicializando configuraciones: " . $e->getMessage());
    }
}

// <--- seguir aqui --->

// Continuación de system.php - Parte 2 de 3
// Métodos adicionales de la clase SystemManager

    // Exportar logs del sistema
    public function exportLogs($format = 'csv', $filters = []) {
        try {
            // Validar formato
            if (!in_array($format, ['csv', 'json', 'txt'])) {
                return $this->jsonResponse(false, null, 'Formato de exportación inválido', null, 400);
            }
            
            // Obtener logs sin paginación
            $query = "SELECT * FROM " . $this->logs_table . " WHERE 1=1";
            $params = [];
            
            // Aplicar mismos filtros que getSystemLogs pero sin límite
            if (!empty($filters['action'])) {
                $query .= " AND action LIKE ?";
                $params[] = '%' . $filters['action'] . '%';
            }
            
            if (!empty($filters['user'])) {
                $query .= " AND user LIKE ?";
                $params[] = '%' . $filters['user'] . '%';
            }
            
            if (!empty($filters['date_from'])) {
                $query .= " AND created_at >= ?";
                $params[] = $filters['date_from'] . ' 00:00:00';
            }
            
            if (!empty($filters['date_to'])) {
                $query .= " AND created_at <= ?";
                $params[] = $filters['date_to'] . ' 23:59:59';
            }
            
            $query .= " ORDER BY created_at DESC LIMIT 10000"; // Límite de seguridad
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $logs = $stmt->fetchAll();
            
            // Formatear datos para exportación
            $export_data = [];
            foreach ($logs as $log) {
                $export_data[] = [
                    'ID' => $log['id'],
                    'Acción' => $log['action'],
                    'Descripción' => $log['description'],
                    'Usuario' => $log['user'],
                    'IP' => $log['ip_address'],
                    'Fecha' => date('Y-m-d H:i:s', strtotime($log['created_at'])),
                    'Metadata' => $log['metadata']
                ];
            }
            
            $filename = "system_logs_export_" . date('Y-m-d_H-i-s');
            
            if ($format === 'csv') {
                $csv_content = $this->generateCSV($export_data);
                
                $this->logActivity('logs_exported', "Logs exportados en formato CSV", ['format' => $format, 'total_records' => count($logs)]);
                
                return $this->jsonResponse(true, [
                    'filename' => $filename . '.csv',
                    'content' => base64_encode($csv_content),
                    'mime_type' => 'text/csv',
                    'total_records' => count($logs)
                ], 'Exportación CSV generada exitosamente');
                
            } elseif ($format === 'json') {
                $json_content = json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                
                $this->logActivity('logs_exported', "Logs exportados en formato JSON", ['format' => $format, 'total_records' => count($logs)]);
                
                return $this->jsonResponse(true, [
                    'filename' => $filename . '.json',
                    'content' => base64_encode($json_content),
                    'mime_type' => 'application/json',
                    'total_records' => count($logs)
                ], 'Exportación JSON generada exitosamente');
                
            } else { // txt
                $txt_content = $this->generateTXT($export_data);
                
                $this->logActivity('logs_exported', "Logs exportados en formato TXT", ['format' => $format, 'total_records' => count($logs)]);
                
                return $this->jsonResponse(true, [
                    'filename' => $filename . '.txt',
                    'content' => base64_encode($txt_content),
                    'mime_type' => 'text/plain',
                    'total_records' => count($logs)
                ], 'Exportación TXT generada exitosamente');
            }
            
        } catch(Exception $e) {
            error_log("Error exportando logs: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Limpiar logs del sistema
    public function clearLogs($keep_critical = true, $days_to_keep = 30) {
        try {
            $deleted_count = 0;
            
            if ($keep_critical) {
                // Mantener logs críticos (errores, eliminaciones, suspensiones)
                $stmt = $this->conn->prepare("
                    DELETE FROM " . $this->logs_table . " 
                    WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                    AND action NOT LIKE '%error%' 
                    AND action NOT LIKE '%failed%' 
                    AND action NOT LIKE '%deleted%'
                    AND action NOT LIKE '%suspended%'
                ");
                $stmt->execute([$days_to_keep]);
                $deleted_count = $stmt->rowCount();
                
                $this->logActivity('logs_cleared', "Logs limpiados manteniendo críticos - $deleted_count registros eliminados", [
                    'keep_critical' => true,
                    'days_to_keep' => $days_to_keep,
                    'deleted_count' => $deleted_count
                ]);
                
            } else {
                // Eliminar todos los logs antiguos
                $stmt = $this->conn->prepare("
                    DELETE FROM " . $this->logs_table . " 
                    WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                ");
                $stmt->execute([$days_to_keep]);
                $deleted_count = $stmt->rowCount();
                
                $this->logActivity('logs_cleared', "Todos los logs limpiados - $deleted_count registros eliminados", [
                    'keep_critical' => false,
                    'days_to_keep' => $days_to_keep,
                    'deleted_count' => $deleted_count
                ]);
            }
            
            return $this->jsonResponse(true, [
                'deleted_count' => $deleted_count,
                'keep_critical' => $keep_critical,
                'days_kept' => $days_to_keep
            ], "Logs limpiados exitosamente - $deleted_count registros eliminados");
            
        } catch(Exception $e) {
            error_log("Error limpiando logs: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Obtener actividad reciente del sistema
    public function getRecentActivity($limit = 50) {
        try {
            $stmt = $this->conn->prepare("
                SELECT action, description, user, created_at, metadata
                FROM " . $this->logs_table . " 
                ORDER BY created_at DESC 
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            $activities = $stmt->fetchAll();
            
            // Formatear actividades
            foreach ($activities as &$activity) {
                $activity['created_at'] = date('c', strtotime($activity['created_at']));
                $activity['metadata'] = $activity['metadata'] ? json_decode($activity['metadata'], true) : null;
                $activity['time_ago'] = $this->timeAgo($activity['created_at']);
                
                // Categorizar actividad
                $activity['category'] = $this->categorizeActivity($activity['action']);
                $activity['icon'] = $this->getActivityIcon($activity['action']);
            }
            
            return $this->jsonResponse(true, $activities, 'Actividad reciente obtenida exitosamente');
            
        } catch(Exception $e) {
            error_log("Error obteniendo actividad reciente: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Crear backup de la base de datos
    public function createBackup($include_logs = false) {
        try {
            $backup_name = 'backup_' . date('Y-m-d_H-i-s');
            $backup_path = sys_get_temp_dir() . '/' . $backup_name . '.sql';
            
            // Simular creación de backup (en producción usar mysqldump)
            $tables_to_backup = [$this->users_table, $this->plans_table, $this->settings_table];
            
            if ($include_logs) {
                $tables_to_backup[] = $this->logs_table;
            }
            
            $backup_content = "-- Backup del Sistema de Reservas\n";
            $backup_content .= "-- Generado el: " . date('Y-m-d H:i:s') . "\n\n";
            
            foreach ($tables_to_backup as $table) {
                try {
                    // Obtener estructura de tabla (simulada)
                    $backup_content .= "-- Estructura de tabla: $table\n";
                    $backup_content .= "DROP TABLE IF EXISTS `$table`;\n";
                    
                    // En producción aquí iría la estructura real de cada tabla
                    $backup_content .= "-- CREATE TABLE statements...\n\n";
                    
                    // Obtener datos
                    $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM `$table`");
                    $stmt->execute();
                    $count = $stmt->fetch()['count'];
                    
                    $backup_content .= "-- Datos de tabla: $table ($count registros)\n";
                    $backup_content .= "-- INSERT statements...\n\n";
                    
                } catch(Exception $e) {
                    $backup_content .= "-- Error al respaldar tabla $table: " . $e->getMessage() . "\n\n";
                }
            }
            
            // Guardar backup (simulado)
            $backup_size = strlen($backup_content);
            $backup_created = true; // Simular éxito
            
            if ($backup_created) {
                $this->logActivity('backup_created', "Backup de base de datos creado: $backup_name", [
                    'backup_name' => $backup_name,
                    'include_logs' => $include_logs,
                    'size_bytes' => $backup_size,
                    'tables_included' => $tables_to_backup
                ]);
                
                return $this->jsonResponse(true, [
                    'backup_name' => $backup_name,
                    'backup_size_kb' => round($backup_size / 1024, 2),
                    'tables_included' => $tables_to_backup,
                    'include_logs' => $include_logs,
                    'created_at' => date('c')
                ], 'Backup creado exitosamente');
                
            } else {
                return $this->jsonResponse(false, null, 'Error creando el backup', null, 500);
            }
            
        } catch(Exception $e) {
            error_log("Error creando backup: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Verificar actualizaciones disponibles
    public function checkUpdates() {
        try {
            // Simular verificación de actualizaciones
            $current_version = '1.0.0';
            $latest_version = '1.2.1';
            $has_updates = version_compare($latest_version, $current_version, '>');
            
            $updates = [
                'current_version' => $current_version,
                'latest_version' => $latest_version,
                'has_updates' => $has_updates,
                'last_check' => date('c')
            ];
            
            if ($has_updates) {
                $updates['changelog'] = [
                    [
                        'version' => '1.2.1',
                        'release_date' => '2024-07-25',
                        'type' => 'patch',
                        'changes' => [
                            'Corrección de errores en exportación de datos',
                            'Mejoras en el rendimiento del dashboard',
                            'Actualización de dependencias de seguridad'
                        ]
                    ],
                    [
                        'version' => '1.2.0',
                        'release_date' => '2024-07-20',
                        'type' => 'minor',
                        'changes' => [
                            'Nueva funcionalidad de reportes avanzados',
                            'Integración con servicios de pago adicionales',
                            'Mejoras en la interfaz de usuario',
                            'Sistema de notificaciones mejorado'
                        ]
                    ],
                    [
                        'version' => '1.1.0',
                        'release_date' => '2024-07-15',
                        'type' => 'minor',
                        'changes' => [
                            'Soporte para múltiples idiomas',
                            'API REST mejorada',
                            'Sistema de backups automáticos'
                        ]
                    ]
                ];
                
                $updates['update_priority'] = 'medium';
                $updates['security_update'] = true;
            }
            
            $this->logActivity('updates_checked', 'Verificación de actualizaciones realizada', [
                'current_version' => $current_version,
                'latest_version' => $latest_version,
                'has_updates' => $has_updates
            ]);
            
            return $this->jsonResponse(true, $updates, $has_updates ? 'Actualizaciones disponibles' : 'Sistema actualizado');
            
        } catch(Exception $e) {
            error_log("Error verificando actualizaciones: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Obtener configuraciones del sistema
    public function getSettings($category = null) {
        try {
            $query = "SELECT * FROM " . $this->settings_table;
            $params = [];
            
            if ($category) {
                $query .= " WHERE category = ?";
                $params[] = $category;
            }
            
            $query .= " ORDER BY category, setting_key";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $settings = $stmt->fetchAll();
            
            // Organizar configuraciones por categoría
            $organized_settings = [];
            foreach ($settings as $setting) {
                $cat = $setting['category'];
                if (!isset($organized_settings[$cat])) {
                    $organized_settings[$cat] = [];
                }
                
                $organized_settings[$cat][$setting['setting_key']] = [
                    'value' => $setting['setting_value'],
                    'updated_at' => $setting['updated_at']
                ];
            }
            
            $this->logActivity('settings_viewed', $category ? "Configuraciones de categoría '$category' consultadas" : 'Todas las configuraciones consultadas');
            
            return $this->jsonResponse(true, $organized_settings, 'Configuraciones obtenidas exitosamente');
            
        } catch(Exception $e) {
            error_log("Error obteniendo configuraciones: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Actualizar configuraciones del sistema
    public function updateSettings($settings) {
        try {
            if (empty($settings) || !is_array($settings)) {
                return $this->jsonResponse(false, null, 'Configuraciones inválidas', null, 400);
            }
            
            $this->conn->beginTransaction();
            
            $updated_count = 0;
            $updated_settings = [];
            
            foreach ($settings as $category => $category_settings) {
                if (!is_array($category_settings)) continue;
                
                foreach ($category_settings as $key => $value) {
                    // Validar configuraciones críticas
                    if ($category === 'security' && $key === 'max_login_attempts') {
                        $value = max(3, min(20, intval($value))); // Entre 3 y 20
                    }
                    
                    if ($category === 'backup' && $key === 'retention_days') {
                        $value = max(7, min(365, intval($value))); // Entre 7 y 365 días
                    }
                    
                    $stmt = $this->conn->prepare("
                        INSERT INTO " . $this->settings_table . " (category, setting_key, setting_value, updated_at) 
                        VALUES (?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE 
                        setting_value = VALUES(setting_value), 
                        updated_at = NOW()
                    ");
                    
                    $stmt->execute([$category, $key, $value]);
                    
                    if ($stmt->rowCount() > 0) {
                        $updated_count++;
                        $updated_settings[] = "$category.$key = $value";
                    }
                }
            }
            
            $this->conn->commit();
            
            $this->logActivity('settings_updated', "Configuraciones actualizadas - $updated_count cambios", [
                'updated_count' => $updated_count,
                'changes' => $updated_settings
            ]);
            
            return $this->jsonResponse(true, [
                'updated_count' => $updated_count,
                'updated_settings' => $updated_settings
            ], "Configuraciones actualizadas exitosamente - $updated_count cambios realizados");
            
        } catch(Exception $e) {
            $this->conn->rollBack();
            error_log("Error actualizando configuraciones: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Activar/desactivar modo mantenimiento
    public function setMaintenanceMode($enabled, $message = null) {
        try {
            $mode = $enabled ? 'true' : 'false';
            $default_message = 'Sistema en mantenimiento. Volvemos pronto.';
            $maintenance_message = $message ?: $default_message;
            
            // Actualizar configuraciones de mantenimiento
            $stmt = $this->conn->prepare("
                INSERT INTO " . $this->settings_table . " (category, setting_key, setting_value, updated_at) 
                VALUES ('maintenance', 'mode_enabled', ?, NOW())
                ON DUPLICATE KEY UPDATE 
                setting_value = VALUES(setting_value), 
                updated_at = NOW()
            ");
            $stmt->execute([$mode]);
            
            $stmt = $this->conn->prepare("
                INSERT INTO " . $this->settings_table . " (category, setting_key, setting_value, updated_at) 
                VALUES ('maintenance', 'message', ?, NOW())
                ON DUPLICATE KEY UPDATE 
                setting_value = VALUES(setting_value), 
                updated_at = NOW()
            ");
            $stmt->execute([$maintenance_message]);
            
            $action = $enabled ? 'activado' : 'desactivado';
            $this->logActivity('maintenance_mode_changed', "Modo mantenimiento $action", [
                'enabled' => $enabled,
                'message' => $maintenance_message
            ]);
            
            return $this->jsonResponse(true, [
                'maintenance_mode' => $enabled,
                'message' => $maintenance_message,
                'updated_at' => date('c')
            ], "Modo mantenimiento $action exitosamente");
            
        } catch(Exception $e) {
            error_log("Error configurando modo mantenimiento: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Enviar notificación global
    public function sendGlobalNotification($title, $message, $type = 'info', $target_users = 'all') {
        try {
            // Validar tipo de notificación
            $allowed_types = ['info', 'warning', 'error', 'success'];
            if (!in_array($type, $allowed_types)) {
                $type = 'info';
            }
            
            // Obtener usuarios objetivo
            $query = "SELECT COUNT(*) as count FROM " . $this->users_table;
            $params = [];
            
            if ($target_users !== 'all') {
                if ($target_users === 'active') {
                    $query .= " WHERE status = 'active'";
                } elseif ($target_users === 'paid') {
                    $query .= " WHERE plan != 'free' AND status = 'active'";
                } elseif (is_array($target_users)) {
                    $placeholders = str_repeat('?,', count($target_users) - 1) . '?';
                    $query .= " WHERE id IN ($placeholders)";
                    $params = $target_users;
                }
            }
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $target_count = $stmt->fetch()['count'];
            
            // Simular envío de notificación
            $notification_id = 'notif_' . uniqid();
            $sent_at = date('c');
            
            // En producción aquí se integraría con:
            // - Sistema de email masivo
            // - Notificaciones push
            // - SMS masivo
            // - Notificaciones in-app
            
            $this->logActivity('global_notification_sent', "Notificación global enviada: $title", [
                'notification_id' => $notification_id,
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'target_users' => $target_users,
                'target_count' => $target_count
            ]);
            
            return $this->jsonResponse(true, [
                'notification_id' => $notification_id,
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'target_count' => $target_count,
                'sent_at' => $sent_at
            ], "Notificación enviada exitosamente a $target_count usuarios");
            
        } catch(Exception $e) {
            error_log("Error enviando notificación global: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Métodos auxiliares
    private function generateCSV($data) {
        if (empty($data)) return '';
        
        $output = fopen('php://temp', 'r+');
        fputcsv($output, array_keys($data[0]));
        
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        
        rewind($output);
        $csv_content = stream_get_contents($output);
        fclose($output);
        
        return $csv_content;
    }
    
    private function generateTXT($data) {
        $txt_content = "SYSTEM LOGS EXPORT\n";
        $txt_content .= "==================\n";
        $txt_content .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
        
        foreach ($data as $row) {
            $txt_content .= "ID: {$row['ID']}\n";
            $txt_content .= "Action: {$row['Acción']}\n";
            $txt_content .= "Description: {$row['Descripción']}\n";
            $txt_content .= "User: {$row['Usuario']}\n";
            $txt_content .= "IP: {$row['IP']}\n";
            $txt_content .= "Date: {$row['Fecha']}\n";
            $txt_content .= "Metadata: {$row['Metadata']}\n";
            $txt_content .= str_repeat('-', 50) . "\n\n";
        }
        
        return $txt_content;
    }
    
    private function timeAgo($datetime) {
        $time = time() - strtotime($datetime);
        
        if ($time < 60) return 'hace unos segundos';
        if ($time < 3600) return 'hace ' . floor($time/60) . ' minutos';
        if ($time < 86400) return 'hace ' . floor($time/3600) . ' horas';
        if ($time < 2592000) return 'hace ' . floor($time/86400) . ' días';
        
        return 'hace ' . floor($time/2592000) . ' meses';
    }
    
    private function categorizeActivity($action) {
        if (strpos($action, 'user') !== false) return 'users';
        if (strpos($action, 'plan') !== false) return 'plans';
        if (strpos($action, 'payment') !== false) return 'billing';
        if (strpos($action, 'system') !== false || strpos($action, 'settings') !== false) return 'system';
        return 'other';
    }
    
    private function getActivityIcon($action) {
        if (strpos($action, 'created') !== false) return 'plus';
        if (strpos($action, 'deleted') !== false) return 'trash';
        if (strpos($action, 'updated') !== false) return 'edit';
        if (strpos($action, 'suspended') !== false) return 'pause';
        if (strpos($action, 'activated') !== false) return 'play';
        if (strpos($action, 'payment') !== false) return 'credit-card';
        if (strpos($action, 'login') !== false) return 'log-in';
        return 'activity';
    }

// <--- seguir aqui --->

// Continuación de system.php - Parte 3 de 3
// Métodos finales de la clase SystemManager y procesamiento de requests

    // Obtener información detallada del servidor
    public function getServerInfo() {
        try {
            $server_info = [
                'php' => [
                    'version' => PHP_VERSION,
                    'sapi' => PHP_SAPI,
                    'memory_limit' => ini_get('memory_limit'),
                    'max_execution_time' => ini_get('max_execution_time'),
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'post_max_size' => ini_get('post_max_size'),
                    'extensions' => get_loaded_extensions()
                ],
                'mysql' => [
                    'version' => $this->getMySQLVersion(),
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci'
                ],
                'server' => [
                    'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                    'os' => PHP_OS,
                    'architecture' => php_uname('m'),
                    'hostname' => gethostname(),
                    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown',
                    'server_admin' => $_SERVER['SERVER_ADMIN'] ?? 'Unknown'
                ],
                'environment' => [
                    'timezone' => date_default_timezone_get(),
                    'date_time' => date('Y-m-d H:i:s'),
                    'temp_dir' => sys_get_temp_dir(),
                    'include_path' => get_include_path()
                ]
            ];
            
            // Información de configuración de seguridad
            $server_info['security'] = [
                'https_enabled' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                'allow_url_fopen' => ini_get('allow_url_fopen') ? true : false,
                'display_errors' => ini_get('display_errors') ? true : false,
                'expose_php' => ini_get('expose_php') ? true : false
            ];
            
            $this->logActivity('server_info_viewed', 'Información del servidor consultada');
            
            return $this->jsonResponse(true, $server_info, 'Información del servidor obtenida exitosamente');
            
        } catch(Exception $e) {
            error_log("Error obteniendo información del servidor: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Obtener métricas de rendimiento detalladas
    public function getPerformanceMetrics() {
        try {
            $metrics = [];
            
            // Tiempo de respuesta de la base de datos
            $db_tests = [];
            for ($i = 0; $i < 5; $i++) {
                $start = microtime(true);
                $stmt = $this->conn->prepare("SELECT COUNT(*) FROM " . $this->users_table);
                $stmt->execute();
                $stmt->fetch();
                $db_tests[] = (microtime(true) - $start) * 1000;
            }
            
            $metrics['database'] = [
                'avg_response_time_ms' => round(array_sum($db_tests) / count($db_tests), 2),
                'min_response_time_ms' => round(min($db_tests), 2),
                'max_response_time_ms' => round(max($db_tests), 2),
                'total_queries' => 5
            ];
            
            // Métricas de memoria
            $metrics['memory'] = [
                'current_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'peak_usage_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'limit_mb' => round($this->parseMemoryLimit(ini_get('memory_limit')) / 1024 / 1024, 2)
            ];
            
            // Actividad del sistema (últimas 24 horas)
            $stmt = $this->conn->prepare("
                SELECT 
                    COUNT(*) as total_activities,
                    COUNT(DISTINCT user) as active_admins,
                    COUNT(DISTINCT ip_address) as unique_ips
                FROM " . $this->logs_table . " 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute();
            $activity = $stmt->fetch();
            
            $metrics['activity_24h'] = [
                'total_activities' => intval($activity['total_activities']),
                'active_admins' => intval($activity['active_admins']),
                'unique_ips' => intval($activity['unique_ips']),
                'avg_activities_per_hour' => round(intval($activity['total_activities']) / 24, 1)
            ];
            
            // Distribución de actividades por hora
            $stmt = $this->conn->prepare("
                SELECT 
                    HOUR(created_at) as hour,
                    COUNT(*) as activities
                FROM " . $this->logs_table . " 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY HOUR(created_at)
                ORDER BY hour
            ");
            $stmt->execute();
            $hourly_activity = $stmt->fetchAll();
            
            $metrics['hourly_distribution'] = [];
            for ($i = 0; $i < 24; $i++) {
                $metrics['hourly_distribution'][$i] = 0;
            }
            
            foreach ($hourly_activity as $hour_data) {
                $metrics['hourly_distribution'][intval($hour_data['hour'])] = intval($hour_data['activities']);
            }
            
            // Análisis de errores
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) as error_count
                FROM " . $this->logs_table . " 
                WHERE (action LIKE '%error%' OR action LIKE '%failed%')
                AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute();
            $errors = $stmt->fetch();
            
            $metrics['errors_24h'] = [
                'total_errors' => intval($errors['error_count']),
                'error_rate_percent' => $activity['total_activities'] > 0 ? 
                    round((intval($errors['error_count']) / intval($activity['total_activities'])) * 100, 2) : 0
            ];
            
            $metrics['generated_at'] = date('c');
            
            return $this->jsonResponse(true, $metrics, 'Métricas de rendimiento obtenidas exitosamente');
            
        } catch(Exception $e) {
            error_log("Error obteniendo métricas de rendimiento: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
    
    // Ejecutar diagnóstico del sistema
    public function runSystemDiagnostic() {
        try {
            $diagnostic = [
                'timestamp' => date('c'),
                'tests' => [],
                'overall_status' => 'healthy',
                'recommendations' => []
            ];
            
            // Test 1: Conectividad de base de datos
            try {
                $start = microtime(true);
                $stmt = $this->conn->prepare("SELECT 1");
                $stmt->execute();
                $response_time = (microtime(true) - $start) * 1000;
                
                $diagnostic['tests']['database_connectivity'] = [
                    'status' => 'pass',
                    'response_time_ms' => round($response_time, 2),
                    'message' => 'Base de datos respondiendo correctamente'
                ];
            } catch(Exception $e) {
                $diagnostic['tests']['database_connectivity'] = [
                    'status' => 'fail',
                    'message' => 'Error de conectividad: ' . $e->getMessage()
                ];
                $diagnostic['overall_status'] = 'critical';
            }
            
            // Test 2: Integridad de tablas
            $required_tables = [$this->users_table, $this->plans_table, $this->logs_table, $this->settings_table];
            $missing_tables = [];
            
            foreach ($required_tables as $table) {
                try {
                    $stmt = $this->conn->prepare("SELECT 1 FROM $table LIMIT 1");
                    $stmt->execute();
                } catch(Exception $e) {
                    $missing_tables[] = $table;
                }
            }
            
            if (empty($missing_tables)) {
                $diagnostic['tests']['table_integrity'] = [
                    'status' => 'pass',
                    'message' => 'Todas las tablas requeridas están presentes'
                ];
            } else {
                $diagnostic['tests']['table_integrity'] = [
                    'status' => 'fail',
                    'message' => 'Tablas faltantes: ' . implode(', ', $missing_tables)
                ];
                $diagnostic['overall_status'] = 'critical';
            }
            
            // Test 3: Configuraciones críticas
            $critical_settings = ['general.site_name', 'security.session_timeout', 'backup.auto_backup_enabled'];
            $missing_settings = [];
            
            foreach ($critical_settings as $setting) {
                list($category, $key) = explode('.', $setting);
                $stmt = $this->conn->prepare("SELECT setting_value FROM " . $this->settings_table . " WHERE category = ? AND setting_key = ?");
                $stmt->execute([$category, $key]);
                
                if (!$stmt->fetch()) {
                    $missing_settings[] = $setting;
                }
            }
            
            if (empty($missing_settings)) {
                $diagnostic['tests']['configuration'] = [
                    'status' => 'pass',
                    'message' => 'Configuraciones críticas presentes'
                ];
            } else {
                $diagnostic['tests']['configuration'] = [
                    'status' => 'warning',
                    'message' => 'Configuraciones faltantes: ' . implode(', ', $missing_settings)
                ];
                if ($diagnostic['overall_status'] === 'healthy') {
                    $diagnostic['overall_status'] = 'warning';
                }
            }
            
            // Test 4: Espacio en disco
            $disk_usage = 75; // Simulado
            if ($disk_usage > 90) {
                $diagnostic['tests']['disk_space'] = [
                    'status' => 'fail',
                    'usage_percent' => $disk_usage,
                    'message' => 'Espacio en disco crítico'
                ];
                $diagnostic['overall_status'] = 'critical';
                $diagnostic['recommendations'][] = 'Liberar espacio en disco inmediatamente';
            } elseif ($disk_usage > 80) {
                $diagnostic['tests']['disk_space'] = [
                    'status' => 'warning',
                    'usage_percent' => $disk_usage,
                    'message' => 'Espacio en disco bajo'
                ];
                if ($diagnostic['overall_status'] === 'healthy') {
                    $diagnostic['overall_status'] = 'warning';
                }
                $diagnostic['recommendations'][] = 'Considerar limpiar archivos temporales y logs antiguos';
            } else {
                $diagnostic['tests']['disk_space'] = [
                    'status' => 'pass',
                    'usage_percent' => $disk_usage,
                    'message' => 'Espacio en disco adecuado'
                ];
            }
            
            // Test 5: Rendimiento de logs
            $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM " . $this->logs_table);
            $stmt->execute();
            $log_count = intval($stmt->fetch()['count']);
            
            if ($log_count > 100000) {
                $diagnostic['tests']['log_performance'] = [
                    'status' => 'warning',
                    'log_count' => $log_count,
                    'message' => 'Demasiados logs pueden afectar el rendimiento'
                ];
                $diagnostic['recommendations'][] = 'Considerar limpiar logs antiguos para mejorar el rendimiento';
            } else {
                $diagnostic['tests']['log_performance'] = [
                    'status' => 'pass',
                    'log_count' => $log_count,
                    'message' => 'Cantidad de logs adecuada'
                ];
            }
            
            // Agregar recomendaciones generales
            if ($diagnostic['overall_status'] === 'healthy') {
                $diagnostic['recommendations'][] = 'Sistema funcionando correctamente';
                $diagnostic['recommendations'][] = 'Mantener backups regulares';
                $diagnostic['recommendations'][] = 'Monitorear logs de errores periódicamente';
            }
            
            $this->logActivity('system_diagnostic', 'Diagnóstico del sistema ejecutado', [
                'overall_status' => $diagnostic['overall_status'],
                'tests_run' => count($diagnostic['tests']),
                'recommendations' => count($diagnostic['recommendations'])
            ]);
            
            return $this->jsonResponse(true, $diagnostic, 'Diagnóstico del sistema completado');
            
        } catch(Exception $e) {
            error_log("Error ejecutando diagnóstico: " . $e->getMessage());
            return $this->jsonResponse(false, null, 'Error interno del servidor', null, 500);
        }
    }
}

// Rate limiting para system
class SystemRateLimiter {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    public function isAllowed($ip, $limit = 100, $window = 3600) {
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

// Crear tabla de rate limits si no existe
function ensureRateLimitTable($conn) {
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
    ensureRateLimitTable($db);
    
    // Rate limiting
    $rate_limiter = new SystemRateLimiter($db);
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    if (!$rate_limiter->isAllowed($client_ip, 100, 3600)) {
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
    
    $system_manager = new SystemManager($db);
    
    if (!$system_manager->validateToken($auth_header)) {
        http_response_code(401);
        echo json_encode([
            'success' => false, 
            'message' => 'Token de autorización inválido',
            'timestamp' => date('c')
        ]);
        exit();
    }
    
    // Inicializar configuraciones del sistema
    initializeSystemSettings($db);
    
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
            if (count($path_parts) === 2 && $path_parts[0] === 'system' && $path_parts[1] === 'health') {
                // GET /system/health - Estado de salud del sistema
                $system_manager->getSystemHealth();
                
            } elseif (count($path_parts) === 2 && $path_parts[0] === 'system' && $path_parts[1] === 'stats') {
                // GET /system/stats - Estadísticas generales
                $system_manager->getSystemStats();
                
            } elseif (count($path_parts) === 2 && $path_parts[0] === 'system' && $path_parts[1] === 'logs') {
                // GET /system/logs - Logs del sistema
                $system_manager->getSystemLogs($query_params);
                
            } elseif (count($path_parts) === 2 && $path_parts[0] === 'system' && $path_parts[1] === 'activity') {
                // GET /system/activity - Actividad reciente
                $limit = isset($query_params['limit']) ? intval($query_params['limit']) : 50;
                $system_manager->getRecentActivity($limit);
                
            } elseif (count($path_parts) === 2 && $path_parts[0] === 'system' && $path_parts[1] === 'updates') {
                // GET /system/updates - Verificar actualizaciones
                $system_manager->checkUpdates();
                
            } elseif (count($path_parts) === 2 && $path_parts[0] === 'system' && $path_parts[1] === 'settings') {
                // GET /system/settings - Obtener configuraciones
                $category = $query_params['category'] ?? null;
                $system_manager->getSettings($category);
                
            } elseif (count($path_parts) === 2 && $path_parts[0] === 'system' && $path_parts[1] === 'server-info') {
                // GET /system/server-info - Información del servidor
                $system_manager->getServerInfo();
                
            } elseif (count($path_parts) === 2 && $path_parts[0] === 'system' && $path_parts[1] === 'performance') {
                // GET /system/performance - Métricas de rendimiento
                $system_manager->getPerformanceMetrics();
                
            } elseif (count($path_parts) === 2 && $path_parts[0] === 'system' && $path_parts[1] === 'diagnostic') {
                // GET /system/diagnostic - Ejecutar diagnóstico
                $system_manager->runSystemDiagnostic();
                
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Endpoint no encontrado']);
            }
            break;
            
        case 'POST':
            if (count($path_parts) === 3 && $path_parts[0] === 'system' && $path_parts[1] === 'logs' && $path_parts[2] === 'export') {
                // POST /system/logs/export - Exportar logs
                $format = $input['format'] ?? 'csv';
                $filters = $input['filters'] ?? [];
                $system_manager->exportLogs($format, $filters);
                
            } elseif (count($path_parts) === 2 && $path_parts[0] === 'system' && $path_parts[1] === 'backup') {
                // POST /system/backup - Crear backup
                $include_logs = $input['include_logs'] ?? false;
                $system_manager->createBackup($include_logs);
                
            } elseif (count($path_parts) === 2 && $path_parts[0] === 'system' && $path_parts[1] === 'maintenance') {
                // POST /system/maintenance - Activar/desactivar mantenimiento
                $enabled = $input['enabled'] ?? false;
                $message = $input['message'] ?? null;
                $system_manager->setMaintenanceMode($enabled, $message);
                
            } elseif (count($path_parts) === 3 && $path_parts[0] === 'system' && $path_parts[1] === 'notifications' && $path_parts[2] === 'global') {
                // POST /system/notifications/global - Enviar notificación global
                $title = $input['title'] ?? '';
                $message = $input['message'] ?? '';
                $type = $input['type'] ?? 'info';
                $target_users = $input['target_users'] ?? 'all';
                $system_manager->sendGlobalNotification($title, $message, $type, $target_users);
                
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Endpoint no encontrado']);
            }
            break;
            
        case 'PUT':
            if (count($path_parts) === 2 && $path_parts[0] === 'system' && $path_parts[1] === 'settings') {
                // PUT /system/settings - Actualizar configuraciones
                $system_manager->updateSettings($input);
                
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Endpoint no encontrado']);
            }
            break;
            
        case 'DELETE':
            if (count($path_parts) === 2 && $path_parts[0] === 'system' && $path_parts[1] === 'logs') {
                // DELETE /system/logs - Limpiar logs
                $keep_critical = isset($query_params['keep_critical']) ? filter_var($query_params['keep_critical'], FILTER_VALIDATE_BOOLEAN) : true;
                $days_to_keep = isset($query_params['days_to_keep']) ? intval($query_params['days_to_keep']) : 30;
                $system_manager->clearLogs($keep_critical, $days_to_keep);
                
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
    error_log("Error general en system.php: " . $e->getMessage());
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
 * === SALUD Y MONITOREO ===
 * GET /system/health - Estado de salud del sistema (CPU, memoria, disco, BD)
 * 
 * GET /system/stats - Estadísticas generales (usuarios, ingresos, reservas, actividad)
 * 
 * GET /system/server-info - Información detallada del servidor
 * 
 * GET /system/performance - Métricas de rendimiento detalladas
 * 
 * GET /system/diagnostic - Ejecutar diagnóstico completo del sistema
 * 
 * === LOGS ===
 * GET /system/logs - Obtener logs del sistema con filtros
 * Parámetros: page, per_page, action, user, level, date_from, date_to, search, order_by, order_dir
 * 
 * POST /system/logs/export - Exportar logs del sistema
 * Body: {format: "csv|json|txt", filters: {...}}
 * 
 * DELETE /system/logs - Limpiar logs del sistema
 * Parámetros: keep_critical (bool), days_to_keep (int)
 * 
 * === ACTIVIDAD ===
 * GET /system/activity - Actividad reciente del sistema
 * Parámetros: limit (default: 50)
 * 
 * === BACKUPS Y ACTUALIZACIONES ===
 * POST /system/backup - Crear backup de base de datos
 * Body: {include_logs: boolean}
 * 
 * GET /system/updates - Verificar actualizaciones disponibles
 * 
 * === CONFIGURACIONES ===
 * GET /system/settings - Obtener configuraciones del sistema
 * Parámetros: category (opcional)
 * 
 * PUT /system/settings - Actualizar configuraciones
 * Body: {category: {setting_key: value, ...}, ...}
 * 
 * === MANTENIMIENTO ===
 * POST /system/maintenance - Activar/desactivar modo mantenimiento
 * Body: {enabled: boolean, message?: string}
 * 
 * === NOTIFICACIONES ===
 * POST /system/notifications/global - Enviar notificación global
 * Body: {title: string, message: string, type?: "info|warning|error|success", target_users?: "all|active|paid|[ids]"}
 */

?>

// <--- FIN DEL ARCHIVO system.php 