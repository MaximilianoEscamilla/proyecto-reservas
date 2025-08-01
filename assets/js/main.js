/**
 * ====================================
 * SISTEMA DE RESERVAS DE SERVICIOS
 * JAVASCRIPT PRINCIPAL - FUNCIONES CORE
 * Archivo: assets/js/main.js
 * Sección 1 de 1 (500 líneas)
 * ====================================
 */

// Configuración global de la aplicación
const AppConfig = {
    API_BASE_URL: '/api',
    GOOGLE_MAPS_API_KEY: 'YOUR_GOOGLE_MAPS_API_KEY',
    MAX_SEARCH_RADIUS: 20, // km
    DEFAULT_SEARCH_RADIUS: 5, // km
    ITEMS_PER_PAGE: 12,
    DEBOUNCE_DELAY: 300, // ms
    ANIMATION_DURATION: 300, // ms
    CACHE_DURATION: 5 * 60 * 1000, // 5 minutos
    RETRY_ATTEMPTS: 3,
    RETRY_DELAY: 1000 // ms
};

// Estado global de la aplicación
const AppState = {
    currentLocation: null,
    currentUser: null,
    searchResults: [],
    currentFilters: {},
    currentPage: 1,
    totalPages: 1,
    loading: false,
    cache: new Map(),
    lastSearchQuery: '',
    selectedService: null
};

// Clase principal de la aplicación
class ReservaFacilApp {
    constructor() {
        this.init();
    }

    // Inicialización de la aplicación
    init() {
        this.bindEvents();
        this.initializeComponents();
        this.loadUserSession();
        this.setupErrorHandling();
        this.setupPerformanceMonitoring();
    }

    // Vincular eventos del DOM
    bindEvents() {
        document.addEventListener('DOMContentLoaded', () => {
            this.setupGeolocation();
            this.setupSearch();
            this.setupFilters();
            this.setupNavigation();
            this.setupAccessibility();
        });

        // Eventos de ventana
        window.addEventListener('resize', this.debounce(this.handleResize.bind(this), 250));
        window.addEventListener('online', this.handleOnline.bind(this));
        window.addEventListener('offline', this.handleOffline.bind(this));
        window.addEventListener('beforeunload', this.handleBeforeUnload.bind(this));
    }

    // Inicializar componentes
    initializeComponents() {
        this.notification = new NotificationManager();
        this.storage = new StorageManager();
        this.api = new ApiManager();
        this.analytics = new AnalyticsManager();
        this.ui = new UIManager();
    }

    // Cargar sesión del usuario
    loadUserSession() {
        const savedUser = this.storage.get('user');
        if (savedUser) {
            AppState.currentUser = savedUser;
            this.updateUserUI();
        }
    }

    // Configurar geolocalización
    setupGeolocation() {
        const locationInput = document.getElementById('location-input');

        if (navigator.geolocation) {
            this.showLocationDetecting();

            navigator.geolocation.getCurrentPosition(
                (position) => this.handleLocationSuccess(position),
                (error) => this.handleLocationError(error),
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 5 * 60 * 1000 // 5 minutos
                }
            );
        } else {
            this.handleLocationNotSupported();
        }
    }

    // Manejar éxito de geolocalización
    async handleLocationSuccess(position) {
        const { latitude: lat, longitude: lng } = position.coords;
        AppState.currentLocation = { lat, lng, accuracy: position.coords.accuracy };

        try {
            const address = await this.reverseGeocode(lat, lng);
            this.updateLocationInput(address);
            await this.loadNearbyServices();
            this.analytics.track('location_detected', { lat, lng, accuracy: position.coords.accuracy });
        } catch (error) {
            console.error('Error procesando ubicación:', error);
            this.updateLocationInput(`${lat.toFixed(4)}, ${lng.toFixed(4)}`);
        }
    }

    // Manejar error de geolocalización
    handleLocationError(error) {
        let message = 'Error detectando ubicación';

        switch (error.code) {
            case error.PERMISSION_DENIED:
                message = 'Permiso de ubicación denegado';
                break;
            case error.POSITION_UNAVAILABLE:
                message = 'Ubicación no disponible';
                break;
            case error.TIMEOUT:
                message = 'Tiempo de espera agotado';
                break;
        }

        console.warn('Error de geolocalización:', message);
        this.handleLocationNotSupported();
        this.analytics.track('location_error', { error: error.code, message });
    }

    // Manejar geolocalización no soportada
    handleLocationNotSupported() {
        const locationInput = document.getElementById('location-input');
        locationInput.placeholder = 'Ingresa tu ubicación';
        this.loadDemoServices();
    }

    // Reverse geocoding
    async reverseGeocode(lat, lng) {
        try {
            // En producción, usar API real de Google Maps o Nominatim
            const cacheKey = `geocode_${lat.toFixed(4)}_${lng.toFixed(4)}`;
            const cached = this.storage.get(cacheKey);

            if (cached && Date.now() - cached.timestamp < AppConfig.CACHE_DURATION) {
                return cached.address;
            }

            // Simulación de geocoding para demo
            await this.delay(1000);
            const mockAddress = this.generateMockAddress(lat, lng);

            this.storage.set(cacheKey, {
                address: mockAddress,
                timestamp: Date.now()
            });

            return mockAddress;
        } catch (error) {
            throw new Error('Error en geocoding: ' + error.message);
        }
    }

    // Generar dirección simulada
    generateMockAddress(lat, lng) {
        const areas = [
            'Buenos Aires, CABA',
            'Córdoba, Córdoba',
            'Rosario, Santa Fe',
            'La Plata, Buenos Aires',
            'San Miguel de Tucumán, Tucumán',
            'Mar del Plata, Buenos Aires',
            'Salta, Salta',
            'Mendoza, Mendoza'
        ];

        // Seleccionar área basada en coordenadas aproximadas
        let areaIndex = Math.floor(Math.abs(lat + lng) * 1000) % areas.length;
        return areas[areaIndex];
    }

    // Configurar búsqueda
    setupSearch() {
        const searchForm = document.getElementById('search-form');
        const searchInput = document.getElementById('service-search');
        const locationInput = document.getElementById('location-input');

        if (searchForm) {
            searchForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.performSearch();
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', this.debounce((e) => {
                this.handleSearchInput(e.target.value);
            }, AppConfig.DEBOUNCE_DELAY));
        }

        if (locationInput) {
            locationInput.addEventListener('change', (e) => {
                this.handleLocationChange(e.target.value);
            });
        }

        // Tags populares
        document.querySelectorAll('.popular-tag').forEach(tag => {
            tag.addEventListener('click', (e) => {
                e.preventDefault();
                this.handlePopularTagClick(tag.textContent.trim());
            });
        });
    }

    // Configurar filtros
    setupFilters() {
        const filtersToggle = document.getElementById('filters-toggle');
        const filtersGrid = document.getElementById('filters-grid');
        const clearFilters = document.getElementById('clear-filters');
        const applyFilters = document.getElementById('apply-filters');

        if (filtersToggle && filtersGrid) {
            filtersToggle.addEventListener('click', () => {
                this.toggleFilters();
            });
        }

        if (clearFilters) {
            clearFilters.addEventListener('click', () => {
                this.clearAllFilters();
            });
        }

        if (applyFilters) {
            applyFilters.addEventListener('click', () => {
                this.applyCurrentFilters();
            });
        }

        // Listeners para filtros individuales
        ['category-filter', 'price-filter', 'rating-filter', 'availability-filter', 'distance-filter', 'sort-filter'].forEach(filterId => {
            const filter = document.getElementById(filterId);
            if (filter) {
                filter.addEventListener('change', () => {
                    this.handleFilterChange(filterId, filter.value);
                });
            }
        });
    }

    // Realizar búsqueda principal
    async performSearch() {
        const searchTerm = document.getElementById('service-search')?.value?.trim();
        const location = document.getElementById('location-input')?.value?.trim();

        if (!this.validateSearchInputs(searchTerm, location)) {
            return;
        }

        try {
            this.setLoadingState(true);
            AppState.lastSearchQuery = searchTerm;

            const searchParams = {
                query: searchTerm,
                location: location,
                lat: AppState.currentLocation?.lat,
                lng: AppState.currentLocation?.lng,
                radius: AppState.currentFilters.distance || AppConfig.DEFAULT_SEARCH_RADIUS,
                ...AppState.currentFilters
            };

            const results = await this.api.searchServices(searchParams);
            await this.displaySearchResults(results);

            this.analytics.track('search_performed', {
                query: searchTerm,
                location: location,
                resultsCount: results.length,
                filters: AppState.currentFilters
            });

        } catch (error) {
            console.error('Error en búsqueda:', error);
            this.handleSearchError(error);
        } finally {
            this.setLoadingState(false);
        }
    }

    // Validar inputs de búsqueda
    validateSearchInputs(searchTerm, location) {
        if (!searchTerm) {
            this.notification.show('Por favor ingresa qué servicio buscas', 'warning');
            document.getElementById('service-search')?.focus();
            return false;
        }

        if (!location) {
            this.notification.show('Por favor ingresa tu ubicación', 'warning');
            document.getElementById('location-input')?.focus();
            return false;
        }

        return true;
    }

    // Cargar servicios cercanos
    async loadNearbyServices() {
        if (!AppState.currentLocation) return;

        try {
            this.showLoadingResults();

            const params = {
                lat: AppState.currentLocation.lat,
                lng: AppState.currentLocation.lng,
                radius: AppConfig.DEFAULT_SEARCH_RADIUS,
                limit: AppConfig.ITEMS_PER_PAGE
            };

            const services = await this.api.getNearbyServices(params);
            await this.displaySearchResults(services);

        } catch (error) {
            console.error('Error cargando servicios cercanos:', error);
            this.loadDemoServices();
        }
    }

    // Cargar servicios de demostración
    loadDemoServices() {
        const demoServices = this.generateDemoServices();
        this.displaySearchResults(demoServices);
    }

    // Generar datos de demostración
    generateDemoServices() {
        const categories = [
            { name: 'Barbería Premium', category: 'belleza', icon: '✂️', business: 'StyleCut', price: 2500, duration: 45, rating: 4.8, reviews: 127 },
            { name: 'Masaje Relajante', category: 'salud', icon: '💆', business: 'Spa Zen', price: 4500, duration: 60, rating: 4.9, reviews: 89 },
            { name: 'Entrenamiento Personal', category: 'fitness', icon: '💪', business: 'FitMax Gym', price: 3500, duration: 90, rating: 4.7, reviews: 203 },
            { name: 'Manicura Completa', category: 'belleza', icon: '💅', business: 'Beauty Nails', price: 1800, duration: 75, rating: 4.6, reviews: 156 },
            { name: 'Consulta Nutricional', category: 'salud', icon: '🥗', business: 'NutriVida', price: 3000, duration: 60, rating: 4.8, reviews: 94 },
            { name: 'Clase de Yoga', category: 'fitness', icon: '🧘', business: 'Yoga Flow', price: 1500, duration: 60, rating: 4.9, reviews: 178 },
            { name: 'Corte y Peinado', category: 'belleza', icon: '💇', business: 'Hair Studio', price: 3200, duration: 90, rating: 4.7, reviews: 245 },
            { name: 'Terapia Holística', category: 'salud', icon: '🌿', business: 'Wellness Center', price: 4000, duration: 75, rating: 4.8, reviews: 112 }
        ];

        return categories.map((service, index) => ({
            id: index + 1,
            uuid: this.generateUUID(),
            ...service,
            distance: (Math.random() * 4 + 0.5).toFixed(1),
            available: Math.random() > 0.3,
            nextAvailable: this.getNextAvailableSlot(),
            images: [`/images/services/${service.category}_${index + 1}.jpg`],
            lat: (AppState.currentLocation?.lat || -34.6037) + (Math.random() - 0.5) * 0.02,
            lng: (AppState.currentLocation?.lng || -58.3816) + (Math.random() - 0.5) * 0.02
        }));
    }

    // Mostrar resultados de búsqueda
    async displaySearchResults(services) {
        AppState.searchResults = services;
        const resultsSection = document.getElementById('results-section');
        const resultsGrid = document.getElementById('results-grid');
        const resultsCount = document.getElementById('results-count');

        if (!resultsSection || !resultsGrid || !resultsCount) return;

        // Actualizar contador
        resultsCount.textContent = `${services.length} resultado${services.length !== 1 ? 's' : ''}`;

        // Mostrar mensaje si no hay resultados
        if (services.length === 0) {
            this.showNoResults();
            return;
        }

        // Generar HTML de tarjetas
        const cardsHTML = services.map(service => this.createServiceCard(service)).join('');
        resultsGrid.innerHTML = cardsHTML;

        // Mostrar sección con animación
        resultsSection.style.display = 'block';
        await this.delay(100);
        resultsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });

        // Lazy load de imágenes
        this.initLazyLoading();
    }

    // Crear tarjeta de servicio
    createServiceCard(service) {
        const starsHTML = this.generateStarsHTML(service.rating);
        const availabilityBadge = service.available
            ? '<span class="availability-badge available">Disponible</span>'
            : `<span class="availability-badge next">Próximo: ${service.nextAvailable}</span>`;

        return `
            <div class="service-card" data-service-id="${service.id}" onclick="app.selectService(${service.id})">
                <div class="service-image" style="background-image: linear-gradient(135deg, #E5E7EB, #D1D5DB);">
                    <div class="service-icon">${service.icon}</div>
                    ${availabilityBadge}
                </div>
                <div class="service-content">
                    <div class="service-header">
                        <div class="service-info">
                            <h3>${service.name}</h3>
                            <div class="service-business">${service.business}</div>
                        </div>
                        <div class="service-price">$${service.price.toLocaleString()}</div>
                    </div>
                    <div class="service-rating">
                        <div class="stars">${starsHTML}</div>
                        <span class="rating-text">${service.rating} (${service.reviews} reseñas)</span>
                    </div>
                    <div class="service-footer">
                        <div class="service-duration">⏱️ ${service.duration} min</div>
                        <div class="service-distance">📍 ${service.distance} km</div>
                    </div>
                </div>
            </div>
        `;
    }

    // Generar HTML de estrellas
    generateStarsHTML(rating) {
        const fullStars = Math.floor(rating);
        const hasHalfStar = rating % 1 >= 0.5;
        const emptyStars = 5 - fullStars - (hasHalfStar ? 1 : 0);

        return '⭐'.repeat(fullStars) +
            (hasHalfStar ? '⭐' : '') +
            '☆'.repeat(emptyStars);
    }

    // Seleccionar servicio
    selectService(serviceId) {
        const service = AppState.searchResults.find(s => s.id === serviceId);
        if (!service) return;

        AppState.selectedService = service;
        this.analytics.track('service_selected', {
            serviceId: service.id,
            serviceName: service.name,
            business: service.business,
            price: service.price
        });

        // Mostrar modal de reserva o redirigir
        this.showBookingModal(service);
    }

    // Mostrar modal de reserva
    showBookingModal(service) {
        const modal = this.ui.createModal({
            title: `Reservar: ${service.name}`,
            content: this.createBookingModalContent(service),
            onConfirm: () => this.processBooking(service),
            confirmText: 'Reservar Ahora',
            cancelText: 'Cancelar'
        });

        modal.show();
    }

    // Crear contenido del modal de reserva
    createBookingModalContent(service) {
        return `
            <div class="booking-modal-content">
                <div class="service-summary">
                    <div class="service-icon">${service.icon}</div>
                    <div>
                        <h4>${service.name}</h4>
                        <p>${service.business}</p>
                        <p class="price">$${service.price.toLocaleString()} - ${service.duration} min</p>
                    </div>
                </div>
                <div class="booking-form">
                    <div class="form-group">
                        <label>Fecha preferida:</label>
                        <input type="date" id="booking-date" min="${new Date().toISOString().split('T')[0]}" required>
                    </div>
                    <div class="form-group">
                        <label>Horario preferido:</label>
                        <select id="booking-time" required>
                            <option value="">Seleccionar horario</option>
                            ${this.generateTimeSlots()}
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Notas especiales (opcional):</label>
                        <textarea id="booking-notes" placeholder="Alguna solicitud especial..."></textarea>
                    </div>
                </div>
            </div>
        `;
    }

    // Generar horarios disponibles
    generateTimeSlots() {
        const slots = [];
        for (let hour = 8; hour <= 20; hour++) {
            for (let minute = 0; minute < 60; minute += 30) {
                const time = `${hour.toString().padStart(2, '0')}:${minute.toString().padStart(2, '0')}`;
                slots.push(`<option value="${time}">${time}</option>`);
            }
        }
        return slots.join('');
    }

    // Procesar reserva
    async processBooking(service) {
        try {
            const bookingData = {
                serviceId: service.id,
                date: document.getElementById('booking-date')?.value,
                time: document.getElementById('booking-time')?.value,
                notes: document.getElementById('booking-notes')?.value
            };

            if (!bookingData.date || !bookingData.time) {
                this.notification.show('Por favor completa la fecha y horario', 'warning');
                return;
            }

            this.setLoadingState(true);

            // En producción, enviar a API real
            await this.delay(2000); // Simular API call

            this.notification.show('¡Reserva confirmada! Te contactaremos pronto.', 'success');
            this.analytics.track('booking_completed', {
                serviceId: service.id,
                date: bookingData.date,
                time: bookingData.time
            });

        } catch (error) {
            console.error('Error procesando reserva:', error);
            this.notification.show('Error al procesar la reserva. Intenta nuevamente.', 'error');
        } finally {
            this.setLoadingState(false);
        }
    }

    // Utilidades
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
    }

    delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            const r = Math.random() * 16 | 0;
            const v = c == 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    getNextAvailableSlot() {
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        const hours = 9 + Math.floor(Math.random() * 10); // 9-19h
        return `${tomorrow.toLocaleDateString()} ${hours}:00`;
    }

    // Estado de carga
    setLoadingState(loading) {
        AppState.loading = loading;
        const searchBtn = document.querySelector('.search-btn');
        if (searchBtn) {
            searchBtn.disabled = loading;
            searchBtn.innerHTML = loading
                ? '<div class="spinner"></div> Buscando...'
                : '🔍 Buscar Servicios';
        }
    }

    // Métodos adicionales (stubs para implementación completa)
    handleSearchInput(value) { /* Implementar autocompletado */ }
    handleLocationChange(value) { /* Validar nueva ubicación */ }
    handlePopularTagClick(tag) {
        document.getElementById('service-search').value = tag;
        this.performSearch();
    }
    toggleFilters() { /* Mostrar/ocultar filtros */ }
    clearAllFilters() { /* Limpiar todos los filtros */ }
    applyCurrentFilters() { /* Aplicar filtros activos */ }
    handleFilterChange(filterId, value) { /* Manejar cambio de filtro */ }
    updateLocationInput(address) {
        const input = document.getElementById('location-input');
        if (input) input.value = address;
    }
    showLocationDetecting() { /* Mostrar estado de detección */ }
    showLoadingResults() { /* Mostrar loader en resultados */ }
    showNoResults() { /* Mostrar mensaje sin resultados */ }
    handleSearchError(error) { /* Manejar errores de búsqueda */ }
    initLazyLoading() { /* Inicializar lazy loading */ }
    setupNavigation() { /* Configurar navegación */ }
    setupAccessibility() { /* Configurar accesibilidad */ }
    handleResize() { /* Manejar redimensionado */ }
    handleOnline() { /* Manejar conexión */ }
    handleOffline() { /* Manejar desconexión */ }
    handleBeforeUnload() { /* Limpiar antes de salir */ }
    updateUserUI() { /* Actualizar interfaz de usuario */ }
    setupErrorHandling() { /* Configurar manejo de errores */ }
    setupPerformanceMonitoring() { /* Configurar monitoreo */ }
}

// Inicializar aplicación
const app = new ReservaFacilApp();

// Exportar para uso global
window.app = app;
window.AppConfig = AppConfig;
window.AppState = AppState;

//< !--
//====================================
//SECCIÓN COMPLETADA: JavaScript Principal
//ARCHIVO: assets/js/main.js
//LÍNEAS: 500
//PROGRESO: 9/41 archivos completados
//FASE 3: Página principal y servicios (2/2)
//====================================
//SEGUIR AQUÍ
//====================================
//-->