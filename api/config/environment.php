<?php
/**
 * ====================================
 * SISTEMA DE RESERVAS DE SERVICIOS
 * CONFIGURACIÓN DE VARIABLES DE ENTORNO
 * Archivo: api/config/environment.php
 * Sección 1 de 1 (500 líneas)
 * ====================================
 */

// Evitar acceso directo al archivo
if (!defined('SECURE_ACCESS')) {
    http_response_code(403);
    die('Acceso denegado');
}

/**
 * Clase EnvironmentConfig
 * Maneja todas las variables de entorno y configuración del sistema
 * Optimizada para múltiples entornos (desarrollo, staging, producción)
 */
class EnvironmentConfig
{
    // Entorno actual
    private static $environment = 'development';
    private static $instance = null;
    
    // Variables de configuración
    private static $config = [];
    private static $secrets = [];
    private static $loaded = false;
    
    // Rutas importantes
    private static $rootPath;
    private static $configPath;
    private static $uploadsPath;
    private static $logsPath;
    private static $backupsPath;
    
    /**
     * Constructor privado para singleton
     */
    private function __construct()
    {
        $this->detectEnvironment();
        $this->loadEnvironmentFile();
        $this->setDefaultConfiguration();
        $this->validateConfiguration();
        $this->initializePaths();
    }
    
    /**
     * Obtener instancia única
     * @return EnvironmentConfig
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Detectar entorno actual basado en indicadores
     */
    private function detectEnvironment()
    {
        // Detectar por variable de entorno
        if (isset($_ENV['APP_ENV'])) {
            self::$environment = $_ENV['APP_ENV'];
        }
        // Detectar por host (Hostinger específico)
        elseif (isset($_SERVER['HTTP_HOST'])) {
            $host = $_SERVER['HTTP_HOST'];
            if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
                self::$environment = 'development';
            } elseif (strpos($host, 'staging') !== false || strpos($host, 'test') !== false) {
                self::$environment = 'staging';
            } else {
                self::$environment = 'production';
            }
        }
        // Detectar por IP del servidor
        elseif (isset($_SERVER['SERVER_ADDR'])) {
            $serverIP = $_SERVER['SERVER_ADDR'];
            if (in_array($serverIP, ['127.0.0.1', '::1', 'localhost'])) {
                self::$environment = 'development';
            }
        }
        
        // Definir constante global
        if (!defined('APP_ENV')) {
            define('APP_ENV', self::$environment);
        }
    }
    
    /**
     * Cargar archivo .env si existe
     */
    private function loadEnvironmentFile()
    {
        $envFile = dirname(__DIR__, 2) . '/.env';
        
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            
            foreach ($lines as $line) {
                // Ignorar comentarios
                if (strpos($line, '#') === 0) {
                    continue;
                }
                
                // Parsear variable
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value, '"\'');
                    
                    // Establecer en $_ENV y $_SERVER
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                    putenv("$key=$value");
                }
            }
        }
        
        self::$loaded = true;
    }
    
    /**
     * Establecer configuración por defecto según el entorno
     */
    private function setDefaultConfiguration()
    {
        // Configuración base común
        self::$config = [
            'app' => [
                'name' => 'ReservaMax Pro',
                'version' => '1.0.0',
                'environment' => self::$environment,
                'debug' => self::$environment !== 'production',
                'timezone' => 'America/Argentina/Buenos_Aires',
                'locale' => 'es_AR',
                'url' => $this->getAppUrl(),
                'api_url' => $this->getAppUrl() . '/api',
                'maintenance_mode' => false
            ],
            
            'database' => [
                'host' => $_ENV['DB_HOST'] ?? 'localhost',
                'port' => $_ENV['DB_PORT'] ?? 3306,
                'name' => $_ENV['DB_NAME'] ?? 'reservas_db',
                'username' => $_ENV['DB_USER'] ?? 'root',
                'password' => $_ENV['DB_PASS'] ?? '',
                'charset' => 'utf8mb4',
                'prefix' => $_ENV['DB_PREFIX'] ?? '',
                'pool_size' => 10,
                'timeout' => 30
            ],
            
            'security' => [
                'jwt_secret' => $_ENV['JWT_SECRET'] ?? $this->generateSecret(),
                'jwt_expiry' => 3600, // 1 hora
                'jwt_refresh_expiry' => 604800, // 7 días
                'password_min_length' => 8,
                'max_login_attempts' => 5,
                'lockout_duration' => 900, // 15 minutos
                'csrf_token_expiry' => 3600,
                'session_lifetime' => 7200, // 2 horas
                'two_factor_enabled' => true,
                'encryption_key' => $_ENV['ENCRYPTION_KEY'] ?? $this->generateSecret(32)
            ],
            
            'mail' => [
                'driver' => $_ENV['MAIL_DRIVER'] ?? 'smtp',
                'host' => $_ENV['MAIL_HOST'] ?? 'smtp.gmail.com',
                'port' => $_ENV['MAIL_PORT'] ?? 587,
                'username' => $_ENV['MAIL_USERNAME'] ?? '',
                'password' => $_ENV['MAIL_PASSWORD'] ?? '',
                'encryption' => $_ENV['MAIL_ENCRYPTION'] ?? 'tls',
                'from_address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@reservamax.com',
                'from_name' => $_ENV['MAIL_FROM_NAME'] ?? 'ReservaMax',
                'max_daily_emails' => 1000
            ],
            
            'payments' => [
                'mercadopago' => [
                    'public_key' => $_ENV['MP_PUBLIC_KEY'] ?? '',
                    'access_token' => $_ENV['MP_ACCESS_TOKEN'] ?? '',
                    'webhook_secret' => $_ENV['MP_WEBHOOK_SECRET'] ?? '',
                    'sandbox' => self::$environment !== 'production'
                ],
                'stripe' => [
                    'public_key' => $_ENV['STRIPE_PUBLIC_KEY'] ?? '',
                    'secret_key' => $_ENV['STRIPE_SECRET_KEY'] ?? '',
                    'webhook_secret' => $_ENV['STRIPE_WEBHOOK_SECRET'] ?? ''
                ],
                'default_currency' => 'ARS',
                'commission_rate' => 0.05 // 5%
            ],
            
            'notifications' => [
                'whatsapp' => [
                    'api_url' => $_ENV['WHATSAPP_API_URL'] ?? '',
                    'token' => $_ENV['WHATSAPP_TOKEN'] ?? '',
                    'phone_number_id' => $_ENV['WHATSAPP_PHONE_ID'] ?? ''
                ],
                'sms' => [
                    'provider' => $_ENV['SMS_PROVIDER'] ?? 'twilio',
                    'api_key' => $_ENV['SMS_API_KEY'] ?? '',
                    'api_secret' => $_ENV['SMS_API_SECRET'] ?? '',
                    'from_number' => $_ENV['SMS_FROM_NUMBER'] ?? ''
                ],
                'push' => [
                    'vapid_public_key' => $_ENV['VAPID_PUBLIC_KEY'] ?? '',
                    'vapid_private_key' => $_ENV['VAPID_PRIVATE_KEY'] ?? '',
                    'vapid_subject' => $_ENV['VAPID_SUBJECT'] ?? 'mailto:admin@reservamax.com'
                ]
            ],
            
            'storage' => [
                'default' => 'local',
                'local_path' => '../uploads',
                'max_file_size' => 10485760, // 10MB
                'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'],
                'image_quality' => 85,
                'generate_thumbnails' => true
            ],
            
            'cache' => [
                'enabled' => self::$environment === 'production',
                'driver' => 'file',
                'ttl' => 3600,
                'path' => '../cache'
            ],
            
            'logs' => [
                'enabled' => true,
                'level' => self::$environment === 'production' ? 'error' : 'debug',
                'path' => '../logs',
                'max_size' => 52428800, // 50MB
                'rotation' => true,
                'max_files' => 10
            ],
            
            'api' => [
                'rate_limit' => [
                    'enabled' => true,
                    'requests_per_minute' => 60,
                    'requests_per_hour' => 1000
                ],
                'cors' => [
                    'enabled' => true,
                    'allowed_origins' => ['*'],
                    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
                    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With']
                ],
                'versioning' => [
                    'enabled' => true,
                    'default_version' => 'v1',
                    'header_name' => 'API-Version'
                ]
            ],
            
            'business' => [
                'trial_days' => 30,
                'max_services_trial' => 5,
                'max_bookings_trial' => 50,
                'plans' => [
                    'basic' => [
                        'price' => 2500,
                        'max_services' => 10,
                        'max_bookings_month' => 200,
                        'features' => ['basic_reports', 'email_notifications']
                    ],
                    'premium' => [
                        'price' => 4500,
                        'max_services' => 50,
                        'max_bookings_month' => 1000,
                        'features' => ['advanced_reports', 'all_notifications', 'custom_branding']
                    ],
                    'enterprise' => [
                        'price' => 8500,
                        'max_services' => -1, // Ilimitado
                        'max_bookings_month' => -1,
                        'features' => ['everything', 'priority_support', 'white_label']
                    ]
                ]
            ]
        ];
        
        // Configuración específica por entorno
        $this->setEnvironmentSpecificConfig();
    }
    
    /**
     * Configuración específica por entorno
     */
    private function setEnvironmentSpecificConfig()
    {
        switch (self::$environment) {
            case 'development':
                self::$config['app']['debug'] = true;
                self::$config['logs']['level'] = 'debug';
                self::$config['cache']['enabled'] = false;
                self::$config['security']['max_login_attempts'] = 100;
                self::$config['payments']['mercadopago']['sandbox'] = true;
                break;
                
            case 'staging':
                self::$config['app']['debug'] = true;
                self::$config['logs']['level'] = 'info';
                self::$config['cache']['enabled'] = true;
                self::$config['payments']['mercadopago']['sandbox'] = true;
                break;
                
            case 'production':
                self::$config['app']['debug'] = false;
                self::$config['logs']['level'] = 'error';
                self::$config['cache']['enabled'] = true;
                self::$config['security']['csrf_protection'] = true;
                self::$config['api']['rate_limit']['enabled'] = true;
                break;
        }
    }
    
    /**
     * Validar configuración crítica
     */
    private function validateConfiguration()
    {
        $required = [
            'database.host',
            'database.name',
            'database.username',
            'security.jwt_secret',
            'security.encryption_key'
        ];
        
        foreach ($required as $key) {
            if (empty($this->get($key))) {
                throw new Exception("Configuración requerida faltante: {$key}");
            }
        }
        
        // Validar secretos en producción
        if (self::$environment === 'production') {
            $secrets = [
                'security.jwt_secret',
                'security.encryption_key',
                'payments.mercadopago.access_token'
            ];
            
            foreach ($secrets as $secret) {
                $value = $this->get($secret);
                if (empty($value) || strlen($value) < 16) {
                    throw new Exception("Secreto inseguro en producción: {$secret}");
                }
            }
        }
    }
    
    /**
     * Inicializar rutas del sistema
     */
    private function initializePaths()
    {
        self::$rootPath = dirname(__DIR__, 2);
        self::$configPath = self::$rootPath . '/config';
        self::$uploadsPath = self::$rootPath . '/uploads';
        self::$logsPath = self::$rootPath . '/logs';
        self::$backupsPath = self::$rootPath . '/backups';
        
        // Crear directorios si no existen
        $directories = [
            self::$uploadsPath,
            self::$logsPath,
            self::$backupsPath,
            self::$rootPath . '/cache'
        ];
        
        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
                
                // Crear archivo .htaccess para seguridad
                $htaccess = $dir . '/.htaccess';
                if (!file_exists($htaccess)) {
                    file_put_contents($htaccess, "Deny from all\n");
                }
            }
        }
        
        // Establecer constantes de rutas
        if (!defined('ROOT_PATH')) {
            define('ROOT_PATH', self::$rootPath);
            define('UPLOADS_PATH', self::$uploadsPath);
            define('LOGS_PATH', self::$logsPath);
            define('BACKUPS_PATH', self::$backupsPath);
        }
    }
    
    /**
     * Obtener URL base de la aplicación
     * @return string
     */
    private function getAppUrl()
    {
        if (isset($_ENV['APP_URL'])) {
            return rtrim($_ENV['APP_URL'], '/');
        }
        
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        
        return $protocol . '://' . $host . rtrim($path, '/');
    }
    
    /**
     * Generar secreto aleatorio
     * @param int $length
     * @return string
     */
    private function generateSecret($length = 64)
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes($length / 2));
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            return bin2hex(openssl_random_pseudo_bytes($length / 2));
        } else {
            // Fallback menos seguro
            return substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', $length)), 0, $length);
        }
    }
    
    /**
     * Obtener valor de configuración
     * @param string $key Clave en formato dot notation (ej: 'database.host')
     * @param mixed $default Valor por defecto
     * @return mixed
     */
    public static function get($key, $default = null)
    {
        $keys = explode('.', $key);
        $value = self::$config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }
    
    /**
     * Establecer valor de configuración
     * @param string $key
     * @param mixed $value
     */
    public static function set($key, $value)
    {
        $keys = explode('.', $key);
        $config = &self::$config;
        
        foreach ($keys as $k) {
            if (!isset($config[$k])) {
                $config[$k] = [];
            }
            $config = &$config[$k];
        }
        
        $config = $value;
    }
    
    /**
     * Obtener toda la configuración
     * @return array
     */
    public static function all()
    {
        return self::$config;
    }
    
    /**
     * Obtener entorno actual
     * @return string
     */
    public static function getEnvironment()
    {
        return self::$environment;
    }
    
    /**
     * Verificar si estamos en desarrollo
     * @return bool
     */
    public static function isDevelopment()
    {
        return self::$environment === 'development';
    }
    
    /**
     * Verificar si estamos en producción
     * @return bool
     */
    public static function isProduction()
    {
        return self::$environment === 'production';
    }
    
    /**
     * Verificar si estamos en staging
     * @return bool
     */
    public static function isStaging()
    {
        return self::$environment === 'staging';
    }
    
    /**
     * Obtener información del sistema
     * @return array
     */
    public static function getSystemInfo()
    {
        return [
            'app_name' => self::get('app.name'),
            'app_version' => self::get('app.version'),
            'environment' => self::$environment,
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'root_path' => self::$rootPath,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'loaded_extensions' => get_loaded_extensions(),
            'timezone' => date_default_timezone_get(),
            'config_loaded' => self::$loaded
        ];
    }
    
    /**
     * Crear archivo .env de ejemplo
     */
    public static function createEnvExample()
    {
        $envExample = "# Configuración de la aplicación\n";
        $envExample .= "APP_ENV=production\n";
        $envExample .= "APP_URL=https://tudominio.com\n";
        $envExample .= "\n# Base de datos\n";
        $envExample .= "DB_HOST=localhost\n";
        $envExample .= "DB_PORT=3306\n";
        $envExample .= "DB_NAME=reservas_db\n";
        $envExample .= "DB_USER=tu_usuario\n";
        $envExample .= "DB_PASS=tu_contraseña\n";
        $envExample .= "\n# Seguridad\n";
        $envExample .= "JWT_SECRET=" . (new self())->generateSecret() . "\n";
        $envExample .= "ENCRYPTION_KEY=" . (new self())->generateSecret() . "\n";
        $envExample .= "\n# Email\n";
        $envExample .= "MAIL_HOST=smtp.gmail.com\n";
        $envExample .= "MAIL_PORT=587\n";
        $envExample .= "MAIL_USERNAME=tu@email.com\n";
        $envExample .= "MAIL_PASSWORD=tu_password\n";
        $envExample .= "\n# MercadoPago\n";
        $envExample .= "MP_PUBLIC_KEY=tu_public_key\n";
        $envExample .= "MP_ACCESS_TOKEN=tu_access_token\n";
        
        file_put_contents(self::$rootPath . '/.env.example', $envExample);
    }
    
    /**
     * Validar configuración completa del sistema
     * @return array
     */
    public static function validateSystem()
    {
        $validation = [
            'status' => 'ok',
            'errors' => [],
            'warnings' => [],
            'checks' => []
        ];
        
        try {
            // Verificar PHP version
            if (version_compare(PHP_VERSION, '8.0.0', '<')) {
                $validation['errors'][] = 'PHP 8.0+ requerido. Versión actual: ' . PHP_VERSION;
            }
            
            // Verificar extensiones requeridas
            $requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'openssl', 'curl'];
            foreach ($requiredExtensions as $ext) {
                if (!extension_loaded($ext)) {
                    $validation['errors'][] = "Extensión PHP requerida no encontrada: {$ext}";
                }
            }
            
            // Verificar permisos de directorios
            $writableDirectories = [self::$uploadsPath, self::$logsPath, self::$backupsPath];
            foreach ($writableDirectories as $dir) {
                if (!is_writable($dir)) {
                    $validation['errors'][] = "Directorio no escribible: {$dir}";
                }
            }
            
            // Verificar configuración de base de datos
            if (empty(self::get('database.host'))) {
                $validation['errors'][] = 'Configuración de base de datos incompleta';
            }
            
            // Verificar configuraciones de producción
            if (self::isProduction()) {
                if (strlen(self::get('security.jwt_secret')) < 32) {
                    $validation['errors'][] = 'JWT secret muy corto para producción';
                }
                if (self::get('app.debug')) {
                    $validation['warnings'][] = 'Debug habilitado en producción';
                }
            }
            
            $validation['checks'] = [
                'php_version' => PHP_VERSION,
                'environment' => self::$environment,
                'debug_mode' => self::get('app.debug'),
                'database_configured' => !empty(self::get('database.host')),
                'mail_configured' => !empty(self::get('mail.host')),
                'payments_configured' => !empty(self::get('payments.mercadopago.access_token'))
            ];
            
            if (!empty($validation['errors'])) {
                $validation['status'] = 'error';
            } elseif (!empty($validation['warnings'])) {
                $validation['status'] = 'warning';
            }
            
        } catch (Exception $e) {
            $validation['status'] = 'error';
            $validation['errors'][] = 'Error en validación: ' . $e->getMessage();
        }
        
        return $validation;
    }
}

// Funciones de utilidad globales

/**
 * Obtener configuración
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function config($key, $default = null)
{
    return EnvironmentConfig::get($key, $default);
}

/**
 * Obtener variable de entorno
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function env($key, $default = null)
{
    return $_ENV[$key] ?? $default;
}

/**
 * Verificar si estamos en desarrollo
 * @return bool
 */
function is_development()
{
    return EnvironmentConfig::isDevelopment();
}

/**
 * Verificar si estamos en producción
 * @return bool
 */
function is_production()
{
    return EnvironmentConfig::isProduction();
}

// Inicializar configuración de entorno
EnvironmentConfig::getInstance();

// Establecer timezone
date_default_timezone_set(config('app.timezone', 'UTC'));

// Configurar locale
if (function_exists('setlocale')) {
    setlocale(LC_TIME, config('app.locale', 'es_AR.UTF-8'));
}

?>

<!--
====================================
SECCIÓN COMPLETADA: Variables de Entorno
ARCHIVO: api/config/environment.php
LÍNEAS: 500
PROGRESO: 3/41 archivos completados
====================================
SEGUIR AQUÍ
====================================
-->