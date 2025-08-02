<?php
/**
 * ====================================
 * SISTEMA DE RESERVAS DE SERVICIOS
 * API DE VISTA DE CALENDARIO
 * Archivo: api/calendar/calendar-view.php
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
require_once __DIR__ . '/availability.php';

/**
 * Clase CalendarViewAPI
 * Maneja la vista visual del calendario, eventos, y representación gráfica
 */
class CalendarViewAPI
{
    private $db;
    private $user_id;
    private $user_uuid;
    private $timezone;
    private $logger;
    private $availability_api;
    private $cache_duration = 300; // 5 minutos
    
    public function __construct()
    {
        $this->db = DatabaseConfig::getConnection();
        $this->logger = new Logger('calendar-view');
        $this->timezone = date_default_timezone_get();
        $this->availability_api = new AvailabilityAPI();
        
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
            case 'monthly-view':
                $data = $this->getMonthlyView($params);
                break;
            case 'weekly-view':
                $data = $this->getWeeklyView($params);
                break;
            case 'daily-view':
                $data = $this->getDailyView($params);
                break;
            case 'calendar-events':
                $data = $this->getCalendarEvents($params);
                break;
            case 'booking-details':
                $data = $this->getBookingDetails($params);
                break;
            case 'available-slots':
                $data = $this->getAvailableSlots($params);
                break;
            case 'calendar-config':
                $data = $this->getCalendarConfig();
                break;
            case 'export-calendar':
                $this->exportCalendar($params);
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
            'date', 'month', 'year', 'week_start', 'view_type',
            'service_id', 'status', 'customer_id', 'booking_id',
            'include_availability', 'format', 'color_scheme'
        ];
        
        foreach ($allowed_params as $param) {
            if (isset($_GET[$param])) {
                $params[$param] = sanitize($_GET[$param]);
            }
        }
        
        return $params;
    }
    
    /**
     * Obtener vista mensual del calendario
     */
    private function getMonthlyView($params)
    {
        $month = (int)($params['month'] ?? date('n'));
        $year = (int)($params['year'] ?? date('Y'));
        $include_availability = (bool)($params['include_availability'] ?? true);
        
        // Validar parámetros
        if ($month < 1 || $month > 12) {
            throw new Exception('Mes no válido', 400);
        }
        
        if ($year < 2020 || $year > 2030) {
            throw new Exception('Año no válido', 400);
        }
        
        // Calcular fechas del mes
        $first_day = new DateTime("$year-$month-01");
        $last_day = clone $first_day;
        $last_day->modify('last day of this month');
        
        // Calcular semana completa (incluir días de semanas anteriores/siguientes)
        $calendar_start = clone $first_day;
        $calendar_start->modify('monday this week');
        
        $calendar_end = clone $last_day;
        $calendar_end->modify('sunday this week');
        
        // Obtener eventos del período
        $events = $this->getCalendarEvents([
            'start_date' => $calendar_start->format('Y-m-d'),
            'end_date' => $calendar_end->format('Y-m-d')
        ]);
        
        // Obtener disponibilidad si se requiere
        $availability = null;
        if ($include_availability) {
            $availability = $this->getAvailabilityData(
                $calendar_start->format('Y-m-d'),
                $calendar_end->format('Y-m-d')
            );
        }
        
        // Generar estructura del calendario
        $calendar_days = $this->generateCalendarDays($calendar_start, $calendar_end, $events, $availability);
        
        return [
            'view_type' => 'monthly',
            'month' => $month,
            'year' => $year,
            'month_name' => $first_day->format('F'),
            'calendar_start' => $calendar_start->format('Y-m-d'),
            'calendar_end' => $calendar_end->format('Y-m-d'),
            'current_month_start' => $first_day->format('Y-m-d'),
            'current_month_end' => $last_day->format('Y-m-d'),
            'days' => $calendar_days,
            'events_summary' => $this->getEventsSummary($events),
            'navigation' => $this->getMonthNavigation($month, $year),
            'timezone' => $this->timezone
        ];
    }
    
    /**
     * Obtener vista semanal del calendario
     */
    private function getWeeklyView($params)
    {
        $date = $params['date'] ?? date('Y-m-d');
        $week_start = $params['week_start'] ?? 'monday'; // monday or sunday
        
        if (!$this->isValidDate($date)) {
            throw new Exception('Fecha no válida', 400);
        }
        
        // Calcular inicio y fin de la semana
        $reference_date = new DateTime($date);
        
        if ($week_start === 'sunday') {
            $week_start_date = clone $reference_date;
            $week_start_date->modify('sunday this week');
            if ($week_start_date > $reference_date) {
                $week_start_date->modify('-7 days');
            }
        } else {
            $week_start_date = clone $reference_date;
            $week_start_date->modify('monday this week');
        }
        
        $week_end_date = clone $week_start_date;
        $week_end_date->modify('+6 days');
        
        // Obtener eventos de la semana
        $events = $this->getCalendarEvents([
            'start_date' => $week_start_date->format('Y-m-d'),
            'end_date' => $week_end_date->format('Y-m-d')
        ]);
        
        // Obtener disponibilidad
        $availability = $this->getAvailabilityData(
            $week_start_date->format('Y-m-d'),
            $week_end_date->format('Y-m-d')
        );
        
        // Generar días de la semana
        $week_days = [];
        $current_day = clone $week_start_date;
        
        for ($i = 0; $i < 7; $i++) {
            $date_str = $current_day->format('Y-m-d');
            $day_events = array_filter($events, function($event) use ($date_str) {
                return $event['date'] === $date_str;
            });
            
            $week_days[] = [
                'date' => $date_str,
                'day_name' => $current_day->format('l'),
                'day_short' => $current_day->format('D'),
                'day_number' => $current_day->format('j'),
                'is_today' => $date_str === date('Y-m-d'),
                'is_weekend' => in_array($current_day->format('w'), [0, 6]),
                'events' => array_values($day_events),
                'availability' => $availability[$date_str] ?? null,
                'hours_grid' => $this->generateHoursGrid($date_str, $day_events, $availability[$date_str] ?? null)
            ];
            
            $current_day->modify('+1 day');
        }
        
        return [
            'view_type' => 'weekly',
            'week_start' => $week_start_date->format('Y-m-d'),
            'week_end' => $week_end_date->format('Y-m-d'),
            'week_number' => $week_start_date->format('W'),
            'month_year' => $reference_date->format('F Y'),
            'days' => $week_days,
            'events_summary' => $this->getEventsSummary($events),
            'navigation' => $this->getWeekNavigation($week_start_date),
            'timezone' => $this->timezone
        ];
    }
    
    /**
     * Obtener vista diaria del calendario
     */
    private function getDailyView($params)
    {
        $date = $params['date'] ?? date('Y-m-d');
        
        if (!$this->isValidDate($date)) {
            throw new Exception('Fecha no válida', 400);
        }
        
        $selected_date = new DateTime($date);
        
        // Obtener eventos del día
        $events = $this->getCalendarEvents([
            'start_date' => $date,
            'end_date' => $date
        ]);
        
        // Obtener disponibilidad del día
        $availability = $this->getAvailabilityData($date, $date);
        $day_availability = $availability[$date] ?? null;
        
        // Generar grilla horaria detallada
        $hours_grid = $this->generateDetailedHoursGrid($date, $events, $day_availability);
        
        // Estadísticas del día
        $day_stats = $this->getDayStatistics($date, $events);
        
        return [
            'view_type' => 'daily',
            'date' => $date,
            'day_name' => $selected_date->format('l'),
            'formatted_date' => $selected_date->format('F j, Y'),
            'is_today' => $date === date('Y-m-d'),
            'events' => $events,
            'availability' => $day_availability,
            'hours_grid' => $hours_grid,
            'statistics' => $day_stats,
            'navigation' => $this->getDayNavigation($selected_date),
            'timezone' => $this->timezone
        ];
    }
    
    /**
     * Obtener eventos del calendario
     */
    private function getCalendarEvents($params)
    {
        $start_date = $params['start_date'] ?? date('Y-m-d');
        $end_date = $params['end_date'] ?? date('Y-m-d');
        $service_id = $params['service_id'] ?? null;
        $status = $params['status'] ?? null;
        $customer_id = $params['customer_id'] ?? null;
        
        $sql = "
            SELECT 
                b.id,
                b.uuid,
                b.booking_date as date,
                b.booking_time as time,
                b.status,
                b.total_price,
                b.payment_status,
                b.customer_name,
                b.customer_email,
                b.customer_phone,
                b.notes,
                b.created_at,
                s.id as service_id,
                s.name as service_name,
                s.duration_minutes,
                s.color as service_color,
                CASE 
                    WHEN b.status = 'confirmed' THEN '#28a745'
                    WHEN b.status = 'pending' THEN '#ffc107'
                    WHEN b.status = 'completed' THEN '#17a2b8'
                    WHEN b.status = 'cancelled' THEN '#dc3545'
                    WHEN b.status = 'no_show' THEN '#6c757d'
                    ELSE '#007bff'
                END as status_color,
                TIME_FORMAT(b.booking_time, '%H:%i') as formatted_time,
                DATE_ADD(CONCAT(b.booking_date, ' ', b.booking_time), INTERVAL s.duration_minutes MINUTE) as end_datetime
            FROM bookings b
            INNER JOIN services s ON b.service_id = s.id
            WHERE b.user_id = ? 
            AND b.booking_date BETWEEN ? AND ?
        ";
        
        $params_array = [$this->user_id, $start_date, $end_date];
        
        // Filtros adicionales
        if ($service_id) {
            $sql .= " AND b.service_id = ?";
            $params_array[] = $service_id;
        }
        
        if ($status) {
            $sql .= " AND b.status = ?";
            $params_array[] = $status;
        }
        
        if ($customer_id) {
            $sql .= " AND b.customer_email = ?";
            $params_array[] = $customer_id;
        }
        
        $sql .= " ORDER BY b.booking_date, b.booking_time";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params_array);
        $events = $stmt->fetchAll();
        
        // Procesar eventos para el calendario
        foreach ($events as &$event) {
            $event['id'] = (int)$event['id'];
            $event['service_id'] = (int)$event['service_id'];
            $event['duration_minutes'] = (int)$event['duration_minutes'];
            $event['total_price'] = (float)$event['total_price'];
            
            // Calcular hora de fin
            $start_time = new DateTime($event['date'] . ' ' . $event['time']);
            $end_time = clone $start_time;
            $end_time->add(new DateInterval('PT' . $event['duration_minutes'] . 'M'));
            $event['end_time'] = $end_time->format('H:i:s');
            $event['end_time_formatted'] = $end_time->format('H:i');
            
            // Información adicional para el calendario
            $event['title'] = $event['service_name'] . ' - ' . $event['customer_name'];
            $event['description'] = $event['notes'] ?: 'Sin notas adicionales';
            $event['duration_text'] = $this->formatDuration($event['duration_minutes']);
            
            // Determinar color (priorizar color del servicio si existe)
            if ($event['service_color']) {
                $event['color'] = $event['service_color'];
            } else {
                $event['color'] = $event['status_color'];
            }
            
            // Información de conflictos
            $event['has_conflicts'] = false; // Se calculará después si es necesario
        }
        
        return $events;
    }
    
    /**
     * Obtener datos de disponibilidad
     */
    private function getAvailabilityData($start_date, $end_date)
    {
        // Usar la API de availability para obtener los datos
        $availability_data = $this->availability_api->getAvailability([
            'start_date' => $start_date,
            'end_date' => $end_date
        ]);
        
        // Reorganizar por fecha para fácil acceso
        $availability_by_date = [];
        foreach ($availability_data['availability'] as $day) {
            $availability_by_date[$day['date']] = $day;
        }
        
        return $availability_by_date;
    }

    // <--- CONTINUAR AQUI CON PARTE 2 --->

    <?php
/**
 * ====================================
 * SISTEMA DE RESERVAS DE SERVICIOS
 * API DE VISTA DE CALENDARIO
 * Archivo: api/calendar/calendar-view.php
 * Parte 2 de 3 (líneas 501-1000)
 * ====================================
 */

    /**
     * Generar días del calendario
     */
    private function generateCalendarDays($calendar_start, $calendar_end, $events, $availability)
    {
        $days = [];
        $current_day = clone $calendar_start;
        
        while ($current_day <= $calendar_end) {
            $date_str = $current_day->format('Y-m-d');
            $day_events = array_filter($events, function($event) use ($date_str) {
                return $event['date'] === $date_str;
            });
            
            $day_availability = $availability[$date_str] ?? null;
            
            $days[] = [
                'date' => $date_str,
                'day_number' => $current_day->format('j'),
                'day_name' => $current_day->format('D'),
                'is_today' => $date_str === date('Y-m-d'),
                'is_weekend' => in_array($current_day->format('w'), [0, 6]),
                'is_current_month' => $current_day->format('n') == $calendar_start->format('n'),
                'events' => array_values($day_events),
                'events_count' => count($day_events),
                'availability' => $day_availability,
                'has_availability' => $day_availability ? $day_availability['is_available'] : false,
                'availability_slots' => $day_availability ? count($day_availability['time_slots'] ?? []) : 0,
                'day_summary' => $this->getDaySummary($day_events, $day_availability)
            ];
            
            $current_day->modify('+1 day');
        }
        
        return $days;
    }
    
    /**
     * Generar grilla horaria para vista semanal
     */
    private function generateHoursGrid($date, $events, $availability)
    {
        $hours_grid = [];
        $business_hours = $this->getBusinessHoursForDate($date);
        
        if (!$business_hours || !$business_hours['is_open']) {
            return []; // Día cerrado
        }
        
        // Generar slots de 30 minutos desde apertura hasta cierre
        $start_time = new DateTime($date . ' ' . $business_hours['open_time']);
        $end_time = new DateTime($date . ' ' . $business_hours['close_time']);
        $slot_duration = 30; // minutos
        
        $current_time = clone $start_time;
        
        while ($current_time < $end_time) {
            $time_str = $current_time->format('H:i:s');
            $slot_end = clone $current_time;
            $slot_end->add(new DateInterval('PT' . $slot_duration . 'M'));
            
            // Verificar si hay evento en este slot
            $slot_event = $this->findEventInTimeSlot($events, $current_time, $slot_end);
            
            // Verificar disponibilidad
            $is_available = $this->isSlotAvailable($availability, $time_str);
            
            $hours_grid[] = [
                'time' => $time_str,
                'time_formatted' => $current_time->format('H:i'),
                'end_time' => $slot_end->format('H:i:s'),
                'end_time_formatted' => $slot_end->format('H:i'),
                'has_event' => $slot_event !== null,
                'event' => $slot_event,
                'is_available' => $is_available,
                'is_break_time' => $this->isBreakTime($business_hours, $current_time),
                'slot_type' => $this->getSlotType($slot_event, $is_available, $business_hours, $current_time)
            ];
            
            $current_time->add(new DateInterval('PT' . $slot_duration . 'M'));
        }
        
        return $hours_grid;
    }
    
    /**
     * Generar grilla horaria detallada para vista diaria
     */
    private function generateDetailedHoursGrid($date, $events, $availability)
    {
        $hours_grid = [];
        $business_hours = $this->getBusinessHoursForDate($date);
        
        if (!$business_hours || !$business_hours['is_open']) {
            return []; // Día cerrado
        }
        
        // Generar slots de 15 minutos para vista detallada
        $start_time = new DateTime($date . ' ' . $business_hours['open_time']);
        $end_time = new DateTime($date . ' ' . $business_hours['close_time']);
        $slot_duration = 15; // minutos
        
        $current_time = clone $start_time;
        
        while ($current_time < $end_time) {
            $time_str = $current_time->format('H:i:s');
            $slot_end = clone $current_time;
            $slot_end->add(new DateInterval('PT' . $slot_duration . 'M'));
            
            // Verificar si hay evento en este slot
            $slot_event = $this->findEventInTimeSlot($events, $current_time, $slot_end);
            
            // Verificar disponibilidad
            $is_available = $this->isSlotAvailable($availability, $time_str);
            
            // Verificar si es inicio de hora (para mostrar etiqueta)
            $is_hour_start = $current_time->format('i') === '00';
            
            $hours_grid[] = [
                'time' => $time_str,
                'time_formatted' => $current_time->format('H:i'),
                'end_time' => $slot_end->format('H:i:s'),
                'end_time_formatted' => $slot_end->format('H:i'),
                'has_event' => $slot_event !== null,
                'event' => $slot_event,
                'is_available' => $is_available,
                'is_break_time' => $this->isBreakTime($business_hours, $current_time),
                'is_hour_start' => $is_hour_start,
                'hour_label' => $is_hour_start ? $current_time->format('g A') : null,
                'slot_type' => $this->getSlotType($slot_event, $is_available, $business_hours, $current_time),
                'can_book' => $is_available && !$slot_event && $this->canBookAtTime($current_time)
            ];
            
            $current_time->add(new DateInterval('PT' . $slot_duration . 'M'));
        }
        
        return $hours_grid;
    }
    
    /**
     * Obtener horarios de negocio para una fecha específica
     */
    private function getBusinessHoursForDate($date)
    {
        $date_obj = new DateTime($date);
        $day_of_week = (int)$date_obj->format('w');
        
        // Verificar si hay horarios especiales para esta fecha
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
        
        // Usar horarios regulares de negocio
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
     * Encontrar evento en slot de tiempo
     */
    private function findEventInTimeSlot($events, $slot_start, $slot_end)
    {
        foreach ($events as $event) {
            $event_start = new DateTime($event['date'] . ' ' . $event['time']);
            $event_end = clone $event_start;
            $event_end->add(new DateInterval('PT' . $event['duration_minutes'] . 'M'));
            
            // Verificar si hay solapamiento
            if ($slot_start < $event_end && $slot_end > $event_start) {
                return $event;
            }
        }
        
        return null;
    }
    
    /**
     * Verificar si slot está disponible
     */
    private function isSlotAvailable($availability, $time_str)
    {
        if (!$availability || !$availability['is_available']) {
            return false;
        }
        
        if (!isset($availability['time_slots'])) {
            return false;
        }
        
        foreach ($availability['time_slots'] as $slot) {
            if ($slot['time'] === $time_str) {
                return $slot['is_available'];
            }
        }
        
        return false;
    }
    
    /**
     * Verificar si es horario de descanso
     */
    private function isBreakTime($business_hours, $current_time)
    {
        if (!$business_hours['break_start'] || !$business_hours['break_end']) {
            return false;
        }
        
        $break_start = new DateTime($current_time->format('Y-m-d') . ' ' . $business_hours['break_start']);
        $break_end = new DateTime($current_time->format('Y-m-d') . ' ' . $business_hours['break_end']);
        
        return $current_time >= $break_start && $current_time < $break_end;
    }
    
    /**
     * Obtener tipo de slot
     */
    private function getSlotType($event, $is_available, $business_hours, $current_time)
    {
        if ($event) {
            return 'booked';
        }
        
        if ($this->isBreakTime($business_hours, $current_time)) {
            return 'break';
        }
        
        if ($is_available) {
            return 'available';
        }
        
        return 'unavailable';
    }
    
    /**
     * Verificar si se puede reservar en este horario
     */
    private function canBookAtTime($time)
    {
        $now = new DateTime();
        
        // No se puede reservar en el pasado
        if ($time <= $now) {
            return false;
        }
        
        // Verificar tiempo mínimo de anticipación (ejemplo: 1 hora)
        $min_advance = clone $now;
        $min_advance->add(new DateInterval('PT1H'));
        
        return $time >= $min_advance;
    }
    
    /**
     * Obtener resumen del día
     */
    private function getDaySummary($events, $availability)
    {
        $total_events = count($events);
        $confirmed_events = 0;
        $pending_events = 0;
        $total_revenue = 0;
        
        foreach ($events as $event) {
            if ($event['status'] === 'confirmed' || $event['status'] === 'completed') {
                $confirmed_events++;
                $total_revenue += $event['total_price'];
            } elseif ($event['status'] === 'pending') {
                $pending_events++;
            }
        }
        
        $available_slots = 0;
        if ($availability && isset($availability['time_slots'])) {
            foreach ($availability['time_slots'] as $slot) {
                if ($slot['is_available']) {
                    $available_slots++;
                }
            }
        }
        
        return [
            'total_events' => $total_events,
            'confirmed_events' => $confirmed_events,
            'pending_events' => $pending_events,
            'available_slots' => $available_slots,
            'total_revenue' => $total_revenue,
            'is_busy' => $total_events >= 8, // Día ocupado si tiene 8+ eventos
            'utilization_rate' => $available_slots > 0 ? round(($total_events / ($total_events + $available_slots)) * 100, 1) : 0
        ];
    }
    
    /**
     * Obtener estadísticas del día
     */
    private function getDayStatistics($date, $events)
    {
        $stats = [
            'total_bookings' => count($events),
            'confirmed_bookings' => 0,
            'pending_bookings' => 0,
            'completed_bookings' => 0,
            'cancelled_bookings' => 0,
            'total_revenue' => 0,
            'confirmed_revenue' => 0,
            'average_booking_value' => 0,
            'busiest_hour' => null,
            'services_breakdown' => [],
            'hourly_distribution' => []
        ];
        
        $hourly_count = [];
        $services_count = [];
        $services_revenue = [];
        
        foreach ($events as $event) {
            // Contadores por estado
            switch ($event['status']) {
                case 'confirmed':
                    $stats['confirmed_bookings']++;
                    $stats['confirmed_revenue'] += $event['total_price'];
                    break;
                case 'pending':
                    $stats['pending_bookings']++;
                    break;
                case 'completed':
                    $stats['completed_bookings']++;
                    $stats['confirmed_revenue'] += $event['total_price'];
                    break;
                case 'cancelled':
                    $stats['cancelled_bookings']++;
                    break;
            }
            
            // Revenue total
            if (in_array($event['status'], ['confirmed', 'completed'])) {
                $stats['total_revenue'] += $event['total_price'];
            }
            
            // Distribución por hora
            $hour = (new DateTime($event['time']))->format('H');
            $hourly_count[$hour] = ($hourly_count[$hour] ?? 0) + 1;
            
            // Servicios
            $service_name = $event['service_name'];
            $services_count[$service_name] = ($services_count[$service_name] ?? 0) + 1;
            $services_revenue[$service_name] = ($services_revenue[$service_name] ?? 0) + $event['total_price'];
        }
        
        // Calcular promedios
        if ($stats['total_bookings'] > 0) {
            $stats['average_booking_value'] = round($stats['total_revenue'] / $stats['total_bookings'], 2);
        }
        
        // Hora más ocupada
        if (!empty($hourly_count)) {
            $stats['busiest_hour'] = array_search(max($hourly_count), $hourly_count) . ':00';
        }
        
        // Breakdown de servicios
        foreach ($services_count as $service => $count) {
            $stats['services_breakdown'][] = [
                'service_name' => $service,
                'bookings_count' => $count,
                'revenue' => $services_revenue[$service] ?? 0
            ];
        }
        
        // Distribución horaria
        for ($h = 0; $h < 24; $h++) {
            $hour_str = sprintf('%02d', $h);
            $stats['hourly_distribution'][] = [
                'hour' => $hour_str . ':00',
                'bookings' => $hourly_count[$hour_str] ?? 0
            ];
        }
        
        return $stats;
    }
    
    /**
     * Obtener resumen de eventos
     */
    private function getEventsSummary($events)
    {
        $summary = [
            'total_events' => count($events),
            'by_status' => [
                'confirmed' => 0,
                'pending' => 0,
                'completed' => 0,
                'cancelled' => 0,
                'no_show' => 0
            ],
            'total_revenue' => 0,
            'unique_customers' => 0,
            'unique_services' => 0
        ];
        
        $customers = [];
        $services = [];
        
        foreach ($events as $event) {
            // Contar por estado
            if (isset($summary['by_status'][$event['status']])) {
                $summary['by_status'][$event['status']]++;
            }
            
            // Revenue
            if (in_array($event['status'], ['confirmed', 'completed'])) {
                $summary['total_revenue'] += $event['total_price'];
            }
            
            // Clientes únicos
            $customers[$event['customer_email']] = true;
            
            // Servicios únicos
            $services[$event['service_id']] = true;
        }
        
        $summary['unique_customers'] = count($customers);
        $summary['unique_services'] = count($services);
        
        return $summary;
    }
    
    /**
     * Obtener navegación para vista mensual
     */
    private function getMonthNavigation($month, $year)
    {
        $current_date = new DateTime("$year-$month-01");
        
        $prev_month = clone $current_date;
        $prev_month->modify('-1 month');
        
        $next_month = clone $current_date;
        $next_month->modify('+1 month');
        
        return [
            'previous' => [
                'month' => $prev_month->format('n'),
                'year' => $prev_month->format('Y'),
                'label' => $prev_month->format('F Y'),
                'url_params' => 'month=' . $prev_month->format('n') . '&year=' . $prev_month->format('Y')
            ],
            'current' => [
                'month' => $month,
                'year' => $year,
                'label' => $current_date->format('F Y')
            ],
            'next' => [
                'month' => $next_month->format('n'),
                'year' => $next_month->format('Y'),
                'label' => $next_month->format('F Y'),
                'url_params' => 'month=' . $next_month->format('n') . '&year=' . $next_month->format('Y')
            ]
        ];
    }
    
    /**
     * Obtener navegación para vista semanal
     */
    private function getWeekNavigation($week_start_date)
    {
        $prev_week = clone $week_start_date;
        $prev_week->modify('-7 days');
        
        $next_week = clone $week_start_date;
        $next_week->modify('+7 days');
        
        return [
            'previous' => [
                'date' => $prev_week->format('Y-m-d'),
                'label' => 'Week of ' . $prev_week->format('M j'),
                'url_params' => 'date=' . $prev_week->format('Y-m-d')
            ],
            'current' => [
                'start_date' => $week_start_date->format('Y-m-d'),
                'label' => 'Week of ' . $week_start_date->format('M j, Y')
            ],
            'next' => [
                'date' => $next_week->format('Y-m-d'),
                'label' => 'Week of ' . $next_week->format('M j'),
                'url_params' => 'date=' . $next_week->format('Y-m-d')
            ]
        ];
    }
    
    /**
     * Obtener navegación para vista diaria
     */
    private function getDayNavigation($selected_date)
    {
        $prev_day = clone $selected_date;
        $prev_day->modify('-1 day');
        
        $next_day = clone $selected_date;
        $next_day->modify('+1 day');
        
        return [
            'previous' => [
                'date' => $prev_day->format('Y-m-d'),
                'label' => $prev_day->format('M j'),
                'url_params' => 'date=' . $prev_day->format('Y-m-d')
            ],
            'current' => [
                'date' => $selected_date->format('Y-m-d'),
                'label' => $selected_date->format('F j, Y')
            ],
            'next' => [
                'date' => $next_day->format('Y-m-d'),
                'label' => $next_day->format('M j'),
                'url_params' => 'date=' . $next_day->format('Y-m-d')
            ]
        ];
    }
    
    /**
     * Formatear duración en texto legible
     */
    private function formatDuration($minutes)
    {
        if ($minutes < 60) {
            return $minutes . ' min';
        }
        
        $hours = floor($minutes / 60);
        $remaining_minutes = $minutes % 60;
        
        if ($remaining_minutes === 0) {
            return $hours . 'h';
        }
        
        return $hours . 'h ' . $remaining_minutes . 'min';
    }
    
    /**
     * Obtener detalles de una reserva específica
     */
    private function getBookingDetails($params)
    {
        $booking_id = $params['booking_id'] ?? null;
        
        if (!$booking_id) {
            throw new Exception('ID de reserva requerido', 400);
        }
        
        $stmt = $this->db->prepare("
            SELECT 
                b.*,
                s.name as service_name,
                s.description as service_description,
                s.duration_minutes,
                s.color as service_color,
                TIME_FORMAT(b.booking_time, '%H:%i') as formatted_time,
                DATE_ADD(CONCAT(b.booking_date, ' ', b.booking_time), INTERVAL s.duration_minutes MINUTE) as end_datetime
            FROM bookings b
            INNER JOIN services s ON b.service_id = s.id
            WHERE b.id = ? AND b.user_id = ?
        ");
        
        $stmt->execute([$booking_id, $this->user_id]);
        $booking = $stmt->fetch();
        
        if (!$booking) {
            throw new Exception('Reserva no encontrada', 404);
        }
        
        // Información adicional
        $start_datetime = new DateTime($booking['booking_date'] . ' ' . $booking['booking_time']);
        $end_datetime = clone $start_datetime;
        $end_datetime->add(new DateInterval('PT' . $booking['duration_minutes'] . 'M'));
        
        $booking['formatted_date'] = $start_datetime->format('F j, Y');
        $booking['formatted_time_range'] = $start_datetime->format('g:i A') . ' - ' . $end_datetime->format('g:i A');
        $booking['duration_text'] = $this->formatDuration($booking['duration_minutes']);
        $booking['end_time'] = $end_datetime->format('H:i:s');
        
        // Historial de cambios (si existe)
        $booking['history'] = $this->getBookingHistory($booking_id);
        
        return $booking;
    }
    
    /**
     * Obtener historial de cambios de una reserva
     */
    private function getBookingHistory($booking_id)
    {
        $stmt = $this->db->prepare("
            SELECT 
                action,
                old_value,
                new_value,
                changed_by,
                created_at,
                notes
            FROM booking_history 
            WHERE booking_id = ?
            ORDER BY created_at DESC
            LIMIT 10
        ");
        
        $stmt->execute([$booking_id]);
        return $stmt->fetchAll();
    }

    // <--- CONTINUAR AQUI CON PARTE 3 --->s

    <?php
/**
 * ====================================
 * SISTEMA DE RESERVAS DE SERVICIOS
 * API DE VISTA DE CALENDARIO
 * Archivo: api/calendar/calendar-view.php
 * Parte 3 de 3 (líneas 1001-1500) - FINAL
 * ====================================
 */

    /**
     * Obtener slots disponibles para reserva
     */
    private function getAvailableSlots($params)
    {
        $date = $params['date'] ?? date('Y-m-d');
        $service_id = $params['service_id'] ?? null;
        $duration = $params['duration'] ?? null;
        
        if (!$this->isValidDate($date)) {
            throw new Exception('Fecha no válida', 400);
        }
        
        // Obtener disponibilidad del día
        $availability_data = $this->getAvailabilityData($date, $date);
        $day_availability = $availability_data[$date] ?? null;
        
        if (!$day_availability || !$day_availability['is_available']) {
            return [
                'date' => $date,
                'service_id' => $service_id,
                'available_slots' => [],
                'message' => 'No hay disponibilidad para esta fecha'
            ];
        }
        
        // Filtrar slots disponibles
        $available_slots = [];
        foreach ($day_availability['time_slots'] as $slot) {
            if ($slot['is_available']) {
                $slot_time = new DateTime($date . ' ' . $slot['time']);
                
                // Verificar si se puede reservar (no en el pasado + tiempo mínimo)
                if ($this->canBookAtTime($slot_time)) {
                    $available_slots[] = [
                        'time' => $slot['time'],
                        'time_formatted' => $slot['time_formatted'],
                        'end_time' => $slot['end_time'],
                        'end_time_formatted' => $slot['end_time_formatted'],
                        'duration' => $slot['duration'],
                        'can_book' => true,
                        'booking_url' => $this->generateBookingUrl($date, $slot['time'], $service_id)
                    ];
                }
            }
        }
        
        return [
            'date' => $date,
            'service_id' => $service_id,
            'total_slots' => count($day_availability['time_slots']),
            'available_slots' => $available_slots,
            'available_count' => count($available_slots),
            'business_hours' => $day_availability['business_hours'],
            'timezone' => $this->timezone
        ];
    }
    
    /**
     * Generar URL para reserva
     */
    private function generateBookingUrl($date, $time, $service_id = null)
    {
        $base_url = '/pages/booking.html';
        $params = [
            'date' => $date,
            'time' => $time
        ];
        
        if ($service_id) {
            $params['service_id'] = $service_id;
        }
        
        return $base_url . '?' . http_build_query($params);
    }
    
    /**
     * Obtener configuración del calendario
     */
    private function getCalendarConfig()
    {
        // Configuración del usuario
        $stmt = $this->db->prepare("
            SELECT 
                timezone,
                business_name,
                calendar_color_scheme,
                default_view,
                week_start_day,
                booking_advance_time,
                slot_duration
            FROM users 
            WHERE id = ?
        ");
        
        $stmt->execute([$this->user_id]);
        $user_config = $stmt->fetch();
        
        // Servicios con colores
        $stmt = $this->db->prepare("
            SELECT id, name, color, duration_minutes
            FROM services 
            WHERE user_id = ? AND is_active = 1
            ORDER BY name
        ");
        
        $stmt->execute([$this->user_id]);
        $services = $stmt->fetchAll();
        
        // Estados de reserva con colores
        $booking_statuses = [
            'pending' => ['label' => 'Pendiente', 'color' => '#ffc107'],
            'confirmed' => ['label' => 'Confirmada', 'color' => '#28a745'],
            'completed' => ['label' => 'Completada', 'color' => '#17a2b8'],
            'cancelled' => ['label' => 'Cancelada', 'color' => '#dc3545'],
            'no_show' => ['label' => 'No Asistió', 'color' => '#6c757d']
        ];
        
        return [
            'user_config' => [
                'timezone' => $user_config['timezone'] ?? 'UTC',
                'business_name' => $user_config['business_name'],
                'color_scheme' => $user_config['calendar_color_scheme'] ?? 'default',
                'default_view' => $user_config['default_view'] ?? 'weekly',
                'week_start_day' => $user_config['week_start_day'] ?? 'monday',
                'booking_advance_time' => (int)($user_config['booking_advance_time'] ?? 60),
                'slot_duration' => (int)($user_config['slot_duration'] ?? 30)
            ],
            'services' => $services,
            'booking_statuses' => $booking_statuses,
            'view_options' => [
                'monthly' => 'Vista Mensual',
                'weekly' => 'Vista Semanal',
                'daily' => 'Vista Diaria'
            ],
            'color_schemes' => [
                'default' => 'Predeterminado',
                'blue' => 'Azul',
                'green' => 'Verde',
                'purple' => 'Morado',
                'dark' => 'Oscuro'
            ]
        ];
    }
    
    /**
     * Exportar calendario
     */
    private function exportCalendar($params)
    {
        $format = $params['format'] ?? 'ical';
        $start_date = $params['start_date'] ?? date('Y-m-d');
        $end_date = $params['end_date'] ?? date('Y-m-d', strtotime($start_date . ' +30 days'));
        
        // Obtener eventos para exportar
        $events = $this->getCalendarEvents([
            'start_date' => $start_date,
            'end_date' => $end_date
        ]);
        
        switch ($format) {
            case 'ical':
                $this->exportToICal($events);
                break;
            case 'csv':
                $this->exportToCSV($events);
                break;
            case 'json':
                $this->exportToJSON($events);
                break;
            default:
                throw new Exception('Formato de exportación no válido', 400);
        }
    }
    
    /**
     * Exportar a formato iCal
     */
    private function exportToICal($events)
    {
        $calendar = "BEGIN:VCALENDAR\r\n";
        $calendar .= "VERSION:2.0\r\n";
        $calendar .= "PRODID:-//ReservaFacil//Calendar//ES\r\n";
        $calendar .= "CALSCALE:GREGORIAN\r\n";
        $calendar .= "METHOD:PUBLISH\r\n";
        $calendar .= "X-WR-CALNAME:" . $this->user_data['business_name'] . "\r\n";
        $calendar .= "X-WR-TIMEZONE:" . $this->timezone . "\r\n";
        
        foreach ($events as $event) {
            $start_datetime = new DateTime($event['date'] . ' ' . $event['time']);
            $end_datetime = clone $start_datetime;
            $end_datetime->add(new DateInterval('PT' . $event['duration_minutes'] . 'M'));
            
            $calendar .= "BEGIN:VEVENT\r\n";
            $calendar .= "UID:" . $event['uuid'] . "@reservafacil.com\r\n";
            $calendar .= "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
            $calendar .= "DTSTART:" . $start_datetime->format('Ymd\THis') . "\r\n";
            $calendar .= "DTEND:" . $end_datetime->format('Ymd\THis') . "\r\n";
            $calendar .= "SUMMARY:" . $this->escapeICalText($event['title']) . "\r\n";
            $calendar .= "DESCRIPTION:" . $this->escapeICalText($event['description']) . "\r\n";
            
            // Información adicional
            $calendar .= "ORGANIZER:CN=" . $this->user_data['business_name'] . "\r\n";
            $calendar .= "ATTENDEE:CN=" . $event['customer_name'] . ":MAILTO:" . $event['customer_email'] . "\r\n";
            $calendar .= "STATUS:" . strtoupper($event['status']) . "\r\n";
            $calendar .= "CLASS:PUBLIC\r\n";
            
            if ($event['customer_phone']) {
                $calendar .= "X-PHONE:" . $event['customer_phone'] . "\r\n";
            }
            
            $calendar .= "X-PRICE:" . $event['total_price'] . "\r\n";
            $calendar .= "X-SERVICE:" . $event['service_name'] . "\r\n";
            
            $calendar .= "END:VEVENT\r\n";
        }
        
        $calendar .= "END:VCALENDAR\r\n";
        
        // Enviar archivo
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="calendario.ics"');
        header('Content-Length: ' . strlen($calendar));
        echo $calendar;
        exit;
    }
    
    /**
     * Exportar a formato CSV
     */
    private function exportToCSV($events)
    {
        $csv_data = [];
        
        // Headers
        $csv_data[] = [
            'Fecha',
            'Hora Inicio',
            'Hora Fin',
            'Servicio',
            'Cliente',
            'Email',
            'Teléfono',
            'Estado',
            'Precio',
            'Duración',
            'Notas'
        ];
        
        // Datos
        foreach ($events as $event) {
            $start_datetime = new DateTime($event['date'] . ' ' . $event['time']);
            $end_datetime = clone $start_datetime;
            $end_datetime->add(new DateInterval('PT' . $event['duration_minutes'] . 'M'));
            
            $csv_data[] = [
                $start_datetime->format('Y-m-d'),
                $start_datetime->format('H:i'),
                $end_datetime->format('H:i'),
                $event['service_name'],
                $event['customer_name'],
                $event['customer_email'],
                $event['customer_phone'],
                $event['status'],
                $event['total_price'],
                $event['duration_minutes'] . ' min',
                $event['notes']
            ];
        }
        
        // Generar CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="calendario.csv"');
        
        $output = fopen('php://output', 'w');
        
        // BOM para UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        foreach ($csv_data as $row) {
            fputcsv($output, $row, ';');
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Exportar a formato JSON
     */
    private function exportToJSON($events)
    {
        $export_data = [
            'export_info' => [
                'generated_at' => date('Y-m-d H:i:s'),
                'timezone' => $this->timezone,
                'business_name' => $this->user_data['business_name'],
                'total_events' => count($events)
            ],
            'events' => $events
        ];
        
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="calendario.json"');
        echo json_encode($export_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * Escapar texto para iCal
     */
    private function escapeICalText($text)
    {
        $text = str_replace(['\\', ';', ',', "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $text);
        return $text;
    }
    
    /**
     * Manejar peticiones POST
     */
    private function handlePostRequest()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
        
        switch ($action) {
            case 'update-calendar-config':
                $data = $this->updateCalendarConfig($input);
                break;
            case 'bulk-update-events':
                $data = $this->bulkUpdateEvents($input);
                break;
            default:
                throw new Exception('Acción no válida', 400);
        }
        
        $this->sendSuccessResponse($data);
    }
    
    /**
     * Actualizar configuración del calendario
     */
    private function updateCalendarConfig($input)
    {
        $config = $input['config'] ?? [];
        
        $allowed_fields = [
            'calendar_color_scheme',
            'default_view',
            'week_start_day',
            'booking_advance_time',
            'slot_duration'
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
        
        $update_values[] = $this->user_id;
        
        $stmt = $this->db->prepare("
            UPDATE users 
            SET " . implode(', ', $update_fields) . "
            WHERE id = ?
        ");
        
        $stmt->execute($update_values);
        
        return [
            'success' => true,
            'message' => 'Configuración del calendario actualizada',
            'updated_config' => $this->getCalendarConfig()
        ];
    }
    
    /**
     * Actualización masiva de eventos
     */
    private function bulkUpdateEvents($input)
    {
        $updates = $input['updates'] ?? [];
        $operation = $input['operation'] ?? 'update_status';
        
        if (empty($updates)) {
            throw new Exception('Lista de actualizaciones requerida', 400);
        }
        
        $this->db->beginTransaction();
        
        try {
            $results = [];
            
            foreach ($updates as $update) {
                $booking_id = $update['booking_id'] ?? null;
                
                if (!$booking_id) {
                    continue;
                }
                
                switch ($operation) {
                    case 'update_status':
                        $new_status = $update['status'] ?? null;
                        if ($new_status) {
                            $this->updateBookingStatus($booking_id, $new_status);
                            $results[] = ['booking_id' => $booking_id, 'status' => $new_status];
                        }
                        break;
                        
                    case 'reschedule':
                        $new_date = $update['new_date'] ?? null;
                        $new_time = $update['new_time'] ?? null;
                        if ($new_date && $new_time) {
                            $this->rescheduleBooking($booking_id, $new_date, $new_time);
                            $results[] = ['booking_id' => $booking_id, 'new_date' => $new_date, 'new_time' => $new_time];
                        }
                        break;
                }
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Actualización masiva completada',
                'operation' => $operation,
                'results' => $results,
                'total_updated' => count($results)
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Actualizar estado de reserva
     */
    private function updateBookingStatus($booking_id, $new_status)
    {
        $stmt = $this->db->prepare("
            UPDATE bookings 
            SET status = ?, updated_at = NOW()
            WHERE id = ? AND user_id = ?
        ");
        
        $stmt->execute([$new_status, $booking_id, $this->user_id]);
        
        // Registrar cambio en historial
        $this->logBookingChange($booking_id, 'status_change', null, $new_status);
    }
    
    /**
     * Reprogramar reserva
     */
    private function rescheduleBooking($booking_id, $new_date, $new_time)
    {
        // Verificar disponibilidad del nuevo slot
        $availability_check = $this->availability_api->checkSpecificAvailability([
            'date' => $new_date,
            'time' => $new_time,
            'booking_id' => $booking_id // Para excluir de verificación de conflictos
        ]);
        
        if (!$availability_check['is_available']) {
            throw new Exception('El nuevo horario no está disponible', 400);
        }
        
        // Obtener valores anteriores
        $stmt = $this->db->prepare("SELECT booking_date, booking_time FROM bookings WHERE id = ? AND user_id = ?");
        $stmt->execute([$booking_id, $this->user_id]);
        $old_booking = $stmt->fetch();
        
        if (!$old_booking) {
            throw new Exception('Reserva no encontrada', 404);
        }
        
        // Actualizar reserva
        $stmt = $this->db->prepare("
            UPDATE bookings 
            SET booking_date = ?, booking_time = ?, updated_at = NOW()
            WHERE id = ? AND user_id = ?
        ");
        
        $stmt->execute([$new_date, $new_time, $booking_id, $this->user_id]);
        
        // Registrar cambio en historial
        $this->logBookingChange(
            $booking_id, 
            'reschedule', 
            $old_booking['booking_date'] . ' ' . $old_booking['booking_time'],
            $new_date . ' ' . $new_time
        );
    }
    
    /**
     * Registrar cambio en historial de reserva
     */
    private function logBookingChange($booking_id, $action, $old_value, $new_value)
    {
        $stmt = $this->db->prepare("
            INSERT INTO booking_history (booking_id, action, old_value, new_value, changed_by, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([$booking_id, $action, $old_value, $new_value, $this->user_id]);
    }
    
    /**
     * Manejar peticiones PUT y DELETE (delegar a availability API)
     */
    private function handlePutRequest()
    {
        // Delegar a availability API para actualizaciones de horarios
        $this->availability_api->processRequest();
    }
    
    private function handleDeleteRequest()
    {
        // Delegar a availability API para eliminaciones
        $this->availability_api->processRequest();
    }
    
    /**
     * Verificar token JWT
     */
    private function verifyJWT($token)
    {
        try {
            // Aquí implementarías la verificación JWT real
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
        $this->logger->error('Error en calendar view API', [
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
        
        error_log(json_encode($log_entry));
    }
}

// Ejecutar la API si se accede directamente
if (basename($_SERVER['PHP_SELF']) === 'calendar-view.php') {
    try {
        $api = new CalendarViewAPI();
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
 * === VISTAS DEL CALENDARIO (GET) ===
 * 
 * GET /api/calendar/calendar-view.php?action=monthly-view&month=1&year=2024&include_availability=true
 * - Vista mensual completa con eventos y disponibilidad
 * 
 * GET /api/calendar/calendar-view.php?action=weekly-view&date=2024-01-15&week_start=monday
 * - Vista semanal con grilla horaria
 * 
 * GET /api/calendar/calendar-view.php?action=daily-view&date=2024-01-15
 * - Vista diaria detallada con slots de 15 minutos
 * 
 * GET /api/calendar/calendar-view.php?action=calendar-events&start_date=2024-01-01&end_date=2024-01-31&service_id=1&status=confirmed
 * - Obtener eventos/reservas del calendario con filtros
 * 
 * GET /api/calendar/calendar-view.php?action=available-slots&date=2024-01-15&service_id=1
 * - Obtener slots disponibles para reserva
 * 
 * GET /api/calendar/calendar-view.php?action=booking-details&booking_id=123
 * - Obtener detalles completos de una reserva
 * 
 * GET /api/calendar/calendar-view.php?action=calendar-config
 * - Obtener configuración del calendario (colores, servicios, etc.)
 * 
 * === EXPORTACIÓN (GET) ===
 * 
 * GET /api/calendar/calendar-view.php?action=export-calendar&format=ical&start_date=2024-01-01&end_date=2024-01-31
 * - Exportar calendario en formato iCal
 * 
 * GET /api/calendar/calendar-view.php?action=export-calendar&format=csv&start_date=2024-01-01&end_date=2024-01-31
 * - Exportar calendario en formato CSV
 * 
 * GET /api/calendar/calendar-view.php?action=export-calendar&format=json&start_date=2024-01-01&end_date=2024-01-31
 * - Exportar calendario en formato JSON
 * 
 * === CONFIGURACIÓN (POST) ===
 * 
 * POST /api/calendar/calendar-view.php
 * Content-Type: application/json
 * {
 *   "action": "update-calendar-config",
 *   "config": {
 *     "calendar_color_scheme": "blue",
 *     "default_view": "weekly",
 *     "week_start_day": "monday",
 *     "booking_advance_time": 120,
 *     "slot_duration": 30
 *   }
 * }
 * 
 * === OPERACIONES MASIVAS (POST) ===
 * 
 * POST /api/calendar/calendar-view.php
 * {
 *   "action": "bulk-update-events",
 *   "operation": "update_status",
 *   "updates": [
 *     {"booking_id": 123, "status": "confirmed"},
 *     {"booking_id": 124, "status": "cancelled"}
 *   ]
 * }
 * 
 * POST /api/calendar/calendar-view.php
 * {
 *   "action": "bulk-update-events",
 *   "operation": "reschedule",
 *   "updates": [
 *     {"booking_id": 123, "new_date": "2024-01-20", "new_time": "14:00"},
 *     {"booking_id": 124, "new_date": "2024-01-21", "new_time": "15:30"}
 *   ]
 * }
 * 
 * PARÁMETROS COMUNES:
 * - date: YYYY-MM-DD
 * - start_date, end_date: YYYY-MM-DD  
 * - month: 1-12
 * - year: YYYY
 * - service_id: número
 * - status: pending|confirmed|completed|cancelled|no_show
 * - week_start: monday|sunday
 * - include_availability: true|false
 * - format: ical|csv|json
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
 * TIPOS DE VISTA:
 * - monthly: Calendario mensual con días y eventos
 * - weekly: Vista semanal con grilla horaria (slots de 30min)
 * - daily: Vista diaria detallada (slots de 15min)
 * 
 * ESTADOS DE SLOT:
 * - available: Disponible para reserva
 * - booked: Ocupado con reserva
 * - break: Horario de descanso
 * - unavailable: No disponible
 * 
 * AUTENTICACIÓN:
 * Header: Authorization: Bearer {JWT_TOKEN}
 * 
 * EXPORTACIÓN:
 * - iCal: Formato estándar para calendarios (Outlook, Google Calendar, etc.)
 * - CSV: Hoja de cálculo con datos de reservas
 * - JSON: Datos estructurados para integración
 */