/* ============================================
   DASHBOARD.JS - PARTE 1/8
   Inicialización y Configuración Base
   Sistema de reservas - Panel de control
============================================ */

'use strict';

/* ============================================
   CONFIGURACIÓN GLOBAL
============================================ */
const DASHBOARD_CONFIG = {
    apiUrl: '../api/',
    theme: 'light',
    language: 'es',
    timezone: 'America/Bogota',
    currency: 'COP',
    dateFormat: 'DD/MM/YYYY',
    timeFormat: 'HH:mm',
    autoRefresh: true,
    refreshInterval: 30000, // 30 segundos
    notifications: {
        position: 'top-right',
        duration: 5000
    },
    charts: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'top'
            }
        }
    }
};

/* ============================================
   VARIABLES GLOBALES
============================================ */
let currentSection = 'dashboard';
let sidebarOpen = true;
let refreshIntervals = {};
let notificationCount = 0;
let currentUser = null;
let businessData = null;
let dashboardData = {
    stats: {},
    charts: {},
    schedule: [],
    bookings: [],
    notifications: []
};

/* ============================================
   UTILIDADES PRINCIPALES
============================================ */

/**
 * Función de logging con timestamp
 */
function log(message, type = 'info') {
    const timestamp = new Date().toLocaleString('es-CO');
    const prefix = `[${timestamp}] Dashboard:`;

    switch (type) {
        case 'error':
            console.error(prefix, message);
            break;
        case 'warn':
            console.warn(prefix, message);
            break;
        case 'success':
            console.log(`%c${prefix} ${message}`, 'color: #10b981; font-weight: bold;');
            break;
        default:
            console.log(prefix, message);
    }
}

/**
 * Manejo de errores global
 */
function handleError(error, context = 'Dashboard') {
    log(`Error en ${context}: ${error.message}`, 'error');

    // Mostrar notificación de error al usuario
    showNotification(`Error: ${error.message}`, 'error');

    // Enviar error a servicio de logging (opcional)
    if (typeof sendErrorToLogging === 'function') {
        sendErrorToLogging(error, context);
    }
}

/**
 * Función para mostrar notificaciones
 */
function showNotification(message, type = 'info', duration = 5000) {
    // Crear elemento de notificación
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <div class="notification-content">
            <i class="fas fa-${getNotificationIcon(type)}"></i>
            <span>${message}</span>
        </div>
        <button class="notification-close" onclick="closeNotification(this)">
            <i class="fas fa-times"></i>
        </button>
    `;

    // Añadir al DOM
    let container = document.querySelector('.notifications-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'notifications-container';
        document.body.appendChild(container);
    }

    container.appendChild(notification);

    // Auto-cerrar después del tiempo especificado
    setTimeout(() => {
        if (notification.parentNode) {
            closeNotification(notification.querySelector('.notification-close'));
        }
    }, duration);
}

/**
 * Obtener icono según tipo de notificación
 */
function getNotificationIcon(type) {
    const icons = {
        'success': 'check-circle',
        'error': 'exclamation-circle',
        'warning': 'exclamation-triangle',
        'info': 'info-circle'
    };
    return icons[type] || 'info-circle';
}

/**
 * Cerrar notificación
 */
function closeNotification(button) {
    const notification = button.closest('.notification');
    if (notification) {
        notification.style.opacity = '0';
        notification.style.transform = 'translateX(100%)';
        setTimeout(() => {
            notification.remove();
        }, 300);
    }
}

/**
 * Formatear números como moneda colombiana
 */
function formatCurrency(amount) {
    return new Intl.NumberFormat('es-CO', {
        style: 'currency',
        currency: 'COP',
        minimumFractionDigits: 0
    }).format(amount);
}

/**
 * Formatear fechas
 */
function formatDate(date, format = DASHBOARD_CONFIG.dateFormat) {
    if (!date) return '';

    const d = new Date(date);
    const day = String(d.getDate()).padStart(2, '0');
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const year = d.getFullYear();

    switch (format) {
        case 'DD/MM/YYYY':
            return `${day}/${month}/${year}`;
        case 'YYYY-MM-DD':
            return `${year}-${month}-${day}`;
        default:
            return d.toLocaleDateString('es-CO');
    }
}

/**
 * Formatear tiempo
 */
function formatTime(time) {
    if (!time) return '';

    const t = new Date(`2000-01-01T${time}`);
    return t.toLocaleTimeString('es-CO', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false
    });
}

/**
 * Debounce function para optimizar búsquedas
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Función para hacer peticiones API
 */
async function apiRequest(endpoint, options = {}) {
    try {
        const defaultOptions = {
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
        };

        const response = await fetch(DASHBOARD_CONFIG.apiUrl + endpoint, {
            ...defaultOptions,
            ...options
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const data = await response.json();
        return data;
    } catch (error) {
        handleError(error, `API Request: ${endpoint}`);
        throw error;
    }
}

/* ============================================
   GESTIÓN DE TEMAS
============================================ */

/**
 * Inicializar tema desde localStorage
 */
function initializeTheme() {
    try {
        const savedTheme = localStorage.getItem('dashboard-theme') || 'light';
        setTheme(savedTheme);
        log(`Tema inicializado: ${savedTheme}`, 'success');
    } catch (error) {
        handleError(error, 'Theme Initialization');
        setTheme('light'); // Fallback
    }
}

/**
 * Establecer tema
 */
function setTheme(theme) {
    try {
        document.body.setAttribute('data-theme', theme);
        document.body.className = `theme-${theme}`;

        // Actualizar botones de tema
        document.querySelectorAll('.theme-option').forEach(option => {
            option.classList.remove('active');
            if (option.getAttribute('data-theme') === theme) {
                option.classList.add('active');
            }
        });

        // Guardar en localStorage
        localStorage.setItem('dashboard-theme', theme);
        DASHBOARD_CONFIG.theme = theme;

        log(`Tema cambiado a: ${theme}`, 'success');
    } catch (error) {
        handleError(error, 'Set Theme');
    }
}

/**
 * Event listeners para selector de tema
 */
function initializeThemeSelector() {
    try {
        const themeOptions = document.querySelectorAll('.theme-option');

        themeOptions.forEach(option => {
            option.addEventListener('click', (e) => {
                e.preventDefault();
                const theme = option.getAttribute('data-theme');
                setTheme(theme);
            });
        });

        log('Theme selector inicializado', 'success');
    } catch (error) {
        handleError(error, 'Theme Selector Initialization');
    }
}

/* ============================================
   GESTIÓN DEL SIDEBAR
============================================ */

/**
 * Inicializar sidebar
 */
function initializeSidebar() {
    try {
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.querySelector('.main-content');

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', toggleSidebar);
        }

        // Auto-ocultar sidebar en mobile
        if (window.innerWidth <= 1023) {
            sidebarOpen = false;
            sidebar?.classList.remove('open');
        }

        // Event listeners para items del menú
        const menuItems = document.querySelectorAll('.menu-item');
        menuItems.forEach(item => {
            item.addEventListener('click', (e) => {
                e.preventDefault();
                const section = item.getAttribute('data-section');
                if (section) {
                    showSection(section);
                }
            });
        });

        log('Sidebar inicializado', 'success');
    } catch (error) {
        handleError(error, 'Sidebar Initialization');
    }
}

/**
 * Alternar sidebar
 */
function toggleSidebar() {
    try {
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');

        sidebarOpen = !sidebarOpen;

        if (sidebarOpen) {
            sidebar.classList.add('open');
            sidebarToggle.setAttribute('aria-expanded', 'true');
        } else {
            sidebar.classList.remove('open');
            sidebarToggle.setAttribute('aria-expanded', 'false');
        }

        log(`Sidebar ${sidebarOpen ? 'abierto' : 'cerrado'}`, 'info');
    } catch (error) {
        handleError(error, 'Toggle Sidebar');
    }
}

/* ============================================
   NAVEGACIÓN ENTRE SECCIONES
============================================ */

/**
 * Mostrar sección específica
 */
function showSection(sectionName) {
    try {
        // Ocultar todas las secciones
        const sections = document.querySelectorAll('.content-section');
        sections.forEach(section => {
            section.classList.remove('active');
        });

        // Mostrar sección seleccionada
        const targetSection = document.getElementById(`${sectionName}Section`);
        if (targetSection) {
            targetSection.classList.add('active');
        }

        // Actualizar navegación
        updateNavigation(sectionName);

        // Actualizar breadcrumb
        updateBreadcrumb(sectionName);

        // Cargar datos específicos de la sección
        loadSectionData(sectionName);

        currentSection = sectionName;
        log(`Sección cambiada a: ${sectionName}`, 'info');

    } catch (error) {
        handleError(error, 'Show Section');
    }
}

/**
 * Actualizar estado de navegación
 */
function updateNavigation(activeSection) {
    try {
        const menuItems = document.querySelectorAll('.menu-item');

        menuItems.forEach(item => {
            item.classList.remove('active');
            const link = item.querySelector('.menu-link');
            link?.setAttribute('aria-current', 'false');

            if (item.getAttribute('data-section') === activeSection) {
                item.classList.add('active');
                link?.setAttribute('aria-current', 'page');
            }
        });
    } catch (error) {
        handleError(error, 'Update Navigation');
    }
}

/**
 * Actualizar breadcrumb
 */
function updateBreadcrumb(sectionName) {
    try {
        const breadcrumbCurrent = document.getElementById('currentBreadcrumb');
        const sectionNames = {
            'dashboard': 'Dashboard',
            'calendar': 'Calendario',
            'bookings': 'Reservas',
            'services': 'Servicios',
            'customers': 'Clientes',
            'reviews': 'Reseñas',
            'statistics': 'Estadísticas',
            'settings': 'Configuración'
        };

        if (breadcrumbCurrent) {
            breadcrumbCurrent.textContent = sectionNames[sectionName] || sectionName;
        }
    } catch (error) {
        handleError(error, 'Update Breadcrumb');
    }
}

/**
 * Cargar datos específicos de cada sección
 */
function loadSectionData(sectionName) {
    try {
        switch (sectionName) {
            case 'dashboard':
                loadDashboardData();
                break;
            case 'calendar':
                if (typeof CalendarManager !== 'undefined') {
                    CalendarManager.refresh();
                }
                break;
            case 'bookings':
                loadBookingsData();
                break;
            case 'services':
                loadServicesData();
                break;
            case 'customers':
                loadCustomersData();
                break;
            case 'reviews':
                loadReviewsData();
                break;
            case 'statistics':
                loadStatisticsData();
                break;
            case 'settings':
                loadSettingsData();
                break;
        }
    } catch (error) {
        handleError(error, `Load Section Data: ${sectionName}`);
    }
}

/* ============================================
   VERIFICACIÓN DE CAMBIOS NO GUARDADOS
============================================ */

/**
 * Verificar si hay cambios no guardados
 */
function hasUnsavedChanges() {
    // Verificar formularios modificados
    const forms = document.querySelectorAll('form');
    for (let form of forms) {
        if (form.classList.contains('modified')) {
            return true;
        }
    }
    return false;
}

/**
 * Marcar formulario como modificado
 */
function markFormAsModified(form) {
    form.classList.add('modified');
}

/**
 * Marcar formulario como guardado
 */
function markFormAsSaved(form) {
    form.classList.remove('modified');
}

/* ============================================
   INICIALIZACIÓN PRINCIPAL
============================================ */

/**
 * Función principal de inicialización
 */
function initializeDashboard() {
    try {
        log('Iniciando Dashboard del Sistema de Reservas...', 'info');

        // Inicializar componentes base
        initializeTheme();
        initializeThemeSelector();
        initializeSidebar();

        // Cargar datos iniciales
        loadInitialData();

        // Configurar auto-refresh si está habilitado
        if (DASHBOARD_CONFIG.autoRefresh) {
            setupAutoRefresh();
        }

        // Configurar event listeners globales
        setupGlobalEventListeners();

        log('Dashboard inicializado correctamente', 'success');

    } catch (error) {
        handleError(error, 'Dashboard Initialization');
    }
}

/**
 * Cargar datos iniciales
 */
async function loadInitialData() {
    try {
        log('Cargando datos iniciales...', 'info');

        // Cargar datos del usuario y negocio
        await Promise.all([
            loadUserData(),
            loadBusinessData(),
            loadDashboardData()
        ]);

        log('Datos iniciales cargados', 'success');
    } catch (error) {
        handleError(error, 'Load Initial Data');
    }
}

/**
 * Configurar auto-refresh
 */
function setupAutoRefresh() {
    try {
        refreshIntervals.main = setInterval(() => {
            if (currentSection === 'dashboard') {
                refreshDashboard();
            }
        }, DASHBOARD_CONFIG.refreshInterval);

        log('Auto-refresh configurado', 'info');
    } catch (error) {
        handleError(error, 'Setup Auto Refresh');
    }
}

/**
 * Configurar event listeners globales
 */
function setupGlobalEventListeners() {
    try {
        // Detectar cambios en formularios
        document.addEventListener('input', (e) => {
            const form = e.target.closest('form');
            if (form) {
                markFormAsModified(form);
            }
        });

        // Manejar resize de ventana
        window.addEventListener('resize', debounce(() => {
            handleWindowResize();
        }, 250));

        // Manejar visibilidad de página
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                pauseAutoRefresh();
            } else {
                resumeAutoRefresh();
            }
        });

        log('Event listeners globales configurados', 'info');
    } catch (error) {
        handleError(error, 'Setup Global Event Listeners');
    }
}

/**
 * Manejar resize de ventana
 */
function handleWindowResize() {
    try {
        const width = window.innerWidth;

        // Auto-cerrar sidebar en mobile
        if (width <= 1023 && sidebarOpen) {
            toggleSidebar();
        }

        // Refresh charts si existen
        if (typeof refreshCharts === 'function') {
            refreshCharts();
        }
    } catch (error) {
        handleError(error, 'Handle Window Resize');
    }
}

/**
 * Pausar auto-refresh
 */
function pauseAutoRefresh() {
    Object.values(refreshIntervals).forEach(interval => {
        clearInterval(interval);
    });
    log('Auto-refresh pausado', 'info');
}

/**
 * Reanudar auto-refresh
 */
function resumeAutoRefresh() {
    if (DASHBOARD_CONFIG.autoRefresh) {
        setupAutoRefresh();
        log('Auto-refresh reanudado', 'info');
    }
}

/* ============================================
   FUNCIONES EXPUESTAS GLOBALMENTE
============================================ */

// Exponer funciones necesarias al scope global
window.showSection = showSection;
window.toggleSidebar = toggleSidebar;
window.setTheme = setTheme;
window.showNotification = showNotification;
window.closeNotification = closeNotification;
window.hasUnsavedChanges = hasUnsavedChanges;

// <--- SEGUIR AQUÍ CON PARTE 2: GESTIÓN DE DATOS Y API --->

/* ============================================
   PARTE 2/8 - GESTIÓN DE DATOS Y API
============================================ */

/* ============================================
   GESTIÓN DE DATOS DEL USUARIO
============================================ */

/**
 * Cargar datos del usuario
 */
async function loadUserData() {
    try {
        log('Cargando datos del usuario...', 'info');

        // Simulación de datos del usuario (reemplazar con API real)
        const userData = {
            id: 1,
            name: 'Juan Pérez',
            email: 'juan.perez@example.com',
            role: 'Propietario',
            avatar: '../assets/images/default-avatar.png',
            status: 'online',
            plan: {
                type: 'premium',
                expiresAt: '2024-12-31',
                daysRemaining: 45
            }
        };

        // En producción, usar:
        // const userData = await apiRequest('user/profile');

        currentUser = userData;
        updateUserInterface(userData);
        updatePlanIndicator(userData.plan);

        log('Datos del usuario cargados', 'success');
        return userData;
    } catch (error) {
        handleError(error, 'Load User Data');
        return null;
    }
}

/**
 * Actualizar interfaz con datos del usuario
 */
function updateUserInterface(userData) {
    try {
        // Actualizar nombre del usuario
        const userNameElements = document.querySelectorAll('#userName, #dropdownUserName');
        userNameElements.forEach(element => {
            if (element) element.textContent = userData.name;
        });

        // Actualizar email
        const userEmailElement = document.getElementById('dropdownUserEmail');
        if (userEmailElement) {
            userEmailElement.textContent = userData.email;
        }

        // Actualizar avatar
        const avatarElements = document.querySelectorAll('#userAvatarImg, .dropdown-avatar');
        avatarElements.forEach(element => {
            if (element) element.src = userData.avatar;
        });

        // Actualizar estado
        const statusElement = document.getElementById('userStatus');
        if (statusElement) {
            statusElement.className = `status-indicator ${userData.status}`;
            statusElement.setAttribute('aria-label', `Estado: ${userData.status}`);
        }

        log('Interfaz de usuario actualizada', 'success');
    } catch (error) {
        handleError(error, 'Update User Interface');
    }
}

/**
 * Actualizar indicador de plan
 */
function updatePlanIndicator(planData) {
    try {
        const planTimeElement = document.getElementById('planTimeRemaining');
        const currentPlanElement = document.getElementById('currentPlan');
        const planExpiryElement = document.getElementById('planExpiry');

        if (planTimeElement) {
            planTimeElement.textContent = `${planData.daysRemaining} días`;
        }

        if (currentPlanElement) {
            currentPlanElement.textContent = planData.type.toUpperCase();
        }

        if (planExpiryElement) {
            planExpiryElement.textContent = formatDate(planData.expiresAt);
        }

        // Cambiar color según días restantes
        if (planData.daysRemaining <= 7) {
            planTimeElement?.classList.add('text-danger');
        } else if (planData.daysRemaining <= 30) {
            planTimeElement?.classList.add('text-warning');
        }

        log('Indicador de plan actualizado', 'success');
    } catch (error) {
        handleError(error, 'Update Plan Indicator');
    }
}

/* ============================================
   GESTIÓN DE DATOS DEL NEGOCIO
============================================ */

/**
 * Cargar datos del negocio
 */
async function loadBusinessData() {
    try {
        log('Cargando datos del negocio...', 'info');

        // Simulación de datos del negocio (reemplazar con API real)
        const businessData = {
            id: 1,
            name: 'Salón de Belleza Elena',
            category: 'Belleza y Estética',
            logo: '../assets/images/default-business.png',
            rating: 4.5,
            reviewsCount: 126,
            phone: '+57 300 123 4567',
            email: 'contacto@salonelena.com',
            address: 'Calle 123 #45-67, Bogotá',
            schedule: {
                monday: { open: '08:00', close: '18:00', active: true },
                tuesday: { open: '08:00', close: '18:00', active: true },
                wednesday: { open: '08:00', close: '18:00', active: true },
                thursday: { open: '08:00', close: '18:00', active: true },
                friday: { open: '08:00', close: '18:00', active: true },
                saturday: { open: '09:00', close: '16:00', active: true },
                sunday: { open: '10:00', close: '14:00', active: false }
            }
        };

        // En producción, usar:
        // const businessData = await apiRequest('business/profile');

        businessData = businessData;
        updateBusinessInterface(businessData);

        log('Datos del negocio cargados', 'success');
        return businessData;
    } catch (error) {
        handleError(error, 'Load Business Data');
        return null;
    }
}

/**
 * Actualizar interfaz con datos del negocio
 */
function updateBusinessInterface(businessData) {
    try {
        // Actualizar nombre del negocio
        const businessNameElement = document.getElementById('businessName');
        if (businessNameElement) {
            businessNameElement.textContent = businessData.name;
        }

        // Actualizar categoría
        const businessCategoryElement = document.getElementById('businessCategory');
        if (businessCategoryElement) {
            businessCategoryElement.textContent = businessData.category;
        }

        // Actualizar logo
        const businessLogoElement = document.getElementById('businessLogoImg');
        if (businessLogoElement) {
            businessLogoElement.src = businessData.logo;
        }

        // Actualizar rating
        updateBusinessRating(businessData.rating, businessData.reviewsCount);

        log('Interfaz del negocio actualizada', 'success');
    } catch (error) {
        handleError(error, 'Update Business Interface');
    }
}

/**
 * Actualizar rating del negocio
 */
function updateBusinessRating(rating, reviewsCount) {
    try {
        const starsContainer = document.getElementById('businessStars');
        const ratingTextElement = document.getElementById('businessRatingText');

        if (starsContainer) {
            // Limpiar estrellas existentes
            starsContainer.innerHTML = '';

            // Crear estrellas
            for (let i = 1; i <= 5; i++) {
                const star = document.createElement('i');
                if (i <= Math.floor(rating)) {
                    star.className = 'fas fa-star';
                } else if (i === Math.ceil(rating) && rating % 1 !== 0) {
                    star.className = 'fas fa-star-half-alt';
                } else {
                    star.className = 'far fa-star';
                }
                starsContainer.appendChild(star);
            }
        }

        if (ratingTextElement) {
            ratingTextElement.textContent = `${rating} (${reviewsCount} reseñas)`;
        }

        log('Rating del negocio actualizado', 'success');
    } catch (error) {
        handleError(error, 'Update Business Rating');
    }
}

/* ============================================
   GESTIÓN DE DATOS DEL DASHBOARD
============================================ */

/**
 * Cargar datos principales del dashboard
 */
async function loadDashboardData() {
    try {
        log('Cargando datos del dashboard...', 'info');

        // Simulación de datos del dashboard (reemplazar con API real)
        const dashboardData = {
            stats: {
                revenue: {
                    current: 1250000,
                    change: 12.5,
                    trend: 'positive'
                },
                bookings: {
                    current: 47,
                    change: 8.3,
                    trend: 'positive'
                },
                customers: {
                    current: 128,
                    change: 15.2,
                    trend: 'positive'
                },
                rating: {
                    current: 4.8,
                    change: 0.2,
                    trend: 'positive'
                }
            },
            todaySchedule: [
                {
                    time: '09:00',
                    customer: 'María García',
                    service: 'Corte de cabello',
                    status: 'confirmed'
                },
                {
                    time: '10:30',
                    customer: 'Ana López',
                    service: 'Manicura',
                    status: 'pending'
                },
                {
                    time: '14:00',
                    customer: 'Carlos Ruiz',
                    service: 'Masaje relajante',
                    status: 'confirmed'
                },
                {
                    time: '15:30',
                    customer: 'Laura Martín',
                    service: 'Pedicura',
                    status: 'confirmed'
                }
            ],
            recentBookings: [
                {
                    customer: 'María García',
                    service: 'Corte de cabello',
                    amount: 25000,
                    time: 'Hace 5 min',
                    avatar: 'MG'
                },
                {
                    customer: 'Ana López',
                    service: 'Manicura',
                    amount: 15000,
                    time: 'Hace 12 min',
                    avatar: 'AL'
                },
                {
                    customer: 'Carlos Ruiz',
                    service: 'Masaje relajante',
                    amount: 45000,
                    time: 'Hace 1 hora',
                    avatar: 'CR'
                },
                {
                    customer: 'Luis Martínez',
                    service: 'Corte y barba',
                    amount: 35000,
                    time: 'Hace 2 horas',
                    avatar: 'LM'
                },
                {
                    customer: 'Sofía Morales',
                    service: 'Tinte de cabello',
                    amount: 55000,
                    time: 'Hace 3 horas',
                    avatar: 'SM'
                }
            ],
            activityMetrics: {
                newBookings: 12,
                newCustomers: 3,
                newReviews: 5,
                completionRate: 94
            }
        };

        // En producción, usar:
        // const dashboardData = await apiRequest('dashboard/data');

        updateDashboardStats(dashboardData.stats);
        updateTodaySchedule(dashboardData.todaySchedule);
        updateRecentBookings(dashboardData.recentBookings);
        updateActivityMetrics(dashboardData.activityMetrics);
        updateMenuBadges();

        log('Datos del dashboard cargados', 'success');
        return dashboardData;
    } catch (error) {
        handleError(error, 'Load Dashboard Data');
        return null;
    }
}

/**
 * Actualizar estadísticas principales
 */
function updateDashboardStats(stats) {
    try {
        // Actualizar revenue
        updateStatCard('totalRevenue', 'revenueChange', stats.revenue);

        // Actualizar bookings
        updateStatCard('totalBookings', 'bookingsChange', stats.bookings);

        // Actualizar customers
        updateStatCard('totalCustomers', 'customersChange', stats.customers);

        // Actualizar rating
        updateStatCard('averageRating', 'ratingChange', stats.rating);

        log('Estadísticas del dashboard actualizadas', 'success');
    } catch (error) {
        handleError(error, 'Update Dashboard Stats');
    }
}

/**
 * Actualizar tarjeta de estadística individual
 */
function updateStatCard(valueElementId, changeElementId, data) {
    try {
        const valueElement = document.getElementById(valueElementId);
        const changeElement = document.getElementById(changeElementId);

        if (valueElement) {
            if (valueElementId === 'totalRevenue') {
                valueElement.textContent = formatCurrency(data.current);
            } else {
                valueElement.textContent = data.current;
            }
        }

        if (changeElement) {
            const changeText = data.trend === 'positive' ? '+' : '-';
            changeElement.textContent = `${changeText}${Math.abs(data.change)}${valueElementId === 'averageRating' ? '' : '%'}`;
            changeElement.className = `stat-change ${data.trend}`;
        }
    } catch (error) {
        handleError(error, 'Update Stat Card');
    }
}

/**
 * Actualizar agenda de hoy
 */
function updateTodaySchedule(schedule) {
    try {
        const scheduleContainer = document.getElementById('todaySchedule');
        if (!scheduleContainer) return;

        scheduleContainer.innerHTML = '';

        schedule.forEach(appointment => {
            const scheduleItem = document.createElement('div');
            scheduleItem.className = 'schedule-item';
            scheduleItem.setAttribute('role', 'listitem');

            scheduleItem.innerHTML = `
                <div class="schedule-time">${appointment.time}</div>
                <div class="schedule-details">
                    <div class="schedule-service">${appointment.service}</div>
                    <div class="schedule-client">Cliente: ${appointment.customer}</div>
                </div>
                <div class="schedule-status ${appointment.status}">
                    ${appointment.status === 'confirmed' ? 'Confirmada' : 'Pendiente'}
                </div>
            `;

            scheduleContainer.appendChild(scheduleItem);
        });

        log('Agenda de hoy actualizada', 'success');
    } catch (error) {
        handleError(error, 'Update Today Schedule');
    }
}

/**
 * Actualizar reservas recientes
 */
function updateRecentBookings(bookings) {
    try {
        const bookingsContainer = document.getElementById('recentBookings');
        if (!bookingsContainer) return;

        bookingsContainer.innerHTML = '';

        bookings.forEach(booking => {
            const bookingItem = document.createElement('div');
            bookingItem.className = 'booking-item';
            bookingItem.setAttribute('role', 'listitem');

            bookingItem.innerHTML = `
                <div class="booking-avatar">${booking.avatar}</div>
                <div class="booking-info">
                    <div class="booking-client">${booking.customer}</div>
                    <div class="booking-service">${booking.service} - ${formatCurrency(booking.amount)}</div>
                </div>
                <div class="booking-time">${booking.time}</div>
            `;

            bookingsContainer.appendChild(bookingItem);
        });

        log('Reservas recientes actualizadas', 'success');
    } catch (error) {
        handleError(error, 'Update Recent Bookings');
    }
}

/**
 * Actualizar métricas de actividad
 */
function updateActivityMetrics(metrics) {
    try {
        const elements = {
            'newBookingsWeek': metrics.newBookings,
            'newCustomersWeek': metrics.newCustomers,
            'newReviewsWeek': metrics.newReviews,
            'completionRate': `${metrics.completionRate}%`
        };

        Object.entries(elements).forEach(([elementId, value]) => {
            const element = document.getElementById(elementId);
            if (element) {
                element.textContent = value;
            }
        });

        log('Métricas de actividad actualizadas', 'success');
    } catch (error) {
        handleError(error, 'Update Activity Metrics');
    }
}

/**
 * Actualizar badges del menú
 */
function updateMenuBadges() {
    try {
        // Simular datos de badges
        const badgeData = {
            'todayBookings': 4,
            'pendingBookings': 2,
            'newReviews': 3
        };

        Object.entries(badgeData).forEach(([badgeId, count]) => {
            const badgeElement = document.getElementById(badgeId);
            if (badgeElement) {
                badgeElement.textContent = count;
                badgeElement.style.display = count > 0 ? 'flex' : 'none';
            }
        });

        log('Badges del menú actualizados', 'success');
    } catch (error) {
        handleError(error, 'Update Menu Badges');
    }
}

/* ============================================
   GESTIÓN DE NOTIFICACIONES
============================================ */

/**
 * Cargar notificaciones
 */
async function loadNotifications() {
    try {
        log('Cargando notificaciones...', 'info');

        // Simulación de notificaciones (reemplazar con API real)
        const notifications = [
            {
                id: 1,
                type: 'booking',
                title: 'Nueva reserva',
                message: 'María García ha reservado un corte de cabello para mañana a las 10:00',
                time: '2 min',
                read: false
            },
            {
                id: 2,
                type: 'payment',
                title: 'Pago recibido',
                message: 'Se ha procesado el pago de $25,000 de Ana López',
                time: '15 min',
                read: false
            },
            {
                id: 3,
                type: 'alert',
                title: 'Recordatorio',
                message: 'Tu plan vence en 7 días. Considera renovar para mantener el acceso',
                time: '1 hora',
                read: true
            }
        ];

        // En producción, usar:
        // const notifications = await apiRequest('notifications');

        updateNotificationBadge(notifications);
        updateNotificationList(notifications);

        log('Notificaciones cargadas', 'success');
        return notifications;
    } catch (error) {
        handleError(error, 'Load Notifications');
        return [];
    }
}

/**
 * Actualizar badge de notificaciones
 */
function updateNotificationBadge(notifications) {
    try {
        const unreadCount = notifications.filter(n => !n.read).length;
        const badgeElement = document.getElementById('notificationBadge');

        if (badgeElement) {
            badgeElement.textContent = unreadCount;
            badgeElement.style.display = unreadCount > 0 ? 'flex' : 'none';
        }

        notificationCount = unreadCount;
        log(`Badge de notificaciones actualizado: ${unreadCount}`, 'info');
    } catch (error) {
        handleError(error, 'Update Notification Badge');
    }
}

/**
 * Actualizar lista de notificaciones
 */
function updateNotificationList(notifications) {
    try {
        const listContainer = document.getElementById('notificationList');
        if (!listContainer) return;

        listContainer.innerHTML = '';

        notifications.forEach(notification => {
            const notificationItem = document.createElement('div');
            notificationItem.className = `notification-item ${notification.read ? 'read' : 'unread'}`;
            notificationItem.setAttribute('role', 'listitem');

            notificationItem.innerHTML = `
                <div class="notification-icon ${notification.type}">
                    <i class="fas fa-${getNotificationTypeIcon(notification.type)}"></i>
                </div>
                <div class="notification-content">
                    <div class="notification-title">${notification.title}</div>
                    <div class="notification-message">${notification.message}</div>
                    <div class="notification-time">${notification.time}</div>
                </div>
            `;

            notificationItem.addEventListener('click', () => {
                markNotificationAsRead(notification.id);
            });

            listContainer.appendChild(notificationItem);
        });

        log('Lista de notificaciones actualizada', 'success');
    } catch (error) {
        handleError(error, 'Update Notification List');
    }
}

/**
 * Obtener icono según tipo de notificación
 */
function getNotificationTypeIcon(type) {
    const icons = {
        'booking': 'calendar-plus',
        'payment': 'credit-card',
        'alert': 'exclamation-triangle'
    };
    return icons[type] || 'bell';
}

/**
 * Marcar notificación como leída
 */
async function markNotificationAsRead(notificationId) {
    try {
        // En producción, hacer llamada a API
        // await apiRequest(`notifications/${notificationId}/read`, { method: 'POST' });

        // Actualizar interfaz localmente
        const notificationElement = document.querySelector(`[data-notification-id="${notificationId}"]`);
        if (notificationElement) {
            notificationElement.classList.remove('unread');
            notificationElement.classList.add('read');
        }

        // Recargar notificaciones
        await loadNotifications();

        log(`Notificación ${notificationId} marcada como leída`, 'info');
    } catch (error) {
        handleError(error, 'Mark Notification As Read');
    }
}

/**
 * Marcar todas las notificaciones como leídas
 */
async function markAllNotificationsRead() {
    try {
        // En producción, hacer llamada a API
        // await apiRequest('notifications/mark-all-read', { method: 'POST' });

        // Recargar notificaciones
        await loadNotifications();

        showNotification('Todas las notificaciones han sido marcadas como leídas', 'success');
        log('Todas las notificaciones marcadas como leídas', 'info');
    } catch (error) {
        handleError(error, 'Mark All Notifications Read');
    }
}

/* ============================================
   FUNCIONES DE REFRESH
============================================ */

/**
 * Refrescar dashboard completo
 */
async function refreshDashboard() {
    try {
        log('Refrescando dashboard...', 'info');

        await Promise.all([
            loadDashboardData(),
            loadNotifications()
        ]);

        showNotification('Dashboard actualizado', 'success', 2000);
        log('Dashboard refrescado correctamente', 'success');
    } catch (error) {
        handleError(error, 'Refresh Dashboard');
    }
}

/**
 * Refrescar agenda de hoy
 */
async function refreshTodaySchedule() {
    try {
        // En producción, cargar solo la agenda
        // const schedule = await apiRequest('bookings/today');

        await loadDashboardData();
        showNotification('Agenda actualizada', 'success', 2000);
        log('Agenda de hoy refrescada', 'info');
    } catch (error) {
        handleError(error, 'Refresh Today Schedule');
    }
}

// Exponer funciones necesarias al scope global
window.loadUserData = loadUserData;
window.loadBusinessData = loadBusinessData;
window.loadDashboardData = loadDashboardData;
window.loadNotifications = loadNotifications;
window.refreshDashboard = refreshDashboard;
window.refreshTodaySchedule = refreshTodaySchedule;
window.markAllNotificationsRead = markAllNotificationsRead;

// <--- SEGUIR AQUÍ CON PARTE 3: GESTIÓN DE BOOKINGS Y CALENDAR --->

/* ============================================
   PARTE 3/8 - GESTIÓN DE BOOKINGS Y CALENDAR
============================================ */

/* ============================================
   GESTIÓN DE RESERVAS (BOOKINGS)
============================================ */

/**
 * Cargar datos de reservas
 */
async function loadBookingsData() {
    try {
        log('Cargando datos de reservas...', 'info');

        // Simulación de datos de reservas (reemplazar con API real)
        const bookingsData = {
            bookings: [
                {
                    id: 1,
                    customer: {
                        name: 'María García',
                        phone: '+57 300 123 4567',
                        email: 'maria.garcia@email.com',
                        avatar: 'MG'
                    },
                    service: 'Corte de cabello',
                    date: '2024-12-15',
                    time: '09:00',
                    status: 'confirmed',
                    price: 25000,
                    duration: 45,
                    notes: 'Cliente regular, prefiere corte bob'
                },
                {
                    id: 2,
                    customer: {
                        name: 'Ana López',
                        phone: '+57 301 987 6543',
                        email: 'ana.lopez@email.com',
                        avatar: 'AL'
                    },
                    service: 'Manicura',
                    date: '2024-12-15',
                    time: '10:30',
                    status: 'pending',
                    price: 15000,
                    duration: 30,
                    notes: ''
                },
                {
                    id: 3,
                    customer: {
                        name: 'Carlos Ruiz',
                        phone: '+57 302 456 7890',
                        email: 'carlos.ruiz@email.com',
                        avatar: 'CR'
                    },
                    service: 'Masaje relajante',
                    date: '2024-12-15',
                    time: '14:00',
                    status: 'completed',
                    price: 45000,
                    duration: 60,
                    notes: 'Solicita música relajante'
                }
            ],
            pagination: {
                current: 1,
                total: 5,
                perPage: 10,
                totalRecords: 47
            },
            filters: {
                status: 'all',
                date: '',
                service: 'all'
            }
        };

        // En producción, usar:
        // const bookingsData = await apiRequest('bookings', {
        //     method: 'GET',
        //     params: getCurrentFilters()
        // });

        updateBookingsTable(bookingsData.bookings);
        updateBookingsPagination(bookingsData.pagination);

        log('Datos de reservas cargados', 'success');
        return bookingsData;
    } catch (error) {
        handleError(error, 'Load Bookings Data');
        return null;
    }
}

/**
 * Actualizar tabla de reservas
 */
function updateBookingsTable(bookings) {
    try {
        const tableBody = document.getElementById('bookingsTableBody');
        if (!tableBody) return;

        tableBody.innerHTML = '';

        bookings.forEach(booking => {
            const row = document.createElement('tr');
            row.setAttribute('role', 'row');
            row.innerHTML = `
                <td>
                    <div class="customer-info">
                        <div class="customer-avatar">${booking.customer.avatar}</div>
                        <div class="customer-details">
                            <span class="customer-name">${booking.customer.name}</span>
                            <span class="customer-phone">${booking.customer.phone}</span>
                        </div>
                    </div>
                </td>
                <td>${booking.service}</td>
                <td>${formatDate(booking.date)}</td>
                <td>${formatTime(booking.time)}</td>
                <td>
                    <span class="status-badge ${booking.status}">
                        ${getStatusText(booking.status)}
                    </span>
                </td>
                <td>${formatCurrency(booking.price)}</td>
                <td>
                    <div class="table-actions">
                        <button class="action-btn edit" title="Editar" aria-label="Editar reserva" 
                                onclick="editBooking(${booking.id})">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="action-btn view" title="Ver detalles" aria-label="Ver detalles" 
                                onclick="viewBookingDetails(${booking.id})">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="action-btn delete" title="Cancelar" aria-label="Cancelar reserva" 
                                onclick="cancelBooking(${booking.id})">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </td>
            `;
            tableBody.appendChild(row);
        });

        log('Tabla de reservas actualizada', 'success');
    } catch (error) {
        handleError(error, 'Update Bookings Table');
    }
}

/**
 * Obtener texto del estado
 */
function getStatusText(status) {
    const statusTexts = {
        'confirmed': 'Confirmada',
        'pending': 'Pendiente',
        'completed': 'Completada',
        'cancelled': 'Cancelada',
        'no-show': 'No apareció'
    };
    return statusTexts[status] || status;
}

/**
 * Actualizar paginación de reservas
 */
function updateBookingsPagination(pagination) {
    try {
        const paginationInfo = document.querySelector('.pagination-info');
        const paginationControls = document.querySelector('.pagination-controls');

        if (paginationInfo) {
            const start = (pagination.current - 1) * pagination.perPage + 1;
            const end = Math.min(pagination.current * pagination.perPage, pagination.totalRecords);
            paginationInfo.textContent = `Mostrando ${start}-${end} de ${pagination.totalRecords} reservas`;
        }

        if (paginationControls) {
            paginationControls.innerHTML = '';

            // Botón anterior
            const prevBtn = document.createElement('button');
            prevBtn.className = 'btn btn-sm btn-secondary';
            prevBtn.textContent = 'Anterior';
            prevBtn.disabled = pagination.current <= 1;
            prevBtn.onclick = () => changePage(pagination.current - 1);
            paginationControls.appendChild(prevBtn);

            // Números de página
            for (let i = 1; i <= pagination.total; i++) {
                const pageBtn = document.createElement('button');
                pageBtn.className = `btn btn-sm ${i === pagination.current ? 'btn-primary' : 'btn-secondary'}`;
                pageBtn.textContent = i;
                pageBtn.onclick = () => changePage(i);
                paginationControls.appendChild(pageBtn);
            }

            // Botón siguiente
            const nextBtn = document.createElement('button');
            nextBtn.className = 'btn btn-sm btn-secondary';
            nextBtn.textContent = 'Siguiente';
            nextBtn.disabled = pagination.current >= pagination.total;
            nextBtn.onclick = () => changePage(pagination.current + 1);
            paginationControls.appendChild(nextBtn);
        }

        log('Paginación de reservas actualizada', 'success');
    } catch (error) {
        handleError(error, 'Update Bookings Pagination');
    }
}

/**
 * Cambiar página de reservas
 */
async function changePage(page) {
    try {
        log(`Cambiando a página ${page}`, 'info');
        // En producción, recargar datos con nueva página
        await loadBookingsData();
    } catch (error) {
        handleError(error, 'Change Page');
    }
}

/**
 * Configurar filtros de reservas
 */
function setupBookingFilters() {
    try {
        const statusFilter = document.getElementById('bookingStatusFilter');
        const dateFilter = document.getElementById('bookingDateFilter');
        const serviceFilter = document.getElementById('bookingServiceFilter');

        if (statusFilter) {
            statusFilter.addEventListener('change', handleFilterChange);
        }

        if (dateFilter) {
            dateFilter.addEventListener('change', handleFilterChange);
        }

        if (serviceFilter) {
            serviceFilter.addEventListener('change', handleFilterChange);
        }

        log('Filtros de reservas configurados', 'success');
    } catch (error) {
        handleError(error, 'Setup Booking Filters');
    }
}

/**
 * Manejar cambio de filtros
 */
async function handleFilterChange() {
    try {
        log('Aplicando filtros...', 'info');
        await loadBookingsData();
    } catch (error) {
        handleError(error, 'Handle Filter Change');
    }
}

/**
 * Exportar reservas
 */
async function exportBookings() {
    try {
        log('Exportando reservas...', 'info');

        // En producción, generar y descargar archivo
        showNotification('Las reservas han sido exportadas exitosamente', 'success');

        log('Reservas exportadas', 'success');
    } catch (error) {
        handleError(error, 'Export Bookings');
    }
}

/**
 * Editar reserva
 */
function editBooking(bookingId) {
    try {
        log(`Editando reserva ${bookingId}`, 'info');

        // Abrir modal de edición
        openBookingModal(bookingId);

    } catch (error) {
        handleError(error, 'Edit Booking');
    }
}

/**
 * Ver detalles de reserva
 */
function viewBookingDetails(bookingId) {
    try {
        log(`Viendo detalles de reserva ${bookingId}`, 'info');

        // Mostrar modal con detalles completos
        showBookingDetailsModal(bookingId);

    } catch (error) {
        handleError(error, 'View Booking Details');
    }
}

/**
 * Cancelar reserva
 */
async function cancelBooking(bookingId) {
    try {
        const confirmed = confirm('¿Estás seguro de que quieres cancelar esta reserva?');
        if (!confirmed) return;

        log(`Cancelando reserva ${bookingId}`, 'info');

        // En producción, hacer llamada a API
        // await apiRequest(`bookings/${bookingId}/cancel`, { method: 'POST' });

        await loadBookingsData();
        showNotification('Reserva cancelada exitosamente', 'success');

    } catch (error) {
        handleError(error, 'Cancel Booking');
    }
}

/* ============================================
   GESTIÓN DEL CALENDARIO
============================================ */

/**
 * Inicializar calendario
 */
function initializeCalendar() {
    try {
        log('Inicializando calendario...', 'info');

        const calendarEl = document.getElementById('calendar');
        if (!calendarEl) {
            log('Elemento de calendario no encontrado', 'warn');
            return;
        }

        // Verificar si FullCalendar está disponible
        if (typeof FullCalendar === 'undefined') {
            log('FullCalendar no está cargado, mostrando placeholder', 'warn');
            showCalendarPlaceholder();
            return;
        }

        // Configurar FullCalendar
        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'timeGridWeek',
            locale: 'es',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay'
            },
            slotMinTime: '08:00:00',
            slotMaxTime: '20:00:00',
            allDaySlot: false,
            selectable: true,
            selectMirror: true,
            editable: true,
            dayMaxEvents: true,
            businessHours: {
                daysOfWeek: [1, 2, 3, 4, 5, 6],
                startTime: '08:00',
                endTime: '18:00'
            },
            events: getCalendarEvents(),
            select: handleCalendarSelect,
            eventClick: handleEventClick,
            eventDrop: handleEventDrop,
            eventResize: handleEventResize
        });

        calendar.render();
        window.calendar = calendar; // Guardar referencia global

        // Configurar botones de vista
        setupCalendarViewToggle();

        log('Calendario inicializado correctamente', 'success');

    } catch (error) {
        handleError(error, 'Initialize Calendar');
        showCalendarPlaceholder();
    }
}

/**
 * Mostrar placeholder del calendario
 */
function showCalendarPlaceholder() {
    const calendarEl = document.getElementById('calendar');
    if (calendarEl) {
        calendarEl.innerHTML = `
            <div class="calendar-placeholder">
                <div class="calendar-placeholder-icon">📅</div>
                <div class="calendar-placeholder-text">Calendario de Reservas</div>
                <div class="calendar-placeholder-subtext">El calendario FullCalendar se cargará aquí</div>
            </div>
        `;
    }
}

/**
 * Obtener eventos del calendario
 */
function getCalendarEvents() {
    return [
        {
            id: '1',
            title: 'María García - Corte de cabello',
            start: '2024-12-15T09:00:00',
            end: '2024-12-15T09:45:00',
            backgroundColor: '#2563eb',
            borderColor: '#1d4ed8',
            extendedProps: {
                customer: 'María García',
                service: 'Corte de cabello',
                phone: '+57 300 123 4567',
                status: 'confirmed'
            }
        },
        {
            id: '2',
            title: 'Ana López - Manicura',
            start: '2024-12-15T10:30:00',
            end: '2024-12-15T11:00:00',
            backgroundColor: '#f59e0b',
            borderColor: '#d97706',
            extendedProps: {
                customer: 'Ana López',
                service: 'Manicura',
                phone: '+57 301 987 6543',
                status: 'pending'
            }
        },
        {
            id: '3',
            title: 'Carlos Ruiz - Masaje relajante',
            start: '2024-12-15T14:00:00',
            end: '2024-12-15T15:00:00',
            backgroundColor: '#10b981',
            borderColor: '#059669',
            extendedProps: {
                customer: 'Carlos Ruiz',
                service: 'Masaje relajante',
                phone: '+57 302 456 7890',
                status: 'confirmed'
            }
        }
    ];
}

/**
 * Configurar toggle de vista del calendario
 */
function setupCalendarViewToggle() {
    try {
        const viewButtons = document.querySelectorAll('.view-btn');

        viewButtons.forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();

                // Remover clase active de todos los botones
                viewButtons.forEach(btn => {
                    btn.classList.remove('active');
                    btn.setAttribute('aria-pressed', 'false');
                });

                // Activar botón seleccionado
                button.classList.add('active');
                button.setAttribute('aria-pressed', 'true');

                // Cambiar vista del calendario
                const view = button.getAttribute('data-view');
                changeCalendarView(view);
            });
        });

        log('Toggle de vista del calendario configurado', 'success');
    } catch (error) {
        handleError(error, 'Setup Calendar View Toggle');
    }
}

/**
 * Cambiar vista del calendario
 */
function changeCalendarView(view) {
    try {
        if (!window.calendar) return;

        const viewMap = {
            'week': 'timeGridWeek',
            'month': 'dayGridMonth',
            'day': 'timeGridDay'
        };

        const calendarView = viewMap[view] || 'timeGridWeek';
        window.calendar.changeView(calendarView);

        log(`Vista del calendario cambiada a: ${view}`, 'info');
    } catch (error) {
        handleError(error, 'Change Calendar View');
    }
}

/**
 * Manejar selección en calendario
 */
function handleCalendarSelect(selectInfo) {
    try {
        log('Selección en calendario detectada', 'info');

        // Abrir modal de nueva reserva con fecha/hora preseleccionada
        openNewBookingModal({
            date: selectInfo.startStr.split('T')[0],
            time: selectInfo.startStr.split('T')[1]?.substring(0, 5)
        });

        // Limpiar selección
        window.calendar.unselect();

    } catch (error) {
        handleError(error, 'Handle Calendar Select');
    }
}

/**
 * Manejar click en evento
 */
function handleEventClick(clickInfo) {
    try {
        log('Click en evento del calendario', 'info');

        const event = clickInfo.event;
        const eventId = event.id;

        // Mostrar detalles del evento
        viewBookingDetails(eventId);

    } catch (error) {
        handleError(error, 'Handle Event Click');
    }
}

/**
 * Manejar arrastre de evento
 */
async function handleEventDrop(dropInfo) {
    try {
        log('Evento arrastrado en calendario', 'info');

        const event = dropInfo.event;
        const newStart = event.start;
        const newEnd = event.end;

        // En producción, actualizar en la base de datos
        // await apiRequest(`bookings/${event.id}`, {
        //     method: 'PUT',
        //     body: JSON.stringify({
        //         start: newStart,
        //         end: newEnd
        //     })
        // });

        showNotification('Reserva reprogramada exitosamente', 'success');

    } catch (error) {
        handleError(error, 'Handle Event Drop');
        dropInfo.revert(); // Revertir cambio en caso de error
    }
}

/**
 * Manejar redimensión de evento
 */
async function handleEventResize(resizeInfo) {
    try {
        log('Evento redimensionado en calendario', 'info');

        const event = resizeInfo.event;
        const newEnd = event.end;

        // En producción, actualizar duración en la base de datos
        // await apiRequest(`bookings/${event.id}`, {
        //     method: 'PUT',
        //     body: JSON.stringify({
        //         end: newEnd
        //     })
        // });

        showNotification('Duración de reserva actualizada', 'success');

    } catch (error) {
        handleError(error, 'Handle Event Resize');
        resizeInfo.revert(); // Revertir cambio en caso de error
    }
}

/**
 * Refrescar calendario
 */
function refreshCalendar() {
    try {
        if (window.calendar) {
            window.calendar.refetchEvents();
            log('Calendario refrescado', 'info');
        }
    } catch (error) {
        handleError(error, 'Refresh Calendar');
    }
}

/**
 * Abrir modal de nueva reserva
 */
function openNewBookingModal(preselectedData = {}) {
    try {
        log('Abriendo modal de nueva reserva', 'info');

        const modal = document.getElementById('newBookingModal');
        if (modal) {
            // Prellenar datos si se proporcionan
            if (preselectedData.date) {
                const dateInput = document.getElementById('bookingDate');
                if (dateInput) dateInput.value = preselectedData.date;
            }

            // Resetear wizard al paso 1
            resetBookingWizard();

            // Mostrar modal
            modal.setAttribute('aria-hidden', 'false');
        }

    } catch (error) {
        handleError(error, 'Open New Booking Modal');
    }
}

/**
 * Resetear wizard de reserva
 */
function resetBookingWizard() {
    try {
        const steps = document.querySelectorAll('.form-step');
        const nextBtn = document.getElementById('nextStepBtn');
        const prevBtn = document.getElementById('prevStepBtn');
        const confirmBtn = document.getElementById('confirmBookingBtn');

        // Mostrar solo el primer paso
        steps.forEach((step, index) => {
            step.classList.toggle('active', index === 0);
        });

        // Configurar botones
        if (nextBtn) {
            nextBtn.style.display = 'inline-flex';
            nextBtn.textContent = 'Siguiente';
        }

        if (prevBtn) prevBtn.style.display = 'none';
        if (confirmBtn) confirmBtn.style.display = 'none';

        log('Wizard de reserva reseteado', 'info');
    } catch (error) {
        handleError(error, 'Reset Booking Wizard');
    }
}

// Exponer funciones necesarias al scope global
window.loadBookingsData = loadBookingsData;
window.setupBookingFilters = setupBookingFilters;
window.exportBookings = exportBookings;
window.editBooking = editBooking;
window.viewBookingDetails = viewBookingDetails;
window.cancelBooking = cancelBooking;
window.initializeCalendar = initializeCalendar;
window.refreshCalendar = refreshCalendar;
window.openNewBookingModal = openNewBookingModal;
window.changePage = changePage;

// <--- SEGUIR AQUÍ CON PARTE 4: MODALES Y WIZARDS --->

/* ============================================
   PARTE 4/8 - MODALES Y WIZARDS
============================================ */

/* ============================================
   GESTIÓN GENERAL DE MODALES
============================================ */

/**
 * Abrir modal
 */
function openModal(modalId) {
    try {
        const modal = document.getElementById(modalId);
        if (!modal) {
            log(`Modal ${modalId} no encontrado`, 'warn');
            return;
        }

        modal.setAttribute('aria-hidden', 'false');
        modal.classList.add('entering');

        // Enfocar primer elemento focuseable
        setTimeout(() => {
            const firstFocusable = modal.querySelector('input, button, select, textarea, [tabindex]:not([tabindex="-1"])');
            if (firstFocusable) {
                firstFocusable.focus();
            }
            modal.classList.remove('entering');
        }, 100);

        // Agregar event listener para cerrar con ESC
        document.addEventListener('keydown', handleModalKeydown);

        log(`Modal ${modalId} abierto`, 'info');
    } catch (error) {
        handleError(error, 'Open Modal');
    }
}

/**
 * Cerrar modal
 */
function closeModal(modalId) {
    try {
        const modal = document.getElementById(modalId);
        if (!modal) return;

        modal.classList.add('exiting');

        setTimeout(() => {
            modal.setAttribute('aria-hidden', 'true');
            modal.classList.remove('exiting');

            // Limpiar formularios del modal
            const forms = modal.querySelectorAll('form');
            forms.forEach(form => {
                form.reset();
                markFormAsSaved(form);
            });
        }, 300);

        // Remover event listener
        document.removeEventListener('keydown', handleModalKeydown);

        log(`Modal ${modalId} cerrado`, 'info');
    } catch (error) {
        handleError(error, 'Close Modal');
    }
}

/**
 * Manejar teclas en modales
 */
function handleModalKeydown(e) {
    if (e.key === 'Escape') {
        const openModal = document.querySelector('.modal[aria-hidden="false"]');
        if (openModal) {
            closeModal(openModal.id);
        }
    }
}

/* ============================================
   MODAL DE EMERGENCIA
============================================ */

/**
 * Abrir modal de emergencia
 */
function openEmergencyModal() {
    try {
        log('Abriendo modal de emergencia', 'info');
        openModal('emergencyModal');

        // Prellenar fecha actual
        const today = new Date().toISOString().split('T')[0];
        log(`Preparando cancelación de emergencia para: ${today}`, 'warn');

    } catch (error) {
        handleError(error, 'Open Emergency Modal');
    }
}

/**
 * Procesar cancelación de emergencia
 */
async function processEmergencyCancellation() {
    try {
        const form = document.getElementById('emergencyForm');
        if (!form) return;

        const formData = new FormData(form);
        const emergencyData = {
            reason: formData.get('emergencyReason') || document.getElementById('emergencyReason')?.value,
            message: formData.get('emergencyMessage') || document.getElementById('emergencyMessage')?.value,
            offerReschedule: document.getElementById('offerReschedule')?.checked || false,
            sendWhatsApp: document.getElementById('sendWhatsApp')?.checked || false,
            sendEmail: document.getElementById('sendEmail')?.checked || false
        };

        // Validar datos requeridos
        if (!emergencyData.reason) {
            showNotification('Por favor selecciona un motivo de cancelación', 'error');
            return;
        }

        log('Procesando cancelación de emergencia...', 'warn');

        // Mostrar loading
        const submitBtn = form.querySelector('button[onclick="processEmergencyCancellation()"]');
        const originalText = submitBtn?.innerHTML;
        if (submitBtn) {
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...';
            submitBtn.disabled = true;
        }

        // En producción, hacer llamada a API
        // await apiRequest('emergency/cancel-today', {
        //     method: 'POST',
        //     body: JSON.stringify(emergencyData)
        // });

        // Simular procesamiento
        await new Promise(resolve => setTimeout(resolve, 2000));

        // Restaurar botón
        if (submitBtn) {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }

        closeModal('emergencyModal');
        showNotification('Cancelación de emergencia procesada. Se han enviado notificaciones a todos los clientes.', 'success', 7000);

        // Actualizar dashboard
        await refreshDashboard();

        log('Cancelación de emergencia completada', 'success');

    } catch (error) {
        handleError(error, 'Process Emergency Cancellation');
    }
}

/* ============================================
   MODAL DE RENOVACIÓN
============================================ */

/**
 * Abrir modal de renovación
 */
function openRenewalModal() {
    try {
        log('Abriendo modal de renovación', 'info');
        openModal('renewalModal');

        // Preseleccionar plan recomendado
        const recommendedPlan = document.querySelector('.plan-card.recommended');
        if (recommendedPlan) {
            selectPlan('sixmonth');
        }

    } catch (error) {
        handleError(error, 'Open Renewal Modal');
    }
}

/**
 * Seleccionar plan
 */
function selectPlan(planType) {
    try {
        // Remover selección anterior
        document.querySelectorAll('.plan-card').forEach(card => {
            card.classList.remove('selected');
        });

        // Seleccionar nuevo plan
        const selectedCard = document.querySelector(`[data-plan="${planType}"]`);
        if (selectedCard) {
            selectedCard.classList.add('selected');
        }

        log(`Plan seleccionado: ${planType}`, 'info');
    } catch (error) {
        handleError(error, 'Select Plan');
    }
}

/**
 * Proceder al pago
 */
function proceedToPayment() {
    try {
        const selectedPlan = document.querySelector('.plan-card.selected');
        const selectedPayment = document.querySelector('input[name="paymentMethod"]:checked');

        if (!selectedPlan) {
            showNotification('Por favor selecciona un plan', 'error');
            return;
        }

        if (!selectedPayment) {
            showNotification('Por favor selecciona un método de pago', 'error');
            return;
        }

        const planType = selectedPlan.getAttribute('data-plan');
        const paymentMethod = selectedPayment.value;

        log(`Procesando pago: Plan ${planType}, Método ${paymentMethod}`, 'info');

        // En producción, redirigir a procesador de pagos
        showNotification('Redirigiendo al procesador de pagos...', 'info');

        // Simular redirección
        setTimeout(() => {
            closeModal('renewalModal');
            showNotification('Pago procesado exitosamente. Tu plan ha sido renovado.', 'success');
        }, 2000);

    } catch (error) {
        handleError(error, 'Proceed To Payment');
    }
}

/* ============================================
   MODAL DE SERVICIO
============================================ */

/**
 * Abrir modal de servicio
 */
function openServiceModal(serviceId = null) {
    try {
        log('Abriendo modal de servicio', 'info');

        const modal = document.getElementById('serviceModal');
        const title = document.getElementById('serviceModalTitle');

        if (serviceId) {
            title.textContent = 'Editar Servicio';
            loadServiceData(serviceId);
        } else {
            title.textContent = 'Nuevo Servicio';
            resetServiceForm();
        }

        openModal('serviceModal');

    } catch (error) {
        handleError(error, 'Open Service Modal');
    }
}

/**
 * Cargar datos del servicio para edición
 */
async function loadServiceData(serviceId) {
    try {
        log(`Cargando datos del servicio ${serviceId}`, 'info');

        // En producción, cargar desde API
        // const serviceData = await apiRequest(`services/${serviceId}`);

        // Datos simulados
        const serviceData = {
            id: serviceId,
            name: 'Corte de cabello',
            category: 'haircut',
            price: 25000,
            duration: 45,
            description: 'Corte personalizado según tu estilo y tipo de cabello. Incluye lavado y peinado.',
            capacity: 1,
            preparation: 15,
            active: true,
            onlineBooking: true,
            requiresDeposit: false,
            allowCancellation: true
        };

        // Prellenar formulario
        populateServiceForm(serviceData);

    } catch (error) {
        handleError(error, 'Load Service Data');
    }
}

/**
 * Prellenar formulario de servicio
 */
function populateServiceForm(serviceData) {
    try {
        const elements = {
            'serviceName': serviceData.name,
            'serviceCategory': serviceData.category,
            'servicePrice': serviceData.price,
            'serviceDuration': serviceData.duration,
            'serviceDescription': serviceData.description,
            'serviceCapacity': serviceData.capacity,
            'servicePreparation': serviceData.preparation,
            'serviceActive': serviceData.active,
            'serviceOnline': serviceData.onlineBooking,
            'serviceRequiresDeposit': serviceData.requiresDeposit,
            'serviceCancellation': serviceData.allowCancellation
        };

        Object.entries(elements).forEach(([elementId, value]) => {
            const element = document.getElementById(elementId);
            if (element) {
                if (element.type === 'checkbox') {
                    element.checked = value;
                } else {
                    element.value = value;
                }
            }
        });

        log('Formulario de servicio prellenado', 'success');
    } catch (error) {
        handleError(error, 'Populate Service Form');
    }
}

/**
 * Resetear formulario de servicio
 */
function resetServiceForm() {
    try {
        const form = document.getElementById('serviceForm');
        if (form) {
            form.reset();

            // Valores por defecto
            const defaults = {
                'serviceCapacity': 1,
                'servicePreparation': 0,
                'serviceActive': true,
                'serviceOnline': false,
                'serviceRequiresDeposit': false,
                'serviceCancellation': true
            };

            Object.entries(defaults).forEach(([elementId, value]) => {
                const element = document.getElementById(elementId);
                if (element) {
                    if (element.type === 'checkbox') {
                        element.checked = value;
                    } else {
                        element.value = value;
                    }
                }
            });
        }

        log('Formulario de servicio reseteado', 'info');
    } catch (error) {
        handleError(error, 'Reset Service Form');
    }
}

/**
 * Preview de imagen de servicio
 */
function previewServiceImage(input) {
    try {
        const file = input.files[0];
        const preview = document.getElementById('serviceImagePreview');

        if (file && preview) {
            const reader = new FileReader();

            reader.onload = function (e) {
                preview.innerHTML = `
                    <img src="${e.target.result}" alt="Preview del servicio" style="max-width: 100%; max-height: 150px; border-radius: 8px;">
                    <button type="button" class="btn btn-sm btn-secondary" onclick="removeServiceImage()" style="margin-top: 8px;">
                        <i class="fas fa-trash"></i> Remover
                    </button>
                `;
            };

            reader.readAsDataURL(file);
        }

        log('Preview de imagen de servicio actualizado', 'info');
    } catch (error) {
        handleError(error, 'Preview Service Image');
    }
}

/**
 * Remover imagen de servicio
 */
function removeServiceImage() {
    try {
        const input = document.getElementById('serviceImage');
        const preview = document.getElementById('serviceImagePreview');

        if (input) input.value = '';
        if (preview) {
            preview.innerHTML = `
                <i class="fas fa-camera"></i>
                <span>Subir imagen</span>
                <small>JPG, PNG o WEBP. Máximo 5MB</small>
            `;
        }

        log('Imagen de servicio removida', 'info');
    } catch (error) {
        handleError(error, 'Remove Service Image');
    }
}

/**
 * Guardar servicio
 */
async function saveService() {
    try {
        const form = document.getElementById('serviceForm');
        if (!form) return;

        // Validar formulario
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const formData = new FormData(form);
        const serviceData = {
            name: formData.get('serviceName') || document.getElementById('serviceName')?.value,
            category: formData.get('serviceCategory') || document.getElementById('serviceCategory')?.value,
            price: parseInt(formData.get('servicePrice') || document.getElementById('servicePrice')?.value),
            duration: parseInt(formData.get('serviceDuration') || document.getElementById('serviceDuration')?.value),
            description: formData.get('serviceDescription') || document.getElementById('serviceDescription')?.value,
            capacity: parseInt(formData.get('serviceCapacity') || document.getElementById('serviceCapacity')?.value),
            preparation: parseInt(formData.get('servicePreparation') || document.getElementById('servicePreparation')?.value),
            active: document.getElementById('serviceActive')?.checked || false,
            onlineBooking: document.getElementById('serviceOnline')?.checked || false,
            requiresDeposit: document.getElementById('serviceRequiresDeposit')?.checked || false,
            allowCancellation: document.getElementById('serviceCancellation')?.checked || false
        };

        log('Guardando servicio...', 'info');

        // En producción, hacer llamada a API
        // const response = await apiRequest('services', {
        //     method: 'POST',
        //     body: JSON.stringify(serviceData)
        // });

        closeModal('serviceModal');
        showNotification('Servicio guardado exitosamente', 'success');

        // Recargar servicios si estamos en la sección correspondiente
        if (currentSection === 'services') {
            await loadServicesData();
        }

        log('Servicio guardado correctamente', 'success');

    } catch (error) {
        handleError(error, 'Save Service');
    }
}

/* ============================================
   WIZARD DE NUEVA RESERVA
============================================ */

let currentBookingStep = 1;
const totalBookingSteps = 3;

/**
 * Siguiente paso del wizard
 */
function nextStep() {
    try {
        if (currentBookingStep < totalBookingSteps) {
            if (validateCurrentStep()) {
                currentBookingStep++;
                updateBookingWizard();
            }
        }
    } catch (error) {
        handleError(error, 'Next Step');
    }
}

/**
 * Paso anterior del wizard
 */
function previousStep() {
    try {
        if (currentBookingStep > 1) {
            currentBookingStep--;
            updateBookingWizard();
        }
    } catch (error) {
        handleError(error, 'Previous Step');
    }
}

/**
 * Actualizar wizard de reserva
 */
function updateBookingWizard() {
    try {
        // Mostrar/ocultar pasos
        document.querySelectorAll('.form-step').forEach((step, index) => {
            step.classList.toggle('active', index === currentBookingStep - 1);
        });

        // Actualizar botones
        const prevBtn = document.getElementById('prevStepBtn');
        const nextBtn = document.getElementById('nextStepBtn');
        const confirmBtn = document.getElementById('confirmBookingBtn');

        if (prevBtn) {
            prevBtn.style.display = currentBookingStep > 1 ? 'inline-flex' : 'none';
        }

        if (nextBtn) {
            nextBtn.style.display = currentBookingStep < totalBookingSteps ? 'inline-flex' : 'none';
        }

        if (confirmBtn) {
            confirmBtn.style.display = currentBookingStep === totalBookingSteps ? 'inline-flex' : 'none';
        }

        // Cargar contenido específico del paso
        loadStepContent(currentBookingStep);

        log(`Wizard actualizado al paso ${currentBookingStep}`, 'info');
    } catch (error) {
        handleError(error, 'Update Booking Wizard');
    }
}

/**
 * Validar paso actual
 */
function validateCurrentStep() {
    try {
        const currentStepElement = document.querySelector('.form-step.active');
        if (!currentStepElement) return false;

        const requiredFields = currentStepElement.querySelectorAll('[required]');
        let isValid = true;

        requiredFields.forEach(field => {
            if (!field.value.trim()) {
                field.classList.add('error');
                isValid = false;
            } else {
                field.classList.remove('error');
            }
        });

        if (!isValid) {
            showNotification('Por favor completa todos los campos requeridos', 'error');
        }

        return isValid;
    } catch (error) {
        handleError(error, 'Validate Current Step');
        return false;
    }
}

/**
 * Cargar contenido del paso
 */
function loadStepContent(step) {
    try {
        switch (step) {
            case 2:
                loadServicesSelection();
                break;
            case 3:
                loadAvailableTimes();
                break;
        }
    } catch (error) {
        handleError(error, 'Load Step Content');
    }
}

/**
 * Cargar selección de servicios
 */
function loadServicesSelection() {
    try {
        const container = document.getElementById('servicesSelection');
        if (!container) return;

        // Servicios simulados
        const services = [
            { id: 1, name: 'Corte de cabello', price: 25000, duration: 45 },
            { id: 2, name: 'Manicura', price: 15000, duration: 30 },
            { id: 3, name: 'Masaje relajante', price: 45000, duration: 60 },
            { id: 4, name: 'Tratamiento facial', price: 35000, duration: 50 }
        ];

        container.innerHTML = '';

        services.forEach(service => {
            const serviceOption = document.createElement('div');
            serviceOption.className = 'service-option';
            serviceOption.setAttribute('data-service-id', service.id);
            serviceOption.innerHTML = `
                <h4>${service.name}</h4>
                <p>${formatCurrency(service.price)}</p>
                <small>${service.duration} minutos</small>
            `;

            serviceOption.addEventListener('click', () => selectService(service.id));
            container.appendChild(serviceOption);
        });

        log('Selección de servicios cargada', 'success');
    } catch (error) {
        handleError(error, 'Load Services Selection');
    }
}

/**
 * Seleccionar servicio
 */
function selectService(serviceId) {
    try {
        // Remover selección anterior
        document.querySelectorAll('.service-option').forEach(option => {
            option.classList.remove('selected');
        });

        // Seleccionar nuevo servicio
        const selectedOption = document.querySelector(`[data-service-id="${serviceId}"]`);
        if (selectedOption) {
            selectedOption.classList.add('selected');
        }

        log(`Servicio seleccionado: ${serviceId}`, 'info');
    } catch (error) {
        handleError(error, 'Select Service');
    }
}

/**
 * Cargar horarios disponibles
 */
function loadAvailableTimes() {
    try {
        const container = document.getElementById('availableTimes');
        const dateInput = document.getElementById('bookingDate');

        if (!container || !dateInput?.value) return;

        // Horarios simulados
        const availableTimes = [
            '09:00', '09:30', '10:00', '10:30', '11:00', '11:30',
            '14:00', '14:30', '15:00', '15:30', '16:00', '16:30'
        ];

        container.innerHTML = '';

        availableTimes.forEach(time => {
            const timeSlot = document.createElement('button');
            timeSlot.className = 'time-slot-btn';
            timeSlot.textContent = time;
            timeSlot.addEventListener('click', (e) => {
                e.preventDefault();
                selectTimeSlot(time);
            });

            container.appendChild(timeSlot);
        });

        log('Horarios disponibles cargados', 'success');
    } catch (error) {
        handleError(error, 'Load Available Times');
    }
}

/**
 * Seleccionar horario
 */
function selectTimeSlot(time) {
    try {
        // Remover selección anterior
        document.querySelectorAll('.time-slot-btn').forEach(btn => {
            btn.classList.remove('selected');
        });

        // Seleccionar nuevo horario
        const selectedBtn = Array.from(document.querySelectorAll('.time-slot-btn'))
            .find(btn => btn.textContent === time);

        if (selectedBtn) {
            selectedBtn.classList.add('selected');
        }

        log(`Horario seleccionado: ${time}`, 'info');
    } catch (error) {
        handleError(error, 'Select Time Slot');
    }
}

/**
 * Confirmar reserva
 */
async function confirmBooking() {
    try {
        if (!validateCurrentStep()) return;

        // Recopilar datos del formulario
        const bookingData = {
            customer: {
                name: document.getElementById('clientName')?.value,
                phone: document.getElementById('clientPhone')?.value,
                email: document.getElementById('clientEmail')?.value
            },
            serviceId: document.querySelector('.service-option.selected')?.getAttribute('data-service-id'),
            date: document.getElementById('bookingDate')?.value,
            time: document.querySelector('.time-slot-btn.selected')?.textContent
        };

        if (!bookingData.serviceId || !bookingData.time) {
            showNotification('Por favor completa todos los datos de la reserva', 'error');
            return;
        }

        log('Confirmando reserva...', 'info');

        // En producción, hacer llamada a API
        // await apiRequest('bookings', {
        //     method: 'POST',
        //     body: JSON.stringify(bookingData)
        // });

        closeModal('newBookingModal');
        showNotification('Reserva creada exitosamente', 'success');

        // Actualizar calendario y dashboard
        if (window.calendar) {
            refreshCalendar();
        }
        if (currentSection === 'bookings') {
            await loadBookingsData();
        }
        await loadDashboardData();

        log('Reserva confirmada correctamente', 'success');

    } catch (error) {
        handleError(error, 'Confirm Booking');
    }
}

// Exponer funciones necesarias al scope global
window.openModal = openModal;
window.closeModal = closeModal;
window.openEmergencyModal = openEmergencyModal;
window.processEmergencyCancellation = processEmergencyCancellation;
window.openRenewalModal = openRenewalModal;
window.selectPlan = selectPlan;
window.proceedToPayment = proceedToPayment;
window.openServiceModal = openServiceModal;
window.resetServiceForm = resetServiceForm;
window.previewServiceImage = previewServiceImage;
window.removeServiceImage = removeServiceImage;
window.saveService = saveService;
window.nextStep = nextStep;
window.previousStep = previousStep;
window.confirmBooking = confirmBooking;

// <--- SEGUIR AQUÍ CON PARTE 5: GESTIÓN DE SERVICIOS Y CLIENTES --->

/* ============================================
   PARTE 5/8 - GESTIÓN DE SERVICIOS Y CLIENTES
============================================ */

/* ============================================
   GESTIÓN DE SERVICIOS
============================================ */

/**
 * Cargar datos de servicios
 */
async function loadServicesData() {
    try {
        log('Cargando datos de servicios...', 'info');

        // Simulación de datos de servicios (reemplazar con API real)
        const servicesData = {
            services: [
                {
                    id: 1,
                    name: 'Corte de Cabello',
                    category: 'Cortes',
                    price: 25000,
                    duration: 45,
                    description: 'Corte personalizado según tu estilo y tipo de cabello. Incluye lavado y peinado.',
                    image: '../assets/images/services/haircut.jpg',
                    status: 'active',
                    bookingsThisMonth: 32,
                    averageRating: 4.8,
                    lastBooked: '2024-12-14T15:30:00'
                },
                {
                    id: 2,
                    name: 'Manicura Completa',
                    category: 'Spa',
                    price: 15000,
                    duration: 30,
                    description: 'Manicura completa con limado, cutículas, base, esmalte y topcoat de larga duración.',
                    image: '../assets/images/services/manicure.jpg',
                    status: 'active',
                    bookingsThisMonth: 28,
                    averageRating: 4.6,
                    lastBooked: '2024-12-14T14:00:00'
                },
                {
                    id: 3,
                    name: 'Masaje Relajante',
                    category: 'Spa',
                    price: 45000,
                    duration: 60,
                    description: 'Masaje terapéutico de cuerpo completo para aliviar el estrés y la tensión muscular.',
                    image: '../assets/images/services/massage.jpg',
                    status: 'active',
                    bookingsThisMonth: 18,
                    averageRating: 4.9,
                    lastBooked: '2024-12-14T16:00:00'
                },
                {
                    id: 4,
                    name: 'Corte y Barba',
                    category: 'Cortes',
                    price: 35000,
                    duration: 60,
                    description: 'Servicio completo de corte de cabello y arreglo de barba con productos premium.',
                    image: null,
                    status: 'inactive',
                    bookingsThisMonth: 0,
                    averageRating: 0,
                    lastBooked: null
                }
            ],
            categories: ['Cortes', 'Peinados', 'Coloración', 'Tratamientos', 'Spa'],
            filters: {
                category: 'all',
                status: 'all'
            }
        };

        // En producción, usar:
        // const servicesData = await apiRequest('services', {
        //     method: 'GET',
        //     params: getCurrentServiceFilters()
        // });

        updateServicesGrid(servicesData.services);
        updateServiceFilters(servicesData.categories);

        log('Datos de servicios cargados', 'success');
        return servicesData;
    } catch (error) {
        handleError(error, 'Load Services Data');
        return null;
    }
}

/**
 * Actualizar grid de servicios
 */
function updateServicesGrid(services) {
    try {
        const grid = document.getElementById('servicesGrid');
        if (!grid) return;

        grid.innerHTML = '';

        services.forEach(service => {
            const serviceCard = document.createElement('div');
            serviceCard.className = 'service-card';
            serviceCard.setAttribute('role', 'gridcell');

            const imageContent = service.image
                ? `<img src="${service.image}" alt="${service.name}">`
                : `<i class="fas fa-cut"></i>`;

            serviceCard.innerHTML = `
                <div class="service-image ${service.image ? '' : 'placeholder'}">
                    ${imageContent}
                    <div class="service-badge ${service.status}">${getServiceStatusText(service.status)}</div>
                </div>
                <div class="service-content">
                    <div class="service-header">
                        <div class="service-info">
                            <h3 class="service-name">${service.name}</h3>
                            <span class="service-category">${service.category}</span>
                        </div>
                        <div class="service-price">${formatCurrency(service.price)}</div>
                    </div>
                    <p class="service-description">${service.description}</p>
                    <div class="service-meta">
                        <div class="service-duration">
                            <i class="fas fa-clock"></i>
                            ${service.duration} min
                        </div>
                        <div class="service-bookings">${service.bookingsThisMonth} reservas este mes</div>
                    </div>
                    <div class="service-actions">
                        <button class="btn btn-sm btn-secondary" onclick="editService(${service.id})">
                            <i class="fas fa-edit"></i>
                            Editar
                        </button>
                        <button class="btn btn-sm btn-primary" onclick="viewServiceStats(${service.id})">
                            <i class="fas fa-chart-bar"></i>
                            Stats
                        </button>
                        ${service.status === 'inactive' ?
                    `<button class="btn btn-sm btn-success" onclick="activateService(${service.id})">
                                <i class="fas fa-play"></i>
                                Activar
                            </button>` :
                    `<button class="btn btn-sm btn-warning" onclick="deactivateService(${service.id})">
                                <i class="fas fa-pause"></i>
                                Pausar
                            </button>`
                }
                    </div>
                </div>
            `;

            grid.appendChild(serviceCard);
        });

        log('Grid de servicios actualizado', 'success');
    } catch (error) {
        handleError(error, 'Update Services Grid');
    }
}

/**
 * Obtener texto del estado del servicio
 */
function getServiceStatusText(status) {
    const statusTexts = {
        'active': 'Activo',
        'inactive': 'Inactivo',
        'draft': 'Borrador'
    };
    return statusTexts[status] || status;
}

/**
 * Actualizar filtros de servicios
 */
function updateServiceFilters(categories) {
    try {
        const categoryFilter = document.getElementById('servicesCategoryFilter');
        const statusFilter = document.getElementById('servicesStatusFilter');

        if (categoryFilter) {
            // Limpiar opciones existentes
            const defaultOption = categoryFilter.querySelector('option[value="all"]');
            categoryFilter.innerHTML = '';
            categoryFilter.appendChild(defaultOption);

            // Añadir categorías
            categories.forEach(category => {
                const option = document.createElement('option');
                option.value = category.toLowerCase();
                option.textContent = category;
                categoryFilter.appendChild(option);
            });
        }

        // Configurar event listeners
        setupServiceFilters();

        log('Filtros de servicios actualizados', 'success');
    } catch (error) {
        handleError(error, 'Update Service Filters');
    }
}

/**
 * Configurar filtros de servicios
 */
function setupServiceFilters() {
    try {
        const categoryFilter = document.getElementById('servicesCategoryFilter');
        const statusFilter = document.getElementById('servicesStatusFilter');

        if (categoryFilter) {
            categoryFilter.addEventListener('change', handleServiceFilterChange);
        }

        if (statusFilter) {
            statusFilter.addEventListener('change', handleServiceFilterChange);
        }

        log('Filtros de servicios configurados', 'success');
    } catch (error) {
        handleError(error, 'Setup Service Filters');
    }
}

/**
 * Manejar cambio de filtros de servicios
 */
async function handleServiceFilterChange() {
    try {
        log('Aplicando filtros de servicios...', 'info');
        await loadServicesData();
    } catch (error) {
        handleError(error, 'Handle Service Filter Change');
    }
}

/**
 * Editar servicio
 */
function editService(serviceId) {
    try {
        log(`Editando servicio ${serviceId}`, 'info');
        openServiceModal(serviceId);
    } catch (error) {
        handleError(error, 'Edit Service');
    }
}

/**
 * Ver estadísticas del servicio
 */
function viewServiceStats(serviceId) {
    try {
        log(`Viendo estadísticas del servicio ${serviceId}`, 'info');

        // En producción, abrir modal o navegar a página de estadísticas
        showNotification('Estadísticas del servicio no implementadas aún', 'info');

    } catch (error) {
        handleError(error, 'View Service Stats');
    }
}

/**
 * Activar servicio
 */
async function activateService(serviceId) {
    try {
        log(`Activando servicio ${serviceId}`, 'info');

        // En producción, hacer llamada a API
        // await apiRequest(`services/${serviceId}/activate`, { method: 'POST' });

        await loadServicesData();
        showNotification('Servicio activado exitosamente', 'success');

    } catch (error) {
        handleError(error, 'Activate Service');
    }
}

/**
 * Desactivar servicio
 */
async function deactivateService(serviceId) {
    try {
        const confirmed = confirm('¿Estás seguro de que quieres pausar este servicio?');
        if (!confirmed) return;

        log(`Desactivando servicio ${serviceId}`, 'info');

        // En producción, hacer llamada a API
        // await apiRequest(`services/${serviceId}/deactivate`, { method: 'POST' });

        await loadServicesData();
        showNotification('Servicio pausado exitosamente', 'success');

    } catch (error) {
        handleError(error, 'Deactivate Service');
    }
}

/* ============================================
   GESTIÓN DE CLIENTES
============================================ */

/**
 * Cargar datos de clientes
 */
async function loadCustomersData() {
    try {
        log('Cargando datos de clientes...', 'info');

        // Simulación de datos de clientes (reemplazar con API real)
        const customersData = {
            customers: [
                {
                    id: 1,
                    name: 'María García',
                    email: 'maria.garcia@email.com',
                    phone: '+57 300 123 4567',
                    avatar: 'MG',
                    totalVisits: 12,
                    totalSpent: 285000,
                    lastVisit: '2024-12-15',
                    averageRating: 5.0,
                    favoriteService: 'Corte de cabello',
                    status: 'active',
                    registrationDate: '2024-01-15',
                    notes: 'Cliente VIP, siempre puntual'
                },
                {
                    id: 2,
                    name: 'Ana López',
                    email: 'ana.lopez@email.com',
                    phone: '+57 301 987 6543',
                    avatar: 'AL',
                    totalVisits: 8,
                    totalSpent: 120000,
                    lastVisit: '2024-12-10',
                    averageRating: 4.8,
                    favoriteService: 'Manicura',
                    status: 'active',
                    registrationDate: '2024-03-20',
                    notes: ''
                },
                {
                    id: 3,
                    name: 'Carlos Ruiz',
                    email: 'carlos.ruiz@email.com',
                    phone: '+57 302 456 7890',
                    avatar: 'CR',
                    totalVisits: 5,
                    totalSpent: 225000,
                    lastVisit: '2024-12-08',
                    averageRating: 4.9,
                    favoriteService: 'Masaje relajante',
                    status: 'active',
                    registrationDate: '2024-06-10',
                    notes: 'Prefiere citas en la tarde'
                },
                {
                    id: 4,
                    name: 'Laura Martín',
                    email: 'laura.martin@email.com',
                    phone: '+57 303 789 0123',
                    avatar: 'LM',
                    totalVisits: 2,
                    totalSpent: 50000,
                    lastVisit: '2024-11-25',
                    averageRating: 4.5,
                    favoriteService: 'Pedicura',
                    status: 'inactive',
                    registrationDate: '2024-10-05',
                    notes: 'Cliente nuevo'
                }
            ],
            pagination: {
                current: 1,
                total: 3,
                perPage: 12,
                totalRecords: 128
            },
            filters: {
                search: '',
                type: 'all'
            }
        };

        // En producción, usar:
        // const customersData = await apiRequest('customers', {
        //     method: 'GET',
        //     params: getCurrentCustomerFilters()
        // });

        updateCustomersGrid(customersData.customers);
        updateCustomerStats(customersData);

        log('Datos de clientes cargados', 'success');
        return customersData;
    } catch (error) {
        handleError(error, 'Load Customers Data');
        return null;
    }
}

/**
 * Actualizar grid de clientes
 */
function updateCustomersGrid(customers) {
    try {
        const grid = document.querySelector('.customers-grid');
        if (!grid) return;

        grid.innerHTML = '';

        customers.forEach(customer => {
            const customerCard = document.createElement('div');
            customerCard.className = 'customer-card';

            customerCard.innerHTML = `
                <div class="customer-avatar large">${customer.avatar}</div>
                <div class="customer-info">
                    <h3 class="customer-name">${customer.name}</h3>
                    <p class="customer-email">${customer.email}</p>
                    <p class="customer-phone">${customer.phone}</p>
                </div>
                <div class="customer-stats">
                    <div class="stat-item">
                        <span class="stat-value">${customer.totalVisits}</span>
                        <span class="stat-label">Visitas</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-value">${formatCurrency(customer.totalSpent)}</span>
                        <span class="stat-label">Total gastado</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-value">${formatDate(customer.lastVisit)}</span>
                        <span class="stat-label">Última visita</span>
                    </div>
                </div>
                <div class="customer-actions">
                    <button class="btn btn-sm btn-secondary" onclick="viewCustomerHistory(${customer.id})">
                        Ver historial
                    </button>
                    <button class="btn btn-sm btn-primary" onclick="newAppointmentForCustomer(${customer.id})">
                        Nueva cita
                    </button>
                </div>
            `;

            grid.appendChild(customerCard);
        });

        log('Grid de clientes actualizado', 'success');
    } catch (error) {
        handleError(error, 'Update Customers Grid');
    }
}

/**
 * Actualizar estadísticas de clientes
 */
function updateCustomerStats(customersData) {
    try {
        // Calcular estadísticas
        const totalCustomers = customersData.pagination.totalRecords;
        const activeCustomers = customersData.customers.filter(c => c.status === 'active').length;
        const newThisMonth = customersData.customers.filter(c => {
            const regDate = new Date(c.registrationDate);
            const now = new Date();
            return regDate.getMonth() === now.getMonth() && regDate.getFullYear() === now.getFullYear();
        }).length;

        // Actualizar elementos en la interfaz si existen
        const statsElements = {
            'totalCustomersCount': totalCustomers,
            'activeCustomersCount': activeCustomers,
            'newCustomersThisMonth': newThisMonth
        };

        Object.entries(statsElements).forEach(([elementId, value]) => {
            const element = document.getElementById(elementId);
            if (element) {
                element.textContent = value;
            }
        });

        log('Estadísticas de clientes actualizadas', 'success');
    } catch (error) {
        handleError(error, 'Update Customer Stats');
    }
}

/**
 * Configurar búsqueda de clientes
 */
function setupCustomerSearch() {
    try {
        const searchInput = document.getElementById('customerSearch');
        const typeFilter = document.getElementById('customerTypeFilter');

        if (searchInput) {
            const debouncedSearch = debounce(handleCustomerSearch, 500);
            searchInput.addEventListener('input', debouncedSearch);
        }

        if (typeFilter) {
            typeFilter.addEventListener('change', handleCustomerFilterChange);
        }

        log('Búsqueda de clientes configurada', 'success');
    } catch (error) {
        handleError(error, 'Setup Customer Search');
    }
}

/**
 * Manejar búsqueda de clientes
 */
async function handleCustomerSearch() {
    try {
        const searchTerm = document.getElementById('customerSearch')?.value;
        log(`Buscando clientes: "${searchTerm}"`, 'info');

        await loadCustomersData();
    } catch (error) {
        handleError(error, 'Handle Customer Search');
    }
}

/**
 * Manejar cambio de filtro de clientes
 */
async function handleCustomerFilterChange() {
    try {
        log('Aplicando filtros de clientes...', 'info');
        await loadCustomersData();
    } catch (error) {
        handleError(error, 'Handle Customer Filter Change');
    }
}

/**
 * Ver historial del cliente
 */
function viewCustomerHistory(customerId) {
    try {
        log(`Viendo historial del cliente ${customerId}`, 'info');

        // En producción, abrir modal o navegar a página de historial
        showNotification('Historial del cliente no implementado aún', 'info');

    } catch (error) {
        handleError(error, 'View Customer History');
    }
}

/**
 * Nueva cita para cliente
 */
function newAppointmentForCustomer(customerId) {
    try {
        log(`Creando nueva cita para cliente ${customerId}`, 'info');

        // Abrir modal de nueva reserva con cliente preseleccionado
        openNewBookingModal({ customerId });

    } catch (error) {
        handleError(error, 'New Appointment For Customer');
    }
}

/**
 * Agregar nuevo cliente
 */
function addCustomer() {
    try {
        log('Abriendo formulario para agregar cliente', 'info');

        // En producción, abrir modal de nuevo cliente
        showNotification('Formulario de nuevo cliente no implementado aún', 'info');

    } catch (error) {
        handleError(error, 'Add Customer');
    }
}

/**
 * Exportar clientes
 */
async function exportCustomers() {
    try {
        log('Exportando clientes...', 'info');

        // En producción, generar y descargar archivo
        showNotification('Los clientes han sido exportados exitosamente', 'success');

        log('Clientes exportados', 'success');
    } catch (error) {
        handleError(error, 'Export Customers');
    }
}

/* ============================================
   GESTIÓN DE RESEÑAS
============================================ */

/**
 * Cargar datos de reseñas
 */
async function loadReviewsData() {
    try {
        log('Cargando datos de reseñas...', 'info');

        // Simulación de datos de reseñas (reemplazar con API real)
        const reviewsData = {
            overview: {
                averageRating: 4.8,
                totalReviews: 126,
                distribution: {
                    5: 107,
                    4: 15,
                    3: 3,
                    2: 1,
                    1: 0
                }
            },
            reviews: [
                {
                    id: 1,
                    customer: {
                        name: 'María García',
                        avatar: 'MG'
                    },
                    rating: 5,
                    comment: 'Excelente servicio, muy profesional y el resultado fue perfecto.',
                    service: 'Corte de cabello',
                    date: '2024-12-14',
                    verified: true,
                    response: null
                },
                {
                    id: 2,
                    customer: {
                        name: 'Ana López',
                        avatar: 'AL'
                    },
                    rating: 4,
                    comment: 'Muy buen trabajo, solo tardaron un poco más de lo esperado.',
                    service: 'Manicura',
                    date: '2024-12-13',
                    verified: true,
                    response: 'Gracias por tu comentario Ana, trabajaremos en mejorar los tiempos.'
                }
            ]
        };

        // En producción, usar:
        // const reviewsData = await apiRequest('reviews');

        updateReviewsOverview(reviewsData.overview);
        updateReviewsList(reviewsData.reviews);

        log('Datos de reseñas cargados', 'success');
        return reviewsData;
    } catch (error) {
        handleError(error, 'Load Reviews Data');
        return null;
    }
}

/**
 * Actualizar resumen de reseñas
 */
function updateReviewsOverview(overview) {
    try {
        // Actualizar rating general
        const ratingNumber = document.querySelector('.rating-number');
        const ratingCount = document.querySelector('.rating-count');

        if (ratingNumber) {
            ratingNumber.textContent = overview.averageRating.toFixed(1);
        }

        if (ratingCount) {
            ratingCount.textContent = `${overview.totalReviews} reseñas`;
        }

        // Actualizar distribución de ratings
        Object.entries(overview.distribution).forEach(([stars, count]) => {
            const percentage = (count / overview.totalReviews) * 100;
            const barFill = document.querySelector(`[data-stars="${stars}"] .fill`);
            const barCount = document.querySelector(`[data-stars="${stars}"] .count`);

            if (barFill) {
                barFill.style.width = `${percentage}%`;
            }

            if (barCount) {
                barCount.textContent = count;
            }
        });

        log('Resumen de reseñas actualizado', 'success');
    } catch (error) {
        handleError(error, 'Update Reviews Overview');
    }
}

/**
 * Actualizar lista de reseñas
 */
function updateReviewsList(reviews) {
    try {
        const container = document.querySelector('.reviews-list');
        if (!container) return;

        const reviewsHtml = reviews.map(review => `
            <div class="review-item" data-review-id="${review.id}">
                <div class="review-header">
                    <div class="review-customer">
                        <div class="review-avatar">${review.customer.avatar}</div>
                        <div class="review-customer-info">
                            <div class="review-customer-name">${review.customer.name}</div>
                            <div class="review-date">${formatDate(review.date)}</div>
                        </div>
                    </div>
                    <div class="review-rating">
                        ${generateStarRating(review.rating)}
                    </div>
                </div>
                <div class="review-content">
                    <p>${review.comment}</p>
                    ${review.response ? `
                        <div class="review-response">
                            <strong>Respuesta:</strong> ${review.response}
                        </div>
                    ` : `
                        <button class="btn btn-sm btn-primary" onclick="respondToReview(${review.id})">
                            Responder
                        </button>
                    `}
                </div>
            </div>
        `).join('');

        container.innerHTML = reviewsHtml;

        log('Lista de reseñas actualizada', 'success');
    } catch (error) {
        handleError(error, 'Update Reviews List');
    }
}

/**
 * Generar estrellas de rating
 */
function generateStarRating(rating) {
    let stars = '';
    for (let i = 1; i <= 5; i++) {
        if (i <= rating) {
            stars += '<i class="fas fa-star"></i>';
        } else {
            stars += '<i class="far fa-star"></i>';
        }
    }
    return stars;
}

/**
 * Responder a reseña
 */
function respondToReview(reviewId) {
    try {
        log(`Respondiendo a reseña ${reviewId}`, 'info');

        // En producción, abrir modal de respuesta
        showNotification('Función de respuesta no implementada aún', 'info');

    } catch (error) {
        handleError(error, 'Respond To Review');
    }
}

/**
 * Responder a reseñas pendientes
 */
function respondToReviews() {
    try {
        log('Abriendo panel de respuestas pendientes', 'info');

        // En producción, mostrar modal con reseñas sin responder
        showNotification('Panel de respuestas pendientes no implementado aún', 'info');

    } catch (error) {
        handleError(error, 'Respond To Reviews');
    }
}

// Exponer funciones necesarias al scope global
window.loadServicesData = loadServicesData;
window.setupServiceFilters = setupServiceFilters;
window.editService = editService;
window.viewServiceStats = viewServiceStats;
window.activateService = activateService;
window.deactivateService = deactivateService;
window.loadCustomersData = loadCustomersData;
window.setupCustomerSearch = setupCustomerSearch;
window.viewCustomerHistory = viewCustomerHistory;
window.newAppointmentForCustomer = newAppointmentForCustomer;
window.addCustomer = addCustomer;
window.exportCustomers = exportCustomers;
window.loadReviewsData = loadReviewsData;
window.respondToReview = respondToReview;
window.respondToReviews = respondToReviews;

// <--- SEGUIR AQUÍ CON PARTE 6: STATISTICS Y SETTINGS --->

/* ============================================
   DASHBOARD.JS - PARTE 6/8
   Statistics y Settings Management
   Sistema de reservas - Panel de control
============================================ */

/* ============================================
   GESTIÓN DE ESTADÍSTICAS
============================================ */

/**
 * Cargar datos de estadísticas
 */
async function loadStatisticsData() {
    try {
        log('Cargando datos de estadísticas...', 'info');

        // Simulación de datos de estadísticas (reemplazar con API real)
        const statisticsData = {
            period: 'current-month',
            revenue: {
                current: 1250000,
                previous: 980000,
                growth: 27.5,
                trend: 'positive',
                daily: [
                    { date: '2024-12-01', amount: 45000 },
                    { date: '2024-12-02', amount: 52000 },
                    { date: '2024-12-03', amount: 38000 },
                    { date: '2024-12-04', amount: 67000 },
                    { date: '2024-12-05', amount: 41000 },
                    { date: '2024-12-06', amount: 59000 },
                    { date: '2024-12-07', amount: 73000 },
                    { date: '2024-12-08', amount: 48000 },
                    { date: '2024-12-09', amount: 65000 },
                    { date: '2024-12-10', amount: 55000 },
                    { date: '2024-12-11', amount: 70000 },
                    { date: '2024-12-12', amount: 63000 },
                    { date: '2024-12-13', amount: 58000 },
                    { date: '2024-12-14', amount: 75000 },
                    { date: '2024-12-15', amount: 42000 }
                ]
            },
            bookings: {
                total: 156,
                completed: 142,
                cancelled: 8,
                noShow: 6,
                completionRate: 91.0,
                byService: [
                    { service: 'Corte de cabello', count: 52, percentage: 33.3 },
                    { service: 'Manicura', count: 38, percentage: 24.4 },
                    { service: 'Masaje relajante', count: 28, percentage: 17.9 },
                    { service: 'Tratamiento facial', count: 23, percentage: 14.7 },
                    { service: 'Pedicura', count: 15, percentage: 9.6 }
                ],
                byHour: [
                    { hour: '09:00', count: 12 },
                    { hour: '10:00', count: 18 },
                    { hour: '11:00', count: 22 },
                    { hour: '12:00', count: 8 },
                    { hour: '13:00', count: 5 },
                    { hour: '14:00', count: 25 },
                    { hour: '15:00', count: 28 },
                    { hour: '16:00', count: 24 },
                    { hour: '17:00', count: 14 }
                ]
            },
            customers: {
                total: 128,
                new: 15,
                returning: 113,
                retentionRate: 88.3,
                averageVisits: 3.2,
                topCustomers: [
                    { name: 'María García', visits: 12, spent: 285000 },
                    { name: 'Ana López', visits: 8, spent: 120000 },
                    { name: 'Carlos Ruiz', visits: 5, spent: 225000 },
                    { name: 'Laura Martín', visits: 7, spent: 175000 },
                    { name: 'Sofia Morales', visits: 6, spent: 150000 }
                ]
            },
            performance: {
                averageServiceTime: 52,
                customerSatisfaction: 4.8,
                repeatBookingRate: 65.2,
                peakHours: ['14:00', '15:00', '16:00'],
                popularDays: ['Viernes', 'Sábado', 'Domingo']
            }
        };

        // En producción, usar:
        // const statisticsData = await apiRequest('statistics', {
        //     method: 'GET',
        //     params: { period: getCurrentStatsPeriod() }
        // });

        updateStatisticsSummary(statisticsData);
        generateRevenueChart(statisticsData.revenue);
        generateBookingsChart(statisticsData.bookings);
        generateHourlyChart(statisticsData.bookings);
        generateCustomersChart(statisticsData.customers);
        updatePerformanceMetrics(statisticsData.performance);

        log('Datos de estadísticas cargados', 'success');
        return statisticsData;
    } catch (error) {
        handleError(error, 'Load Statistics Data');
        return null;
    }
}

/**
 * Actualizar resumen de estadísticas
 */
function updateStatisticsSummary(data) {
    try {
        // Actualizar elementos de resumen
        const summaryElements = {
            'statsRevenueAmount': formatCurrency(data.revenue.current),
            'statsRevenueGrowth': `${data.revenue.growth > 0 ? '+' : ''}${data.revenue.growth}%`,
            'statsBookingsTotal': data.bookings.total,
            'statsBookingsRate': `${data.bookings.completionRate}%`,
            'statsCustomersTotal': data.customers.total,
            'statsCustomersNew': data.customers.new,
            'statsRetentionRate': `${data.customers.retentionRate}%`,
            'statsSatisfactionScore': data.performance.customerSatisfaction
        };

        Object.entries(summaryElements).forEach(([elementId, value]) => {
            const element = document.getElementById(elementId);
            if (element) {
                element.textContent = value;
            }
        });

        // Actualizar clases de crecimiento
        const growthElement = document.getElementById('statsRevenueGrowth');
        if (growthElement) {
            growthElement.className = `growth-indicator ${data.revenue.trend}`;
        }

        log('Resumen de estadísticas actualizado', 'success');
    } catch (error) {
        handleError(error, 'Update Statistics Summary');
    }
}

/**
 * Generar gráfico de ingresos
 */
function generateRevenueChart(revenueData) {
    try {
        const canvas = document.getElementById('revenueChart');
        if (!canvas) {
            log('Canvas de gráfico de ingresos no encontrado', 'warn');
            return;
        }

        // Verificar si Chart.js está disponible
        if (typeof Chart === 'undefined') {
            log('Chart.js no está cargado, mostrando placeholder', 'warn');
            showChartPlaceholder(canvas, 'Gráfico de Ingresos');
            return;
        }

        // Destruir gráfico existente si existe
        if (window.revenueChart) {
            window.revenueChart.destroy();
        }

        const ctx = canvas.getContext('2d');
        window.revenueChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: revenueData.daily.map(item => formatDate(item.date, 'DD/MM')),
                datasets: [{
                    label: 'Ingresos Diarios',
                    data: revenueData.daily.map(item => item.amount),
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return formatCurrency(context.parsed.y);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function (value) {
                                return formatCurrency(value);
                            }
                        }
                    }
                }
            }
        });

        log('Gráfico de ingresos generado', 'success');
    } catch (error) {
        handleError(error, 'Generate Revenue Chart');
        showChartPlaceholder(document.getElementById('revenueChart'), 'Gráfico de Ingresos');
    }
}

/**
 * Generar gráfico de reservas por servicio
 */
function generateBookingsChart(bookingsData) {
    try {
        const canvas = document.getElementById('bookingsChart');
        if (!canvas) {
            log('Canvas de gráfico de reservas no encontrado', 'warn');
            return;
        }

        if (typeof Chart === 'undefined') {
            showChartPlaceholder(canvas, 'Gráfico de Reservas por Servicio');
            return;
        }

        // Destruir gráfico existente si existe
        if (window.bookingsChart) {
            window.bookingsChart.destroy();
        }

        const ctx = canvas.getContext('2d');
        window.bookingsChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: bookingsData.byService.map(item => item.service),
                datasets: [{
                    data: bookingsData.byService.map(item => item.count),
                    backgroundColor: [
                        '#3b82f6',  // Azul
                        '#10b981',  // Verde
                        '#f59e0b',  // Amarillo
                        '#ef4444',  // Rojo
                        '#8b5cf6'   // Púrpura
                    ],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                const percentage = bookingsData.byService[context.dataIndex].percentage;
                                return `${context.label}: ${context.parsed} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });

        log('Gráfico de reservas generado', 'success');
    } catch (error) {
        handleError(error, 'Generate Bookings Chart');
        showChartPlaceholder(document.getElementById('bookingsChart'), 'Gráfico de Reservas');
    }
}

/**
 * Generar gráfico de horarios
 */
function generateHourlyChart(bookingsData) {
    try {
        const canvas = document.getElementById('hourlyChart');
        if (!canvas) return;

        if (typeof Chart === 'undefined') {
            showChartPlaceholder(canvas, 'Gráfico de Reservas por Hora');
            return;
        }

        // Destruir gráfico existente si existe
        if (window.hourlyChart) {
            window.hourlyChart.destroy();
        }

        const ctx = canvas.getContext('2d');
        window.hourlyChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: bookingsData.byHour.map(item => item.hour),
                datasets: [{
                    label: 'Reservas por Hora',
                    data: bookingsData.byHour.map(item => item.count),
                    backgroundColor: '#10b981',
                    borderColor: '#059669',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        log('Gráfico de horarios generado', 'success');
    } catch (error) {
        handleError(error, 'Generate Hourly Chart');
    }
}

/**
 * Generar datos de clientes top
 */
function generateCustomersChart(customersData) {
    try {
        // Actualizar tabla de top clientes
        const topCustomersTable = document.getElementById('topCustomersTable');
        if (topCustomersTable) {
            const tbody = topCustomersTable.querySelector('tbody');
            if (tbody) {
                tbody.innerHTML = '';

                customersData.topCustomers.forEach((customer, index) => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${index + 1}</td>
                        <td>${customer.name}</td>
                        <td>${customer.visits}</td>
                        <td>${formatCurrency(customer.spent)}</td>
                    `;
                    tbody.appendChild(row);
                });
            }
        }

        log('Datos de clientes top actualizados', 'success');
    } catch (error) {
        handleError(error, 'Generate Customers Chart');
    }
}

/**
 * Actualizar métricas de rendimiento
 */
function updatePerformanceMetrics(performance) {
    try {
        const metricsElements = {
            'avgServiceTime': `${performance.averageServiceTime} min`,
            'customerSatisfaction': performance.customerSatisfaction,
            'repeatBookingRate': `${performance.repeatBookingRate}%`,
            'peakHours': performance.peakHours.join(', '),
            'popularDays': performance.popularDays.join(', ')
        };

        Object.entries(metricsElements).forEach(([elementId, value]) => {
            const element = document.getElementById(elementId);
            if (element) {
                element.textContent = value;
            }
        });

        log('Métricas de rendimiento actualizadas', 'success');
    } catch (error) {
        handleError(error, 'Update Performance Metrics');
    }
}

/**
 * Mostrar placeholder para gráficos
 */
function showChartPlaceholder(canvas, title) {
    try {
        if (!canvas) return;

        const container = canvas.parentElement;
        if (container) {
            container.innerHTML = `
                <div class="chart-placeholder">
                    <div class="chart-placeholder-icon">📊</div>
                    <div class="chart-placeholder-title">${title}</div>
                    <div class="chart-placeholder-text">Chart.js se cargará aquí</div>
                </div>
            `;
        }
    } catch (error) {
        handleError(error, 'Show Chart Placeholder');
    }
}

/**
 * Configurar filtros de estadísticas
 */
function setupStatisticsFilters() {
    try {
        const periodFilter = document.getElementById('statsPeriodFilter');
        const dateRangeFilter = document.getElementById('statsDateRange');

        if (periodFilter) {
            periodFilter.addEventListener('change', handleStatsFilterChange);
        }

        if (dateRangeFilter) {
            dateRangeFilter.addEventListener('change', handleStatsFilterChange);
        }

        log('Filtros de estadísticas configurados', 'success');
    } catch (error) {
        handleError(error, 'Setup Statistics Filters');
    }
}

/**
 * Manejar cambio de filtros de estadísticas
 */
async function handleStatsFilterChange() {
    try {
        log('Aplicando filtros de estadísticas...', 'info');
        await loadStatisticsData();
    } catch (error) {
        handleError(error, 'Handle Stats Filter Change');
    }
}

/**
 * Exportar estadísticas
 */
async function exportStatistics() {
    try {
        log('Exportando estadísticas...', 'info');

        // En producción, generar y descargar archivo
        showNotification('Las estadísticas han sido exportadas exitosamente', 'success');

        log('Estadísticas exportadas', 'success');
    } catch (error) {
        handleError(error, 'Export Statistics');
    }
}

/**
 * Refrescar todos los gráficos
 */
function refreshCharts() {
    try {
        // Redimensionar gráficos existentes
        if (window.revenueChart) {
            window.revenueChart.resize();
        }
        if (window.bookingsChart) {
            window.bookingsChart.resize();
        }
        if (window.hourlyChart) {
            window.hourlyChart.resize();
        }

        log('Gráficos refrescados', 'info');
    } catch (error) {
        handleError(error, 'Refresh Charts');
    }
}

/* ============================================
   GESTIÓN DE CONFIGURACIONES - INICIO
============================================ */

/**
 * Cargar datos de configuración
 */
async function loadSettingsData() {
    try {
        log('Cargando datos de configuración...', 'info');

        // Simulación de datos de configuración (reemplazar con API real)
        const settingsData = {
            business: {
                name: 'Salón de Belleza Elena',
                category: 'Belleza y Estética',
                description: 'Ofrecemos servicios de belleza integral con profesionales especializados y productos de alta calidad.',
                phone: '+57 300 123 4567',
                email: 'contacto@salonelena.com',
                address: 'Calle 123 #45-67, Bogotá',
                website: 'www.salonelena.com',
                logo: '../assets/images/default-business.png'
            },
            schedule: {
                monday: { active: true, open: '08:00', close: '18:00' },
                tuesday: { active: true, open: '08:00', close: '18:00' },
                wednesday: { active: true, open: '08:00', close: '18:00' },
                thursday: { active: true, open: '08:00', close: '18:00' },
                friday: { active: true, open: '08:00', close: '18:00' },
                saturday: { active: true, open: '09:00', close: '16:00' },
                sunday: { active: false, open: '10:00', close: '14:00' }
            },
            booking: {
                advanceBookingDays: 30,
                cancellationHours: 24,
                confirmationRequired: true,
                onlineBooking: true,
                timeSlotDuration: 30,
                bufferTime: 15,
                maxBookingsPerDay: 20
            },
            notifications: {
                emailBookingConfirmation: true,
                emailBookingReminder: true,
                emailCancellation: true,
                smsBookingConfirmation: false,
                smsBookingReminder: true,
                smsCancellation: false,
                whatsappBookingConfirmation: true,
                whatsappBookingReminder: true,
                whatsappCancellation: true
            },
            payments: {
                mercadoPagoEnabled: true,
                mercadoPagoKey: 'APP_USR_xxxxxxxx',
                qrPaymentsEnabled: true,
                bankTransferEnabled: true,
                cashEnabled: true,
                requireDeposit: false,
                depositPercentage: 50
            }
        };

        // En producción, usar:
        // const settingsData = await apiRequest('settings');

        populateBusinessSettings(settingsData.business);
        populateScheduleSettings(settingsData.schedule);
        populateBookingSettings(settingsData.booking);
        populateNotificationSettings(settingsData.notifications);
        populatePaymentSettings(settingsData.payments);

        log('Datos de configuración cargados', 'success');
        return settingsData;
    } catch (error) {
        handleError(error, 'Load Settings Data');
        return null;
    }
}

/**
 * Poblar configuraciones del negocio
 */
function populateBusinessSettings(businessData) {
    try {
        const fields = {
            'businessName': businessData.name,
            'businessCategory': businessData.category,
            'businessDescription': businessData.description,
            'businessPhone': businessData.phone,
            'businessEmail': businessData.email,
            'businessAddress': businessData.address,
            'businessWebsite': businessData.website
        };

        Object.entries(fields).forEach(([fieldId, value]) => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.value = value || '';
            }
        });

        // Actualizar preview del logo
        const logoPreview = document.getElementById('logoPreview');
        if (logoPreview && businessData.logo) {
            logoPreview.src = businessData.logo;
        }

        log('Configuraciones del negocio pobladas', 'success');
    } catch (error) {
        handleError(error, 'Populate Business Settings');
    }
}

// Exponer funciones necesarias al scope global
window.loadStatisticsData = loadStatisticsData;
window.setupStatisticsFilters = setupStatisticsFilters;
window.exportStatistics = exportStatistics;
window.refreshCharts = refreshCharts;
window.loadSettingsData = loadSettingsData;

// <--- SEGUIR AQUÍ CON PARTE 6 CONTINUACIÓN: SETTINGS COMPLETO --->

/* ============================================
   DASHBOARD.JS - PARTE 7/8
   Settings Completo y Funciones Avanzadas
   Sistema de reservas - Panel de control
============================================ */

/* ============================================
   CONFIGURACIONES - CONTINUACIÓN
============================================ */

/**
 * Poblar configuraciones de horarios
 */
function populateScheduleSettings(scheduleData) {
    try {
        const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

        days.forEach(day => {
            const dayData = scheduleData[day];
            if (!dayData) return;

            // Checkbox de día activo
            const activeCheck = document.getElementById(`${day}Active`);
            if (activeCheck) {
                activeCheck.checked = dayData.active;
                // Agregar event listener para toggle
                activeCheck.addEventListener('change', (e) => {
                    toggleDaySchedule(day, e.target.checked);
                });
            }

            // Horarios
            const openTime = document.getElementById(`${day}Open`);
            const closeTime = document.getElementById(`${day}Close`);

            if (openTime) openTime.value = dayData.open || '';
            if (closeTime) closeTime.value = dayData.close || '';

            // Habilitar/deshabilitar horarios según estado activo
            toggleDaySchedule(day, dayData.active);
        });

        log('Configuraciones de horarios pobladas', 'success');
    } catch (error) {
        handleError(error, 'Populate Schedule Settings');
    }
}

/**
 * Alternar horario del día
 */
function toggleDaySchedule(day, isActive) {
    try {
        const openTime = document.getElementById(`${day}Open`);
        const closeTime = document.getElementById(`${day}Close`);

        if (openTime) {
            openTime.disabled = !isActive;
            openTime.required = isActive;
        }
        if (closeTime) {
            closeTime.disabled = !isActive;
            closeTime.required = isActive;
        }

        // Actualizar estilos visuales
        const dayContainer = document.querySelector(`.schedule-day[data-day="${day}"]`);
        if (dayContainer) {
            dayContainer.classList.toggle('disabled', !isActive);
        }

        log(`Horario del ${day} ${isActive ? 'habilitado' : 'deshabilitado'}`, 'info');
    } catch (error) {
        handleError(error, 'Toggle Day Schedule');
    }
}

/**
 * Poblar configuraciones de reservas
 */
function populateBookingSettings(bookingData) {
    try {
        const fields = {
            'advanceBookingDays': bookingData.advanceBookingDays,
            'cancellationHours': bookingData.cancellationHours,
            'timeSlotDuration': bookingData.timeSlotDuration,
            'bufferTime': bookingData.bufferTime,
            'maxBookingsPerDay': bookingData.maxBookingsPerDay
        };

        Object.entries(fields).forEach(([fieldId, value]) => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.value = value || '';
            }
        });

        // Checkboxes
        const checkboxes = {
            'confirmationRequired': bookingData.confirmationRequired,
            'onlineBooking': bookingData.onlineBooking
        };

        Object.entries(checkboxes).forEach(([fieldId, value]) => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.checked = value || false;
            }
        });

        log('Configuraciones de reservas pobladas', 'success');
    } catch (error) {
        handleError(error, 'Populate Booking Settings');
    }
}

/**
 * Poblar configuraciones de notificaciones
 */
function populateNotificationSettings(notificationData) {
    try {
        Object.entries(notificationData).forEach(([setting, value]) => {
            const checkbox = document.getElementById(setting);
            if (checkbox) {
                checkbox.checked = value || false;
            }
        });

        // Configurar dependencias entre notificaciones
        setupNotificationDependencies();

        log('Configuraciones de notificaciones pobladas', 'success');
    } catch (error) {
        handleError(error, 'Populate Notification Settings');
    }
}

/**
 * Configurar dependencias de notificaciones
 */
function setupNotificationDependencies() {
    try {
        // Si WhatsApp está habilitado, mostrar configuración adicional
        const whatsappEnabled = document.getElementById('whatsappBookingConfirmation');
        const whatsappConfig = document.getElementById('whatsappConfiguration');

        if (whatsappEnabled && whatsappConfig) {
            const updateWhatsappConfig = () => {
                const anyWhatsappEnabled = document.querySelectorAll('[id*="whatsapp"]:checked').length > 0;
                whatsappConfig.style.display = anyWhatsappEnabled ? 'block' : 'none';
            };

            // Event listeners para todos los checkboxes de WhatsApp
            document.querySelectorAll('[id*="whatsapp"]').forEach(checkbox => {
                checkbox.addEventListener('change', updateWhatsappConfig);
            });

            updateWhatsappConfig();
        }

        log('Dependencias de notificaciones configuradas', 'success');
    } catch (error) {
        handleError(error, 'Setup Notification Dependencies');
    }
}

/**
 * Poblar configuraciones de pagos
 */
function populatePaymentSettings(paymentData) {
    try {
        // Checkboxes de métodos de pago
        const checkboxes = {
            'mercadoPagoEnabled': paymentData.mercadoPagoEnabled,
            'qrPaymentsEnabled': paymentData.qrPaymentsEnabled,
            'bankTransferEnabled': paymentData.bankTransferEnabled,
            'cashEnabled': paymentData.cashEnabled,
            'requireDeposit': paymentData.requireDeposit
        };

        Object.entries(checkboxes).forEach(([fieldId, value]) => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.checked = value || false;
                // Configurar event listeners para dependencias
                field.addEventListener('change', () => handlePaymentSettingChange(fieldId, field.checked));
            }
        });

        // Campos de texto
        const fields = {
            'mercadoPagoKey': paymentData.mercadoPagoKey,
            'depositPercentage': paymentData.depositPercentage
        };

        Object.entries(fields).forEach(([fieldId, value]) => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.value = value || '';
            }
        });

        // Configurar dependencias iniciales
        handlePaymentSettingChange('mercadoPagoEnabled', paymentData.mercadoPagoEnabled);
        handlePaymentSettingChange('requireDeposit', paymentData.requireDeposit);

        log('Configuraciones de pagos pobladas', 'success');
    } catch (error) {
        handleError(error, 'Populate Payment Settings');
    }
}

/**
 * Manejar cambios en configuraciones de pago
 */
function handlePaymentSettingChange(settingId, isEnabled) {
    try {
        switch (settingId) {
            case 'mercadoPagoEnabled':
                const mpKeyField = document.getElementById('mercadoPagoKey');
                const mpConfig = document.querySelector('.mercadopago-config');
                if (mpKeyField) mpKeyField.required = isEnabled;
                if (mpConfig) mpConfig.style.display = isEnabled ? 'block' : 'none';
                break;

            case 'requireDeposit':
                const depositField = document.getElementById('depositPercentage');
                const depositConfig = document.querySelector('.deposit-config');
                if (depositField) depositField.required = isEnabled;
                if (depositConfig) depositConfig.style.display = isEnabled ? 'block' : 'none';
                break;
        }

        log(`Configuración de pago ${settingId} ${isEnabled ? 'habilitada' : 'deshabilitada'}`, 'info');
    } catch (error) {
        handleError(error, 'Handle Payment Setting Change');
    }
}

/**
 * Guardar configuraciones
 */
async function saveSettings(section = 'all') {
    try {
        log(`Guardando configuraciones: ${section}`, 'info');

        // Validar antes de guardar
        if (!validateSettingsSection(section)) {
            return;
        }

        // Recopilar datos según la sección
        let settingsData = {};

        switch (section) {
            case 'business':
                settingsData = collectBusinessSettings();
                break;
            case 'schedule':
                settingsData = collectScheduleSettings();
                break;
            case 'booking':
                settingsData = collectBookingSettings();
                break;
            case 'notifications':
                settingsData = collectNotificationSettings();
                break;
            case 'payments':
                settingsData = collectPaymentSettings();
                break;
            case 'appearance':
                settingsData = collectAppearanceSettings();
                break;
            default:
                // Recopilar todas las configuraciones
                settingsData = {
                    business: collectBusinessSettings(),
                    schedule: collectScheduleSettings(),
                    booking: collectBookingSettings(),
                    notifications: collectNotificationSettings(),
                    payments: collectPaymentSettings(),
                    appearance: collectAppearanceSettings()
                };
        }

        // Mostrar loading
        const saveBtn = document.querySelector(`[onclick*="saveSettings('${section}')"]`);
        const originalText = saveBtn?.innerHTML;
        if (saveBtn) {
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';
            saveBtn.disabled = true;
        }

        // En producción, hacer llamada a API
        // await apiRequest('settings', {
        //     method: 'PUT',
        //     body: JSON.stringify({ section, data: settingsData })
        // });

        // Simular delay de red
        await new Promise(resolve => setTimeout(resolve, 1500));

        // Restaurar botón
        if (saveBtn) {
            saveBtn.innerHTML = originalText;
            saveBtn.disabled = false;
        }

        // Marcar formulario como guardado
        const form = document.getElementById(`${section}SettingsForm`);
        if (form) {
            markFormAsSaved(form);
        }

        showNotification('Configuraciones guardadas exitosamente', 'success');
        log('Configuraciones guardadas correctamente', 'success');

    } catch (error) {
        handleError(error, 'Save Settings');

        // Restaurar botón en caso de error
        const saveBtn = document.querySelector(`[onclick*="saveSettings('${section}')"]`);
        if (saveBtn) {
            saveBtn.innerHTML = saveBtn.getAttribute('data-original-text') || 'Guardar';
            saveBtn.disabled = false;
        }
    }
}

/**
 * Validar sección de configuraciones
 */
function validateSettingsSection(section) {
    try {
        const form = document.getElementById(`${section}SettingsForm`);
        if (!form) return true;

        // Validación HTML5
        if (!form.checkValidity()) {
            form.reportValidity();
            return false;
        }

        // Validaciones personalizadas
        switch (section) {
            case 'schedule':
                return validateScheduleSettings();
            case 'payments':
                return validatePaymentSettings();
            case 'notifications':
                return validateNotificationSettings();
            default:
                return true;
        }
    } catch (error) {
        handleError(error, 'Validate Settings Section');
        return false;
    }
}

/**
 * Validar configuraciones de horarios
 */
function validateScheduleSettings() {
    try {
        const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        let hasActiveDay = false;

        for (const day of days) {
            const activeCheck = document.getElementById(`${day}Active`);
            if (activeCheck?.checked) {
                hasActiveDay = true;

                const openTime = document.getElementById(`${day}Open`);
                const closeTime = document.getElementById(`${day}Close`);

                if (!openTime?.value || !closeTime?.value) {
                    showNotification(`Por favor completa los horarios para ${day}`, 'error');
                    return false;
                }

                // Validar que hora de cierre sea posterior a hora de apertura
                if (openTime.value >= closeTime.value) {
                    showNotification(`La hora de cierre debe ser posterior a la de apertura para ${day}`, 'error');
                    return false;
                }
            }
        }

        if (!hasActiveDay) {
            showNotification('Debe tener al menos un día activo', 'error');
            return false;
        }

        return true;
    } catch (error) {
        handleError(error, 'Validate Schedule Settings');
        return false;
    }
}

/**
 * Validar configuraciones de pagos
 */
function validatePaymentSettings() {
    try {
        const mercadoPagoEnabled = document.getElementById('mercadoPagoEnabled')?.checked;
        const mercadoPagoKey = document.getElementById('mercadoPagoKey')?.value;

        if (mercadoPagoEnabled && !mercadoPagoKey) {
            showNotification('La clave de MercadoPago es requerida cuando está habilitado', 'error');
            return false;
        }

        const requireDeposit = document.getElementById('requireDeposit')?.checked;
        const depositPercentage = document.getElementById('depositPercentage')?.value;

        if (requireDeposit && (!depositPercentage || depositPercentage <= 0 || depositPercentage > 100)) {
            showNotification('El porcentaje de depósito debe estar entre 1 y 100', 'error');
            return false;
        }

        return true;
    } catch (error) {
        handleError(error, 'Validate Payment Settings');
        return false;
    }
}

/**
 * Validar configuraciones de notificaciones
 */
function validateNotificationSettings() {
    try {
        // Verificar que al menos un método de notificación esté habilitado
        const notificationMethods = document.querySelectorAll('[id*="BookingConfirmation"]:checked');

        if (notificationMethods.length === 0) {
            showNotification('Debe habilitar al menos un método de notificación para confirmaciones', 'error');
            return false;
        }

        return true;
    } catch (error) {
        handleError(error, 'Validate Notification Settings');
        return false;
    }
}

/**
 * Recopilar configuraciones del negocio
 */
function collectBusinessSettings() {
    try {
        return {
            name: document.getElementById('businessName')?.value || '',
            category: document.getElementById('businessCategory')?.value || '',
            description: document.getElementById('businessDescription')?.value || '',
            phone: document.getElementById('businessPhone')?.value || '',
            email: document.getElementById('businessEmail')?.value || '',
            address: document.getElementById('businessAddress')?.value || '',
            website: document.getElementById('businessWebsite')?.value || ''
        };
    } catch (error) {
        handleError(error, 'Collect Business Settings');
        return {};
    }
}

/**
 * Recopilar configuraciones de horarios
 */
function collectScheduleSettings() {
    try {
        const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        const schedule = {};

        days.forEach(day => {
            const activeCheck = document.getElementById(`${day}Active`);
            const openTime = document.getElementById(`${day}Open`);
            const closeTime = document.getElementById(`${day}Close`);

            schedule[day] = {
                active: activeCheck?.checked || false,
                open: openTime?.value || '',
                close: closeTime?.value || ''
            };
        });

        return schedule;
    } catch (error) {
        handleError(error, 'Collect Schedule Settings');
        return {};
    }
}

/**
 * Recopilar configuraciones de reservas
 */
function collectBookingSettings() {
    try {
        return {
            advanceBookingDays: parseInt(document.getElementById('advanceBookingDays')?.value) || 30,
            cancellationHours: parseInt(document.getElementById('cancellationHours')?.value) || 24,
            confirmationRequired: document.getElementById('confirmationRequired')?.checked || false,
            onlineBooking: document.getElementById('onlineBooking')?.checked || false,
            timeSlotDuration: parseInt(document.getElementById('timeSlotDuration')?.value) || 30,
            bufferTime: parseInt(document.getElementById('bufferTime')?.value) || 15,
            maxBookingsPerDay: parseInt(document.getElementById('maxBookingsPerDay')?.value) || 20
        };
    } catch (error) {
        handleError(error, 'Collect Booking Settings');
        return {};
    }
}

/**
 * Recopilar configuraciones de notificaciones
 */
function collectNotificationSettings() {
    try {
        const notifications = {};
        const notificationFields = [
            'emailBookingConfirmation', 'emailBookingReminder', 'emailCancellation',
            'smsBookingConfirmation', 'smsBookingReminder', 'smsCancellation',
            'whatsappBookingConfirmation', 'whatsappBookingReminder', 'whatsappCancellation'
        ];

        notificationFields.forEach(field => {
            const checkbox = document.getElementById(field);
            notifications[field] = checkbox?.checked || false;
        });

        return notifications;
    } catch (error) {
        handleError(error, 'Collect Notification Settings');
        return {};
    }
}

/**
 * Recopilar configuraciones de pagos
 */
function collectPaymentSettings() {
    try {
        return {
            mercadoPagoEnabled: document.getElementById('mercadoPagoEnabled')?.checked || false,
            mercadoPagoKey: document.getElementById('mercadoPagoKey')?.value || '',
            qrPaymentsEnabled: document.getElementById('qrPaymentsEnabled')?.checked || false,
            bankTransferEnabled: document.getElementById('bankTransferEnabled')?.checked || false,
            cashEnabled: document.getElementById('cashEnabled')?.checked || false,
            requireDeposit: document.getElementById('requireDeposit')?.checked || false,
            depositPercentage: parseInt(document.getElementById('depositPercentage')?.value) || 50
        };
    } catch (error) {
        handleError(error, 'Collect Payment Settings');
        return {};
    }
}

/**
 * Recopilar configuraciones de apariencia
 */
function collectAppearanceSettings() {
    try {
        return {
            theme: document.getElementById('themeSelect')?.value || 'light',
            primaryColor: document.getElementById('primaryColorPicker')?.value || '#3b82f6',
            accentColor: document.getElementById('accentColorPicker')?.value || '#10b981',
            logoPosition: document.getElementById('logoPositionSelect')?.value || 'left',
            showBusinessHours: document.getElementById('showBusinessHours')?.checked || true,
            showRating: document.getElementById('showRating')?.checked || true
        };
    } catch (error) {
        handleError(error, 'Collect Appearance Settings');
        return {};
    }
}

// Exponer funciones necesarias al scope global
window.toggleDaySchedule = toggleDaySchedule;
window.saveSettings = saveSettings;
window.validateSettingsSection = validateSettingsSection;
window.handlePaymentSettingChange = handlePaymentSettingChange;

// <--- SEGUIR AQUÍ CON PARTE 8: FUNCIONES FINALES Y INICIALIZACIÓN --->

/* ============================================
   DASHBOARD.JS - PARTE 8/8 - FINAL
   Inicialización Completa y Cleanup
   Sistema de reservas - Panel de control
============================================ */

/* ============================================
   FUNCIONES DE UTILIDAD AVANZADAS
============================================ */

/**
 * Preview de logo/imagen de negocio
 */
function previewBusinessLogo(input) {
    try {
        const file = input.files[0];
        const preview = document.getElementById('logoPreview');

        if (file && preview) {
            // Validar tipo de archivo
            const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
            if (!allowedTypes.includes(file.type)) {
                showNotification('Solo se permiten archivos JPG, PNG o WEBP', 'error');
                input.value = '';
                return;
            }

            // Validar tamaño (máximo 5MB)
            if (file.size > 5 * 1024 * 1024) {
                showNotification('El archivo no puede ser mayor a 5MB', 'error');
                input.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function (e) {
                preview.src = e.target.result;
                preview.style.display = 'block';

                // Mostrar botón de remover
                const removeBtn = document.getElementById('removeLogoBtn');
                if (removeBtn) {
                    removeBtn.style.display = 'inline-block';
                }

                // Marcar formulario como modificado
                const form = input.closest('form');
                if (form) markFormAsModified(form);
            };

            reader.readAsDataURL(file);
        }

        log('Preview de logo actualizado', 'info');
    } catch (error) {
        handleError(error, 'Preview Business Logo');
    }
}

/**
 * Remover logo de negocio
 */
function removeBusinessLogo() {
    try {
        const input = document.getElementById('businessLogo');
        const preview = document.getElementById('logoPreview');
        const removeBtn = document.getElementById('removeLogoBtn');

        if (input) input.value = '';
        if (preview) {
            preview.src = '../assets/images/default-business.png';
            preview.style.display = 'block';
        }
        if (removeBtn) removeBtn.style.display = 'none';

        // Marcar formulario como modificado
        const form = input?.closest('form');
        if (form) markFormAsModified(form);

        log('Logo de negocio removido', 'info');
    } catch (error) {
        handleError(error, 'Remove Business Logo');
    }
}

/**
 * Aplicar tema personalizado
 */
function applyCustomTheme() {
    try {
        const primaryColor = document.getElementById('primaryColorPicker')?.value;
        const accentColor = document.getElementById('accentColorPicker')?.value;

        if (primaryColor) {
            document.documentElement.style.setProperty('--primary-color', primaryColor);
        }
        if (accentColor) {
            document.documentElement.style.setProperty('--accent-color', accentColor);
        }

        log('Tema personalizado aplicado', 'success');
    } catch (error) {
        handleError(error, 'Apply Custom Theme');
    }
}

/**
 * Resetear configuraciones a valores por defecto
 */
function resetSettingsToDefault(section) {
    try {
        const confirmed = confirm(`¿Estás seguro de que quieres resetear las configuraciones de ${section} a los valores por defecto?`);
        if (!confirmed) return;

        log(`Reseteando configuraciones de ${section}`, 'info');

        // Valores por defecto según sección
        const defaults = {
            business: {
                name: '',
                category: 'Servicios Generales',
                description: '',
                phone: '',
                email: '',
                address: '',
                website: ''
            },
            schedule: {
                monday: { active: true, open: '08:00', close: '18:00' },
                tuesday: { active: true, open: '08:00', close: '18:00' },
                wednesday: { active: true, open: '08:00', close: '18:00' },
                thursday: { active: true, open: '08:00', close: '18:00' },
                friday: { active: true, open: '08:00', close: '18:00' },
                saturday: { active: false, open: '09:00', close: '16:00' },
                sunday: { active: false, open: '10:00', close: '14:00' }
            },
            booking: {
                advanceBookingDays: 30,
                cancellationHours: 24,
                confirmationRequired: true,
                onlineBooking: false,
                timeSlotDuration: 30,
                bufferTime: 15,
                maxBookingsPerDay: 20
            },
            notifications: {
                emailBookingConfirmation: true,
                emailBookingReminder: false,
                emailCancellation: true,
                smsBookingConfirmation: false,
                smsBookingReminder: false,
                smsCancellation: false,
                whatsappBookingConfirmation: false,
                whatsappBookingReminder: false,
                whatsappCancellation: false
            },
            payments: {
                mercadoPagoEnabled: false,
                mercadoPagoKey: '',
                qrPaymentsEnabled: false,
                bankTransferEnabled: true,
                cashEnabled: true,
                requireDeposit: false,
                depositPercentage: 50
            },
            appearance: {
                theme: 'light',
                primaryColor: '#3b82f6',
                accentColor: '#10b981',
                logoPosition: 'left',
                showBusinessHours: true,
                showRating: true
            }
        };

        // Aplicar valores por defecto
        const sectionDefaults = defaults[section];
        if (sectionDefaults) {
            switch (section) {
                case 'business':
                    populateBusinessSettings(sectionDefaults);
                    break;
                case 'schedule':
                    populateScheduleSettings(sectionDefaults);
                    break;
                case 'booking':
                    populateBookingSettings(sectionDefaults);
                    break;
                case 'notifications':
                    populateNotificationSettings(sectionDefaults);
                    break;
                case 'payments':
                    populatePaymentSettings(sectionDefaults);
                    break;
                case 'appearance':
                    populateAppearanceSettings(sectionDefaults);
                    break;
            }

            // Marcar formulario como modificado
            const form = document.getElementById(`${section}SettingsForm`);
            if (form) markFormAsModified(form);

            showNotification(`Configuraciones de ${section} reseteadas a valores por defecto`, 'success');
        }

    } catch (error) {
        handleError(error, 'Reset Settings To Default');
    }
}

/**
 * Exportar configuraciones
 */
async function exportSettings() {
    try {
        log('Exportando configuraciones...', 'info');

        // Recopilar todas las configuraciones
        const allSettings = {
            business: collectBusinessSettings(),
            schedule: collectScheduleSettings(),
            booking: collectBookingSettings(),
            notifications: collectNotificationSettings(),
            payments: collectPaymentSettings(),
            appearance: collectAppearanceSettings(),
            exportDate: new Date().toISOString(),
            version: '1.0.0'
        };

        // Crear archivo JSON
        const dataStr = JSON.stringify(allSettings, null, 2);
        const dataBlob = new Blob([dataStr], { type: 'application/json' });

        // Crear enlace de descarga
        const link = document.createElement('a');
        link.href = URL.createObjectURL(dataBlob);
        link.download = `configuraciones_dashboard_${formatDate(new Date(), 'YYYY-MM-DD')}.json`;
        link.click();

        // Cleanup
        URL.revokeObjectURL(link.href);

        showNotification('Configuraciones exportadas exitosamente', 'success');
        log('Configuraciones exportadas', 'success');

    } catch (error) {
        handleError(error, 'Export Settings');
    }
}

/**
 * Importar configuraciones
 */
function importSettings(input) {
    try {
        const file = input.files[0];
        if (!file) return;

        if (file.type !== 'application/json') {
            showNotification('Solo se permiten archivos JSON', 'error');
            input.value = '';
            return;
        }

        const reader = new FileReader();
        reader.onload = function (e) {
            try {
                const settings = JSON.parse(e.target.result);

                // Validar estructura básica
                if (!settings.business || !settings.schedule) {
                    throw new Error('Archivo de configuraciones inválido');
                }

                // Confirmar importación
                const confirmed = confirm('¿Estás seguro de que quieres importar estas configuraciones? Esto sobrescribirá las configuraciones actuales.');
                if (!confirmed) {
                    input.value = '';
                    return;
                }

                // Aplicar configuraciones
                populateBusinessSettings(settings.business);
                populateScheduleSettings(settings.schedule);
                populateBookingSettings(settings.booking);
                populateNotificationSettings(settings.notifications);
                populatePaymentSettings(settings.payments);
                if (settings.appearance) {
                    populateAppearanceSettings(settings.appearance);
                }

                showNotification('Configuraciones importadas exitosamente', 'success');
                log('Configuraciones importadas', 'success');

            } catch (parseError) {
                showNotification('Error al leer el archivo de configuraciones', 'error');
                handleError(parseError, 'Parse Import Settings');
            }

            input.value = '';
        };

        reader.readAsText(file);

    } catch (error) {
        handleError(error, 'Import Settings');
        input.value = '';
    }
}

/* ============================================
   FUNCIONES DE BÚSQUEDA GLOBAL
============================================ */

/**
 * Búsqueda global en el dashboard
 */
function performGlobalSearch(query) {
    try {
        if (!query || query.length < 2) {
            clearGlobalSearchResults();
            return;
        }

        log(`Realizando búsqueda global: "${query}"`, 'info');

        // Simular resultados de búsqueda
        const searchResults = {
            bookings: [
                { id: 1, type: 'booking', title: 'Reserva de María García', description: 'Corte de cabello - 15/12/2024 09:00' },
                { id: 2, type: 'booking', title: 'Reserva de Ana López', description: 'Manicura - 15/12/2024 10:30' }
            ],
            customers: [
                { id: 1, type: 'customer', title: 'María García', description: '+57 300 123 4567 - maria.garcia@email.com' },
                { id: 2, type: 'customer', title: 'Ana López', description: '+57 301 987 6543 - ana.lopez@email.com' }
            ],
            services: [
                { id: 1, type: 'service', title: 'Corte de cabello', description: '$25,000 - 45 minutos' },
                { id: 2, type: 'service', title: 'Manicura', description: '$15,000 - 30 minutos' }
            ]
        };

        // Filtrar resultados basados en la query
        const filteredResults = {};
        Object.keys(searchResults).forEach(category => {
            filteredResults[category] = searchResults[category].filter(item =>
                item.title.toLowerCase().includes(query.toLowerCase()) ||
                item.description.toLowerCase().includes(query.toLowerCase())
            );
        });

        displayGlobalSearchResults(filteredResults);

    } catch (error) {
        handleError(error, 'Perform Global Search');
    }
}

/**
 * Mostrar resultados de búsqueda global
 */
function displayGlobalSearchResults(results) {
    try {
        const resultsContainer = document.getElementById('globalSearchResults');
        if (!resultsContainer) return;

        resultsContainer.innerHTML = '';
        let hasResults = false;

        Object.entries(results).forEach(([category, items]) => {
            if (items.length > 0) {
                hasResults = true;

                const categorySection = document.createElement('div');
                categorySection.className = 'search-category';
                categorySection.innerHTML = `
                    <h4 class="search-category-title">${getCategoryTitle(category)}</h4>
                    <div class="search-items">
                        ${items.map(item => `
                            <div class="search-item" onclick="navigateToSearchResult('${item.type}', ${item.id})">
                                <div class="search-item-icon">
                                    <i class="fas fa-${getSearchItemIcon(item.type)}"></i>
                                </div>
                                <div class="search-item-content">
                                    <div class="search-item-title">${item.title}</div>
                                    <div class="search-item-description">${item.description}</div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                `;

                resultsContainer.appendChild(categorySection);
            }
        });

        if (!hasResults) {
            resultsContainer.innerHTML = `
                <div class="no-search-results">
                    <i class="fas fa-search"></i>
                    <p>No se encontraron resultados</p>
                </div>
            `;
        }

        resultsContainer.style.display = 'block';

    } catch (error) {
        handleError(error, 'Display Global Search Results');
    }
}

/**
 * Limpiar resultados de búsqueda
 */
function clearGlobalSearchResults() {
    try {
        const resultsContainer = document.getElementById('globalSearchResults');
        if (resultsContainer) {
            resultsContainer.style.display = 'none';
            resultsContainer.innerHTML = '';
        }
    } catch (error) {
        handleError(error, 'Clear Global Search Results');
    }
}

/**
 * Obtener título de categoría de búsqueda
 */
function getCategoryTitle(category) {
    const titles = {
        'bookings': 'Reservas',
        'customers': 'Clientes',
        'services': 'Servicios',
        'reviews': 'Reseñas'
    };
    return titles[category] || category;
}

/**
 * Obtener icono de item de búsqueda
 */
function getSearchItemIcon(type) {
    const icons = {
        'booking': 'calendar-alt',
        'customer': 'user',
        'service': 'cut',
        'review': 'star'
    };
    return icons[type] || 'search';
}

/**
 * Navegar a resultado de búsqueda
 */
function navigateToSearchResult(type, id) {
    try {
        log(`Navegando a ${type} con ID ${id}`, 'info');

        // Cerrar búsqueda
        clearGlobalSearchResults();
        const searchInput = document.getElementById('globalSearchInput');
        if (searchInput) searchInput.value = '';

        // Navegar según el tipo
        switch (type) {
            case 'booking':
                showSection('bookings');
                setTimeout(() => viewBookingDetails(id), 500);
                break;
            case 'customer':
                showSection('customers');
                setTimeout(() => viewCustomerHistory(id), 500);
                break;
            case 'service':
                showSection('services');
                setTimeout(() => editService(id), 500);
                break;
            default:
                showSection(type + 's');
        }

    } catch (error) {
        handleError(error, 'Navigate To Search Result');
    }
}

/* ============================================
   CONFIGURACIÓN DE BÚSQUEDA
============================================ */

/**
 * Configurar búsqueda global
 */
function setupGlobalSearch() {
    try {
        const searchInput = document.getElementById('globalSearchInput');
        const searchToggle = document.getElementById('globalSearchToggle');

        if (searchInput) {
            // Debounced search
            const debouncedSearch = debounce((query) => {
                performGlobalSearch(query);
            }, 300);

            searchInput.addEventListener('input', (e) => {
                debouncedSearch(e.target.value);
            });

            // Limpiar al perder foco
            searchInput.addEventListener('blur', () => {
                setTimeout(() => clearGlobalSearchResults(), 200);
            });

            // Teclas especiales
            searchInput.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    clearGlobalSearchResults();
                    searchInput.value = '';
                    searchInput.blur();
                }
            });
        }

        if (searchToggle) {
            searchToggle.addEventListener('click', () => {
                if (searchInput) {
                    searchInput.focus();
                }
            });
        }

        log('Búsqueda global configurada', 'success');
    } catch (error) {
        handleError(error, 'Setup Global Search');
    }
}

/* ============================================
   FUNCIONES DE CLEANUP Y FINALIZACIÓN
============================================ */

/**
 * Cleanup al cerrar sesión
 */
function performLogoutCleanup() {
    try {
        log('Realizando cleanup antes del logout...', 'info');

        // Limpiar intervalos
        Object.values(refreshIntervals).forEach(interval => {
            clearInterval(interval);
        });

        // Limpiar gráficos
        if (window.revenueChart) {
            window.revenueChart.destroy();
            window.revenueChart = null;
        }
        if (window.bookingsChart) {
            window.bookingsChart.destroy();
            window.bookingsChart = null;
        }
        if (window.hourlyChart) {
            window.hourlyChart.destroy();
            window.hourlyChart = null;
        }

        // Limpiar datos sensibles
        currentUser = null;
        businessData = null;
        dashboardData = {};

        // Limpiar localStorage de sesión
        localStorage.removeItem('dashboard-temp-data');

        log('Cleanup completado', 'success');
    } catch (error) {
        handleError(error, 'Perform Logout Cleanup');
    }
}

/**
 * Cerrar sesión
 */
async function logout() {
    try {
        const confirmed = confirm('¿Estás seguro de que quieres cerrar sesión?');
        if (!confirmed) return;

        log('Cerrando sesión...', 'info');

        // Verificar cambios no guardados
        if (hasUnsavedChanges()) {
            const saveConfirmed = confirm('Tienes cambios no guardados. ¿Quieres guardarlos antes de cerrar sesión?');
            if (saveConfirmed) {
                await saveSettings('all');
            }
        }

        // Realizar cleanup
        performLogoutCleanup();

        // En producción, hacer llamada a API de logout
        // await apiRequest('auth/logout', { method: 'POST' });

        // Redirigir al login
        window.location.href = 'login.html';

    } catch (error) {
        handleError(error, 'Logout');
    }
}

/* ============================================
   INICIALIZACIÓN COMPLETA FINAL
============================================ */

/**
 * Configurar todos los event listeners del dashboard
 */
function setupAllEventListeners() {
    try {
        log('Configurando todos los event listeners...', 'info');

        // Event listeners ya configurados en partes anteriores
        setupGlobalEventListeners();
        setupGlobalSearch();
        setupBookingFilters();
        setupServiceFilters();
        setupCustomerSearch();
        setupStatisticsFilters();

        // Event listeners específicos adicionales
        document.addEventListener('click', (e) => {
            // Cerrar dropdowns al hacer click fuera
            if (!e.target.closest('.dropdown')) {
                document.querySelectorAll('.dropdown.open').forEach(dropdown => {
                    dropdown.classList.remove('open');
                });
            }
        });

        // Prevenir pérdida de datos
        window.addEventListener('beforeunload', (e) => {
            if (hasUnsavedChanges()) {
                e.preventDefault();
                e.returnValue = '';
                return '';
            }
        });

        log('Todos los event listeners configurados', 'success');
    } catch (error) {
        handleError(error, 'Setup All Event Listeners');
    }
}

/**
 * Verificar dependencias del dashboard
 */
function checkDashboardDependencies() {
    try {
        const dependencies = {
            'FontAwesome': () => document.querySelector('link[href*="fontawesome"]') !== null,
            'Chart.js': () => typeof Chart !== 'undefined',
            'FullCalendar': () => typeof FullCalendar !== 'undefined'
        };

        const missing = [];
        Object.entries(dependencies).forEach(([name, check]) => {
            if (!check()) {
                missing.push(name);
                log(`Dependencia faltante: ${name}`, 'warn');
            }
        });

        if (missing.length > 0) {
            log(`Dependencias faltantes: ${missing.join(', ')}. Algunas funcionalidades pueden estar limitadas.`, 'warn');
            showNotification(`Algunas librerías no están cargadas: ${missing.join(', ')}`, 'warning', 8000);
        } else {
            log('Todas las dependencias están disponibles', 'success');
        }

        return missing.length === 0;
    } catch (error) {
        handleError(error, 'Check Dashboard Dependencies');
        return false;
    }
}

/**
 * Inicialización final y completa del dashboard
 */
async function initializeCompleteDashboard() {
    try {
        log('=== INICIANDO DASHBOARD COMPLETO ===', 'info');

        // 1. Verificar dependencias
        checkDashboardDependencies();

        // 2. Inicializar componentes base (ya hecho en Parte 1)
        await initializeDashboard();

        // 3. Configurar todos los event listeners
        setupAllEventListeners();

        // 4. Inicializar calendar si la sección está visible
        if (currentSection === 'calendar') {
            initializeCalendar();
        }

        // 5. Cargar notificaciones
        await loadNotifications();

        // 6. Mostrar mensaje de bienvenida
        setTimeout(() => {
            if (currentUser) {
                showNotification(`¡Bienvenido de vuelta, ${currentUser.name}!`, 'success', 3000);
            }
        }, 1000);

        log('=== DASHBOARD COMPLETAMENTE INICIALIZADO ===', 'success');

    } catch (error) {
        handleError(error, 'Initialize Complete Dashboard');
        showNotification('Error al inicializar el dashboard. Por favor recarga la página.', 'error', 10000);
    }
}

// Exponer funciones finales al scope global
window.previewBusinessLogo = previewBusinessLogo;
window.removeBusinessLogo = removeBusinessLogo;
window.applyCustomTheme = applyCustomTheme;
window.resetSettingsToDefault = resetSettingsToDefault;
window.exportSettings = exportSettings;
window.importSettings = importSettings;
window.performGlobalSearch = performGlobalSearch;
window.navigateToSearchResult = navigateToSearchResult;
window.logout = logout;

/* ============================================
   INICIALIZACIÓN AUTOMÁTICA
============================================ */

// Ejecutar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => {
    initializeCompleteDashboard();
});

// Ejecutar si el DOM ya está listo
if (document.readyState === 'loading') {
    // El event listener anterior se encargará
} else {
    // DOM ya está listo, ejecutar inmediatamente
    initializeCompleteDashboard();
}

/* ============================================
   FIN DEL DASHBOARD.JS - SISTEMA COMPLETO
============================================ */

log('Dashboard.js cargado completamente - 8 partes integradas', 'success');