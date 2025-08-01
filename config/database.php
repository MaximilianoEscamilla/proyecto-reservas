<?php
/**
 * Configuración segura de base de datos
 * 
 * @author MaximilianoEscamilla
 * @version 2.0
 */

class DatabaseConfig {
    private static $instance = null;
    private $connection;
    
    // Configuración de BD (usar variables de entorno en producción)
    private const DB_CONFIG = [
        'host' => 'localhost',
        'dbname' => 'reservas_system',
        'username' => 'reservas_user', // Cambiar por usuario específico
        'password' => '', // Configurar contraseña fuerte
        'charset' => 'utf8mb4',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]
    ];
    
    private function __construct() {
        try {
            $dsn = sprintf(
                "mysql:host=%s;dbname=%s;charset=%s",
                self::DB_CONFIG['host'],
                self::DB_CONFIG['dbname'],
                self::DB_CONFIG['charset']
            );
            
            $this->connection = new PDO(
                $dsn,
                self::DB_CONFIG['username'],
                self::DB_CONFIG['password'],
                self::DB_CONFIG['options']
            );
            
        } catch (PDOException $e) {
            error_log("Error de conexión a BD: " . $e->getMessage());
            throw new Exception("Error de conexión a la base de datos");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    // Método seguro para consultas SELECT
    public function select($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error en SELECT: " . $e->getMessage());
            throw new Exception("Error al consultar datos");
        }
    }
    
    // Método seguro para consultas SELECT que retorna un solo registro
    public function selectOne($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Error en SELECT ONE: " . $e->getMessage());
            throw new Exception("Error al consultar datos");
        }
    }
    
    // Método seguro para INSERT
    public function insert($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $result = $stmt->execute($params);
            return $result ? $this->connection->lastInsertId() : false;
        } catch (PDOException $e) {
            error_log("Error en INSERT: " . $e->getMessage());
            throw new Exception("Error al insertar datos");
        }
    }
    
    // Método seguro para UPDATE
    public function update($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Error en UPDATE: " . $e->getMessage());
            throw new Exception("Error al actualizar datos");
        }
    }
    
    // Método seguro para DELETE
    public function delete($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Error en DELETE: " . $e->getMessage());
            throw new Exception("Error al eliminar datos");
        }
    }
    
    // Iniciar transacción
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
    
    // Confirmar transacción
    public function commit() {
        return $this->connection->commit();
    }
    
    // Revertir transacción
    public function rollback() {
        return $this->connection->rollback();
    }
    
    // Verificar si estamos en una transacción
    public function inTransaction() {
        return $this->connection->inTransaction();
    }
    
    // Ejecutar múltiples consultas en transacción
    public function transaction($callback) {
        try {
            $this->beginTransaction();
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Exception $e) {
            $this->rollback();
            throw $e;
        }
    }
    
    // Prevenir clonación
    private function __clone() {}
    
    // Prevenir deserialización
    public function __wakeup() {
        throw new Exception("No se puede deserializar un singleton");
    }
}

// Clase para manejar consultas específicas del sistema de reservas
class ReservasQuery {
    private $db;
    
    public function __construct() {
        $this->db = DatabaseConfig::getInstance();
    }
    
    // Obtener usuario por email (con prepared statement)
    public function getUserByEmail($email) {
        $sql = "SELECT id, email, password, role, status, created_at 
                FROM users 
                WHERE email = ? AND status = 'active'";
        return $this->db->selectOne($sql, [$email]);
    }
    
    // Crear nuevo usuario
    public function createUser($userData) {
        $sql = "INSERT INTO users (email, password, name, phone, role, status, created_at) 
                VALUES (?, ?, ?, ?, ?, 'active', NOW())";
        
        return $this->db->insert($sql, [
            $userData['email'],
            $userData['password'], // Ya debe venir hasheada
            $userData['name'],
            $userData['phone'],
            $userData['role'] ?? 'user'
        ]);
    }
    
    // Obtener reservas por usuario
    public function getUserReservations($userId, $limit = 10, $offset = 0) {
        $sql = "SELECT r.*, s.name as service_name, s.description as service_description
                FROM reservations r 
                JOIN services s ON r.service_id = s.id 
                WHERE r.user_id = ? 
                ORDER BY r.reservation_date DESC 
                LIMIT ? OFFSET ?";
        
        return $this->db->select($sql, [$userId, $limit, $offset]);
    }
    
    // Verificar disponibilidad
    public function checkAvailability($serviceId, $date, $time) {
        $sql = "SELECT COUNT(*) as count 
                FROM reservations 
                WHERE service_id = ? 
                AND reservation_date = ? 
                AND reservation_time = ? 
                AND status IN ('confirmed', 'pending')";
        
        $result = $this->db->selectOne($sql, [$serviceId, $date, $time]);
        return $result['count'] == 0;
    }
    
    // Crear reserva
    public function createReservation($reservationData) {
        // Verificar disponibilidad primero
        if (!$this->checkAvailability(
            $reservationData['service_id'], 
            $reservationData['reservation_date'], 
            $reservationData['reservation_time']
        )) {
            throw new Exception("El horario seleccionado no está disponible");
        }
        
        $sql = "INSERT INTO reservations 
                (user_id, service_id, reservation_date, reservation_time, guests, status, notes, created_at) 
                VALUES (?, ?, ?, ?, ?, 'pending', ?, NOW())";
        
        return $this->db->insert($sql, [
            $reservationData['user_id'],
            $reservationData['service_id'],
            $reservationData['reservation_date'],
            $reservationData['reservation_time'],
            $reservationData['guests'],
            $reservationData['notes'] ?? ''
        ]);
    }
}
?>