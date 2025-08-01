<?php
/**
 * Configuración de Seguridad del Sistema de Reservas
 * 
 * @author MaximilianoEscamilla
 * @version 2.0
 */

class SecurityConfig {
    
    // Configuración de sesiones seguras
    public static function initializeSecureSessions() {
        // Configurar sesión segura
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure', 1);
        ini_set('session.use_strict_mode', 1);
        ini_set('session.cookie_samesite', 'Strict');
        
        // Regenerar ID de sesión
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Regenerar ID cada 30 minutos
        if (!isset($_SESSION['last_regeneration'])) {
            $_SESSION['last_regeneration'] = time();
        } elseif (time() - $_SESSION['last_regeneration'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = time();
        }
    }
    
    // Generar token CSRF
    public static function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    // Verificar token CSRF
    public static function verifyCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
    
    // Sanitizar datos de entrada
    public static function sanitizeInput($data) {
        if (is_array($data)) {
            return array_map([self::class, 'sanitizeInput'], $data);
        }
        
        // Eliminar espacios y caracteres especiales
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        
        return $data;
    }
    
    // Validar email
    public static function validateEmail($email) {
        $email = filter_var($email, FILTER_SANITIZE_EMAIL);
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    // Validar teléfono
    public static function validatePhone($phone) {
        $phone = preg_replace('/[^0-9+\-\s\(\)]/', '', $phone);
        return preg_match('/^[\+]?[0-9\s\-\(\)]{8,15}$/', $phone);
    }
    
    // Validar fecha
    public static function validateDate($date, $format = 'Y-m-d') {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }
    
    // Hash de contraseñas seguro
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
    }
    
    // Verificar contraseña
    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    // Validar fuerza de contraseña
    public static function validatePasswordStrength($password) {
        $errors = [];
        
        if (strlen($password) < 8) {
            $errors[] = "La contraseña debe tener al menos 8 caracteres";
        }
        
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = "La contraseña debe contener al menos una mayúscula";
        }
        
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = "La contraseña debe contener al menos una minúscula";
        }
        
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = "La contraseña debe contener al menos un número";
        }
        
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = "La contraseña debe contener al menos un carácter especial";
        }
        
        return empty($errors) ? true : $errors;
    }
    
    // Rate limiting simple
    public static function checkRateLimit($identifier, $maxAttempts = 5, $timeWindow = 300) {
        $cacheFile = __DIR__ . '/../cache/rate_limit_' . md5($identifier) . '.json';
        
        if (!file_exists(dirname($cacheFile))) {
            mkdir(dirname($cacheFile), 0755, true);
        }
        
        $attempts = [];
        if (file_exists($cacheFile)) {
            $attempts = json_decode(file_get_contents($cacheFile), true) ?: [];
        }
        
        // Limpiar intentos antiguos
        $now = time();
        $attempts = array_filter($attempts, function($timestamp) use ($now, $timeWindow) {
            return ($now - $timestamp) < $timeWindow;
        });
        
        // Verificar si excede el límite
        if (count($attempts) >= $maxAttempts) {
            return false;
        }
        
        // Registrar nuevo intento
        $attempts[] = $now;
        file_put_contents($cacheFile, json_encode($attempts));
        
        return true;
    }
    
    // Limpiar archivos de caché antiguos
    public static function cleanupCache() {
        $cacheDir = __DIR__ . '/../cache/';
        if (!is_dir($cacheDir)) return;
        
        $files = glob($cacheDir . 'rate_limit_*.json');
        foreach ($files as $file) {
            if (time() - filemtime($file) > 3600) { // 1 hora
                unlink($file);
            }
        }
    }
    
    // Headers de seguridad
    public static function setSecurityHeaders() {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\'; style-src \'self\' \'unsafe-inline\';');
    }
}

// Inicializar seguridad automáticamente
SecurityConfig::initializeSecureSessions();
SecurityConfig::setSecurityHeaders();
SecurityConfig::cleanupCache();
?>