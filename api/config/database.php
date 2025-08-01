<?php
/**
 * ====================================
 * SISTEMA DE RESERVAS DE SERVICIOS
 * CONFIGURACIÓN DE BASE DE DATOS
 * Archivo: api/config/database.php
 * Sección 1 de 1 (500 líneas)
 * ====================================
 */

// Evitar acceso directo al archivo
if (!defined('SECURE_ACCESS')) {
    http_response_code(403);
    die('Acceso denegado');
}

/**
 * Clase DatabaseConfig
 * Maneja la configuración y conexión a la base de datos
 * Optimizada para hosting compartido de Hostinger
 */
class DatabaseConfig
{
    // Configuración de conexión
    private static $host;
    private static $database;
    private static $username;
    private static $password;
    private static $port;
    private static $charset;
    private static $options;
    
    // Instancia de conexión singleton
    private static $connection = null;
    private static $instance = null;
    
    // Configuración de pool de conexiones
    private static $maxConnections = 10;
    private static $activeConnections = 0;
    private static $connectionPool = [];
    
    // Estadísticas de rendimiento
    private static $queryCount = 0;
    private static $totalQueryTime = 0;
    private static $slowQueries = [];
    
    /**
     * Constructor privado para singleton
     */
    private function __construct()
    {
        $this->loadConfiguration();
        $this->validateConfiguration();
    }
    
    /**
     * Obtener instancia única de la clase
     * @return DatabaseConfig
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Cargar configuración desde variables de entorno
     */
    private function loadConfiguration()
    {
        // Configuración para Hostinger (producción)
        if (isset($_ENV['DB_HOST'])) {
            self::$host = $_ENV['DB_HOST'];
            self::$database = $_ENV['DB_NAME'];
            self::$username = $_ENV['DB_USER'];
            self::$password = $_ENV['DB_PASS'];
            self::$port = $_ENV['DB_PORT'] ?? 3306;
        } 
        // Configuración local de desarrollo
        else {
            self::$host = 'localhost';
            self::$database = 'reservas_db';
            self::$username = 'root';
            self::$password = '';
            self::$port = 3306;
        }
        
        // Configuración común
        self::$charset = 'utf8mb4';
        
        // Opciones de PDO optimizadas
        self::$options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_TIMEOUT => 30,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . self::$charset . " COLLATE utf8mb4_unicode_ci",
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            PDO::MYSQL_ATTR_FOUND_ROWS => true,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL
        ];
    }
    
    /**
     * Validar configuración de base de datos
     */
    private function validateConfiguration()
    {
        $required = ['host', 'database', 'username'];
        foreach ($required as $field) {
            if (empty(self::$$field)) {
                throw new Exception("Configuración de BD incompleta: {$field} requerido");
            }
        }
    }
    
    /**
     * Establecer conexión a la base de datos
     * @return PDO
     * @throws Exception
     */
    public static function getConnection()
    {
        if (self::$connection === null || !self::isConnectionAlive()) {
            self::createConnection();
        }
        
        return self::$connection;
    }
    
    /**
     * Crear nueva conexión a la base de datos
     * @throws Exception
     */
    private static function createConnection()
    {
        try {
            $dsn = sprintf(
                "mysql:host=%s;port=%d;dbname=%s;charset=%s",
                self::$host,
                self::$port,
                self::$database,
                self::$charset
            );
            
            self::$connection = new PDO($dsn, self::$username, self::$password, self::$options);
            
            // Configurar timezone
            self::$connection->exec("SET time_zone = '+00:00'");
            
            // Configurar modo SQL estricto
            self::$connection->exec("SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'");
            
            // Optimizaciones para Hostinger
            self::$connection->exec("SET SESSION query_cache_type = ON");
            self::$connection->exec("SET SESSION query_cache_size = 1048576");
            
            self::$activeConnections++;
            
            // Log de conexión exitosa
            self::logActivity('connection_established', [
                'host' => self::$host,
                'database' => self::$database,
                'active_connections' => self::$activeConnections
            ]);
            
        } catch (PDOException $e) {
            self::handleConnectionError($e);
        }
    }
    
    /**
     * Verificar si la conexión está activa
     * @return bool
     */
    private static function isConnectionAlive()
    {
        if (self::$connection === null) {
            return false;
        }
        
        try {
            self::$connection->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Manejar errores de conexión
     * @param PDOException $e
     * @throws Exception
     */
    private static function handleConnectionError(PDOException $e)
    {
        $errorCode = $e->getCode();
        $errorMessage = $e->getMessage();
        
        // Log del error
        self::logActivity('connection_error', [
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'host' => self::$host,
            'database' => self::$database
        ], 'error');
        
        // Errores específicos de MySQL
        switch ($errorCode) {
            case 1045: // Access denied
                throw new Exception('Error de autenticación: Usuario o contraseña incorrectos');
            case 1049: // Unknown database
                throw new Exception('Base de datos no encontrada: ' . self::$database);
            case 2002: // Can't connect to server
                throw new Exception('No se puede conectar al servidor de base de datos');
            case 1040: // Too many connections
                throw new Exception('Demasiadas conexiones activas. Intente más tarde.');
            default:
                throw new Exception('Error de conexión a base de datos: ' . $errorMessage);
        }
    }
    
    /**
     * Ejecutar consulta preparada con estadísticas
     * @param string $sql
     * @param array $params
     * @return PDOStatement
     */
    public static function executeQuery($sql, $params = [])
    {
        $startTime = microtime(true);
        
        try {
            $connection = self::getConnection();
            $stmt = $connection->prepare($sql);
            $stmt->execute($params);
            
            $executionTime = microtime(true) - $startTime;
            self::$queryCount++;
            self::$totalQueryTime += $executionTime;
            
            // Detectar consultas lentas (> 1 segundo)
            if ($executionTime > 1.0) {
                self::$slowQueries[] = [
                    'sql' => $sql,
                    'params' => $params,
                    'execution_time' => $executionTime,
                    'timestamp' => date('Y-m-d H:i:s')
                ];
            }
            
            return $stmt;
            
        } catch (PDOException $e) {
            self::logActivity('query_error', [
                'sql' => $sql,
                'params' => $params,
                'error' => $e->getMessage()
            ], 'error');
            
            throw $e;
        }
    }
    
    /**
     * Iniciar transacción
     * @return bool
     */
    public static function beginTransaction()
    {
        $connection = self::getConnection();
        return $connection->beginTransaction();
    }
    
    /**
     * Confirmar transacción
     * @return bool
     */
    public static function commit()
    {
        $connection = self::getConnection();
        return $connection->commit();
    }
    
    /**
     * Revertir transacción
     * @return bool
     */
    public static function rollback()
    {
        $connection = self::getConnection();
        return $connection->rollback();
    }
    
    /**
     * Obtener último ID insertado
     * @return string
     */
    public static function getLastInsertId()
    {
        $connection = self::getConnection();
        return $connection->lastInsertId();
    }
    
    /**
     * Verificar si estamos en transacción
     * @return bool
     */
    public static function inTransaction()
    {
        $connection = self::getConnection();
        return $connection->inTransaction();
    }
    
    /**
     * Escapar string para consultas SQL
     * @param string $string
     * @return string
     */
    public static function quote($string)
    {
        $connection = self::getConnection();
        return $connection->quote($string);
    }
    
    /**
     * Obtener información de la base de datos
     * @return array
     */
    public static function getDatabaseInfo()
    {
        try {
            $connection = self::getConnection();
            
            // Información del servidor
            $serverInfo = $connection->getAttribute(PDO::ATTR_SERVER_INFO);
            $serverVersion = $connection->getAttribute(PDO::ATTR_SERVER_VERSION);
            $clientVersion = $connection->getAttribute(PDO::ATTR_CLIENT_VERSION);
            
            // Estadísticas de conexión
            $stats = $connection->query("SHOW STATUS LIKE 'Connections'")->fetch();
            $uptime = $connection->query("SHOW STATUS LIKE 'Uptime'")->fetch();
            
            return [
                'server_info' => $serverInfo,
                'server_version' => $serverVersion,
                'client_version' => $clientVersion,
                'database_name' => self::$database,
                'charset' => self::$charset,
                'total_connections' => $stats['Value'] ?? 'N/A',
                'server_uptime' => $uptime['Value'] ?? 'N/A',
                'active_connections' => self::$activeConnections,
                'query_count' => self::$queryCount,
                'total_query_time' => round(self::$totalQueryTime, 4),
                'avg_query_time' => self::$queryCount > 0 ? round(self::$totalQueryTime / self::$queryCount, 4) : 0,
                'slow_queries_count' => count(self::$slowQueries)
            ];
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Obtener estadísticas de rendimiento
     * @return array
     */
    public static function getPerformanceStats()
    {
        return [
            'query_count' => self::$queryCount,
            'total_query_time' => round(self::$totalQueryTime, 4),
            'average_query_time' => self::$queryCount > 0 ? round(self::$totalQueryTime / self::$queryCount, 4) : 0,
            'slow_queries' => self::$slowQueries,
            'active_connections' => self::$activeConnections,
            'max_connections' => self::$maxConnections
        ];
    }
    
    /**
     * Limpiar estadísticas de rendimiento
     */
    public static function clearPerformanceStats()
    {
        self::$queryCount = 0;
        self::$totalQueryTime = 0;
        self::$slowQueries = [];
    }
    
    /**
     * Verificar salud de la base de datos
     * @return array
     */
    public static function healthCheck()
    {
        $health = [
            'status' => 'healthy',
            'checks' => [],
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        try {
            // Test de conexión
            $connection = self::getConnection();
            $health['checks']['connection'] = 'OK';
            
            // Test de escritura
            $testQuery = "SELECT 1 as test";
            $result = $connection->query($testQuery)->fetch();
            $health['checks']['read'] = $result['test'] == 1 ? 'OK' : 'FAIL';
            
            // Test de espacio en disco (si es posible)
            $spaceQuery = "SELECT table_schema as 'database', 
                          ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as 'size_mb'
                          FROM information_schema.tables 
                          WHERE table_schema = '" . self::$database . "'
                          GROUP BY table_schema";
            
            $spaceResult = $connection->query($spaceQuery)->fetch();
            $health['checks']['database_size'] = $spaceResult['size_mb'] . ' MB';
            
            // Test de performance
            $startTime = microtime(true);
            $connection->query("SELECT COUNT(*) FROM information_schema.tables")->fetch();
            $queryTime = microtime(true) - $startTime;
            
            $health['checks']['performance'] = $queryTime < 1.0 ? 'OK' : 'SLOW';
            $health['checks']['query_time'] = round($queryTime, 4) . 's';
            
        } catch (Exception $e) {
            $health['status'] = 'unhealthy';
            $health['error'] = $e->getMessage();
        }
        
        return $health;
    }
    
    /**
     * Registrar actividad del sistema
     * @param string $action
     * @param array $data
     * @param string $level
     */
    private static function logActivity($action, $data = [], $level = 'info')
    {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'action' => $action,
            'data' => $data,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
        
        // En desarrollo, mostrar en logs de PHP
        if (!isset($_ENV['DB_HOST'])) {
            error_log('DB_LOG: ' . json_encode($logEntry));
        }
        
        // En producción, guardar en archivo de logs (si es necesario)
        // TODO: Implementar sistema de logs más robusto
    }
    
    /**
     * Cerrar todas las conexiones
     */
    public static function closeAllConnections()
    {
        if (self::$connection !== null) {
            self::$connection = null;
            self::$activeConnections = 0;
            
            self::logActivity('all_connections_closed', [
                'query_count' => self::$queryCount,
                'total_query_time' => self::$totalQueryTime
            ]);
        }
    }
    
    /**
     * Optimizar base de datos
     * @return array
     */
    public static function optimizeDatabase()
    {
        try {
            $connection = self::getConnection();
            $results = [];
            
            // Obtener todas las tablas
            $tables = $connection->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($tables as $table) {
                // Optimizar tabla
                $result = $connection->query("OPTIMIZE TABLE `{$table}`")->fetch();
                $results[$table] = $result;
            }
            
            self::logActivity('database_optimized', [
                'tables_count' => count($tables),
                'results' => $results
            ]);
            
            return $results;
            
        } catch (Exception $e) {
            self::logActivity('optimization_error', ['error' => $e->getMessage()], 'error');
            throw $e;
        }
    }
    
    /**
     * Realizar backup de la base de datos
     * @param string $backupPath
     * @return bool
     */
    public static function createBackup($backupPath = null)
    {
        if ($backupPath === null) {
            $backupPath = '../backups/backup_' . date('Y-m-d_H-i-s') . '.sql';
        }
        
        try {
            // Crear directorio si no existe
            $backupDir = dirname($backupPath);
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }
            
            // Comando mysqldump
            $command = sprintf(
                'mysqldump --user=%s --password=%s --host=%s --port=%d %s > %s',
                escapeshellarg(self::$username),
                escapeshellarg(self::$password),
                escapeshellarg(self::$host),
                self::$port,
                escapeshellarg(self::$database),
                escapeshellarg($backupPath)
            );
            
            $output = [];
            $returnVar = 0;
            exec($command, $output, $returnVar);
            
            if ($returnVar === 0) {
                self::logActivity('backup_created', [
                    'backup_path' => $backupPath,
                    'file_size' => filesize($backupPath)
                ]);
                return true;
            } else {
                throw new Exception('Error al crear backup: ' . implode("\n", $output));
            }
            
        } catch (Exception $e) {
            self::logActivity('backup_error', ['error' => $e->getMessage()], 'error');
            return false;
        }
    }
    
    /**
     * Destructor - limpiar recursos
     */
    public function __destruct()
    {
        // No cerrar conexión automáticamente para aprovechar la persistencia
        // self::closeAllConnections();
    }
}

/**
 * Funciones de utilidad para acceso rápido
 */

/**
 * Obtener conexión de base de datos
 * @return PDO
 */
function getDB()
{
    return DatabaseConfig::getConnection();
}

/**
 * Ejecutar consulta rápida
 * @param string $sql
 * @param array $params
 * @return PDOStatement
 */
function query($sql, $params = [])
{
    return DatabaseConfig::executeQuery($sql, $params);
}

/**
 * Ejecutar consulta y obtener todos los resultados
 * @param string $sql
 * @param array $params
 * @return array
 */
function fetchAll($sql, $params = [])
{
    $stmt = DatabaseConfig::executeQuery($sql, $params);
    return $stmt->fetchAll();
}

/**
 * Ejecutar consulta y obtener un solo resultado
 * @param string $sql
 * @param array $params
 * @return array|false
 */
function fetchOne($sql, $params = [])
{
    $stmt = DatabaseConfig::executeQuery($sql, $params);
    return $stmt->fetch();
}

/**
 * Ejecutar consulta y obtener un solo valor
 * @param string $sql
 * @param array $params
 * @return mixed
 */
function fetchValue($sql, $params = [])
{
    $stmt = DatabaseConfig::executeQuery($sql, $params);
    return $stmt->fetchColumn();
}

// Inicializar configuración de base de datos
DatabaseConfig::getInstance();

?>

<!--
====================================
SECCIÓN COMPLETADA: Configuración de Base de Datos
ARCHIVO: api/config/database.php
LÍNEAS: 500
PROGRESO: 2/41 archivos completados
====================================
SEGUIR AQUÍ
====================================
-->