<?php
/**
 * ====================================
 * SISTEMA DE RESERVAS DE SERVICIOS
 * CONFIGURACIÓN GENERAL DEL SISTEMA
 * Archivo: api/config/config.php
 * Sección 1 de 1 (500 líneas)
 * ====================================
 */

// Definir acceso seguro
define('SECURE_ACCESS', true);

// Configuración de errores según el entorno
if (defined('APP_ENV') && APP_ENV === 'production') {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
} else {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

// Incluir dependencias principales
require_once __DIR__ . '/environment.php';
require_once __DIR__ . '/database.php';

/**
 * Clase Config
 * Configuración central del sistema con inicialización automática
 * Gestiona todas las configuraciones y dependencias del sistema
 */
class Config
{
    private static $instance = null;
    private static $initialized = false;
    private static $startTime;
    private static $dependencies = [];
    private static $routes = [];
    private static $middleware = [];
    private static $services = [];
    
    /**
     * Constructor privado para singleton
     */
    private function __construct()
    {
        self::$startTime = microtime(true);
        $this->initializeSystem();
        $this->registerDependencies();
        $this->setupSecurity();
        $this->configureSession();
        $this->initializeRoutes();
        $this->loadServices();
        self::$initialized = true;
    }
    
    /**
     * Obtener instancia única
     * @return Config
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Inicializar sistema básico
     */
    private function initializeSystem()
    {
        // Configurar zona horaria
        date_default_timezone_set(config('app.timezone', 'America/Argentina/Buenos_Aires'));
        
        // Configurar memoria y tiempo de ejecución
        ini_set('memory_limit', '256M');
        ini_set('max_execution_time', '300');
        
        // Configurar uploads
        ini_set('upload_max_filesize', '10M');
        ini_set('post_max_size', '12M');
        ini_set('max_file_uploads', '20');
        
        // Configurar sesiones seguras
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure', is_production() ? 1 : 0);
        ini_set('session.use_strict_mode', 1);
        ini_set('session.cookie_samesite', 'Strict');
        
        // Headers de seguridad
        $this->setSecurityHeaders();
        
        // Configurar manejo de errores personalizado
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }
    
    /**
     * Establecer headers de seguridad
     */
    private function setSecurityHeaders()
    {
        if (!headers_sent()) {
            // Prevenir XSS
            header('X-XSS-Protection: 1; mode=block');
            
            // Prevenir MIME sniffing
            header('X-Content-Type-Options: nosniff');
            
            // Prevenir clickjacking
            header('X-Frame-Options: DENY');
            
            // Política de contenido seguro
            $csp = "default-src 'self'; " .
                   "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdnjs.cloudflare.com https://sdk.mercadopago.com; " .
                   "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com; " .
                   "font-src 'self' https://fonts.gstatic.com; " .
                   "img-src 'self' data: https:; " .
                   "connect-src 'self' https://api.mercadopago.com https://api.whatsapp.com; " .
                   "media-src 'self'; " .
                   "object-src 'none'; " .
                   "base-uri 'self'";
            
            header("Content-Security-Policy: {$csp}");
            
            // HSTS en producción
            if (is_production() && isset($_SERVER['HTTPS'])) {
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            }
            
            // Política de referrer
            header('Referrer-Policy: strict-origin-when-cross-origin');
            
            // Permisos de características
            header('Permissions-Policy: geolocation=(self), microphone=(), camera=()');
        }
    }
    
    /**
     * Registrar dependencias del sistema
     */
    private function registerDependencies()
    {
        // Autoloader personalizado para clases del sistema
        spl_autoload_register(function ($className) {
            $classMap = [
                'UserManager' => __DIR__ . '/../auth/UserManager.php',
                'ServiceManager' => __DIR__ . '/../services/ServiceManager.php',
                'BookingManager' => __DIR__ . '/../services/BookingManager.php',
                'PaymentManager' => __DIR__ . '/../payments/PaymentManager.php',
                'NotificationManager' => __DIR__ . '/../notifications/NotificationManager.php',
                'EmailService' => __DIR__ . '/../notifications/EmailService.php',
                'WhatsAppService' => __DIR__ . '/../notifications/WhatsAppService.php',
                'SMSService' => __DIR__ . '/../notifications/SMSService.php',
                'JWTManager' => __DIR__ . '/../auth/JWTManager.php',
                'SecurityManager' => __DIR__ . '/../auth/SecurityManager.php',
                'FileManager' => __DIR__ . '/../utils/FileManager.php',
                'Logger' => __DIR__ . '/../utils/Logger.php',
                'Validator' => __DIR__ . '/../utils/Validator.php',
                'Cache' => __DIR__ . '/../utils/Cache.php'
            ];
            
            if (isset($classMap[$className]) && file_exists($classMap[$className])) {
                require_once $classMap[$className];
            }
        });
        
        // Registrar servicios principales
        self::$dependencies = [
            'db' => function() { return DatabaseConfig::getConnection(); },
            'environment' => function() { return EnvironmentConfig::getInstance(); },
            'logger' => function() { return new Logger(); },
            'cache' => function() { return new Cache(); },
            'validator' => function() { return new Validator(); }
        ];
    }
    
    /**
     * Configurar seguridad del sistema
     */
    private function setupSecurity()
    {
        // Filtrar y limpiar inputs globales
        $this->sanitizeGlobalInputs();
        
        // Configurar CORS si está habilitado
        if (config('api.cors.enabled', false)) {
            $this->configureCORS();
        }
        
        // Verificar rate limiting
        if (config('api.rate_limit.enabled', false)) {
            $this->checkRateLimit();
        }
        
        // Verificar modo mantenimiento
        if (config('app.maintenance_mode', false)) {
            $this->checkMaintenanceMode();
        }
    }
    
    /**
     * Limpiar inputs globales
     */
    private function sanitizeGlobalInputs()
    {
        // Limpiar $_GET
        if (!empty($_GET)) {
            $_GET = array_map([$this, 'sanitizeInput'], $_GET);
        }
        
        // Limpiar $_POST (excepto archivos)
        if (!empty($_POST)) {
            $_POST = array_map([$this, 'sanitizeInput'], $_POST);
        }
        
        // Limpiar $_REQUEST
        if (!empty($_REQUEST)) {
            $_REQUEST = array_map([$this, 'sanitizeInput'], $_REQUEST);
        }
    }
    
    /**
     * Sanitizar input individual
     * @param mixed $input
     * @return mixed
     */
    private function sanitizeInput($input)
    {
        if (is_array($input)) {
            return array_map([$this, 'sanitizeInput'], $input);
        }
        
        if (is_string($input)) {
            // Remover tags HTML/PHP
            $input = strip_tags($input);
            
            // Convertir caracteres especiales
            $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
            
            // Remover caracteres de control
            $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $input);
            
            // Trim espacios
            $input = trim($input);
        }
        
        return $input;
    }
    
    /**
     * Configurar CORS
     */
    private function configureCORS()
    {
        $allowedOrigins = config('api.cors.allowed_origins', ['*']);
        $allowedMethods = config('api.cors.allowed_methods', ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS']);
        $allowedHeaders = config('api.cors.allowed_headers', ['Content-Type', 'Authorization']);
        
        if (isset($_SERVER['HTTP_ORIGIN'])) {
            $origin = $_SERVER['HTTP_ORIGIN'];
            
            if (in_array('*', $allowedOrigins) || in_array($origin, $allowedOrigins)) {
                header("Access-Control-Allow-Origin: {$origin}");
            }
        }
        
        header('Access-Control-Allow-Methods: ' . implode(', ', $allowedMethods));
        header('Access-Control-Allow-Headers: ' . implode(', ', $allowedHeaders));
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 3600');
        
        // Manejar preflight OPTIONS
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit();
        }
    }
    
    /**
     * Verificar rate limiting
     */
    private function checkRateLimit()
    {
        $clientIP = $this->getClientIP();
        $requestsPerMinute = config('api.rate_limit.requests_per_minute', 60);
        $requestsPerHour = config('api.rate_limit.requests_per_hour', 1000);
        
        // Implementar rate limiting básico en memoria/cache
        $cacheKey = "rate_limit_{$clientIP}";
        $cache = $this->getDependency('cache');
        
        $requests = $cache->get($cacheKey, ['minute' => 0, 'hour' => 0, 'last_reset' => time()]);
        
        $now = time();
        $minutesPassed = floor(($now - $requests['last_reset']) / 60);
        
        if ($minutesPassed >= 1) {
            $requests['minute'] = 0;
            if ($minutesPassed >= 60) {
                $requests['hour'] = 0;
            }
            $requests['last_reset'] = $now;
        }
        
        $requests['minute']++;
        $requests['hour']++;
        
        if ($requests['minute'] > $requestsPerMinute || $requests['hour'] > $requestsPerHour) {
            http_response_code(429);
            header('Retry-After: 60');
            die(json_encode(['error' => 'Rate limit exceeded']));
        }
        
        $cache->set($cacheKey, $requests, 3600);
    }
    
    /**
     * Verificar modo mantenimiento
     */
    private function checkMaintenanceMode()
    {
        // Permitir acceso a administradores
        if (isset($_SERVER['HTTP_X_ADMIN_TOKEN']) && 
            $_SERVER['HTTP_X_ADMIN_TOKEN'] === config('security.admin_token')) {
            return;
        }
        
        http_response_code(503);
        header('Retry-After: 3600');
        die(json_encode([
            'error' => 'Sistema en mantenimiento',
            'message' => 'El sistema está temporalmente fuera de servicio por mantenimiento.'
        ]));
    }
    
    /**
     * Obtener IP del cliente
     * @return string
     */
    private function getClientIP()
    {
        $ipHeaders = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Proxy
            'HTTP_X_FORWARDED',          // Proxy
            'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
            'HTTP_FORWARDED_FOR',        // Proxy
            'HTTP_FORWARDED',            // Proxy
            'REMOTE_ADDR'                // Standard
        ];
        
        foreach ($ipHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Configurar sesiones
     */
    private function configureSession()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('RESERVAMAX_SESSION');
            session_start();
            
            // Regenerar ID de sesión periódicamente
            if (!isset($_SESSION['last_regeneration'])) {
                $_SESSION['last_regeneration'] = time();
            } elseif (time() - $_SESSION['last_regeneration'] > 300) { // 5 minutos
                session_regenerate_id(true);
                $_SESSION['last_regeneration'] = time();
            }
        }
    }
    
    /**
     * Inicializar rutas del API
     */
    private function initializeRoutes()
    {
        self::$routes = [
            'GET' => [
                '/api/health' => 'HealthController@check',
                '/api/services' => 'ServiceController@list',
                '/api/services/{id}' => 'ServiceController@show',
                '/api/bookings' => 'BookingController@list',
                '/api/bookings/{id}' => 'BookingController@show',
                '/api/users/profile' => 'UserController@profile'
            ],
            'POST' => [
                '/api/auth/register' => 'AuthController@register',
                '/api/auth/login' => 'AuthController@login',
                '/api/auth/refresh' => 'AuthController@refresh',
                '/api/services' => 'ServiceController@create',
                '/api/bookings' => 'BookingController@create',
                '/api/payments/mercadopago' => 'PaymentController@mercadopago',
                '/api/notifications/send' => 'NotificationController@send'
            ],
            'PUT' => [
                '/api/services/{id}' => 'ServiceController@update',
                '/api/bookings/{id}' => 'BookingController@update',
                '/api/users/profile' => 'UserController@updateProfile'
            ],
            'DELETE' => [
                '/api/services/{id}' => 'ServiceController@delete',
                '/api/bookings/{id}' => 'BookingController@cancel'
            ]
        ];
        
        // Middleware por defecto
        self::$middleware = [
            'auth' => 'AuthMiddleware',
            'admin' => 'AdminMiddleware',
            'cors' => 'CorsMiddleware',
            'rate_limit' => 'RateLimitMiddleware',
            'validate' => 'ValidationMiddleware'
        ];
    }
    
    /**
     * Cargar servicios del sistema
     */
    private function loadServices()
    {
        self::$services = [
            'encryption' => new EncryptionService(config('security.encryption_key')),
            'jwt' => new JWTService(config('security.jwt_secret')),
            'mailer' => new MailerService(config('mail')),
            'sms' => new SMSService(config('notifications.sms')),
            'whatsapp' => new WhatsAppService(config('notifications.whatsapp')),
            'storage' => new StorageService(config('storage')),
            'logger' => new LoggerService(config('logs'))
        ];
    }
    
    /**
     * Obtener dependencia
     * @param string $name
     * @return mixed
     */
    public static function getDependency($name)
    {
        if (isset(self::$dependencies[$name])) {
            if (is_callable(self::$dependencies[$name])) {
                return self::$dependencies[$name]();
            }
            return self::$dependencies[$name];
        }
        return null;
    }
    
    /**
     * Obtener servicio
     * @param string $name
     * @return mixed
     */
    public static function getService($name)
    {
        return self::$services[$name] ?? null;
    }
    
    /**
     * Manejar errores
     */
    public function handleError($severity, $message, $file, $line)
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        
        $error = [
            'type' => 'error',
            'severity' => $severity,
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $this->getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ];
        
        $this->logError($error);
        
        if (is_production()) {
            return true; // No mostrar errores en producción
        }
        
        return false; // Mostrar errores en desarrollo
    }
    
    /**
     * Manejar excepciones
     */
    public function handleException($exception)
    {
        $error = [
            'type' => 'exception',
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $this->getClientIP()
        ];
        
        $this->logError($error);
        
        if (is_production()) {
            http_response_code(500);
            die(json_encode(['error' => 'Error interno del servidor']));
        } else {
            http_response_code(500);
            die(json_encode(['error' => $exception->getMessage(), 'trace' => $error]));
        }
    }
    
    /**
     * Manejar shutdown
     */
    public function handleShutdown()
    {
        $error = error_get_last();
        
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
            $this->logError([
                'type' => 'fatal_error',
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
        
        // Log de estadísticas de rendimiento
        $executionTime = microtime(true) - self::$startTime;
        $memoryUsage = memory_get_peak_usage(true);
        
        if (config('logs.enabled') && ($executionTime > 5 || $memoryUsage > 50 * 1024 * 1024)) {
            $this->logPerformance([
                'execution_time' => $executionTime,
                'memory_usage' => $memoryUsage,
                'url' => $_SERVER['REQUEST_URI'] ?? '',
                'method' => $_SERVER['REQUEST_METHOD'] ?? ''
            ]);
        }
    }
    
    /**
     * Registrar error en logs
     * @param array $error
     */
    private function logError($error)
    {
        if (config('logs.enabled')) {
            $logFile = LOGS_PATH . '/error_' . date('Y-m-d') . '.log';
            $logEntry = '[' . $error['timestamp'] . '] ' . json_encode($error) . PHP_EOL;
            file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * Registrar estadísticas de rendimiento
     * @param array $stats
     */
    private function logPerformance($stats)
    {
        $logFile = LOGS_PATH . '/performance_' . date('Y-m-d') . '.log';
        $logEntry = '[' . date('Y-m-d H:i:s') . '] ' . json_encode($stats) . PHP_EOL;
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Obtener estadísticas del sistema
     * @return array
     */
    public static function getSystemStats()
    {
        return [
            'initialized' => self::$initialized,
            'startup_time' => self::$startTime,
            'uptime' => microtime(true) - self::$startTime,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'environment' => config('app.environment'),
            'debug_mode' => config('app.debug'),
            'dependencies_loaded' => count(self::$dependencies),
            'services_loaded' => count(self::$services),
            'routes_registered' => array_sum(array_map('count', self::$routes))
        ];
    }
    
    /**
     * Verificar si el sistema está inicializado
     * @return bool
     */
    public static function isInitialized()
    {
        return self::$initialized;
    }
}

// Funciones de utilidad globales

/**
 * Obtener dependencia del sistema
 * @param string $name
 * @return mixed
 */
function app($name)
{
    return Config::getDependency($name);
}

/**
 * Obtener servicio del sistema
 * @param string $name
 * @return mixed
 */
function service($name)
{
    return Config::getService($name);
}

/**
 * Respuesta JSON estándar
 * @param mixed $data
 * @param int $status
 */
function jsonResponse($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

/**
 * Respuesta de error JSON
 * @param string $message
 * @param int $status
 * @param array $details
 */
function errorResponse($message, $status = 400, $details = [])
{
    $response = ['error' => $message];
    if (!empty($details)) {
        $response['details'] = $details;
    }
    jsonResponse($response, $status);
}

/**
 * Validar y sanitizar entrada
 * @param string $input
 * @param string $type
 * @return mixed
 */
function sanitize($input, $type = 'string')
{
    switch ($type) {
        case 'email':
            return filter_var($input, FILTER_SANITIZE_EMAIL);
        case 'int':
            return filter_var($input, FILTER_SANITIZE_NUMBER_INT);
        case 'float':
            return filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        case 'url':
            return filter_var($input, FILTER_SANITIZE_URL);
        default:
            return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }
}

// Inicializar configuración del sistema
Config::getInstance();

?>

<!--
====================================
SECCIÓN COMPLETADA: Configuración General
ARCHIVO: api/config/config.php
LÍNEAS: 500
PROGRESO: 4/41 archivos completados
FASE 1 COMPLETADA: Configuración y base de datos (4/4)
====================================
SEGUIR AQUÍ - FASE 2: Sistema de autenticación
====================================
-->