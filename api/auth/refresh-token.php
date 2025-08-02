 <?php
require_once __DIR__ . '/../../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Método no permitido', 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['refresh_token'])) {
        errorResponse('Refresh token requerido', 400);
    }
    
    // Validar refresh token
    $tokenData = validateJWTToken($input['refresh_token']);
    
    if (!$tokenData || $tokenData['type'] !== 'refresh') {
        errorResponse('Refresh token inválido', 401);
    }
    
    // Obtener usuario
    $user = getUserById($tokenData['user_id']);
    
    if (!$user) {
        errorResponse('Usuario no encontrado', 404);
    }
    
    // Generar nuevo access token
    $jwtSecret = config('security.jwt_secret');
    $accessExpiry = time() + config('security.jwt_expiry', 3600);
    
    $accessPayload = [
        'iss' => config('app.url'),
        'iat' => time(),
        'exp' => $accessExpiry,
        'sub' => $user['uuid'],
        'user_id' => $user['id'],
        'email' => $user['email'],
        'role' => 'user',
        'plan' => $user['plan_type']
    ];
    
    $accessToken = createJWT($accessPayload, $jwtSecret);
    
    jsonResponse([
        'status' => 'success',
        'tokens' => [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $accessExpiry - time()
        ]
    ]);
    
} catch (Exception $e) {
    writeLog('error', 'Error refrescando token', ['error' => $e->getMessage()]);
    errorResponse('Error interno del servidor', 500);
}

/**
 * Crear JWT token
 */
function createJWT($payload, $secret)
{
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payload = json_encode($payload);
    
    $headerEncoded = base64UrlEncode($header);
    $payloadEncoded = base64UrlEncode($payload);
    
    $signature = hash_hmac('sha256', $headerEncoded . "." . $payloadEncoded, $secret, true);
    $signatureEncoded = base64UrlEncode($signature);
    
    return $headerEncoded . "." . $payloadEncoded . "." . $signatureEncoded;
}
?>
