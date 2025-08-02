 
<?php
/**
 * 6. ARCHIVO: api/auth/logout.php (CREAR NUEVO)
 * Cerrar sesión
 */
?>
<?php
require_once __DIR__ . '/../../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Método no permitido', 405);
}

try {
    $user = requireAuth();
    
    // Invalidar refresh token en base de datos
    $db = DatabaseConfig::getConnection();
    $stmt = $db->prepare("UPDATE users SET refresh_token = NULL WHERE id = ?");
    $stmt->execute([$user['id']]);
    
    // Log del logout
    writeLog('info', 'Usuario cerró sesión', [
        'user_id' => $user['id'],
        'email' => $user['email']
    ]);
    
    jsonResponse([
        'status' => 'success',
        'message' => 'Sesión cerrada correctamente'
    ]);
    
} catch (Exception $e) {
    writeLog('error', 'Error en logout', ['error' => $e->getMessage()]);
    errorResponse('Error interno del servidor', 500);
}
?>

<?php