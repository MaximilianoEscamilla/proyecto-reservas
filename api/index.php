/**
 * 3. ARCHIVO: api/index.php (CREAR NUEVO)
 * Router principal para todas las APIs
 */
?>
<?php
require_once __DIR__ . '/../includes/config.php';

// Aplicar rate limiting global
applyRateLimit();

// Obtener la ruta solicitada
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
$path = str_replace('/api', '', $path);

// Log de la petición
writeLog('info', 'API Request', [
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $path,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
]);

// Router básico
switch (true) {
    // Rutas de autenticación
    case preg_match('#^/auth/login/?$#', $path):
        require_once __DIR__ . '/auth/login.php';
        break;
        
    case preg_match('#^/auth/register/?$#', $path):
        require_once __DIR__ . '/auth/register.php';
        break;
        
    case preg_match('#^/auth/verify/?$#', $path):
        require_once __DIR__ . '/auth/verify.php';
        break;
        
    case preg_match('#^/auth/logout/?$#', $path):
        require_once __DIR__ . '/auth/logout.php';
        break;
        
    case preg_match('#^/auth/refresh-token/?$#', $path):
        require_once __DIR__ . '/auth/refresh-token.php';
        break;
        
    case preg_match('#^/auth/forgot-password/?$#', $path):
        require_once __DIR__ . '/auth/forgot-password.php';
        break;
        
    case preg_match('#^/auth/reset-password/?$#', $path):
        require_once __DIR__ . '/auth/reset-password.php';
        break;
    
    // Rutas de usuarios (requieren autenticación)
    case preg_match('#^/users/profile/?$#', $path):
        requireAuth();
        require_once __DIR__ . '/users/profile.php';
        break;
        
    case preg_match('#^/users/settings/?$#', $path):
        requireAuth();
        require_once __DIR__ . '/users/settings.php';
        break;
    
    // Rutas de servicios
    case preg_match('#^/services/?$#', $path):
        requireAuth();
        require_once __DIR__ . '/services/services.php';
        break;
        
    case preg_match('#^/services/(\d+)/?$#', $path, $matches):
        requireAuth();
        $_GET['id'] = $matches[1];
        require_once __DIR__ . '/services/services.php';
        break;
    
    // Rutas de reservas
    case preg_match('#^/bookings/?$#', $path):
        requireAuth();
        require_once __DIR__ . '/bookings/bookings.php';
        break;
        
    case preg_match('#^/bookings/(\d+)/?$#', $path, $matches):
        requireAuth();
        $_GET['id'] = $matches[1];
        require_once __DIR__ . '/bookings/bookings.php';
        break;
        
    case preg_match('#^/bookings/availability/?$#', $path):
        require_once __DIR__ . '/bookings/availability.php';
        break;
    
    // Rutas de dashboard
    case preg_match('#^/dashboard/stats/?$#', $path):
        requireAuth();
        require_once __DIR__ . '/dashboard/stats.php';
        break;
        
    case preg_match('#^/dashboard/recent/?$#', $path):
        requireAuth();
        require_once __DIR__ . '/dashboard/recent.php';
        break;
    
    // Rutas de pagos
    case preg_match('#^/payments/process/?$#', $path):
        requireAuth();
        require_once __DIR__ . '/payments/process.php';
        break;
        
    case preg_match('#^/payments/webhooks/?$#', $path):
        require_once __DIR__ . '/payments/webhooks.php';
        break;
    
    // Health check
    case preg_match('#^/health/?$#', $path):
        jsonResponse([
            'status' => 'ok',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => config('app.version')
        ]);
        break;
    
    // Ruta no encontrada
    default:
        errorResponse('Endpoint no encontrado', 404);
        break;
}
?>
