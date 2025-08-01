<?php
/**
 * Sistema de Autenticación Seguro
 * 
 * @author MaximilianoEscamilla
 * @version 2.0
 */

require_once '../config/security.php';
require_once '../config/database.php';

class AuthSystem {
    private $db;
    private $query;
    
    public function __construct() {
        $this->db = DatabaseConfig::getInstance();
        $this->query = new ReservasQuery();
    }
    
    // Registro de usuario
    public function register($userData) {
        try {
            // Validar datos de entrada
            $validation = $this->validateRegistrationData($userData);
            if ($validation !== true) {
                return ['success' => false, 'errors' => $validation];
            }
            
            // Verificar si el email ya existe
            if ($this->query->getUserByEmail($userData['email'])) {
                return ['success' => false, 'errors' => ['El email ya está registrado']];
            }
            
            // Rate limiting
            if (!SecurityConfig::checkRateLimit('register_' . $_SERVER['REMOTE_ADDR'], 3, 300)) {
                return ['success' => false, 'errors' => ['Demasiados intentos de registro. Intenta en 5 minutos.']];
            }
            
            // Hashear contraseña
            $userData['password'] = SecurityConfig::hashPassword($userData['password']);
            
            // Crear usuario
            $userId = $this->query->createUser($userData);
            
            if ($userId) {
                // Iniciar sesión automáticamente
                $this->createUserSession($userId, $userData['email'], $userData['role'] ?? 'user');
                
                return [
                    'success' => true, 
                    'message' => 'Usuario registrado exitosamente',
                    'user_id' => $userId
                ];
            } else {
                return ['success' => false, 'errors' => ['Error al crear el usuario']];
            }
            
        } catch (Exception $e) {
            error_log("Error en registro: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Error interno del servidor']];
        }
    }
    
    // Login de usuario
    public function login($email, $password, $rememberMe = false) {
        try {
            // Sanitizar entrada
            $email = SecurityConfig::sanitizeInput($email);
            
            // Validar formato de email
            if (!SecurityConfig::validateEmail($email)) {
                return ['success' => false, 'errors' => ['Email inválido']];
            }
            
            // Rate limiting por IP
            if (!SecurityConfig::checkRateLimit('login_' . $_SERVER['REMOTE_ADDR'], 5, 300)) {
                return ['success' => false, 'errors' => ['Demasiados intentos de login. Intenta en 5 minutos.']];
            }
            
            // Rate limiting por email
            if (!SecurityConfig::checkRateLimit('login_email_' . $email, 3, 300)) {
                return ['success' => false, 'errors' => ['Demasiados intentos para este email. Intenta en 5 minutos.']];
            }
            
            // Buscar usuario
            $user = $this->query->getUserByEmail($email);
            
            if (!$user || !SecurityConfig::verifyPassword($password, $user['password'])) {
                return ['success' => false, 'errors' => ['Credenciales inválidas']];
            }
            
            // Crear sesión
            $this->createUserSession($user['id'], $user['email'], $user['role']);
            
            // Cookie "Remember Me" (opcional)
            if ($rememberMe) {
                $this->setRememberMeCookie($user['id']);
            }
            
            return [
                'success' => true, 
                'message' => 'Login exitoso',
                'user' => [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'role' => $user['role']
                ]
            ];
            
        } catch (Exception $e) {
            error_log("Error en login: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Error interno del servidor']];
        }
    }
    
    // Logout
    public function logout() {
        // Destruir sesión
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        
        // Eliminar cookie remember me
        if (isset($_COOKIE['remember_me'])) {
            setcookie('remember_me', '', time() - 3600, '/', '', true, true);
        }
        
        return ['success' => true, 'message' => 'Logout exitoso'];
    }
    
    // Verificar si el usuario está autenticado
    public function isAuthenticated() {
        return isset($_SESSION['user_id']) && isset($_SESSION['user_email']);
    }
    
    // Obtener usuario actual
    public function getCurrentUser() {
        if (!$this->isAuthenticated()) {
            return null;
        }
        
        return [
            'id' => $_SESSION['user_id'],
            'email' => $_SESSION['user_email'],
            'role' => $_SESSION['user_role'] ?? 'user'
        ];
    }
    
    // Verificar rol
    public function hasRole($requiredRole) {
        $user = $this->getCurrentUser();
        if (!$user) return false;
        
        $roles = ['user' => 1, 'admin' => 2, 'super_admin' => 3];
        $userLevel = $roles[$user['role']] ?? 0;
        $requiredLevel = $roles[$requiredRole] ?? 999;
        
        return $userLevel >= $requiredLevel;
    }
    
    // Middleware de autenticación
    public function requireAuth($requiredRole = null) {
        if (!$this->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['error' => 'No autenticado']);
            exit;
        }
        
        if ($requiredRole && !$this->hasRole($requiredRole)) {
            http_response_code(403);
            echo json_encode(['error' => 'Sin permisos suficientes']);
            exit;
        }
        
        return $this->getCurrentUser();
    }
    
    // Crear sesión de usuario
    private function createUserSession($userId, $email, $role) {
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_email'] = $email;
        $_SESSION['user_role'] = $role;
        $_SESSION['login_time'] = time();
        
        // Regenerar ID de sesión por seguridad
        session_regenerate_id(true);
    }
    
    // Cookie Remember Me (implementación básica)
    private function setRememberMeCookie($userId) {
        $token = bin2hex(random_bytes(32));
        $expiry = time() + (30 * 24 * 60 * 60); // 30 días
        
        // Guardar token en BD (aquí simplificado)
        // En producción, guardar hash del token en BD
        setcookie('remember_me', $token, $expiry, '/', '', true, true);
    }
    
    // Validar datos de registro
    private function validateRegistrationData($data) {
        $errors = [];
        
        // Validar email
        if (empty($data['email']) || !SecurityConfig::validateEmail($data['email'])) {
            $errors[] = "Email inválido";
        }
        
        // Validar contraseña
        if (empty($data['password'])) {
            $errors[] = "La contraseña es requerida";
        } else {
            $passwordValidation = SecurityConfig::validatePasswordStrength($data['password']);
            if ($passwordValidation !== true) {
                $errors = array_merge($errors, $passwordValidation);
            }
        }
        
        // Validar nombre
        if (empty($data['name']) || strlen($data['name']) < 2) {
            $errors[] = "El nombre debe tener al menos 2 caracteres";
        }
        
        // Validar teléfono (opcional)
        if (!empty($data['phone']) && !SecurityConfig::validatePhone($data['phone'])) {
            $errors[] = "Formato de teléfono inválido";
        }
        
        return empty($errors) ? true : $errors;
    }
}

// Manejo de requests API
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Verificar token CSRF
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!SecurityConfig::verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Token CSRF inválido']);
        exit;
    }
    
    $auth = new AuthSystem();
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'register':
            $userData = [
                'email' => $_POST['email'] ?? '',
                'password' => $_POST['password'] ?? '',
                'name' => $_POST['name'] ?? '',
                'phone' => $_POST['phone'] ?? '',
                'role' => 'user' // Por defecto
            ];
            
            $result = $auth->register($userData);
            echo json_encode($result);
            break;
            
        case 'login':
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            $rememberMe = isset($_POST['remember_me']);
            
            $result = $auth->login($email, $password, $rememberMe);
            echo json_encode($result);
            break;
            
        case 'logout':
            $result = $auth->logout();
            echo json_encode($result);
            break;
            
        case 'check_auth':
            $user = $auth->getCurrentUser();
            echo json_encode([
                'authenticated' => $user !== null,
                'user' => $user
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Acción no válida']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
}
?>