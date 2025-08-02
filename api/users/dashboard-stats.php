 <?php
/**
 * ====================================
 * SISTEMA DE RESERVAS DE SERVICIOS
 * API DE ESTADÍSTICAS DEL DASHBOARD
 * Archivo: api/users/dashboard-stats.php
 * Parte 1 de 3 (500 líneas)
 * ====================================
 */

// Verificar acceso seguro
if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
}

// Incluir configuraciones necesarias
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

/**
 * Clase DashboardStatsAPI
 * Maneja todas las estadísticas y métricas del dashboard del usuario
 */
class DashboardStatsAPI
{
    private $db;
    private $user_id;
    private $user_uuid;
    private $timezone;
    private $logger;
    private $cache_duration = 300; // 5 minutos
    
    public function __construct()
    {
        $this->db = DatabaseConfig::getConnection();
        $this->logger = new Logger('dashboard-stats');
        $this->timezone = date_default_timezone_get();
        
        // Verificar autenticación
        $this->authenticateUser();
    }
    
    /**
     * Procesar solicitud principal
     */
    public function processRequest()
    {
        try {
            // Verificar método HTTP
            if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
                throw new Exception('Método no permitido', 405);
            }
            
            // Obtener parámetros de consulta
            $params = $this->getRequestParams();
            
            // Validar parámetros
            $this->validateParams($params);
            
            // Obtener tipo de estadística solicitada
            $stat_type = $params['type'] ?? 'overview';
            
            // Procesar según el tipo
            switch ($stat_type) {
                case 'overview':
                    $data = $this->getOverviewStats($params);
                    break;
                case 'bookings':
                    $data = $this->getBookingStats($params);
                    break;
                case 'revenue':
                    $data = $this->getRevenueStats($params);
                    break;
                case 'customers':
                    $data = $this->getCustomerStats($params);
                    break;
                case 'services':
                    $data = $this->getServiceStats($params);
                    break;
                case 'recent-bookings':
                    $data = $this->getRecentBookings($params);
                    break;
                case 'alerts':
                    $data = $this->getAlerts($params);
                    break;
                default:
                    throw new Exception('Tipo de estadística no válido', 400);
            }
            
            // Log de acceso exitoso
            $this->logger->info('Estadísticas obtenidas', [
                'user_id' => $this->user_id,
                'type' => $stat_type,
                'params' => $params
            ]);
            
            // Respuesta exitosa
            $this->sendSuccessResponse($data, $stat_type);
            
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }
    
    /**
     * Autenticar usuario y obtener información
     */
    private function authenticateUser()
    {
        // Obtener token del header Authorization
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
        
        if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            throw new Exception('Token de autorización requerido', 401);
        }
        
        $token = $matches[1];
        
        // Verificar y decodificar token JWT
        $userData = $this->verifyJWT($token);
        
        if (!$userData) {
            throw new Exception('Token inválido o expirado', 401);
        }
        
        $this->user_id = $userData['user_id'];
        $this->user_uuid = $userData['sub'];
        
        // Verificar que el usuario existe y está activo
        $this->verifyUserStatus();
    }
    
    /**
     * Verificar estado del usuario en la base de datos
     */
    private function verifyUserStatus()
    {
        $stmt = $this->db->prepare("
            SELECT id, uuid, status, plan_type, plan_expires, business_name 
            FROM users 
            WHERE id = ? AND uuid = ?
        ");
        
        $stmt->execute([$this->user_id, $this->user_uuid]);
        $user = $stmt->fetch();
        
        if (!$user) {
            throw new Exception('Usuario no encontrado', 404);
        }
        
        if ($user['status'] !== 'active') {
            throw new Exception('Cuenta no activa', 403);
        }
        
        // Verificar plan expirado (permitir acceso pero con limitaciones)
        if ($user['plan_expires'] && strtotime($user['plan_expires']) < time()) {
            $this->plan_expired = true;
        }
        
        $this->user_data = $user;
    }
    
    /**
     * Obtener parámetros de la solicitud
     */
    private function getRequestParams()
    {
        $params = [];
        
        // Parámetros permitidos
        $allowed_params = [
            'type', 'period', 'start_date', 'end_date', 
            'limit', 'offset', 'filter', 'timezone'
        ];
        
        foreach ($allowed_params as $param) {
            if (isset($_GET[$param])) {
                $params[$param] = sanitize($_GET[$param]);
            }
        }
        
        return $params;
    }
    
    /**
     * Validar parámetros de entrada
     */
    private function validateParams($params)
    {
        // Validar período
        if (isset($params['period'])) {
            $valid_periods = ['today', 'yesterday', 'week', 'month', 'quarter', 'year', 'custom'];
            if (!in_array($params['period'], $valid_periods)) {
                throw new Exception('Período no válido', 400);
            }
        }
        
        // Validar fechas
        if (isset($params['start_date']) && !$this->isValidDate($params['start_date'])) {
            throw new Exception('Fecha de inicio no válida', 400);
        }
        
        if (isset($params['end_date']) && !$this->isValidDate($params['end_date'])) {
            throw new Exception('Fecha de fin no válida', 400);
        }
        
        // Validar límites
        if (isset($params['limit'])) {
            $limit = (int)$params['limit'];
            if ($limit < 1 || $limit > 1000) {
                throw new Exception('Límite debe estar entre 1 y 1000', 400);
            }
        }
        
        if (isset($params['offset'])) {
            $offset = (int)$params['offset'];
            if ($offset < 0) {
                throw new Exception('Offset no puede ser negativo', 400);
            }
        }
    }
    
    /**
     * Obtener estadísticas generales (overview)
     */
    private function getOverviewStats($params)
    {
        $period = $params['period'] ?? 'today';
        $dates = $this->getDateRange($period, $params);
        
        // Obtener estadísticas principales en paralelo
        $stats = [
            'bookings' => $this->getBookingCount($dates),
            'revenue' => $this->getRevenue($dates),
            'customers' => $this->getCustomerCount($dates),
            'rating' => $this->getAverageRating($dates),
            'services' => $this->getActiveServicesCount(),
            'completion_rate' => $this->getCompletionRate($dates)
        ];
        
        // Obtener comparación con período anterior
        $previous_dates = $this->getPreviousDateRange($period, $dates);
        $previous_stats = [
            'bookings' => $this->getBookingCount($previous_dates),
            'revenue' => $this->getRevenue($previous_dates),
            'customers' => $this->getCustomerCount($previous_dates),
            'rating' => $this->getAverageRating($previous_dates)
        ];
        
        // Calcular cambios porcentuales
        $changes = $this->calculateChanges($stats, $previous_stats);
        
        // Obtener tendencias
        $trends = $this->getTrends($period, $dates);
        
        return [
            'period' => $period,
            'date_range' => $dates,
            'stats' => $stats,
            'changes' => $changes,
            'trends' => $trends,
            'last_updated' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Obtener rango de fechas según el período
     */
    private function getDateRange($period, $params)
    {
        $now = new DateTime('now', new DateTimeZone($this->timezone));
        
        switch ($period) {
            case 'today':
                return [
                    'start' => $now->format('Y-m-d 00:00:00'),
                    'end' => $now->format('Y-m-d 23:59:59')
                ];
                
            case 'yesterday':
                $yesterday = clone $now;
                $yesterday->modify('-1 day');
                return [
                    'start' => $yesterday->format('Y-m-d 00:00:00'),
                    'end' => $yesterday->format('Y-m-d 23:59:59')
                ];
                
            case 'week':
                $start = clone $now;
                $start->modify('monday this week')->setTime(0, 0, 0);
                return [
                    'start' => $start->format('Y-m-d H:i:s'),
                    'end' => $now->format('Y-m-d H:i:s')
                ];
                
            case 'month':
                $start = clone $now;
                $start->modify('first day of this month')->setTime(0, 0, 0);
                return [
                    'start' => $start->format('Y-m-d H:i:s'),
                    'end' => $now->format('Y-m-d H:i:s')
                ];
                
            case 'quarter':
                $month = (int)$now->format('n');
                $quarter_start = floor(($month - 1) / 3) * 3 + 1;
                $start = new DateTime($now->format('Y') . '-' . $quarter_start . '-01');
                return [
                    'start' => $start->format('Y-m-d 00:00:00'),
                    'end' => $now->format('Y-m-d H:i:s')
                ];
                
            case 'year':
                $start = new DateTime($now->format('Y') . '-01-01');
                return [
                    'start' => $start->format('Y-m-d 00:00:00'),
                    'end' => $now->format('Y-m-d H:i:s')
                ];
                
            case 'custom':
                if (!isset($params['start_date']) || !isset($params['end_date'])) {
                    throw new Exception('Fechas requeridas para período personalizado', 400);
                }
                return [
                    'start' => $params['start_date'] . ' 00:00:00',
                    'end' => $params['end_date'] . ' 23:59:59'
                ];
                
            default:
                throw new Exception('Período no válido', 400);
        }
    }
    
    /**
     * Obtener rango de fechas del período anterior para comparación
     */
    private function getPreviousDateRange($period, $current_dates)
    {
        $start = new DateTime($current_dates['start']);
        $end = new DateTime($current_dates['end']);
        $diff = $start->diff($end);
        
        $prev_end = clone $start;
        $prev_end->modify('-1 second');
        
        $prev_start = clone $prev_end;
        $prev_start->sub($diff);
        
        return [
            'start' => $prev_start->format('Y-m-d H:i:s'),
            'end' => $prev_end->format('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Obtener conteo de reservas
     */
    private function getBookingCount($dates)
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN status = 'confirmed' THEN 1 END) as confirmed,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
                COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled
            FROM bookings 
            WHERE user_id = ? 
            AND created_at BETWEEN ? AND ?
        ");
        
        $stmt->execute([$this->user_id, $dates['start'], $dates['end']]);
        $result = $stmt->fetch();
        
        return [
            'total' => (int)$result['total'],
            'confirmed' => (int)$result['confirmed'],
            'pending' => (int)$result['pending'],
            'completed' => (int)$result['completed'],
            'cancelled' => (int)$result['cancelled']
        ];
    }
    
    /**
     * Obtener ingresos
     */
    private function getRevenue($dates)
    {
        $stmt = $this->db->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN b.status IN ('confirmed', 'completed') THEN b.total_price END), 0) as total,
                COALESCE(SUM(CASE WHEN b.status = 'completed' THEN b.total_price END), 0) as confirmed,
                COALESCE(SUM(CASE WHEN b.status = 'pending' THEN b.total_price END), 0) as pending,
                COALESCE(AVG(CASE WHEN b.status IN ('confirmed', 'completed') THEN b.total_price END), 0) as average
            FROM bookings b
            WHERE b.user_id = ? 
            AND b.created_at BETWEEN ? AND ?
        ");
        
        $stmt->execute([$this->user_id, $dates['start'], $dates['end']]);
        $result = $stmt->fetch();
        
        return [
            'total' => (float)$result['total'],
            'confirmed' => (float)$result['confirmed'],
            'pending' => (float)$result['pending'],
            'average' => (float)$result['average']
        ];
    }
    
    /**
     * Obtener conteo de clientes
     */
    private function getCustomerCount($dates)
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(DISTINCT customer_email) as unique_customers,
                COUNT(DISTINCT CASE WHEN created_at BETWEEN ? AND ? THEN customer_email END) as new_customers,
                COUNT(customer_email) as total_bookings
            FROM bookings 
            WHERE user_id = ? 
            AND created_at <= ?
        ");
        
        $stmt->execute([$dates['start'], $dates['end'], $this->user_id, $dates['end']]);
        $result = $stmt->fetch();
        
        return [
            'unique' => (int)$result['unique_customers'],
            'new' => (int)$result['new_customers'],
            'total_bookings' => (int)$result['total_bookings']
        ];
    }
    
    /**
     * Obtener calificación promedio
     */
    private function getAverageRating($dates)
    {
        $stmt = $this->db->prepare("
            SELECT 
                COALESCE(AVG(r.rating), 0) as average,
                COUNT(r.rating) as total_reviews,
                COUNT(CASE WHEN r.rating = 5 THEN 1 END) as five_star,
                COUNT(CASE WHEN r.rating >= 4 THEN 1 END) as four_plus_star
            FROM reviews r
            INNER JOIN bookings b ON r.booking_id = b.id
            WHERE b.user_id = ? 
            AND r.created_at BETWEEN ? AND ?
            AND r.is_visible = 1
        ");
        
        $stmt->execute([$this->user_id, $dates['start'], $dates['end']]);
        $result = $stmt->fetch();
        
        return [
            'average' => round((float)$result['average'], 1),
            'total_reviews' => (int)$result['total_reviews'],
            'five_star' => (int)$result['five_star'],
            'four_plus_star' => (int)$result['four_plus_star']
        ];
    }
    
    /**
     * Obtener número de servicios activos
     */
    private function getActiveServicesCount()
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as active_services
            FROM services 
            WHERE user_id = ? AND is_active = 1
        ");
        
        $stmt->execute([$this->user_id]);
        $result = $stmt->fetch();
        
        return (int)$result['active_services'];
    }
    
    /**
     * Obtener tasa de completación
     */
    private function getCompletionRate($dates)
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
                COUNT(CASE WHEN status = 'no_show' THEN 1 END) as no_show
            FROM bookings 
            WHERE user_id = ? 
            AND booking_date BETWEEN ? AND ?
            AND status IN ('completed', 'no_show')
        ");
        
        $stmt->execute([$this->user_id, $dates['start'], $dates['end']]);
        $result = $stmt->fetch();
        
        $total = (int)$result['total'];
        $completed = (int)$result['completed'];
        
        return $total > 0 ? round(($completed / $total) * 100, 1) : 0;
    }

    // <--- SEGUIR AQUI --->

    <?php
/**
 * ====================================
 * SISTEMA DE RESERVAS DE SERVICIOS
 * API DE ESTADÍSTICAS DEL DASHBOARD
 * Archivo: api/users/dashboard-stats.php
 * Parte 2 de 3 (líneas 501-1000)
 * ====================================
 */

    /**
     * Calcular cambios porcentuales entre períodos
     */
    private function calculateChanges($current, $previous)
    {
        $changes = [];
        
        foreach ($current as $key => $value) {
            if (isset($previous[$key])) {
                $prev_value = $previous[$key];
                
                // Para valores anidados (como bookings, revenue)
                if (is_array($value)) {
                    $changes[$key] = [];
                    foreach ($value as $sub_key => $sub_value) {
                        if (isset($prev_value[$sub_key])) {
                            $changes[$key][$sub_key] = $this->calculatePercentageChange(
                                $sub_value, 
                                $prev_value[$sub_key]
                            );
                        }
                    }
                } else {
                    $changes[$key] = $this->calculatePercentageChange($value, $prev_value);
                }
            }
        }
        
        return $changes;
    }
    
    /**
     * Calcular cambio porcentual entre dos valores
     */
    private function calculatePercentageChange($current, $previous)
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }
        
        $change = (($current - $previous) / $previous) * 100;
        return round($change, 1);
    }
    
    /**
     * Obtener tendencias del período
     */
    private function getTrends($period, $dates)
    {
        // Definir intervalos según el período
        $intervals = $this->getTrendIntervals($period, $dates);
        
        $trends = [
            'bookings' => $this->getBookingTrends($intervals),
            'revenue' => $this->getRevenueTrends($intervals),
            'customers' => $this->getCustomerTrends($intervals),
            'hourly_distribution' => $this->getHourlyDistribution($dates),
            'service_popularity' => $this->getServicePopularity($dates)
        ];
        
        return $trends;
    }
    
    /**
     * Obtener intervalos para tendencias según el período
     */
    private function getTrendIntervals($period, $dates)
    {
        $start = new DateTime($dates['start']);
        $end = new DateTime($dates['end']);
        $intervals = [];
        
        switch ($period) {
            case 'today':
            case 'yesterday':
                // Intervalos por hora
                $current = clone $start;
                while ($current <= $end) {
                    $next = clone $current;
                    $next->add(new DateInterval('PT1H'));
                    $intervals[] = [
                        'start' => $current->format('Y-m-d H:i:s'),
                        'end' => min($next, $end)->format('Y-m-d H:i:s'),
                        'label' => $current->format('H:00')
                    ];
                    $current = $next;
                }
                break;
                
            case 'week':
                // Intervalos por día
                $current = clone $start;
                while ($current <= $end) {
                    $next = clone $current;
                    $next->add(new DateInterval('P1D'));
                    $intervals[] = [
                        'start' => $current->format('Y-m-d 00:00:00'),
                        'end' => min($next, $end)->format('Y-m-d 23:59:59'),
                        'label' => $current->format('D d/m')
                    ];
                    $current = $next;
                }
                break;
                
            case 'month':
                // Intervalos por semana
                $current = clone $start;
                $week_num = 1;
                while ($current <= $end) {
                    $next = clone $current;
                    $next->add(new DateInterval('P7D'));
                    $intervals[] = [
                        'start' => $current->format('Y-m-d 00:00:00'),
                        'end' => min($next, $end)->format('Y-m-d 23:59:59'),
                        'label' => 'Semana ' . $week_num
                    ];
                    $current = $next;
                    $week_num++;
                }
                break;
                
            case 'quarter':
            case 'year':
                // Intervalos por mes
                $current = clone $start;
                while ($current <= $end) {
                    $next = clone $current;
                    $next->add(new DateInterval('P1M'));
                    $intervals[] = [
                        'start' => $current->format('Y-m-01 00:00:00'),
                        'end' => min($next, $end)->format('Y-m-d 23:59:59'),
                        'label' => $current->format('M Y')
                    ];
                    $current = $next;
                }
                break;
        }
        
        return $intervals;
    }
    
    /**
     * Obtener tendencias de reservas
     */
    private function getBookingTrends($intervals)
    {
        $trends = [];
        
        foreach ($intervals as $interval) {
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total,
                    COUNT(CASE WHEN status = 'confirmed' THEN 1 END) as confirmed,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed
                FROM bookings 
                WHERE user_id = ? 
                AND created_at BETWEEN ? AND ?
            ");
            
            $stmt->execute([$this->user_id, $interval['start'], $interval['end']]);
            $result = $stmt->fetch();
            
            $trends[] = [
                'period' => $interval['label'],
                'total' => (int)$result['total'],
                'confirmed' => (int)$result['confirmed'],
                'completed' => (int)$result['completed']
            ];
        }
        
        return $trends;
    }
    
    /**
     * Obtener tendencias de ingresos
     */
    private function getRevenueTrends($intervals)
    {
        $trends = [];
        
        foreach ($intervals as $interval) {
            $stmt = $this->db->prepare("
                SELECT 
                    COALESCE(SUM(CASE WHEN status IN ('confirmed', 'completed') THEN total_price END), 0) as revenue
                FROM bookings 
                WHERE user_id = ? 
                AND created_at BETWEEN ? AND ?
            ");
            
            $stmt->execute([$this->user_id, $interval['start'], $interval['end']]);
            $result = $stmt->fetch();
            
            $trends[] = [
                'period' => $interval['label'],
                'revenue' => (float)$result['revenue']
            ];
        }
        
        return $trends;
    }
    
    /**
     * Obtener tendencias de clientes
     */
    private function getCustomerTrends($intervals)
    {
        $trends = [];
        
        foreach ($intervals as $interval) {
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(DISTINCT customer_email) as unique_customers,
                    COUNT(*) as total_bookings
                FROM bookings 
                WHERE user_id = ? 
                AND created_at BETWEEN ? AND ?
            ");
            
            $stmt->execute([$this->user_id, $interval['start'], $interval['end']]);
            $result = $stmt->fetch();
            
            $trends[] = [
                'period' => $interval['label'],
                'unique_customers' => (int)$result['unique_customers'],
                'total_bookings' => (int)$result['total_bookings']
            ];
        }
        
        return $trends;
    }
    
    /**
     * Obtener distribución por horas del día
     */
    private function getHourlyDistribution($dates)
    {
        $stmt = $this->db->prepare("
            SELECT 
                HOUR(booking_time) as hour,
                COUNT(*) as bookings,
                COALESCE(SUM(total_price), 0) as revenue
            FROM bookings 
            WHERE user_id = ? 
            AND booking_date BETWEEN ? AND ?
            AND status IN ('confirmed', 'completed')
            GROUP BY HOUR(booking_time)
            ORDER BY hour
        ");
        
        $stmt->execute([$this->user_id, $dates['start'], $dates['end']]);
        $results = $stmt->fetchAll();
        
        // Crear array completo de 24 horas
        $distribution = [];
        for ($i = 0; $i < 24; $i++) {
            $distribution[] = [
                'hour' => sprintf('%02d:00', $i),
                'bookings' => 0,
                'revenue' => 0
            ];
        }
        
        // Llenar con datos reales
        foreach ($results as $row) {
            $hour = (int)$row['hour'];
            $distribution[$hour]['bookings'] = (int)$row['bookings'];
            $distribution[$hour]['revenue'] = (float)$row['revenue'];
        }
        
        return $distribution;
    }
    
    /**
     * Obtener popularidad de servicios
     */
    private function getServicePopularity($dates)
    {
        $stmt = $this->db->prepare("
            SELECT 
                s.id,
                s.name,
                s.price,
                COUNT(b.id) as bookings,
                COALESCE(SUM(b.total_price), 0) as revenue,
                COALESCE(AVG(r.rating), 0) as avg_rating
            FROM services s
            LEFT JOIN bookings b ON s.id = b.service_id 
                AND b.user_id = ? 
                AND b.created_at BETWEEN ? AND ?
                AND b.status IN ('confirmed', 'completed')
            LEFT JOIN reviews r ON b.id = r.booking_id AND r.is_visible = 1
            WHERE s.user_id = ? AND s.is_active = 1
            GROUP BY s.id, s.name, s.price
            ORDER BY bookings DESC, revenue DESC
            LIMIT 10
        ");
        
        $stmt->execute([$this->user_id, $dates['start'], $dates['end'], $this->user_id]);
        $results = $stmt->fetchAll();
        
        $services = [];
        foreach ($results as $row) {
            $services[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'price' => (float)$row['price'],
                'bookings' => (int)$row['bookings'],
                'revenue' => (float)$row['revenue'],
                'avg_rating' => round((float)$row['avg_rating'], 1)
            ];
        }
        
        return $services;
    }
    
    /**
     * Obtener estadísticas detalladas de reservas
     */
    private function getBookingStats($params)
    {
        $period = $params['period'] ?? 'month';
        $dates = $this->getDateRange($period, $params);
        
        // Estadísticas básicas
        $basic_stats = $this->getBookingCount($dates);
        
        // Estadísticas por estado
        $status_stats = $this->getBookingsByStatus($dates);
        
        // Estadísticas por servicio
        $service_stats = $this->getBookingsByService($dates);
        
        // Estadísticas por canal
        $channel_stats = $this->getBookingsByChannel($dates);
        
        // Tiempo promedio entre reserva y cita
        $lead_time = $this->getAverageLeadTime($dates);
        
        // Reservas por día de la semana
        $weekday_stats = $this->getBookingsByWeekday($dates);
        
        return [
            'period' => $period,
            'date_range' => $dates,
            'basic_stats' => $basic_stats,
            'by_status' => $status_stats,
            'by_service' => $service_stats,
            'by_channel' => $channel_stats,
            'lead_time' => $lead_time,
            'by_weekday' => $weekday_stats,
            'trends' => $this->getBookingTrends($this->getTrendIntervals($period, $dates))
        ];
    }
    
    /**
     * Obtener reservas por estado
     */
    private function getBookingsByStatus($dates)
    {
        $stmt = $this->db->prepare("
            SELECT 
                status,
                COUNT(*) as count,
                COALESCE(SUM(total_price), 0) as revenue,
                ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM bookings WHERE user_id = ? AND created_at BETWEEN ? AND ?), 1) as percentage
            FROM bookings 
            WHERE user_id = ? 
            AND created_at BETWEEN ? AND ?
            GROUP BY status
            ORDER BY count DESC
        ");
        
        $stmt->execute([
            $this->user_id, $dates['start'], $dates['end'],
            $this->user_id, $dates['start'], $dates['end']
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtener reservas por servicio
     */
    private function getBookingsByService($dates)
    {
        $stmt = $this->db->prepare("
            SELECT 
                s.name as service_name,
                s.price as service_price,
                COUNT(b.id) as bookings,
                COALESCE(SUM(b.total_price), 0) as revenue,
                COALESCE(AVG(r.rating), 0) as avg_rating
            FROM services s
            LEFT JOIN bookings b ON s.id = b.service_id 
                AND b.user_id = ? 
                AND b.created_at BETWEEN ? AND ?
            LEFT JOIN reviews r ON b.id = r.booking_id AND r.is_visible = 1
            WHERE s.user_id = ? AND s.is_active = 1
            GROUP BY s.id, s.name, s.price
            HAVING bookings > 0
            ORDER BY bookings DESC
        ");
        
        $stmt->execute([$this->user_id, $dates['start'], $dates['end'], $this->user_id]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtener reservas por canal
     */
    private function getBookingsByChannel($dates)
    {
        $stmt = $this->db->prepare("
            SELECT 
                COALESCE(booking_source, 'direct') as channel,
                COUNT(*) as bookings,
                COALESCE(SUM(total_price), 0) as revenue,
                ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM bookings WHERE user_id = ? AND created_at BETWEEN ? AND ?), 1) as percentage
            FROM bookings 
            WHERE user_id = ? 
            AND created_at BETWEEN ? AND ?
            GROUP BY booking_source
            ORDER BY bookings DESC
        ");
        
        $stmt->execute([
            $this->user_id, $dates['start'], $dates['end'],
            $this->user_id, $dates['start'], $dates['end']
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtener tiempo promedio entre reserva y cita
     */
    private function getAverageLeadTime($dates)
    {
        $stmt = $this->db->prepare("
            SELECT 
                AVG(TIMESTAMPDIFF(HOUR, created_at, CONCAT(booking_date, ' ', booking_time))) as avg_lead_time_hours,
                MIN(TIMESTAMPDIFF(HOUR, created_at, CONCAT(booking_date, ' ', booking_time))) as min_lead_time_hours,
                MAX(TIMESTAMPDIFF(HOUR, created_at, CONCAT(booking_date, ' ', booking_time))) as max_lead_time_hours
            FROM bookings 
            WHERE user_id = ? 
            AND created_at BETWEEN ? AND ?
            AND status IN ('confirmed', 'completed')
        ");
        
        $stmt->execute([$this->user_id, $dates['start'], $dates['end']]);
        $result = $stmt->fetch();
        
        return [
            'average_hours' => round((float)$result['avg_lead_time_hours'], 1),
            'min_hours' => (int)$result['min_lead_time_hours'],
            'max_hours' => (int)$result['max_lead_time_hours']
        ];
    }
    
    /**
     * Obtener reservas por día de la semana
     */
    private function getBookingsByWeekday($dates)
    {
        $stmt = $this->db->prepare("
            SELECT 
                DAYNAME(booking_date) as weekday,
                DAYOFWEEK(booking_date) as weekday_num,
                COUNT(*) as bookings,
                COALESCE(SUM(total_price), 0) as revenue
            FROM bookings 
            WHERE user_id = ? 
            AND created_at BETWEEN ? AND ?
            AND status IN ('confirmed', 'completed')
            GROUP BY DAYOFWEEK(booking_date), DAYNAME(booking_date)
            ORDER BY weekday_num
        ");
        
        $stmt->execute([$this->user_id, $dates['start'], $dates['end']]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtener estadísticas de ingresos
     */
    private function getRevenueStats($params)
    {
        $period = $params['period'] ?? 'month';
        $dates = $this->getDateRange($period, $params);
        
        // Ingresos básicos
        $basic_revenue = $this->getRevenue($dates);
        
        // Ingresos por método de pago
        $payment_methods = $this->getRevenueByPaymentMethod($dates);
        
        // Ingresos por servicio
        $service_revenue = $this->getRevenueByService($dates);
        
        // Proyección de ingresos
        $projection = $this->getRevenueProjection($period, $dates);
        
        // Métricas de rendimiento
        $performance = $this->getRevenuePerformance($dates);
        
        return [
            'period' => $period,
            'date_range' => $dates,
            'basic_revenue' => $basic_revenue,
            'by_payment_method' => $payment_methods,
            'by_service' => $service_revenue,
            'projection' => $projection,
            'performance' => $performance,
            'trends' => $this->getRevenueTrends($this->getTrendIntervals($period, $dates))
        ];
    }
    
    /**
     * Obtener ingresos por método de pago
     */
    private function getRevenueByPaymentMethod($dates)
    {
        $stmt = $this->db->prepare("
            SELECT 
                COALESCE(payment_method, 'cash') as method,
                COUNT(*) as transactions,
                COALESCE(SUM(total_price), 0) as revenue,
                COALESCE(AVG(total_price), 0) as avg_transaction
            FROM bookings 
            WHERE user_id = ? 
            AND created_at BETWEEN ? AND ?
            AND status IN ('confirmed', 'completed')
            GROUP BY payment_method
            ORDER BY revenue DESC
        ");
        
        $stmt->execute([$this->user_id, $dates['start'], $dates['end']]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtener ingresos por servicio
     */
    private function getRevenueByService($dates)
    {
        $stmt = $this->db->prepare("
            SELECT 
                s.name as service_name,
                s.price as base_price,
                COUNT(b.id) as bookings,
                COALESCE(SUM(b.total_price), 0) as revenue,
                COALESCE(AVG(b.total_price), 0) as avg_price,
                ROUND(SUM(b.total_price) * 100.0 / (
                    SELECT SUM(total_price) 
                    FROM bookings 
                    WHERE user_id = ? 
                    AND created_at BETWEEN ? AND ? 
                    AND status IN ('confirmed', 'completed')
                ), 1) as revenue_percentage
            FROM services s
            INNER JOIN bookings b ON s.id = b.service_id
            WHERE b.user_id = ? 
            AND b.created_at BETWEEN ? AND ?
            AND b.status IN ('confirmed', 'completed')
            GROUP BY s.id, s.name, s.price
            ORDER BY revenue DESC
        ");
        
        $stmt->execute([
            $this->user_id, $dates['start'], $dates['end'],
            $this->user_id, $dates['start'], $dates['end']
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtener proyección de ingresos
     */
    private function getRevenueProjection($period, $dates)
    {
        // Solo hacer proyección para períodos actuales
        $now = new DateTime();
        $end_date = new DateTime($dates['end']);
        
        if ($end_date < $now) {
            return null; // No proyectar para períodos pasados
        }
        
        $start_date = new DateTime($dates['start']);
        $total_days = $start_date->diff($end_date)->days + 1;
        $elapsed_days = $start_date->diff($now)->days + 1;
        
        if ($elapsed_days <= 0) {
            return null;
        }
        
        // Obtener ingresos actuales
        $current_revenue = $this->getRevenue([
            'start' => $dates['start'],
            'end' => $now->format('Y-m-d H:i:s')
        ]);
        
        // Calcular proyección
        $daily_average = $current_revenue['total'] / $elapsed_days;
        $projected_total = $daily_average * $total_days;
        
        return [
            'current_revenue' => $current_revenue['total'],
            'daily_average' => round($daily_average, 2),
            'projected_total' => round($projected_total, 2),
            'remaining_days' => $total_days - $elapsed_days,
            'completion_percentage' => round(($elapsed_days / $total_days) * 100, 1)
        ];
    }
    
    /**
     * Obtener métricas de rendimiento de ingresos
     */
    private function getRevenuePerformance($dates)
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_bookings,
                COALESCE(SUM(total_price), 0) as total_revenue,
                COALESCE(AVG(total_price), 0) as avg_booking_value,
                COALESCE(MAX(total_price), 0) as highest_booking,
                COALESCE(MIN(total_price), 0) as lowest_booking,
                COUNT(DISTINCT customer_email) as unique_customers,
                COALESCE(SUM(total_price) / COUNT(DISTINCT customer_email), 0) as avg_customer_value
            FROM bookings 
            WHERE user_id = ? 
            AND created_at BETWEEN ? AND ?
            AND status IN ('confirmed', 'completed')
        ");
        
        $stmt->execute([$this->user_id, $dates['start'], $dates['end']]);
        $result = $stmt->fetch();
        
        return [
            'total_bookings' => (int)$result['total_bookings'],
            'total_revenue' => (float)$result['total_revenue'],
            'avg_booking_value' => round((float)$result['avg_booking_value'], 2),
            'highest_booking' => (float)$result['highest_booking'],
            'lowest_booking' => (float)$result['lowest_booking'],
            'unique_customers' => (int)$result['unique_customers'],
            'avg_customer_value' => round((float)$result['avg_customer_value'], 2)
        ];
    }
    
    /**
     * Obtener estadísticas de clientes
     */
    private function getCustomerStats($params)
    {
        $period = $params['period'] ?? 'month';
        $dates = $this->getDateRange($period, $params);
        
        return [
            'period' => $period,
            'date_range' => $dates,
            'overview' => $this->getCustomerOverview($dates),
            'top_customers' => $this->getTopCustomers($dates),
            'customer_retention' => $this->getCustomerRetention($dates),
            'customer_segments' => $this->getCustomerSegments($dates),
            'geographic_distribution' => $this->getCustomerGeography($dates)
        ];
    }
    
    /**
     * Obtener resumen de clientes
     */
    private function getCustomerOverview($dates)
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(DISTINCT customer_email) as total_customers,
                COUNT(*) as total_bookings,
                COALESCE(AVG(total_price), 0) as avg_spending,
                COALESCE(SUM(total_price), 0) as total_revenue,
                COUNT(DISTINCT CASE WHEN created_at BETWEEN ? AND ? THEN customer_email END) as new_customers
            FROM bookings 
            WHERE user_id = ? 
            AND created_at <= ?
            AND status IN ('confirmed', 'completed')
        ");
        
        $stmt->execute([$dates['start'], $dates['end'], $this->user_id, $dates['end']]);
        $result = $stmt->fetch();
        
        $total_customers = (int)$result['total_customers'];
        
        return [
            'total_customers' => $total_customers,
            'new_customers' => (int)$result['new_customers'],
            'total_bookings' => (int)$result['total_bookings'],
            'avg_bookings_per_customer' => $total_customers > 0 ? round($result['total_bookings'] / $total_customers, 1) : 0,
            'avg_spending_per_customer' => round((float)$result['avg_spending'], 2),
            'total_revenue' => (float)$result['total_revenue']
        ];
    }
    
    /**
     * Obtener top clientes
     */
    private function getTopCustomers($dates)
    {
        $stmt = $this->db->prepare("
            SELECT 
                customer_email,
                customer_name,
                customer_phone,
                COUNT(*) as total_bookings,
                COALESCE(SUM(total_price), 0) as total_spent,
                COALESCE(AVG(total_price), 0) as avg_booking_value,
                MAX(created_at) as last_booking,
                COUNT(CASE WHEN created_at BETWEEN ? AND ? THEN 1 END) as period_bookings,
                COALESCE(SUM(CASE WHEN created_at BETWEEN ? AND ? THEN total_price END), 0) as period_spent
            FROM bookings 
            WHERE user_id = ? 
            AND status IN ('confirmed', 'completed')
            GROUP BY customer_email, customer_name, customer_phone
            HAVING period_bookings > 0
            ORDER BY period_spent DESC, total_spent DESC
            LIMIT 20
        ");
        
        $stmt->execute([
            $dates['start'], $dates['end'], 
            $dates['start'], $dates['end'], 
            $this->user_id
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // <--- CONTINUAR AQUI CON PARTE 3 --->

    <?php
/**
 * ====================================
 * SISTEMA DE RESERVAS DE SERVICIOS
 * API DE ESTADÍSTICAS DEL DASHBOARD
 * Archivo: api/users/dashboard-stats.php
 * Parte 3 de 3 (líneas 1001-1500) - FINAL
 * ====================================
 */

    /**
     * Obtener retención de clientes
     */
    private function getCustomerRetention($dates)
    {
        // Clientes del período anterior
        $previous_dates = $this->getPreviousDateRange('month', $dates);
        
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(DISTINCT CASE WHEN created_at BETWEEN ? AND ? THEN customer_email END) as previous_customers,
                COUNT(DISTINCT CASE WHEN created_at BETWEEN ? AND ? THEN customer_email END) as current_customers,
                COUNT(DISTINCT CASE 
                    WHEN customer_email IN (
                        SELECT DISTINCT customer_email 
                        FROM bookings 
                        WHERE user_id = ? 
                        AND created_at BETWEEN ? AND ?
                        AND status IN ('confirmed', 'completed')
                    ) 
                    AND created_at BETWEEN ? AND ? 
                    THEN customer_email 
                END) as retained_customers
            FROM bookings 
            WHERE user_id = ? 
            AND status IN ('confirmed', 'completed')
        ");
        
        $stmt->execute([
            $previous_dates['start'], $previous_dates['end'],
            $dates['start'], $dates['end'],
            $this->user_id, $previous_dates['start'], $previous_dates['end'],
            $dates['start'], $dates['end'],
            $this->user_id
        ]);
        
        $result = $stmt->fetch();
        $previous_customers = (int)$result['previous_customers'];
        $retained_customers = (int)$result['retained_customers'];
        
        return [
            'previous_customers' => $previous_customers,
            'current_customers' => (int)$result['current_customers'],
            'retained_customers' => $retained_customers,
            'retention_rate' => $previous_customers > 0 ? round(($retained_customers / $previous_customers) * 100, 1) : 0,
            'churn_rate' => $previous_customers > 0 ? round((($previous_customers - $retained_customers) / $previous_customers) * 100, 1) : 0
        ];
    }
    
    /**
     * Obtener segmentos de clientes
     */
    private function getCustomerSegments($dates)
    {
        $stmt = $this->db->prepare("
            SELECT 
                customer_email,
                COUNT(*) as booking_count,
                COALESCE(SUM(total_price), 0) as total_spent,
                DATEDIFF(MAX(created_at), MIN(created_at)) as customer_lifespan_days
            FROM bookings 
            WHERE user_id = ? 
            AND status IN ('confirmed', 'completed')
            GROUP BY customer_email
        ");
        
        $stmt->execute([$this->user_id]);
        $customers = $stmt->fetchAll();
        
        $segments = [
            'new' => ['count' => 0, 'revenue' => 0],           // 1 reserva
            'occasional' => ['count' => 0, 'revenue' => 0],    // 2-5 reservas
            'regular' => ['count' => 0, 'revenue' => 0],       // 6-15 reservas
            'vip' => ['count' => 0, 'revenue' => 0]            // 16+ reservas
        ];
        
        foreach ($customers as $customer) {
            $bookings = (int)$customer['booking_count'];
            $spent = (float)$customer['total_spent'];
            
            if ($bookings == 1) {
                $segments['new']['count']++;
                $segments['new']['revenue'] += $spent;
            } elseif ($bookings <= 5) {
                $segments['occasional']['count']++;
                $segments['occasional']['revenue'] += $spent;
            } elseif ($bookings <= 15) {
                $segments['regular']['count']++;
                $segments['regular']['revenue'] += $spent;
            } else {
                $segments['vip']['count']++;
                $segments['vip']['revenue'] += $spent;
            }
        }
        
        return $segments;
    }
    
    /**
     * Obtener distribución geográfica de clientes
     */
    private function getCustomerGeography($dates)
    {
        $stmt = $this->db->prepare("
            SELECT 
                COALESCE(customer_city, 'No especificado') as city,
                COUNT(DISTINCT customer_email) as customers,
                COUNT(*) as bookings,
                COALESCE(SUM(total_price), 0) as revenue
            FROM bookings 
            WHERE user_id = ? 
            AND created_at BETWEEN ? AND ?
            AND status IN ('confirmed', 'completed')
            GROUP BY customer_city
            ORDER BY customers DESC, revenue DESC
            LIMIT 10
        ");
        
        $stmt->execute([$this->user_id, $dates['start'], $dates['end']]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtener estadísticas de servicios
     */
    private function getServiceStats($params)
    {
        $period = $params['period'] ?? 'month';
        $dates = $this->getDateRange($period, $params);
        
        return [
            'period' => $period,
            'date_range' => $dates,
            'overview' => $this->getServiceOverview($dates),
            'performance' => $this->getServicePerformance($dates),
            'capacity_analysis' => $this->getCapacityAnalysis($dates),
            'pricing_analysis' => $this->getPricingAnalysis($dates),
            'service_combinations' => $this->getServiceCombinations($dates)
        ];
    }
    
    /**
     * Obtener resumen de servicios
     */
    private function getServiceOverview($dates)
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(DISTINCT s.id) as total_services,
                COUNT(DISTINCT CASE WHEN s.is_active = 1 THEN s.id END) as active_services,
                COUNT(DISTINCT CASE WHEN b.id IS NOT NULL THEN s.id END) as services_with_bookings,
                COALESCE(AVG(s.price), 0) as avg_service_price,
                COALESCE(MIN(s.price), 0) as min_price,
                COALESCE(MAX(s.price), 0) as max_price
            FROM services s
            LEFT JOIN bookings b ON s.id = b.service_id 
                AND b.created_at BETWEEN ? AND ?
                AND b.status IN ('confirmed', 'completed')
            WHERE s.user_id = ?
        ");
        
        $stmt->execute([$dates['start'], $dates['end'], $this->user_id]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtener rendimiento de servicios
     */
    private function getServicePerformance($dates)
    {
        $stmt = $this->db->prepare("
            SELECT 
                s.id,
                s.name,
                s.price,
                s.duration_minutes,
                s.is_active,
                COUNT(b.id) as bookings,
                COALESCE(SUM(b.total_price), 0) as revenue,
                COALESCE(AVG(b.total_price), 0) as avg_price,
                COALESCE(AVG(r.rating), 0) as avg_rating,
                COUNT(r.id) as review_count,
                ROUND(COUNT(b.id) * 100.0 / NULLIF((
                    SELECT COUNT(*) 
                    FROM bookings 
                    WHERE user_id = ? 
                    AND created_at BETWEEN ? AND ?
                    AND status IN ('confirmed', 'completed')
                ), 0), 2) as booking_share,
                ROUND(SUM(b.total_price) * 100.0 / NULLIF((
                    SELECT SUM(total_price) 
                    FROM bookings 
                    WHERE user_id = ? 
                    AND created_at BETWEEN ? AND ?
                    AND status IN ('confirmed', 'completed')
                ), 0), 2) as revenue_share
            FROM services s
            LEFT JOIN bookings b ON s.id = b.service_id 
                AND b.user_id = ? 
                AND b.created_at BETWEEN ? AND ?
                AND b.status IN ('confirmed', 'completed')
            LEFT JOIN reviews r ON b.id = r.booking_id AND r.is_visible = 1
            WHERE s.user_id = ?
            GROUP BY s.id, s.name, s.price, s.duration_minutes, s.is_active
            ORDER BY revenue DESC, bookings DESC
        ");
        
        $stmt->execute([
            $this->user_id, $dates['start'], $dates['end'],
            $this->user_id, $dates['start'], $dates['end'],
            $this->user_id, $dates['start'], $dates['end'],
            $this->user_id
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtener análisis de capacidad
     */
    private function getCapacityAnalysis($dates)
    {
        $stmt = $this->db->prepare("
            SELECT 
                DATE(booking_date) as date,
                COUNT(*) as bookings,
                SUM(s.duration_minutes) as total_minutes,
                AVG(s.duration_minutes) as avg_duration
            FROM bookings b
            INNER JOIN services s ON b.service_id = s.id
            WHERE b.user_id = ? 
            AND b.booking_date BETWEEN ? AND ?
            AND b.status IN ('confirmed', 'completed')
            GROUP BY DATE(booking_date)
            ORDER BY date
        ");
        
        $stmt->execute([$this->user_id, $dates['start'], $dates['end']]);
        $daily_data = $stmt->fetchAll();
        
        // Calcular métricas de capacidad
        $total_bookings = array_sum(array_column($daily_data, 'bookings'));
        $total_minutes = array_sum(array_column($daily_data, 'total_minutes'));
        $working_days = count($daily_data);
        
        return [
            'daily_data' => $daily_data,
            'summary' => [
                'total_bookings' => $total_bookings,
                'total_hours' => round($total_minutes / 60, 1),
                'avg_bookings_per_day' => $working_days > 0 ? round($total_bookings / $working_days, 1) : 0,
                'avg_hours_per_day' => $working_days > 0 ? round(($total_minutes / 60) / $working_days, 1) : 0,
                'busiest_day' => $working_days > 0 ? max($daily_data) : null
            ]
        ];
    }
    
    /**
     * Obtener análisis de precios
     */
    private function getPricingAnalysis($dates)
    {
        $stmt = $this->db->prepare("
            SELECT 
                s.price as base_price,
                COUNT(b.id) as bookings,
                COALESCE(AVG(b.total_price), 0) as avg_final_price,
                COALESCE(MIN(b.total_price), 0) as min_final_price,
                COALESCE(MAX(b.total_price), 0) as max_final_price,
                COALESCE(SUM(b.total_price), 0) as total_revenue
            FROM services s
            INNER JOIN bookings b ON s.id = b.service_id
            WHERE b.user_id = ? 
            AND b.created_at BETWEEN ? AND ?
            AND b.status IN ('confirmed', 'completed')
            GROUP BY s.price
            ORDER BY s.price
        ");
        
        $stmt->execute([$this->user_id, $dates['start'], $dates['end']]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtener combinaciones de servicios
     */
    private function getServiceCombinations($dates)
    {
        $stmt = $this->db->prepare("
            SELECT 
                customer_email,
                GROUP_CONCAT(DISTINCT s.name ORDER BY s.name) as services,
                COUNT(DISTINCT s.id) as unique_services,
                COUNT(*) as total_bookings,
                COALESCE(SUM(b.total_price), 0) as total_spent
            FROM bookings b
            INNER JOIN services s ON b.service_id = s.id
            WHERE b.user_id = ? 
            AND b.created_at BETWEEN ? AND ?
            AND b.status IN ('confirmed', 'completed')
            GROUP BY customer_email
            HAVING unique_services > 1
            ORDER BY unique_services DESC, total_spent DESC
            LIMIT 20
        ");
        
        $stmt->execute([$this->user_id, $dates['start'], $dates['end']]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obtener reservas recientes
     */
    private function getRecentBookings($params)
    {
        $limit = (int)($params['limit'] ?? 20);
        $offset = (int)($params['offset'] ?? 0);
        $filter = $params['filter'] ?? 'all';
        
        // Construir condición de filtro
        $filter_condition = '';
        $filter_params = [];
        
        switch ($filter) {
            case 'pending':
                $filter_condition = " AND b.status = 'pending'";
                break;
            case 'confirmed':
                $filter_condition = " AND b.status = 'confirmed'";
                break;
            case 'completed':
                $filter_condition = " AND b.status = 'completed'";
                break;
            case 'cancelled':
                $filter_condition = " AND b.status = 'cancelled'";
                break;
            case 'today':
                $filter_condition = " AND DATE(b.booking_date) = CURDATE()";
                break;
            case 'upcoming':
                $filter_condition = " AND b.booking_date >= CURDATE() AND b.status IN ('confirmed', 'pending')";
                break;
        }
        
        $stmt = $this->db->prepare("
            SELECT 
                b.id,
                b.uuid,
                b.customer_name,
                b.customer_email,
                b.customer_phone,
                b.booking_date,
                b.booking_time,
                b.status,
                b.total_price,
                b.payment_method,
                b.notes,
                b.created_at,
                s.name as service_name,
                s.duration_minutes,
                CASE 
                    WHEN b.booking_date < CURDATE() THEN 'past'
                    WHEN b.booking_date = CURDATE() THEN 'today'
                    ELSE 'future'
                END as time_category
            FROM bookings b
            INNER JOIN services s ON b.service_id = s.id
            WHERE b.user_id = ? {$filter_condition}
            ORDER BY b.created_at DESC, b.booking_date DESC, b.booking_time DESC
            LIMIT ? OFFSET ?
        ");
        
        $params_array = array_merge([$this->user_id], $filter_params, [$limit, $offset]);
        $stmt->execute($params_array);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Obtener total de registros
        $count_stmt = $this->db->prepare("
            SELECT COUNT(*) as total
            FROM bookings b
            WHERE b.user_id = ? {$filter_condition}
        ");
        
        $count_params = array_merge([$this->user_id], $filter_params);
        $count_stmt->execute($count_params);
        $total = $count_stmt->fetch()['total'];
        
        return [
            'bookings' => $bookings,
            'pagination' => [
                'total' => (int)$total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total
            ],
            'filter' => $filter
        ];
    }
    
    /**
     * Obtener alertas y notificaciones
     */
    private function getAlerts($params)
    {
        $alerts = [];
        
        // Reservas pendientes de confirmación
        $pending_bookings = $this->getPendingBookingsAlert();
        if ($pending_bookings['count'] > 0) {
            $alerts[] = $pending_bookings;
        }
        
        // Reservas para hoy
        $today_bookings = $this->getTodayBookingsAlert();
        if ($today_bookings['count'] > 0) {
            $alerts[] = $today_bookings;
        }
        
        // Clientes que no han regresado
        $inactive_customers = $this->getInactiveCustomersAlert();
        if ($inactive_customers['count'] > 0) {
            $alerts[] = $inactive_customers;
        }
        
        // Servicios sin reservas recientes
        $inactive_services = $this->getInactiveServicesAlert();
        if ($inactive_services['count'] > 0) {
            $alerts[] = $inactive_services;
        }
        
        // Reseñas pendientes de respuesta
        $pending_reviews = $this->getPendingReviewsAlert();
        if ($pending_reviews['count'] > 0) {
            $alerts[] = $pending_reviews;
        }
        
        // Capacidad baja para mañana
        $low_capacity = $this->getLowCapacityAlert();
        if ($low_capacity['count'] > 0) {
            $alerts[] = $low_capacity;
        }
        
        return [
            'alerts' => $alerts,
            'total_alerts' => count($alerts),
            'last_updated' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Alerta de reservas pendientes
     */
    private function getPendingBookingsAlert()
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM bookings 
            WHERE user_id = ? 
            AND status = 'pending' 
            AND created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)
        ");
        
        $stmt->execute([$this->user_id]);
        $count = $stmt->fetch()['count'];
        
        return [
            'type' => 'pending_bookings',
            'priority' => 'high',
            'count' => (int)$count,
            'title' => 'Reservas pendientes de confirmación',
            'message' => "Tienes {$count} reservas esperando confirmación por más de 2 horas",
            'action_url' => '/bookings?filter=pending'
        ];
    }
    
    /**
     * Alerta de reservas para hoy
     */
    private function getTodayBookingsAlert()
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM bookings 
            WHERE user_id = ? 
            AND DATE(booking_date) = CURDATE()
            AND status IN ('confirmed', 'pending')
        ");
        
        $stmt->execute([$this->user_id]);
        $count = $stmt->fetch()['count'];
        
        return [
            'type' => 'today_bookings',
            'priority' => 'medium',
            'count' => (int)$count,
            'title' => 'Reservas para hoy',
            'message' => "Tienes {$count} reservas programadas para hoy",
            'action_url' => '/calendar?date=' . date('Y-m-d')
        ];
    }
    
    /**
     * Alerta de clientes inactivos
     */
    private function getInactiveCustomersAlert()
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT customer_email) as count
            FROM bookings 
            WHERE user_id = ? 
            AND status = 'completed'
            AND customer_email NOT IN (
                SELECT DISTINCT customer_email 
                FROM bookings 
                WHERE user_id = ? 
                AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            )
            AND created_at >= DATE_SUB(NOW(), INTERVAL 180 DAY)
        ");
        
        $stmt->execute([$this->user_id, $this->user_id]);
        $count = $stmt->fetch()['count'];
        
        return [
            'type' => 'inactive_customers',
            'priority' => 'low',
            'count' => (int)$count,
            'title' => 'Clientes inactivos',
            'message' => "{$count} clientes no han regresado en los últimos 90 días",
            'action_url' => '/customers?filter=inactive'
        ];
    }
    
    /**
     * Alerta de servicios inactivos
     */
    private function getInactiveServicesAlert()
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM services s
            WHERE s.user_id = ? 
            AND s.is_active = 1
            AND s.id NOT IN (
                SELECT DISTINCT service_id 
                FROM bookings 
                WHERE user_id = ? 
                AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                AND status IN ('confirmed', 'completed')
            )
        ");
        
        $stmt->execute([$this->user_id, $this->user_id]);
        $count = $stmt->fetch()['count'];
        
        return [
            'type' => 'inactive_services',
            'priority' => 'medium',
            'count' => (int)$count,
            'title' => 'Servicios sin reservas',
            'message' => "{$count} servicios no han tenido reservas en 30 días",
            'action_url' => '/services?filter=inactive'
        ];
    }
    
    /**
     * Alerta de reseñas pendientes
     */
    private function getPendingReviewsAlert()
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM reviews r
            INNER JOIN bookings b ON r.booking_id = b.id
            WHERE b.user_id = ? 
            AND r.admin_response IS NULL
            AND r.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        
        $stmt->execute([$this->user_id]);
        $count = $stmt->fetch()['count'];
        
        return [
            'type' => 'pending_reviews',
            'priority' => 'medium',
            'count' => (int)$count,
            'title' => 'Reseñas sin responder',
            'message' => "Tienes {$count} reseñas esperando tu respuesta",
            'action_url' => '/reviews?filter=pending_response'
        ];
    }
    
    /**
     * Alerta de capacidad baja
     */
    private function getLowCapacityAlert()
    {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM bookings 
            WHERE user_id = ? 
            AND DATE(booking_date) = ?
            AND status IN ('confirmed', 'pending')
        ");
        
        $stmt->execute([$this->user_id, $tomorrow]);
        $count = $stmt->fetch()['count'];
        
        // Solo alertar si hay muy pocas reservas para mañana
        if ($count < 3) {
            return [
                'type' => 'low_capacity',
                'priority' => 'low',
                'count' => (int)$count,
                'title' => 'Baja capacidad para mañana',
                'message' => "Solo tienes {$count} reservas programadas para mañana",
                'action_url' => '/calendar?date=' . $tomorrow
            ];
        }
        
        return ['count' => 0];
    }
    
    /**
     * Verificar token JWT
     */
    private function verifyJWT($token)
    {
        try {
            // Aquí implementarías la verificación JWT real
            // Por ahora, simulamos la decodificación
            $payload = base64_decode(explode('.', $token)[1]);
            $data = json_decode($payload, true);
            
            // Verificar expiración
            if (isset($data['exp']) && $data['exp'] < time()) {
                return false;
            }
            
            return $data;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Validar formato de fecha
     */
    private function isValidDate($date)
    {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
    
    /**
     * Enviar respuesta de éxito
     */
    private function sendSuccessResponse($data, $type = null)
    {
        $response = [
            'success' => true,
            'data' => $data,
            'type' => $type,
            'timestamp' => time(),
            'cache_duration' => $this->cache_duration,
            'user_id' => $this->user_id
        ];
        
        header('Content-Type: application/json');
        header('Cache-Control: private, max-age=' . $this->cache_duration);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * Manejar errores
     */
    private function handleError(Exception $e)
    {
        $error_code = $e->getCode() ?: 500;
        $error_message = $e->getMessage();
        
        // Log del error
        $this->logger->error('Error en dashboard stats', [
            'user_id' => $this->user_id ?? null,
            'error' => $error_message,
            'code' => $error_code,
            'trace' => $e->getTraceAsString()
        ]);
        
        // Respuesta de error
        http_response_code($error_code);
        header('Content-Type: application/json');
        
        $response = [
            'success' => false,
            'error' => [
                'message' => $error_message,
                'code' => $error_code
            ],
            'timestamp' => time()
        ];
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * Función utilitaria para sanitizar entrada
 */
function sanitize($input)
{
    if (is_string($input)) {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
    return $input;
}

/**
 * Clase Logger simplificada
 */
class Logger
{
    private $context;
    
    public function __construct($context)
    {
        $this->context = $context;
    }
    
    public function info($message, $data = [])
    {
        $this->log('INFO', $message, $data);
    }
    
    public function error($message, $data = [])
    {
        $this->log('ERROR', $message, $data);
    }
    
    private function log($level, $message, $data)
    {
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = [
            'timestamp' => $timestamp,
            'level' => $level,
            'context' => $this->context,
            'message' => $message,
            'data' => $data
        ];
        
        // Aquí escribirías al archivo de log o base de datos
        error_log(json_encode($log_entry));
    }
}

// Ejecutar la API si se accede directamente
if (basename($_SERVER['PHP_SELF']) === 'dashboard-stats.php') {
    try {
        $api = new DashboardStatsAPI();
        $api->processRequest();
    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => [
                'message' => 'Error interno del servidor',
                'code' => 500
            ]
        ]);
    }
}

?>

/**
 * ====================================
 * DOCUMENTACIÓN DE USO DE LA API
 * ====================================
 * 
 * ENDPOINTS DISPONIBLES:
 * 
 * GET /api/users/dashboard-stats.php?type=overview&period=month
 * - Estadísticas generales del dashboard
 * 
 * GET /api/users/dashboard-stats.php?type=bookings&period=week
 * - Estadísticas detalladas de reservas
 * 
 * GET /api/users/dashboard-stats.php?type=revenue&period=quarter
 * - Análisis de ingresos
 * 
 * GET /api/users/dashboard-stats.php?type=customers&period=year
 * - Estadísticas de clientes
 * 
 * GET /api/users/dashboard-stats.php?type=services&period=month
 * - Análisis de servicios
 * 
 * GET /api/users/dashboard-stats.php?type=recent-bookings&limit=50
 * - Reservas recientes
 * 
 * GET /api/users/dashboard-stats.php?type=alerts
 * - Alertas y notificaciones
 * 
 * PARÁMETROS:
 * - type: overview|bookings|revenue|customers|services|recent-bookings|alerts
 * - period: today|yesterday|week|month|quarter|year|custom
 * - start_date: YYYY-MM-DD (solo para period=custom)
 * - end_date: YYYY-MM-DD (solo para period=custom)
 * - limit: número (para recent-bookings, máximo 1000)
 * - offset: número (para paginación)
 * - filter: all|pending|confirmed|completed|cancelled|today|upcoming
 * 

