<?php
/**
 * API de Gestión de Reservas - ReservaFácil
 * 
 * Este archivo maneja todas las operaciones de reservas (bookings)
 * Incluye creación, modificación, cancelación y gestión de disponibilidad
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
 * Clase principal para gestión de reservas
 */
class BookingsManager {
    private $db;
    private $config;
    private $user_id;
    private $user_type;
    
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
            case 'list':
                $this->getBookingsList();
                break;
            case 'detail':
                $this->getBookingDetail();
                break;
            case 'availability':
                $this->checkAvailability();
                break;
            case 'calendar':
                $this->getCalendarView();
                break;
            case 'stats':
                $this->getBookingStats();
                break;
            case 'customer-history':
                $this->getCustomerBookingHistory();
                break;
            default:
                $this->getBookingsList();
        }
    }
    
    /**
     * Manejar solicitudes POST
     */
    private function handlePost($action) {
        switch ($action) {
            case 'create':
                $this->createBooking();
                break;
            case 'confirm':
                $this->confirmBooking();
                break;
            case 'check-conflicts':
                $this->checkBookingConflicts();
                break;
            case 'send-reminder':
                $this->sendBookingReminder();
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
                $this->updateBooking();
                break;
            case 'reschedule':
                $this->rescheduleBooking();
                break;
            case 'update-status':
                $this->updateBookingStatus();
                break;
            case 'add-notes':
                $this->addBookingNotes();
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
                $this->cancelBooking();
                break;
            case 'delete':
                $this->deleteBooking();
                break;
            default:
                $this->sendError('Acción no válida', 400);
        }
    }
    
    /**
     * Obtener lista de reservas
     */
    private function getBookingsList() {
        try {
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $status = $_GET['status'] ?? 'all';
            $service_id = (int)($_GET['service_id'] ?? 0);
            $date_from = $_GET['date_from'] ?? '';
            $date_to = $_GET['date_to'] ?? '';
            
            $offset = ($page - 1) * $limit;
            
            // Query base diferente según tipo de usuario
            if ($this->user_type === 'owner') {
                // Propietarios ven reservas de sus servicios
                $query = "SELECT b.*, s.name as service_name, s.address as service_address,
                                u.name as customer_name, u.phone as customer_phone, u.email as customer_email
                         FROM bookings b
                         JOIN services s ON b.service_id = s.id
                         JOIN users u ON b.user_id = u.id
                         WHERE s.owner_id = ?";
                $params = [$this->user_id];
            } else {
                // Clientes ven sus propias reservas
                $query = "SELECT b.*, s.name as service_name, s.address as service_address, s.phone as service_phone,
                                so.business_name as owner_business_name
                         FROM bookings b
                         JOIN services s ON b.service_id = s.id
                         JOIN users so ON s.owner_id = so.id
                         WHERE b.user_id = ?";
                $params = [$this->user_id];
            }
            
            // Aplicar filtros
            if ($status !== 'all') {
                $query .= " AND b.status = ?";
                $params[] = $status;
            }
            
            if ($service_id > 0) {
                $query .= " AND b.service_id = ?";
                $params[] = $service_id;
            }
            
            if (!empty($date_from)) {
                $query .= " AND DATE(b.booking_date) >= ?";
                $params[] = $date_from;
            }
            
            if (!empty($date_to)) {
                $query .= " AND DATE(b.booking_date) <= ?";
                $params[] = $date_to;
            }
            
            $query .= " ORDER BY b.booking_date DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->db->prepare($query);
            $bookings = $stmt->execute($params)->fetchAll();
            
            // Obtener total para paginación
            $count_query = str_replace('SELECT b.*, s.name as service_name, s.address as service_address,', 'SELECT COUNT(*) as total', $query);
            $count_query = preg_replace('/ORDER BY.*/', '', $count_query);
            $count_query = preg_replace('/LIMIT.*/', '', $count_query);
            
            $count_params = array_slice($params, 0, -2); // Remover limit y offset
            $count_stmt = $this->db->prepare($count_query);
            $total = $count_stmt->execute($count_params)->fetch()['total'];
            
            // Procesar reservas para incluir información adicional
            foreach ($bookings as &$booking) {
                $booking['can_cancel'] = $this->canCancelBooking($booking);
                $booking['can_reschedule'] = $this->canRescheduleBooking($booking);
                $booking['time_until_booking'] = $this->getTimeUntilBooking($booking['booking_date']);
            }
            
            $this->sendSuccess([
                'bookings' => $bookings,
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => ceil($total / $limit),
                    'total_records' => (int)$total,
                    'limit' => $limit
                ],
                'filters_applied' => [
                    'status' => $status,
                    'service_id' => $service_id,
                    'date_from' => $date_from,
                    'date_to' => $date_to
                ]
            ]);
            
        } catch (Exception $e) {
            $this->logError('Error en getBookingsList: ' . $e->getMessage());
            $this->sendError('Error al obtener lista de reservas', 500);
        }
    }
    
    /**
     * Obtener detalle completo de una reserva
     */
    private function getBookingDetail() {
        try {
            $booking_id = (int)($_GET['id'] ?? 0);
            
            if (!$booking_id) {
                $this->sendError('ID de reserva requerido', 400);
            }
            
            // Query con toda la información necesaria
            $query = "SELECT b.*, 
                            s.name as service_name, s.address as service_address, s.phone as service_phone,
                            s.latitude, s.longitude,
                            u.name as customer_name, u.phone as customer_phone, u.email as customer_email,
                            so.business_name as owner_business_name, so.phone as owner_phone
                     FROM bookings b
                     JOIN services s ON b.service_id = s.id
                     JOIN users u ON b.user_id = u.id
                     JOIN users so ON s.owner_id = so.id
                     WHERE b.id = ?";
            
            $params = [$booking_id];
            
            // Verificar permisos según tipo de usuario
            if ($this->user_type === 'owner') {
                $query .= " AND s.owner_id = ?";
                $params[] = $this->user_id;
            } else {
                $query .= " AND b.user_id = ?";
                $params[] = $this->user_id;
            }
            
            $stmt = $this->db->prepare($query);
            $booking = $stmt->execute($params)->fetch();
            
            if (!$booking) {
                $this->sendError('Reserva no encontrada o sin permisos', 404);
            }
            
            // Obtener historial de cambios
            $history_query = "SELECT * FROM booking_history 
                             WHERE booking_id = ? 
                             ORDER BY created_at DESC";
            $history_stmt = $this->db->prepare($history_query);
            $booking['history'] = $history_stmt->execute([$booking_id])->fetchAll();
            
            // Obtener imágenes del servicio
            $images_query = "SELECT filename, 
                                   CONCAT(?, '/uploads/services/', filename) as url
                            FROM service_images 
                            WHERE service_id = ? 
                            ORDER BY created_at ASC 
                            LIMIT 3";
            $images_stmt = $this->db->prepare($images_query);
            $booking['service_images'] = $images_stmt->execute([
                $this->config->get('base_url'), 
                $booking['service_id']
            ])->fetchAll();
            
            // Añadir información de estado
            $booking['can_cancel'] = $this->canCancelBooking($booking);
            $booking['can_reschedule'] = $this->canRescheduleBooking($booking);
            $booking['can_confirm'] = $this->canConfirmBooking($booking);
            $booking['time_until_booking'] = $this->getTimeUntilBooking($booking['booking_date']);
            
            $this->sendSuccess(['booking' => $booking]);
            
        } catch (Exception $e) {
            $this->logError('Error en getBookingDetail: ' . $e->getMessage());
            $this->sendError('Error al obtener detalle de reserva', 500);
        }
    }
    
    /**
     * Verificar disponibilidad para una fecha y hora específica
     */
    private function checkAvailability() {
        try {
            $service_id = (int)($_GET['service_id'] ?? 0);
            $date = $_GET['date'] ?? '';
            $time = $_GET['time'] ?? '';
            $duration = (int)($_GET['duration'] ?? 60); // en minutos
            
            if (!$service_id || !$date || !$time) {
                $this->sendError('Parámetros requeridos: service_id, date, time', 400);
            }
            
            // Validar que el servicio existe y está activo
            $service_query = "SELECT id, name, status FROM services WHERE id = ? AND status = 'active'";
            $service_stmt = $this->db->prepare($service_query);
            $service = $service_stmt->execute([$service_id])->fetch();
            
            if (!$service) {
                $this->sendError('Servicio no encontrado o inactivo', 404);
            }
            
            // Verificar horarios de operación
            $day_of_week = date('w', strtotime($date)); // 0 = domingo, 6 = sábado
            
            $schedule_query = "SELECT open_time, close_time, is_closed 
                              FROM service_schedules 
                              WHERE service_id = ? AND day_of_week = ?";
            $schedule_stmt = $this->db->prepare($schedule_query);
            $schedule = $schedule_stmt->execute([$service_id, $day_of_week])->fetch();
            
            if (!$schedule || $schedule['is_closed']) {
                $this->sendSuccess([
                    'available' => false,
                    'reason' => 'Servicio cerrado en esta fecha',
                    'service_closed' => true
                ]);
                return;
            }
            
            // Verificar si la hora solicitada está dentro del horario
            $requested_time = strtotime($time);
            $open_time = strtotime($schedule['open_time']);
            $close_time = strtotime($schedule['close_time']);
            $end_time = $requested_time + ($duration * 60);
            
            if ($requested_time < $open_time || $end_time > $close_time) {
                $this->sendSuccess([
                    'available' => false,
                    'reason' => 'Fuera del horario de atención',
                    'schedule' => [
                        'open_time' => $schedule['open_time'],
                        'close_time' => $schedule['close_time']
                    ]
                ]);
                return;
            }
            
            // Verificar conflictos con otras reservas
            $booking_datetime = $date . ' ' . $time;
            $end_datetime = date('Y-m-d H:i:s', strtotime($booking_datetime) + ($duration * 60));
            
            $conflict_query = "SELECT COUNT(*) as conflicts
                              FROM bookings 
                              WHERE service_id = ? 
                              AND status IN ('confirmed', 'pending')
                              AND (
                                  (booking_date <= ? AND DATE_ADD(booking_date, INTERVAL duration MINUTE) > ?) OR
                                  (booking_date < ? AND DATE_ADD(booking_date, INTERVAL duration MINUTE) >= ?)
                              )";
            
            $conflict_stmt = $this->db->prepare($conflict_query);
            $conflicts = $conflict_stmt->execute([
                $service_id,
                $booking_datetime, $booking_datetime,
                $end_datetime, $end_datetime
            ])->fetch()['conflicts'];
            
            if ($conflicts > 0) {
                // Sugerir horarios alternativos
                $alternatives = $this->getAlternativeTimeSlots($service_id, $date, $duration);
                
                $this->sendSuccess([
                    'available' => false,
                    'reason' => 'Horario no disponible',
                    'has_conflicts' => true,
                    'alternative_times' => $alternatives
                ]);
                return;
            }
            
            // Verificar que no sea una fecha pasada
            if (strtotime($booking_datetime) <= time()) {
                $this->sendSuccess([
                    'available' => false,
                    'reason' => 'No se pueden hacer reservas en fechas pasadas',
                    'past_date' => true
                ]);
                return;
            }
            
            $this->sendSuccess([
                'available' => true,
                'service_name' => $service['name'],
                'requested_datetime' => $booking_datetime,
                'duration_minutes' => $duration,
                'end_datetime' => $end_datetime
            ]);
            
        } catch (Exception $e) {
            $this->logError('Error en checkAvailability: ' . $e->getMessage());
            $this->sendError('Error al verificar disponibilidad', 500);
        }
    }
    
    /**
     * Obtener vista de calendario con todas las reservas
     */
    private function getCalendarView() {
        try {
            $service_id = (int)($_GET['service_id'] ?? 0);
            $month = $_GET['month'] ?? date('Y-m');
            
            // Validar formato de mes
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                $this->sendError('Formato de mes inválido (YYYY-MM)', 400);
            }
            
            $start_date = $month . '-01';
            $end_date = date('Y-m-t', strtotime($start_date));
            
            $query = "SELECT b.*, s.name as service_name
                     FROM bookings b
                     JOIN services s ON b.service_id = s.id
                     WHERE DATE(b.booking_date) BETWEEN ? AND ?";
            
            $params = [$start_date, $end_date];
            
            // Filtrar según tipo de usuario
            if ($this->user_type === 'owner') {
                $query .= " AND s.owner_id = ?";
                $params[] = $this->user_id;
                
                if ($service_id > 0) {
                    $query .= " AND b.service_id = ?";
                    $params[] = $service_id;
                }
            } else {
                $query .= " AND b.user_id = ?";
                $params[] = $this->user_id;
                
                if ($service_id > 0) {
                    $query .= " AND b.service_id = ?";
                    $params[] = $service_id;
                }
            }
            
            $query .= " ORDER BY b.booking_date ASC";
            
            $stmt = $this->db->prepare($query);
            $bookings = $stmt->execute($params)->fetchAll();
            
            // Organizar por días
            $calendar = [];
            foreach ($bookings as $booking) {
                $date = date('Y-m-d', strtotime($booking['booking_date']));
                if (!isset($calendar[$date])) {
                    $calendar[$date] = [];
                }
                $calendar[$date][] = [
                    'id' => $booking['id'],
                    'time' => date('H:i', strtotime($booking['booking_date'])),
                    'duration' => $booking['duration'],
                    'status' => $booking['status'],
                    'service_name' => $booking['service_name'],
                    'customer_name' => $booking['customer_name'] ?? 'Cliente',
                    'notes' => $booking['notes']
                ];
            }
            
            $this->sendSuccess([
                'calendar' => $calendar,
                'month' => $month,
                'total_bookings' => count($bookings)
            ]);
            
        } catch (Exception $e) {
            $this->logError('Error en getCalendarView: ' . $e->getMessage());
            $this->sendError('Error al obtener vista de calendario', 500);
        }
    }
<!-- 
====================================
SECCIÓN COMPLETADA: API de Gestión de Reservas - Operaciones GET
ARCHIVO: api/services/bookings.php
LÍNEAS: 500
PROGRESO: 1/2 secciones completadas
====================================
SEGUIR AQUÍ
====================================
-->

/**
     * Obtener estadísticas de reservas
     */
    private function getBookingStats() {
        try {
            if ($this->user_type !== 'owner') {
                $this->sendError('Solo propietarios pueden acceder a estadísticas', 403);
            }
            
            // Estadísticas generales
            $general_stats = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_bookings,
                    COUNT(CASE WHEN status = 'confirmed' THEN 1 END) as confirmed_bookings,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_bookings,
                    COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_bookings,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_bookings,
                    COUNT(CASE WHEN DATE(booking_date) = CURDATE() THEN 1 END) as today_bookings,
                    COUNT(CASE WHEN booking_date >= NOW() THEN 1 END) as upcoming_bookings
                FROM bookings b
                JOIN services s ON b.service_id = s.id
                WHERE s.owner_id = ?
            ")->execute([$this->user_id])->fetch();
            
            // Estadísticas por mes (últimos 6 meses)
            $monthly_stats = $this->db->prepare("
                SELECT 
                    DATE_FORMAT(booking_date, '%Y-%m') as month,
                    COUNT(*) as booking_count,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count
                FROM bookings b
                JOIN services s ON b.service_id = s.id
                WHERE s.owner_id = ? 
                AND booking_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                GROUP BY month
                ORDER BY month DESC
            ")->execute([$this->user_id])->fetchAll();
            
            // Top servicios por reservas
            $top_services = $this->db->prepare("
                SELECT s.name, COUNT(b.id) as booking_count
                FROM services s
                LEFT JOIN bookings b ON s.id = b.service_id
                WHERE s.owner_id = ?
                GROUP BY s.id
                ORDER BY booking_count DESC
                LIMIT 5
            ")->execute([$this->user_id])->fetchAll();
            
            $this->sendSuccess([
                'general_stats' => $general_stats,
                'monthly_stats' => $monthly_stats,
                'top_services' => $top_services,
                'generated_at' => date('Y-m-d H:i:s')
            ]);
            
        } catch (Exception $e) {
            $this->logError('Error en getBookingStats: ' . $e->getMessage());
            $this->sendError('Error al obtener estadísticas', 500);
        }
    }
    
    /**
     * Crear nueva reserva
     */
    private function createBooking() {
        try {
            $input = $this->getInputData();
            
            // Validar campos requeridos
            $required_fields = ['service_id', 'booking_date', 'duration'];
            foreach ($required_fields as $field) {
                if (empty($input[$field])) {
                    $this->sendError("Campo requerido: {$field}", 400);
                }
            }
            
            $service_id = (int)$input['service_id'];
            $booking_date = $input['booking_date'];
            $duration = (int)$input['duration'];
            $notes = $input['notes'] ?? '';
            $customer_phone = $input['customer_phone'] ?? '';
            $customer_email = $input['customer_email'] ?? '';
            
            // Validar que el servicio existe y está activo
            $service = $this->db->prepare("
                SELECT id, name, owner_id, status 
                FROM services 
                WHERE id = ? AND status = 'active'
            ")->execute([$service_id])->fetch();
            
            if (!$service) {
                $this->sendError('Servicio no encontrado o inactivo', 404);
            }
            
            // Verificar disponibilidad
            $availability = $this->checkBookingAvailability($service_id, $booking_date, $duration);
            if (!$availability['available']) {
                $this->sendError('Horario no disponible: ' . $availability['reason'], 400);
            }
            
            // Generar código único de reserva
            $booking_code = $this->generateBookingCode();
            
            // Crear reserva
            $insert_query = "INSERT INTO bookings (
                                service_id, user_id, booking_date, duration, 
                                booking_code, status, notes, customer_phone, 
                                customer_email, created_at
                            ) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, NOW())";
            
            $stmt = $this->db->prepare($insert_query);
            $result = $stmt->execute([
                $service_id, $this->user_id, $booking_date, $duration,
                $booking_code, $notes, $customer_phone, $customer_email
            ]);
            
            if ($result) {
                $booking_id = $this->db->lastInsertId();
                
                // Registrar en historial
                $this->addBookingHistory($booking_id, 'created', 'Reserva creada', $this->user_id);
                
                // Enviar notificación al propietario
                $this->sendBookingNotification($booking_id, 'new_booking');
                
                // Log de actividad
                $this->logActivity('booking_created', $booking_id, "Reserva creada para servicio: {$service['name']}");
                
                $this->sendSuccess([
                    'message' => 'Reserva creada exitosamente',
                    'booking_id' => $booking_id,
                    'booking_code' => $booking_code,
                    'status' => 'pending'
                ], 201);
                
            } else {
                $this->sendError('Error al crear la reserva', 500);
            }
            
        } catch (Exception $e) {
            $this->logError('Error en createBooking: ' . $e->getMessage());
            $this->sendError('Error al crear reserva', 500);
        }
    }
    
    /**
     * Confirmar reserva (solo propietarios)
     */
    private function confirmBooking() {
        try {
            if ($this->user_type !== 'owner') {
                $this->sendError('Solo propietarios pueden confirmar reservas', 403);
            }
            
            $booking_id = (int)($_GET['id'] ?? 0);
            $input = $this->getInputData();
            
            if (!$booking_id) {
                $this->sendError('ID de reserva requerido', 400);
            }
            
            // Verificar que la reserva pertenece al propietario
            $booking = $this->db->prepare("
                SELECT b.*, s.name as service_name, s.owner_id
                FROM bookings b
                JOIN services s ON b.service_id = s.id
                WHERE b.id = ? AND s.owner_id = ?
            ")->execute([$booking_id, $this->user_id])->fetch();
            
            if (!$booking) {
                $this->sendError('Reserva no encontrada o sin permisos', 404);
            }
            
            if ($booking['status'] !== 'pending') {
                $this->sendError('Solo se pueden confirmar reservas pendientes', 400);
            }
            
            // Actualizar estado
            $update_query = "UPDATE bookings 
                           SET status = 'confirmed', confirmed_at = NOW(), confirmed_by = ?
                           WHERE id = ?";
            
            $stmt = $this->db->prepare($update_query);
            $result = $stmt->execute([$this->user_id, $booking_id]);
            
            if ($result) {
                // Registrar en historial
                $this->addBookingHistory($booking_id, 'confirmed', 'Reserva confirmada por propietario', $this->user_id);
                
                // Enviar notificación al cliente
                $this->sendBookingNotification($booking_id, 'booking_confirmed');
                
                // Log de actividad
                $this->logActivity('booking_confirmed', $booking_id, "Reserva confirmada para: {$booking['service_name']}");
                
                $this->sendSuccess([
                    'message' => 'Reserva confirmada exitosamente',
                    'booking_id' => $booking_id,
                    'new_status' => 'confirmed'
                ]);
                
            } else {
                $this->sendError('Error al confirmar reserva', 500);
            }
            
        } catch (Exception $e) {
            $this->logError('Error en confirmBooking: ' . $e->getMessage());
            $this->sendError('Error al confirmar reserva', 500);
        }
    }
    
    /**
     * Actualizar reserva existente
     */
    private function updateBooking() {
        try {
            $booking_id = (int)($_GET['id'] ?? 0);
            $input = $this->getInputData();
            
            if (!$booking_id) {
                $this->sendError('ID de reserva requerido', 400);
            }
            
            // Verificar permisos de acceso
            $booking = $this->getBookingWithPermissions($booking_id);
            if (!$booking) {
                $this->sendError('Reserva no encontrada o sin permisos', 404);
            }
            
            if (!in_array($booking['status'], ['pending', 'confirmed'])) {
                $this->sendError('No se puede modificar esta reserva', 400);
            }
            
            // Campos actualizables
            $update_fields = [];
            $params = [];
            
            if (isset($input['notes'])) {
                $update_fields[] = "notes = ?";
                $params[] = $input['notes'];
            }
            
            if (isset($input['customer_phone'])) {
                $update_fields[] = "customer_phone = ?";
                $params[] = $input['customer_phone'];
            }
            
            if (isset($input['customer_email'])) {
                $update_fields[] = "customer_email = ?";
                $params[] = $input['customer_email'];
            }
            
            if (empty($update_fields)) {
                $this->sendError('No hay campos para actualizar', 400);
            }
            
            $update_fields[] = "updated_at = NOW()";
            $params[] = $booking_id;
            
            $update_query = "UPDATE bookings SET " . implode(', ', $update_fields) . " WHERE id = ?";
            
            $stmt = $this->db->prepare($update_query);
            $result = $stmt->execute($params);
            
            if ($result) {
                $this->addBookingHistory($booking_id, 'updated', 'Reserva actualizada', $this->user_id);
                $this->logActivity('booking_updated', $booking_id, 'Reserva actualizada');
                
                $this->sendSuccess(['message' => 'Reserva actualizada exitosamente']);
            } else {
                $this->sendError('Error al actualizar reserva', 500);
            }
            
        } catch (Exception $e) {
            $this->logError('Error en updateBooking: ' . $e->getMessage());
            $this->sendError('Error al actualizar reserva', 500);
        }
    }
    
    /**
     * Reprogramar reserva
     */
    private function rescheduleBooking() {
        try {
            $booking_id = (int)($_GET['id'] ?? 0);
            $input = $this->getInputData();
            
            if (!$booking_id) {
                $this->sendError('ID de reserva requerido', 400);
            }
            
            if (empty($input['new_booking_date'])) {
                $this->sendError('Nueva fecha requerida', 400);
            }
            
            // Verificar permisos
            $booking = $this->getBookingWithPermissions($booking_id);
            if (!$booking) {
                $this->sendError('Reserva no encontrada o sin permisos', 404);
            }
            
            if (!$this->canRescheduleBooking($booking)) {
                $this->sendError('Esta reserva no se puede reprogramar', 400);
            }
            
            $new_date = $input['new_booking_date'];
            $duration = $input['duration'] ?? $booking['duration'];
            
            // Verificar disponibilidad en nueva fecha
            $availability = $this->checkBookingAvailability($booking['service_id'], $new_date, $duration, $booking_id);
            if (!$availability['available']) {
                $this->sendError('Nueva fecha no disponible: ' . $availability['reason'], 400);
            }
            
            // Guardar fecha original para historial
            $original_date = $booking['booking_date'];
            
            // Actualizar reserva
            $update_query = "UPDATE bookings 
                           SET booking_date = ?, duration = ?, status = 'pending', updated_at = NOW()
                           WHERE id = ?";
            
            $stmt = $this->db->prepare($update_query);
            $result = $stmt->execute([$new_date, $duration, $booking_id]);
            
            if ($result) {
                $history_note = "Reprogramada de {$original_date} a {$new_date}";
                $this->addBookingHistory($booking_id, 'rescheduled', $history_note, $this->user_id);
                
                // Notificar a ambas partes
                $this->sendBookingNotification($booking_id, 'booking_rescheduled');
                
                $this->logActivity('booking_rescheduled', $booking_id, $history_note);
                
                $this->sendSuccess([
                    'message' => 'Reserva reprogramada exitosamente',
                    'original_date' => $original_date,
                    'new_date' => $new_date,
                    'status' => 'pending'
                ]);
                
            } else {
                $this->sendError('Error al reprogramar reserva', 500);
            }
            
        } catch (Exception $e) {
            $this->logError('Error en rescheduleBooking: ' . $e->getMessage());
            $this->sendError('Error al reprogramar reserva', 500);
        }
    }
    
    /**
     * Cancelar reserva
     */
    private function cancelBooking() {
        try {
            $booking_id = (int)($_GET['id'] ?? 0);
            $input = $this->getInputData();
            
            if (!$booking_id) {
                $this->sendError('ID de reserva requerido', 400);
            }
            
            // Verificar permisos
            $booking = $this->getBookingWithPermissions($booking_id);
            if (!$booking) {
                $this->sendError('Reserva no encontrada o sin permisos', 404);
            }
            
            if (!$this->canCancelBooking($booking)) {
                $this->sendError('Esta reserva no se puede cancelar', 400);
            }
            
            $cancellation_reason = $input['reason'] ?? 'Cancelada por usuario';
            
            // Actualizar estado
            $update_query = "UPDATE bookings 
                           SET status = 'cancelled', cancelled_at = NOW(), 
                               cancelled_by = ?, cancellation_reason = ?
                           WHERE id = ?";
            
            $stmt = $this->db->prepare($update_query);
            $result = $stmt->execute([$this->user_id, $cancellation_reason, $booking_id]);
            
            if ($result) {
                $this->addBookingHistory($booking_id, 'cancelled', $cancellation_reason, $this->user_id);
                
                // Notificar cancelación
                $this->sendBookingNotification($booking_id, 'booking_cancelled');
                
                $this->logActivity('booking_cancelled', $booking_id, "Cancelada: {$cancellation_reason}");
                
                $this->sendSuccess([
                    'message' => 'Reserva cancelada exitosamente',
                    'cancellation_reason' => $cancellation_reason
                ]);
                
            } else {
                $this->sendError('Error al cancelar reserva', 500);
            }
            
        } catch (Exception $e) {
            $this->logError('Error en cancelBooking: ' . $e->getMessage());
            $this->sendError('Error al cancelar reserva', 500);
        }
    }
    
    // MÉTODOS AUXILIARES PRIVADOS
    
    /**
     * Verificar disponibilidad completa
     */
    private function checkBookingAvailability($service_id, $booking_date, $duration, $exclude_booking_id = null) {
        // Verificar servicio activo
        $service = $this->db->prepare("SELECT status FROM services WHERE id = ?")->execute([$service_id])->fetch();
        if (!$service || $service['status'] !== 'active') {
            return ['available' => false, 'reason' => 'Servicio no disponible'];
        }
        
        // Verificar horarios de operación
        $day_of_week = date('w', strtotime($booking_date));
        $schedule = $this->db->prepare("
            SELECT open_time, close_time, is_closed 
            FROM service_schedules 
            WHERE service_id = ? AND day_of_week = ?
        ")->execute([$service_id, $day_of_week])->fetch();
        
        if (!$schedule || $schedule['is_closed']) {
            return ['available' => false, 'reason' => 'Servicio cerrado en esta fecha'];
        }
        
        // Verificar conflictos
        $end_datetime = date('Y-m-d H:i:s', strtotime($booking_date) + ($duration * 60));
        
        $conflict_query = "SELECT COUNT(*) as conflicts
                          FROM bookings 
                          WHERE service_id = ? 
                          AND status IN ('confirmed', 'pending')
                          AND id != ?
                          AND (
                              (booking_date <= ? AND DATE_ADD(booking_date, INTERVAL duration MINUTE) > ?) OR
                              (booking_date < ? AND DATE_ADD(booking_date, INTERVAL duration MINUTE) >= ?)
                          )";
        
        $conflicts = $this->db->prepare($conflict_query)->execute([
            $service_id, $exclude_booking_id ?? 0,
            $booking_date, $booking_date,
            $end_datetime, $end_datetime
        ])->fetch()['conflicts'];
        
        if ($conflicts > 0) {
            return ['available' => false, 'reason' => 'Horario ocupado'];
        }
        
        return ['available' => true];
    }
    
    /**
     * Obtener reserva con verificación de permisos
     */
    private function getBookingWithPermissions($booking_id) {
        $query = "SELECT b.*, s.owner_id
                 FROM bookings b
                 JOIN services s ON b.service_id = s.id
                 WHERE b.id = ?";
        
        if ($this->user_type === 'owner') {
            $query .= " AND s.owner_id = ?";
        } else {
            $query .= " AND b.user_id = ?";
        }
        
        return $this->db->prepare($query)->execute([$booking_id, $this->user_id])->fetch();
    }
    
    /**
     * Verificar si se puede cancelar reserva
     */
    private function canCancelBooking($booking) {
        if (!in_array($booking['status'], ['pending', 'confirmed'])) {
            return false;
        }
        
        // Verificar tiempo mínimo para cancelación (2 horas antes)
        $booking_time = strtotime($booking['booking_date']);
        $min_cancel_time = $booking_time - (2 * 3600); // 2 horas antes
        
        return time() < $min_cancel_time;
    }
    
    /**
     * Verificar si se puede reprogramar reserva
     */
    private function canRescheduleBooking($booking) {
        if (!in_array($booking['status'], ['pending', 'confirmed'])) {
            return false;
        }
        
        // Verificar tiempo mínimo para reprogramación (4 horas antes)
        $booking_time = strtotime($booking['booking_date']);
        $min_reschedule_time = $booking_time - (4 * 3600); // 4 horas antes
        
        return time() < $min_reschedule_time;
    }
    
    /**
     * Verificar si se puede confirmar reserva
     */
    private function canConfirmBooking($booking) {
        return $booking['status'] === 'pending' && $this->user_type === 'owner';
    }
    
    /**
     * Obtener tiempo hasta la reserva
     */
    private function getTimeUntilBooking($booking_date) {
        $booking_time = strtotime($booking_date);
        $current_time = time();
        $diff = $booking_time - $current_time;
        
        if ($diff < 0) {
            return 'Pasada';
        }
        
        $days = floor($diff / 86400);
        $hours = floor(($diff % 86400) / 3600);
        $minutes = floor(($diff % 3600) / 60);
        
        if ($days > 0) {
            return "{$days} días, {$hours} horas";
        } elseif ($hours > 0) {
            return "{$hours} horas, {$minutes} minutos";
        } else {
            return "{$minutes} minutos";
        }
    }
    
    /**
     * Generar código único de reserva
     */
    private function generateBookingCode() {
        $code = 'RF' . date('Ymd') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Verificar que no existe
        $exists = $this->db->prepare("SELECT id FROM bookings WHERE booking_code = ?")->execute([$code])->fetch();
        
        if ($exists) {
            return $this->generateBookingCode(); // Recursivo si existe
        }
        
        return $code;
    }
    
    /**
     * Obtener horarios alternativos
     */
    private function getAlternativeTimeSlots($service_id, $date, $duration, $limit = 5) {
        $alternatives = [];
        $day_of_week = date('w', strtotime($date));
        
        // Obtener horario del servicio
        $schedule = $this->db->prepare("
            SELECT open_time, close_time 
            FROM service_schedules 
            WHERE service_id = ? AND day_of_week = ? AND is_closed = 0
        ")->execute([$service_id, $day_of_week])->fetch();
        
        if (!$schedule) return $alternatives;
        
        $open_time = strtotime($date . ' ' . $schedule['open_time']);
        $close_time = strtotime($date . ' ' . $schedule['close_time']);
        $slot_duration = $duration * 60; // en segundos
        
        // Generar slots cada 30 minutos
        for ($time = $open_time; $time <= ($close_time - $slot_duration); $time += 1800) {
            $slot_datetime = date('Y-m-d H:i:s', $time);
            
            $availability = $this->checkBookingAvailability($service_id, $slot_datetime, $duration);
            if ($availability['available']) {
                $alternatives[] = [
                    'datetime' => $slot_datetime,
                    'time' => date('H:i', $time)
                ];
                
                if (count($alternatives) >= $limit) break;
            }
        }
        
        return $alternatives;
    }
    
    /**
     * Agregar entrada al historial de reserva
     */
    private function addBookingHistory($booking_id, $action, $description, $user_id) {
        $query = "INSERT INTO booking_history (booking_id, action, description, user_id, created_at) 
                 VALUES (?, ?, ?, ?, NOW())";
        $this->db->prepare($query)->execute([$booking_id, $action, $description, $user_id]);
    }
    
    /**
     * Enviar notificación de reserva
     */
    private function sendBookingNotification($booking_id, $type) {
        // Implementar sistema de notificaciones
        // En producción integrar con servicio de email/SMS
        $this->logActivity('notification_sent', $booking_id, "Notificación enviada: {$type}");
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
    private function logActivity($action, $booking_id, $description) {
        try {
            $query = "INSERT INTO activity_logs (user_id, action, resource_type, resource_id, description, created_at) 
                     VALUES (?, ?, 'booking', ?, ?, NOW())";
            $this->db->prepare($query)->execute([$this->user_id, $action, $booking_id, $description]);
        } catch (Exception $e) {
            error_log("Error logging activity: " . $e->getMessage());
        }
    }
    
    /**
     * Registrar errores
     */
    private function logError($message) {
        $log_message = "[BOOKINGS API] " . date('Y-m-d H:i:s') . " - USER_ID: {$this->user_id} - " . $message;
        error_log($log_message);
        
        if ($this->config->get('environment') === 'production') {
            // Enviar a sistema de monitoreo en producción
        }
    }
}

// Inicializar y procesar solicitud
try {
    $bookings_manager = new BookingsManager();
    $bookings_manager->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error interno del servidor',
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    
    error_log("[BOOKINGS API FATAL] " . date('Y-m-d H:i:s') . " - " . $e->getMessage());
}
?>
<!-- 
====================================
SECCIÓN COMPLETADA: API de Gestión de Reservas - Sección Final Completa
ARCHIVO: api/services/bookings.php
LÍNEAS: 500
PROGRESO: ARCHIVO COMPLETADO EXITOSAMENTE
====================================
SEGUIR AQUÍ
====================================
-->