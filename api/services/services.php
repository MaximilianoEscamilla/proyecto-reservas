<?php
/**
 * API de Gestión de Servicios - ReservaFácil
 * 
 * Este archivo maneja todas las operaciones CRUD de servicios de los propietarios
 * Incluye validaciones, geolocalización, categorías y estado de servicios
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
 * Clase principal para gestión de servicios
 */
class ServicesManager {
    private $db;
    private $config;
    private $user_id;
    
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
                $this->getServicesList();
                break;
            case 'detail':
                $this->getServiceDetail();
                break;
            case 'categories':
                $this->getServiceCategories();
                break;
            case 'nearby':
                $this->getNearbyServices();
                break;
            case 'search':
                $this->searchServices();
                break;
            case 'stats':
                $this->getServiceStats();
                break;
            default:
                $this->getServicesList();
        }
    }
    
    /**
     * Manejar solicitudes POST
     */
    private function handlePost($action) {
        switch ($action) {
            case 'create':
                $this->createService();
                break;
            case 'upload-images':
                $this->uploadServiceImages();
                break;
            case 'validate-address':
                $this->validateAddress();
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
                $this->updateService();
                break;
            case 'toggle-status':
                $this->toggleServiceStatus();
                break;
            case 'update-schedule':
                $this->updateServiceSchedule();
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
            case 'delete':
                $this->deleteService();
                break;
            case 'delete-image':
                $this->deleteServiceImage();
                break;
            default:
                $this->sendError('Acción no válida', 400);
        }
    }
    
    /**
     * Obtener lista de servicios del propietario
     */
    private function getServicesList() {
        try {
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 10);
            $status = $_GET['status'] ?? 'all';
            $category = $_GET['category'] ?? '';
            
            $offset = ($page - 1) * $limit;
            
            // Construir query base
            $query = "SELECT s.*, sc.name as category_name, 
                            COUNT(b.id) as total_bookings,
                            AVG(r.rating) as avg_rating,
                            COUNT(r.id) as total_reviews
                     FROM services s
                     LEFT JOIN service_categories sc ON s.category_id = sc.id
                     LEFT JOIN bookings b ON s.id = b.service_id
                     LEFT JOIN reviews r ON s.id = r.service_id
                     WHERE s.owner_id = ?";
            
            $params = [$this->user_id];
            
            // Filtros adicionales
            if ($status !== 'all') {
                $query .= " AND s.status = ?";
                $params[] = $status;
            }
            
            if (!empty($category)) {
                $query .= " AND s.category_id = ?";
                $params[] = $category;
            }
            
            $query .= " GROUP BY s.id ORDER BY s.created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->db->prepare($query);
            $services = $stmt->execute($params)->fetchAll();
            
            // Obtener total de registros para paginación
            $count_query = "SELECT COUNT(*) as total FROM services s WHERE s.owner_id = ?";
            $count_params = [$this->user_id];
            
            if ($status !== 'all') {
                $count_query .= " AND s.status = ?";
                $count_params[] = $status;
            }
            
            if (!empty($category)) {
                $count_query .= " AND s.category_id = ?";
                $count_params[] = $category;
            }
            
            $count_stmt = $this->db->prepare($count_query);
            $total = $count_stmt->execute($count_params)->fetch()['total'];
            
            // Procesar servicios para incluir imágenes
            foreach ($services as &$service) {
                $service['images'] = $this->getServiceImages($service['id']);
                $service['schedule'] = $this->getServiceSchedule($service['id']);
                
                // Formatear números
                $service['avg_rating'] = $service['avg_rating'] ? round($service['avg_rating'], 1) : 0;
                $service['total_bookings'] = (int)$service['total_bookings'];
                $service['total_reviews'] = (int)$service['total_reviews'];
            }
            
            $this->sendSuccess([
                'services' => $services,
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => ceil($total / $limit),
                    'total_records' => (int)$total,
                    'limit' => $limit
                ]
            ]);
            
        } catch (Exception $e) {
            $this->logError('Error en getServicesList: ' . $e->getMessage());
            $this->sendError('Error al obtener servicios', 500);
        }
    }
    
    /**
     * Obtener detalle completo de un servicio
     */
    private function getServiceDetail() {
        try {
            $service_id = (int)($_GET['id'] ?? 0);
            
            if (!$service_id) {
                $this->sendError('ID de servicio requerido', 400);
            }
            
            // Verificar que el servicio pertenece al usuario
            $query = "SELECT s.*, sc.name as category_name, sc.icon as category_icon,
                            u.business_name, u.phone, u.email,
                            AVG(r.rating) as avg_rating,
                            COUNT(r.id) as total_reviews
                     FROM services s
                     LEFT JOIN service_categories sc ON s.category_id = sc.id
                     LEFT JOIN users u ON s.owner_id = u.id
                     LEFT JOIN reviews r ON s.id = r.service_id
                     WHERE s.id = ? AND s.owner_id = ?
                     GROUP BY s.id";
            
            $stmt = $this->db->prepare($query);
            $service = $stmt->execute([$service_id, $this->user_id])->fetch();
            
            if (!$service) {
                $this->sendError('Servicio no encontrado', 404);
            }
            
            // Obtener información adicional
            $service['images'] = $this->getServiceImages($service_id);
            $service['schedule'] = $this->getServiceSchedule($service_id);
            $service['amenities'] = $this->getServiceAmenities($service_id);
            $service['recent_bookings'] = $this->getRecentBookings($service_id, 5);
            $service['recent_reviews'] = $this->getRecentReviews($service_id, 5);
            
            // Formatear datos
            $service['avg_rating'] = $service['avg_rating'] ? round($service['avg_rating'], 1) : 0;
            $service['total_reviews'] = (int)$service['total_reviews'];
            
            $this->sendSuccess(['service' => $service]);
            
        } catch (Exception $e) {
            $this->logError('Error en getServiceDetail: ' . $e->getMessage());
            $this->sendError('Error al obtener detalle del servicio', 500);
        }
    }
    
    /**
     * Obtener categorías de servicios disponibles
     */
    private function getServiceCategories() {
        try {
            $query = "SELECT id, name, description, icon, color 
                     FROM service_categories 
                     WHERE active = 1 
                     ORDER BY name ASC";
            
            $stmt = $this->db->prepare($query);
            $categories = $stmt->execute()->fetchAll();
            
            $this->sendSuccess(['categories' => $categories]);
            
        } catch (Exception $e) {
            $this->logError('Error en getServiceCategories: ' . $e->getMessage());
            $this->sendError('Error al obtener categorías', 500);
        }
    }
    
    /**
     * Obtener servicios cercanos por geolocalización
     */
    private function getNearbyServices() {
        try {
            $lat = (float)($_GET['lat'] ?? 0);
            $lng = (float)($_GET['lng'] ?? 0);
            $radius = (float)($_GET['radius'] ?? 5); // Radio en km
            $category = $_GET['category'] ?? '';
            
            if (!$lat || !$lng) {
                $this->sendError('Coordenadas requeridas', 400);
            }
            
            // Query con cálculo de distancia usando fórmula Haversine
            $query = "SELECT s.*, sc.name as category_name, sc.icon as category_icon,
                            u.business_name, u.phone,
                            AVG(r.rating) as avg_rating,
                            COUNT(r.id) as total_reviews,
                            (6371 * acos(cos(radians(?)) * cos(radians(s.latitude)) * 
                             cos(radians(s.longitude) - radians(?)) + 
                             sin(radians(?)) * sin(radians(s.latitude)))) AS distance
                     FROM services s
                     LEFT JOIN service_categories sc ON s.category_id = sc.id
                     LEFT JOIN users u ON s.owner_id = u.id
                     LEFT JOIN reviews r ON s.id = r.service_id
                     WHERE s.status = 'active' 
                     AND s.latitude IS NOT NULL 
                     AND s.longitude IS NOT NULL";
            
            $params = [$lat, $lng, $lat];
            
            if (!empty($category)) {
                $query .= " AND s.category_id = ?";
                $params[] = $category;
            }
            
            $query .= " GROUP BY s.id 
                       HAVING distance <= ? 
                       ORDER BY distance ASC 
                       LIMIT 50";
            $params[] = $radius;
            
            $stmt = $this->db->prepare($query);
            $services = $stmt->execute($params)->fetchAll();
            
            // Procesar servicios
            foreach ($services as &$service) {
                $service['images'] = $this->getServiceImages($service['id']);
                $service['avg_rating'] = $service['avg_rating'] ? round($service['avg_rating'], 1) : 0;
                $service['total_reviews'] = (int)$service['total_reviews'];
                $service['distance'] = round($service['distance'], 2);
            }
            
            $this->sendSuccess([
                'services' => $services,
                'search_params' => [
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'radius' => $radius,
                    'category' => $category
                ]
            ]);
            
        } catch (Exception $e) {
            $this->logError('Error en getNearbyServices: ' . $e->getMessage());
            $this->sendError('Error al buscar servicios cercanos', 500);
        }
    }
    
    /**
     * Buscar servicios por texto
     */
    private function searchServices() {
        try {
            $query_text = $_GET['q'] ?? '';
            $category = $_GET['category'] ?? '';
            $lat = (float)($_GET['lat'] ?? 0);
            $lng = (float)($_GET['lng'] ?? 0);
            $radius = (float)($_GET['radius'] ?? 10);
            
            if (strlen($query_text) < 2) {
                $this->sendError('Consulta muy corta (mínimo 2 caracteres)', 400);
            }
            
            $search_query = "SELECT s.*, sc.name as category_name, sc.icon as category_icon,
                                   u.business_name, u.phone,
                                   AVG(r.rating) as avg_rating,
                                   COUNT(r.id) as total_reviews";
            
            // Agregar cálculo de distancia si hay coordenadas
            if ($lat && $lng) {
                $search_query .= ", (6371 * acos(cos(radians(?)) * cos(radians(s.latitude)) * 
                                     cos(radians(s.longitude) - radians(?)) + 
                                     sin(radians(?)) * sin(radians(s.latitude)))) AS distance";
            }
            
            $search_query .= " FROM services s
                              LEFT JOIN service_categories sc ON s.category_id = sc.id
                              LEFT JOIN users u ON s.owner_id = u.id
                              LEFT JOIN reviews r ON s.id = r.service_id
                              WHERE s.status = 'active' 
                              AND (s.name LIKE ? OR s.description LIKE ? OR u.business_name LIKE ?)";
            
            $params = [];
            
            if ($lat && $lng) {
                $params = [$lat, $lng, $lat];
            }
            
            $search_term = "%{$query_text}%";
            $params = array_merge($params, [$search_term, $search_term, $search_term]);
            
            if (!empty($category)) {
                $search_query .= " AND s.category_id = ?";
                $params[] = $category;
            }
            
            $search_query .= " GROUP BY s.id";
            
            if ($lat && $lng) {
                $search_query .= " HAVING distance <= ? ORDER BY distance ASC";
                $params[] = $radius;
            } else {
                $search_query .= " ORDER BY s.created_at DESC";
            }
            
            $search_query .= " LIMIT 20";
            
            $stmt = $this->db->prepare($search_query);
            $services = $stmt->execute($params)->fetchAll();
            
            // Procesar resultados
            foreach ($services as &$service) {
                $service['images'] = $this->getServiceImages($service['id']);
                $service['avg_rating'] = $service['avg_rating'] ? round($service['avg_rating'], 1) : 0;
                $service['total_reviews'] = (int)$service['total_reviews'];
                
                if (isset($service['distance'])) {
                    $service['distance'] = round($service['distance'], 2);
                }
            }
            
            $this->sendSuccess([
                'services' => $services,
                'search_params' => [
                    'query' => $query_text,
                    'category' => $category,
                    'total_results' => count($services)
                ]
            ]);
            
        } catch (Exception $e) {
            $this->logError('Error en searchServices: ' . $e->getMessage());
            $this->sendError('Error en la búsqueda', 500);
        }
    }
    
    /**
     * Obtener estadísticas de servicios del propietario
     */
    private function getServiceStats() {
        try {
            // Estadísticas generales
            $stats_query = "SELECT 
                               COUNT(*) as total_services,
                               COUNT(CASE WHEN status = 'active' THEN 1 END) as active_services,
                               COUNT(CASE WHEN status = 'inactive' THEN 1 END) as inactive_services,
                               COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as new_this_month
                           FROM services 
                           WHERE owner_id = ?";
            
            $stmt = $this->db->prepare($stats_query);
            $general_stats = $stmt->execute([$this->user_id])->fetch();
            
            // Top servicios por reservas
            $top_services_query = "SELECT s.name, COUNT(b.id) as booking_count
                                  FROM services s
                                  LEFT JOIN bookings b ON s.id = b.service_id
                                  WHERE s.owner_id = ?
                                  GROUP BY s.id
                                  ORDER BY booking_count DESC
                                  LIMIT 5";
            
            $stmt = $this->db->prepare($top_services_query);
            $top_services = $stmt->execute([$this->user_id])->fetchAll();
            
            // Estadísticas por categoría
            $category_stats_query = "SELECT sc.name as category_name, COUNT(s.id) as service_count
                                    FROM services s
                                    LEFT JOIN service_categories sc ON s.category_id = sc.id
                                    WHERE s.owner_id = ?
                                    GROUP BY s.category_id
                                    ORDER BY service_count DESC";
            
            $stmt = $this->db->prepare($category_stats_query);
            $category_stats = $stmt->execute([$this->user_id])->fetchAll();
            
            $this->sendSuccess([
                'general_stats' => $general_stats,
                'top_services' => $top_services,
                'category_stats' => $category_stats
            ]);
            
        } catch (Exception $e) {
            $this->logError('Error en getServiceStats: ' . $e->getMessage());
            $this->sendError('Error al obtener estadísticas', 500);
        }
    }
    
    /**
     * Crear nuevo servicio
     */
    private function createService() {
        try {
            $input = $this->getInputData();
            
            // Validar datos requeridos
            $required_fields = ['name', 'description', 'category_id', 'address', 'latitude', 'longitude'];
            foreach ($required_fields as $field) {
                if (empty($input[$field])) {
                    $this->sendError("Campo requerido: {$field}", 400);
                }
            }
            
            // Validar coordenadas
            if (!$this->isValidCoordinate($input['latitude'], $input['longitude'])) {
                $this->sendError('Coordenadas inválidas', 400);
            }
            
            // Validar categoría
            if (!$this->isValidCategory($input['category_id'])) {
                $this->sendError('Categoría inválida', 400);
            }
            
            // Insertar servicio
            $insert_query = "INSERT INTO services (
                                owner_id, name, description, category_id, 
                                address, latitude, longitude, phone, email, 
                                website, price_range, status, created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())";
            
            $params = [
                $this->user_id,
                $input['name'],
                $input['description'],
                $input['category_id'],
                $input['address'],
                $input['latitude'],
                $input['longitude'],
                $input['phone'] ?? null,
                $input['email'] ?? null,
                $input['website'] ?? null,
                $input['price_range'] ?? 'medium'
            ];
            
            $stmt = $this->db->prepare($insert_query);
            $result = $stmt->execute($params);
            
            if ($result) {
                $service_id = $this->db->lastInsertId();
                
                // Insertar horarios si se proporcionan
                if (!empty($input['schedule'])) {
                    $this->insertServiceSchedule($service_id, $input['schedule']);
                }
                
                // Insertar amenities si se proporcionan
                if (!empty($input['amenities'])) {
                    $this->insertServiceAmenities($service_id, $input['amenities']);
                }
                
                // Registrar en logs
                $this->logActivity('service_created', $service_id, "Servicio '{$input['name']}' creado");
                
                $this->sendSuccess([
                    'message' => 'Servicio creado exitosamente',
                    'service_id' => $service_id
                ], 201);
            } else {
                $this->sendError('Error al crear el servicio', 500);
            }
            
        } catch (Exception $e) {
            $this->logError('Error en createService: ' . $e->getMessage());
            $this->sendError('Error al crear servicio', 500);
        }
    }
<!-- 
====================================
SECCIÓN COMPLETADA: API de Gestión de Servicios - Operaciones GET y POST
ARCHIVO: api/services/services.php
LÍNEAS: 500
PROGRESO: 1/2 secciones completadas
====================================
SEGUIR AQUÍ
====================================
-->

/**
     * Actualizar servicio existente
     */
    private function updateService() {
        try {
            $service_id = (int)($_GET['id'] ?? 0);
            $input = $this->getInputData();
            
            if (!$service_id) {
                $this->sendError('ID de servicio requerido', 400);
            }
            
            // Verificar que el servicio pertenece al usuario
            if (!$this->verifyServiceOwnership($service_id)) {
                $this->sendError('Servicio no encontrado o sin permisos', 403);
            }
            
            // Construir query de actualización dinámicamente
            $update_fields = [];
            $params = [];
            
            $allowed_fields = ['name', 'description', 'category_id', 'address', 'latitude', 'longitude', 
                              'phone', 'email', 'website', 'price_range'];
            
            foreach ($allowed_fields as $field) {
                if (isset($input[$field])) {
                    $update_fields[] = "{$field} = ?";
                    $params[] = $input[$field];
                }
            }
            
            if (empty($update_fields)) {
                $this->sendError('No hay campos para actualizar', 400);
            }
            
            // Validaciones específicas
            if (isset($input['latitude']) && isset($input['longitude'])) {
                if (!$this->isValidCoordinate($input['latitude'], $input['longitude'])) {
                    $this->sendError('Coordenadas inválidas', 400);
                }
            }
            
            if (isset($input['category_id']) && !$this->isValidCategory($input['category_id'])) {
                $this->sendError('Categoría inválida', 400);
            }
            
            $update_fields[] = "updated_at = NOW()";
            $params[] = $service_id;
            
            $update_query = "UPDATE services SET " . implode(', ', $update_fields) . " WHERE id = ?";
            
            $stmt = $this->db->prepare($update_query);
            $result = $stmt->execute($params);
            
            if ($result) {
                // Actualizar horarios si se proporcionan
                if (isset($input['schedule'])) {
                    $this->updateServiceSchedule($service_id, $input['schedule']);
                }
                
                // Actualizar amenities si se proporcionan
                if (isset($input['amenities'])) {
                    $this->updateServiceAmenities($service_id, $input['amenities']);
                }
                
                // Registrar en logs
                $this->logActivity('service_updated', $service_id, 'Servicio actualizado');
                
                $this->sendSuccess(['message' => 'Servicio actualizado exitosamente']);
            } else {
                $this->sendError('Error al actualizar el servicio', 500);
            }
            
        } catch (Exception $e) {
            $this->logError('Error en updateService: ' . $e->getMessage());
            $this->sendError('Error al actualizar servicio', 500);
        }
    }
    
    /**
     * Cambiar estado del servicio (activo/inactivo)
     */
    private function toggleServiceStatus() {
        try {
            $service_id = (int)($_GET['id'] ?? 0);
            $input = $this->getInputData();
            $new_status = $input['status'] ?? '';
            
            if (!$service_id) {
                $this->sendError('ID de servicio requerido', 400);
            }
            
            if (!in_array($new_status, ['active', 'inactive', 'maintenance'])) {
                $this->sendError('Estado inválido', 400);
            }
            
            // Verificar propiedad
            if (!$this->verifyServiceOwnership($service_id)) {
                $this->sendError('Servicio no encontrado o sin permisos', 403);
            }
            
            $update_query = "UPDATE services SET status = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $this->db->prepare($update_query);
            $result = $stmt->execute([$new_status, $service_id]);
            
            if ($result) {
                // Si se desactiva, cancelar reservas pendientes
                if ($new_status === 'inactive') {
                    $this->cancelPendingBookings($service_id);
                }
                
                $this->logActivity('service_status_changed', $service_id, "Estado cambiado a: {$new_status}");
                
                $this->sendSuccess([
                    'message' => 'Estado del servicio actualizado',
                    'new_status' => $new_status
                ]);
            } else {
                $this->sendError('Error al actualizar estado', 500);
            }
            
        } catch (Exception $e) {
            $this->logError('Error en toggleServiceStatus: ' . $e->getMessage());
            $this->sendError('Error al cambiar estado', 500);
        }
    }
    
  /**
     * Actualizar horarios de servicio
     */
    private function updateServiceSchedule() {
        try {
            $service_id = (int)($_GET['id'] ?? 0);
            $input = $this->getInputData();
            
            if (!$service_id) {
                $this->sendError('ID de servicio requerido', 400);
            }
            
            if (!$this->verifyServiceOwnership($service_id)) {
                $this->sendError('Servicio no encontrado o sin permisos', 403);
            }
            
            if (empty($input['schedule']) || !is_array($input['schedule'])) {
                $this->sendError('Horarios requeridos', 400);
            }
            
            // Eliminar horarios existentes
            $delete_query = "DELETE FROM service_schedules WHERE service_id = ?";
            $stmt = $this->db->prepare($delete_query);
            $stmt->execute([$service_id]);
            
            // Insertar nuevos horarios
            $this->insertServiceSchedule($service_id, $input['schedule']);
            
            $this->logActivity('service_schedule_updated', $service_id, 'Horarios actualizados');
            
            $this->sendSuccess(['message' => 'Horarios actualizados exitosamente']);
            
        } catch (Exception $e) {
            $this->logError('Error en updateServiceSchedule: ' . $e->getMessage());
            $this->sendError('Error al actualizar horarios', 500);
        }
    }
    
    /**
     * Eliminar servicio
     */
    private function deleteService() {
        try {
            $service_id = (int)($_GET['id'] ?? 0);
            
            if (!$service_id) {
                $this->sendError('ID de servicio requerido', 400);
            }
            
            if (!$this->verifyServiceOwnership($service_id)) {
                $this->sendError('Servicio no encontrado o sin permisos', 403);
            }
            
            // Verificar si tiene reservas activas
            $bookings_query = "SELECT COUNT(*) as active_bookings 
                              FROM bookings 
                              WHERE service_id = ? AND status IN ('confirmed', 'pending')";
            $stmt = $this->db->prepare($bookings_query);
            $booking_count = $stmt->execute([$service_id])->fetch()['active_bookings'];
            
            if ($booking_count > 0) {
                $this->sendError('No se puede eliminar. Tiene reservas activas.', 400);
            }
            
            // Iniciar transacción
            $this->db->beginTransaction();
            
            try {
                // Eliminar imágenes
                $this->deleteAllServiceImages($service_id);
                
                // Eliminar horarios
                $delete_schedules = "DELETE FROM service_schedules WHERE service_id = ?";
                $this->db->prepare($delete_schedules)->execute([$service_id]);
                
                // Eliminar amenities
                $delete_amenities = "DELETE FROM service_amenities WHERE service_id = ?";
                $this->db->prepare($delete_amenities)->execute([$service_id]);
                
                // Eliminar reseñas
                $delete_reviews = "DELETE FROM reviews WHERE service_id = ?";
                $this->db->prepare($delete_reviews)->execute([$service_id]);
                
                // Eliminar servicio
                $delete_service = "DELETE FROM services WHERE id = ?";
                $this->db->prepare($delete_service)->execute([$service_id]);
                
                $this->db->commit();
                
                $this->logActivity('service_deleted', $service_id, 'Servicio eliminado');
                
                $this->sendSuccess(['message' => 'Servicio eliminado exitosamente']);
                
            } catch (Exception $e) {
                $this->db->rollback();
                throw $e;
            }
            
        } catch (Exception $e) {
            $this->logError('Error en deleteService: ' . $e->getMessage());
            $this->sendError('Error al eliminar servicio', 500);
        }
    }
    
    /**
     * Subir imágenes del servicio
     */
    private function uploadServiceImages() {
        try {
            $service_id = (int)($_POST['service_id'] ?? 0);
            
            if (!$service_id) {
                $this->sendError('ID de servicio requerido', 400);
            }
            
            if (!$this->verifyServiceOwnership($service_id)) {
                $this->sendError('Servicio no encontrado o sin permisos', 403);
            }
            
            if (empty($_FILES['images'])) {
                $this->sendError('No se encontraron imágenes', 400);
            }
            
            $uploaded_images = [];
            $upload_dir = $this->config->get('upload_path') . '/services/';
            
            // Crear directorio si no existe
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $files = $_FILES['images'];
            $file_count = is_array($files['name']) ? count($files['name']) : 1;
            
            for ($i = 0; $i < $file_count; $i++) {
                $file_name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
                $file_tmp = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                $file_size = is_array($files['size']) ? $files['size'][$i] : $files['size'];
                $file_error = is_array($files['error']) ? $files['error'][$i] : $files['error'];
                
                // Validar archivo
                if ($file_error !== UPLOAD_ERR_OK) {
                    continue;
                }
                
                if ($file_size > 5 * 1024 * 1024) { // 5MB máximo
                    continue;
                }
                
                $file_info = pathinfo($file_name);
                $allowed_types = ['jpg', 'jpeg', 'png', 'webp'];
                
                if (!in_array(strtolower($file_info['extension']), $allowed_types)) {
                    continue;
                }
                
                // Generar nombre único
                $new_filename = uniqid() . '_' . $service_id . '.' . $file_info['extension'];
                $file_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($file_tmp, $file_path)) {
                    // Redimensionar imagen
                    $this->resizeImage($file_path, 1200, 800);
                    
                    // Guardar en base de datos
                    $insert_image = "INSERT INTO service_images (service_id, filename, original_name, size, created_at) 
                                    VALUES (?, ?, ?, ?, NOW())";
                    $stmt = $this->db->prepare($insert_image);
                    $stmt->execute([$service_id, $new_filename, $file_name, $file_size]);
                    
                    $uploaded_images[] = [
                        'id' => $this->db->lastInsertId(),
                        'filename' => $new_filename,
                        'url' => $this->config->get('base_url') . '/uploads/services/' . $new_filename
                    ];
                }
            }
            
            if (empty($uploaded_images)) {
                $this->sendError('No se pudieron subir las imágenes', 400);
            }
            
            $this->logActivity('service_images_uploaded', $service_id, count($uploaded_images) . ' imágenes subidas');
            
            $this->sendSuccess([
                'message' => 'Imágenes subidas exitosamente',
                'images' => $uploaded_images
            ]);
            
        } catch (Exception $e) {
            $this->logError('Error en uploadServiceImages: ' . $e->getMessage());
            $this->sendError('Error al subir imágenes', 500);
        }
    }
    
    /**
     * Eliminar imagen del servicio
     */
    private function deleteServiceImage() {
        try {
            $image_id = (int)($_GET['image_id'] ?? 0);
            
            if (!$image_id) {
                $this->sendError('ID de imagen requerido', 400);
            }
            
            // Verificar que la imagen pertenece a un servicio del usuario
            $query = "SELECT si.filename, si.service_id 
                     FROM service_images si
                     JOIN services s ON si.service_id = s.id
                     WHERE si.id = ? AND s.owner_id = ?";
            
            $stmt = $this->db->prepare($query);
            $image = $stmt->execute([$image_id, $this->user_id])->fetch();
            
            if (!$image) {
                $this->sendError('Imagen no encontrada', 404);
            }
            
            // Eliminar archivo físico
            $file_path = $this->config->get('upload_path') . '/services/' . $image['filename'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            
            // Eliminar de base de datos
            $delete_query = "DELETE FROM service_images WHERE id = ?";
            $stmt = $this->db->prepare($delete_query);
            $stmt->execute([$image_id]);
            
            $this->logActivity('service_image_deleted', $image['service_id'], 'Imagen eliminada');
            
            $this->sendSuccess(['message' => 'Imagen eliminada exitosamente']);
            
        } catch (Exception $e) {
            $this->logError('Error en deleteServiceImage: ' . $e->getMessage());
            $this->sendError('Error al eliminar imagen', 500);
        }
    }
    
    /**
     * Validar dirección usando API de geocodificación
     */
    private function validateAddress() {
        try {
            $input = $this->getInputData();
            $address = $input['address'] ?? '';
            
            if (empty($address)) {
                $this->sendError('Dirección requerida', 400);
            }
            
            // Usar API de geocodificación (ejemplo con OpenStreetMap Nominatim)
            $encoded_address = urlencode($address);
            $api_url = "https://nominatim.openstreetmap.org/search?format=json&q={$encoded_address}&limit=1";
            
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'user_agent' => 'ReservaFacil/1.0'
                ]
            ]);
            
            $response = file_get_contents($api_url, false, $context);
            
            if ($response === false) {
                $this->sendError('Error al validar dirección', 500);
            }
            
            $data = json_decode($response, true);
            
            if (empty($data)) {
                $this->sendError('Dirección no encontrada', 404);
            }
            
            $location = $data[0];
            
            $this->sendSuccess([
                'valid' => true,
                'formatted_address' => $location['display_name'],
                'latitude' => (float)$location['lat'],
                'longitude' => (float)$location['lon']
            ]);
            
        } catch (Exception $e) {
            $this->logError('Error en validateAddress: ' . $e->getMessage());
            $this->sendError('Error al validar dirección', 500);
        }
    }
    
    // MÉTODOS AUXILIARES PRIVADOS
    
    /**
     * Obtener imágenes de un servicio
     */
    private function getServiceImages($service_id) {
        $query = "SELECT id, filename, original_name, 
                         CONCAT(?, '/uploads/services/', filename) as url
                 FROM service_images 
                 WHERE service_id = ? 
                 ORDER BY created_at ASC";
        
        $stmt = $this->db->prepare($query);
        return $stmt->execute([$this->config->get('base_url'), $service_id])->fetchAll();
    }
    
    /**
     * Obtener horarios de un servicio
     */
    private function getServiceSchedule($service_id) {
        $query = "SELECT day_of_week, open_time, close_time, is_closed
                 FROM service_schedules 
                 WHERE service_id = ? 
                 ORDER BY day_of_week ASC";
        
        $stmt = $this->db->prepare($query);
        return $stmt->execute([$service_id])->fetchAll();
    }
    
    /**
     * Obtener amenities de un servicio
     */
    private function getServiceAmenities($service_id) {
        $query = "SELECT a.name, a.icon
                 FROM service_amenities sa
                 JOIN amenities a ON sa.amenity_id = a.id
                 WHERE sa.service_id = ?
                 ORDER BY a.name ASC";
        
        $stmt = $this->db->prepare($query);
        return $stmt->execute([$service_id])->fetchAll();
    }
    
    /**
     * Obtener reservas recientes
     */
    private function getRecentBookings($service_id, $limit = 5) {
        $query = "SELECT b.*, u.name as customer_name
                 FROM bookings b
                 JOIN users u ON b.user_id = u.id
                 WHERE b.service_id = ?
                 ORDER BY b.created_at DESC
                 LIMIT ?";
        
        $stmt = $this->db->prepare($query);
        return $stmt->execute([$service_id, $limit])->fetchAll();
    }
    
    /**
     * Obtener reseñas recientes
     */
    private function getRecentReviews($service_id, $limit = 5) {
        $query = "SELECT r.*, u.name as customer_name
                 FROM reviews r
                 JOIN users u ON r.user_id = u.id
                 WHERE r.service_id = ?
                 ORDER BY r.created_at DESC
                 LIMIT ?";
        
        $stmt = $this->db->prepare($query);
        return $stmt->execute([$service_id, $limit])->fetchAll();
    }
    
    /**
     * Verificar propiedad del servicio
     */
    private function verifyServiceOwnership($service_id) {
        $query = "SELECT COUNT(*) as count FROM services WHERE id = ? AND owner_id = ?";
        $stmt = $this->db->prepare($query);
        $result = $stmt->execute([$service_id, $this->user_id])->fetch();
        return $result['count'] > 0;
    }
    
    /**
     * Validar coordenadas geográficas
     */
    private function isValidCoordinate($lat, $lng) {
        return is_numeric($lat) && is_numeric($lng) && 
               $lat >= -90 && $lat <= 90 && 
               $lng >= -180 && $lng <= 180;
    }
    
    /**
     * Validar si existe la categoría
     */
    private function isValidCategory($category_id) {
        $query = "SELECT COUNT(*) as count FROM service_categories WHERE id = ? AND active = 1";
        $stmt = $this->db->prepare($query);
        $result = $stmt->execute([$category_id])->fetch();
        return $result['count'] > 0;
    }
    
    /**
     * Insertar horarios de servicio
     */
    private function insertServiceSchedule($service_id, $schedule) {
        $insert_query = "INSERT INTO service_schedules (service_id, day_of_week, open_time, close_time, is_closed) 
                        VALUES (?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($insert_query);
        
        foreach ($schedule as $day) {
            $stmt->execute([
                $service_id,
                $day['day_of_week'],
                $day['open_time'] ?? null,
                $day['close_time'] ?? null,
                $day['is_closed'] ?? false
            ]);
        }
    }
    
    /**
     * Insertar amenities de servicio
     */
    private function insertServiceAmenities($service_id, $amenities) {
        $insert_query = "INSERT INTO service_amenities (service_id, amenity_id) VALUES (?, ?)";
        $stmt = $this->db->prepare($insert_query);
        
        foreach ($amenities as $amenity_id) {
            $stmt->execute([$service_id, $amenity_id]);
        }
    }
    
    /**
     * Actualizar amenities de servicio
     */
    private function updateServiceAmenities($service_id, $amenities) {
        // Eliminar existentes
        $delete_query = "DELETE FROM service_amenities WHERE service_id = ?";
        $this->db->prepare($delete_query)->execute([$service_id]);
        
        // Insertar nuevos
        $this->insertServiceAmenities($service_id, $amenities);
    }
    
    /**
     * Cancelar reservas pendientes cuando se desactiva servicio
     */
    private function cancelPendingBookings($service_id) {
        $update_query = "UPDATE bookings 
                        SET status = 'cancelled', 
                            cancelled_at = NOW(), 
                            cancellation_reason = 'Servicio desactivado por el propietario'
                        WHERE service_id = ? AND status IN ('pending', 'confirmed')";
        
        $this->db->prepare($update_query)->execute([$service_id]);
    }
    
    /**
     * Eliminar todas las imágenes de un servicio
     */
    private function deleteAllServiceImages($service_id) {
        // Obtener archivos a eliminar
        $query = "SELECT filename FROM service_images WHERE service_id = ?";
        $stmt = $this->db->prepare($query);
        $images = $stmt->execute([$service_id])->fetchAll();
        
        // Eliminar archivos físicos del servidor
        $upload_dir = $this->config->get('upload_path') . '/services/';
        foreach ($images as $image) {
            $file_path = $upload_dir . $image['filename'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }
        
        // Eliminar registros de base de datos
        $delete_query = "DELETE FROM service_images WHERE service_id = ?";
        $this->db->prepare($delete_query)->execute([$service_id]);
    }
    
    /**
     * Redimensionar imagen manteniendo proporción
     */
    private function resizeImage($file_path, $max_width, $max_height) {
        $image_info = getimagesize($file_path);
        if (!$image_info) return false;
        
        list($width, $height, $type) = $image_info;
        
        if ($width <= $max_width && $height <= $max_height) {
            return true; // No necesita redimensionamiento
        }
        
        // Calcular nuevas dimensiones manteniendo proporción
        $ratio = min($max_width / $width, $max_height / $height);
        $new_width = (int)($width * $ratio);
        $new_height = (int)($height * $ratio);
        
        // Crear imagen origen según el tipo
        switch ($type) {
            case IMAGETYPE_JPEG:
                $source = imagecreatefromjpeg($file_path);
                break;
            case IMAGETYPE_PNG:
                $source = imagecreatefrompng($file_path);
                break;
            case IMAGETYPE_WEBP:
                $source = imagecreatefromwebp($file_path);
                break;
            default:
                return false;
        }
        
        if (!$source) return false;
        
        // Crear nueva imagen redimensionada
        $resized = imagecreatetruecolor($new_width, $new_height);
        
        // Preservar transparencia para PNG
        if ($type == IMAGETYPE_PNG) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }
        
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
        
        // Guardar imagen redimensionada
        switch ($type) {
            case IMAGETYPE_JPEG:
                imagejpeg($resized, $file_path, 85);
                break;
            case IMAGETYPE_PNG:
                imagepng($resized, $file_path, 8);
                break;
            case IMAGETYPE_WEBP:
                imagewebp($resized, $file_path, 85);
                break;
        }
        
        imagedestroy($source);
        imagedestroy($resized);
        
        return true;
    }
    
    /**
     * Obtener datos de entrada JSON
     */
    private function getInputData() {
        $input = file_get_contents('php://input');
        return json_decode($input, true) ?? [];
    }
    
    /**
     * Enviar respuesta exitosa en formato JSON
     */
    private function sendSuccess($data, $status_code = 200) {
        http_response_code($status_code);
        echo json_encode([
            'success' => true,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s'),
            'server_time' => time()
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * Enviar respuesta de error en formato JSON
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
     * Registrar actividad del usuario
     */
    private function logActivity($action, $service_id, $description) {
        try {
            $query = "INSERT INTO activity_logs (user_id, action, resource_type, resource_id, description, ip_address, user_agent, created_at) 
                     VALUES (?, ?, 'service', ?, ?, ?, ?, NOW())";
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                $this->user_id, 
                $action, 
                $service_id, 
                $description,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            // No fallar si el log falla
            error_log("Error logging activity: " . $e->getMessage());
        }
    }
    
    /**
     * Registrar errores del sistema
     */
    private function logError($message) {
        $log_message = "[SERVICES API] " . date('Y-m-d H:i:s') . " - USER_ID: {$this->user_id} - " . $message;
        error_log($log_message);
        
        // En producción, enviar a sistema de monitoreo externo
        if ($this->config->get('environment') === 'production') {
            // Integrar con Sentry, LogRocket, Rollbar, etc.
            // $this->sendToMonitoringService($message);
        }
    }
}

// Inicializar y procesar la solicitud
try {
    $services_manager = new ServicesManager();
    $services_manager->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error interno del servidor. Inténtalo más tarde.',
        'error_code' => 500,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    
    error_log("[SERVICES API FATAL ERROR] " . date('Y-m-d H:i:s') . " - " . $e->getMessage());
}
?>
<!-- 
====================================
SECCIÓN COMPLETADA: API de Gestión de Servicios - Sección Final Completa
ARCHIVO: api/services/services.php
LÍNEAS: 500
PROGRESO: ARCHIVO COMPLETADO EXITOSAMENTE
====================================
SEGUIR AQUÍ
====================================
-->