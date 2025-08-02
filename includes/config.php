<?php
/**
 * ====================================
 * ARCHIVO: includes/config.php
 * CONFIGURACIÓN PRINCIPAL DEL SISTEMA
 * ====================================
 */

// Configuración de errores para desarrollo
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Zona horaria
date_default_timezone_set('America/Argentina/Buenos_Aires');

// Constantes de rutas
define('ROOT_PATH', dirname(__DIR__));
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('API_PATH', ROOT_PATH . '/api');
define('LOGS_PATH', ROOT_PATH . '/storage/logs');
define('UPLOADS_PATH', ROOT_PATH . '/storage/uploads');

// Crear directorios si no existen
$directories = [LOGS_PATH, UPLOADS_PATH];
foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

/**
 * Configuración de Base de Datos
 */
class DatabaseConfig
{
    private static $connection = null;
    
    private static $config = [
        'host' => 'localhost',
        'dbname' => 'reservas_db',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    ];
    
    public static function getConnection()
    {
        if (self::$connection === null) {
            try {
                $dsn = "mysql:host=" . self::$config['host'] . ";dbname=" . self::$config['dbname'] . ";charset=" . self::$config['charset'];
                self::$connection = new PDO($dsn, self::$config['username'], self::$config['password'], self::$config['options']);
            } catch (PDOException $e) {
                throw new Exception('Error de conexión a base de datos: ' . $e->getMessage());
            }
        }
        return self::$connection;
    }
}

/**
 * Configuración de Entorno
 */
class EnvironmentConfig
{
    private static $instance = null;
    private $config = [];
    
    private function __construct()
    {
        $this->loadConfig();
    }
    
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function loadConfig()
    {
        $this->config = [
            'app' => [
                'name' => 'ReservaFácil',
                'url' => $this->getCurrentUrl(),
                'version' => '1.0.0',
                'timezone' => 'America/Argentina/Buenos_Aires',
                'locale' => 'es_AR',
                'debug' => $this->isLocalhost()
            ],
            'security' => [
                'jwt_secret' => 'your-super-secret-jwt-key-change-this-in-production',
                'jwt_expiry' => 3600, // 1 hora
                'jwt_refresh_expiry' => 604800, // 7 días
                'password_min_length' => 6,
                'max_login_attempts' => 5,
                'lockout_duration' => 900, // 15 minutos
                'session_lifetime' => 7200, // 2 horas
                'csrf_protection' => false, // Habilitar en producción
                'rate_limit_enabled' => true
            ],
            'business' => [
                'trial_days' => 30,
                'max_services_trial' => 5,
                'max_bookings_trial' => 50,
                'supported_countries' => ['AR'],
                'default_currency' => 'ARS',
                'default_language' => 'es'
            ],
            'email' => [
                'enabled' => true,
                'driver' => 'smtp', // smtp, mail, sendmail
                'host' => 'smtp.gmail.com',
                'port' => 587,
                'username' => '', // Tu email SMTP
                'password' => '', // Tu contraseña SMTP
                'encryption' => 'tls',
                'from_email' => 'noreply@reservafacil.com',
                'from_name' => 'ReservaFácil'
            ],
            'storage' => [
                'driver' => 'local',
                'max_file_size' => 5242880, // 5MB
                'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'pdf'],
                'upload_path' => UPLOADS_PATH
            ],
            'logs' => [
                'enabled' => true,
                'level' => 'info', // debug, info, warning, error
                'max_files' => 30,
                'path' => LOGS_PATH
            ],
            'api' => [
                'version' => 'v1',
                'base_url' => $this->getCurrentUrl() . '/api',
                'rate_limit' => 100, // requests per minute
                'cors_enabled' => true,
                'cors_origins' => ['*']
            ]
        ];
    }
    
    public function get($key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $k) {
            if (isset($value[$k])) {
                $value = $value[$k];
            } else {
                return $default;
            }
        }
        
        return $value;
    }
    
    private function getCurrentUrl()
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $protocol . $host;
    }
    
    private function isLocalhost()
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return in_array($host, ['localhost', '127.0.0.1', '::1']) || strpos($host, 'localhost:') === 0;
    }
}

/**
 * Función helper para obtener configuración
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function config($key, $default = null)
{
    return EnvironmentConfig::getInstance()->get($key, $default);
}

/**
 * Función helper para respuestas JSON
 * @param mixed $data
 * @param int $status
 */
function jsonResponse($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    // Headers CORS
    if (config('api.cors_enabled')) {
        $origins = config('api.cors_origins', ['*']);
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        if (in_array('*', $origins) || in_array($origin, $origins)) {
            header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
        }
        
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Allow-Credentials: true');
    }
    
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Función helper para respuestas de error
 * @param string $message
 * @param int $status
 * @param array $errors
 */
function errorResponse($message, $status = 400, $errors = [])
{
    $response = [
        'status' => 'error',
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s'),
        'server_time' => time()
    ];
    
    if (!empty($errors)) {
        $response['errors'] = $errors;
    }
    
    jsonResponse($response, $status);
}

/**
 * Función helper para logging
 * @param string $level
 * @param string $message
 * @param array $context
 */
function writeLog($level, $message, $context = [])
{
    if (!config('logs.enabled')) {
        return;
    }
    
    $logFile = LOGS_PATH . '/app_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    
    $logEntry = [
        'timestamp' => $timestamp,
        'level' => strtoupper($level),
        'message' => $message,
        'context' => $context,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];
    
    file_put_contents($logFile, json_encode($logEntry) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Autoloader simple para clases del sistema
 */
spl_autoload_register(function ($class) {
    $file = INCLUDES_PATH . '/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Incluir archivos esenciales
$essentialFiles = [
    INCLUDES_PATH . '/security_headers.php',
    INCLUDES_PATH . '/auth_middleware.php',
    INCLUDES_PATH . '/validation.php',
    INCLUDES_PATH . '/rate_limiter.php'
];

foreach ($essentialFiles as $file) {
    if (file_exists($file)) {
        require_once $file;
    }
}

// Configurar headers de seguridad por defecto
if (function_exists('setSecurityHeaders')) {
    setSecurityHeaders();
}

// Manejar preflight requests CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if (config('api.cors_enabled')) {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Max-Age: 86400');
    }
    http_response_code(200);
    exit;
}

/**
 * Exception personalizada para validación
 */
class ValidationException extends Exception
{
    private $errors;
    
    public function __construct($message, $code = 422, $errors = [])
    {
        parent::__construct($message, $code);
        $this->errors = $errors;
    }
    
    public function getErrors()
    {
        return $this->errors;
    }
}

/**
 * Logger básico del sistema
 */
class Logger
{
    private $context;
    
    public function __construct($context = 'app')
    {
        $this->context = $context;
    }
    
    public function info($message, $data = [])
    {
        $this->log('info', $message, $data);
    }
    
    public function warning($message, $data = [])
    {
        $this->log('warning', $message, $data);
    }
    
    public function error($message, $data = [])
    {
        $this->log('error', $message, $data);
    }
    
    public function debug($message, $data = [])
    {
        if (config('app.debug')) {
            $this->log('debug', $message, $data);
        }
    }
    
    private function log($level, $message, $data)
    {
        writeLog($level, "[{$this->context}] {$message}", $data);
    }
}

// Configuración completada
writeLog('info', 'Sistema de configuración inicializado', [
    'version' => config('app.version'),
    'debug' => config('app.debug'),
    'timezone' => config('app.timezone')
]);

?>