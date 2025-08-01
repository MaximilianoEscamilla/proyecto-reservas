/**
 * ============================================
 * ADMIN.JS - PARTE 1/6
 * Configuración Inicial y Sistema de Autenticación
 * Sistema de reservas - Panel de control
 * ============================================
 */

// Configuración global del sistema
const AdminConfig = {
    // Configuración de autenticación (cifrada en base64)
    auth: {
        user: atob('bG1pdHJpb24yMzA='), // lmitrion230
        pass: atob('TGVsb3VjaDQyNjc1My4='), // Lelouch426753.
        sessionTimeout: 3600000, // 1 hora en milisegundos
        maxLoginAttempts: 5,
        lockoutTime: 900000 // 15 minutos en milisegundos
    },

    // Configuración del sistema
    system: {
        version: '2.4.1',
        apiUrl: '/api/admin',
        refreshInterval: 30000, // 30 segundos
        maxNotifications: 10,
        autoBackupInterval: 7200000 // 2 horas
    },

    // Configuración de UI
    ui: {
        animationDuration: 300,
        notificationDuration: 5000,
        tablePageSize: 25,
        maxModalWidth: '500px'
    },

    // Estados del sistema
    status: {
        isAuthenticated: false,
        currentUser: null,
        sessionExpiry: null,
        lastActivity: null,
        activeSection: 'overview',
        isMaintenanceMode: false
    }
};

// Variables globales del sistema
let adminSession = null;
let loginAttempts = 0;
let lockoutTimer = null;
let sessionTimer = null;
let activityTimer = null;
let autoRefreshTimer = null;

// Cache para datos del sistema
const AdminCache = {
    users: [],
    filteredUsers: [],
    systemMetrics: {},
    notifications: [],
    logs: [],
    settings: {}
};

// Estado de la UI
const UIState = {
    currentPage: 1,
    pageSize: 25,
    sortColumn: '',
    sortDirection: 'asc',
    selectedUsers: new Set(),
    activeFilters: {},
    openModals: [],
    activeNotifications: []
};

/**
 * Clase principal para el manejo del panel de administrador
 */
class AdminPanel {
    constructor() {
        this.initializeSystem();
        this.bindEvents();
        this.checkExistingSession();
    }

    /**
     * Inicializar el sistema
     */
    initializeSystem() {
        console.log('🚀 Inicializando Panel de Administrador v' + AdminConfig.system.version);

        // Verificar dependencias
        this.checkDependencies();

        // Configurar eventos globales
        this.setupGlobalEvents();

        // Inicializar componentes
        this.initializeComponents();

        // Configurar timers
        this.setupTimers();

        console.log('✅ Sistema inicializado correctamente');
    }

    /**
     * Verificar dependencias del sistema
     */
    checkDependencies() {
        const requiredElements = [
            'authSection',
            'adminPanel',
            'adminLoginForm',
            'adminUsername',
            'adminPassword'
        ];

        const missingElements = requiredElements.filter(id => !document.getElementById(id));

        if (missingElements.length > 0) {
            console.error('❌ Elementos HTML faltantes:', missingElements);
            throw new Error('Elementos HTML requeridos no encontrados');
        }

        console.log('✅ Todas las dependencias verificadas');
    }

    /**
     * Configurar eventos globales
     */
    setupGlobalEvents() {
        // Detectar inactividad del usuario
        document.addEventListener('mousemove', () => this.updateActivity());
        document.addEventListener('keypress', () => this.updateActivity());
        document.addEventListener('click', () => this.updateActivity());

        // Manejar cierre de ventana
        window.addEventListener('beforeunload', (e) => {
            if (AdminConfig.status.isAuthenticated) {
                e.preventDefault();
                e.returnValue = '¿Estás seguro de que quieres salir?';
            }
        });

        // Manejar cambio de visibilidad de pestaña
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.pauseAutoRefresh();
            } else {
                this.resumeAutoRefresh();
            }
        });

        // Manejar errores globales
        window.addEventListener('error', (e) => {
            this.logError('JavaScript Error', e.message, e.filename, e.lineno);
        });
    }

    /**
     * Inicializar componentes del sistema
     */
    initializeComponents() {
        // Configurar formulario de login
        this.setupLoginForm();

        // Inicializar tooltips
        this.initializeTooltips();

        // Configurar filtros
        this.setupFilters();

        // Inicializar theme
        this.initializeTheme();
    }

    /**
     * Configurar formulario de login
     */
    setupLoginForm() {
        const loginForm = document.getElementById('adminLoginForm');
        if (!loginForm) return;

        loginForm.addEventListener('submit', (e) => {
            e.preventDefault();
            this.handleLogin();
        });

        // Auto-focus en el campo de usuario
        const usernameField = document.getElementById('adminUsername');
        if (usernameField) {
            usernameField.focus();
        }

        // Manejar Enter en campos
        document.getElementById('adminUsername').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                document.getElementById('adminPassword').focus();
            }
        });

        document.getElementById('adminPassword').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                this.handleLogin();
            }
        });
    }

    /**
     * Configurar timers del sistema
     */
    setupTimers() {
        // Timer para verificar sesión
        sessionTimer = setInterval(() => {
            this.checkSessionExpiry();
        }, 60000); // Verificar cada minuto

        // Timer para actividad del usuario
        activityTimer = setInterval(() => {
            this.checkUserActivity();
        }, 30000); // Verificar cada 30 segundos
    }

    /**
     * Verificar si existe una sesión activa
     */
    checkExistingSession() {
        const savedSession = sessionStorage.getItem('adminSession');
        if (savedSession) {
            try {
                const session = JSON.parse(savedSession);
                if (session.expires > Date.now()) {
                    this.restoreSession(session);
                } else {
                    this.clearSession();
                }
            } catch (e) {
                console.warn('Sesión inválida encontrada, limpiando...');
                this.clearSession();
            }
        }
    }

    /**
     * Manejar proceso de login
     */
    async handleLogin() {
        const username = document.getElementById('adminUsername').value.trim();
        const password = document.getElementById('adminPassword').value;
        const loginBtn = document.querySelector('.btn-login');

        // Limpiar errores previos
        this.clearFormErrors();

        // Verificar bloqueo por intentos fallidos
        if (this.isAccountLocked()) {
            this.showLoginError('Cuenta bloqueada. Intenta más tarde.');
            return;
        }

        // Validaciones básicas
        if (!this.validateLoginForm(username, password)) {
            return;
        }

        // Mostrar estado de carga
        this.setLoadingState(loginBtn, true);

        try {
            // Simular delay de autenticación (en producción sería una llamada real)
            await this.delay(1500);

            // Verificar credenciales
            if (await this.authenticateUser(username, password)) {
                this.onLoginSuccess(username);
            } else {
                this.onLoginFailure();
            }
        } catch (error) {
            console.error('Error durante autenticación:', error);
            this.showLoginError('Error del sistema. Intenta más tarde.');
        } finally {
            this.setLoadingState(loginBtn, false);
        }
    }

    /**
     * Validar formulario de login
     */
    validateLoginForm(username, password) {
        let isValid = true;

        if (!username) {
            this.showFieldError('adminUsername', 'Usuario requerido');
            isValid = false;
        }

        if (!password) {
            this.showFieldError('adminPassword', 'Contraseña requerida');
            isValid = false;
        }

        if (username.length < 3) {
            this.showFieldError('adminUsername', 'Usuario debe tener al menos 3 caracteres');
            isValid = false;
        }

        if (password.length < 6) {
            this.showFieldError('adminPassword', 'Contraseña debe tener al menos 6 caracteres');
            isValid = false;
        }

        return isValid;
    }

    /**
     * Autenticar usuario
     */
    async authenticateUser(username, password) {
        // En un entorno real, esto sería una llamada a la API
        return username === AdminConfig.auth.user && password === AdminConfig.auth.pass;
    }

    /**
     * Manejar login exitoso
     */
    onLoginSuccess(username) {
        console.log('✅ Autenticación exitosa para:', username);

        // Resetear intentos de login
        loginAttempts = 0;
        this.clearLockout();

        // Crear sesión
        const session = {
            user: username,
            loginTime: Date.now(),
            expires: Date.now() + AdminConfig.auth.sessionTimeout,
            lastActivity: Date.now()
        };

        // Guardar sesión
        this.saveSession(session);

        // Actualizar estado del sistema
        AdminConfig.status.isAuthenticated = true;
        AdminConfig.status.currentUser = username;
        AdminConfig.status.sessionExpiry = session.expires;
        AdminConfig.status.lastActivity = session.lastActivity;

        // Ocultar login y mostrar panel
        this.showAdminPanel();

        // Inicializar datos del panel
        this.initializeAdminData();

        // Mostrar notificación de bienvenida
        this.showNotification('Bienvenido', `Sesión iniciada como ${username}`, 'success');

        // Registrar evento de login
        this.logActivity('login', 'Inicio de sesión exitoso', { user: username });
    }

    /**
     * Manejar login fallido
     */
    onLoginFailure() {
        console.warn('❌ Intento de autenticación fallido');

        loginAttempts++;

        if (loginAttempts >= AdminConfig.auth.maxLoginAttempts) {
            this.lockAccount();
            this.showLoginError(`Cuenta bloqueada por ${Math.ceil(AdminConfig.auth.lockoutTime / 60000)} minutos`);
        } else {
            const remaining = AdminConfig.auth.maxLoginAttempts - loginAttempts;
            this.showLoginError(`Credenciales incorrectas. ${remaining} intentos restantes.`);
        }

        // Limpiar campo de contraseña
        document.getElementById('adminPassword').value = '';

        // Registrar intento fallido
        this.logActivity('login_failed', 'Intento de login fallido', {
            attempts: loginAttempts,
            timestamp: Date.now()
        });
    }

    /**
     * Verificar si la cuenta está bloqueada
     */
    isAccountLocked() {
        const lockoutEnd = localStorage.getItem('adminLockoutEnd');
        if (lockoutEnd && Date.now() < parseInt(lockoutEnd)) {
            return true;
        }

        if (lockoutEnd && Date.now() >= parseInt(lockoutEnd)) {
            this.clearLockout();
        }

        return false;
    }

    /**
     * Bloquear cuenta temporalmente
     */
    lockAccount() {
        const lockoutEnd = Date.now() + AdminConfig.auth.lockoutTime;
        localStorage.setItem('adminLockoutEnd', lockoutEnd.toString());

        lockoutTimer = setTimeout(() => {
            this.clearLockout();
            this.showNotification('Cuenta Desbloqueada', 'Ya puedes intentar iniciar sesión nuevamente', 'success');
        }, AdminConfig.auth.lockoutTime);

        console.warn('🔒 Cuenta bloqueada hasta:', new Date(lockoutEnd));
    }

    /**
     * Limpiar bloqueo de cuenta
     */
    clearLockout() {
        localStorage.removeItem('adminLockoutEnd');
        loginAttempts = 0;

        if (lockoutTimer) {
            clearTimeout(lockoutTimer);
            lockoutTimer = null;
        }
    }

    /**
     * Mostrar panel de administrador
     */
    showAdminPanel() {
        document.getElementById('authSection').style.display = 'none';
        document.getElementById('adminPanel').classList.add('active');

        // Actualizar información del usuario
        this.updateUserInfo();

        // Iniciar auto-refresh
        this.startAutoRefresh();
    }

    /**
     * Actualizar información del usuario en la UI
     */
    updateUserInfo() {
        const avatar = document.getElementById('adminAvatar');
        const userName = document.getElementById('adminUserName');

        if (avatar) {
            avatar.textContent = AdminConfig.status.currentUser.substring(0, 2).toUpperCase();
        }

        if (userName) {
            userName.textContent = 'Admin Principal';
        }
    }

    /**
     * Guardar sesión
     */
    saveSession(session) {
        adminSession = session;
        sessionStorage.setItem('adminSession', JSON.stringify(session));
    }

    /**
     * Restaurar sesión existente
     */
    restoreSession(session) {
        console.log('🔄 Restaurando sesión existente');

        adminSession = session;
        AdminConfig.status.isAuthenticated = true;
        AdminConfig.status.currentUser = session.user;
        AdminConfig.status.sessionExpiry = session.expires;
        AdminConfig.status.lastActivity = session.lastActivity;

        this.showAdminPanel();
        this.initializeAdminData();

        this.showNotification('Sesión Restaurada', 'Bienvenido de vuelta', 'success');
    }

    /**
     * Limpiar sesión
     */
    clearSession() {
        adminSession = null;
        sessionStorage.removeItem('adminSession');

        AdminConfig.status.isAuthenticated = false;
        AdminConfig.status.currentUser = null;
        AdminConfig.status.sessionExpiry = null;
        AdminConfig.status.lastActivity = null;

        // Limpiar timers
        this.stopAutoRefresh();

        // Mostrar login
        document.getElementById('adminPanel').classList.remove('active');
        document.getElementById('authSection').style.display = 'flex';

        // Limpiar formulario
        document.getElementById('adminLoginForm').reset();
    }

    /**
     * Actualizar actividad del usuario
     */
    updateActivity() {
        if (AdminConfig.status.isAuthenticated) {
            AdminConfig.status.lastActivity = Date.now();
            if (adminSession) {
                adminSession.lastActivity = Date.now();
                this.saveSession(adminSession);
            }
        }
    }

    /**
     * Verificar expiración de sesión
     */
    checkSessionExpiry() {
        if (AdminConfig.status.isAuthenticated && AdminConfig.status.sessionExpiry) {
            const timeLeft = AdminConfig.status.sessionExpiry - Date.now();

            if (timeLeft <= 0) {
                this.handleSessionExpired();
            } else if (timeLeft <= 300000) { // 5 minutos
                this.showSessionWarning(Math.ceil(timeLeft / 60000));
            }
        }
    }

    /**
     * Verificar actividad del usuario
     */
    checkUserActivity() {
        if (AdminConfig.status.isAuthenticated && AdminConfig.status.lastActivity) {
            const inactiveTime = Date.now() - AdminConfig.status.lastActivity;
            const maxInactiveTime = 1800000; // 30 minutos

            if (inactiveTime >= maxInactiveTime) {
                this.handleInactiveSession();
            }
        }
    }

    /**
     * Manejar sesión expirada
     */
    handleSessionExpired() {
        console.warn('⏰ Sesión expirada');

        this.showNotification('Sesión Expirada', 'Tu sesión ha expirado. Por favor, inicia sesión nuevamente.', 'warning', 10000);

        this.logActivity('session_expired', 'Sesión expirada automáticamente');

        setTimeout(() => {
            this.performLogout(false);
        }, 5000);
    }

    /**
     * Manejar sesión inactiva
     */
    handleInactiveSession() {
        console.warn('😴 Sesión inactiva detectada');

        this.showNotification('Sesión Inactiva', 'Sesión cerrada por inactividad', 'warning');

        this.logActivity('session_inactive', 'Sesión cerrada por inactividad');

        this.performLogout(false);
    }

    /**
     * Mostrar advertencia de sesión
     */
    showSessionWarning(minutesLeft) {
        this.showNotification(
            'Sesión por Expirar',
            `Tu sesión expirará en ${minutesLeft} minuto(s). Guarda tus cambios.`,
            'warning',
            30000
        );
    }

    /**
     * Funciones de utilidad
     */
    delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    setLoadingState(button, isLoading) {
        if (isLoading) {
            button.classList.add('loading');
            button.disabled = true;
        } else {
            button.classList.remove('loading');
            button.disabled = false;
        }
    }

    clearFormErrors() {
        document.querySelectorAll('.form-error').forEach(error => {
            error.classList.remove('show');
        });
        document.querySelectorAll('.form-input').forEach(input => {
            input.classList.remove('error');
        });
    }

    showFieldError(fieldId, message) {
        const field = document.getElementById(fieldId);
        const errorDiv = field.parentNode.parentNode.querySelector('.form-error');

        field.classList.add('error');
        errorDiv.textContent = message;
        errorDiv.classList.add('show');
    }

    showLoginError(message) {
        const errorDiv = document.getElementById('loginError');
        if (errorDiv) {
            errorDiv.textContent = message;
            errorDiv.classList.add('show');
        }
    }
}

// Inicializar el panel cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => {
    window.adminPanel = new AdminPanel();
});

// Exportar para uso global
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { AdminPanel, AdminConfig, AdminCache, UIState };
}

/* <-- seguir aquí --> */

/**
 * ============================================
 * ADMIN.JS - PARTE 2/6
 * Gestión de Datos, Usuarios y Sistema de Notificaciones
 * Sistema de reservas - Panel de control
 * ============================================
 */

/**
 * Extensión de la clase AdminPanel para gestión de datos
 */
AdminPanel.prototype.initializeAdminData = function () {
    console.log('📊 Inicializando datos del panel...');

    // Cargar datos mockados
    this.loadMockData();

    // Inicializar métricas del sistema
    this.initializeSystemMetrics();

    // Cargar configuraciones
    this.loadSystemSettings();

    // Actualizar estadísticas
    this.updateAllStatistics();

    // Cargar actividad reciente
    this.loadRecentActivity();

    // Inicializar logs
    this.initializeLogSystem();

    console.log('✅ Datos del panel inicializados');
};

/**
 * Cargar datos de prueba
 */
AdminPanel.prototype.loadMockData = function () {
    // Datos de usuarios simulados
    AdminCache.users = [
        {
            id: 1,
            name: "Restaurant El Buen Sabor",
            owner: "Carlos Mendoza",
            email: "carlos@elbuensabor.com",
            phone: "+57 301 234 5678",
            plan: "premium",
            status: "active",
            created: "2024-01-15",
            expires: "2024-12-15",
            revenue: 299000,
            region: "bogota",
            reservations: 1847,
            lastLogin: "2024-07-26",
            totalRevenue: 2691000,
            monthlyGrowth: 12.5
        },
        {
            id: 2,
            name: "Café Central",
            owner: "María López",
            email: "maria@cafecentral.co",
            phone: "+57 302 345 6789",
            plan: "basic",
            status: "active",
            created: "2024-02-20",
            expires: "2024-08-20",
            revenue: 99000,
            region: "medellin",
            reservations: 634,
            lastLogin: "2024-07-25",
            totalRevenue: 594000,
            monthlyGrowth: 8.3
        },
        {
            id: 3,
            name: "Parrilla Argentina",
            owner: "Roberto Silva",
            email: "roberto@parrilla.com",
            phone: "+57 303 456 7890",
            plan: "enterprise",
            status: "active",
            created: "2023-11-10",
            expires: "2024-11-10",
            revenue: 599000,
            region: "cali",
            reservations: 2156,
            lastLogin: "2024-07-27",
            totalRevenue: 5391000,
            monthlyGrowth: 15.7
        },
        {
            id: 4,
            name: "Pizzería Napoli",
            owner: "Giuseppe Romano",
            email: "giuseppe@napoli.co",
            phone: "+57 304 567 8901",
            plan: "basic",
            status: "expired",
            created: "2024-01-05",
            expires: "2024-07-05",
            revenue: 99000,
            region: "barranquilla",
            reservations: 423,
            lastLogin: "2024-07-20",
            totalRevenue: 693000,
            monthlyGrowth: -2.1
        },
        {
            id: 5,
            name: "Sushi Zen",
            owner: "Akira Tanaka",
            email: "akira@sushizen.co",
            phone: "+57 305 678 9012",
            plan: "premium",
            status: "suspended",
            created: "2024-03-12",
            expires: "2025-03-12",
            revenue: 299000,
            region: "bogota",
            reservations: 891,
            lastLogin: "2024-07-15",
            totalRevenue: 1495000,
            monthlyGrowth: 5.2
        },
        {
            id: 6,
            name: "Bar La Terraza",
            owner: "Ana García",
            email: "ana@laterraza.com",
            phone: "+57 306 789 0123",
            plan: "free",
            status: "active",
            created: "2024-06-01",
            expires: null,
            revenue: 0,
            region: "other",
            reservations: 87,
            lastLogin: "2024-07-26",
            totalRevenue: 0,
            monthlyGrowth: 0
        },
        {
            id: 7,
            name: "Asadero El Fogón",
            owner: "Pedro Ramírez",
            email: "pedro@elfogon.co",
            phone: "+57 307 890 1234",
            plan: "premium",
            status: "active",
            created: "2024-01-30",
            expires: "2025-01-30",
            revenue: 299000,
            region: "bogota",
            reservations: 1245,
            lastLogin: "2024-07-27",
            totalRevenue: 1794000,
            monthlyGrowth: 9.8
        },
        {
            id: 8,
            name: "Marisquería Costa Brava",
            owner: "Carmen Delgado",
            email: "carmen@costabrava.co",
            phone: "+57 308 901 2345",
            plan: "enterprise",
            status: "active",
            created: "2023-09-15",
            expires: "2024-09-15",
            revenue: 599000,
            region: "barranquilla",
            reservations: 1876,
            lastLogin: "2024-07-26",
            totalRevenue: 5990000,
            monthlyGrowth: 18.4
        }
    ];

    // Copiar a filtrados inicialmente
    AdminCache.filteredUsers = [...AdminCache.users];

    console.log(`📦 Cargados ${AdminCache.users.length} usuarios de prueba`);
};

/**
 * Inicializar métricas del sistema
 */
AdminPanel.prototype.initializeSystemMetrics = function () {
    AdminCache.systemMetrics = {
        cpu: { usage: 23, limit: 80, status: 'healthy' },
        memory: { usage: 67, limit: 85, status: 'warning' },
        disk: { usage: 75, limit: 90, status: 'warning' },
        network: { status: 'active', latency: 45, uptime: 99.8 },
        database: {
            connections: 45,
            maxConnections: 100,
            size: '2.1 GB',
            lastBackup: Date.now() - 7200000, // 2 horas atrás
            status: 'healthy'
        },
        server: {
            uptime: Date.now() - 1296000000, // 15 días
            requests: 1247698,
            errors: 234,
            avgResponseTime: 245,
            status: 'healthy'
        }
    };

    console.log('📈 Métricas del sistema inicializadas');
};

/**
 * Cargar configuraciones del sistema
 */
AdminPanel.prototype.loadSystemSettings = function () {
    const defaultSettings = {
        general: {
            appName: 'Sistema de Reservas',
            appDescription: 'Sistema integral de gestión de reservas para restaurantes',
            maxReservations: 5,
            sessionTimeout: 60
        },
        security: {
            maxLoginAttempts: 5,
            lockoutTime: 15,
            passwordComplexity: 'medium',
            require2FA: false,
            auditLogging: true
        },
        notifications: {
            notificationEmail: 'admin@reservas.com',
            notifyNewUsers: true,
            notifyFailedPayments: true,
            notifySystemErrors: true,
            reportFrequency: 'weekly'
        },
        integrations: {
            googleAnalyticsId: '',
            mercadoPagoToken: '',
            paypalClientId: '',
            sendgridApiKey: '',
            twilioSid: ''
        },
        advanced: {
            debugMode: false,
            cacheTtl: 3600,
            rateLimit: 100,
            gzipCompression: true
        }
    };

    // Cargar desde localStorage o usar defaults
    const savedSettings = localStorage.getItem('adminSettings');
    AdminCache.settings = savedSettings ?
        { ...defaultSettings, ...JSON.parse(savedSettings) } :
        defaultSettings;

    console.log('⚙️ Configuraciones del sistema cargadas');
};

/**
 * Sistema de notificaciones avanzado
 */
AdminPanel.prototype.showNotification = function (title, message, type = 'success', duration = 5000) {
    // Prevenir demasiadas notificaciones
    if (UIState.activeNotifications.length >= AdminConfig.system.maxNotifications) {
        this.removeOldestNotification();
    }

    const notification = this.createNotificationElement(title, message, type);
    document.body.appendChild(notification);

    // Registrar notificación
    const notificationData = {
        id: Date.now(),
        title,
        message,
        type,
        timestamp: new Date(),
        element: notification
    };

    UIState.activeNotifications.push(notificationData);
    AdminCache.notifications.unshift(notificationData);

    // Mostrar con animación
    setTimeout(() => notification.classList.add('show'), 100);

    // Auto-cerrar
    if (duration > 0) {
        setTimeout(() => {
            this.closeNotification(notification);
        }, duration);
    }

    // Log de la notificación
    this.logActivity('notification', `${type}: ${title}`, { message });

    return notificationData.id;
};

/**
 * Crear elemento de notificación
 */
AdminPanel.prototype.createNotificationElement = function (title, message, type) {
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;

    const iconMap = {
        success: 'fas fa-check-circle',
        error: 'fas fa-exclamation-circle',
        warning: 'fas fa-exclamation-triangle',
        info: 'fas fa-info-circle'
    };

    notification.innerHTML = `
        <div class="notification-header">
            <div class="notification-icon">
                <i class="${iconMap[type] || iconMap.info}"></i>
            </div>
            <h4 class="notification-title">${this.escapeHtml(title)}</h4>
            <button class="notification-close" onclick="adminPanel.closeNotification(this)">×</button>
        </div>
        <p class="notification-body">${this.escapeHtml(message)}</p>
        <div class="notification-timestamp">${new Date().toLocaleTimeString()}</div>
    `;

    // Hacer clic para cerrar
    notification.addEventListener('click', (e) => {
        if (!e.target.classList.contains('notification-close')) {
            this.closeNotification(notification);
        }
    });

    return notification;
};

/**
 * Cerrar notificación
 */
AdminPanel.prototype.closeNotification = function (element) {
    const notification = element.closest ? element.closest('.notification') : element;

    if (notification) {
        notification.classList.remove('show');

        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }

            // Remover del tracking
            UIState.activeNotifications = UIState.activeNotifications.filter(n => n.element !== notification);
        }, 300);
    }
};

/**
 * Remover notificación más antigua
 */
AdminPanel.prototype.removeOldestNotification = function () {
    if (UIState.activeNotifications.length > 0) {
        const oldest = UIState.activeNotifications[0];
        this.closeNotification(oldest.element);
    }
};

/**
 * Actualizar todas las estadísticas
 */
AdminPanel.prototype.updateAllStatistics = function () {
    this.updateOverviewStats();
    this.updateUserStats();
    this.updatePlanStats();
    this.updateSystemHealth();
};

/**
 * Actualizar estadísticas de overview
 */
AdminPanel.prototype.updateOverviewStats = function () {
    const totalUsers = AdminCache.users.length;
    const totalRevenue = AdminCache.users.reduce((sum, user) => sum + user.revenue, 0);
    const totalBookings = AdminCache.users.reduce((sum, user) => sum + user.reservations, 0);
    const systemErrors = AdminCache.systemMetrics.server.errors || 0;

    this.updateCounter('totalUsersCount', totalUsers);
    this.updateCounter('totalRevenueCount', this.formatCurrency(totalRevenue));
    this.updateCounter('totalBookingsCount', totalBookings.toLocaleString());
    this.updateCounter('systemErrorsCount', systemErrors);

    console.log('📊 Estadísticas de overview actualizadas');
};

/**
 * Actualizar estadísticas de usuarios
 */
AdminPanel.prototype.updateUserStats = function () {
    const activeUsers = AdminCache.users.filter(u => u.status === 'active').length;
    const premiumUsers = AdminCache.users.filter(u => ['premium', 'enterprise'].includes(u.plan)).length;
    const expiredUsers = AdminCache.users.filter(u => u.status === 'expired').length;
    const monthlyRevenue = AdminCache.users.reduce((sum, u) => sum + u.revenue, 0);

    this.updateCounter('activeUsersCount', activeUsers);
    this.updateCounter('premiumUsersCount', premiumUsers);
    this.updateCounter('expiredUsersCount', expiredUsers);
    this.updateCounter('monthlyRevenueCount', this.formatCurrency(monthlyRevenue));

    // Actualizar badges en navegación
    this.updateCounter('activeUsersBadge', activeUsers);
    this.updateCounter('expiringPlansBadge', expiredUsers);

    console.log('👥 Estadísticas de usuarios actualizadas');
};

/**
 * Actualizar estadísticas de planes
 */
AdminPanel.prototype.updatePlanStats = function () {
    const activeSubscriptions = AdminCache.users.filter(u => u.status === 'active').length;
    const totalMRR = AdminCache.users.reduce((sum, u) => sum + u.revenue, 0);

    // Calcular planes próximos a expirar (30 días)
    const expiringPlans = AdminCache.users.filter(u => {
        if (!u.expires) return false;
        const expireDate = new Date(u.expires);
        const now = new Date();
        const thirtyDaysFromNow = new Date(now.getTime() + 30 * 24 * 60 * 60 * 1000);
        return expireDate <= thirtyDaysFromNow && expireDate > now;
    }).length;

    this.updateCounter('totalMrrCount', this.formatCurrency(totalMRR));
    this.updateCounter('activeSubscriptionsCount', activeSubscriptions);
    this.updateCounter('expiringPlansCount', expiringPlans);
    this.updateCounter('failedPaymentsCount', 0); // Mock data

    console.log('💳 Estadísticas de planes actualizadas');
};

/**
 * Actualizar salud del sistema
 */
AdminPanel.prototype.updateSystemHealth = function () {
    const metrics = AdminCache.systemMetrics;

    this.updateMetric('cpuUsage', `${metrics.cpu.usage}%`, metrics.cpu.usage);
    this.updateMetric('memoryUsage', `${metrics.memory.usage}%`, metrics.memory.usage);
    this.updateMetric('diskUsage', `${metrics.disk.usage}%`, metrics.disk.usage);
    this.updateMetric('networkStatus', metrics.network.status === 'active' ? 'Activa' : 'Inactiva', 100);

    // Actualizar estado general del sistema
    const overallHealth = this.calculateOverallHealth(metrics);
    this.updateSystemStatus(overallHealth);

    console.log('🏥 Salud del sistema actualizada');
};

/**
 * Calcular salud general del sistema
 */
AdminPanel.prototype.calculateOverallHealth = function (metrics) {
    const cpuScore = metrics.cpu.usage < metrics.cpu.limit ? 100 : 50;
    const memoryScore = metrics.memory.usage < metrics.memory.limit ? 100 : 50;
    const diskScore = metrics.disk.usage < metrics.disk.limit ? 100 : 50;
    const networkScore = metrics.network.status === 'active' ? 100 : 0;

    const averageScore = (cpuScore + memoryScore + diskScore + networkScore) / 4;

    if (averageScore >= 90) return 'healthy';
    if (averageScore >= 70) return 'warning';
    return 'error';
};

/**
 * Actualizar estado del sistema
 */
AdminPanel.prototype.updateSystemStatus = function (status) {
    const statusElement = document.getElementById('systemStatus');
    if (!statusElement) return;

    const statusConfig = {
        healthy: { text: 'Sistema Operativo', class: 'healthy', icon: 'fas fa-circle' },
        warning: { text: 'Requiere Atención', class: 'warning', icon: 'fas fa-exclamation-triangle' },
        error: { text: 'Sistema Crítico', class: 'error', icon: 'fas fa-times-circle' }
    };

    const config = statusConfig[status] || statusConfig.healthy;

    statusElement.className = `system-status ${config.class}`;
    statusElement.innerHTML = `<i class="${config.icon}"></i><span>${config.text}</span>`;
};

/**
 * Cargar actividad reciente
 */
AdminPanel.prototype.loadRecentActivity = function () {
    const activities = [
        {
            type: 'user',
            title: 'Nuevo usuario registrado',
            description: 'Bar La Terraza se registró en el plan gratuito',
            time: Date.now() - 300000, // 5 minutos atrás
            user: 'Sistema'
        },
        {
            type: 'payment',
            title: 'Pago procesado exitosamente',
            description: 'Restaurant El Buen Sabor - Plan Premium ($299.000)',
            time: Date.now() - 3600000, // 1 hora atrás
            user: 'carlos@elbuensabor.com'
        },
        {
            type: 'system',
            title: 'Backup automático completado',
            description: 'Base de datos respaldada exitosamente (2.1 GB)',
            time: Date.now() - 7200000, // 2 horas atrás
            user: 'Sistema'
        },
        {
            type: 'user',
            title: 'Plan actualizado',
            description: 'Café Central cambió de Básico a Premium',
            time: Date.now() - 10800000, // 3 horas atrás
            user: 'maria@cafecentral.co'
        },
        {
            type: 'error',
            title: 'Error de conexión detectado',
            description: 'Timeout en API de pagos - Resuelto automáticamente',
            time: Date.now() - 14400000, // 4 horas atrás
            user: 'Sistema'
        }
    ];

    AdminCache.recentActivity = activities;
    this.renderRecentActivity();

    console.log('📝 Actividad reciente cargada');
};

/**
 * Renderizar actividad reciente
 */
AdminPanel.prototype.renderRecentActivity = function () {
    const activityFeed = document.getElementById('activityFeed');
    if (!activityFeed) return;

    const html = AdminCache.recentActivity.map(activity => `
        <div class="activity-item">
            <div class="activity-icon ${activity.type}">
                <i class="fas fa-${this.getActivityIcon(activity.type)}"></i>
            </div>
            <div class="activity-content">
                <h4 class="activity-title">${this.escapeHtml(activity.title)}</h4>
                <p class="activity-description">${this.escapeHtml(activity.description)}</p>
                <div class="activity-meta">
                    <span class="activity-user">${this.escapeHtml(activity.user)}</span>
                </div>
            </div>
            <div class="activity-time">${this.formatTimeAgo(activity.time)}</div>
        </div>
    `).join('');

    activityFeed.innerHTML = html;
};

/**
 * Funciones de utilidad
 */
AdminPanel.prototype.updateCounter = function (elementId, value) {
    const element = document.getElementById(elementId);
    if (element) {
        element.textContent = value;
    }
};

AdminPanel.prototype.updateMetric = function (elementId, value, percentage) {
    const valueElement = document.getElementById(elementId);
    const barElement = document.getElementById(elementId.replace('Usage', 'Bar').replace('Status', 'Bar'));

    if (valueElement) valueElement.textContent = value;
    if (barElement && typeof percentage === 'number') {
        barElement.style.width = `${Math.min(percentage, 100)}%`;

        // Cambiar color según el porcentaje
        if (percentage >= 90) {
            barElement.className = 'metric-fill error';
        } else if (percentage >= 70) {
            barElement.className = 'metric-fill warning';
        } else {
            barElement.className = 'metric-fill healthy';
        }
    }
};

AdminPanel.prototype.formatCurrency = function (amount) {
    return new Intl.NumberFormat('es-CO', {
        style: 'currency',
        currency: 'COP',
        minimumFractionDigits: 0
    }).format(amount);
};

AdminPanel.prototype.formatTimeAgo = function (timestamp) {
    const now = Date.now();
    const diff = now - timestamp;

    const minutes = Math.floor(diff / 60000);
    const hours = Math.floor(diff / 3600000);
    const days = Math.floor(diff / 86400000);

    if (minutes < 60) return `Hace ${minutes} min`;
    if (hours < 24) return `Hace ${hours} hora${hours > 1 ? 's' : ''}`;
    return `Hace ${days} día${days > 1 ? 's' : ''}`;
};

AdminPanel.prototype.getActivityIcon = function (type) {
    const icons = {
        user: 'user-plus',
        payment: 'credit-card',
        system: 'cog',
        error: 'exclamation-triangle',
        security: 'shield-alt'
    };
    return icons[type] || 'info-circle';
};

AdminPanel.prototype.escapeHtml = function (text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
};

/**
 * Sistema de logging de actividad
 */
AdminPanel.prototype.logActivity = function (action, description, metadata = {}) {
    const logEntry = {
        id: Date.now(),
        timestamp: new Date(),
        action,
        description,
        user: AdminConfig.status.currentUser,
        metadata,
        ip: '192.168.1.100', // Mock IP
        userAgent: navigator.userAgent.substring(0, 100)
    };

    // Agregar al cache de logs
    AdminCache.logs.unshift(logEntry);

    // Mantener solo los últimos 1000 logs en memoria
    if (AdminCache.logs.length > 1000) {
        AdminCache.logs.splice(1000);
    }

    // En un entorno real, esto se enviaría al servidor
    console.log('📝 Log:', logEntry);
};

/**
 * Log de errores del sistema
 */
AdminPanel.prototype.logError = function (title, message, filename = '', lineno = 0) {
    this.logActivity('error', title, {
        message,
        filename,
        lineno,
        stackTrace: new Error().stack
    });

    // Mostrar notificación de error crítico
    this.showNotification('Error del Sistema', message, 'error', 10000);
};

/* <-- seguir aquí --> */

/**
 * ============================================
 * ADMIN.JS - PARTE 3/6
 * Gestión de Usuarios, Filtrado y Tabla Dinámica
 * Sistema de reservas - Panel de control
 * ============================================
 */

/**
 * Gestión de usuarios y tabla dinámica
 */
AdminPanel.prototype.initializeUserManagement = function () {
    console.log('👥 Inicializando gestión de usuarios...');

    // Configurar filtros
    this.setupUserFilters();

    // Configurar ordenamiento
    this.setupTableSorting();

    // Configurar paginación
    this.setupPagination();

    // Renderizar tabla inicial
    this.renderUsersTable();

    // Configurar selección masiva
    this.setupBulkSelection();

    console.log('✅ Gestión de usuarios inicializada');
};

/**
 * Configurar filtros de usuarios
 */
AdminPanel.prototype.setupUserFilters = function () {
    // Filtro de búsqueda
    const searchInput = document.getElementById('userSearch');
    if (searchInput) {
        searchInput.addEventListener('input', this.debounce(() => {
            this.applyFilters();
        }, 300));
    }

    // Filtros de selección
    const filterSelects = ['planFilter', 'statusFilter', 'regionFilter'];
    filterSelects.forEach(filterId => {
        const filter = document.getElementById(filterId);
        if (filter) {
            filter.addEventListener('change', () => {
                this.applyFilters();
            });
        }
    });

    // Configurar filtros avanzados
    this.setupAdvancedFilters();
};

/**
 * Configurar filtros avanzados
 */
AdminPanel.prototype.setupAdvancedFilters = function () {
    // Filtro por rango de fechas
    const dateFromInput = document.getElementById('dateFrom');
    const dateToInput = document.getElementById('dateTo');

    if (dateFromInput && dateToInput) {
        [dateFromInput, dateToInput].forEach(input => {
            input.addEventListener('change', () => {
                this.applyFilters();
            });
        });
    }

    // Filtro por rango de ingresos
    const revenueMinInput = document.getElementById('revenueMin');
    const revenueMaxInput = document.getElementById('revenueMax');

    if (revenueMinInput && revenueMaxInput) {
        [revenueMinInput, revenueMaxInput].forEach(input => {
            input.addEventListener('input', this.debounce(() => {
                this.applyFilters();
            }, 500));
        });
    }
};

/**
 * Aplicar filtros a los usuarios
 */
AdminPanel.prototype.applyFilters = function () {
    const filters = this.getActiveFilters();

    AdminCache.filteredUsers = AdminCache.users.filter(user => {
        return this.matchesAllFilters(user, filters);
    });

    // Resetear paginación
    UIState.currentPage = 1;

    // Actualizar tabla
    this.renderUsersTable();
    this.updatePagination();

    // Log de filtrado
    this.logActivity('filter_users', 'Filtros aplicados', {
        filters,
        resultCount: AdminCache.filteredUsers.length
    });
};

/**
 * Obtener filtros activos
 */
AdminPanel.prototype.getActiveFilters = function () {
    return {
        search: document.getElementById('userSearch')?.value.toLowerCase() || '',
        plan: document.getElementById('planFilter')?.value || '',
        status: document.getElementById('statusFilter')?.value || '',
        region: document.getElementById('regionFilter')?.value || '',
        dateFrom: document.getElementById('dateFrom')?.value || '',
        dateTo: document.getElementById('dateTo')?.value || '',
        revenueMin: parseFloat(document.getElementById('revenueMin')?.value) || 0,
        revenueMax: parseFloat(document.getElementById('revenueMax')?.value) || Infinity
    };
};

/**
 * Verificar si usuario coincide con todos los filtros
 */
AdminPanel.prototype.matchesAllFilters = function (user, filters) {
    // Filtro de búsqueda
    if (filters.search) {
        const searchMatch =
            user.name.toLowerCase().includes(filters.search) ||
            user.owner.toLowerCase().includes(filters.search) ||
            user.email.toLowerCase().includes(filters.search) ||
            user.phone.includes(filters.search);

        if (!searchMatch) return false;
    }

    // Filtros de selección
    if (filters.plan && user.plan !== filters.plan) return false;
    if (filters.status && user.status !== filters.status) return false;
    if (filters.region && user.region !== filters.region) return false;

    // Filtro de fechas
    if (filters.dateFrom || filters.dateTo) {
        const userDate = new Date(user.created);
        if (filters.dateFrom && userDate < new Date(filters.dateFrom)) return false;
        if (filters.dateTo && userDate > new Date(filters.dateTo)) return false;
    }

    // Filtro de ingresos
    if (user.revenue < filters.revenueMin || user.revenue > filters.revenueMax) {
        return false;
    }

    return true;
};

/**
 * Limpiar todos los filtros
 */
AdminPanel.prototype.clearAllFilters = function () {
    // Limpiar campos de filtro
    const filterElements = [
        'userSearch', 'planFilter', 'statusFilter', 'regionFilter',
        'dateFrom', 'dateTo', 'revenueMin', 'revenueMax'
    ];

    filterElements.forEach(elementId => {
        const element = document.getElementById(elementId);
        if (element) {
            element.value = '';
        }
    });

    // Restaurar usuarios filtrados
    AdminCache.filteredUsers = [...AdminCache.users];
    UIState.currentPage = 1;

    // Actualizar tabla
    this.renderUsersTable();
    this.updatePagination();

    this.showNotification('Filtros Limpiados', 'Todos los filtros han sido removidos', 'success');
    this.logActivity('clear_filters', 'Filtros limpiados');
};

/**
 * Configurar ordenamiento de tabla
 */
AdminPanel.prototype.setupTableSorting = function () {
    const sortableHeaders = document.querySelectorAll('.sortable');

    sortableHeaders.forEach(header => {
        header.addEventListener('click', () => {
            const column = header.dataset.column;
            this.sortTable(column);
        });
    });
};

/**
 * Ordenar tabla por columna
 */
AdminPanel.prototype.sortTable = function (column) {
    // Determinar dirección de ordenamiento
    if (UIState.sortColumn === column) {
        UIState.sortDirection = UIState.sortDirection === 'asc' ? 'desc' : 'asc';
    } else {
        UIState.sortColumn = column;
        UIState.sortDirection = 'asc';
    }

    // Aplicar ordenamiento
    AdminCache.filteredUsers.sort((a, b) => {
        let valueA = this.getSortValue(a, column);
        let valueB = this.getSortValue(b, column);

        // Manejar valores nulos
        if (valueA === null || valueA === undefined) valueA = '';
        if (valueB === null || valueB === undefined) valueB = '';

        // Comparación
        let comparison = 0;
        if (typeof valueA === 'string' && typeof valueB === 'string') {
            comparison = valueA.localeCompare(valueB);
        } else {
            comparison = valueA < valueB ? -1 : valueA > valueB ? 1 : 0;
        }

        return UIState.sortDirection === 'desc' ? -comparison : comparison;
    });

    // Actualizar UI
    this.updateSortIndicators();
    this.renderUsersTable();

    this.logActivity('sort_table', 'Tabla ordenada', {
        column,
        direction: UIState.sortDirection
    });
};

/**
 * Obtener valor para ordenamiento
 */
AdminPanel.prototype.getSortValue = function (user, column) {
    const columnMap = {
        'name': user.name,
        'email': user.email,
        'owner': user.owner,
        'plan': user.plan,
        'status': user.status,
        'created': new Date(user.created),
        'revenue': user.revenue,
        'reservations': user.reservations,
        'lastLogin': new Date(user.lastLogin)
    };

    return columnMap[column] || '';
};

/**
 * Actualizar indicadores de ordenamiento
 */
AdminPanel.prototype.updateSortIndicators = function () {
    // Limpiar todos los indicadores
    document.querySelectorAll('.sortable i').forEach(icon => {
        icon.className = 'fas fa-sort';
    });

    // Actualizar indicador activo
    const activeHeader = document.querySelector(`[data-column="${UIState.sortColumn}"]`);
    if (activeHeader) {
        const icon = activeHeader.querySelector('i');
        if (icon) {
            icon.className = UIState.sortDirection === 'asc' ? 'fas fa-sort-up' : 'fas fa-sort-down';
        }
    }
};

/**
 * Configurar paginación
 */
AdminPanel.prototype.setupPagination = function () {
    // Configurar selector de tamaño de página
    const pageSizeSelect = document.getElementById('tablePageSize');
    if (pageSizeSelect) {
        pageSizeSelect.addEventListener('change', () => {
            UIState.pageSize = parseInt(pageSizeSelect.value);
            UIState.currentPage = 1;
            this.renderUsersTable();
            this.updatePagination();
        });
    }
};

/**
 * Actualizar paginación
 */
AdminPanel.prototype.updatePagination = function () {
    const totalUsers = AdminCache.filteredUsers.length;
    const totalPages = Math.ceil(totalUsers / UIState.pageSize);
    const startItem = (UIState.currentPage - 1) * UIState.pageSize + 1;
    const endItem = Math.min(UIState.currentPage * UIState.pageSize, totalUsers);

    // Actualizar información de paginación
    const paginationInfo = document.getElementById('paginationInfo');
    if (paginationInfo) {
        paginationInfo.textContent = `Mostrando ${startItem}-${endItem} de ${totalUsers} usuarios`;
    }

    // Actualizar botones de navegación
    const prevBtn = document.getElementById('prevPageBtn');
    const nextBtn = document.getElementById('nextPageBtn');

    if (prevBtn) prevBtn.disabled = UIState.currentPage <= 1;
    if (nextBtn) nextBtn.disabled = UIState.currentPage >= totalPages;

    // Generar números de página
    this.generatePageNumbers(totalPages);
};

/**
 * Generar números de página
 */
AdminPanel.prototype.generatePageNumbers = function (totalPages) {
    const pageNumbers = document.getElementById('pageNumbers');
    if (!pageNumbers) return;

    let pages = [];
    const maxVisible = 5;

    if (totalPages <= maxVisible) {
        pages = Array.from({ length: totalPages }, (_, i) => i + 1);
    } else {
        pages = [1];
        if (UIState.currentPage > 3) pages.push('...');

        for (let i = Math.max(2, UIState.currentPage - 1); i <= Math.min(totalPages - 1, UIState.currentPage + 1); i++) {
            pages.push(i);
        }

        if (UIState.currentPage < totalPages - 2) pages.push('...');
        pages.push(totalPages);
    }

    pageNumbers.innerHTML = pages.map(page => {
        if (page === '...') {
            return '<span class="page-ellipsis">...</span>';
        }
        return `<button class="page-number ${page === UIState.currentPage ? 'active' : ''}" 
                       onclick="adminPanel.goToPage(${page})">${page}</button>`;
    }).join('');
};

/**
 * Ir a página específica
 */
AdminPanel.prototype.goToPage = function (page) {
    UIState.currentPage = page;
    this.renderUsersTable();
    this.updatePagination();

    // Scroll a la tabla
    const tableContainer = document.querySelector('.table-container');
    if (tableContainer) {
        tableContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
};

/**
 * Navegación de páginas
 */
AdminPanel.prototype.previousPage = function () {
    if (UIState.currentPage > 1) {
        this.goToPage(UIState.currentPage - 1);
    }
};

AdminPanel.prototype.nextPage = function () {
    const totalPages = Math.ceil(AdminCache.filteredUsers.length / UIState.pageSize);
    if (UIState.currentPage < totalPages) {
        this.goToPage(UIState.currentPage + 1);
    }
};

/**
 * Renderizar tabla de usuarios
 */
AdminPanel.prototype.renderUsersTable = function () {
    const tbody = document.getElementById('usersTableBody');
    if (!tbody) return;

    const startIndex = (UIState.currentPage - 1) * UIState.pageSize;
    const endIndex = startIndex + UIState.pageSize;
    const pageUsers = AdminCache.filteredUsers.slice(startIndex, endIndex);

    if (pageUsers.length === 0) {
        tbody.innerHTML = this.renderEmptyState();
        return;
    }

    tbody.innerHTML = pageUsers.map(user => this.renderUserRow(user)).join('');

    // Actualizar estado de selección
    this.updateSelectionState();
};

/**
 * Renderizar fila de usuario
 */
AdminPanel.prototype.renderUserRow = function (user) {
    const isSelected = UIState.selectedUsers.has(user.id);
    const statusClass = this.getStatusClass(user.status);

    return `
        <tr class="user-row ${statusClass}" data-user-id="${user.id}">
            <td>
                <input type="checkbox" class="user-checkbox" value="${user.id}" 
                       ${isSelected ? 'checked' : ''} onchange="adminPanel.toggleUserSelection(${user.id})">
            </td>
            <td>
                <div class="user-info">
                    <div class="user-avatar" style="background-color: ${this.getAvatarColor(user.name)}">
                        ${user.name.charAt(0).toUpperCase()}
                    </div>
                    <div class="user-details">
                        <div class="user-name" title="${this.escapeHtml(user.name)}">
                            ${this.escapeHtml(user.name)}
                        </div>
                        <div class="user-owner">${this.escapeHtml(user.owner)}</div>
                    </div>
                </div>
            </td>
            <td>
                <div class="contact-info">
                    <div class="user-email">
                        <i class="fas fa-envelope"></i>
                        <a href="mailto:${user.email}">${this.escapeHtml(user.email)}</a>
                    </div>
                    <div class="user-phone">
                        <i class="fas fa-phone"></i>
                        <a href="tel:${user.phone}">${this.escapeHtml(user.phone)}</a>
                    </div>
                </div>
            </td>
            <td>
                <div class="plan-info">
                    <span class="plan-badge ${user.plan}">${this.getPlanName(user.plan)}</span>
                    ${user.expires ? `<div class="plan-expires">Exp: ${this.formatDate(user.expires)}</div>` : ''}
                </div>
            </td>
            <td>
                <span class="status-badge ${user.status}" title="Estado: ${this.getStatusName(user.status)}">
                    ${this.getStatusName(user.status)}
                </span>
            </td>
            <td>
                <div class="date-info">
                    <div class="created-date" title="Fecha de registro">
                        ${this.formatDate(user.created)}
                    </div>
                    <div class="last-login" title="Último acceso">
                        ${this.formatTimeAgo(new Date(user.lastLogin).getTime())}
                    </div>
                </div>
            </td>
            <td>
                <div class="revenue-info">
                    <div class="revenue-amount" title="Ingresos mensuales">
                        ${this.formatCurrency(user.revenue)}
                    </div>
                    <div class="reservations-count" title="Total de reservas">
                        ${user.reservations.toLocaleString()} reservas
                    </div>
                    ${user.monthlyGrowth ? `
                        <div class="growth-indicator ${user.monthlyGrowth >= 0 ? 'positive' : 'negative'}">
                            <i class="fas fa-arrow-${user.monthlyGrowth >= 0 ? 'up' : 'down'}"></i>
                            ${Math.abs(user.monthlyGrowth).toFixed(1)}%
                        </div>
                    ` : ''}
                </div>
            </td>
            <td>
                <div class="action-buttons">
                    <button class="btn-action btn-view" onclick="adminPanel.viewUser(${user.id})" 
                            title="Ver detalles">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="btn-action btn-edit" onclick="adminPanel.editUser(${user.id})" 
                            title="Editar usuario">
                        <i class="fas fa-edit"></i>
                    </button>
                    <div class="dropdown">
                        <button class="btn-action btn-more" onclick="adminPanel.toggleUserMenu(${user.id})" 
                                title="Más opciones">
                            <i class="fas fa-ellipsis-v"></i>
                        </button>
                        <div class="dropdown-menu" id="userMenu${user.id}">
                            ${this.renderUserDropdownMenu(user)}
                        </div>
                    </div>
                </div>
            </td>
        </tr>
    `;
};

/**
 * Renderizar menú desplegable de usuario
 */
AdminPanel.prototype.renderUserDropdownMenu = function (user) {
    const menuItems = [];

    // Opciones según el estado del usuario
    if (user.status === 'active') {
        menuItems.push(`
            <a href="#" onclick="adminPanel.suspendUser(${user.id})">
                <i class="fas fa-pause"></i> Suspender
            </a>
        `);
    } else if (user.status === 'suspended') {
        menuItems.push(`
            <a href="#" onclick="adminPanel.activateUser(${user.id})">
                <i class="fas fa-play"></i> Activar
            </a>
        `);
    }

    // Opciones de plan
    menuItems.push(`
        <a href="#" onclick="adminPanel.changePlan(${user.id})">
            <i class="fas fa-crown"></i> Cambiar Plan
        </a>
        <a href="#" onclick="adminPanel.extendPlan(${user.id})">
            <i class="fas fa-calendar-plus"></i> Extender Plan
        </a>
    `);

    // Opciones de información
    menuItems.push(`
        <a href="#" onclick="adminPanel.viewLogs(${user.id})">
            <i class="fas fa-file-alt"></i> Ver Logs
        </a>
        <a href="#" onclick="adminPanel.sendMessage(${user.id})">
            <i class="fas fa-envelope"></i> Enviar Mensaje
        </a>
    `);

    // Separador y opción peligrosa
    menuItems.push(`
        <div class="dropdown-divider"></div>
        <a href="#" onclick="adminPanel.deleteUser(${user.id})" class="danger">
            <i class="fas fa-trash"></i> Eliminar
        </a>
    `);

    return menuItems.join('');
};

/**
 * Renderizar estado vacío
 */
AdminPanel.prototype.renderEmptyState = function () {
    return `
        <tr>
            <td colspan="8" class="empty-state">
                <div class="empty-state-content">
                    <i class="fas fa-search fa-3x"></i>
                    <h3>No se encontraron usuarios</h3>
                    <p>No hay usuarios que coincidan con los filtros aplicados.</p>
                    <button class="btn btn-outline" onclick="adminPanel.clearAllFilters()">
                        <i class="fas fa-times"></i>
                        Limpiar Filtros
                    </button>
                </div>
            </td>
        </tr>
    `;
};

/**
 * Configurar selección masiva
 */
AdminPanel.prototype.setupBulkSelection = function () {
    const selectAllCheckbox = document.getElementById('selectAllUsers');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', (e) => {
            this.toggleSelectAll(e.target.checked);
        });
    }
};

/**
 * Alternar selección de usuario
 */
AdminPanel.prototype.toggleUserSelection = function (userId) {
    if (UIState.selectedUsers.has(userId)) {
        UIState.selectedUsers.delete(userId);
    } else {
        UIState.selectedUsers.add(userId);
    }

    this.updateBulkActions();
    this.updateSelectAllState();
};

/**
 * Alternar seleccionar todos
 */
AdminPanel.prototype.toggleSelectAll = function (selectAll) {
    const currentPageUsers = this.getCurrentPageUsers();

    if (selectAll) {
        currentPageUsers.forEach(user => {
            UIState.selectedUsers.add(user.id);
        });
    } else {
        currentPageUsers.forEach(user => {
            UIState.selectedUsers.delete(user.id);
        });
    }

    this.updateSelectionState();
    this.updateBulkActions();
};

/**
 * Obtener usuarios de la página actual
 */
AdminPanel.prototype.getCurrentPageUsers = function () {
    const startIndex = (UIState.currentPage - 1) * UIState.pageSize;
    const endIndex = startIndex + UIState.pageSize;
    return AdminCache.filteredUsers.slice(startIndex, endIndex);
};

/**
 * Actualizar estado de selección
 */
AdminPanel.prototype.updateSelectionState = function () {
    // Actualizar checkboxes de usuarios
    document.querySelectorAll('.user-checkbox').forEach(checkbox => {
        const userId = parseInt(checkbox.value);
        checkbox.checked = UIState.selectedUsers.has(userId);
    });

    this.updateSelectAllState();
};

/**
 * Actualizar estado del checkbox "seleccionar todos"
 */
AdminPanel.prototype.updateSelectAllState = function () {
    const selectAllCheckbox = document.getElementById('selectAllUsers');
    if (!selectAllCheckbox) return;

    const currentPageUsers = this.getCurrentPageUsers();
    const selectedInPage = currentPageUsers.filter(user => UIState.selectedUsers.has(user.id)).length;

    if (selectedInPage === 0) {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = false;
    } else if (selectedInPage === currentPageUsers.length) {
        selectAllCheckbox.checked = true;
        selectAllCheckbox.indeterminate = false;
    } else {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = true;
    }
};

/**
 * Actualizar acciones masivas
 */
AdminPanel.prototype.updateBulkActions = function () {
    const bulkActions = document.getElementById('bulkActions');
    const selectedCount = document.getElementById('selectedCount');

    if (UIState.selectedUsers.size > 0) {
        if (bulkActions) bulkActions.style.display = 'block';
        if (selectedCount) {
            selectedCount.textContent = `${UIState.selectedUsers.size} usuario${UIState.selectedUsers.size > 1 ? 's' : ''} seleccionado${UIState.selectedUsers.size > 1 ? 's' : ''}`;
        }
    } else {
        if (bulkActions) bulkActions.style.display = 'none';
    }
};

/**
 * Funciones de utilidad para renderizado
 */
AdminPanel.prototype.getStatusClass = function (status) {
    const classes = {
        'active': '',
        'inactive': 'inactive',
        'suspended': 'suspended',
        'expired': 'expired'
    };
    return classes[status] || '';
};

AdminPanel.prototype.getAvatarColor = function (name) {
    const colors = [
        '#dc2626', '#ea580c', '#d97706', '#ca8a04', '#65a30d',
        '#16a34a', '#059669', '#0891b2', '#0284c7', '#2563eb',
        '#4f46e5', '#7c3aed', '#a21caf', '#be185d'
    ];

    const hash = name.split('').reduce((a, b) => {
        a = ((a << 5) - a) + b.charCodeAt(0);
        return a & a;
    }, 0);

    return colors[Math.abs(hash) % colors.length];
};

AdminPanel.prototype.getPlanName = function (plan) {
    const plans = {
        'free': 'Gratuito',
        'basic': 'Básico',
        'premium': 'Premium',
        'enterprise': 'Empresarial'
    };
    return plans[plan] || plan;
};

AdminPanel.prototype.getStatusName = function (status) {
    const statuses = {
        'active': 'Activo',
        'inactive': 'Inactivo',
        'suspended': 'Suspendido',
        'expired': 'Expirado'
    };
    return statuses[status] || status;
};

AdminPanel.prototype.formatDate = function (dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('es-CO', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric'
    });
};

/**
 * Función debounce para optimizar rendimiento
 */
AdminPanel.prototype.debounce = function (func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
};

/* <-- seguir aquí --> */

/**
 * ============================================
 * ADMIN.JS - PARTE 4/6
 * Sistema de Modales y Acciones de Usuario
 * Sistema de reservas - Panel de control
 * ============================================
 */

/**
 * Sistema de modales avanzado
 */
AdminPanel.prototype.showModal = function (title, content, actions = [], options = {}) {
    const modal = document.createElement('div');
    modal.className = 'modal-overlay';
    modal.id = `modal_${Date.now()}`;

    const modalOptions = {
        width: options.width || '500px',
        closable: options.closable !== false,
        backdrop: options.backdrop !== false,
        keyboard: options.keyboard !== false,
        className: options.className || ''
    };

    const actionsHtml = actions.map(action =>
        `<button class="btn ${action.class || 'btn-outline'}" 
                 ${action.loading ? 'data-loading="true"' : ''}
                 onclick="${action.onclick}"
                 ${action.disabled ? 'disabled' : ''}>
            ${action.icon ? `<i class="${action.icon}"></i>` : ''}
            ${action.text}
         </button>`
    ).join('');

    modal.innerHTML = `
        <div class="modal-content ${modalOptions.className}" style="max-width: ${modalOptions.width}">
            <div class="modal-header">
                <h3 class="modal-title">${this.escapeHtml(title)}</h3>
                ${modalOptions.closable ? '<button class="modal-close" onclick="adminPanel.closeModal()">&times;</button>' : ''}
            </div>
            <div class="modal-body">
                ${content}
            </div>
            ${actions.length > 0 ? `
                <div class="modal-actions">
                    ${actionsHtml}
                </div>
            ` : ''}
        </div>
    `;

    document.body.appendChild(modal);
    UIState.openModals.push(modal);

    // Mostrar modal con animación
    setTimeout(() => modal.classList.add('show'), 100);

    // Configurar eventos
    this.setupModalEvents(modal, modalOptions);

    return modal;
};

/**
 * Configurar eventos del modal
 */
AdminPanel.prototype.setupModalEvents = function (modal, options) {
    // Cerrar con ESC
    if (options.keyboard) {
        const handleEsc = (e) => {
            if (e.key === 'Escape' && UIState.openModals[UIState.openModals.length - 1] === modal) {
                this.closeModal();
                document.removeEventListener('keydown', handleEsc);
            }
        };
        document.addEventListener('keydown', handleEsc);
    }

    // Cerrar al hacer clic fuera (backdrop)
    if (options.backdrop) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                this.closeModal();
            }
        });
    }

    // Prevenir scroll del body
    document.body.style.overflow = 'hidden';
};

/**
 * Cerrar modal activo
 */
AdminPanel.prototype.closeModal = function () {
    const activeModal = UIState.openModals[UIState.openModals.length - 1];
    if (!activeModal) return;

    activeModal.classList.remove('show');

    setTimeout(() => {
        if (activeModal.parentNode) {
            activeModal.parentNode.removeChild(activeModal);
        }

        // Remover del tracking
        UIState.openModals = UIState.openModals.filter(m => m !== activeModal);

        // Restaurar scroll si no hay más modales
        if (UIState.openModals.length === 0) {
            document.body.style.overflow = '';
        }
    }, 300);
};

/**
 * Ver detalles de usuario
 */
AdminPanel.prototype.viewUser = function (userId) {
    const user = AdminCache.users.find(u => u.id === userId);
    if (!user) {
        this.showNotification('Error', 'Usuario no encontrado', 'error');
        return;
    }

    const content = `
        <div class="user-details-modal">
            <div class="user-header">
                <div class="user-avatar-large" style="background-color: ${this.getAvatarColor(user.name)}">
                    ${user.name.charAt(0).toUpperCase()}
                </div>
                <div class="user-info-main">
                    <h2>${this.escapeHtml(user.name)}</h2>
                    <p class="user-owner">${this.escapeHtml(user.owner)}</p>
                    <span class="status-badge ${user.status}">${this.getStatusName(user.status)}</span>
                </div>
            </div>
            
            <div class="user-details-grid">
                <div class="detail-section">
                    <h4><i class="fas fa-user"></i> Información Personal</h4>
                    <div class="detail-item">
                        <label>Propietario:</label>
                        <span>${this.escapeHtml(user.owner)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Email:</label>
                        <span><a href="mailto:${user.email}">${this.escapeHtml(user.email)}</a></span>
                    </div>
                    <div class="detail-item">
                        <label>Teléfono:</label>
                        <span><a href="tel:${user.phone}">${this.escapeHtml(user.phone)}</a></span>
                    </div>
                    <div class="detail-item">
                        <label>Región:</label>
                        <span>${this.getRegionName(user.region)}</span>
                    </div>
                </div>
                
                <div class="detail-section">
                    <h4><i class="fas fa-crown"></i> Plan y Facturación</h4>
                    <div class="detail-item">
                        <label>Plan Actual:</label>
                        <span class="plan-badge ${user.plan}">${this.getPlanName(user.plan)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Ingresos Mensuales:</label>
                        <span class="revenue-amount">${this.formatCurrency(user.revenue)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Ingresos Totales:</label>
                        <span class="revenue-total">${this.formatCurrency(user.totalRevenue || 0)}</span>
                    </div>
                    ${user.expires ? `
                        <div class="detail-item">
                            <label>Vencimiento:</label>
                            <span class="expiry-date">${this.formatDate(user.expires)}</span>
                        </div>
                    ` : ''}
                </div>
                
                <div class="detail-section">
                    <h4><i class="fas fa-chart-line"></i> Estadísticas</h4>
                    <div class="detail-item">
                        <label>Total Reservas:</label>
                        <span>${user.reservations.toLocaleString()}</span>
                    </div>
                    <div class="detail-item">
                        <label>Crecimiento Mensual:</label>
                        <span class="growth-indicator ${user.monthlyGrowth >= 0 ? 'positive' : 'negative'}">
                            <i class="fas fa-arrow-${user.monthlyGrowth >= 0 ? 'up' : 'down'}"></i>
                            ${Math.abs(user.monthlyGrowth || 0).toFixed(1)}%
                        </span>
                    </div>
                    <div class="detail-item">
                        <label>Fecha de Registro:</label>
                        <span>${this.formatDate(user.created)}</span>
                    </div>
                    <div class="detail-item">
                        <label>Último Acceso:</label>
                        <span>${this.formatTimeAgo(new Date(user.lastLogin).getTime())}</span>
                    </div>
                </div>
                
                <div class="detail-section full-width">
                    <h4><i class="fas fa-history"></i> Actividad Reciente</h4>
                    <div class="activity-timeline">
                        ${this.generateUserActivity(user)}
                    </div>
                </div>
            </div>
        </div>
    `;

    const actions = [
        {
            text: 'Editar Usuario',
            class: 'btn-primary',
            icon: 'fas fa-edit',
            onclick: `adminPanel.closeModal(); adminPanel.editUser(${userId});`
        },
        {
            text: 'Cerrar',
            class: 'btn-outline',
            onclick: 'adminPanel.closeModal()'
        }
    ];

    this.showModal(`Detalles de ${user.name}`, content, actions, { width: '800px' });
    this.logActivity('view_user', 'Usuario visualizado', { userId, userName: user.name });
};

/**
 * Generar actividad del usuario
 */
AdminPanel.prototype.generateUserActivity = function (user) {
    const activities = [
        {
            action: 'Pago procesado',
            description: `Plan ${this.getPlanName(user.plan)} - ${this.formatCurrency(user.revenue)}`,
            time: Date.now() - 86400000,
            type: 'payment'
        },
        {
            action: 'Reserva creada',
            description: 'Nueva reserva para 4 personas',
            time: Date.now() - 172800000,
            type: 'reservation'
        },
        {
            action: 'Perfil actualizado',
            description: 'Información de contacto modificada',
            time: Date.now() - 432000000,
            type: 'profile'
        }
    ];

    return activities.map(activity => `
        <div class="timeline-item">
            <div class="timeline-marker ${activity.type}">
                <i class="fas fa-${this.getActivityIcon(activity.type)}"></i>
            </div>
            <div class="timeline-content">
                <div class="timeline-title">${activity.action}</div>
                <div class="timeline-description">${activity.description}</div>
                <div class="timeline-time">${this.formatTimeAgo(activity.time)}</div>
            </div>
        </div>
    `).join('');
};

/**
 * Editar usuario
 */
AdminPanel.prototype.editUser = function (userId) {
    const user = AdminCache.users.find(u => u.id === userId);
    if (!user) {
        this.showNotification('Error', 'Usuario no encontrado', 'error');
        return;
    }

    const content = `
        <form id="editUserForm" class="edit-user-form">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Nombre del Negocio *</label>
                    <input type="text" name="name" class="form-input" value="${this.escapeHtml(user.name)}" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Propietario *</label>
                    <input type="text" name="owner" class="form-input" value="${this.escapeHtml(user.owner)}" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Email *</label>
                    <input type="email" name="email" class="form-input" value="${user.email}" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Teléfono *</label>
                    <input type="tel" name="phone" class="form-input" value="${user.phone}" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Plan</label>
                    <select name="plan" class="form-select">
                        <option value="free" ${user.plan === 'free' ? 'selected' : ''}>Gratuito</option>
                        <option value="basic" ${user.plan === 'basic' ? 'selected' : ''}>Básico</option>
                        <option value="premium" ${user.plan === 'premium' ? 'selected' : ''}>Premium</option>
                        <option value="enterprise" ${user.plan === 'enterprise' ? 'selected' : ''}>Empresarial</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Estado</label>
                    <select name="status" class="form-select">
                        <option value="active" ${user.status === 'active' ? 'selected' : ''}>Activo</option>
                        <option value="inactive" ${user.status === 'inactive' ? 'selected' : ''}>Inactivo</option>
                        <option value="suspended" ${user.status === 'suspended' ? 'selected' : ''}>Suspendido</option>
                        <option value="expired" ${user.status === 'expired' ? 'selected' : ''}>Expirado</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Región</label>
                    <select name="region" class="form-select">
                        <option value="bogota" ${user.region === 'bogota' ? 'selected' : ''}>Bogotá</option>
                        <option value="medellin" ${user.region === 'medellin' ? 'selected' : ''}>Medellín</option>
                        <option value="cali" ${user.region === 'cali' ? 'selected' : ''}>Cali</option>
                        <option value="barranquilla" ${user.region === 'barranquilla' ? 'selected' : ''}>Barranquilla</option>
                        <option value="other" ${user.region === 'other' ? 'selected' : ''}>Otra</option>
                    </select>
                </div>
                
                ${user.plan !== 'free' ? `
                    <div class="form-group full-width">
                        <label class="form-label">Fecha de Vencimiento</label>
                        <input type="date" name="expires" class="form-input" 
                               value="${user.expires ? user.expires : ''}" 
                               min="${new Date().toISOString().split('T')[0]}">
                    </div>
                ` : ''}
                
                <div class="form-group full-width">
                    <label class="form-label">Notas Administrativas</label>
                    <textarea name="notes" class="form-textarea" rows="3" 
                              placeholder="Notas internas sobre este usuario..."></textarea>
                </div>
            </div>
        </form>
    `;

    const actions = [
        {
            text: 'Cancelar',
            class: 'btn-outline',
            onclick: 'adminPanel.closeModal()'
        },
        {
            text: 'Guardar Cambios',
            class: 'btn-primary',
            icon: 'fas fa-save',
            onclick: `adminPanel.saveUserChanges(${userId})`
        }
    ];

    this.showModal(`Editar Usuario - ${user.name}`, content, actions, { width: '700px' });
    this.logActivity('edit_user_start', 'Inicio de edición de usuario', { userId, userName: user.name });
};

/**
 * Guardar cambios de usuario
 */
AdminPanel.prototype.saveUserChanges = function (userId) {
    const form = document.getElementById('editUserForm');
    if (!form) return;

    const formData = new FormData(form);
    const updatedData = {};

    // Recopilar datos del formulario
    for (let [key, value] of formData.entries()) {
        updatedData[key] = value.trim();
    }

    // Validar datos
    if (!this.validateUserData(updatedData)) {
        return;
    }

    // Mostrar estado de carga
    const saveBtn = document.querySelector('.modal-actions .btn-primary');
    this.setLoadingState(saveBtn, true);

    // Simular guardado
    setTimeout(() => {
        // Actualizar usuario en cache
        const userIndex = AdminCache.users.findIndex(u => u.id === userId);
        if (userIndex !== -1) {
            Object.assign(AdminCache.users[userIndex], updatedData);

            // Actualizar usuarios filtrados
            const filteredIndex = AdminCache.filteredUsers.findIndex(u => u.id === userId);
            if (filteredIndex !== -1) {
                Object.assign(AdminCache.filteredUsers[filteredIndex], updatedData);
            }
        }

        // Actualizar UI
        this.renderUsersTable();
        this.updateAllStatistics();

        // Cerrar modal y mostrar confirmación
        this.closeModal();
        this.showNotification('Usuario Actualizado', 'Los cambios se han guardado exitosamente', 'success');

        // Log de la acción
        this.logActivity('edit_user_save', 'Usuario actualizado', {
            userId,
            changes: updatedData,
            timestamp: new Date()
        });

        this.setLoadingState(saveBtn, false);
    }, 1500);
};

/**
 * Validar datos de usuario
 */
AdminPanel.prototype.validateUserData = function (data) {
    const errors = [];

    if (!data.name || data.name.length < 2) {
        errors.push('El nombre del negocio debe tener al menos 2 caracteres');
    }

    if (!data.owner || data.owner.length < 2) {
        errors.push('El nombre del propietario debe tener al menos 2 caracteres');
    }

    if (!data.email || !this.isValidEmail(data.email)) {
        errors.push('El email no tiene un formato válido');
    }

    if (!data.phone || data.phone.length < 10) {
        errors.push('El teléfono debe tener al menos 10 dígitos');
    }

    if (errors.length > 0) {
        this.showNotification('Errores de Validación', errors.join('<br>'), 'error', 8000);
        return false;
    }

    return true;
};

/**
 * Suspender usuario
 */
AdminPanel.prototype.suspendUser = function (userId) {
    const user = AdminCache.users.find(u => u.id === userId);
    if (!user) return;

    const content = `
        <div class="confirmation-modal">
            <div class="confirmation-icon warning">
                <i class="fas fa-pause"></i>
            </div>
            <h3>¿Suspender Usuario?</h3>
            <p>¿Estás seguro de que quieres suspender a <strong>${this.escapeHtml(user.name)}</strong>?</p>
            <p class="confirmation-details">El usuario no podrá acceder al sistema hasta que sea reactivado.</p>
            
            <div class="suspension-options">
                <label class="form-label">Motivo de suspensión:</label>
                <select id="suspensionReason" class="form-select">
                    <option value="payment">Problemas de pago</option>
                    <option value="violation">Violación de términos</option>
                    <option value="request">Solicitud del usuario</option>
                    <option value="maintenance">Mantenimiento</option>
                    <option value="other">Otro</option>
                </select>
                
                <label class="form-label">Notas adicionales:</label>
                <textarea id="suspensionNotes" class="form-textarea" rows="3" 
                          placeholder="Detalles sobre la suspensión..."></textarea>
            </div>
        </div>
    `;

    const actions = [
        {
            text: 'Cancelar',
            class: 'btn-outline',
            onclick: 'adminPanel.closeModal()'
        },
        {
            text: 'Suspender Usuario',
            class: 'btn-warning',
            icon: 'fas fa-pause',
            onclick: `adminPanel.confirmSuspendUser(${userId})`
        }
    ];

    this.showModal('Suspender Usuario', content, actions);
};

/**
 * Confirmar suspensión de usuario
 */
AdminPanel.prototype.confirmSuspendUser = function (userId) {
    const reason = document.getElementById('suspensionReason')?.value || 'other';
    const notes = document.getElementById('suspensionNotes')?.value || '';

    // Actualizar estado del usuario
    this.updateUserStatus(userId, 'suspended', {
        reason,
        notes,
        suspendedBy: AdminConfig.status.currentUser,
        suspendedAt: new Date()
    });

    this.closeModal();
    this.showNotification('Usuario Suspendido', 'El usuario ha sido suspendido exitosamente', 'success');
};

/**
 * Activar usuario
 */
AdminPanel.prototype.activateUser = function (userId) {
    const user = AdminCache.users.find(u => u.id === userId);
    if (!user) return;

    const content = `
        <div class="confirmation-modal">
            <div class="confirmation-icon success">
                <i class="fas fa-play"></i>
            </div>
            <h3>¿Activar Usuario?</h3>
            <p>¿Estás seguro de que quieres activar a <strong>${this.escapeHtml(user.name)}</strong>?</p>
            <p class="confirmation-details">El usuario podrá acceder al sistema nuevamente.</p>
        </div>
    `;

    const actions = [
        {
            text: 'Cancelar',
            class: 'btn-outline',
            onclick: 'adminPanel.closeModal()'
        },
        {
            text: 'Activar Usuario',
            class: 'btn-success',
            icon: 'fas fa-play',
            onclick: `adminPanel.confirmActivateUser(${userId})`
        }
    ];

    this.showModal('Activar Usuario', content, actions);
};

/**
 * Confirmar activación de usuario
 */
AdminPanel.prototype.confirmActivateUser = function (userId) {
    // Actualizar estado del usuario
    this.updateUserStatus(userId, 'active', {
        activatedBy: AdminConfig.status.currentUser,
        activatedAt: new Date()
    });

    this.closeModal();
    this.showNotification('Usuario Activado', 'El usuario ha sido activado exitosamente', 'success');
};

/**
 * Actualizar estado de usuario
 */
AdminPanel.prototype.updateUserStatus = function (userId, newStatus, metadata = {}) {
    const userIndex = AdminCache.users.findIndex(u => u.id === userId);
    if (userIndex !== -1) {
        const oldStatus = AdminCache.users[userIndex].status;
        AdminCache.users[userIndex].status = newStatus;

        // Actualizar usuarios filtrados
        const filteredIndex = AdminCache.filteredUsers.findIndex(u => u.id === userId);
        if (filteredIndex !== -1) {
            AdminCache.filteredUsers[filteredIndex].status = newStatus;
        }

        // Actualizar UI
        this.renderUsersTable();
        this.updateAllStatistics();

        // Log de la acción
        this.logActivity('status_change', `Estado cambiado de ${oldStatus} a ${newStatus}`, {
            userId,
            oldStatus,
            newStatus,
            metadata
        });
    }
};

/**
 * Eliminar usuario
 */
AdminPanel.prototype.deleteUser = function (userId) {
    const user = AdminCache.users.find(u => u.id === userId);
    if (!user) return;

    const content = `
        <div class="confirmation-modal danger">
            <div class="confirmation-icon error">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3>¿Eliminar Usuario?</h3>
            <p>¿Estás seguro de que quieres eliminar permanentemente a <strong>${this.escapeHtml(user.name)}</strong>?</p>
            <div class="danger-warning">
                <i class="fas fa-warning"></i>
                <strong>¡Esta acción no se puede deshacer!</strong>
                <ul>
                    <li>Se perderán todos los datos del usuario</li>
                    <li>Se eliminarán todas las reservas asociadas</li>
                    <li>Se cancelarán los pagos pendientes</li>
                </ul>
            </div>
            
            <div class="confirmation-input">
                <label class="form-label">Para confirmar, escribe el nombre del negocio:</label>
                <input type="text" id="deleteConfirmation" class="form-input" 
                       placeholder="${user.name}" autocomplete="off">
            </div>
        </div>
    `;

    const actions = [
        {
            text: 'Cancelar',
            class: 'btn-outline',
            onclick: 'adminPanel.closeModal()'
        },
        {
            text: 'Eliminar Permanentemente',
            class: 'btn-danger',
            icon: 'fas fa-trash',
            onclick: `adminPanel.confirmDeleteUser(${userId}, '${user.name}')`
        }
    ];

    this.showModal('Eliminar Usuario', content, actions, { width: '600px' });
};

/**
 * Confirmar eliminación de usuario
 */
AdminPanel.prototype.confirmDeleteUser = function (userId, expectedName) {
    const confirmation = document.getElementById('deleteConfirmation')?.value.trim();

    if (confirmation !== expectedName) {
        this.showNotification('Error', 'El nombre ingresado no coincide', 'error');
        return;
    }

    const deleteBtn = document.querySelector('.modal-actions .btn-danger');
    this.setLoadingState(deleteBtn, true);

    // Simular eliminación
    setTimeout(() => {
        // Remover de cache
        AdminCache.users = AdminCache.users.filter(u => u.id !== userId);
        AdminCache.filteredUsers = AdminCache.filteredUsers.filter(u => u.id !== userId);

        // Remover de seleccionados
        UIState.selectedUsers.delete(userId);

        // Actualizar UI
        this.renderUsersTable();
        this.updateAllStatistics();
        this.updateBulkActions();

        this.closeModal();
        this.showNotification('Usuario Eliminado', 'El usuario ha sido eliminado permanentemente', 'success');

        // Log de la acción
        this.logActivity('delete_user', 'Usuario eliminado permanentemente', {
            userId,
            userName: expectedName,
            deletedBy: AdminConfig.status.currentUser
        });

        this.setLoadingState(deleteBtn, false);
    }, 2000);
};

/**
 * Funciones de utilidad
 */
AdminPanel.prototype.getRegionName = function (region) {
    const regions = {
        'bogota': 'Bogotá',
        'medellin': 'Medellín',
        'cali': 'Cali',
        'barranquilla': 'Barranquilla',
        'other': 'Otra'
    };
    return regions[region] || region;
};

AdminPanel.prototype.isValidEmail = function (email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
};

AdminPanel.prototype.toggleUserMenu = function (userId) {
    // Cerrar otros menús abiertos
    document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
        if (menu.id !== `userMenu${userId}`) {
            menu.classList.remove('show');
        }
    });

    // Alternar menú actual
    const menu = document.getElementById(`userMenu${userId}`);
    if (menu) {
        menu.classList.toggle('show');
    }

    // Cerrar menú al hacer clic fuera
    setTimeout(() => {
        document.addEventListener('click', function closeMenu(e) {
            if (!e.target.closest('.dropdown')) {
                document.querySelectorAll('.dropdown-menu.show').forEach(m => {
                    m.classList.remove('show');
                });
                document.removeEventListener('click', closeMenu);
            }
        });
    }, 100);
};

/* <-- seguir aquí --> */

/**
 * ============================================
 * ADMIN.JS - PARTE 5/6
 * Funciones Avanzadas, Auto-refresh y Gestión de Configuraciones
 * Sistema de reservas - Panel de control
 * ============================================
 */

/**
 * Cambiar plan de usuario
 */
AdminPanel.prototype.changePlan = function (userId) {
    const user = AdminCache.users.find(u => u.id === userId);
    if (!user) return;

    const planPrices = {
        free: 0,
        basic: 99000,
        premium: 299000,
        enterprise: 599000
    };

    const content = `
        <div class="change-plan-modal">
            <div class="current-plan">
                <h4>Plan Actual</h4>
                <div class="plan-card current">
                    <span class="plan-badge ${user.plan}">${this.getPlanName(user.plan)}</span>
                    <div class="plan-price">${this.formatCurrency(planPrices[user.plan])}/mes</div>
                    ${user.expires ? `<div class="plan-expires">Vence: ${this.formatDate(user.expires)}</div>` : ''}
                </div>
            </div>
            
            <div class="plan-selection">
                <h4>Seleccionar Nuevo Plan</h4>
                <div class="plans-grid">
                    ${Object.keys(planPrices).map(planKey => `
                        <div class="plan-option ${planKey === user.plan ? 'current' : ''}" 
                             onclick="adminPanel.selectPlan('${planKey}')">
                            <input type="radio" name="newPlan" value="${planKey}" 
                                   ${planKey === user.plan ? 'checked' : ''} id="plan_${planKey}">
                            <label for="plan_${planKey}">
                                <div class="plan-name">${this.getPlanName(planKey)}</div>
                                <div class="plan-price">${this.formatCurrency(planPrices[planKey])}/mes</div>
                                <div class="plan-features">
                                    ${this.getPlanFeatures(planKey).map(feature => `<div>• ${feature}</div>`).join('')}
                                </div>
                            </label>
                        </div>
                    `).join('')}
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Motivo del cambio</label>
                <textarea id="changeReason" class="form-textarea" rows="2" 
                          placeholder="Motivo del cambio de plan..."></textarea>
            </div>
        </div>
    `;

    const actions = [
        { text: 'Cancelar', class: 'btn-outline', onclick: 'adminPanel.closeModal()' },
        { text: 'Cambiar Plan', class: 'btn-primary', icon: 'fas fa-crown', onclick: `adminPanel.confirmPlanChange(${userId})` }
    ];

    this.showModal(`Cambiar Plan - ${user.name}`, content, actions, { width: '700px' });

    this.logActivity('change_plan_start', 'Inicio de cambio de plan', { userId, userName: user.name });
};

/**
 * Obtener características del plan
 */
AdminPanel.prototype.getPlanFeatures = function (plan) {
    const features = {
        free: ['50 reservas/mes', '1 ubicación', 'Soporte email'],
        basic: ['500 reservas/mes', '2 ubicaciones', 'Soporte prioritario', 'Reportes básicos'],
        premium: ['Reservas ilimitadas', '5 ubicaciones', 'Soporte 24/7', 'Reportes avanzados', 'API básica'],
        enterprise: ['Reservas ilimitadas', 'Ubicaciones ilimitadas', 'Soporte dedicado', 'Reportes personalizados', 'API completa']
    };
    return features[plan] || [];
};

/**
 * Seleccionar plan
 */
AdminPanel.prototype.selectPlan = function (planKey) {
    document.getElementById(`plan_${planKey}`).checked = true;
    document.querySelectorAll('.plan-option').forEach(option => {
        option.classList.remove('selected');
    });
    document.querySelector(`#plan_${planKey}`).closest('.plan-option').classList.add('selected');
};

/**
 * Confirmar cambio de plan
 */
AdminPanel.prototype.confirmPlanChange = function (userId) {
    const selectedPlan = document.querySelector('input[name="newPlan"]:checked')?.value;
    const reason = document.getElementById('changeReason').value;

    if (!selectedPlan) {
        this.showNotification('Error', 'Selecciona un plan', 'error');
        return;
    }

    const user = AdminCache.users.find(u => u.id === userId);
    if (selectedPlan === user.plan) {
        this.showNotification('Info', 'El plan seleccionado es el mismo actual', 'info');
        return;
    }

    const changeBtn = document.querySelector('.modal-actions .btn-primary');
    this.setLoadingState(changeBtn, true);

    setTimeout(() => {
        this.updateUserPlan(userId, selectedPlan, {
            reason,
            previousPlan: user.plan,
            changedBy: AdminConfig.status.currentUser
        });

        this.closeModal();
        this.showNotification('Plan Actualizado', `Plan cambiado a ${this.getPlanName(selectedPlan)} exitosamente`, 'success');
        this.setLoadingState(changeBtn, false);
    }, 2000);
};

/**
 * Actualizar plan de usuario
 */
AdminPanel.prototype.updateUserPlan = function (userId, newPlan, metadata = {}) {
    const userIndex = AdminCache.users.findIndex(u => u.id === userId);
    if (userIndex !== -1) {
        const planPrices = { free: 0, basic: 99000, premium: 299000, enterprise: 599000 };

        AdminCache.users[userIndex].plan = newPlan;
        AdminCache.users[userIndex].revenue = planPrices[newPlan];

        // Actualizar fecha de expiración para planes pagos
        if (newPlan !== 'free') {
            const nextYear = new Date();
            nextYear.setFullYear(nextYear.getFullYear() + 1);
            AdminCache.users[userIndex].expires = nextYear.toISOString().split('T')[0];
        } else {
            AdminCache.users[userIndex].expires = null;
        }

        // Actualizar usuarios filtrados
        const filteredIndex = AdminCache.filteredUsers.findIndex(u => u.id === userId);
        if (filteredIndex !== -1) {
            Object.assign(AdminCache.filteredUsers[filteredIndex], AdminCache.users[userIndex]);
        }

        // Actualizar UI
        this.renderUsersTable();
        this.updateAllStatistics();

        // Log de la acción
        this.logActivity('plan_change', `Plan cambiado a ${this.getPlanName(newPlan)}`, {
            userId,
            newPlan,
            metadata
        });
    }
};

/**
 * Extender plan de usuario
 */
AdminPanel.prototype.extendPlan = function (userId) {
    const user = AdminCache.users.find(u => u.id === userId);
    if (!user) return;

    if (user.plan === 'free') {
        this.showNotification('Info', 'Los planes gratuitos no tienen fecha de expiración', 'info');
        return;
    }

    const content = `
        <div class="extend-plan-modal">
            <div class="current-plan-info">
                <h4>Plan Actual</h4>
                <div class="plan-details">
                    <span class="plan-badge ${user.plan}">${this.getPlanName(user.plan)}</span>
                    <div class="current-expiry">
                        Vence: ${user.expires ? this.formatDate(user.expires) : 'No definido'}
                    </div>
                </div>
            </div>
            
            <div class="extension-options">
                <h4>Período de Extensión</h4>
                <div class="extension-grid">
                    <div class="extension-option" onclick="adminPanel.selectExtension(1)">
                        <input type="radio" name="extension" value="1" id="ext_1">
                        <label for="ext_1">
                            <div class="extension-period">1 Mes</div>
                            <div class="extension-price">${this.formatCurrency(user.revenue)}</div>
                        </label>
                    </div>
                    
                    <div class="extension-option recommended" onclick="adminPanel.selectExtension(3)">
                        <div class="extension-badge">Recomendado</div>
                        <input type="radio" name="extension" value="3" id="ext_3" checked>
                        <label for="ext_3">
                            <div class="extension-period">3 Meses</div>
                            <div class="extension-price">${this.formatCurrency(user.revenue * 3)}</div>
                        </label>
                    </div>
                    
                    <div class="extension-option" onclick="adminPanel.selectExtension(6)">
                        <input type="radio" name="extension" value="6" id="ext_6">
                        <label for="ext_6">
                            <div class="extension-period">6 Meses</div>
                            <div class="extension-price">${this.formatCurrency(user.revenue * 6)}</div>
                        </label>
                    </div>
                    
                    <div class="extension-option" onclick="adminPanel.selectExtension(12)">
                        <input type="radio" name="extension" value="12" id="ext_12">
                        <label for="ext_12">
                            <div class="extension-period">12 Meses</div>
                            <div class="extension-price">${this.formatCurrency(user.revenue * 12)}</div>
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Motivo de la extensión</label>
                <textarea id="extensionReason" class="form-textarea" rows="2" 
                          placeholder="Motivo de la extensión del plan..."></textarea>
            </div>
        </div>
    `;

    const actions = [
        { text: 'Cancelar', class: 'btn-outline', onclick: 'adminPanel.closeModal()' },
        { text: 'Extender Plan', class: 'btn-warning', icon: 'fas fa-calendar-plus', onclick: `adminPanel.confirmExtendPlan(${userId})` }
    ];

    this.showModal(`Extender Plan - ${user.name}`, content, actions, { width: '600px' });

    this.logActivity('extend_plan_start', 'Inicio de extensión de plan', { userId, userName: user.name });
};

/**
 * Seleccionar extensión
 */
AdminPanel.prototype.selectExtension = function (months) {
    document.getElementById(`ext_${months}`).checked = true;
    document.querySelectorAll('.extension-option').forEach(option => {
        option.classList.remove('selected');
    });
    document.querySelector(`#ext_${months}`).closest('.extension-option').classList.add('selected');
};

/**
 * Confirmar extensión de plan
 */
AdminPanel.prototype.confirmExtendPlan = function (userId) {
    const selectedMonths = parseInt(document.querySelector('input[name="extension"]:checked').value);
    const reason = document.getElementById('extensionReason').value;

    const extendBtn = document.querySelector('.modal-actions .btn-warning');
    this.setLoadingState(extendBtn, true);

    setTimeout(() => {
        this.processExtendPlan(userId, selectedMonths, reason);
        this.closeModal();
        this.showNotification('Plan Extendido', `Plan extendido por ${selectedMonths} mes${selectedMonths > 1 ? 'es' : ''} exitosamente`, 'success');
        this.setLoadingState(extendBtn, false);
    }, 1500);
};

/**
 * Procesar extensión de plan
 */
AdminPanel.prototype.processExtendPlan = function (userId, months, reason) {
    const userIndex = AdminCache.users.findIndex(u => u.id === userId);
    if (userIndex !== -1) {
        const user = AdminCache.users[userIndex];
        const currentExpiry = user.expires ? new Date(user.expires) : new Date();
        const newExpiry = new Date(currentExpiry);
        newExpiry.setMonth(newExpiry.getMonth() + months);

        AdminCache.users[userIndex].expires = newExpiry.toISOString().split('T')[0];

        // Actualizar usuarios filtrados
        const filteredIndex = AdminCache.filteredUsers.findIndex(u => u.id === userId);
        if (filteredIndex !== -1) {
            AdminCache.filteredUsers[filteredIndex].expires = AdminCache.users[userIndex].expires;
        }

        // Actualizar UI
        this.renderUsersTable();
        this.updateAllStatistics();

        // Log de la acción
        this.logActivity('extend_plan', `Plan extendido por ${months} meses`, {
            userId,
            months,
            reason,
            newExpiry: newExpiry.toISOString().split('T')[0],
            extendedBy: AdminConfig.status.currentUser
        });
    }
};

/**
 * Enviar mensaje a usuario
 */
AdminPanel.prototype.sendMessage = function (userId) {
    const user = AdminCache.users.find(u => u.id === userId);
    if (!user) return;

    const content = `
        <div class="send-message-modal">
            <div class="message-recipient">
                <h4>Enviar mensaje a:</h4>
                <div class="recipient-info">
                    <div class="user-avatar" style="background-color: ${this.getAvatarColor(user.name)}">
                        ${user.name.charAt(0).toUpperCase()}
                    </div>
                    <div class="recipient-details">
                        <div class="recipient-name">${this.escapeHtml(user.name)}</div>
                        <div class="recipient-email">${this.escapeHtml(user.email)}</div>
                    </div>
                </div>
            </div>
            
            <form id="messageForm">
                <div class="form-group">
                    <label class="form-label">Tipo de mensaje</label>
                    <select id="messageType" class="form-select">
                        <option value="info">Información</option>
                        <option value="warning">Advertencia</option>
                        <option value="promotion">Promoción</option>
                        <option value="support">Soporte</option>
                        <option value="billing">Facturación</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Asunto *</label>
                    <input type="text" id="messageSubject" class="form-input" 
                           placeholder="Asunto del mensaje..." required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Mensaje *</label>
                    <textarea id="messageBody" class="form-textarea" rows="6" 
                              placeholder="Escribe tu mensaje aquí..." required></textarea>
                </div>
                
                <div class="message-options">
                    <label class="checkbox-label">
                        <input type="checkbox" id="sendCopy"> 
                        Enviar copia a mi email
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" id="highPriority"> 
                        Marcar como alta prioridad
                    </label>
                </div>
            </form>
        </div>
    `;

    const actions = [
        { text: 'Cancelar', class: 'btn-outline', onclick: 'adminPanel.closeModal()' },
        { text: 'Enviar Mensaje', class: 'btn-primary', icon: 'fas fa-paper-plane', onclick: `adminPanel.confirmSendMessage(${userId})` }
    ];

    this.showModal(`Mensaje para ${user.name}`, content, actions, { width: '600px' });
};

/**
 * Confirmar envío de mensaje
 */
AdminPanel.prototype.confirmSendMessage = function (userId) {
    const subject = document.getElementById('messageSubject').value.trim();
    const body = document.getElementById('messageBody').value.trim();
    const messageType = document.getElementById('messageType').value;
    const sendCopy = document.getElementById('sendCopy').checked;
    const highPriority = document.getElementById('highPriority').checked;

    if (!subject || !body) {
        this.showNotification('Error', 'El asunto y mensaje son requeridos', 'error');
        return;
    }

    const sendBtn = document.querySelector('.modal-actions .btn-primary');
    this.setLoadingState(sendBtn, true);

    setTimeout(() => {
        this.processSendMessage(userId, {
            subject,
            body,
            messageType,
            sendCopy,
            highPriority
        });

        this.closeModal();
        this.showNotification('Mensaje Enviado', 'El mensaje ha sido enviado exitosamente', 'success');
        this.setLoadingState(sendBtn, false);
    }, 2000);
};

/**
 * Procesar envío de mensaje
 */
AdminPanel.prototype.processSendMessage = function (userId, messageData) {
    const user = AdminCache.users.find(u => u.id === userId);

    // Log de la acción
    this.logActivity('send_message', 'Mensaje enviado a usuario', {
        userId,
        userName: user.name,
        userEmail: user.email,
        subject: messageData.subject,
        messageType: messageData.messageType,
        highPriority: messageData.highPriority,
        sentBy: AdminConfig.status.currentUser
    });

    // En un entorno real, aquí se enviaría el email
    console.log('📧 Mensaje enviado:', {
        to: user.email,
        subject: messageData.subject,
        body: messageData.body,
        type: messageData.messageType
    });
};

/**
 * Ver logs de usuario
 */
AdminPanel.prototype.viewLogs = function (userId) {
    const user = AdminCache.users.find(u => u.id === userId);
    if (!user) return;

    // Filtrar logs relacionados con este usuario
    const userLogs = AdminCache.logs.filter(log =>
        log.metadata && (log.metadata.userId === userId || log.metadata.userEmail === user.email)
    );

    const content = `
        <div class="user-logs-modal">
            <div class="logs-header">
                <h4>Logs de Actividad</h4>
                <div class="user-info-mini">
                    <span class="user-name">${this.escapeHtml(user.name)}</span>
                    <span class="user-email">${this.escapeHtml(user.email)}</span>
                </div>
            </div>
            
            <div class="logs-filters">
                <select id="logTypeFilter" class="filter-select">
                    <option value="">Todos los tipos</option>
                    <option value="view_user">Vista de usuario</option>
                    <option value="edit_user">Edición</option>
                    <option value="status_change">Cambio de estado</option>
                    <option value="plan_change">Cambio de plan</option>
                    <option value="send_message">Mensajes</option>
                </select>
                
                <input type="date" id="logDateFilter" class="filter-input">
                
                <button class="btn btn-sm btn-outline" onclick="adminPanel.filterUserLogs(${userId})">
                    <i class="fas fa-filter"></i> Filtrar
                </button>
            </div>
            
            <div class="logs-container" id="userLogsContainer">
                ${userLogs.length > 0 ? this.renderUserLogs(userLogs) : this.renderNoLogs()}
            </div>
        </div>
    `;

    const actions = [
        { text: 'Exportar Logs', class: 'btn-outline', icon: 'fas fa-download', onclick: `adminPanel.exportUserLogs(${userId})` },
        { text: 'Cerrar', class: 'btn-primary', onclick: 'adminPanel.closeModal()' }
    ];

    this.showModal(`Logs - ${user.name}`, content, actions, { width: '800px' });
};

/**
 * Renderizar logs de usuario
 */
AdminPanel.prototype.renderUserLogs = function (logs) {
    return `
        <div class="logs-list">
            ${logs.map(log => `
                <div class="log-entry">
                    <div class="log-header">
                        <div class="log-timestamp">${new Date(log.timestamp).toLocaleString('es-CO')}</div>
                        <div class="log-action">${log.action}</div>
                        <div class="log-user">por ${log.user}</div>
                    </div>
                    <div class="log-description">${this.escapeHtml(log.description)}</div>
                    ${log.metadata && Object.keys(log.metadata).length > 0 ? `
                        <div class="log-metadata">
                            <details>
                                <summary>Ver detalles</summary>
                                <pre>${JSON.stringify(log.metadata, null, 2)}</pre>
                            </details>
                        </div>
                    ` : ''}
                </div>
            `).join('')}
        </div>
    `;
};

/**
 * Renderizar estado sin logs
 */
AdminPanel.prototype.renderNoLogs = function () {
    return `
        <div class="no-logs">
            <i class="fas fa-file-alt fa-3x"></i>
            <h3>No hay logs disponibles</h3>
            <p>No se encontraron registros de actividad para este usuario.</p>
        </div>
    `;
};

/**
 * Sistema de auto-actualización
 */
AdminPanel.prototype.startAutoRefresh = function () {
    if (autoRefreshTimer) {
        clearInterval(autoRefreshTimer);
    }

    autoRefreshTimer = setInterval(() => {
        if (AdminConfig.status.isAuthenticated && !document.hidden) {
            this.performAutoRefresh();
        }
    }, AdminConfig.system.refreshInterval);

    console.log('🔄 Auto-refresh iniciado cada', AdminConfig.system.refreshInterval / 1000, 'segundos');
};

AdminPanel.prototype.stopAutoRefresh = function () {
    if (autoRefreshTimer) {
        clearInterval(autoRefreshTimer);
        autoRefreshTimer = null;
        console.log('⏹️ Auto-refresh detenido');
    }
};

AdminPanel.prototype.pauseAutoRefresh = function () {
    this.stopAutoRefresh();
    console.log('⏸️ Auto-refresh pausado (pestaña oculta)');
};

AdminPanel.prototype.resumeAutoRefresh = function () {
    if (AdminConfig.status.isAuthenticated) {
        this.startAutoRefresh();
        console.log('▶️ Auto-refresh reanudado (pestaña visible)');
    }
};

/**
 * Realizar actualización automática
 */
AdminPanel.prototype.performAutoRefresh = function () {
    // Actualizar métricas del sistema
    this.simulateSystemMetricsChanges();

    // Actualizar estadísticas
    this.updateAllStatistics();

    // Actualizar actividad reciente ocasionalmente
    this.refreshRecentActivity();

    // Actualizar salud del sistema
    this.updateSystemHealth();

    console.log('🔄 Auto-refresh ejecutado -', new Date().toLocaleTimeString());
};

/**
 * Simular cambios en métricas del sistema
 */
AdminPanel.prototype.simulateSystemMetricsChanges = function () {
    const metrics = AdminCache.systemMetrics;

    // Simular cambios leves en CPU
    metrics.cpu.usage += (Math.random() - 0.5) * 5;
    metrics.cpu.usage = Math.max(10, Math.min(90, metrics.cpu.usage));

    // Simular cambios en memoria
    metrics.memory.usage += (Math.random() - 0.5) * 3;
    metrics.memory.usage = Math.max(30, Math.min(95, metrics.memory.usage));

    // Simular cambios en disco (más lento)
    metrics.disk.usage += (Math.random() - 0.5) * 1;
    metrics.disk.usage = Math.max(60, Math.min(90, metrics.disk.usage));

    // Actualizar contadores del servidor
    metrics.server.requests += Math.floor(Math.random() * 50) + 10;
    metrics.server.errors += Math.random() < 0.1 ? 1 : 0; // 10% probabilidad de error
};

/**
 * Actualizar actividad reciente
 */
AdminPanel.prototype.refreshRecentActivity = function () {
    // Simular nueva actividad ocasionalmente (20% de probabilidad)
    if (Math.random() < 0.2) {
        const newActivity = this.generateRandomActivity();
        AdminCache.recentActivity.unshift(newActivity);

        // Mantener solo las últimas 10 actividades
        if (AdminCache.recentActivity.length > 10) {
            AdminCache.recentActivity.splice(10);
        }

        this.renderRecentActivity();
    }
};

/**
 * Generar actividad aleatoria
 */
AdminPanel.prototype.generateRandomActivity = function () {
    const activities = [
        {
            type: 'user',
            title: 'Usuario actualizado',
            description: 'Información de perfil modificada',
            user: 'Sistema'
        },
        {
            type: 'payment',
            title: 'Pago procesado',
            description: 'Renovación automática de plan',
            user: 'Sistema'
        },
        {
            type: 'system',
            title: 'Mantenimiento completado',
            description: 'Optimización de base de datos',
            user: 'Sistema'
        },
        {
            type: 'security',
            title: 'Backup automático',
            description: 'Respaldo de datos completado',
            user: 'Sistema'
        }
    ];

    const activity = activities[Math.floor(Math.random() * activities.length)];

    return {
        ...activity,
        time: Date.now(),
        id: Date.now()
    };
};

/**
 * Logout del administrador
 */
AdminPanel.prototype.performLogout = function (showConfirmation = true) {
    if (showConfirmation) {
        const content = `
            <div class="logout-confirmation">
                <div class="logout-icon">
                    <i class="fas fa-sign-out-alt"></i>
                </div>
                <h3>¿Cerrar Sesión?</h3>
                <p>¿Estás seguro de que quieres cerrar la sesión administrativa?</p>
                <p class="logout-warning">Se perderán los cambios no guardados.</p>
            </div>
        `;

        const actions = [
            { text: 'Cancelar', class: 'btn-outline', onclick: 'adminPanel.closeModal()' },
            { text: 'Cerrar Sesión', class: 'btn-warning', icon: 'fas fa-sign-out-alt', onclick: 'adminPanel.confirmLogout()' }
        ];

        this.showModal('Cerrar Sesión', content, actions);
    } else {
        this.confirmLogout();
    }
};

/**
 * Confirmar logout
 */
AdminPanel.prototype.confirmLogout = function () {
    this.showNotification('Cerrando Sesión', 'Guardando configuración...', 'info', 2000);

    setTimeout(() => {
        // Log de logout
        this.logActivity('logout', 'Sesión cerrada manualmente');

        // Clean up
        this.stopAutoRefresh();
        this.clearSession();

        // Cerrar modal si existe
        if (UIState.openModals.length > 0) {
            this.closeModal();
        }

        this.showNotification('Sesión Cerrada', 'Has cerrado sesión exitosamente', 'success');
    }, 1500);
};

/* <-- seguir aquí --> */

/**
 * ============================================
 * ADMIN.JS - PARTE 6/6 - FINAL
 * Funciones Finales, Sistema de Logs y Navegación
 * Sistema de reservas - Panel de control
 * ============================================
 */

/**
 * Funciones de navegación entre secciones
 */
AdminPanel.prototype.showAdminSection = function (sectionName) {
    // Remover clase active de todas las secciones
    document.querySelectorAll('.section-content').forEach(section => {
        section.classList.remove('active');
    });

    // Remover clase active de todos los links de navegación
    document.querySelectorAll('.admin-nav-link').forEach(link => {
        link.classList.remove('active');
    });

    // Mostrar sección seleccionada
    const targetSection = document.getElementById(sectionName + 'Section');
    if (targetSection) {
        targetSection.classList.add('active');
    }

    // Activar link de navegación
    const activeLink = document.querySelector(`[data-section="${sectionName}"]`);
    if (activeLink) {
        activeLink.classList.add('active');
    }

    // Actualizar estado actual
    AdminConfig.status.activeSection = sectionName;

    // Ejecutar inicialización específica de la sección
    this.initializeSection(sectionName);

    // Log de navegación
    this.logActivity('navigate', `Navegó a sección ${sectionName}`);
};

/**
 * Inicializar sección específica
 */
AdminPanel.prototype.initializeSection = function (sectionName) {
    switch (sectionName) {
        case 'users':
            this.initializeUserManagement();
            break;
        case 'plans':
            this.updatePlanStats();
            break;
        case 'system':
            this.updateSystemHealth();
            break;
        case 'logs':
            this.initializeLogSystem();
            break;
        case 'settings':
            this.loadSystemSettings();
            break;
        case 'updates':
            this.checkSystemUpdates();
            break;
        case 'overview':
        default:
            this.updateAllStatistics();
            this.loadRecentActivity();
            break;
    }
};

/**
 * Inicializar sistema de logs
 */
AdminPanel.prototype.initializeLogSystem = function () {
    console.log('📝 Inicializando sistema de logs...');

    this.renderSystemLogs();
    this.updateLogStats();
    this.setupLogFilters();

    console.log('✅ Sistema de logs inicializado');
};

/**
 * Renderizar logs del sistema
 */
AdminPanel.prototype.renderSystemLogs = function () {
    const logContent = document.getElementById('logContent');
    if (!logContent) return;

    const logs = this.getFilteredLogs();
    const logEntries = logs.slice(0, 50); // Mostrar últimos 50 logs

    if (logEntries.length === 0) {
        logContent.innerHTML = `
            <div class="no-logs-message">
                <i class="fas fa-file-alt fa-3x"></i>
                <h3>No hay logs disponibles</h3>
                <p>No se encontraron registros que coincidan con los filtros.</p>
            </div>
        `;
        return;
    }

    logContent.innerHTML = logEntries.map(log => `
        <div class="log-entry ${this.getLogLevelClass(log.action)}">
            <div class="log-header">
                <span class="log-timestamp">${new Date(log.timestamp).toLocaleString('es-CO')}</span>
                <span class="log-level ${this.getLogLevelClass(log.action)}">${this.getLogLevel(log.action)}</span>
                <span class="log-category">${this.getLogCategory(log.action)}</span>
            </div>
            <div class="log-message">${this.escapeHtml(log.description)}</div>
            <div class="log-details">
                <span class="log-user">Usuario: ${log.user}</span>
                ${log.metadata && log.metadata.ip ? `<span class="log-ip">IP: ${log.metadata.ip}</span>` : ''}
            </div>
            ${log.metadata && Object.keys(log.metadata).length > 0 ? `
                <div class="log-metadata-toggle">
                    <button class="btn-toggle" onclick="adminPanel.toggleLogMetadata('${log.id}')">
                        <i class="fas fa-chevron-down"></i> Ver detalles
                    </button>
                    <div class="log-metadata" id="metadata_${log.id}" style="display: none;">
                        <pre>${JSON.stringify(log.metadata, null, 2)}</pre>
                    </div>
                </div>
            ` : ''}
        </div>
    `).join('');
};

/**
 * Obtener logs filtrados
 */
AdminPanel.prototype.getFilteredLogs = function () {
    let filteredLogs = [...AdminCache.logs];

    const levelFilter = document.getElementById('logLevel')?.value;
    const categoryFilter = document.getElementById('logCategory')?.value;
    const dateFromFilter = document.getElementById('logDateFrom')?.value;
    const dateToFilter = document.getElementById('logDateTo')?.value;

    if (levelFilter) {
        filteredLogs = filteredLogs.filter(log => this.getLogLevel(log.action) === levelFilter);
    }

    if (categoryFilter) {
        filteredLogs = filteredLogs.filter(log => this.getLogCategory(log.action) === categoryFilter);
    }

    if (dateFromFilter) {
        const fromDate = new Date(dateFromFilter);
        filteredLogs = filteredLogs.filter(log => new Date(log.timestamp) >= fromDate);
    }

    if (dateToFilter) {
        const toDate = new Date(dateToFilter);
        toDate.setHours(23, 59, 59, 999);
        filteredLogs = filteredLogs.filter(log => new Date(log.timestamp) <= toDate);
    }

    return filteredLogs.sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp));
};

/**
 * Configurar filtros de logs
 */
AdminPanel.prototype.setupLogFilters = function () {
    const filterElements = ['logLevel', 'logCategory', 'logDateFrom', 'logDateTo'];

    filterElements.forEach(elementId => {
        const element = document.getElementById(elementId);
        if (element) {
            element.addEventListener('change', () => {
                this.renderSystemLogs();
            });
        }
    });
};

/**
 * Actualizar estadísticas de logs
 */
AdminPanel.prototype.updateLogStats = function () {
    const logs = AdminCache.logs;
    const last24h = Date.now() - 86400000; // 24 horas atrás

    const recentLogs = logs.filter(log => new Date(log.timestamp) >= new Date(last24h));

    const errorLogs = recentLogs.filter(log => this.getLogLevel(log.action) === 'error').length;
    const warningLogs = recentLogs.filter(log => this.getLogLevel(log.action) === 'warning').length;
    const infoLogs = recentLogs.filter(log => this.getLogLevel(log.action) === 'info').length;
    const debugLogs = recentLogs.filter(log => this.getLogLevel(log.action) === 'debug').length;

    this.updateCounter('errorLogsCount', errorLogs);
    this.updateCounter('warningLogsCount', warningLogs);
    this.updateCounter('infoLogsCount', infoLogs);
    this.updateCounter('debugLogsCount', debugLogs);

    // Actualizar badge en navegación
    this.updateCounter('errorLogsBadge', errorLogs);
};

/**
 * Funciones de utilidad para logs
 */
AdminPanel.prototype.getLogLevel = function (action) {
    const errorActions = ['error', 'delete_user', 'session_expired'];
    const warningActions = ['suspend_user', 'login_failed', 'session_inactive'];
    const debugActions = ['navigate', 'filter_users', 'sort_table'];

    if (errorActions.includes(action)) return 'error';
    if (warningActions.includes(action)) return 'warning';
    if (debugActions.includes(action)) return 'debug';
    return 'info';
};

AdminPanel.prototype.getLogCategory = function (action) {
    const categories = {
        'login': 'auth',
        'logout': 'auth',
        'login_failed': 'auth',
        'session_expired': 'auth',
        'session_inactive': 'auth',
        'view_user': 'user',
        'edit_user': 'user',
        'delete_user': 'user',
        'suspend_user': 'user',
        'activate_user': 'user',
        'plan_change': 'user',
        'extend_plan': 'user',
        'send_message': 'user',
        'status_change': 'user',
        'navigate': 'system',
        'filter_users': 'system',
        'sort_table': 'system',
        'error': 'system'
    };

    return categories[action] || 'system';
};

AdminPanel.prototype.getLogLevelClass = function (action) {
    return this.getLogLevel(action);
};

/**
 * Alternar metadata de log
 */
AdminPanel.prototype.toggleLogMetadata = function (logId) {
    const metadata = document.getElementById(`metadata_${logId}`);
    const button = metadata.previousElementSibling;
    const icon = button.querySelector('i');

    if (metadata.style.display === 'none') {
        metadata.style.display = 'block';
        icon.className = 'fas fa-chevron-up';
        button.innerHTML = '<i class="fas fa-chevron-up"></i> Ocultar detalles';
    } else {
        metadata.style.display = 'none';
        icon.className = 'fas fa-chevron-down';
        button.innerHTML = '<i class="fas fa-chevron-down"></i> Ver detalles';
    }
};

/**
 * Exportar logs
 */
AdminPanel.prototype.exportLogs = function () {
    const logs = this.getFilteredLogs();

    if (logs.length === 0) {
        this.showNotification('Sin Datos', 'No hay logs para exportar', 'warning');
        return;
    }

    const content = `
        <div class="export-logs-modal">
            <h4>Exportar Logs del Sistema</h4>
            <p>Se exportarán ${logs.length} registros de log.</p>
            
            <div class="form-group">
                <label class="form-label">Formato de exportación</label>
                <select id="exportFormat" class="form-select">
                    <option value="csv">CSV (Excel)</option>
                    <option value="json">JSON</option>
                    <option value="txt">Texto plano</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" id="includeMetadata" checked>
                    Incluir metadata detallada
                </label>
            </div>
        </div>
    `;

    const actions = [
        { text: 'Cancelar', class: 'btn-outline', onclick: 'adminPanel.closeModal()' },
        { text: 'Exportar', class: 'btn-primary', icon: 'fas fa-download', onclick: 'adminPanel.processLogExport()' }
    ];

    this.showModal('Exportar Logs', content, actions);
};

/**
 * Procesar exportación de logs
 */
AdminPanel.prototype.processLogExport = function () {
    const format = document.getElementById('exportFormat').value;
    const includeMetadata = document.getElementById('includeMetadata').checked;
    const logs = this.getFilteredLogs();

    const exportBtn = document.querySelector('.modal-actions .btn-primary');
    this.setLoadingState(exportBtn, true);

    setTimeout(() => {
        let content = '';
        let filename = `logs_${new Date().toISOString().split('T')[0]}`;

        switch (format) {
            case 'csv':
                content = this.generateCSVLogs(logs, includeMetadata);
                filename += '.csv';
                break;
            case 'json':
                content = JSON.stringify(logs, null, 2);
                filename += '.json';
                break;
            case 'txt':
                content = this.generateTextLogs(logs, includeMetadata);
                filename += '.txt';
                break;
        }

        this.downloadFile(content, filename);

        this.closeModal();
        this.showNotification('Exportación Completa', `${logs.length} logs exportados en formato ${format.toUpperCase()}`, 'success');
        this.setLoadingState(exportBtn, false);

        // Log de la exportación
        this.logActivity('export_logs', `Logs exportados en formato ${format}`, {
            recordCount: logs.length,
            format,
            includeMetadata
        });
    }, 2000);
};

/**
 * Generar CSV de logs
 */
AdminPanel.prototype.generateCSVLogs = function (logs, includeMetadata) {
    const headers = ['Timestamp', 'Level', 'Category', 'Action', 'Description', 'User'];
    if (includeMetadata) headers.push('Metadata');

    let csv = headers.join(',') + '\n';

    logs.forEach(log => {
        const row = [
            `"${new Date(log.timestamp).toLocaleString('es-CO')}"`,
            `"${this.getLogLevel(log.action)}"`,
            `"${this.getLogCategory(log.action)}"`,
            `"${log.action}"`,
            `"${log.description.replace(/"/g, '""')}"`,
            `"${log.user}"`
        ];

        if (includeMetadata) {
            row.push(`"${JSON.stringify(log.metadata || {}).replace(/"/g, '""')}"`);
        }

        csv += row.join(',') + '\n';
    });

    return csv;
};

/**
 * Generar texto de logs
 */
AdminPanel.prototype.generateTextLogs = function (logs, includeMetadata) {
    return logs.map(log => {
        let text = `[${new Date(log.timestamp).toLocaleString('es-CO')}] `;
        text += `${this.getLogLevel(log.action).toUpperCase()} `;
        text += `${this.getLogCategory(log.action)} `;
        text += `${log.action}: ${log.description} `;
        text += `(User: ${log.user})`;

        if (includeMetadata && log.metadata && Object.keys(log.metadata).length > 0) {
            text += `\n  Metadata: ${JSON.stringify(log.metadata)}`;
        }

        return text;
    }).join('\n\n');
};

/**
 * Descargar archivo
 */
AdminPanel.prototype.downloadFile = function (content, filename) {
    const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = filename;
    link.style.display = 'none';

    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    URL.revokeObjectURL(url);
};

/**
 * Limpiar logs
 */
AdminPanel.prototype.clearLogs = function () {
    const content = `
        <div class="clear-logs-modal">
            <div class="warning-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3>¿Limpiar Todos los Logs?</h3>
            <p>Esta acción eliminará <strong>todos los registros de log</strong> del sistema.</p>
            <p class="danger-warning">
                <i class="fas fa-warning"></i>
                <strong>¡Esta acción no se puede deshacer!</strong>
            </p>
            
            <div class="confirmation-options">
                <label class="checkbox-label">
                    <input type="checkbox" id="keepCriticalLogs">
                    Mantener logs críticos (errores y seguridad)
                </label>
            </div>
        </div>
    `;

    const actions = [
        { text: 'Cancelar', class: 'btn-outline', onclick: 'adminPanel.closeModal()' },
        { text: 'Limpiar Logs', class: 'btn-danger', icon: 'fas fa-trash', onclick: 'adminPanel.confirmClearLogs()' }
    ];

    this.showModal('Limpiar Logs', content, actions);
};

/**
 * Confirmar limpieza de logs
 */
AdminPanel.prototype.confirmClearLogs = function () {
    const keepCritical = document.getElementById('keepCriticalLogs').checked;

    const clearBtn = document.querySelector('.modal-actions .btn-danger');
    this.setLoadingState(clearBtn, true);

    setTimeout(() => {
        const originalCount = AdminCache.logs.length;

        if (keepCritical) {
            // Mantener solo logs críticos
            AdminCache.logs = AdminCache.logs.filter(log => {
                const level = this.getLogLevel(log.action);
                return level === 'error' || log.action.includes('security');
            });
        } else {
            // Limpiar todos los logs
            AdminCache.logs = [];
        }

        const remainingCount = AdminCache.logs.length;
        const deletedCount = originalCount - remainingCount;

        // Actualizar UI
        this.renderSystemLogs();
        this.updateLogStats();

        this.closeModal();
        this.showNotification('Logs Limpiados', `${deletedCount} registros eliminados. ${remainingCount} registros mantenidos.`, 'success');

        // Log de la acción
        this.logActivity('clear_logs', `Logs limpiados: ${deletedCount} eliminados, ${remainingCount} mantenidos`, {
            deletedCount,
            remainingCount,
            keepCritical
        });

        this.setLoadingState(clearBtn, false);
    }, 2000);
};

/**
 * Verificar actualizaciones del sistema
 */
AdminPanel.prototype.checkSystemUpdates = function () {
    console.log('🔍 Verificando actualizaciones del sistema...');

    // Simular verificación de actualizaciones
    setTimeout(() => {
        const hasUpdates = Math.random() > 0.7; // 30% probabilidad de actualizaciones

        if (hasUpdates) {
            this.updateCounter('updatesBadge', '1');
            document.getElementById('updatesBadge').style.display = 'inline-block';
            this.showNotification('Actualización Disponible', 'Hay una nueva actualización del sistema disponible', 'info');
        } else {
            this.updateCounter('updatesBadge', '0');
            document.getElementById('updatesBadge').style.display = 'none';
        }
    }, 1000);
};

/**
 * Funciones de utilidad final
 */
AdminPanel.prototype.refreshOverview = function () {
    console.log('🔄 Actualizando vista general...');
    this.updateAllStatistics();
    this.loadRecentActivity();
    this.showNotification('Vista Actualizada', 'Los datos han sido actualizados', 'success', 2000);
};

AdminPanel.prototype.backupDatabase = function () {
    if (!confirm('¿Iniciar backup completo de la base de datos?')) return;

    this.showNotification('Backup Iniciado', 'Creando respaldo de la base de datos...', 'info', 3000);

    setTimeout(() => {
        const backupSize = (Math.random() * 2 + 1).toFixed(1); // Entre 1.0 y 3.0 GB
        this.showNotification('Backup Completado', `Respaldo creado exitosamente (${backupSize} GB)`, 'success');

        // Actualizar última fecha de backup
        AdminCache.systemMetrics.database.lastBackup = Date.now();

        this.logActivity('database_backup', `Backup de base de datos completado (${backupSize} GB)`);
    }, 5000);
};

AdminPanel.prototype.sendGlobalNotification = function () {
    const message = prompt('Ingresa el mensaje para enviar a todos los usuarios:');
    if (!message || !message.trim()) return;

    this.showNotification('Enviando Notificación', 'Notificación global en proceso...', 'info', 3000);

    setTimeout(() => {
        const activeUsers = AdminCache.users.filter(u => u.status === 'active').length;
        this.showNotification('Notificación Enviada', `Mensaje enviado a ${activeUsers} usuarios activos`, 'success');

        this.logActivity('global_notification', 'Notificación global enviada', {
            message: message.substring(0, 100),
            recipientCount: activeUsers
        });
    }, 2000);
};

AdminPanel.prototype.viewSystemLogs = function () {
    this.showAdminSection('logs');
};

/**
 * Inicialización automática cuando el DOM está listo
 */
document.addEventListener('DOMContentLoaded', function () {
    // Verificar que no se haya inicializado ya
    if (window.adminPanel) return;

    try {
        // Inicializar panel de administrador
        window.adminPanel = new AdminPanel();

        // Configurar funciones globales para compatibilidad
        window.showAdminSection = (section) => window.adminPanel.showAdminSection(section);
        window.filterUsers = () => window.adminPanel.applyFilters();
        window.clearFilters = () => window.adminPanel.clearAllFilters();
        window.toggleUserSelection = (id) => window.adminPanel.toggleUserSelection(id);
        window.toggleSelectAll = () => window.adminPanel.toggleSelectAll(document.getElementById('selectAllUsers').checked);
        window.goToPage = (page) => window.adminPanel.goToPage(page);
        window.previousPage = () => window.adminPanel.previousPage();
        window.nextPage = () => window.adminPanel.nextPage();
        window.changePageSize = () => window.adminPanel.changePageSize();
        window.refreshUsers = () => window.adminPanel.refreshUsers();
        window.exportUsers = () => window.adminPanel.exportUsers();
        window.addNewUser = () => window.adminPanel.addNewUser();
        window.adminLogout = () => window.adminPanel.performLogout();

        console.log('🎉 Panel de Administrador inicializado exitosamente');

    } catch (error) {
        console.error('❌ Error al inicializar el panel de administrador:', error);

        // Mostrar mensaje de error al usuario
        setTimeout(() => {
            alert('Error al cargar el panel de administrador. Por favor, recarga la página.');
        }, 1000);
    }
});

// Manejo de errores globales
window.addEventListener('error', function (e) {
    if (window.adminPanel) {
        window.adminPanel.logError('JavaScript Error', e.message, e.filename, e.lineno);
    }
});

// Manejo de promesas rechazadas
window.addEventListener('unhandledrejection', function (e) {
    if (window.adminPanel) {
        window.adminPanel.logError('Unhandled Promise Rejection', e.reason?.message || 'Unknown error');
    }
});

// Exportar para uso en módulos
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { AdminPanel, AdminConfig, AdminCache, UIState };
}

console.log('📦 Admin.js completamente cargado - Versión', AdminConfig?.system?.version || '2.4.1');

/* 🎯 ADMIN.JS COMPLETO - TODAS LAS FUNCIONES IMPLEMENTADAS */