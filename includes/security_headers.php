<?php
/**
 * ====================================
 * ARCHIVO: includes/security_headers.php
 * CONFIGURACIÓN DE HEADERS DE SEGURIDAD
 * ====================================
 */

/**
 * Configurar headers de seguridad HTTP
 */
function setSecurityHeaders()
{
    // Prevenir XSS
    header('X-XSS-Protection: 1; mode=block');
    
    // Prevenir MIME type sniffing
    header('X-Content-Type-Options: nosniff');
    
    // Prevenir clickjacking
    header('X-Frame-Options: DENY');
    
    // Referrer policy
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // Content Security Policy básico
    if (!config('app.debug')) {
        $csp = "default-src 'self'; " .
               "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; " .
               "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; " .
               "font-src 'self' https://fonts.gstatic.com; " .
               "img-src 'self' data: https:; " .
               "connect-src 'self'";
        header("Content-Security-Policy: {$csp}");
    }
    
    // HTTPS redirection en producción
    if (!config('app.debug') && !isset($_SERVER['HTTPS'])) {
        $redirectURL = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        header("Location: {$redirectURL}");
        exit();
    }
}

/**
 * ====================================
 * ARCHIVO: includes/auth_middleware.php
 * MIDDLEWARE DE AUTENTICACIÓN
 * ====================================
 */

/**
 * Middleware de autenticación JWT
 */
function requireAuth()
{
    $token = getBearerToken();
    
    if (!$token) {
        errorResponse('Token de autorización requerido', 401);
    }
    
    $user = validateJWTToken($token);
    
    if (!$user) {
        errorResponse('Token inválido o expirado', 401);
    }
    
    // Agregar usuario al contexto global
    $GLOBALS['current_user'] = $user;
    
    return $user;
}

/**
 * Obtener token Bearer del header Authorization
 */
function getBearerToken()
{
    $headers = getAuthorizationHeader();
    
    if (!empty($headers)) {
        if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
            return $matches[1];
        }
    }
    
    return null;
}

/**
 * Obtener header de autorización
 */
function getAuthorizationHeader()
{
    $headers = null;
    
    if (isset($_SERVER['Authorization'])) {
        $headers = trim($_SERVER["Authorization"]);
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
        
        if (isset($requestHeaders['Authorization'])) {
            $headers = trim($requestHeaders['Authorization']);
        }
    }
    
    return $headers;
}

/**
 * Validar token JWT
 */
function validateJWTToken($token)
{
    try {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            return false;
        }
        
        list($header, $payload, $signature) = $parts;
        
        // Decodificar payload
        $payloadDecoded = json_decode(base64UrlDecode($payload), true);
        
        if (!$payloadDecoded) {
            return false;
        }
        
        // Verificar expiración
        if (isset($payloadDecoded['exp']) && $payloadDecoded['exp'] < time()) {
            return false;
        }
        
        // Verificar firma
        $expectedSignature = base64UrlEncode(
            hash_hmac('sha256', $header . '.' . $payload, config('security.jwt_secret'), true)
        );
        
        if (!hash_equals($signature, $expectedSignature)) {
            return false;
        }
        
        // Cargar usuario completo desde base de datos
        if (isset($payloadDecoded['user_id'])) {
            return getUserById($payloadDecoded['user_id']);
        }
        
        return $payloadDecoded;
        
    } catch (Exception $e) {
        writeLog('error', 'Error validando token JWT', ['error' => $e->getMessage()]);
        return false;
    }
}

/**
 * Obtener usuario por ID
 */
function getUserById($userId)
{
    try {
        $db = DatabaseConfig::getConnection();
        $stmt = $db->prepare("
            SELECT id, uuid, email, first_name, last_name, business_name, 
                   plan_type, status, email_verified, created_at
            FROM users 
            WHERE id = ? AND status = 'active'
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    } catch (Exception $e) {
        writeLog('error', 'Error obteniendo usuario', ['user_id' => $userId, 'error' => $e->getMessage()]);
        return false;
    }
}

/**
 * Decodificación Base64 URL-safe
 */
function base64UrlDecode($data)
{
    return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
}

/**
 * Codificación Base64 URL-safe
 */
function base64UrlEncode($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Obtener usuario actual autenticado
 */
function getCurrentUser()
{
    return $GLOBALS['current_user'] ?? null;
}

/**
 * Verificar si el usuario tiene permisos específicos
 */
function hasPermission($permission)
{
    $user = getCurrentUser();
    
    if (!$user) {
        return false;
    }
    
    // Lógica de permisos basada en plan
    $permissions = getUserPermissions($user);
    
    return in_array($permission, $permissions);
}

/**
 * Obtener permisos del usuario basado en su plan
 */
function getUserPermissions($user)
{
    $permissions = ['read_own_data', 'update_own_profile'];
    
    switch ($user['plan_type']) {
        case 'trial':
            $permissions[] = 'create_services_limited';
            $permissions[] = 'manage_bookings_limited';
            break;
        case 'basic':
            $permissions[] = 'create_services';
            $permissions[] = 'manage_bookings';
            $permissions[] = 'basic_reports';
            break;
        case 'premium':
            $permissions[] = 'create_services_unlimited';
            $permissions[] = 'manage_bookings_unlimited';
            $permissions[] = 'advanced_reports';
            $permissions[] = 'custom_branding';
            break;
        case 'enterprise':
            $permissions[] = 'all_features';
            $permissions[] = 'priority_support';
            $permissions[] = 'white_label';
            break;
    }
    
    return $permissions;
}

/**
 * ====================================
 * ARCHIVO: includes/validation.php
 * FUNCIONES DE VALIDACIÓN
 * ====================================
 */

/**
 * Validar email
 */
function validateEmail($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validar teléfono argentino
 */
function validatePhone($phone)
{
    $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);
    
    // Formato internacional argentino
    if (preg_match('/^\+54\d{10}$/', $cleanPhone)) {
        return true;
    }
    
    // Formato nacional
    if (preg_match('/^(11|2\d|3\d)\d{8}$/', $cleanPhone)) {
        return true;
    }
    
    return false;
}

/**
 * Validar CUIT
 */
function validateCuit($cuit)
{
    $cuit = preg_replace('/[^0-9]/', '', $cuit);
    
    if (strlen($cuit) !== 11) {
        return false;
    }
    
    $multiplicadores = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
    $suma = 0;
    
    for ($i = 0; $i < 10; $i++) {
        $suma += intval($cuit[$i]) * $multiplicadores[$i];
    }
    
    $resto = $suma % 11;
    $digitoVerificador = $resto < 2 ? $resto : 11 - $resto;
    
    return intval($cuit[10]) === $digitoVerificador;
}

/**
 * Sanitizar input
 */
function sanitizeInput($input, $type = 'string')
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
        case 'string':
        default:
            return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * ====================================
 * ARCHIVO: includes/rate_limiter.php
 * LIMITADOR DE VELOCIDAD
 * ====================================
 */

/**
 * Rate limiter simple basado en IP
 */
class RateLimiter
{
    private static $cache = [];
    
    /**
     * Verificar rate limit
     */
    public static function check($key, $maxRequests = 60, $windowSeconds = 60)
    {
        $now = time();
        $windowStart = $now - $windowSeconds;
        
        // Limpiar cache viejo
        self::cleanup($windowStart);
        
        // Inicializar contador si no existe
        if (!isset(self::$cache[$key])) {
            self::$cache[$key] = [];
        }
        
        // Filtrar requests en la ventana actual
        self::$cache[$key] = array_filter(self::$cache[$key], function($timestamp) use ($windowStart) {
            return $timestamp > $windowStart;
        });
        
        // Verificar límite
        if (count(self::$cache[$key]) >= $maxRequests) {
            return false;
        }
        
        // Agregar request actual
        self::$cache[$key][] = $now;
        
        return true;
    }
    
    /**
     * Limpiar cache viejo
     */
    private static function cleanup($windowStart)
    {
        foreach (self::$cache as $key => &$timestamps) {
            $timestamps = array_filter($timestamps, function($timestamp) use ($windowStart) {
                return $timestamp > $windowStart;
            });
            
            if (empty($timestamps)) {
                unset(self::$cache[$key]);
            }
        }
    }
    
    /**
     * Obtener información del rate limit
     */
    public static function getInfo($key, $maxRequests = 60, $windowSeconds = 60)
    {
        $now = time();
        $windowStart = $now - $windowSeconds;
        
        if (!isset(self::$cache[$key])) {
            return [
                'requests' => 0,
                'remaining' => $maxRequests,
                'reset_time' => $now + $windowSeconds
            ];
        }
        
        $requests = array_filter(self::$cache[$key], function($timestamp) use ($windowStart) {
            return $timestamp > $windowStart;
        });
        
        $requestCount = count($requests);
        $remaining = max(0, $maxRequests - $requestCount);
        $resetTime = empty($requests) ? $now : min($requests) + $windowSeconds;
        
        return [
            'requests' => $requestCount,
            'remaining' => $remaining,
            'reset_time' => $resetTime
        ];
    }
}

/**
 * Middleware de rate limiting
 */
function applyRateLimit($key = null, $maxRequests = 60, $windowSeconds = 60)
{
    if (!config('security.rate_limit_enabled')) {
        return true;
    }
    
    $key = $key ?: ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    
    if (!RateLimiter::check($key, $maxRequests, $windowSeconds)) {
        $info = RateLimiter::getInfo($key, $maxRequests, $windowSeconds);
        
        header('X-RateLimit-Limit: ' . $maxRequests);
        header('X-RateLimit-Remaining: ' . $info['remaining']);
        header('X-RateLimit-Reset: ' . $info['reset_time']);
        
        errorResponse('Rate limit excedido. Intenta más tarde.', 429);
    }
    
    return true;
}

?>