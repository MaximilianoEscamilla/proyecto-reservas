
// assets/js/auth.js
// Cliente de Autenticación Independiente - ReservaFácil
// Gestiona toda la lógica de autenticación del sistema

/**
 * AuthClient - Cliente principal de autenticación
 * Maneja login, registro, verificación y gestión de tokens
 */
class AuthClient {
    constructor(options = {}) {
        // Configuración por defecto
        this.config = {
            apiURL: options.apiURL || window.location.origin + '/api',
            tokenKey: options.tokenKey || 'token',
            refreshTokenKey: options.refreshTokenKey || 'refreshToken',
            userKey: options.userKey || 'user',
            rememberKey: options.rememberKey || 'rememberLogin',
            autoRefresh: options.autoRefresh !== false,
            refreshThreshold: options.refreshThreshold || 300, // 5 minutos
            maxRetries: options.maxRetries || 3,
            retryDelay: options.retryDelay || 1000,
            debug: options.debug || false,
            ...options
        };

        // Estado interno
        this.isAuthenticated = false;
        this.currentUser = null;
        this.token = null;
        this.refreshToken = null;
        this.refreshTimer = null;
        this.retryCount = 0;
        this.eventListeners = new Map();

        // Inicializar
        this.init();
    }

    /**
     * Inicializar el cliente de autenticación
     */
    init() {
        this.log('Inicializando AuthClient...');

        // Cargar datos almacenados
        this.loadStoredAuth();

        // Configurar auto-refresh si hay token
        if (this.token) {
            this.setupAutoRefresh();
        }

        // Escuchar cambios de storage (múltiples tabs)
        this.setupStorageListener();

        // Escuchar visibilidad de página para refresh
        this.setupVisibilityListener();

        this.log('AuthClient inicializado', {
            authenticated: this.isAuthenticated,
            user: this.currentUser?.email
        });
    }

    /**
     * Cargar autenticación almacenada
     */
    loadStoredAuth() {
        try {
            this.token = localStorage.getItem(this.config.tokenKey);
            this.refreshToken = localStorage.getItem(this.config.refreshTokenKey);

            const userData = localStorage.getItem(this.config.userKey);
            if (userData) {
                this.currentUser = JSON.parse(userData);
            }

            // Verificar si el token está válido
            if (this.token) {
                const tokenData = this.parseJWT(this.token);
                if (tokenData && tokenData.exp * 1000 > Date.now()) {
                    this.isAuthenticated = true;
                    this.emit('authenticated', this.currentUser);
                } else {
                    this.log('Token expirado, limpiando almacenamiento');
                    this.clearAuth();
                }
            }
        } catch (error) {
            this.log('Error cargando autenticación almacenada:', error);
            this.clearAuth();
        }
    }

    /**
     * Login con email y contraseña
     * @param {Object} credentials - { email, password, remember }
     * @returns {Promise<Object>} Respuesta del login
     */
    async login(credentials) {
        this.log('Iniciando login para:', credentials.email);

        try {
            const response = await this.makeRequest('/auth/login.php', {
                method: 'POST',
                body: JSON.stringify({
                    email: credentials.email,
                    password: credentials.password,
                    remember: credentials.remember || false
                })
            });

            if (response.status === 'success') {
                await this.handleLoginSuccess(response);
                this.log('Login exitoso para:', credentials.email);
                return response;
            } else {
                throw new Error(response.message || 'Error en login');
            }
        } catch (error) {
            this.log('Error en login:', error);
            this.emit('loginError', error);
            throw error;
        }
    }

    /**
     * Registro de nuevo usuario
     * @param {Object} userData - Datos del usuario
     * @returns {Promise<Object>} Respuesta del registro
     */
    async register(userData) {
        this.log('Iniciando registro para:', userData.email);

        try {
            const response = await this.makeRequest('/auth/register.php', {
                method: 'POST',
                body: JSON.stringify(userData)
            });

            if (response.status === 'success') {
                this.log('Registro exitoso para:', userData.email);
                this.emit('registered', response);
                return response;
            } else {
                throw new Error(response.message || 'Error en registro');
            }
        } catch (error) {
            this.log('Error en registro:', error);
            this.emit('registerError', error);
            throw error;
        }
    }

    /**
     * Verificar email con token
     * @param {string} token - Token de verificación
     * @returns {Promise<Object>} Respuesta de verificación
     */
    async verifyEmail(token) {
        this.log('Verificando email con token');

        try {
            const response = await this.makeRequest('/auth/verify.php', {
                method: 'POST',
                body: JSON.stringify({ token })
            });

            if (response.status === 'success') {
                this.log('Email verificado exitosamente');
                this.emit('emailVerified', response);
                return response;
            } else {
                throw new Error(response.message || 'Error en verificación');
            }
        } catch (error) {
            this.log('Error en verificación:', error);
            this.emit('verificationError', error);
            throw error;
        }
    }

    /**
     * Solicitar recuperación de contraseña
     * @param {string} email - Email del usuario
     * @returns {Promise<Object>} Respuesta de la solicitud
     */
    async forgotPassword(email) {
        this.log('Solicitando recuperación de contraseña para:', email);

        try {
            const response = await this.makeRequest('/auth/forgot-password.php', {
                method: 'POST',
                body: JSON.stringify({ email })
            });

            if (response.status === 'success') {
                this.log('Solicitud de recuperación enviada');
                this.emit('passwordResetRequested', response);
                return response;
            } else {
                throw new Error(response.message || 'Error en solicitud');
            }
        } catch (error) {
            this.log('Error en recuperación:', error);
            this.emit('passwordResetError', error);
            throw error;
        }
    }

    /**
     * Resetear contraseña con token
     * @param {Object} data - { token, password, confirmPassword }
     * @returns {Promise<Object>} Respuesta del reset
     */
    async resetPassword(data) {
        this.log('Reseteando contraseña');

        try {
            const response = await this.makeRequest('/auth/reset-password.php', {
                method: 'POST',
                body: JSON.stringify(data)
            });

            if (response.status === 'success') {
                this.log('Contraseña reseteada exitosamente');
                this.emit('passwordReset', response);
                return response;
            } else {
                throw new Error(response.message || 'Error en reset');
            }
        } catch (error) {
            this.log('Error en reset:', error);
            this.emit('passwordResetError', error);
            throw error;
        }
    }

    /**
     * Cerrar sesión
     * @returns {Promise<void>}
     */
    async logout() {
        this.log('Cerrando sesión');

        try {
            // Intentar notificar al servidor
            if (this.token) {
                await this.makeRequest('/auth/logout.php', {
                    method: 'POST'
                }).catch(() => {
                    // Ignorar errores del servidor en logout
                    this.log('Error notificando logout al servidor (ignorado)');
                });
            }
        } catch (error) {
            this.log('Error en logout del servidor:', error);
        } finally {
            // Limpiar estado local siempre
            this.clearAuth();
            this.emit('logout');
            this.log('Sesión cerrada');
        }
    }

    /**
     * Verificar si el token actual es válido
     * @returns {Promise<boolean>} True si es válido
     */
    async verifyToken() {
        if (!this.token) {
            return false;
        }

        try {
            const response = await this.makeRequest('/auth/verify.php', {
                method: 'GET'
            });

            return response.valid === true;
        } catch (error) {
            this.log('Error verificando token:', error);
            return false;
        }
    }

    /**
     * Refrescar token de acceso
     * @returns {Promise<boolean>} True si se refrescó exitosamente
     */
    async refreshAccessToken() {
        if (!this.refreshToken) {
            this.log('No hay refresh token disponible');
            return false;
        }

        this.log('Refrescando token de acceso');

        try {
            const response = await this.makeRequest('/auth/refresh-token.php', {
                method: 'POST',
                body: JSON.stringify({
                    refresh_token: this.refreshToken
                })
            });

            if (response.status === 'success' && response.tokens) {
                this.token = response.tokens.access_token;
                localStorage.setItem(this.config.tokenKey, this.token);

                if (response.tokens.refresh_token) {
                    this.refreshToken = response.tokens.refresh_token;
                    localStorage.setItem(this.config.refreshTokenKey, this.refreshToken);
                }

                this.setupAutoRefresh();
                this.emit('tokenRefreshed', response.tokens);
                this.log('Token refrescado exitosamente');
                return true;
            } else {
                throw new Error(response.message || 'Error refrescando token');
            }
        } catch (error) {
            this.log('Error refrescando token:', error);
            this.clearAuth();
            this.emit('refreshError', error);
            return false;
        }
    }

    /**
     * Obtener información del usuario actual
     * @returns {Promise<Object>} Datos del usuario
     */
    async getCurrentUser() {
        if (!this.isAuthenticated) {
            throw new Error('Usuario no autenticado');
        }

        try {
            const response = await this.makeRequest('/users/profile.php', {
                method: 'GET'
            });

            if (response.status === 'success') {
                this.currentUser = response.user;
                localStorage.setItem(this.config.userKey, JSON.stringify(this.currentUser));
                this.emit('userUpdated', this.currentUser);
                return this.currentUser;
            } else {
                throw new Error(response.message || 'Error obteniendo usuario');
            }
        } catch (error) {
            this.log('Error obteniendo usuario:', error);
            throw error;
        }
    }

    /**
     * Actualizar perfil del usuario
     * @param {Object} profileData - Datos a actualizar
     * @returns {Promise<Object>} Usuario actualizado
     */
    async updateProfile(profileData) {
        this.log('Actualizando perfil');

        try {
            const response = await this.makeRequest('/users/profile.php', {
                method: 'PUT',
                body: JSON.stringify(profileData)
            });

            if (response.status === 'success') {
                this.currentUser = { ...this.currentUser, ...response.user };
                localStorage.setItem(this.config.userKey, JSON.stringify(this.currentUser));
                this.emit('profileUpdated', this.currentUser);
                this.log('Perfil actualizado');
                return this.currentUser;
            } else {
                throw new Error(response.message || 'Error actualizando perfil');
            }
        } catch (error) {
            this.log('Error actualizando perfil:', error);
            throw error;
        }
    }

    /**
     * Cambiar contraseña
     * @param {Object} passwords - { currentPassword, newPassword, confirmPassword }
     * @returns {Promise<Object>} Respuesta del cambio
     */
    async changePassword(passwords) {
        this.log('Cambiando contraseña');

        try {
            const response = await this.makeRequest('/users/change-password.php', {
                method: 'POST',
                body: JSON.stringify(passwords)
            });

            if (response.status === 'success') {
                this.emit('passwordChanged', response);
                this.log('Contraseña cambiada exitosamente');
                return response;
            } else {
                throw new Error(response.message || 'Error cambiando contraseña');
            }
        } catch (error) {
            this.log('Error cambiando contraseña:', error);
            throw error;
        }
    }

    /**
     * Manejar login exitoso
     * @param {Object} response - Respuesta del servidor
     */
    async handleLoginSuccess(response) {
        // Guardar tokens
        this.token = response.tokens.access_token;
        this.refreshToken = response.tokens.refresh_token;

        localStorage.setItem(this.config.tokenKey, this.token);
        if (this.refreshToken) {
            localStorage.setItem(this.config.refreshTokenKey, this.refreshToken);
        }

        // Guardar usuario
        this.currentUser = response.user;
        localStorage.setItem(this.config.userKey, JSON.stringify(this.currentUser));

        // Marcar como autenticado
        this.isAuthenticated = true;

        // Configurar auto-refresh
        if (this.config.autoRefresh) {
            this.setupAutoRefresh();
        }

        // Emitir evento
        this.emit('authenticated', this.currentUser);
    }

    /**
     * Limpiar toda la información de autenticación
     */
    clearAuth() {
        // Limpiar tokens
        this.token = null;
        this.refreshToken = null;
        this.currentUser = null;
        this.isAuthenticated = false;

        // Limpiar storage
        localStorage.removeItem(this.config.tokenKey);
        localStorage.removeItem(this.config.refreshTokenKey);
        localStorage.removeItem(this.config.userKey);
        localStorage.removeItem(this.config.rememberKey);

        // Limpiar timers
        if (this.refreshTimer) {
            clearTimeout(this.refreshTimer);
            this.refreshTimer = null;
        }

        this.log('Autenticación limpiada');
    }

    /**
     * Configurar auto-refresh del token
     */
    setupAutoRefresh() {
        if (!this.config.autoRefresh || !this.token) {
            return;
        }

        // Limpiar timer anterior
        if (this.refreshTimer) {
            clearTimeout(this.refreshTimer);
        }

        try {
            const tokenData = this.parseJWT(this.token);
            if (!tokenData || !tokenData.exp) {
                return;
            }

            const now = Math.floor(Date.now() / 1000);
            const expiresIn = tokenData.exp - now;
            const refreshIn = Math.max(expiresIn - this.config.refreshThreshold, 0);

            if (refreshIn > 0) {
                this.refreshTimer = setTimeout(() => {
                    this.refreshAccessToken();
                }, refreshIn * 1000);

                this.log(`Auto-refresh programado en ${refreshIn} segundos`);
            } else {
                this.log('Token muy próximo a expirar, refrescando ahora');
                this.refreshAccessToken();
            }
        } catch (error) {
            this.log('Error configurando auto-refresh:', error);
        }
    }

    /**
     * Configurar listener para cambios de storage (múltiples tabs)
     */
    setupStorageListener() {
        window.addEventListener('storage', (e) => {
            if (e.key === this.config.tokenKey) {
                if (e.newValue && e.newValue !== this.token) {
                    // Token actualizado en otra tab
                    this.token = e.newValue;
                    this.loadStoredAuth();
                } else if (!e.newValue && this.token) {
                    // Token eliminado en otra tab
                    this.clearAuth();
                    this.emit('logout');
                }
            }
        });
    }

    /**
     * Configurar listener para visibilidad de página
     */
    setupVisibilityListener() {
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && this.isAuthenticated) {
                // Verificar token cuando la página vuelve a ser visible
                this.verifyToken().then(isValid => {
                    if (!isValid) {
                        this.log('Token inválido detectado al volver a la página');
                        this.clearAuth();
                        this.emit('sessionExpired');
                    }
                });
            }
        });
    }

    // <--- SEGUIR AQUI --->

    /**
         * Realizar petición HTTP autenticada
         * @param {string} endpoint - Endpoint de la API
         * @param {Object} options - Opciones de fetch
         * @returns {Promise<Object>} Respuesta parseada
         */
    async makeRequest(endpoint, options = {}) {
        const url = `${this.config.apiURL}${endpoint}`;

        // Configuración por defecto
        const config = {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                ...options.headers
            },
            ...options
        };

        // Agregar token de autorización si está disponible
        if (this.token && !endpoint.includes('/auth/login') && !endpoint.includes('/auth/register')) {
            config.headers['Authorization'] = `Bearer ${this.token}`;
        }

        // Agregar CSRF token si está disponible
        const csrfToken = sessionStorage.getItem('csrf_token');
        if (csrfToken) {
            config.headers['X-CSRF-Token'] = csrfToken;
        }

        this.log(`${config.method} ${endpoint}`, config.body ? JSON.parse(config.body) : null);

        try {
            const response = await fetch(url, config);

            // Manejar respuestas no JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error(`Respuesta no JSON del servidor: ${response.status}`);
            }

            const data = await response.json();

            // Manejar errores HTTP
            if (!response.ok) {
                // Token expirado o inválido
                if (response.status === 401 && this.isAuthenticated) {
                    this.log('Token expirado, intentando refresh');

                    // Intentar refresh automático una vez
                    if (endpoint !== '/auth/refresh-token.php' && this.refreshToken) {
                        const refreshed = await this.refreshAccessToken();
                        if (refreshed && this.retryCount < this.config.maxRetries) {
                            this.retryCount++;
                            // Reintentar petición original con nuevo token
                            config.headers['Authorization'] = `Bearer ${this.token}`;
                            return this.makeRequest(endpoint, options);
                        }
                    }

                    // Si no se pudo refrescar, limpiar auth
                    this.clearAuth();
                    this.emit('sessionExpired');
                }

                throw new Error(data.message || `Error HTTP ${response.status}`);
            }

            // Resetear contador de reintentos en éxito
            this.retryCount = 0;

            this.log(`Respuesta ${config.method} ${endpoint}:`, data);
            return data;

        } catch (error) {
            this.log(`Error en ${config.method} ${endpoint}:`, error);

            // Reintentar en caso de error de red
            if (this.retryCount < this.config.maxRetries && this.isNetworkError(error)) {
                this.retryCount++;
                this.log(`Reintentando petición (${this.retryCount}/${this.config.maxRetries})`);

                await this.delay(this.config.retryDelay * this.retryCount);
                return this.makeRequest(endpoint, options);
            }

            this.retryCount = 0;
            throw error;
        }
    }

    /**
     * Verificar si es un error de red
     * @param {Error} error - Error a verificar
     * @returns {boolean} True si es error de red
     */
    isNetworkError(error) {
        return error instanceof TypeError ||
            error.message.includes('fetch') ||
            error.message.includes('network') ||
            error.message.includes('offline');
    }

    /**
     * Delay para reintentos
     * @param {number} ms - Milisegundos a esperar
     * @returns {Promise<void>}
     */
    delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    /**
     * Parsear JWT token
     * @param {string} token - Token JWT
     * @returns {Object|null} Payload del token
     */
    parseJWT(token) {
        try {
            const base64Url = token.split('.')[1];
            const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
            const jsonPayload = decodeURIComponent(
                atob(base64)
                    .split('')
                    .map(c => '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2))
                    .join('')
            );
            return JSON.parse(jsonPayload);
        } catch (error) {
            this.log('Error parseando JWT:', error);
            return null;
        }
    }

    /**
     * Verificar si el token está próximo a expirar
     * @param {number} threshold - Umbral en segundos (default: 300)
     * @returns {boolean} True si está próximo a expirar
     */
    isTokenExpiringSoon(threshold = 300) {
        if (!this.token) return true;

        const tokenData = this.parseJWT(this.token);
        if (!tokenData || !tokenData.exp) return true;

        const now = Math.floor(Date.now() / 1000);
        return (tokenData.exp - now) < threshold;
    }

    /**
     * Obtener información del token actual
     * @returns {Object|null} Información del token
     */
    getTokenInfo() {
        if (!this.token) return null;

        const tokenData = this.parseJWT(this.token);
        if (!tokenData) return null;

        const now = Math.floor(Date.now() / 1000);

        return {
            ...tokenData,
            isValid: tokenData.exp > now,
            expiresIn: tokenData.exp - now,
            expiringSoon: this.isTokenExpiringSoon()
        };
    }

    /**
     * Sistema de eventos
     */
    on(event, callback) {
        if (!this.eventListeners.has(event)) {
            this.eventListeners.set(event, []);
        }
        this.eventListeners.get(event).push(callback);
    }

    off(event, callback) {
        if (this.eventListeners.has(event)) {
            const listeners = this.eventListeners.get(event);
            const index = listeners.indexOf(callback);
            if (index > -1) {
                listeners.splice(index, 1);
            }
        }
    }

    emit(event, data = null) {
        this.log(`Evento emitido: ${event}`, data);

        if (this.eventListeners.has(event)) {
            this.eventListeners.get(event).forEach(callback => {
                try {
                    callback(data);
                } catch (error) {
                    this.log(`Error en listener de ${event}:`, error);
                }
            });
        }
    }

    /**
     * Logging interno
     */
    log(message, data = null) {
        if (this.config.debug) {
            console.log(`[AuthClient] ${message}`, data || '');
        }
    }

    /**
     * Cleanup - limpiar recursos
     */
    destroy() {
        this.log('Destruyendo AuthClient');

        if (this.refreshTimer) {
            clearTimeout(this.refreshTimer);
            this.refreshTimer = null;
        }

        this.eventListeners.clear();

        // Remover event listeners
        window.removeEventListener('storage', this.storageListener);
        document.removeEventListener('visibilitychange', this.visibilityListener);
    }

    /**
     * Obtener estado actual de autenticación
     * @returns {Object} Estado completo
     */
    getAuthState() {
        return {
            isAuthenticated: this.isAuthenticated,
            user: this.currentUser,
            token: this.token ? '***' : null,
            hasRefreshToken: !!this.refreshToken,
            tokenInfo: this.getTokenInfo()
        };
    }

    /**
     * Validar sesión actual
     * @returns {Promise<boolean>} True si la sesión es válida
     */
    async validateSession() {
        if (!this.isAuthenticated || !this.token) {
            return false;
        }

        try {
            // Verificar token localmente primero
            const tokenInfo = this.getTokenInfo();
            if (!tokenInfo || !tokenInfo.isValid) {
                this.log('Token inválido localmente');
                return false;
            }

            // Verificar con el servidor
            const isValid = await this.verifyToken();
            if (!isValid) {
                this.log('Token inválido en servidor');
                this.clearAuth();
                return false;
            }

            return true;
        } catch (error) {
            this.log('Error validando sesión:', error);
            return false;
        }
    }
}

/**
 * AuthValidator - Validaciones de formularios de autenticación
 */
class AuthValidator {
    constructor() {
        this.rules = {
            email: {
                required: true,
                pattern: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
                message: 'Ingresa un email válido'
            },
            password: {
                required: true,
                minLength: 6,
                message: 'La contraseña debe tener al menos 6 caracteres'
            },
            confirmPassword: {
                required: true,
                match: 'password',
                message: 'Las contraseñas no coinciden'
            },
            firstName: {
                required: true,
                minLength: 2,
                pattern: /^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/,
                message: 'Nombre inválido (solo letras)'
            },
            lastName: {
                required: true,
                minLength: 2,
                pattern: /^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/,
                message: 'Apellido inválido (solo letras)'
            },
            businessName: {
                required: true,
                minLength: 3,
                message: 'Nombre del negocio muy corto'
            },
            phone: {
                required: true,
                pattern: /^[\+]?[\d\s\-\(\)]{8,}$/,
                message: 'Teléfono inválido'
            }
        };
    }

    /**
     * Validar campo individual
     * @param {string} field - Nombre del campo
     * @param {string} value - Valor a validar
     * @param {Object} formData - Datos completos del formulario
     * @returns {Object} { isValid, message }
     */
    validateField(field, value, formData = {}) {
        const rule = this.rules[field];
        if (!rule) {
            return { isValid: true, message: '' };
        }

        // Required
        if (rule.required && (!value || value.trim() === '')) {
            return { isValid: false, message: rule.message || `${field} es requerido` };
        }

        // Si está vacío y no es requerido, es válido
        if (!value && !rule.required) {
            return { isValid: true, message: '' };
        }

        // Min length
        if (rule.minLength && value.length < rule.minLength) {
            return {
                isValid: false,
                message: `Debe tener al menos ${rule.minLength} caracteres`
            };
        }

        // Pattern
        if (rule.pattern && !rule.pattern.test(value)) {
            return { isValid: false, message: rule.message };
        }

        // Match (para confirmar contraseña)
        if (rule.match && formData[rule.match] !== value) {
            return { isValid: false, message: rule.message };
        }

        return { isValid: true, message: '' };
    }

    /**
     * Validar formulario completo
     * @param {Object} formData - Datos del formulario
     * @param {Array} fields - Campos a validar
     * @returns {Object} { isValid, errors }
     */
    validateForm(formData, fields) {
        const errors = {};
        let isValid = true;

        fields.forEach(field => {
            const result = this.validateField(field, formData[field], formData);
            if (!result.isValid) {
                errors[field] = result.message;
                isValid = false;
            }
        });

        return { isValid, errors };
    }

    /**
     * Validar fuerza de contraseña
     * @param {string} password - Contraseña a validar
     * @returns {Object} { score, feedback }
     */
    validatePasswordStrength(password) {
        let score = 0;
        const feedback = [];

        if (!password) {
            return { score: 0, feedback: ['Contraseña requerida'] };
        }

        // Longitud
        if (password.length >= 8) score += 1;
        else feedback.push('Al menos 8 caracteres');

        // Mayúsculas
        if (/[A-Z]/.test(password)) score += 1;
        else feedback.push('Al menos una mayúscula');

        // Minúsculas
        if (/[a-z]/.test(password)) score += 1;
        else feedback.push('Al menos una minúscula');

        // Números
        if (/\d/.test(password)) score += 1;
        else feedback.push('Al menos un número');

        // Caracteres especiales
        if (/[^A-Za-z0-9]/.test(password)) score += 1;
        else feedback.push('Al menos un carácter especial');

        const strength = ['Muy débil', 'Débil', 'Regular', 'Fuerte', 'Muy fuerte'][score];

        return { score, strength, feedback };
    }
}

/**
 * AuthUI - Utilidades para UI de autenticación
 */
class AuthUI {
    constructor(authClient) {
        this.auth = authClient;
        this.validator = new AuthValidator();
    }

    /**
     * Mostrar estado de loading en botón
     * @param {HTMLElement} button - Botón a modificar
     * @param {boolean} loading - Estado de loading
     */
    setButtonLoading(button, loading) {
        if (loading) {
            button.disabled = true;
            button.classList.add('loading');

            const originalText = button.textContent;
            button.dataset.originalText = originalText;

            const spinner = button.querySelector('.btn-spinner') || this.createSpinner();
            button.appendChild(spinner);

            const textElement = button.querySelector('.btn-text');
            if (textElement) {
                textElement.style.display = 'none';
            }
        } else {
            button.disabled = false;
            button.classList.remove('loading');

            const spinner = button.querySelector('.btn-spinner');
            if (spinner) {
                spinner.remove();
            }

            const textElement = button.querySelector('.btn-text');
            if (textElement) {
                textElement.style.display = 'inline-flex';
            } else if (button.dataset.originalText) {
                button.textContent = button.dataset.originalText;
            }
        }
    }

    /**
     * Crear spinner element
     * @returns {HTMLElement} Elemento spinner
     */
    createSpinner() {
        const spinner = document.createElement('div');
        spinner.className = 'btn-spinner';
        spinner.innerHTML = '';
        return spinner;
    }

    /**
     * Mostrar error en campo de formulario
     * @param {HTMLElement} field - Campo input
     * @param {string} message - Mensaje de error
     */
    showFieldError(field, message) {
        field.classList.add('error');
        field.classList.remove('success');

        let errorElement = field.parentNode.querySelector('.form-error');
        if (!errorElement) {
            errorElement = document.createElement('div');
            errorElement.className = 'form-error';
            field.parentNode.appendChild(errorElement);
        }

        errorElement.textContent = message;
        errorElement.classList.add('show');
    }

    /**
     * Ocultar error en campo de formulario
     * @param {HTMLElement} field - Campo input
     */
    hideFieldError(field) {
        field.classList.remove('error');
        field.classList.add('success');

        const errorElement = field.parentNode.querySelector('.form-error');
        if (errorElement) {
            errorElement.classList.remove('show');
        }
    }

    /**
     * Mostrar alerta global
     * @param {string} message - Mensaje a mostrar
     * @param {string} type - Tipo: success, error, warning
     * @param {number} duration - Duración en ms (0 = no auto-hide)
     */
    showAlert(message, type = 'info', duration = 5000) {
        // Crear o obtener container de alertas
        let container = document.querySelector('.alerts-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'alerts-container';
            container.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 10000;
                max-width: 400px;
            `;
            document.body.appendChild(container);
        }

        // Crear alerta
        const alert = document.createElement('div');
        alert.className = `alert alert-${type}`;
        alert.innerHTML = `
            <i class="fas fa-${this.getAlertIcon(type)}"></i>
            <span>${message}</span>
            <button type="button" class="alert-close">
                <i class="fas fa-times"></i>
            </button>
        `;

        // Agregar al container
        container.appendChild(alert);

        // Animar entrada
        setTimeout(() => alert.classList.add('show'), 10);

        // Auto-hide
        if (duration > 0) {
            setTimeout(() => this.hideAlert(alert), duration);
        }

        // Click para cerrar
        alert.querySelector('.alert-close').addEventListener('click', () => {
            this.hideAlert(alert);
        });

        return alert;
    }

    /**
     * Ocultar alerta
     * @param {HTMLElement} alert - Elemento de alerta
     */
    hideAlert(alert) {
        alert.classList.remove('show');
        setTimeout(() => {
            if (alert.parentNode) {
                alert.parentNode.removeChild(alert);
            }
        }, 300);
    }

    /**
     * Obtener icono para tipo de alerta
     * @param {string} type - Tipo de alerta
     * @returns {string} Clase de icono
     */
    getAlertIcon(type) {
        const icons = {
            success: 'check-circle',
            error: 'exclamation-circle',
            warning: 'exclamation-triangle',
            info: 'info-circle'
        };
        return icons[type] || icons.info;
    }

    /**
     * Validar formulario en tiempo real
     * @param {HTMLFormElement} form - Formulario
     * @param {Array} fields - Campos a validar
     */
    setupRealTimeValidation(form, fields) {
        fields.forEach(fieldName => {
            const field = form.querySelector(`[name="${fieldName}"]`);
            if (!field) return;

            field.addEventListener('blur', () => {
                const formData = new FormData(form);
                const value = formData.get(fieldName);
                const result = this.validator.validateField(fieldName, value, Object.fromEntries(formData));

                if (!result.isValid) {
                    this.showFieldError(field, result.message);
                } else {
                    this.hideFieldError(field);
                }
            });

            field.addEventListener('input', () => {
                if (field.classList.contains('error')) {
                    const formData = new FormData(form);
                    const value = formData.get(fieldName);
                    const result = this.validator.validateField(fieldName, value, Object.fromEntries(formData));

                    if (result.isValid) {
                        this.hideFieldError(field);
                    }
                }
            });
        });
    }
}

// <--- SEGUIR AQUI --->

/**
 * AuthManager - Factory y gestión global de autenticación
 * Proporciona una interfaz simplificada para usar en cualquier página
 */
class AuthManager {
    constructor() {
        this.client = null;
        this.ui = null;
        this.initialized = false;
        this.config = {
            debug: window.location.hostname === 'localhost',
            autoRefresh: true,
            redirects: {
                loginSuccess: '/pages/dashboard.html',
                loginRequired: '/pages/login.html',
                emailVerification: '/pages/verify-email.html',
                passwordReset: '/pages/reset-password.html'
            }
        };
    }

    /**
     * Inicializar el AuthManager
     * @param {Object} options - Configuración opcional
     * @returns {Promise<AuthManager>} Instancia inicializada
     */
    async init(options = {}) {
        if (this.initialized) {
            return this;
        }

        // Combinar configuración
        this.config = { ...this.config, ...options };

        // Crear cliente de autenticación
        this.client = new AuthClient({
            debug: this.config.debug,
            autoRefresh: this.config.autoRefresh,
            ...this.config.clientOptions
        });

        // Crear utilidades de UI
        this.ui = new AuthUI(this.client);

        // Configurar eventos globales
        this.setupGlobalEvents();

        // Configurar redirects automáticos
        this.setupAutoRedirects();

        this.initialized = true;
        this.log('AuthManager inicializado');

        return this;
    }

    /**
     * Configurar eventos globales
     */
    setupGlobalEvents() {
        // Sesión expirada
        this.client.on('sessionExpired', () => {
            this.ui.showAlert('Tu sesión ha expirado. Inicia sesión nuevamente.', 'warning');
            setTimeout(() => {
                this.redirectTo(this.config.redirects.loginRequired);
            }, 2000);
        });

        // Login exitoso
        this.client.on('authenticated', (user) => {
            this.ui.showAlert(`¡Bienvenido, ${user.first_name}!`, 'success');
        });

        // Logout
        this.client.on('logout', () => {
            this.ui.showAlert('Sesión cerrada correctamente', 'info');
        });

        // Errores de conexión
        this.client.on('networkError', (error) => {
            this.ui.showAlert('Error de conexión. Verifica tu internet.', 'error');
        });

        // Token refrescado
        this.client.on('tokenRefreshed', () => {
            this.log('Token refrescado automáticamente');
        });
    }

    /**
     * Configurar redirects automáticos
     */
    setupAutoRedirects() {
        const currentPage = window.location.pathname;
        const protectedPages = ['/pages/dashboard.html', '/pages/profile.html', '/pages/settings.html'];
        const authPages = ['/pages/login.html', '/pages/register.html'];

        // Redirect si está en página protegida sin autenticación
        if (protectedPages.some(page => currentPage.includes(page))) {
            if (!this.client.isAuthenticated) {
                this.redirectTo(this.config.redirects.loginRequired);
                return;
            }
        }

        // Redirect si está autenticado en páginas de auth
        if (authPages.some(page => currentPage.includes(page))) {
            if (this.client.isAuthenticated) {
                this.redirectTo(this.config.redirects.loginSuccess);
                return;
            }
        }
    }

    /**
     * Login simplificado
     * @param {Object} credentials - { email, password, remember }
     * @param {Object} options - Opciones adicionales
     * @returns {Promise<Object>} Resultado del login
     */
    async login(credentials, options = {}) {
        try {
            const result = await this.client.login(credentials);

            if (options.redirect !== false) {
                setTimeout(() => {
                    this.redirectTo(options.redirectTo || this.config.redirects.loginSuccess);
                }, 1500);
            }

            return result;
        } catch (error) {
            this.ui.showAlert(error.message, 'error');
            throw error;
        }
    }

    /**
     * Registro simplificado
     * @param {Object} userData - Datos del usuario
     * @param {Object} options - Opciones adicionales
     * @returns {Promise<Object>} Resultado del registro
     */
    async register(userData, options = {}) {
        try {
            const result = await this.client.register(userData);

            this.ui.showAlert('Registro exitoso. Revisa tu email para verificar tu cuenta.', 'success');

            if (options.redirect !== false) {
                setTimeout(() => {
                    this.redirectTo(options.redirectTo || this.config.redirects.emailVerification);
                }, 2000);
            }

            return result;
        } catch (error) {
            this.ui.showAlert(error.message, 'error');
            throw error;
        }
    }

    /**
     * Logout simplificado
     * @param {Object} options - Opciones adicionales
     */
    async logout(options = {}) {
        try {
            await this.client.logout();

            if (options.redirect !== false) {
                setTimeout(() => {
                    this.redirectTo(options.redirectTo || '/');
                }, 1000);
            }
        } catch (error) {
            this.ui.showAlert('Error cerrando sesión', 'error');
        }
    }

    /**
     * Verificar email
     * @param {string} token - Token de verificación
     * @returns {Promise<Object>} Resultado de la verificación
     */
    async verifyEmail(token) {
        try {
            const result = await this.client.verifyEmail(token);
            this.ui.showAlert('Email verificado correctamente. Ya puedes iniciar sesión.', 'success');

            setTimeout(() => {
                this.redirectTo(this.config.redirects.loginRequired);
            }, 2000);

            return result;
        } catch (error) {
            this.ui.showAlert(error.message, 'error');
            throw error;
        }
    }

    /**
     * Solicitar recuperación de contraseña
     * @param {string} email - Email del usuario
     * @returns {Promise<Object>} Resultado de la solicitud
     */
    async forgotPassword(email) {
        try {
            const result = await this.client.forgotPassword(email);
            this.ui.showAlert('Instrucciones enviadas a tu email.', 'success');
            return result;
        } catch (error) {
            this.ui.showAlert(error.message, 'error');
            throw error;
        }
    }

    /**
     * Resetear contraseña
     * @param {Object} data - { token, password, confirmPassword }
     * @returns {Promise<Object>} Resultado del reset
     */
    async resetPassword(data) {
        try {
            const result = await this.client.resetPassword(data);
            this.ui.showAlert('Contraseña cambiada exitosamente.', 'success');

            setTimeout(() => {
                this.redirectTo(this.config.redirects.loginRequired);
            }, 2000);

            return result;
        } catch (error) {
            this.ui.showAlert(error.message, 'error');
            throw error;
        }
    }

    /**
     * Configurar formulario de login automáticamente
     * @param {string|HTMLElement} formSelector - Selector o elemento del formulario
     * @param {Object} options - Opciones de configuración
     */
    setupLoginForm(formSelector, options = {}) {
        const form = typeof formSelector === 'string'
            ? document.querySelector(formSelector)
            : formSelector;

        if (!form) {
            this.log('Formulario de login no encontrado:', formSelector);
            return;
        }

        // Configurar validación en tiempo real
        this.ui.setupRealTimeValidation(form, ['email', 'password']);

        // Manejar submit
        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const formData = new FormData(form);
            const credentials = {
                email: formData.get('email')?.trim(),
                password: formData.get('password'),
                remember: formData.get('remember') === 'on'
            };

            // Validar formulario
            const validation = this.ui.validator.validateForm(credentials, ['email', 'password']);
            if (!validation.isValid) {
                Object.keys(validation.errors).forEach(field => {
                    const fieldElement = form.querySelector(`[name="${field}"]`);
                    if (fieldElement) {
                        this.ui.showFieldError(fieldElement, validation.errors[field]);
                    }
                });
                return;
            }

            // Mostrar loading en botón
            const submitBtn = form.querySelector('[type="submit"]');
            this.ui.setButtonLoading(submitBtn, true);

            try {
                await this.login(credentials, options);
            } catch (error) {
                // Error ya mostrado en login()
            } finally {
                this.ui.setButtonLoading(submitBtn, false);
            }
        });

        this.log('Formulario de login configurado');
    }

    /**
     * Configurar formulario de registro automáticamente
     * @param {string|HTMLElement} formSelector - Selector o elemento del formulario
     * @param {Object} options - Opciones de configuración
     */
    setupRegisterForm(formSelector, options = {}) {
        const form = typeof formSelector === 'string'
            ? document.querySelector(formSelector)
            : formSelector;

        if (!form) {
            this.log('Formulario de registro no encontrado:', formSelector);
            return;
        }

        // Configurar validación en tiempo real
        const fields = ['email', 'password', 'confirmPassword', 'firstName', 'lastName', 'businessName', 'phone'];
        this.ui.setupRealTimeValidation(form, fields);

        // Configurar indicador de fuerza de contraseña
        const passwordField = form.querySelector('[name="password"]');
        if (passwordField) {
            this.setupPasswordStrengthIndicator(passwordField);
        }

        // Manejar submit
        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const formData = new FormData(form);
            const userData = {
                email: formData.get('email')?.trim(),
                password: formData.get('password'),
                confirmPassword: formData.get('confirmPassword'),
                first_name: formData.get('firstName')?.trim(),
                last_name: formData.get('lastName')?.trim(),
                business_name: formData.get('businessName')?.trim(),
                phone: formData.get('phone')?.trim(),
                terms_accepted: formData.get('terms') === 'on'
            };

            // Validar formulario
            const validation = this.ui.validator.validateForm(userData, fields);
            if (!validation.isValid) {
                Object.keys(validation.errors).forEach(field => {
                    const fieldElement = form.querySelector(`[name="${field}"]`);
                    if (fieldElement) {
                        this.ui.showFieldError(fieldElement, validation.errors[field]);
                    }
                });
                return;
            }

            // Verificar términos
            if (!userData.terms_accepted) {
                this.ui.showAlert('Debes aceptar los términos y condiciones', 'error');
                return;
            }

            // Mostrar loading en botón
            const submitBtn = form.querySelector('[type="submit"]');
            this.ui.setButtonLoading(submitBtn, true);

            try {
                await this.register(userData, options);
            } catch (error) {
                // Error ya mostrado en register()
            } finally {
                this.ui.setButtonLoading(submitBtn, false);
            }
        });

        this.log('Formulario de registro configurado');
    }

    /**
     * Configurar indicador de fuerza de contraseña
     * @param {HTMLElement} passwordField - Campo de contraseña
     */
    setupPasswordStrengthIndicator(passwordField) {
        let indicator = passwordField.parentNode.querySelector('.password-strength');

        if (!indicator) {
            indicator = document.createElement('div');
            indicator.className = 'password-strength';
            indicator.innerHTML = `
                <div class="strength-bar">
                    <div class="strength-fill"></div>
                </div>
                <div class="strength-text"></div>
            `;
            passwordField.parentNode.appendChild(indicator);
        }

        passwordField.addEventListener('input', (e) => {
            const password = e.target.value;
            const strength = this.ui.validator.validatePasswordStrength(password);

            const fill = indicator.querySelector('.strength-fill');
            const text = indicator.querySelector('.strength-text');

            // Actualizar barra
            fill.style.width = `${(strength.score / 5) * 100}%`;
            fill.className = `strength-fill strength-${strength.score}`;

            // Actualizar texto
            if (password) {
                text.textContent = strength.strength;
                indicator.style.display = 'block';
            } else {
                indicator.style.display = 'none';
            }
        });
    }

    /**
     * Proteger página actual
     * Redirige a login si no está autenticado
     */
    requireAuth() {
        if (!this.client.isAuthenticated) {
            this.ui.showAlert('Debes iniciar sesión para acceder a esta página', 'warning');
            setTimeout(() => {
                this.redirectTo(this.config.redirects.loginRequired);
            }, 1500);
            return false;
        }
        return true;
    }

    /**
     * Verificar permisos del usuario
     * @param {string|Array} requiredPermissions - Permisos requeridos
     * @returns {boolean} True si tiene permisos
     */
    hasPermission(requiredPermissions) {
        if (!this.client.isAuthenticated || !this.client.currentUser) {
            return false;
        }

        const userPermissions = this.client.currentUser.permissions || [];
        const required = Array.isArray(requiredPermissions)
            ? requiredPermissions
            : [requiredPermissions];

        return required.every(permission => userPermissions.includes(permission));
    }

    /**
     * Obtener información del usuario actual
     * @returns {Object|null} Datos del usuario
     */
    getCurrentUser() {
        return this.client.currentUser;
    }

    /**
     * Verificar si está autenticado
     * @returns {boolean} Estado de autenticación
     */
    isAuthenticated() {
        return this.client.isAuthenticated;
    }

    /**
     * Obtener token actual
     * @returns {string|null} Token de acceso
     */
    getToken() {
        return this.client.token;
    }

    /**
     * Configurar interceptor de requests para axios/fetch
     * @param {Object} httpClient - Cliente HTTP (axios, etc.)
     */
    setupHttpInterceptor(httpClient) {
        if (httpClient.interceptors) {
            // Axios
            httpClient.interceptors.request.use(
                (config) => {
                    if (this.client.token) {
                        config.headers.Authorization = `Bearer ${this.client.token}`;
                    }
                    return config;
                },
                (error) => Promise.reject(error)
            );

            httpClient.interceptors.response.use(
                (response) => response,
                async (error) => {
                    if (error.response?.status === 401 && this.client.isAuthenticated) {
                        this.client.emit('sessionExpired');
                    }
                    return Promise.reject(error);
                }
            );
        }
    }

    /**
     * Utilidad para redireccionar
     * @param {string} url - URL de destino
     */
    redirectTo(url) {
        window.location.href = url;
    }

    /**
     * Logging interno
     */
    log(message, data = null) {
        if (this.config.debug) {
            console.log(`[AuthManager] ${message}`, data || '');
        }
    }

    /**
     * Destruir instancia
     */
    destroy() {
        if (this.client) {
            this.client.destroy();
        }
        this.initialized = false;
    }
}

/**
 * Instancia global de AuthManager
 * Se auto-inicializa cuando se carga el script
 */
let authManagerInstance = null;

/**
 * Función de inicialización global
 * @param {Object} options - Opciones de configuración
 * @returns {Promise<AuthManager>} Instancia de AuthManager
 */
window.initAuth = async function (options = {}) {
    if (!authManagerInstance) {
        authManagerInstance = new AuthManager();
        await authManagerInstance.init(options);
    }
    return authManagerInstance;
};

/**
 * Getter para la instancia global
 * @returns {AuthManager|null} Instancia de AuthManager
 */
Object.defineProperty(window, 'auth', {
    get: function () {
        return authManagerInstance;
    },
    configurable: false
});

/**
 * Auto-inicialización cuando el DOM está listo
 */
document.addEventListener('DOMContentLoaded', async () => {
    try {
        // Auto-inicializar con configuración por defecto
        await window.initAuth();

        // Auto-configurar formularios si existen
        const loginForm = document.querySelector('#loginForm, .login-form, form[data-auth="login"]');
        if (loginForm) {
            authManagerInstance.setupLoginForm(loginForm);
        }

        const registerForm = document.querySelector('#registerForm, .register-form, form[data-auth="register"]');
        if (registerForm) {
            authManagerInstance.setupRegisterForm(registerForm);
        }

        // Auto-proteger páginas marcadas
        if (document.body.hasAttribute('data-require-auth')) {
            authManagerInstance.requireAuth();
        }

        console.log('🔐 Auth system initialized');
    } catch (error) {
        console.error('Error inicializando sistema de autenticación:', error);
    }
});

// <--- SEGUIR AQUI --->
/**
 * CSS Styles para componentes de autenticación
 * Se inyectan automáticamente cuando se carga el script
 */
const authStyles = `
/* ===== AUTH SYSTEM STYLES ===== */
/* Contenedor de alertas */
.alerts-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 10000;
    max-width: 400px;
    pointer-events: none;
}

.alerts-container .alert {
    pointer-events: auto;
    background: white;
    border-radius: 8px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    padding: 16px;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
    font-weight: 500;
    border-left: 4px solid;
    opacity: 0;
    transform: translateX(100px);
    transition: all 0.3s ease;
}

.alerts-container .alert.show {
    opacity: 1;
    transform: translateX(0);
}

.alerts-container .alert-success {
    border-color: #10B981;
    color: #047857;
    background: #ECFDF5;
}

.alerts-container .alert-error {
    border-color: #EF4444;
    color: #DC2626;
    background: #FEF2F2;
}

.alerts-container .alert-warning {
    border-color: #F59E0B;
    color: #D97706;
    background: #FFFBEB;
}

.alerts-container .alert-info {
    border-color: #3B82F6;
    color: #1E40AF;
    background: #EFF6FF;
}

.alerts-container .alert-close {
    margin-left: auto;
    background: none;
    border: none;
    color: inherit;
    cursor: pointer;
    font-size: 16px;
    padding: 4px;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.alerts-container .alert-close:hover {
    opacity: 1;
}

/* Estados de campos de formulario */
.form-input.error {
    border-color: #EF4444 !important;
    box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1) !important;
    background-color: #FEF2F2;
}

.form-input.success {
    border-color: #10B981 !important;
    background-color: #ECFDF5;
    background-image: url("data:image/svg+xml;charset=utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath fill='%2310B981' d='M13.854 3.646a.5.5 0 0 1 0 .708l-7 7a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L6.5 10.293l6.646-6.647a.5.5 0 0 1 .708 0z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    background-size: 16px 16px;
    padding-right: 40px;
}

.form-error {
    color: #EF4444;
    font-size: 12px;
    margin-top: 4px;
    display: none;
    font-weight: 500;
}

.form-error.show {
    display: block;
    animation: slideInError 0.3s ease;
}

@keyframes slideInError {
    from {
        opacity: 0;
        transform: translateY(-5px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Estados de loading en botones */
.btn-spinner {
    width: 16px;
    height: 16px;
    border: 2px solid transparent;
    border-top: 2px solid currentColor;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    display: none;
}

.btn.loading .btn-spinner {
    display: inline-block;
}

.btn.loading .btn-text {
    display: none;
}

.btn.loading {
    pointer-events: none;
    opacity: 0.8;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Indicador de fuerza de contraseña */
.password-strength {
    margin-top: 8px;
    display: none;
}

.strength-bar {
    width: 100%;
    height: 4px;
    background: #E5E7EB;
    border-radius: 2px;
    overflow: hidden;
    margin-bottom: 4px;
}

.strength-fill {
    height: 100%;
    transition: all 0.3s ease;
    border-radius: 2px;
}

.strength-fill.strength-0 {
    width: 0%;
    background: #EF4444;
}

.strength-fill.strength-1 {
    width: 20%;
    background: #EF4444;
}

.strength-fill.strength-2 {
    width: 40%;
    background: #F59E0B;
}

.strength-fill.strength-3 {
    width: 60%;
    background: #F59E0B;
}

.strength-fill.strength-4 {
    width: 80%;
    background: #10B981;
}

.strength-fill.strength-5 {
    width: 100%;
    background: #10B981;
}

.strength-text {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Responsive para alertas */
@media (max-width: 768px) {
    .alerts-container {
        top: 10px;
        right: 10px;
        left: 10px;
        max-width: none;
    }
    
    .alerts-container .alert {
        font-size: 13px;
        padding: 14px;
    }
}

/* Dark mode support */
@media (prefers-color-scheme: dark) {
    .alerts-container .alert {
        background: #1F2937;
        color: white;
        box-shadow: 0 10px 25px rgba(0,0,0,0.3);
    }
    
    .alerts-container .alert-success {
        background: #064E3B;
        color: #6EE7B7;
    }
    
    .alerts-container .alert-error {
        background: #7F1D1D;
        color: #FCA5A5;
    }
    
    .alerts-container .alert-warning {
        background: #78350F;
        color: #FCD34D;
    }
    
    .alerts-container .alert-info {
        background: #1E3A8A;
        color: #93C5FD;
    }
}

/* Accessibility improvements */
@media (prefers-reduced-motion: reduce) {
    .alerts-container .alert,
    .form-error,
    .btn-spinner,
    .strength-fill {
        animation: none !important;
        transition: none !important;
    }
}

/* Print styles */
@media print {
    .alerts-container {
        display: none !important;
    }
}
`;

/**
 * Utilitarios adicionales para el sistema de autenticación
 */
const AuthUtils = {
    /**
     * Debounce function para optimizar validaciones
     * @param {Function} func - Función a ejecutar
     * @param {number} wait - Tiempo de espera en ms
     * @returns {Function} Función con debounce
     */
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    },

    /**
     * Throttle function para limitar ejecuciones
     * @param {Function} func - Función a ejecutar
     * @param {number} limit - Límite en ms
     * @returns {Function} Función con throttle
     */
    throttle(func, limit) {
        let inThrottle;
        return function executedFunction(...args) {
            if (!inThrottle) {
                func.apply(this, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    },

    /**
     * Generar UUID simple
     * @returns {string} UUID
     */
    generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            const r = Math.random() * 16 | 0;
            const v = c == 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    },

    /**
     * Sanitizar string para prevenir XSS
     * @param {string} str - String a sanitizar
     * @returns {string} String sanitizado
     */
    sanitizeString(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },

    /**
     * Validar formato de email
     * @param {string} email - Email a validar
     * @returns {boolean} True si es válido
     */
    isValidEmail(email) {
        const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return regex.test(email);
    },

    /**
     * Generar contraseña aleatoria
     * @param {number} length - Longitud de la contraseña
     * @returns {string} Contraseña generada
     */
    generatePassword(length = 12) {
        const charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        let password = '';
        for (let i = 0; i < length; i++) {
            password += charset.charAt(Math.floor(Math.random() * charset.length));
        }
        return password;
    },

    /**
     * Formatear fecha para mostrar
     * @param {Date|string} date - Fecha a formatear
     * @returns {string} Fecha formateada
     */
    formatDate(date) {
        const d = new Date(date);
        return new Intl.DateTimeFormat('es-AR', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        }).format(d);
    },

    /**
     * Copiar texto al portapapeles
     * @param {string} text - Texto a copiar
     * @returns {Promise<boolean>} True si se copió exitosamente
     */
    async copyToClipboard(text) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch (err) {
            // Fallback para navegadores antiguos
            const textArea = document.createElement('textarea');
            textArea.value = text;
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            try {
                const successful = document.execCommand('copy');
                document.body.removeChild(textArea);
                return successful;
            } catch (err) {
                document.body.removeChild(textArea);
                return false;
            }
        }
    },

    /**
     * Detectar dispositivo móvil
     * @returns {boolean} True si es móvil
     */
    isMobile() {
        return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
    },

    /**
     * Obtener información del navegador
     * @returns {Object} Información del navegador
     */
    getBrowserInfo() {
        const ua = navigator.userAgent;
        let browser = 'Unknown';
        let version = 'Unknown';

        if (ua.indexOf('Chrome') > -1) {
            browser = 'Chrome';
            version = ua.match(/Chrome\/(\d+)/)?.[1] || 'Unknown';
        } else if (ua.indexOf('Firefox') > -1) {
            browser = 'Firefox';
            version = ua.match(/Firefox\/(\d+)/)?.[1] || 'Unknown';
        } else if (ua.indexOf('Safari') > -1) {
            browser = 'Safari';
            version = ua.match(/Version\/(\d+)/)?.[1] || 'Unknown';
        } else if (ua.indexOf('Edge') > -1) {
            browser = 'Edge';
            version = ua.match(/Edge\/(\d+)/)?.[1] || 'Unknown';
        }

        return { browser, version, userAgent: ua };
    }
};

/**
 * Configuración de desarrollo y debugging
 */
const DevConfig = {
    // Credenciales de prueba para desarrollo
    demoCredentials: {
        email: 'demo@reservafacil.com',
        password: 'demo123456',
        adminEmail: 'admin@reservafacil.com',
        adminPassword: 'admin123456'
    },

    // Endpoints de prueba
    mockEndpoints: {
        '/auth/login.php': {
            status: 'success',
            message: 'Login exitoso',
            user: {
                id: 'demo-user-123',
                email: 'demo@reservafacil.com',
                first_name: 'Demo',
                last_name: 'User',
                business_name: 'Demo Business'
            },
            tokens: {
                access_token: 'demo-access-token',
                refresh_token: 'demo-refresh-token'
            }
        }
    },

    // Habilitar modo mock para desarrollo
    enableMockMode() {
        if (window.location.hostname === 'localhost') {
            console.log('🧪 Mock mode enabled for development');
            // Interceptar fetch para endpoints mock
            const originalFetch = window.fetch;
            window.fetch = async function (url, options) {
                const endpoint = url.replace(window.location.origin + '/api', '');
                if (DevConfig.mockEndpoints[endpoint]) {
                    console.log('📡 Mock response for:', endpoint);
                    return new Response(
                        JSON.stringify(DevConfig.mockEndpoints[endpoint]),
                        {
                            status: 200,
                            headers: { 'Content-Type': 'application/json' }
                        }
                    );
                }
                return originalFetch.call(this, url, options);
            };
        }
    }
};

/**
 * Inicialización final del sistema
 */
function initializeAuthSystem() {
    // Inyectar estilos CSS
    const styleSheet = document.createElement('style');
    styleSheet.textContent = authStyles;
    document.head.appendChild(styleSheet);

    // Agregar utilidades globales
    window.AuthUtils = AuthUtils;

    // Configurar modo desarrollo si aplica
    if (window.location.hostname === 'localhost' || window.location.search.includes('debug=true')) {
        DevConfig.enableMockMode();
        window.DevConfig = DevConfig;
    }

    // Configurar eventos globales de error
    window.addEventListener('unhandledrejection', function (event) {
        if (event.reason && event.reason.message && event.reason.message.includes('auth')) {
            console.error('Auth error not handled:', event.reason);
            if (window.auth && window.auth.ui) {
                window.auth.ui.showAlert('Error de autenticación no manejado', 'error');
            }
        }
    });

    // Configurar interceptor global para fetch
    const originalFetch = window.fetch;
    window.fetch = async function (url, options = {}) {
        // Agregar headers comunes
        if (!options.headers) {
            options.headers = {};
        }

        // Agregar Content-Type si no existe y hay body
        if (options.body && !options.headers['Content-Type']) {
            options.headers['Content-Type'] = 'application/json';
        }

        // Agregar token automáticamente si está disponible
        if (window.auth && window.auth.getToken() && !options.headers['Authorization']) {
            options.headers['Authorization'] = `Bearer ${window.auth.getToken()}`;
        }

        try {
            const response = await originalFetch.call(this, url, options);

            // Manejar respuestas 401 globalmente
            if (response.status === 401 && window.auth && window.auth.isAuthenticated()) {
                window.auth.client.emit('sessionExpired');
            }

            return response;
        } catch (error) {
            // Manejar errores de red
            if (error.name === 'TypeError' && error.message.includes('fetch')) {
                if (window.auth && window.auth.ui) {
                    window.auth.ui.showAlert('Error de conexión. Verifica tu internet.', 'error');
                }
            }
            throw error;
        }
    };

    console.log('🔐 Auth system fully initialized');
}

/**
 * Helper para debugging en desarrollo
 */
if (window.location.hostname === 'localhost') {
    window.authDebug = {
        getAuthState: () => window.auth?.getAuthState(),
        clearAuth: () => window.auth?.client.clearAuth(),
        mockLogin: () => window.auth?.login(DevConfig.demoCredentials),
        testAlert: (type = 'info') => window.auth?.ui.showAlert(`Test alert ${type}`, type),
        generatePassword: (length) => AuthUtils.generatePassword(length)
    };
}

// Ejecutar inicialización cuando el script se carga
initializeAuthSystem();

/**
 * Exportar para uso como módulo ES6 si es necesario
 */
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        AuthClient,
        AuthValidator,
        AuthUI,
        AuthManager,
        AuthUtils
    };
}

// Finalización del script
console.log('📦 auth.js loaded successfully');

// <--- ARCHIVO COMPLETADO --->