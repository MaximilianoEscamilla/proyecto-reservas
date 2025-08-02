<?php
/**
 * ====================================
 * SISTEMA DE RESERVAS DE SERVICIOS
 * API DE DISPONIBILIDAD Y HORARIOS
 * Archivo: api/calendar/availability.php
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
 * Clase AvailabilityAPI
 * Maneja toda la disponibilidad de horarios, slots de tiempo y configuración de calendario
 */
class AvailabilityAPI
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
        $this->logger = new Logger('availability');
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
            case 'get-availability':
                $data = $this->getAvailability($params);
                break;
            case 'get-time-slots':
                $data = $this->getTimeSlots($params);
                break;
            case 'get-schedule':
                $data = $this->getWeeklySchedule($params);
                break;
            case 'get-blocked-times':
                $data = $this->getBlockedTimes($params);
                break;
            case 'check-availability':
                $data = $this->checkSpecificAvailability($params);
                break;
            case 'get-business-hours':
                $data = $this->getBusinessHours();
                break;
            case 'get-holidays':
                $data = $this->getHolidays($params);
                break;
            default:
                throw new Exception('Acción no válida', 400);
        }
        
        $this->sendSuccessResponse($data);
    }
    
    /**
     * Manejar peticiones POST
     */
    private function handlePostRequest()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
        
        switch ($action) {
            case 'set-business-hours':
                $data = $this->setBusinessHours($input);
                break;
            case 'add-blocked-time':
                $data = $this->addBlockedTime($input);
                break;
            case 'add-holiday':
                $data = $this->addHoliday($input);
                break;
            case 'set-special-hours':
                $data = $this->setSpecialHours($input);
                break;
            case 'bulk-update-schedule':
                $data = $this->bulkUpdateSchedule($input);
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
        
        // Establecer timezone del usuario
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
            'date', 'start_date', 'end_date', 'service_id', 
            'duration', 'day_of_week', 'month', 'year'
        ];
        
        foreach ($allowed_params as $param) {
            if (isset($_GET[$param])) {
                $params[$param] = sanitize($_GET[$param]);
            }
        }
        
        return $params;
    }
    
    /**
     * Obtener disponibilidad para un rango de fechas
     */
    private function getAvailability($params)
    {
        $start_date = $params['start_date'] ?? date('Y-m-d');
        $end_date = $params['end_date'] ?? date('Y-m-d', strtotime($start_date . ' +30 days'));
        $service_id = $params['service_id'] ?? null;
        
        // Validar fechas
        if (!$this->isValidDate($start_date) || !$this->isValidDate($end_date)) {
            throw new Exception('Fechas no válidas', 400);
        }
        
        // Obtener horarios de negocio
        $business_hours = $this->getBusinessHours();
        
        // Obtener días bloqueados y feriados
        $blocked_dates = $this->getBlockedDates($start_date, $end_date);
        
        // Obtener reservas existentes
        $existing_bookings = $this->getExistingBookings($start_date, $end_date, $service_id);
        
        // Generar disponibilidad día por día
        $availability = [];
        $current_date = new DateTime($start_date);
        $end_dt = new DateTime($end_date);
        
        while ($current_date <= $end_dt) {
            $date_str = $current_date->format('Y-m-d');
            $day_of_week = (int)$current_date->format('w'); // 0 = Sunday, 6 = Saturday
            
            $day_availability = [
                'date' => $date_str,
                'day_name' => $current_date->format('l'),
                'is_available' => true,
                'reason' => null,
                'business_hours' => null,
                'time_slots' => []
            ];
            
            // Verificar si es día bloqueado o feriado
            if (in_array($date_str, $blocked_dates)) {
                $day_availability['is_available'] = false;
                $day_availability['reason'] = 'blocked_or_holiday';
            }
            // Verificar horarios de negocio
            elseif (!isset($business_hours[$day_of_week]) || !$business_hours[$day_of_week]['is_open']) {
                $day_availability['is_available'] = false;
                $day_availability['reason'] = 'closed';
            }
            else {
                // Día disponible, generar slots de tiempo
                $day_availability['business_hours'] = $business_hours[$day_of_week];
                $day_availability['time_slots'] = $this->generateTimeSlots(
                    $date_str, 
                    $business_hours[$day_of_week], 
                    $existing_bookings[$date_str] ?? [],
                    $service_id
                );
            }
            
            $availability[] = $day_availability;
            $current_date->add(new DateInterval('P1D'));
        }
        
        return [
            'start_date' => $start_date,
            'end_date' => $end_date,
            'service_id' => $service_id,
            'timezone' => $this->timezone,
            'availability' => $availability,
            'summary' => $this->getAvailabilitySummary($availability)
        ];
    }
    
    /**
     * Obtener horarios de negocio
     */
    private function getBusinessHours()
    {
        $stmt = $this->db->prepare("
            SELECT 
                day_of_week,
                is_open,
                open_time,
                close_time,
                break_start,
                break_end,
                slot_duration
            FROM business_hours 
            WHERE user_id = ?
            ORDER BY day_of_week
        ");
        
        $stmt->execute([$this->user_id]);
        $hours = $stmt->fetchAll();
        
        // Organizar por día de la semana
        $business_hours = [];
        foreach ($hours as $hour) {
            $business_hours[(int)$hour['day_of_week']] = [
                'is_open' => (bool)$hour['is_open'],
                'open_time' => $hour['open_time'],
                'close_time' => $hour['close_time'],
                'break_start' => $hour['break_start'],
                'break_end' => $hour['break_end'],
                'slot_duration' => (int)$hour['slot_duration'] ?: 30
            ];
        }
        
        // Completar días faltantes con valores por defecto
        for ($i = 0; $i < 7; $i++) {
            if (!isset($business_hours[$i])) {
                $business_hours[$i] = [
                    'is_open' => false,
                    'open_time' => null,
                    'close_time' => null,
                    'break_start' => null,
                    'break_end' => null,
                    'slot_duration' => 30
                ];
            }
        }
        
        return $business_hours;
    }
    
    /**
     * Obtener fechas bloqueadas y feriados
     */
    private function getBlockedDates($start_date, $end_date)
    {
        $stmt = $this->db->prepare("
            SELECT date_blocked, reason
            FROM blocked_dates 
            WHERE user_id = ? 
            AND date_blocked BETWEEN ? AND ?
            
            UNION
            
            SELECT holiday_date as date_blocked, 'holiday' as reason
            FROM holidays 
            WHERE user_id = ? 
            AND holiday_date BETWEEN ? AND ?
            AND is_active = 1
        ");
        
        $stmt->execute([
            $this->user_id, $start_date, $end_date,
            $this->user_id, $start_date, $end_date
        ]);
        
        $blocked = $stmt->fetchAll();
        return array_column($blocked, 'date_blocked');
    }
    
    /**
     * Obtener reservas existentes
     */
    private function getExistingBookings($start_date, $end_date, $service_id = null)
    {
        $sql = "
            SELECT 
                booking_date,
                booking_time,
                s.duration_minutes,
                b.status
            FROM bookings b
            INNER JOIN services s ON b.service_id = s.id
            WHERE b.user_id = ? 
            AND b.booking_date BETWEEN ? AND ?
            AND b.status IN ('confirmed', 'pending')
        ";
        
        $params = [$this->user_id, $start_date, $end_date];
        
        if ($service_id) {
            $sql .= " AND b.service_id = ?";
            $params[] = $service_id;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $bookings = $stmt->fetchAll();
        
        // Organizar por fecha
        $bookings_by_date = [];
        foreach ($bookings as $booking) {
            $date = $booking['booking_date'];
            if (!isset($bookings_by_date[$date])) {
                $bookings_by_date[$date] = [];
            }
            $bookings_by_date[$date][] = $booking;
        }
        
        return $bookings_by_date;
    }
    
    /**
     * Generar slots de tiempo disponibles para un día específico
     */
    private function generateTimeSlots($date, $business_hours, $existing_bookings, $service_id = null)
    {
        if (!$business_hours['is_open']) {
            return [];
        }
        
        $slots = [];
        $slot_duration = $business_hours['slot_duration'];
        
        // Obtener duración del servicio si se especifica
        $service_duration = $slot_duration;
        if ($service_id) {
            $service_duration = $this->getServiceDuration($service_id);
        }
        
        // Crear slots desde hora de apertura hasta cierre
        $current_time = new DateTime($date . ' ' . $business_hours['open_time']);
        $close_time = new DateTime($date . ' ' . $business_hours['close_time']);
        
        // Tiempos de descanso
        $break_start = $business_hours['break_start'] ? new DateTime($date . ' ' . $business_hours['break_start']) : null;
        $break_end = $business_hours['break_end'] ? new DateTime($date . ' ' . $business_hours['break_end']) : null;
        
        while ($current_time < $close_time) {
            $slot_end = clone $current_time;
            $slot_end->add(new DateInterval('PT' . $service_duration . 'M'));
            
            // Verificar que el slot completo cabe antes del cierre
            if ($slot_end > $close_time) {
                break;
            }
            
            $time_str = $current_time->format('H:i:s');
            $slot_info = [
                'time' => $time_str,
                'time_formatted' => $current_time->format('H:i'),
                'end_time' => $slot_end->format('H:i:s'),
                'end_time_formatted' => $slot_end->format('H:i'),
                'duration' => $service_duration,
                'is_available' => true,
                'reason' => null
            ];
            
            // Verificar si está en horario de descanso
            if ($break_start && $break_end && 
                $current_time >= $break_start && $current_time < $break_end) {
                $slot_info['is_available'] = false;
                $slot_info['reason'] = 'break_time';
            }
            // Verificar conflictos con reservas existentes
            elseif ($this->hasBookingConflict($current_time, $slot_end, $existing_bookings)) {
                $slot_info['is_available'] = false;
                $slot_info['reason'] = 'booked';
            }
            // Verificar si ya pasó la hora (solo para el día actual)
            elseif ($date === date('Y-m-d') && $current_time <= new DateTime()) {
                $slot_info['is_available'] = false;
                $slot_info['reason'] = 'past_time';
            }
            
            $slots[] = $slot_info;
            
            // Avanzar al siguiente slot
            $current_time->add(new DateInterval('PT' . $slot_duration . 'M'));
        }
        
        return $slots;
    }
    
    /**
     * Verificar conflicto con reservas existentes
     */
    private function hasBookingConflict($slot_start, $slot_end, $existing_bookings)
    {
        foreach ($existing_bookings as $booking) {
            $booking_start = new DateTime($booking['booking_time']);
            $booking_end = clone $booking_start;
            $booking_end->add(new DateInterval('PT' . $booking['duration_minutes'] . 'M'));
            
            // Verificar solapamiento
            if ($slot_start < $booking_end && $slot_end > $booking_start) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Obtener duración del servicio
     */
    private function getServiceDuration($service_id)
    {
        $stmt = $this->db->prepare("
            SELECT duration_minutes 
            FROM services 
            WHERE id = ? AND user_id = ?
        ");
        
        $stmt->execute([$service_id, $this->user_id]);
        $service = $stmt->fetch();
        
        return $service ? (int)$service['duration_minutes'] : 30;
    }
    
    /**
     * Obtener resumen de disponibilidad
     */
    private function getAvailabilitySummary($availability)
    {
        $total_days = count($availability);
        $available_days = 0;
        $total_slots = 0;
        $available_slots = 0;
        
        foreach ($availability as $day) {
            if ($day['is_available']) {
                $available_days++;
                foreach ($day['time_slots'] as $slot) {
                    $total_slots++;
                    if ($slot['is_available']) {
                        $available_slots++;
                    }
                }
            }
        }
        
        return [
            'total_days' => $total_days,
            'available_days' => $available_days,
            'closed_days' => $total_days - $available_days,
            'total_time_slots' => $total_slots,
            'available_time_slots' => $available_slots,
            'booked_slots' => $total_slots - $available_slots,
            'availability_percentage' => $total_slots > 0 ? round(($available_slots / $total_slots) * 100, 1) : 0
        ];
    }
    
    /**
     * Obtener slots de tiempo específicos para una fecha
     */
    private function getTimeSlots($params)
    {
        $date = $params['date'] ?? date('Y-m-d');
        $service_id = $params['service_id'] ?? null;
        
        if (!$this->isValidDate($date)) {
            throw new Exception('Fecha no válida', 400);
        }
        
        // Obtener disponibilidad para el día específico
        $availability = $this->getAvailability([
            'start_date' => $date,
            'end_date' => $date,
            'service_id' => $service_id
        ]);
        
        $day_data = $availability['availability'][0] ?? null;
        
        if (!$day_data) {
            throw new Exception('No se pudo obtener información del día', 500);
        }
        
        return [
            'date' => $date,
            'service_id' => $service_id,
            'is_available' => $day_data['is_available'],
            'reason' => $day_data['reason'],
            'business_hours' => $day_data['business_hours'],
            'time_slots' => $day_data['time_slots'],
            'timezone' => $this->timezone
        ];
    }

    // <--- CONTINUAR AQUI CON PARTE 2 --->

    <?php
/**
 * ====================================
 * SISTEMA DE RESERVAS DE SERVICIOS
 * API DE DISPONIBILIDAD Y HORARIOS
 * Archivo: api/calendar/availability.php
 * Parte 2 de 3 (líneas 501-1000)
 * ====================================
 */

    /**
     * Obtener horario semanal completo
     */
    private function getWeeklySchedule($params)
    {
        $business_hours = $this->getBusinessHours();
        
        // Obtener días especiales si se especifica una semana
        $start_date = $params['start_date'] ?? null;
        $special_hours = [];
        
        if ($start_date) {
            $end_date = date('Y-m-d', strtotime($start_date . ' +6 days'));
            $special_hours = $this->getSpecialHours($start_date, $end_date);
        }
        
        $schedule = [];
        $days = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
        
        for ($i = 0; $i < 7; $i++) {
            $date = $start_date ? date('Y-m-d', strtotime($start_date . ' +' . $i . ' days')) : null;
            
            // Verificar si hay horario especial para esta fecha
            $day_schedule = $special_hours[$date] ?? $business_hours[$i];
            
            $schedule[] = [
                'day_of_week' => $i,
                'day_name' => $days[$i],
                'date' => $date,
                'is_open' => $day_schedule['is_open'],
                'open_time' => $day_schedule['open_time'],
                'close_time' => $day_schedule['close_time'],
                'break_start' => $day_schedule['break_start'],
                'break_end' => $day_schedule['break_end'],
                'slot_duration' => $day_schedule['slot_duration'],
                'is_special_hours' => isset($special_hours[$date]),
                'total_hours' => $this->calculateWorkingHours($day_schedule)
            ];
        }
        
        return [
            'schedule' => $schedule,
            'start_date' => $start_date,
            'timezone' => $this->timezone,
            'summary' => $this->getScheduleSummary($schedule)
        ];
    }
    
    /**
     * Obtener horarios especiales para un rango de fechas
     */
    private function getSpecialHours($start_date, $end_date)
    {
        $stmt = $this->db->prepare("
            SELECT 
                special_date,
                is_open,
                open_time,
                close_time,
                break_start,
                break_end,
                reason
            FROM special_hours 
            WHERE user_id = ? 
            AND special_date BETWEEN ? AND ?
        ");
        
        $stmt->execute([$this->user_id, $start_date, $end_date]);
        $special_hours = $stmt->fetchAll();
        
        $hours_by_date = [];
        foreach ($special_hours as $hour) {
            $hours_by_date[$hour['special_date']] = [
                'is_open' => (bool)$hour['is_open'],
                'open_time' => $hour['open_time'],
                'close_time' => $hour['close_time'],
                'break_start' => $hour['break_start'],
                'break_end' => $hour['break_end'],
                'slot_duration' => 30, // Valor por defecto
                'reason' => $hour['reason']
            ];
        }
        
        return $hours_by_date;
    }
    
    /**
     * Calcular horas de trabajo
     */
    private function calculateWorkingHours($schedule)
    {
        if (!$schedule['is_open'] || !$schedule['open_time'] || !$schedule['close_time']) {
            return 0;
        }
        
        $open = new DateTime($schedule['open_time']);
        $close = new DateTime($schedule['close_time']);
        $total_minutes = $open->diff($close)->h * 60 + $open->diff($close)->i;
        
        // Restar tiempo de descanso
        if ($schedule['break_start'] && $schedule['break_end']) {
            $break_start = new DateTime($schedule['break_start']);
            $break_end = new DateTime($schedule['break_end']);
            $break_minutes = $break_start->diff($break_end)->h * 60 + $break_start->diff($break_end)->i;
            $total_minutes -= $break_minutes;
        }
        
        return round($total_minutes / 60, 2);
    }
    
    /**
     * Obtener resumen del horario semanal
     */
    private function getScheduleSummary($schedule)
    {
        $open_days = 0;
        $total_hours = 0;
        $earliest_open = null;
        $latest_close = null;
        
        foreach ($schedule as $day) {
            if ($day['is_open']) {
                $open_days++;
                $total_hours += $day['total_hours'];
                
                if (!$earliest_open || $day['open_time'] < $earliest_open) {
                    $earliest_open = $day['open_time'];
                }
                
                if (!$latest_close || $day['close_time'] > $latest_close) {
                    $latest_close = $day['close_time'];
                }
            }
        }
        
        return [
            'open_days' => $open_days,
            'closed_days' => 7 - $open_days,
            'total_hours_per_week' => round($total_hours, 2),
            'avg_hours_per_day' => $open_days > 0 ? round($total_hours / $open_days, 2) : 0,
            'earliest_open_time' => $earliest_open,
            'latest_close_time' => $latest_close
        ];
    }
    
    /**
     * Obtener tiempos bloqueados específicos
     */
    private function getBlockedTimes($params)
    {
        $start_date = $params['start_date'] ?? date('Y-m-d');
        $end_date = $params['end_date'] ?? date('Y-m-d', strtotime($start_date . ' +30 days'));
        
        // Días completamente bloqueados
        $stmt = $this->db->prepare("
            SELECT 
                date_blocked,
                reason,
                'full_day' as block_type,
                NULL as start_time,
                NULL as end_time
            FROM blocked_dates 
            WHERE user_id = ? 
            AND date_blocked BETWEEN ? AND ?
            
            UNION ALL
            
            SELECT 
                holiday_date as date_blocked,
                holiday_name as reason,
                'holiday' as block_type,
                NULL as start_time,
                NULL as end_time
            FROM holidays 
            WHERE user_id = ? 
            AND holiday_date BETWEEN ? AND ?
            AND is_active = 1
            
            UNION ALL
            
            SELECT 
                block_date as date_blocked,
                reason,
                'time_range' as block_type,
                start_time,
                end_time
            FROM blocked_time_ranges 
            WHERE user_id = ? 
            AND block_date BETWEEN ? AND ?
            
            ORDER BY date_blocked, start_time
        ");
        
        $stmt->execute([
            $this->user_id, $start_date, $end_date,
            $this->user_id, $start_date, $end_date,
            $this->user_id, $start_date, $end_date
        ]);
        
        $blocked_times = $stmt->fetchAll();
        
        // Organizar por fecha
        $blocked_by_date = [];
        foreach ($blocked_times as $block) {
            $date = $block['date_blocked'];
            if (!isset($blocked_by_date[$date])) {
                $blocked_by_date[$date] = [];
            }
            $blocked_by_date[$date][] = $block;
        }
        
        return [
            'start_date' => $start_date,
            'end_date' => $end_date,
            'blocked_times' => $blocked_by_date,
            'summary' => [
                'total_blocked_dates' => count($blocked_by_date),
                'total_blocks' => count($blocked_times)
            ]
        ];
    }
    
    /**
     * Verificar disponibilidad específica
     */
    private function checkSpecificAvailability($params)
    {
        $date = $params['date'] ?? null;
        $time = $params['time'] ?? null;
        $service_id = $params['service_id'] ?? null;
        $duration = $params['duration'] ?? null;
        
        if (!$date || !$time) {
            throw new Exception('Fecha y hora son requeridas', 400);
        }
        
        if (!$this->isValidDate($date)) {
            throw new Exception('Fecha no válida', 400);
        }
        
        if (!$this->isValidTime($time)) {
            throw new Exception('Hora no válida', 400);
        }
        
        // Obtener duración del servicio si no se especifica
        if (!$duration && $service_id) {
            $duration = $this->getServiceDuration($service_id);
        }
        
        $duration = $duration ?: 30; // Duración por defecto
        
        $start_datetime = new DateTime($date . ' ' . $time);
        $end_datetime = clone $start_datetime;
        $end_datetime->add(new DateInterval('PT' . $duration . 'M'));
        
        $availability_check = [
            'date' => $date,
            'time' => $time,
            'end_time' => $end_datetime->format('H:i:s'),
            'duration' => $duration,
            'service_id' => $service_id,
            'is_available' => true,
            'conflicts' => []
        ];
        
        // Verificar día de la semana y horarios de negocio
        $day_of_week = (int)$start_datetime->format('w');
        $business_hours = $this->getBusinessHours();
        
        if (!isset($business_hours[$day_of_week]) || !$business_hours[$day_of_week]['is_open']) {
            $availability_check['is_available'] = false;
            $availability_check['conflicts'][] = 'closed_day';
        } else {
            $day_hours = $business_hours[$day_of_week];
            
            // Verificar horario de apertura/cierre
            $open_time = new DateTime($date . ' ' . $day_hours['open_time']);
            $close_time = new DateTime($date . ' ' . $day_hours['close_time']);
            
            if ($start_datetime < $open_time || $end_datetime > $close_time) {
                $availability_check['is_available'] = false;
                $availability_check['conflicts'][] = 'outside_business_hours';
            }
            
            // Verificar horario de descanso
            if ($day_hours['break_start'] && $day_hours['break_end']) {
                $break_start = new DateTime($date . ' ' . $day_hours['break_start']);
                $break_end = new DateTime($date . ' ' . $day_hours['break_end']);
                
                if ($start_datetime < $break_end && $end_datetime > $break_start) {
                    $availability_check['is_available'] = false;
                    $availability_check['conflicts'][] = 'break_time';
                }
            }
        }
        
        // Verificar días bloqueados
        $blocked_dates = $this->getBlockedDates($date, $date);
        if (in_array($date, $blocked_dates)) {
            $availability_check['is_available'] = false;
            $availability_check['conflicts'][] = 'blocked_date';
        }
        
        // Verificar conflictos con reservas existentes
        $existing_bookings = $this->getExistingBookings($date, $date, $service_id);
        if (isset($existing_bookings[$date]) && 
            $this->hasBookingConflict($start_datetime, $end_datetime, $existing_bookings[$date])) {
            $availability_check['is_available'] = false;
            $availability_check['conflicts'][] = 'booking_conflict';
        }
        
        // Verificar si ya pasó la hora (solo para el día actual)
        if ($date === date('Y-m-d') && $start_datetime <= new DateTime()) {
            $availability_check['is_available'] = false;
            $availability_check['conflicts'][] = 'past_time';
        }
        
        return $availability_check;
    }
    
    /**
     * Obtener feriados
     */
    private function getHolidays($params)
    {
        $year = $params['year'] ?? date('Y');
        
        $stmt = $this->db->prepare("
            SELECT 
                id,
                holiday_name,
                holiday_date,
                is_recurring,
                is_active,
                created_at
            FROM holidays 
            WHERE user_id = ? 
            AND YEAR(holiday_date) = ?
            ORDER BY holiday_date
        ");
        
        $stmt->execute([$this->user_id, $year]);
        $holidays = $stmt->fetchAll();
        
        return [
            'year' => $year,
            'holidays' => $holidays,
            'total_holidays' => count($holidays)
        ];
    }
    
    /**
     * Establecer horarios de negocio
     */
    private function setBusinessHours($input)
    {
        $schedule = $input['schedule'] ?? [];
        
        if (!is_array($schedule) || count($schedule) !== 7) {
            throw new Exception('Horario debe incluir los 7 días de la semana', 400);
        }
        
        $this->db->beginTransaction();
        
        try {
            // Eliminar horarios existentes
            $stmt = $this->db->prepare("DELETE FROM business_hours WHERE user_id = ?");
            $stmt->execute([$this->user_id]);
            
            // Insertar nuevos horarios
            $stmt = $this->db->prepare("
                INSERT INTO business_hours (
                    user_id, day_of_week, is_open, open_time, close_time, 
                    break_start, break_end, slot_duration
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($schedule as $day_index => $day_hours) {
                if ($day_index < 0 || $day_index > 6) {
                    throw new Exception('Día de la semana no válido: ' . $day_index, 400);
                }
                
                $is_open = (bool)($day_hours['is_open'] ?? false);
                $open_time = $is_open ? ($day_hours['open_time'] ?? null) : null;
                $close_time = $is_open ? ($day_hours['close_time'] ?? null) : null;
                $break_start = $day_hours['break_start'] ?? null;
                $break_end = $day_hours['break_end'] ?? null;
                $slot_duration = (int)($day_hours['slot_duration'] ?? 30);
                
                // Validaciones
                if ($is_open) {
                    if (!$open_time || !$close_time) {
                        throw new Exception('Horarios de apertura y cierre son requeridos para días abiertos', 400);
                    }
                    
                    if (!$this->isValidTime($open_time) || !$this->isValidTime($close_time)) {
                        throw new Exception('Formato de hora no válido', 400);
                    }
                    
                    if ($open_time >= $close_time) {
                        throw new Exception('Hora de apertura debe ser anterior a la de cierre', 400);
                    }
                    
                    if ($break_start && $break_end) {
                        if (!$this->isValidTime($break_start) || !$this->isValidTime($break_end)) {
                            throw new Exception('Formato de hora de descanso no válido', 400);
                        }
                        
                        if ($break_start >= $break_end) {
                            throw new Exception('Hora de inicio de descanso debe ser anterior a la de fin', 400);
                        }
                    }
                }
                
                $stmt->execute([
                    $this->user_id,
                    $day_index,
                    $is_open ? 1 : 0,
                    $open_time,
                    $close_time,
                    $break_start,
                    $break_end,
                    $slot_duration
                ]);
            }
            
            $this->db->commit();
            
            // Log de la acción
            $this->logger->info('Horarios de negocio actualizados', [
                'user_id' => $this->user_id,
                'schedule' => $schedule
            ]);
            
            return [
                'success' => true,
                'message' => 'Horarios de negocio actualizados correctamente',
                'schedule' => $this->getBusinessHours()
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Agregar tiempo bloqueado
     */
    private function addBlockedTime($input)
    {
        $date = $input['date'] ?? null;
        $start_time = $input['start_time'] ?? null;
        $end_time = $input['end_time'] ?? null;
        $reason = $input['reason'] ?? 'Bloqueado';
        $block_type = $input['block_type'] ?? 'full_day'; // full_day, time_range
        
        if (!$date) {
            throw new Exception('Fecha es requerida', 400);
        }
        
        if (!$this->isValidDate($date)) {
            throw new Exception('Fecha no válida', 400);
        }
        
        if ($block_type === 'full_day') {
            // Bloquear día completo
            $stmt = $this->db->prepare("
                INSERT INTO blocked_dates (user_id, date_blocked, reason)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE reason = VALUES(reason)
            ");
            
            $stmt->execute([$this->user_id, $date, $reason]);
            
        } else {
            // Bloquear rango de tiempo específico
            if (!$start_time || !$end_time) {
                throw new Exception('Hora de inicio y fin son requeridas para bloqueo de tiempo', 400);
            }
            
            if (!$this->isValidTime($start_time) || !$this->isValidTime($end_time)) {
                throw new Exception('Formato de hora no válido', 400);
            }
            
            if ($start_time >= $end_time) {
                throw new Exception('Hora de inicio debe ser anterior a la hora de fin', 400);
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO blocked_time_ranges (user_id, block_date, start_time, end_time, reason)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([$this->user_id, $date, $start_time, $end_time, $reason]);
        }
        
        // Log de la acción
        $this->logger->info('Tiempo bloqueado agregado', [
            'user_id' => $this->user_id,
            'date' => $date,
            'type' => $block_type,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'reason' => $reason
        ]);
        
        return [
            'success' => true,
            'message' => 'Tiempo bloqueado agregado correctamente',
            'blocked_time' => [
                'date' => $date,
                'type' => $block_type,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'reason' => $reason
            ]
        ];
    }
    
    /**
     * Agregar feriado
     */
    private function addHoliday($input)
    {
        $name = $input['name'] ?? null;
        $date = $input['date'] ?? null;
        $is_recurring = (bool)($input['is_recurring'] ?? false);
        
        if (!$name || !$date) {
            throw new Exception('Nombre y fecha del feriado son requeridos', 400);
        }
        
        if (!$this->isValidDate($date)) {
            throw new Exception('Fecha no válida', 400);
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO holidays (user_id, holiday_name, holiday_date, is_recurring, is_active)
            VALUES (?, ?, ?, ?, 1)
        ");
        
        $stmt->execute([$this->user_id, $name, $date, $is_recurring ? 1 : 0]);
        $holiday_id = $this->db->lastInsertId();
        
        // Log de la acción
        $this->logger->info('Feriado agregado', [
            'user_id' => $this->user_id,
            'holiday_id' => $holiday_id,
            'name' => $name,
            'date' => $date,
            'is_recurring' => $is_recurring
        ]);
        
        return [
            'success' => true,
            'message' => 'Feriado agregado correctamente',
            'holiday' => [
                'id' => $holiday_id,
                'name' => $name,
                'date' => $date,
                'is_recurring' => $is_recurring
            ]
        ];
    }
    
    /**
     * Establecer horarios especiales
     */
    private function setSpecialHours($input)
    {
        $date = $input['date'] ?? null;
        $is_open = (bool)($input['is_open'] ?? false);
        $open_time = $input['open_time'] ?? null;
        $close_time = $input['close_time'] ?? null;
        $break_start = $input['break_start'] ?? null;
        $break_end = $input['break_end'] ?? null;
        $reason = $input['reason'] ?? 'Horario especial';
        
        if (!$date) {
            throw new Exception('Fecha es requerida', 400);
        }
        
        if (!$this->isValidDate($date)) {
            throw new Exception('Fecha no válida', 400);
        }
        
        // Validaciones para días abiertos
        if ($is_open) {
            if (!$open_time || !$close_time) {
                throw new Exception('Horarios de apertura y cierre son requeridos', 400);
            }
            
            if (!$this->isValidTime($open_time) || !$this->isValidTime($close_time)) {
                throw new Exception('Formato de hora no válido', 400);
            }
            
            if ($open_time >= $close_time) {
                throw new Exception('Hora de apertura debe ser anterior a la de cierre', 400);
            }
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO special_hours (
                user_id, special_date, is_open, open_time, close_time, 
                break_start, break_end, reason
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                is_open = VALUES(is_open),
                open_time = VALUES(open_time),
                close_time = VALUES(close_time),
                break_start = VALUES(break_start),
                break_end = VALUES(break_end),
                reason = VALUES(reason)
        ");
        
        $stmt->execute([
            $this->user_id,
            $date,
            $is_open ? 1 : 0,
            $is_open ? $open_time : null,
            $is_open ? $close_time : null,
            $break_start,
            $break_end,
            $reason
        ]);
        
        // Log de la acción
        $this->logger->info('Horario especial establecido', [
            'user_id' => $this->user_id,
            'date' => $date,
            'is_open' => $is_open,
            'reason' => $reason
        ]);
        
        return [
            'success' => true,
            'message' => 'Horario especial establecido correctamente',
            'special_hours' => [
                'date' => $date,
                'is_open' => $is_open,
                'open_time' => $open_time,
                'close_time' => $close_time,
                'break_start' => $break_start,
                'break_end' => $break_end,
                'reason' => $reason
            ]
        ];
    }

    // <--- CONTINUAR AQUI CON PARTE 3 --->

    <?php
/**
 * ====================================
 * SISTEMA DE RESERVAS DE SERVICIOS
 * API DE DISPONIBILIDAD Y HORARIOS
 * Archivo: api/calendar/availability.php
 * Parte 3 de 3 (líneas 1001-1500) - FINAL
 * ====================================
 */

    /**
     * Actualización masiva de horarios
     */
    private function bulkUpdateSchedule($input)
    {
        $updates = $input['updates'] ?? [];
        $operation = $input['operation'] ?? 'update'; // update, copy, reset
        
        if (!is_array($updates) || empty($updates)) {
            throw new Exception('Lista de actualizaciones requerida', 400);
        }
        
        $this->db->beginTransaction();
        
        try {
            $results = [];
            
            foreach ($updates as $update) {
                switch ($operation) {
                    case 'update':
                        $result = $this->processSingleScheduleUpdate($update);
                        break;
                    case 'copy':
                        $result = $this->copyScheduleToDate($update);
                        break;
                    case 'reset':
                        $result = $this->resetScheduleToDefault($update);
                        break;
                    default:
                        throw new Exception('Operación no válida: ' . $operation, 400);
                }
                
                $results[] = $result;
            }
            
            $this->db->commit();
            
            // Log de la acción
            $this->logger->info('Actualización masiva de horarios', [
                'user_id' => $this->user_id,
                'operation' => $operation,
                'updates_count' => count($updates)
            ]);
            
            return [
                'success' => true,
                'message' => 'Horarios actualizados masivamente',
                'operation' => $operation,
                'results' => $results,
                'total_updates' => count($results)
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Procesar actualización individual de horario
     */
    private function processSingleScheduleUpdate($update)
    {
        $date = $update['date'] ?? null;
        $type = $update['type'] ?? 'special_hours'; // special_hours, blocked_time, holiday
        
        if (!$date || !$this->isValidDate($date)) {
            throw new Exception('Fecha no válida en actualización: ' . $date, 400);
        }
        
        switch ($type) {
            case 'special_hours':
                return $this->setSpecialHours($update);
            case 'blocked_time':
                return $this->addBlockedTime($update);
            case 'holiday':
                return $this->addHoliday($update);
            default:
                throw new Exception('Tipo de actualización no válido: ' . $type, 400);
        }
    }
    
    /**
     * Copiar horario a fecha específica
     */
    private function copyScheduleToDate($update)
    {
        $source_date = $update['source_date'] ?? null;
        $target_date = $update['target_date'] ?? null;
        
        if (!$source_date || !$target_date) {
            throw new Exception('Fechas de origen y destino requeridas', 400);
        }
        
        if (!$this->isValidDate($source_date) || !$this->isValidDate($target_date)) {
            throw new Exception('Fechas no válidas', 400);
        }
        
        // Obtener horario especial de la fecha origen
        $source_hours = $this->getSpecialHours($source_date, $source_date);
        
        if (isset($source_hours[$source_date])) {
            // Copiar horario especial
            $special_hours = $source_hours[$source_date];
            $this->setSpecialHours([
                'date' => $target_date,
                'is_open' => $special_hours['is_open'],
                'open_time' => $special_hours['open_time'],
                'close_time' => $special_hours['close_time'],
                'break_start' => $special_hours['break_start'],
                'break_end' => $special_hours['break_end'],
                'reason' => 'Copiado desde ' . $source_date
            ]);
        } else {
            // Usar horario regular del día de la semana
            $source_day_of_week = (int)(new DateTime($source_date))->format('w');
            $business_hours = $this->getBusinessHours();
            $day_hours = $business_hours[$source_day_of_week];
            
            $this->setSpecialHours([
                'date' => $target_date,
                'is_open' => $day_hours['is_open'],
                'open_time' => $day_hours['open_time'],
                'close_time' => $day_hours['close_time'],
                'break_start' => $day_hours['break_start'],
                'break_end' => $day_hours['break_end'],
                'reason' => 'Copiado desde ' . $source_date
            ]);
        }
        
        return [
            'success' => true,
            'message' => "Horario copiado de {$source_date} a {$target_date}",
            'source_date' => $source_date,
            'target_date' => $target_date
        ];
    }
    
    /**
     * Resetear horario a valores por defecto
     */
    private function resetScheduleToDefault($update)
    {
        $date = $update['date'] ?? null;
        
        if (!$date || !$this->isValidDate($date)) {
            throw new Exception('Fecha no válida', 400);
        }
        
        // Eliminar horarios especiales
        $stmt = $this->db->prepare("DELETE FROM special_hours WHERE user_id = ? AND special_date = ?");
        $stmt->execute([$this->user_id, $date]);
        
        // Eliminar bloqueos
        $stmt = $this->db->prepare("DELETE FROM blocked_dates WHERE user_id = ? AND date_blocked = ?");
        $stmt->execute([$this->user_id, $date]);
        
        $stmt = $this->db->prepare("DELETE FROM blocked_time_ranges WHERE user_id = ? AND block_date = ?");
        $stmt->execute([$this->user_id, $date]);
        
        return [
            'success' => true,
            'message' => "Horario reseteado para {$date}",
            'date' => $date
        ];
    }
    
    /**
     * Manejar peticiones PUT
     */
    private function handlePutRequest()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
        
        switch ($action) {
            case 'update-business-hours':
                $data = $this->setBusinessHours($input);
                break;
            case 'update-holiday':
                $data = $this->updateHoliday($input);
                break;
            case 'update-blocked-time':
                $data = $this->updateBlockedTime($input);
                break;
            case 'update-special-hours':
                $data = $this->setSpecialHours($input);
                break;
            default:
                throw new Exception('Acción no válida', 400);
        }
        
        $this->sendSuccessResponse($data);
    }
    
    /**
     * Manejar peticiones DELETE
     */
    private function handleDeleteRequest()
    {
        $action = $_GET['action'] ?? '';
        $id = $_GET['id'] ?? null;
        $date = $_GET['date'] ?? null;
        
        switch ($action) {
            case 'delete-holiday':
                $data = $this->deleteHoliday($id);
                break;
            case 'delete-blocked-time':
                $data = $this->deleteBlockedTime($id, $date);
                break;
            case 'delete-special-hours':
                $data = $this->deleteSpecialHours($date);
                break;
            case 'clear-schedule':
                $data = $this->clearSchedule($date);
                break;
            default:
                throw new Exception('Acción no válida', 400);
        }
        
        $this->sendSuccessResponse($data);
    }
    
    /**
     * Actualizar feriado
     */
    private function updateHoliday($input)
    {
        $id = $input['id'] ?? null;
        $name = $input['name'] ?? null;
        $date = $input['date'] ?? null;
        $is_recurring = (bool)($input['is_recurring'] ?? false);
        $is_active = (bool)($input['is_active'] ?? true);
        
        if (!$id) {
            throw new Exception('ID del feriado requerido', 400);
        }
        
        if ($name && $date) {
            if (!$this->isValidDate($date)) {
                throw new Exception('Fecha no válida', 400);
            }
        }
        
        // Verificar que el feriado pertenece al usuario
        $stmt = $this->db->prepare("SELECT id FROM holidays WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $this->user_id]);
        
        if (!$stmt->fetch()) {
            throw new Exception('Feriado no encontrado', 404);
        }
        
        // Actualizar
        $stmt = $this->db->prepare("
            UPDATE holidays 
            SET holiday_name = COALESCE(?, holiday_name),
                holiday_date = COALESCE(?, holiday_date),
                is_recurring = ?,
                is_active = ?
            WHERE id = ? AND user_id = ?
        ");
        
        $stmt->execute([$name, $date, $is_recurring ? 1 : 0, $is_active ? 1 : 0, $id, $this->user_id]);
        
        return [
            'success' => true,
            'message' => 'Feriado actualizado correctamente',
            'holiday_id' => $id
        ];
    }
    
    /**
     * Eliminar feriado
     */
    private function deleteHoliday($id)
    {
        if (!$id) {
            throw new Exception('ID del feriado requerido', 400);
        }
        
        $stmt = $this->db->prepare("DELETE FROM holidays WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $this->user_id]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Feriado no encontrado', 404);
        }
        
        return [
            'success' => true,
            'message' => 'Feriado eliminado correctamente',
            'holiday_id' => $id
        ];
    }
    
    /**
     * Eliminar tiempo bloqueado
     */
    private function deleteBlockedTime($id, $date)
    {
        if ($id) {
            // Eliminar por ID (bloqueo de rango de tiempo)
            $stmt = $this->db->prepare("DELETE FROM blocked_time_ranges WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $this->user_id]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Bloqueo de tiempo no encontrado', 404);
            }
        } elseif ($date) {
            // Eliminar por fecha (día completo bloqueado)
            $stmt = $this->db->prepare("DELETE FROM blocked_dates WHERE date_blocked = ? AND user_id = ?");
            $stmt->execute([$date, $this->user_id]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Día bloqueado no encontrado', 404);
            }
        } else {
            throw new Exception('ID o fecha requeridos', 400);
        }
        
        return [
            'success' => true,
            'message' => 'Tiempo bloqueado eliminado correctamente'
        ];
    }
    
    /**
     * Eliminar horarios especiales
     */
    private function deleteSpecialHours($date)
    {
        if (!$date) {
            throw new Exception('Fecha requerida', 400);
        }
        
        $stmt = $this->db->prepare("DELETE FROM special_hours WHERE special_date = ? AND user_id = ?");
        $stmt->execute([$date, $this->user_id]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Horario especial no encontrado', 404);
        }
        
        return [
            'success' => true,
            'message' => 'Horario especial eliminado correctamente',
            'date' => $date
        ];
    }
    
    /**
     * Limpiar horario completo de una fecha
     */
    private function clearSchedule($date)
    {
        if (!$date) {
            throw new Exception('Fecha requerida', 400);
        }
        
        if (!$this->isValidDate($date)) {
            throw new Exception('Fecha no válida', 400);
        }
        
        $this->db->beginTransaction();
        
        try {
            // Eliminar horarios especiales
            $stmt1 = $this->db->prepare("DELETE FROM special_hours WHERE special_date = ? AND user_id = ?");
            $stmt1->execute([$date, $this->user_id]);
            
            // Eliminar días bloqueados
            $stmt2 = $this->db->prepare("DELETE FROM blocked_dates WHERE date_blocked = ? AND user_id = ?");
            $stmt2->execute([$date, $this->user_id]);
            
            // Eliminar rangos de tiempo bloqueados
            $stmt3 = $this->db->prepare("DELETE FROM blocked_time_ranges WHERE block_date = ? AND user_id = ?");
            $stmt3->execute([$date, $this->user_id]);
            
            $total_deleted = $stmt1->rowCount() + $stmt2->rowCount() + $stmt3->rowCount();
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Horario limpiado correctamente',
                'date' => $date,
                'items_deleted' => $total_deleted
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
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
     * Validar formato de hora
     */
    private function isValidTime($time)
    {
        return preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9](:00)?$/', $time);
    }
    
    /**
     * Enviar respuesta de éxito
     */
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
    
    /**
     * Manejar errores
     */
    private function handleError(Exception $e)
    {
        $error_code = $e->getCode() ?: 500;
        $error_message = $e->getMessage();
        
        // Log del error
        $this->logger->error('Error en availability API', [
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
if (basename($_SERVER['PHP_SELF']) === 'availability.php') {
    try {
        $api = new AvailabilityAPI();
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
 * === CONSULTAS (GET) ===
 * 
 * GET /api/calendar/availability.php?action=get-availability&start_date=2024-01-01&end_date=2024-01-31&service_id=1
 * - Obtener disponibilidad para un rango de fechas
 * 
 * GET /api/calendar/availability.php?action=get-time-slots&date=2024-01-15&service_id=1
 * - Obtener slots de tiempo para una fecha específica
 * 
 * GET /api/calendar/availability.php?action=get-schedule&start_date=2024-01-15
 * - Obtener horario semanal
 * 
 * GET /api/calendar/availability.php?action=check-availability&date=2024-01-15&time=14:30&service_id=1&duration=60
 * - Verificar disponibilidad específica
 * 
 * GET /api/calendar/availability.php?action=get-business-hours
 * - Obtener horarios de negocio
 * 
 * GET /api/calendar/availability.php?action=get-blocked-times&start_date=2024-01-01&end_date=2024-01-31
 * - Obtener tiempos bloqueados
 * 
 * GET /api/calendar/availability.php?action=get-holidays&year=2024
 * - Obtener feriados del año
 * 
 * === CONFIGURACIONES (POST) ===
 * 
 * POST /api/calendar/availability.php
 * Content-Type: application/json
 * {
 *   "action": "set-business-hours",
 *   "schedule": [
 *     {"is_open": false}, // Domingo
 *     {"is_open": true, "open_time": "09:00", "close_time": "18:00", "break_start": "13:00", "break_end": "14:00", "slot_duration": 30}, // Lunes
 *     ...
 *   ]
 * }
 * 
 * POST /api/calendar/availability.php
 * {
 *   "action": "add-blocked-time",
 *   "date": "2024-01-15",
 *   "block_type": "full_day",
 *   "reason": "Vacaciones"
 * }
 * 
 * POST /api/calendar/availability.php
 * {
 *   "action": "add-blocked-time",
 *   "date": "2024-01-15",
 *   "block_type": "time_range",
 *   "start_time": "14:00",
 *   "end_time": "16:00",
 *   "reason": "Reunión"
 * }
 * 
 * POST /api/calendar/availability.php
 * {
 *   "action": "add-holiday",
 *   "name": "Navidad",
 *   "date": "2024-12-25",
 *   "is_recurring": true
 * }
 * 
 * POST /api/calendar/availability.php
 * {
 *   "action": "set-special-hours",
 *   "date": "2024-01-15",
 *   "is_open": true,
 *   "open_time": "10:00",
 *   "close_time": "15:00",
 *   "reason": "Horario reducido"
 * }
 * 
 * POST /api/calendar/availability.php
 * {
 *   "action": "bulk-update-schedule",
 *   "operation": "update",
 *   "updates": [
 *     {"type": "special_hours", "date": "2024-01-15", "is_open": false, "reason": "Día libre"},
 *     {"type": "blocked_time", "date": "2024-01-16", "block_type": "full_day", "reason": "Mantenimiento"}
 *   ]
 * }
 * 
 * === ACTUALIZACIONES (PUT) ===
 * 
 * PUT /api/calendar/availability.php
 * {
 *   "action": "update-holiday",
 *   "id": 1,
 *   "name": "Año Nuevo",
 *   "date": "2024-01-01",
 *   "is_active": true
 * }
 * 
 * === ELIMINACIONES (DELETE) ===
 * 
 * DELETE /api/calendar/availability.php?action=delete-holiday&id=1
 * DELETE /api/calendar/availability.php?action=delete-blocked-time&date=2024-01-15
 * DELETE /api/calendar/availability.php?action=delete-special-hours&date=2024-01-15
 * DELETE /api/calendar/availability.php?action=clear-schedule&date=2024-01-15
 * 
 * AUTENTICACIÓN:
 * Header: Authorization: Bearer {JWT_TOKEN}
 * 
 * PARÁMETROS COMUNES:
 * - date: YYYY-MM-DD
 * - start_date, end_date: YYYY-MM-DD
 * - time: HH:MM or HH:MM:SS
 * - service_id: número
 * - duration: minutos
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
 */