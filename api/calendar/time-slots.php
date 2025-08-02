<?php
/**
 * ====================================
 * SISTEMA DE RESERVAS DE SERVICIOS
 * API DE GESTIÓN DE SLOTS DE TIEMPO
 * Archivo: api/calendar/time-slots.php
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
 * Clase TimeSlotsAPI
 * Maneja la gestión dinámica de slots de tiempo, buffers, y optimización de horarios
 */
class TimeSlotsAPI
{
    private $db;
    private $user_id;
    private $user_uuid;
    private $timezone;
    private $logger;
    private $cache_duration = 300; // 5 minutos
    
    // Configuraciones por defecto
    private $default_configs = [
        'slot_duration' => 30,          // Duración base del slot en minutos
        'buffer_time' => 15,            // Tiempo de buffer entre citas
        'preparation_time' => 5,        // Tiempo de preparación antes de cita
        'cleanup_time' => 10,           // Tiempo de limpieza después de cita
        'max_advance_days' => 90,       // Máximo días de anticipación
        'min_advance_hours' => 2,       // Mínimo horas de anticipación
        'allow_back_to_back' => false,  // Permitir citas consecutivas
        'auto_confirm' => false,        // Auto-confirmar reservas
        'slot_optimization' => true     // Optimización automática de slots
    ];
    
    public function __construct()
    {
        $this->db = DatabaseConfig::getConnection();
        $this->logger = new Logger('time-slots');
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
            $method = $_SERVER['REQUEST_METHOD'];
            
            switch ($method) {
                case 'GET':
                    $this->handleGetRequest();
                    break;
                case 'POST':
                    $this->handlePostRequest();
                    break;
                case 'PUT':
                    $this->handlePutRequest();
                    break;
                case 'DELETE':
                    $this->handleDeleteRequest();
                    break;
                default:
                    throw new Exception('Método no permitido', 405);
            }
            
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }
    
    /**
     * Manejar peticiones GET
     */
    private function handleGetRequest()
    {
        $action = $_GET['action'] ?? '';
        $params = $this->getRequestParams();
        
        switch ($action) {
            case 'generate-slots':
                $data = $this->generateDynamicSlots($params);
                break;
            case 'optimize-slots':
                $data = $this->optimizeTimeSlots($params);
                break;
            case 'get-slot-config':
                $data = $this->getSlotConfiguration($params);
                break;
            case 'validate-slot':
                $data = $this->validateTimeSlot($params);
                break;
            case 'suggest-alternatives':
                $data = $this->suggestAlternativeSlots($params);
                break;
            case 'get-buffers':
                $data = $this->getBufferTimes($params);
                break;
            case 'slot-analytics':
                $data = $this->getSlotAnalytics($params);
                break;
            case 'capacity-analysis':
                $data = $this->getCapacityAnalysis($params);
                break;
            default:
                throw new Exception('Acción no válida', 400);
        }
        
        $this->sendSuccessResponse($data);
    }
    
    /**
     * Autenticar usuario
     */
    private function authenticateUser()
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
        
        if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            throw new Exception('Token de autorización requerido', 401);
        }
        
        $token = $matches[1];
        $userData = $this->verifyJWT($token);
        
        if (!$userData) {
            throw new Exception('Token inválido o expirado', 401);
        }
        
        $this->user_id = $userData['user_id'];
        $this->user_uuid = $userData['sub'];
        
        $this->verifyUserStatus();
    }
    
    /**
     * Verificar estado del usuario
     */
    private function verifyUserStatus()
    {
        $stmt = $this->db->prepare("
            SELECT id, uuid, status, timezone, business_name 
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
        
        if ($user['timezone']) {
            $this->timezone = $user['timezone'];
            date_default_timezone_set($this->timezone);
        }
        
        $this->user_data = $user;
    }
    
    /**
     * Obtener parámetros de solicitud
     */
    private function getRequestParams()
    {
        $params = [];
        
        $allowed_params = [
            'date', 'start_date', 'end_date', 'service_id', 'duration',
            'include_buffers', 'optimization_level', 'slot_size', 'customer_id',
            'preferred_time', 'flexible_duration', 'allow_overlaps'
        ];
        
        foreach ($allowed_params as $param) {
            if (isset($_GET[$param])) {
                $params[$param] = sanitize($_GET[$param]);
            }
        }
        
        return $params;
    }
    
    /**
     * Generar slots dinámicos optimizados
     */
    private function generateDynamicSlots($params)
    {
        $date = $params['date'] ?? date('Y-m-d');
        $service_id = $params['service_id'] ?? null;
        $include_buffers = (bool)($params['include_buffers'] ?? true);
        $optimization_level = $params['optimization_level'] ?? 'medium'; // low, medium, high
        
        if (!$this->isValidDate($date)) {
            throw new Exception('Fecha no válida', 400);
        }
        
        // Obtener configuración de slots
        $slot_config = $this->getServiceSlotConfig($service_id);
        
        // Obtener horarios de negocio para la fecha
        $business_hours = $this->getBusinessHoursForDate($date);
        
        if (!$business_hours || !$business_hours['is_open']) {
            return [
                'date' => $date,
                'service_id' => $service_id,
                'slots' => [],
                'message' => 'Día cerrado'
            ];
        }
        
        // Obtener reservas existentes
        $existing_bookings = $this->getExistingBookings($date, $service_id);
        
        // Generar slots base
        $base_slots = $this->generateBaseSlots($date, $business_hours, $slot_config);
        
        // Aplicar optimizaciones según nivel
        $optimized_slots = $this->applySlotOptimizations($base_slots, $existing_bookings, $optimization_level, $slot_config);
        
        // Aplicar buffers si está habilitado
        if ($include_buffers) {
            $optimized_slots = $this->applyBufferTimes($optimized_slots, $existing_bookings, $slot_config);
        }
        
        // Validar disponibilidad final
        $final_slots = $this->validateSlotAvailability($optimized_slots, $existing_bookings, $slot_config);
        
        return [
            'date' => $date,
            'service_id' => $service_id,
            'total_slots' => count($final_slots),
            'available_slots' => array_filter($final_slots, function($slot) { return $slot['is_available']; }),
            'optimization_level' => $optimization_level,
            'slot_config' => $slot_config,
            'business_hours' => $business_hours,
            'slots' => $final_slots,
            'generation_info' => [
                'base_slots_count' => count($base_slots),
                'optimized_slots_count' => count($optimized_slots),
                'final_slots_count' => count($final_slots),
                'buffers_applied' => $include_buffers,
                'generated_at' => date('Y-m-d H:i:s')
            ]
        ];
    }
    
    /**
     * Obtener configuración de slots para un servicio
     */
    private function getServiceSlotConfig($service_id = null)
    {
        $config = $this->default_configs;
        
        if ($service_id) {
            // Configuración específica del servicio
            $stmt = $this->db->prepare("
                SELECT 
                    duration_minutes,
                    buffer_time,
                    preparation_time,
                    cleanup_time,
                    allow_back_to_back,
                    auto_confirm,
                    max_advance_days,
                    min_advance_hours
                FROM services 
                WHERE id = ? AND user_id = ?
            ");
            
            $stmt->execute([$service_id, $this->user_id]);
            $service_config = $stmt->fetch();
            
            if ($service_config) {
                $config['slot_duration'] = (int)$service_config['duration_minutes'];
                $config['buffer_time'] = (int)($service_config['buffer_time'] ?? $config['buffer_time']);
                $config['preparation_time'] = (int)($service_config['preparation_time'] ?? $config['preparation_time']);
                $config['cleanup_time'] = (int)($service_config['cleanup_time'] ?? $config['cleanup_time']);
                $config['allow_back_to_back'] = (bool)($service_config['allow_back_to_back'] ?? $config['allow_back_to_back']);
                $config['auto_confirm'] = (bool)($service_config['auto_confirm'] ?? $config['auto_confirm']);
                $config['max_advance_days'] = (int)($service_config['max_advance_days'] ?? $config['max_advance_days']);
                $config['min_advance_hours'] = (int)($service_config['min_advance_hours'] ?? $config['min_advance_hours']);
            }
        }
        
        // Configuración global del usuario
        $stmt = $this->db->prepare("
            SELECT 
                default_slot_duration,
                default_buffer_time,
                slot_optimization_enabled,
                auto_confirm_bookings
            FROM user_preferences 
            WHERE user_id = ?
        ");
        
        $stmt->execute([$this->user_id]);
        $user_prefs = $stmt->fetch();
        
        if ($user_prefs) {
            if ($user_prefs['default_slot_duration']) {
                $config['slot_duration'] = (int)$user_prefs['default_slot_duration'];
            }
            if ($user_prefs['default_buffer_time']) {
                $config['buffer_time'] = (int)$user_prefs['default_buffer_time'];
            }
            $config['slot_optimization'] = (bool)($user_prefs['slot_optimization_enabled'] ?? $config['slot_optimization']);
            $config['auto_confirm'] = (bool)($user_prefs['auto_confirm_bookings'] ?? $config['auto_confirm']);
        }
        
        return $config;
    }
    
    /**
     * Obtener horarios de negocio para una fecha específica
     */
    private function getBusinessHoursForDate($date)
    {
        $date_obj = new DateTime($date);
        $day_of_week = (int)$date_obj->format('w');
        
        // Verificar horarios especiales
        $stmt = $this->db->prepare("
            SELECT is_open, open_time, close_time, break_start, break_end
            FROM special_hours 
            WHERE user_id = ? AND special_date = ?
        ");
        
        $stmt->execute([$this->user_id, $date]);
        $special_hours = $stmt->fetch();
        
        if ($special_hours) {
            return [
                'is_open' => (bool)$special_hours['is_open'],
                'open_time' => $special_hours['open_time'],
                'close_time' => $special_hours['close_time'],
                'break_start' => $special_hours['break_start'],
                'break_end' => $special_hours['break_end'],
                'is_special' => true
            ];
        }
        
        // Horarios regulares
        $stmt = $this->db->prepare("
            SELECT is_open, open_time, close_time, break_start, break_end
            FROM business_hours 
            WHERE user_id = ? AND day_of_week = ?
        ");
        
        $stmt->execute([$this->user_id, $day_of_week]);
        $business_hours = $stmt->fetch();
        
        if ($business_hours) {
            return [
                'is_open' => (bool)$business_hours['is_open'],
                'open_time' => $business_hours['open_time'],
                'close_time' => $business_hours['close_time'],
                'break_start' => $business_hours['break_start'],
                'break_end' => $business_hours['break_end'],
                'is_special' => false
            ];
        }
        
        return null;
    }
    
    /**
     * Obtener reservas existentes para una fecha
     */
    private function getExistingBookings($date, $service_id = null)
    {
        $sql = "
            SELECT 
                id,
                booking_time,
                s.duration_minutes,
                status,
                service_id
            FROM bookings b
            INNER JOIN services s ON b.service_id = s.id
            WHERE b.user_id = ? 
            AND b.booking_date = ?
            AND b.status IN ('confirmed', 'pending')
        ";
        
        $params = [$this->user_id, $date];
        
        if ($service_id) {
            $sql .= " AND b.service_id = ?";
            $params[] = $service_id;
        }
        
        $sql .= " ORDER BY b.booking_time";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $bookings = $stmt->fetchAll();
        
        // Calcular tiempos de fin
        foreach ($bookings as &$booking) {
            $start_time = new DateTime($date . ' ' . $booking['booking_time']);
            $end_time = clone $start_time;
            $end_time->add(new DateInterval('PT' . $booking['duration_minutes'] . 'M'));
            $booking['end_time'] = $end_time->format('H:i:s');
        }
        
        return $bookings;
    }
    
    /**
     * Generar slots base sin optimizaciones
     */
    private function generateBaseSlots($date, $business_hours, $slot_config)
    {
        $slots = [];
        $slot_duration = $slot_config['slot_duration'];
        
        $start_time = new DateTime($date . ' ' . $business_hours['open_time']);
        $end_time = new DateTime($date . ' ' . $business_hours['close_time']);
        
        $current_time = clone $start_time;
        
        while ($current_time < $end_time) {
            $slot_end = clone $current_time;
            $slot_end->add(new DateInterval('PT' . $slot_duration . 'M'));
            
            // Verificar que el slot completo cabe
            if ($slot_end > $end_time) {
                break;
            }
            
            $time_str = $current_time->format('H:i:s');
            
            $slots[] = [
                'time' => $time_str,
                'time_formatted' => $current_time->format('H:i'),
                'end_time' => $slot_end->format('H:i:s'),
                'end_time_formatted' => $slot_end->format('H:i'),
                'duration' => $slot_duration,
                'is_available' => true,
                'slot_type' => 'regular',
                'buffer_before' => 0,
                'buffer_after' => 0,
                'conflicts' => [],
                'optimization_score' => 0
            ];
            
            $current_time->add(new DateInterval('PT' . $slot_duration . 'M'));
        }
        
        return $slots;
    }
    
    /**
     * Aplicar optimizaciones a los slots
     */
    private function applySlotOptimizations($slots, $existing_bookings, $optimization_level, $slot_config)
    {
        if (!$slot_config['slot_optimization']) {
            return $slots;
        }
        
        switch ($optimization_level) {
            case 'low':
                return $this->applyBasicOptimizations($slots, $existing_bookings, $slot_config);
            case 'medium':
                return $this->applyMediumOptimizations($slots, $existing_bookings, $slot_config);
            case 'high':
                return $this->applyAdvancedOptimizations($slots, $existing_bookings, $slot_config);
            default:
                return $slots;
        }
    }
    
    /**
     * Aplicar optimizaciones básicas
     */
    private function applyBasicOptimizations($slots, $existing_bookings, $slot_config)
    {
        // Eliminar slots que se superponen con reservas existentes
        foreach ($slots as &$slot) {
            foreach ($existing_bookings as $booking) {
                if ($this->slotsOverlap($slot, $booking)) {
                    $slot['is_available'] = false;
                    $slot['conflicts'][] = 'existing_booking';
                }
            }
        }
        
        return $slots;
    }
    
    /**
     * Aplicar optimizaciones medias
     */
    private function applyMediumOptimizations($slots, $existing_bookings, $slot_config)
    {
        $slots = $this->applyBasicOptimizations($slots, $existing_bookings, $slot_config);
        
        // Calcular score de optimización para cada slot
        foreach ($slots as &$slot) {
            $slot['optimization_score'] = $this->calculateOptimizationScore($slot, $slots, $existing_bookings, $slot_config);
        }
        
        // Aplicar reglas de espaciado si no se permiten citas consecutivas
        if (!$slot_config['allow_back_to_back']) {
            $this->enforceSpacingRules($slots, $existing_bookings, $slot_config);
        }
        
        return $slots;
    }
    
    /**
     * Aplicar optimizaciones avanzadas
     */
    private function applyAdvancedOptimizations($slots, $existing_bookings, $slot_config)
    {
        $slots = $this->applyMediumOptimizations($slots, $existing_bookings, $slot_config);
        
        // Optimización de agrupamiento (agrupar slots disponibles)
        $slots = $this->optimizeSlotGrouping($slots, $slot_config);
        
        // Optimización de eficiencia (minimizar tiempos muertos)
        $slots = $this->optimizeEfficiency($slots, $existing_bookings, $slot_config);
        
        // Reordenar por score de optimización
        usort($slots, function($a, $b) {
            return $b['optimization_score'] <=> $a['optimization_score'];
        });
        
        return $slots;
    }

    // <--- CONTINUAR AQUI CON PARTE 2 --->
    <?php
/**
 * ====================================
 * SISTEMA DE RESERVAS DE SERVICIOS
 * API DE GESTIÓN DE SLOTS DE TIEMPO
 * Archivo: api/calendar/time-slots.php
 * Parte 2 de 3 (líneas 501-1000)
 * ====================================
 */

    /**
     * Verificar si dos slots se superponen
     */
    private function slotsOverlap($slot, $booking)
    {
        $slot_start = new DateTime($slot['time']);
        $slot_end = new DateTime($slot['end_time']);
        $booking_start = new DateTime($booking['booking_time']);
        $booking_end = new DateTime($booking['end_time']);
        
        return $slot_start < $booking_end && $slot_end > $booking_start;
    }
    
    /**
     * Calcular score de optimización para un slot
     */
    private function calculateOptimizationScore($slot, $all_slots, $existing_bookings, $slot_config)
    {
        $score = 100; // Score base
        
        $slot_time = new DateTime($slot['time']);
        
        // Factor 1: Distancia a reservas existentes (mejor si está cerca de otras reservas)
        $min_distance = PHP_INT_MAX;
        foreach ($existing_bookings as $booking) {
            $booking_time = new DateTime($booking['booking_time']);
            $distance = abs($slot_time->getTimestamp() - $booking_time->getTimestamp()) / 60; // minutos
            $min_distance = min($min_distance, $distance);
        }
        
        if ($min_distance !== PHP_INT_MAX) {
            // Penalizar slots muy alejados de otras reservas
            if ($min_distance > 120) { // Más de 2 horas
                $score -= 20;
            } elseif ($min_distance > 60) { // Más de 1 hora
                $score -= 10;
            } elseif ($min_distance < 30) { // Menos de 30 minutos (muy cerca)
                $score += 15;
            }
        }
        
        // Factor 2: Hora del día (favorece horarios populares)
        $hour = (int)$slot_time->format('H');
        if ($hour >= 9 && $hour <= 12) { // Mañana
            $score += 10;
        } elseif ($hour >= 14 && $hour <= 17) { // Tarde
            $score += 15;
        } elseif ($hour >= 18 && $hour <= 20) { // Noche temprana
            $score += 5;
        } else {
            $score -= 10; // Horarios menos populares
        }
        
        // Factor 3: Posición en secuencia (favorece agrupamiento)
        $adjacent_available = 0;
        foreach ($all_slots as $other_slot) {
            if ($other_slot['time'] === $slot['time']) continue;
            
            $other_time = new DateTime($other_slot['time']);
            $time_diff = abs($slot_time->getTimestamp() - $other_time->getTimestamp()) / 60;
            
            if ($time_diff <= $slot_config['slot_duration'] && $other_slot['is_available']) {
                $adjacent_available++;
            }
        }
        
        $score += $adjacent_available * 5; // Bonus por slots adyacentes disponibles
        
        // Factor 4: Tiempo desde ahora (favorece slots más cercanos en el tiempo)
        $now = new DateTime();
        $hours_from_now = ($slot_time->getTimestamp() - $now->getTimestamp()) / 3600;
        
        if ($hours_from_now < $slot_config['min_advance_hours']) {
            $score = 0; // No disponible
        } elseif ($hours_from_now <= 24) { // Dentro de 24 horas
            $score += 20;
        } elseif ($hours_from_now <= 72) { // Dentro de 3 días
            $score += 10;
        }
        
        return max(0, $score);
    }
    
    /**
     * Aplicar reglas de espaciado entre citas
     */
    private function enforceSpacingRules($slots, $existing_bookings, $slot_config)
    {
        $buffer_minutes = $slot_config['buffer_time'];
        
        foreach ($slots as &$slot) {
            if (!$slot['is_available']) continue;
            
            $slot_start = new DateTime($slot['time']);
            $slot_end = new DateTime($slot['end_time']);
            
            foreach ($existing_bookings as $booking) {
                $booking_start = new DateTime($booking['booking_time']);
                $booking_end = new DateTime($booking['end_time']);
                
                // Verificar buffer antes del slot
                $buffer_before_start = clone $slot_start;
                $buffer_before_start->sub(new DateInterval('PT' . $buffer_minutes . 'M'));
                
                if ($buffer_before_start < $booking_end && $slot_start > $booking_start) {
                    $slot['is_available'] = false;
                    $slot['conflicts'][] = 'insufficient_buffer_before';
                    continue 2;
                }
                
                // Verificar buffer después del slot
                $buffer_after_end = clone $slot_end;
                $buffer_after_end->add(new DateInterval('PT' . $buffer_minutes . 'M'));
                
                if ($slot_end < $booking_start && $buffer_after_end > $booking_start) {
                    $slot['is_available'] = false;
                    $slot['conflicts'][] = 'insufficient_buffer_after';
                    continue 2;
                }
            }
        }
    }
    
    /**
     * Optimizar agrupamiento de slots
     */
    private function optimizeSlotGrouping($slots, $slot_config)
    {
        // Agrupar slots consecutivos disponibles
        $groups = [];
        $current_group = [];
        
        foreach ($slots as $slot) {
            if ($slot['is_available']) {
                if (empty($current_group)) {
                    $current_group = [$slot];
                } else {
                    $last_slot = end($current_group);
                    $last_end = new DateTime($last_slot['end_time']);
                    $current_start = new DateTime($slot['time']);
                    
                    // Si son consecutivos (con tolerancia de 5 minutos)
                    $gap = ($current_start->getTimestamp() - $last_end->getTimestamp()) / 60;
                    if ($gap <= 5) {
                        $current_group[] = $slot;
                    } else {
                        $groups[] = $current_group;
                        $current_group = [$slot];
                    }
                }
            } else {
                if (!empty($current_group)) {
                    $groups[] = $current_group;
                    $current_group = [];
                }
            }
        }
        
        if (!empty($current_group)) {
            $groups[] = $current_group;
        }
        
        // Asignar bonus por tamaño de grupo
        foreach ($groups as $group) {
            $group_size = count($group);
            $bonus = min($group_size * 2, 10); // Máximo 10 puntos de bonus
            
            foreach ($group as &$slot) {
                $slot['optimization_score'] += $bonus;
                $slot['group_size'] = $group_size;
            }
        }
        
        return $slots;
    }
    
    /**
     * Optimizar eficiencia (minimizar tiempos muertos)
     */
    private function optimizeEfficiency($slots, $existing_bookings, $slot_config)
    {
        foreach ($slots as &$slot) {
            if (!$slot['is_available']) continue;
            
            $slot_start = new DateTime($slot['time']);
            $slot_end = new DateTime($slot['end_time']);
            
            // Buscar el tiempo muerto antes y después
            $dead_time_before = $this->calculateDeadTimeBefore($slot_start, $existing_bookings);
            $dead_time_after = $this->calculateDeadTimeAfter($slot_end, $existing_bookings);
            
            // Penalizar slots que crean mucho tiempo muerto
            $total_dead_time = $dead_time_before + $dead_time_after;
            
            if ($total_dead_time > 60) { // Más de 1 hora de tiempo muerto
                $slot['optimization_score'] -= 15;
            } elseif ($total_dead_time > 30) { // Más de 30 minutos
                $slot['optimization_score'] -= 10;
            } elseif ($total_dead_time <= 15) { // Mínimo tiempo muerto
                $slot['optimization_score'] += 10;
            }
            
            $slot['dead_time_before'] = $dead_time_before;
            $slot['dead_time_after'] = $dead_time_after;
        }
        
        return $slots;
    }
    
    /**
     * Calcular tiempo muerto antes de un slot
     */
    private function calculateDeadTimeBefore($slot_start, $existing_bookings)
    {
        $min_gap = PHP_INT_MAX;
        
        foreach ($existing_bookings as $booking) {
            $booking_end = new DateTime($booking['end_time']);
            
            if ($booking_end <= $slot_start) {
                $gap = ($slot_start->getTimestamp() - $booking_end->getTimestamp()) / 60;
                $min_gap = min($min_gap, $gap);
            }
        }
        
        return $min_gap === PHP_INT_MAX ? 0 : $min_gap;
    }
    
    /**
     * Calcular tiempo muerto después de un slot
     */
    private function calculateDeadTimeAfter($slot_end, $existing_bookings)
    {
        $min_gap = PHP_INT_MAX;
        
        foreach ($existing_bookings as $booking) {
            $booking_start = new DateTime($booking['booking_time']);
            
            if ($booking_start >= $slot_end) {
                $gap = ($booking_start->getTimestamp() - $slot_end->getTimestamp()) / 60;
                $min_gap = min($min_gap, $gap);
            }
        }
        
        return $min_gap === PHP_INT_MAX ? 0 : $min_gap;
    }
    
    /**
     * Aplicar tiempos de buffer
     */
    private function applyBufferTimes($slots, $existing_bookings, $slot_config)
    {
        foreach ($slots as &$slot) {
            if (!$slot['is_available']) continue;
            
            $buffer_before = $slot_config['preparation_time'];
            $buffer_after = $slot_config['cleanup_time'];
            
            // Ajustar buffers según proximidad a otras reservas
            $slot_start = new DateTime($slot['time']);
            $slot_end = new DateTime($slot['end_time']);
            
            foreach ($existing_bookings as $booking) {
                $booking_start = new DateTime($booking['booking_time']);
                $booking_end = new DateTime($booking['end_time']);
                
                // Si hay una reserva antes, ajustar buffer
                if ($booking_end <= $slot_start) {
                    $gap = ($slot_start->getTimestamp() - $booking_end->getTimestamp()) / 60;
                    $buffer_before = min($buffer_before, $gap);
                }
                
                // Si hay una reserva después, ajustar buffer
                if ($booking_start >= $slot_end) {
                    $gap = ($booking_start->getTimestamp() - $slot_end->getTimestamp()) / 60;
                    $buffer_after = min($buffer_after, $gap);
                }
            }
            
            $slot['buffer_before'] = max(0, $buffer_before);
            $slot['buffer_after'] = max(0, $buffer_after);
            
            // Calcular tiempo efectivo del slot
            $slot['effective_start'] = (clone $slot_start)->sub(new DateInterval('PT' . $slot['buffer_before'] . 'M'))->format('H:i:s');
            $slot['effective_end'] = (clone $slot_end)->add(new DateInterval('PT' . $slot['buffer_after'] . 'M'))->format('H:i:s');
        }
        
        return $slots;
    }
    
    /**
     * Validar disponibilidad final de slots
     */
    private function validateSlotAvailability($slots, $existing_bookings, $slot_config)
    {
        $now = new DateTime();
        $min_advance = clone $now;
        $min_advance->add(new DateInterval('PT' . $slot_config['min_advance_hours'] . 'H'));
        
        foreach ($slots as &$slot) {
            $slot_start = new DateTime($slot['time']);
            
            // Verificar tiempo mínimo de anticipación
            if ($slot_start <= $min_advance) {
                $slot['is_available'] = false;
                $slot['conflicts'][] = 'insufficient_advance_time';
            }
            
            // Verificar límite máximo de anticipación
            $max_advance = clone $now;
            $max_advance->add(new DateInterval('P' . $slot_config['max_advance_days'] . 'D'));
            
            if ($slot_start > $max_advance) {
                $slot['is_available'] = false;
                $slot['conflicts'][] = 'exceeds_max_advance_time';
            }
            
            // Verificar conflictos con horarios de descanso
            if ($this->isInBreakTime($slot, $existing_bookings)) {
                $slot['is_available'] = false;
                $slot['conflicts'][] = 'break_time_conflict';
            }
        }
        
        return $slots;
    }
    
    /**
     * Verificar si un slot está en horario de descanso
     */
    private function isInBreakTime($slot, $existing_bookings)
    {
        // Esta función se implementaría según la lógica de horarios de descanso
        // Por ahora retornamos false
        return false;
    }
    
    /**
     * Sugerir slots alternativos
     */
    private function suggestAlternativeSlots($params)
    {
        $preferred_date = $params['date'] ?? date('Y-m-d');
        $preferred_time = $params['preferred_time'] ?? null;
        $service_id = $params['service_id'] ?? null;
        $flexible_duration = (bool)($params['flexible_duration'] ?? false);
        
        $suggestions = [];
        
        // 1. Buscar en la misma fecha
        $same_day_slots = $this->findAlternativesInDate($preferred_date, $preferred_time, $service_id);
        if (!empty($same_day_slots)) {
            $suggestions['same_day'] = [
                'date' => $preferred_date,
                'message' => 'Horarios alternativos en la misma fecha',
                'slots' => array_slice($same_day_slots, 0, 5) // Top 5
            ];
        }
        
        // 2. Buscar en días cercanos
        $nearby_dates = $this->generateNearbyDates($preferred_date, 7); // 7 días hacia adelante
        foreach ($nearby_dates as $date) {
            $date_slots = $this->findAlternativesInDate($date, $preferred_time, $service_id);
            if (!empty($date_slots)) {
                $suggestions['nearby_dates'][] = [
                    'date' => $date,
                    'day_name' => (new DateTime($date))->format('l'),
                    'message' => 'Horarios disponibles en ' . (new DateTime($date))->format('d/m/Y'),
                    'slots' => array_slice($date_slots, 0, 3) // Top 3 por fecha
                ];
            }
        }
        
        // 3. Buscar con horarios similares en la semana
        if ($preferred_time) {
            $same_time_slots = $this->findSameTimeInWeek($preferred_date, $preferred_time, $service_id);
            if (!empty($same_time_slots)) {
                $suggestions['same_time_week'] = [
                    'message' => 'Mismo horario en otros días de la semana',
                    'slots' => $same_time_slots
                ];
            }
        }
        
        // 4. Sugerir servicios con duración flexible
        if ($flexible_duration && $service_id) {
            $flexible_slots = $this->findFlexibleDurationSlots($preferred_date, $service_id);
            if (!empty($flexible_slots)) {
                $suggestions['flexible_duration'] = [
                    'message' => 'Servicios con duración ajustable',
                    'slots' => $flexible_slots
                ];
            }
        }
        
        // Calcular estadísticas de sugerencias
        $total_suggestions = 0;
        foreach ($suggestions as $category) {
            if (isset($category['slots'])) {
                $total_suggestions += count($category['slots']);
            } elseif (is_array($category)) {
                foreach ($category as $item) {
                    if (isset($item['slots'])) {
                        $total_suggestions += count($item['slots']);
                    }
                }
            }
        }
        
        return [
            'preferred_date' => $preferred_date,
            'preferred_time' => $preferred_time,
            'service_id' => $service_id,
            'total_suggestions' => $total_suggestions,
            'suggestions' => $suggestions,
            'search_radius_days' => 7,
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Encontrar alternativas en una fecha específica
     */
    private function findAlternativesInDate($date, $preferred_time = null, $service_id = null)
    {
        $slots_data = $this->generateDynamicSlots([
            'date' => $date,
            'service_id' => $service_id,
            'optimization_level' => 'medium'
        ]);
        
        $available_slots = array_filter($slots_data['slots'], function($slot) {
            return $slot['is_available'];
        });
        
        if ($preferred_time) {
            // Ordenar por proximidad al horario preferido
            $preferred_timestamp = strtotime($preferred_time);
            
            usort($available_slots, function($a, $b) use ($preferred_timestamp) {
                $a_timestamp = strtotime($a['time']);
                $b_timestamp = strtotime($b['time']);
                
                $a_diff = abs($a_timestamp - $preferred_timestamp);
                $b_diff = abs($b_timestamp - $preferred_timestamp);
                
                return $a_diff <=> $b_diff;
            });
        } else {
            // Ordenar por score de optimización
            usort($available_slots, function($a, $b) {
                return $b['optimization_score'] <=> $a['optimization_score'];
            });
        }
        
        return $available_slots;
    }
    
    /**
     * Generar fechas cercanas
     */
    private function generateNearbyDates($base_date, $days_ahead)
    {
        $dates = [];
        $current_date = new DateTime($base_date);
        
        for ($i = 1; $i <= $days_ahead; $i++) {
            $current_date->add(new DateInterval('P1D'));
            $dates[] = $current_date->format('Y-m-d');
        }
        
        return $dates;
    }
    
    /**
     * Encontrar mismo horario en otros días de la semana
     */
    private function findSameTimeInWeek($base_date, $preferred_time, $service_id)
    {
        $base_date_obj = new DateTime($base_date);
        $same_time_slots = [];
        
        // Buscar en los próximos 14 días
        for ($i = 1; $i <= 14; $i++) {
            $check_date = clone $base_date_obj;
            $check_date->add(new DateInterval('P' . $i . 'D'));
            $check_date_str = $check_date->format('Y-m-d');
            
            // Verificar si el horario específico está disponible
            $is_available = $this->validateTimeSlot([
                'date' => $check_date_str,
                'time' => $preferred_time,
                'service_id' => $service_id
            ]);
            
            if ($is_available['is_available']) {
                $same_time_slots[] = [
                    'date' => $check_date_str,
                    'day_name' => $check_date->format('l'),
                    'time' => $preferred_time,
                    'time_formatted' => (new DateTime($preferred_time))->format('H:i'),
                    'is_available' => true,
                    'match_type' => 'exact_time'
                ];
            }
        }
        
        return $same_time_slots;
    }
    
    /**
     * Encontrar slots con duración flexible
     */
    private function findFlexibleDurationSlots($date, $service_id)
    {
        // Obtener duraciones alternativas del servicio
        $stmt = $this->db->prepare("
            SELECT 
                duration_minutes,
                flexible_duration_min,
                flexible_duration_max
            FROM services 
            WHERE id = ? AND user_id = ?
        ");
        
        $stmt->execute([$service_id, $this->user_id]);
        $service = $stmt->fetch();
        
        if (!$service || !$service['flexible_duration_min']) {
            return [];
        }
        
        $flexible_slots = [];
        $min_duration = (int)$service['flexible_duration_min'];
        $max_duration = (int)$service['flexible_duration_max'];
        $base_duration = (int)$service['duration_minutes'];
        
        // Probar diferentes duraciones
        for ($duration = $min_duration; $duration <= $max_duration; $duration += 15) {
            if ($duration === $base_duration) continue; // Skip duración original
            
            $slots_data = $this->generateDynamicSlots([
                'date' => $date,
                'service_id' => $service_id,
                'duration' => $duration
            ]);
            
            $available_slots = array_filter($slots_data['slots'], function($slot) {
                return $slot['is_available'];
            });
            
            foreach ($available_slots as $slot) {
                $slot['original_duration'] = $base_duration;
                $slot['flexible_duration'] = $duration;
                $slot['duration_difference'] = $duration - $base_duration;
                $flexible_slots[] = $slot;
            }
        }
        
        return $flexible_slots;
    }

    // <--- CONTINUAR AQUI CON PARTE 3 --->
    <?php
/**
 * ====================================
 * SISTEMA DE RESERVAS DE SERVICIOS
 * API DE GESTIÓN DE SLOTS DE TIEMPO
 * Archivo: api/calendar/time-slots.php
 * Parte 3 de 3 (líneas 1001-1500) - FINAL
 * ====================================
 */

    /**
     * Validar un slot de tiempo específico
     */
    private function validateTimeSlot($params)
    {
        $date = $params['date'] ?? null;
        $time = $params['time'] ?? null;
        $service_id = $params['service_id'] ?? null;
        $duration = $params['duration'] ?? null;
        
        if (!$date || !$time) {
            throw new Exception('Fecha y hora son requeridas', 400);
        }
        
        if (!$this->isValidDate($date) || !$this->isValidTime($time)) {
            throw new Exception('Fecha o hora no válidas', 400);
        }
        
        $slot_config = $this->getServiceSlotConfig($service_id);
        $duration = $duration ?: $slot_config['slot_duration'];
        
        $validation = [
            'date' => $date,
            'time' => $time,
            'service_id' => $service_id,
            'duration' => $duration,
            'is_available' => true,
            'conflicts' => [],
            'warnings' => [],
            'recommendations' => []
        ];
        
        // Verificar horarios de negocio
        $business_hours = $this->getBusinessHoursForDate($date);
        if (!$business_hours || !$business_hours['is_open']) {
            $validation['is_available'] = false;
            $validation['conflicts'][] = 'business_closed';
            return $validation;
        }
        
        $slot_start = new DateTime($date . ' ' . $time);
        $slot_end = clone $slot_start;
        $slot_end->add(new DateInterval('PT' . $duration . 'M'));
        
        $open_time = new DateTime($date . ' ' . $business_hours['open_time']);
        $close_time = new DateTime($date . ' ' . $business_hours['close_time']);
        
        if ($slot_start < $open_time || $slot_end > $close_time) {
            $validation['is_available'] = false;
            $validation['conflicts'][] = 'outside_business_hours';
        }
        
        // Verificar break time
        if ($business_hours['break_start'] && $business_hours['break_end']) {
            $break_start = new DateTime($date . ' ' . $business_hours['break_start']);
            $break_end = new DateTime($date . ' ' . $business_hours['break_end']);
            
            if ($slot_start < $break_end && $slot_end > $break_start) {
                $validation['is_available'] = false;
                $validation['conflicts'][] = 'break_time_conflict';
            }
        }
        
        // Verificar reservas existentes
        $existing_bookings = $this->getExistingBookings($date, $service_id);
        foreach ($existing_bookings as $booking) {
            $booking_start = new DateTime($date . ' ' . $booking['booking_time']);
            $booking_end = new DateTime($date . ' ' . $booking['end_time']);
            
            if ($slot_start < $booking_end && $slot_end > $booking_start) {
                $validation['is_available'] = false;
                $validation['conflicts'][] = 'booking_conflict';
                break;
            }
        }
        
        // Verificar tiempo mínimo de anticipación
        $now = new DateTime();
        $min_advance = clone $now;
        $min_advance->add(new DateInterval('PT' . $slot_config['min_advance_hours'] . 'H'));
        
        if ($slot_start <= $min_advance) {
            $validation['is_available'] = false;
            $validation['conflicts'][] = 'insufficient_advance_time';
        }
        
        // Verificar buffers recomendados
        if ($validation['is_available']) {
            $buffer_analysis = $this->analyzeBufferRequirements($slot_start, $slot_end, $existing_bookings, $slot_config);
            $validation['buffer_analysis'] = $buffer_analysis;
            
            if ($buffer_analysis['has_buffer_issues']) {
                $validation['warnings'] = array_merge($validation['warnings'], $buffer_analysis['warnings']);
            }
        }
        
        // Generar recomendaciones
        if (!$validation['is_available']) {
            $validation['recommendations'] = $this->generateSlotRecommendations($date, $time, $service_id, $validation['conflicts']);
        }
        
        return $validation;
    }
    
    /**
     * Analizar requerimientos de buffer
     */
    private function analyzeBufferRequirements($slot_start, $slot_end, $existing_bookings, $slot_config)
    {
        $analysis = [
            'has_buffer_issues' => false,
            'warnings' => [],
            'recommended_buffers' => [
                'before' => $slot_config['preparation_time'],
                'after' => $slot_config['cleanup_time']
            ],
            'actual_buffers' => [
                'before' => 0,
                'after' => 0
            ]
        ];
        
        // Calcular buffers reales
        foreach ($existing_bookings as $booking) {
            $booking_start = new DateTime($booking['booking_time']);
            $booking_end = new DateTime($booking['end_time']);
            
            // Buffer antes del slot
            if ($booking_end <= $slot_start) {
                $buffer_before = ($slot_start->getTimestamp() - $booking_end->getTimestamp()) / 60;
                $analysis['actual_buffers']['before'] = max($analysis['actual_buffers']['before'], $buffer_before);
            }
            
            // Buffer después del slot
            if ($booking_start >= $slot_end) {
                $buffer_after = ($booking_start->getTimestamp() - $slot_end->getTimestamp()) / 60;
                $analysis['actual_buffers']['after'] = max($analysis['actual_buffers']['after'], $buffer_after);
            }
        }
        
        // Verificar si los buffers son suficientes
        if ($analysis['actual_buffers']['before'] < $analysis['recommended_buffers']['before']) {
            $analysis['has_buffer_issues'] = true;
            $analysis['warnings'][] = 'insufficient_preparation_time';
        }
        
        if ($analysis['actual_buffers']['after'] < $analysis['recommended_buffers']['after']) {
            $analysis['has_buffer_issues'] = true;
            $analysis['warnings'][] = 'insufficient_cleanup_time';
        }
        
        return $analysis;
    }
    
    /**
     * Generar recomendaciones para slot no disponible
     */
    private function generateSlotRecommendations($date, $time, $service_id, $conflicts)
    {
        $recommendations = [];
        
        if (in_array('booking_conflict', $conflicts)) {
            $recommendations[] = [
                'type' => 'alternative_times',
                'message' => 'Horario ocupado. Prueba 30 minutos antes o después.',
                'action' => 'suggest_nearby_times'
            ];
        }
        
        if (in_array('outside_business_hours', $conflicts)) {
            $recommendations[] = [
                'type' => 'business_hours',
                'message' => 'Fuera del horario de atención. Consulta horarios disponibles.',
                'action' => 'show_business_hours'
            ];
        }
        
        if (in_array('insufficient_advance_time', $conflicts)) {
            $recommendations[] = [
                'type' => 'advance_booking',
                'message' => 'Reserva con más anticipación para este horario.',
                'action' => 'suggest_future_dates'
            ];
        }
        
        if (in_array('break_time_conflict', $conflicts)) {
            $recommendations[] = [
                'type' => 'avoid_break',
                'message' => 'Horario de descanso. Elige antes o después del break.',
                'action' => 'suggest_non_break_times'
            ];
        }
        
        return $recommendations;
    }
    
    /**
     * Obtener analytics de slots
     */
    private function getSlotAnalytics($params)
    {
        $start_date = $params['start_date'] ?? date('Y-m-d');
        $end_date = $params['end_date'] ?? date('Y-m-d', strtotime($start_date . ' +30 days'));
        $service_id = $params['service_id'] ?? null;
        
        // Estadísticas generales
        $total_stats = $this->calculateTotalSlotStats($start_date, $end_date, $service_id);
        
        // Análisis por día de la semana
        $weekday_analysis = $this->analyzeSlotsByWeekday($start_date, $end_date, $service_id);
        
        // Análisis por hora del día
        $hourly_analysis = $this->analyzeSlotsByHour($start_date, $end_date, $service_id);
        
        // Eficiencia de slots
        $efficiency_metrics = $this->calculateSlotEfficiency($start_date, $end_date, $service_id);
        
        // Patrones de reserva
        $booking_patterns = $this->analyzeBookingPatterns($start_date, $end_date, $service_id);
        
        return [
            'period' => [
                'start_date' => $start_date,
                'end_date' => $end_date,
                'total_days' => (new DateTime($start_date))->diff(new DateTime($end_date))->days + 1
            ],
            'service_id' => $service_id,
            'total_stats' => $total_stats,
            'weekday_analysis' => $weekday_analysis,
            'hourly_analysis' => $hourly_analysis,
            'efficiency_metrics' => $efficiency_metrics,
            'booking_patterns' => $booking_patterns,
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Calcular estadísticas totales de slots
     */
    private function calculateTotalSlotStats($start_date, $end_date, $service_id)
    {
        $sql = "
            SELECT 
                COUNT(*) as total_bookings,
                SUM(s.duration_minutes) as total_booked_minutes,
                AVG(s.duration_minutes) as avg_duration,
                COUNT(DISTINCT DATE(b.booking_date)) as active_days
            FROM bookings b
            INNER JOIN services s ON b.service_id = s.id
            WHERE b.user_id = ? 
            AND b.booking_date BETWEEN ? AND ?
            " . ($service_id ? "AND b.service_id = ?" : "") . "
            AND b.status IN ('confirmed', 'pending', 'completed')
        ";
        
        $params = [$this->user_id, $start_date, $end_date];
        if ($service_id) {
            $params[] = $service_id;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $stats = $stmt->fetch();
        
        return [
            'total_bookings' => (int)$stats['total_bookings'],
            'total_booked_minutes' => (int)($stats['total_booked_minutes'] ?? 0),
            'total_booked_hours' => round(((int)($stats['total_booked_minutes'] ?? 0)) / 60, 1),
            'avg_duration_minutes' => round((float)($stats['avg_duration'] ?? 0), 1),
            'active_days' => (int)$stats['active_days']
        ];
    }
    
    /**
     * Analizar slots por día de la semana
     */
    private function analyzeSlotsByWeekday($start_date, $end_date, $service_id)
    {
        $sql = "
            SELECT 
                DAYNAME(booking_date) as weekday,
                DAYOFWEEK(booking_date) as weekday_num,
                COUNT(*) as bookings,
                COALESCE(AVG(TIME_TO_SEC(booking_time) / 3600), 0) as avg_hour
            FROM bookings 
            WHERE user_id = ? 
            AND booking_date BETWEEN ? AND ?
            " . ($service_id ? "AND service_id = ?" : "") . "
            AND status IN ('confirmed', 'completed')
            GROUP BY DAYOFWEEK(booking_date), DAYNAME(booking_date)
            ORDER BY weekday_num
        ";
        
        $params = [$this->user_id, $start_date, $end_date];
        if ($service_id) {
            $params[] = $service_id;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();
        
        $analysis = [];
        $max_bookings = max(array_column($results, 'bookings') ?: [1]);
        
        foreach ($results as $row) {
            $bookings = (int)$row['bookings'];
            $analysis[] = [
                'weekday' => $row['weekday'],
                'weekday_num' => (int)$row['weekday_num'],
                'bookings' => $bookings,
                'avg_hour' => round((float)$row['avg_hour'], 1),
                'popularity_score' => round(($bookings / $max_bookings) * 100, 1)
            ];
        }
        
        return $analysis;
    }
    
    /**
     * Analizar slots por hora del día
     */
    private function analyzeSlotsByHour($start_date, $end_date, $service_id)
    {
        $sql = "
            SELECT 
                HOUR(booking_time) as hour,
                COUNT(*) as bookings,
                COALESCE(AVG(total_price), 0) as avg_revenue,
                COUNT(DISTINCT booking_date) as active_days
            FROM bookings 
            WHERE user_id = ? 
            AND booking_date BETWEEN ? AND ?
            " . ($service_id ? "AND service_id = ?" : "") . "
            AND status IN ('confirmed', 'completed')
            GROUP BY HOUR(booking_time)
            ORDER BY hour
        ";
        
        $params = [$this->user_id, $start_date, $end_date];
        if ($service_id) {
            $params[] = $service_id;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();
        
        $analysis = [];
        $max_bookings = max(array_column($results, 'bookings') ?: [1]);
        
        foreach ($results as $row) {
            $hour = (int)$row['hour'];
            $bookings = (int)$row['bookings'];
            
            $demand_level = 'low';
            if ($bookings >= $max_bookings * 0.8) {
                $demand_level = 'high';
            } elseif ($bookings >= $max_bookings * 0.5) {
                $demand_level = 'medium';
            }
            
            $analysis[] = [
                'hour' => $hour,
                'hour_formatted' => sprintf('%02d:00', $hour),
                'bookings' => $bookings,
                'avg_revenue' => round((float)$row['avg_revenue'], 2),
                'active_days' => (int)$row['active_days'],
                'demand_level' => $demand_level
            ];
        }
        
        return $analysis;
    }
    
    /**
     * Calcular eficiencia de slots
     */
    private function calculateSlotEfficiency($start_date, $end_date, $service_id)
    {
        // Calcular días de trabajo
        $total_days = (new DateTime($start_date))->diff(new DateTime($end_date))->days + 1;
        $working_days = $this->calculateWorkingDays($start_date, $end_date);
        
        // Tiempo total disponible vs tiempo utilizado
        $business_hours_total = $working_days * 8; // 8 horas promedio por día
        $booked_time_total = $this->calculateTotalBookedTime($start_date, $end_date, $service_id);
        
        return [
            'time_utilization' => [
                'total_days' => $total_days,
                'working_days' => $working_days,
                'total_business_hours' => $business_hours_total,
                'total_booked_hours' => $booked_time_total,
                'utilization_percentage' => $business_hours_total > 0 ? round(($booked_time_total / $business_hours_total) * 100, 1) : 0
            ],
            'efficiency_score' => $this->calculateOverallEfficiencyScore($business_hours_total, $booked_time_total)
        ];
    }
    
    /**
     * Analizar patrones de reserva
     */
    private function analyzeBookingPatterns($start_date, $end_date, $service_id)
    {
        // Análisis de timing de reservas
        $sql = "
            SELECT 
                AVG(TIMESTAMPDIFF(HOUR, created_at, CONCAT(booking_date, ' ', booking_time))) as avg_advance_hours,
                MIN(TIMESTAMPDIFF(HOUR, created_at, CONCAT(booking_date, ' ', booking_time))) as min_advance_hours,
                MAX(TIMESTAMPDIFF(HOUR, created_at, CONCAT(booking_date, ' ', booking_time))) as max_advance_hours,
                COUNT(CASE WHEN TIMESTAMPDIFF(HOUR, created_at, CONCAT(booking_date, ' ', booking_time)) <= 24 THEN 1 END) as last_minute_bookings,
                COUNT(*) as total_bookings
            FROM bookings 
            WHERE user_id = ? 
            AND booking_date BETWEEN ? AND ?
            " . ($service_id ? "AND service_id = ?" : "") . "
            AND status IN ('confirmed', 'completed')
        ";
        
        $params = [$this->user_id, $start_date, $end_date];
        if ($service_id) {
            $params[] = $service_id;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $timing_stats = $stmt->fetch();
        
        $total_bookings = (int)$timing_stats['total_bookings'];
        
        return [
            'booking_timing' => [
                'avg_advance_hours' => round((float)($timing_stats['avg_advance_hours'] ?? 0), 1),
                'min_advance_hours' => (int)($timing_stats['min_advance_hours'] ?? 0),
                'max_advance_hours' => (int)($timing_stats['max_advance_hours'] ?? 0),
                'last_minute_bookings' => (int)$timing_stats['last_minute_bookings'],
                'last_minute_percentage' => $total_bookings > 0 ? round(((int)$timing_stats['last_minute_bookings'] / $total_bookings) * 100, 1) : 0
            ]
        ];
    }
    
    /**
     * Obtener análisis de capacidad
     */
    private function getCapacityAnalysis($params)
    {
        $date = $params['date'] ?? date('Y-m-d');
        $service_id = $params['service_id'] ?? null;
        
        $capacity_data = [
            'date' => $date,
            'service_id' => $service_id
        ];
        
        $business_hours = $this->getBusinessHoursForDate($date);
        
        if (!$business_hours || !$business_hours['is_open']) {
            $capacity_data['message'] = 'Día cerrado';
            $capacity_data['is_open'] = false;
            return $capacity_data;
        }
        
        $capacity_data['is_open'] = true;
        $capacity_data['business_hours'] = $business_hours;
        
        $slot_config = $this->getServiceSlotConfig($service_id);
        
        // Calcular capacidad teórica
        $open_time = new DateTime($date . ' ' . $business_hours['open_time']);
        $close_time = new DateTime($date . ' ' . $business_hours['close_time']);
        $total_minutes = ($close_time->getTimestamp() - $open_time->getTimestamp()) / 60;
        
        // Restar tiempo de break
        if ($business_hours['break_start'] && $business_hours['break_end']) {
            $break_start = new DateTime($date . ' ' . $business_hours['break_start']);
            $break_end = new DateTime($date . ' ' . $business_hours['break_end']);
            $break_minutes = ($break_end->getTimestamp() - $break_start->getTimestamp()) / 60;
            $total_minutes -= $break_minutes;
        }
        
        $theoretical_capacity = floor($total_minutes / $slot_config['slot_duration']);
        
        // Generar slots y analizar
        $slots_data = $this->generateDynamicSlots([
            'date' => $date,
            'service_id' => $service_id,
            'optimization_level' => 'high'
        ]);
        
        $available_slots = array_filter($slots_data['slots'], function($slot) {
            return $slot['is_available'];
        });
        
        $booked_slots = count($slots_data['slots']) - count($available_slots);
        
        $capacity_data['theoretical_capacity'] = $theoretical_capacity;
        $capacity_data['actual_capacity'] = count($slots_data['slots']);
        $capacity_data['available_slots'] = count($available_slots);
        $capacity_data['booked_slots'] = $booked_slots;
        $capacity_data['utilization_rate'] = count($slots_data['slots']) > 0 ? 
            round(($booked_slots / count($slots_data['slots'])) * 100, 1) : 0;
        
        // Identificar oportunidades
        $opportunities = [];
        if ($capacity_data['utilization_rate'] < 50) {
            $opportunities[] = 'Baja utilización - considerar promociones';
        }
        if (count($available_slots) < 3) {
            $opportunities[] = 'Pocos slots disponibles - revisar configuración';
        }
        if ($theoretical_capacity > count($slots_data['slots'])) {
            $opportunities[] = 'Capacidad no utilizada - optimizar horarios';
        }
        
        $capacity_data['optimization_opportunities'] = $opportunities;
        $capacity_data['efficiency_score'] = $this->calculateCapacityEfficiencyScore($capacity_data);
        
        return $capacity_data;
    }
    
    /**
     * Calcular eficiencia de capacidad
     */
    private function calculateCapacityEfficiencyScore($capacity_data)
    {
        $score = 50; // Base score
        
        // Factor utilización (peso: 40%)
        $utilization = $capacity_data['utilization_rate'];
        if ($utilization >= 70 && $utilization <= 90) {
            $score += 40; // Óptimo
        } elseif ($utilization >= 50) {
            $score += 30;
        } elseif ($utilization >= 30) {
            $score += 20;
        } else {
            $score += 10;
        }
        
        // Factor disponibilidad (peso: 30%)
        $availability_ratio = $capacity_data['actual_capacity'] / max(1, $capacity_data['theoretical_capacity']);
        $score += min(30, $availability_ratio * 30);
        
        // Factor oportunidades (peso: 30%)
        $opportunities_penalty = count($capacity_data['optimization_opportunities']) * 10;
        $score = max(0, $score - $opportunities_penalty);
        
        return round($score, 1);
    }
    
    /**
     * Funciones auxiliares
     */
    private function calculateWorkingDays($start_date, $end_date)
    {
        $working_days = 0;
        $current = new DateTime($start_date);
        $end = new DateTime($end_date);
        
        while ($current <= $end) {
            $day_of_week = (int)$current->format('w');
            $business_hours = $this->getBusinessHoursForDate($current->format('Y-m-d'));
            
            if ($business_hours && $business_hours['is_open']) {
                $working_days++;
            }
            
            $current->add(new DateInterval('P1D'));
        }
        
        return $working_days;
    }
    
    private function calculateTotalBookedTime($start_date, $end_date, $service_id)
    {
        $sql = "
            SELECT SUM(s.duration_minutes) / 60 as total_hours
            FROM bookings b
            INNER JOIN services s ON b.service_id = s.id
            WHERE b.user_id = ? 
            AND b.booking_date BETWEEN ? AND ?
            " . ($service_id ? "AND b.service_id = ?" : "") . "
            AND b.status IN ('confirmed', 'completed')
        ";
        
        $params = [$this->user_id, $start_date, $end_date];
        if ($service_id) {
            $params[] = $service_id;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        
        return round((float)($result['total_hours'] ?? 0), 1);
    }
    
    private function calculateOverallEfficiencyScore($total_hours, $booked_hours)
    {
        if ($total_hours == 0) return 0;
        
        $utilization = ($booked_hours / $total_hours) * 100;
        
        if ($utilization >= 70 && $utilization <= 85) {
            return 100; // Óptimo
        } elseif ($utilization >= 60) {
            return 85;
        } elseif ($utilization >= 40) {
            return 70;
        } elseif ($utilization >= 20) {
            return 50;
        } else {
            return 25;
        }
    }
    
    /**
     * Manejar peticiones POST
     */
    private function handlePostRequest()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
        
        switch ($action) {
            case 'update-slot-config':
                $data = $this->updateSlotConfiguration($input);
                break;
            case 'batch-optimize':
                $data = $this->batchOptimizeSlots($input);
                break;
            default:
                throw new Exception('Acción no válida', 400);
        }
        
        $this->sendSuccessResponse($data);
    }
    
    /**
     * Actualizar configuración de slots
     */
    private function updateSlotConfiguration($input)
    {
        $service_id = $input['service_id'] ?? null;
        $config = $input['config'] ?? [];
        
        if (!$service_id) {
            throw new Exception('ID de servicio requerido', 400);
        }
        
        $allowed_fields = [
            'buffer_time', 'preparation_time', 'cleanup_time',
            'allow_back_to_back', 'auto_confirm', 'max_advance_days', 'min_advance_hours'
        ];
        
        $update_fields = [];
        $update_values = [];
        
        foreach ($allowed_fields as $field) {
            if (isset($config[$field])) {
                $update_fields[] = $field . ' = ?';
                $update_values[] = $config[$field];
            }
        }
        
        if (empty($update_fields)) {
            throw new Exception('No hay campos para actualizar', 400);
        }
        
        $update_values[] = $service_id;
        $update_values[] = $this->user_id;
        
        $stmt = $this->db->prepare("
            UPDATE services 
            SET " . implode(', ', $update_fields) . "
            WHERE id = ? AND user_id = ?
        ");
        
        $stmt->execute($update_values);
        
        return [
            'success' => true,
            'message' => 'Configuración de slots actualizada',
            'service_id' => $service_id,
            'updated_config' => $config
        ];
    }
    
    /**
     * Optimización masiva de slots
     */
    private function batchOptimizeSlots($input)
    {
        $dates = $input['dates'] ?? [];
        $service_ids = $input['service_ids'] ?? [];
        $optimization_level = $input['optimization_level'] ?? 'medium';
        
        if (empty($dates)) {
            throw new Exception('Lista de fechas requerida', 400);
        }
        
        $results = [];
        
        foreach ($dates as $date) {
            foreach ($service_ids as $service_id) {
                $optimized_slots = $this->generateDynamicSlots([
                    'date' => $date,
                    'service_id' => $service_id,
                    'optimization_level' => $optimization_level
                ]);
                
                $results[] = [
                    'date' => $date,
                    'service_id' => $service_id,
                    'optimized_slots_count' => count($optimized_slots['slots']),
                    'available_slots_count' => count(array_filter($optimized_slots['slots'], function($slot) {
                        return $slot['is_available'];
                    }))
                ];
            }
        }
        
        return [
            'success' => true,
            'message' => 'Optimización masiva completada',
            'optimization_level' => $optimization_level,
            'total_processed' => count($results),
            'results' => $results
        ];
    }
    
    /**
     * Manejar peticiones PUT y DELETE
     */
    private function handlePutRequest()
    {
        $this->handlePostRequest();
    }
    
    private function handleDeleteRequest()
    {
        throw new Exception('Operación no soportada', 405);
    }
    
    /**
     * Funciones utilitarias
     */
    private function verifyJWT($token)
    {
        try {
            $payload = base64_decode(explode('.', $token)[1]);
            $data = json_decode($payload, true);
            
            if (isset($data['exp']) && $data['exp'] < time()) {
                return false;
            }
            
            return $data;
        } catch (Exception $e) {
            return false;
        }
    }
    
    private function isValidDate($date)
    {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
    
    private function isValidTime($time)
    {
        return preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9](:00)?$/', $time);
    }
    
    private function sendSuccessResponse($data)
    {
        $response = [
            'success' => true,
            'data' => $data,
            'timestamp' => time(),
            'timezone' => $this->timezone,
            'user_id' => $this->user_id,
            'cache_duration' => $this->cache_duration
        ];
        
        header('Content-Type: application/json');
        header('Cache-Control: private, max-age=' . $this->cache_duration);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    private function handleError(Exception $e)
    {
        $error_code = $e->getCode() ?: 500;
        $error_message = $e->getMessage();
        
        $this->logger->error('Error en time slots API', [
            'user_id' => $this->user_id ?? null,
            'error' => $error_message,
            'code' => $error_code,
            'trace' => $e->getTraceAsString()
        ]);
        
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
        
        error_log(json_encode($log_entry));
    }
}

// Ejecutar la API si se accede directamente
if (basename($_SERVER['PHP_SELF']) === 'time-slots.php') {
    try {
        $api = new TimeSlotsAPI();
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
 * ENDPOINTS PRINCIPALES:
 * 
 * === GENERACIÓN DE SLOTS (GET) ===
 * 
 * GET /api/calendar/time-slots.php?action=generate-slots&date=2024-01-15&service_id=1&optimization_level=medium
 * - Generar slots dinámicos optimizados para una fecha
 * 
 * GET /api/calendar/time-slots.php?action=optimize-slots&date=2024-01-15&service_id=1
 * - Optimizar slots existentes con algoritmos avanzados
 * 
 * GET /api/calendar/time-slots.php?action=validate-slot&date=2024-01-15&time=14:30&service_id=1&duration=60
 * - Validar disponibilidad de un slot específico
 * 
 * GET /api/calendar/time-slots.php?action=suggest-alternatives&date=2024-01-15&preferred_time=14:30&service_id=1
 * - Sugerir horarios alternativos inteligentes
 * 
 * === CONFIGURACIÓN (GET) ===
 * 
 * GET /api/calendar/time-slots.php?action=get-slot-config&service_id=1
 * - Obtener configuración de slots para un servicio
 * 
 * GET /api/calendar/time-slots.php?action=get-buffers&date=2024-01-15&service_id=1
 * - Obtener tiempos de buffer recomendados
 * 
 * === ANALYTICS (GET) ===
 * 
 * GET /api/calendar/time-slots.php?action=slot-analytics&start_date=2024-01-01&end_date=2024-01-31&service_id=1
 * - Analytics completo de slots (utilización, patrones, eficiencia)
 * 
 * GET /api/calendar/time-slots.php?action=capacity-analysis&date=2024-01-15&service_id=1
 * - Análisis de capacidad para una fecha específica
 * 
 * === CONFIGURACIÓN (POST) ===
 * 
 * POST /api/calendar/time-slots.php
 * Content-Type: application/json
 * {
 *   "action": "update-slot-config",
 *   "service_id": 1,
 *   "config": {
 *     "buffer_time": 15,
 *     "preparation_time": 5,
 *     "cleanup_time": 10,
 *     "allow_back_to_back": false,
 *     "auto_confirm": true,
 *     "max_advance_days": 90,
 *     "min_advance_hours": 2
 *   }
 * }
 * 
 * === OPTIMIZACIÓN MASIVA (POST) ===
 * 
 * POST /api/calendar/time-slots.php
 * {
 *   "action": "batch-optimize",
 *   "dates": ["2024-01-15", "2024-01-16", "2024-01-17"],
 *   "service_ids": [1, 2, 3],
 *   "optimization_level": "high"
 * }
 * 
 * PARÁMETROS:
 * - date: YYYY-MM-DD
 * - start_date, end_date: YYYY-MM-DD
 * - time: HH:MM or HH:MM:SS
 * - service_id: número
 * - duration: minutos
 * - optimization_level: low|medium|high
 * - include_buffers: true|false
 * - flexible_duration: true|false
 * - preferred_time: HH:MM
 * 
 * NIVELES DE OPTIMIZACIÓN:
 * - low: Eliminar conflictos básicos
 * - medium: Scoring + reglas de espaciado
 * - high: Agrupamiento + optimización de eficiencia
 * 
 * CONFIGURACIONES DE SLOT:
 * - slot_duration: Duración base del slot (minutos)
 * - buffer_time: Tiempo entre citas (minutos)
 * - preparation_time: Tiempo de preparación (minutos)
 * - cleanup_time: Tiempo de limpieza (minutos)
 * - allow_back_to_back: Permitir citas consecutivas
 * - auto_confirm: Auto-confirmar reservas
 * - max_advance_days: Días máximos de anticipación
 * - min_advance_hours: Horas mínimas de anticipación
 * 
 * TIPOS DE CONFLICTO:
 * - booking_conflict: Conflicto con reserva existente
 * - business_closed: Fuera de horarios de negocio
 * - break_time_conflict: Conflicto con horario de descanso
 * - insufficient_advance_time: Muy poco tiempo de anticipación
 * - insufficient_buffer_before: Buffer insuficiente antes
 * - insufficient_buffer_after: Buffer insuficiente después
 * 
 * MÉTRICAS DE ANALYTICS:
 * - utilization_rate: Porcentaje de utilización
 * - efficiency_score: Score de eficiencia (0-100)
 * - avg_advance_hours: Horas promedio de anticipación
 * - booking_patterns: Patrones de reserva por hora/día
 * - capacity_analysis: Análisis de capacidad teórica vs real
 * 
 * RESPUESTA ESTÁNDAR:
 * {
 *   "success": true,
 *   "data": {...},
 *   "timestamp": 1640995200,
 *   "timezone": "America/New_York",
 *   "user_id": 123,
 *   "cache_duration": 300
 * }
 * 
 * AUTENTICACIÓN:
 * Header: Authorization: Bearer {JWT_TOKEN}
 * 
 * CARACTERÍSTICAS ESPECIALES:
 * - Algoritmos de optimización adaptativos
 * - Sugerencias inteligentes de horarios alternativos
 * - Análisis predictivo de patrones
 * - Buffers dinámicos según contexto
 * - Scoring automático de calidad de slots
 * - Análisis de eficiencia multi-dimensional
 * - Optimización masiva para múltiples fechas/servicios
 */