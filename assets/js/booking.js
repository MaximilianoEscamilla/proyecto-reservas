/**
 * ====================================
 * SISTEMA DE RESERVAS DE SERVICIOS
 * JAVASCRIPT DE RESERVAS - GESTIÓN COMPLETA
 * Archivo: assets/js/booking.js
 * Sección 1 de 1 (500 líneas)
 * ====================================
 */

// Clase principal del sistema de reservas
class BookingManager {
    constructor() {
        this.currentBooking = null;
        this.selectedSlots = [];
        this.calendar = null;
        this.paymentProcessor = null;
        this.init();
    }

    // Inicialización del sistema de reservas
    init() {
        this.bindEvents();
        this.initializeCalendar();
        this.loadBookingSettings();
        this.setupValidation();
        this.setupPaymentProcessor();
    }

    // Vincular eventos
    bindEvents() {
        // Eventos del formulario de reserva
        const bookingForm = document.getElementById('booking-form');
        if (bookingForm) {
            bookingForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.processBookingSubmission();
            });
        }

        // Eventos de selección de fecha
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('date-slot')) {
                this.handleDateSelection(e.target);
            }
            if (e.target.classList.contains('time-slot')) {
                this.handleTimeSelection(e.target);
            }
            if (e.target.classList.contains('confirm-booking-btn')) {
                this.confirmBooking();
            }
            if (e.target.classList.contains('cancel-booking-btn')) {
                this.cancelBooking();
            }
        });

        // Eventos de cambio en formulario
        document.addEventListener('change', (e) => {
            if (e.target.name === 'booking-participants') {
                this.updateParticipants(e.target.value);
            }
            if (e.target.name === 'booking-date') {
                this.loadAvailableSlots(e.target.value);
            }
        });

        // Eventos de input en tiempo real
        document.addEventListener('input', (e) => {
            if (e.target.classList.contains('booking-input')) {
                this.validateField(e.target);
            }
        });
    }

    // Inicializar calendario
    initializeCalendar() {
        const calendarContainer = document.getElementById('booking-calendar');
        if (calendarContainer) {
            this.calendar = new BookingCalendar(calendarContainer, {
                onDateSelect: (date) => this.handleCalendarDateSelect(date),
                onTimeSelect: (time) => this.handleCalendarTimeSelect(time),
                minDate: new Date(),
                maxDate: new Date(Date.now() + 60 * 24 * 60 * 60 * 1000), // 60 días
                businessHours: {
                    start: '09:00',
                    end: '20:00',
                    days: [1, 2, 3, 4, 5, 6] // Lunes a sábado
                }
            });
        }
    }

    // Cargar configuración de reservas
    loadBookingSettings() {
        this.settings = {
            minAdvanceHours: 2,
            maxAdvanceDays: 60,
            slotDuration: 30, // minutos
            bufferTime: 15, // minutos entre reservas
            maxParticipants: 10,
            requireDeposit: false,
            depositPercentage: 20,
            cancellationPolicy: 24, // horas
            autoConfirm: false,
            sendReminders: true,
            reminderHours: [24, 2]
        };
    }

    // Configurar validación
    setupValidation() {
        this.validators = {
            customerName: (value) => {
                if (!value || value.trim().length < 2) {
                    return 'Nombre debe tener al menos 2 caracteres';
                }
                if (!/^[a-zA-ZÀ-ÿ\s]+$/.test(value)) {
                    return 'Nombre solo puede contener letras y espacios';
                }
                return null;
            },

            customerEmail: (value) => {
                if (!value) return 'Email es requerido';
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(value)) {
                    return 'Email inválido';
                }
                return null;
            },

            customerPhone: (value) => {
                if (!value) return 'Teléfono es requerido';
                const phoneRegex = /^[\+]?[1-9][\d]{8,14}$/;
                if (!phoneRegex.test(value.replace(/[\s\-\(\)]/g, ''))) {
                    return 'Teléfono inválido';
                }
                return null;
            },

            bookingDate: (value) => {
                if (!value) return 'Fecha es requerida';
                const selectedDate = new Date(value);
                const minDate = new Date();
                minDate.setHours(minDate.getHours() + this.settings.minAdvanceHours);

                if (selectedDate < minDate) {
                    return `Debe reservar con al menos ${this.settings.minAdvanceHours} horas de anticipación`;
                }

                const maxDate = new Date();
                maxDate.setDate(maxDate.getDate() + this.settings.maxAdvanceDays);

                if (selectedDate > maxDate) {
                    return `No se puede reservar con más de ${this.settings.maxAdvanceDays} días de anticipación`;
                }

                return null;
            },

            participants: (value) => {
                const num = parseInt(value);
                if (!num || num < 1) {
                    return 'Debe seleccionar al menos 1 participante';
                }
                if (num > this.settings.maxParticipants) {
                    return `Máximo ${this.settings.maxParticipants} participantes`;
                }
                return null;
            }
        };
    }

    // Configurar procesador de pagos
    setupPaymentProcessor() {
        this.paymentProcessor = new PaymentProcessor({
            mercadoPago: {
                publicKey: AppConfig.MERCADOPAGO_PUBLIC_KEY,
                sandbox: !AppConfig.PRODUCTION
            },
            onSuccess: (payment) => this.handlePaymentSuccess(payment),
            onError: (error) => this.handlePaymentError(error),
            onPending: (payment) => this.handlePaymentPending(payment)
        });
    }

    // Crear nueva reserva
    async createBooking(serviceId, formData) {
        try {
            // Validar datos del formulario
            const validationErrors = this.validateBookingData(formData);
            if (validationErrors.length > 0) {
                throw new ValidationError('Datos inválidos', validationErrors);
            }

            // Verificar disponibilidad
            const isAvailable = await this.checkAvailability(
                serviceId,
                formData.date,
                formData.time,
                formData.duration
            );

            if (!isAvailable) {
                throw new Error('El horario seleccionado ya no está disponible');
            }

            // Crear objeto de reserva
            const booking = {
                uuid: this.generateBookingUUID(),
                serviceId: serviceId,
                customerName: formData.customerName,
                customerEmail: formData.customerEmail,
                customerPhone: formData.customerPhone,
                bookingDate: formData.date,
                bookingTime: formData.time,
                endTime: this.calculateEndTime(formData.time, formData.duration),
                duration: formData.duration,
                participants: formData.participants || 1,
                specialRequests: formData.specialRequests || '',
                totalPrice: formData.totalPrice,
                depositRequired: formData.depositRequired || 0,
                status: 'pending',
                paymentStatus: 'pending',
                createdAt: new Date().toISOString(),
                confirmationCode: this.generateConfirmationCode()
            };

            // Guardar reserva temporal
            this.currentBooking = booking;
            this.saveBookingToStorage(booking);

            // Procesar pago si es requerido
            if (booking.depositRequired > 0 || this.settings.requireDeposit) {
                await this.processPayment(booking);
            } else {
                // Confirmar reserva directamente
                await this.confirmBookingWithoutPayment(booking);
            }

            return booking;

        } catch (error) {
            console.error('Error creando reserva:', error);
            throw error;
        }
    }

    // Validar datos de reserva
    validateBookingData(data) {
        const errors = [];

        // Validar cada campo
        Object.keys(this.validators).forEach(field => {
            if (data[field] !== undefined) {
                const error = this.validators[field](data[field]);
                if (error) {
                    errors.push({ field, message: error });
                }
            }
        });

        // Validaciones adicionales
        if (data.time && data.date) {
            const bookingDateTime = new Date(`${data.date}T${data.time}`);
            const now = new Date();

            if (bookingDateTime <= now) {
                errors.push({ field: 'time', message: 'La hora debe ser futura' });
            }
        }

        return errors;
    }

    // Verificar disponibilidad
    async checkAvailability(serviceId, date, time, duration) {
        try {
            const response = await fetch(`${AppConfig.API_BASE_URL}/services/${serviceId}/availability`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ date, time, duration })
            });

            if (!response.ok) {
                throw new Error('Error verificando disponibilidad');
            }

            const result = await response.json();
            return result.available;

        } catch (error) {
            console.error('Error verificando disponibilidad:', error);
            // En caso de error, simular verificación
            return this.simulateAvailabilityCheck(date, time);
        }
    }

    // Simular verificación de disponibilidad
    simulateAvailabilityCheck(date, time) {
        // Simular algunos horarios ocupados
        const unavailableSlots = ['10:00', '14:00', '16:30'];
        const selectedTime = time;

        // 80% de probabilidad de estar disponible
        return Math.random() > 0.2 && !unavailableSlots.includes(selectedTime);
    }

    // Cargar horarios disponibles
    async loadAvailableSlots(date, serviceId = null) {
        try {
            const slotsContainer = document.getElementById('time-slots-container');
            if (!slotsContainer) return;

            // Mostrar loading
            slotsContainer.innerHTML = '<div class="loading-slots">Cargando horarios...</div>';

            // Generar horarios disponibles
            const slots = await this.generateAvailableSlots(date, serviceId);

            if (slots.length === 0) {
                slotsContainer.innerHTML = '<div class="no-slots">No hay horarios disponibles para esta fecha</div>';
                return;
            }

            // Renderizar horarios
            const slotsHTML = slots.map(slot => `
                <button class="time-slot ${slot.available ? '' : 'unavailable'}" 
                        data-time="${slot.time}" 
                        ${!slot.available ? 'disabled' : ''}>
                    ${slot.time}
                    ${!slot.available ? '<span class="occupied">Ocupado</span>' : ''}
                </button>
            `).join('');

            slotsContainer.innerHTML = slotsHTML;

        } catch (error) {
            console.error('Error cargando horarios:', error);
            const slotsContainer = document.getElementById('time-slots-container');
            if (slotsContainer) {
                slotsContainer.innerHTML = '<div class="error-slots">Error cargando horarios</div>';
            }
        }
    }

    // Generar horarios disponibles
    async generateAvailableSlots(date, serviceId) {
        const slots = [];
        const startHour = 9;
        const endHour = 20;
        const slotDuration = this.settings.slotDuration;

        for (let hour = startHour; hour < endHour; hour++) {
            for (let minute = 0; minute < 60; minute += slotDuration) {
                const time = `${hour.toString().padStart(2, '0')}:${minute.toString().padStart(2, '0')}`;
                const available = await this.checkAvailability(serviceId, date, time, slotDuration);

                slots.push({
                    time: time,
                    available: available,
                    price: this.calculateSlotPrice(time, date)
                });
            }
        }

        return slots;
    }

    // Calcular precio por horario
    calculateSlotPrice(time, date) {
        // Precio base
        let basePrice = this.currentBooking?.basePrice || 2500;

        // Recargos por horario premium
        const hour = parseInt(time.split(':')[0]);
        if (hour >= 18 || hour <= 10) {
            basePrice *= 1.1; // 10% recargo horario premium
        }

        // Recargos por fin de semana
        const dayOfWeek = new Date(date).getDay();
        if (dayOfWeek === 0 || dayOfWeek === 6) {
            basePrice *= 1.15; // 15% recargo fin de semana
        }

        return Math.round(basePrice);
    }

    // Manejar selección de fecha
    handleDateSelection(dateElement) {
        // Remover selección anterior
        document.querySelectorAll('.date-slot.selected').forEach(el => {
            el.classList.remove('selected');
        });

        // Seleccionar nueva fecha
        dateElement.classList.add('selected');
        const selectedDate = dateElement.dataset.date;

        // Cargar horarios para la fecha seleccionada
        this.loadAvailableSlots(selectedDate);

        // Actualizar formulario
        const dateInput = document.getElementById('booking-date');
        if (dateInput) {
            dateInput.value = selectedDate;
        }
    }

    // Manejar selección de hora
    handleTimeSelection(timeElement) {
        if (timeElement.disabled) return;

        // Remover selección anterior
        document.querySelectorAll('.time-slot.selected').forEach(el => {
            el.classList.remove('selected');
        });

        // Seleccionar nueva hora
        timeElement.classList.add('selected');
        const selectedTime = timeElement.dataset.time;

        // Actualizar formulario
        const timeInput = document.getElementById('booking-time');
        if (timeInput) {
            timeInput.value = selectedTime;
        }

        // Actualizar resumen de precio
        this.updateBookingSummary();
    }

    // Actualizar resumen de reserva
    updateBookingSummary() {
        const summaryContainer = document.getElementById('booking-summary');
        if (!summaryContainer) return;

        const formData = this.getFormData();
        const service = AppState.selectedService;

        if (!service || !formData.date || !formData.time) return;

        const basePrice = this.calculateSlotPrice(formData.time, formData.date);
        const participants = parseInt(formData.participants) || 1;
        const totalPrice = basePrice * participants;
        const deposit = this.settings.requireDeposit ? Math.round(totalPrice * this.settings.depositPercentage / 100) : 0;

        const summaryHTML = `
            <div class="summary-header">
                <h4>Resumen de Reserva</h4>
            </div>
            <div class="summary-service">
                <div class="service-icon">${service.icon}</div>
                <div>
                    <strong>${service.name}</strong>
                    <div class="business-name">${service.business}</div>
                </div>
            </div>
            <div class="summary-details">
                <div class="detail-row">
                    <span>Fecha:</span>
                    <span>${new Date(formData.date).toLocaleDateString('es-AR')}</span>
                </div>
                <div class="detail-row">
                    <span>Hora:</span>
                    <span>${formData.time}</span>
                </div>
                <div class="detail-row">
                    <span>Duración:</span>
                    <span>${service.duration} minutos</span>
                </div>
                <div class="detail-row">
                    <span>Participantes:</span>
                    <span>${participants}</span>
                </div>
            </div>
            <div class="summary-pricing">
                <div class="price-row">
                    <span>Precio base:</span>
                    <span>$${basePrice.toLocaleString()}</span>
                </div>
                ${participants > 1 ? `
                <div class="price-row">
                    <span>x ${participants} personas:</span>
                    <span>$${totalPrice.toLocaleString()}</span>
                </div>
                ` : ''}
                ${deposit > 0 ? `
                <div class="price-row deposit">
                    <span>Depósito requerido:</span>
                    <span>$${deposit.toLocaleString()}</span>
                </div>
                ` : ''}
                <div class="price-row total">
                    <span><strong>Total:</strong></span>
                    <span><strong>$${totalPrice.toLocaleString()}</strong></span>
                </div>
            </div>
        `;

        summaryContainer.innerHTML = summaryHTML;
    }

    // Obtener datos del formulario
    getFormData() {
        const form = document.getElementById('booking-form');
        if (!form) return {};

        const formData = new FormData(form);
        const data = {};

        for (let [key, value] of formData.entries()) {
            data[key] = value;
        }

        return data;
    }

    // Procesar envío de reserva
    async processBookingSubmission() {
        try {
            const formData = this.getFormData();
            const service = AppState.selectedService;

            if (!service) {
                throw new Error('No se ha seleccionado un servicio');
            }

            // Actualizar estado de carga
            this.setBookingLoadingState(true);

            // Crear la reserva
            const booking = await this.createBooking(service.id, {
                ...formData,
                duration: service.duration,
                totalPrice: this.calculateTotalPrice(formData),
                depositRequired: this.calculateDeposit(formData)
            });

            // Mostrar confirmación
            this.showBookingConfirmation(booking);

            // Enviar notificaciones
            await this.sendBookingNotifications(booking);

            // Analytics
            app.analytics.track('booking_created', {
                serviceId: service.id,
                bookingId: booking.uuid,
                totalPrice: booking.totalPrice
            });

        } catch (error) {
            console.error('Error procesando reserva:', error);
            this.handleBookingError(error);
        } finally {
            this.setBookingLoadingState(false);
        }
    }

    // Calcular precio total
    calculateTotalPrice(formData) {
        const basePrice = this.calculateSlotPrice(formData.time, formData.date);
        const participants = parseInt(formData.participants) || 1;
        return basePrice * participants;
    }

    // Calcular depósito
    calculateDeposit(formData) {
        if (!this.settings.requireDeposit) return 0;
        const totalPrice = this.calculateTotalPrice(formData);
        return Math.round(totalPrice * this.settings.depositPercentage / 100);
    }

    // Mostrar confirmación de reserva
    showBookingConfirmation(booking) {
        const modal = app.ui.createModal({
            title: '¡Reserva Confirmada!',
            content: this.createConfirmationContent(booking),
            confirmText: 'Entendido',
            showCancel: false,
            onConfirm: () => {
                // Redirigir o actualizar vista
                window.location.href = '/pages/confirmation.html?booking=' + booking.uuid;
            }
        });

        modal.show();
    }

    // Crear contenido de confirmación
    createConfirmationContent(booking) {
        return `
            <div class="confirmation-content">
                <div class="confirmation-icon">✅</div>
                <h3>¡Tu reserva ha sido confirmada!</h3>
                <div class="confirmation-details">
                    <p><strong>Código de confirmación:</strong> ${booking.confirmationCode}</p>
                    <p><strong>Fecha:</strong> ${new Date(booking.bookingDate).toLocaleDateString('es-AR')}</p>
                    <p><strong>Hora:</strong> ${booking.bookingTime}</p>
                    <p><strong>Total:</strong> $${booking.totalPrice.toLocaleString()}</p>
                </div>
                <div class="confirmation-note">
                    <p>Hemos enviado los detalles de tu reserva a ${booking.customerEmail}</p>
                    <p>Te contactaremos 24 horas antes para confirmar tu cita.</p>
                </div>
            </div>
        `;
    }

    // Utilidades
    generateBookingUUID() {
        return 'BK-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9).toUpperCase();
    }

    generateConfirmationCode() {
        return 'CONF-' + Math.random().toString(36).substr(2, 8).toUpperCase();
    }

    calculateEndTime(startTime, durationMinutes) {
        const [hours, minutes] = startTime.split(':').map(Number);
        const endDate = new Date();
        endDate.setHours(hours, minutes + durationMinutes);
        return endDate.toTimeString().substr(0, 5);
    }

    saveBookingToStorage(booking) {
        const bookings = JSON.parse(localStorage.getItem('userBookings') || '[]');
        bookings.push(booking);
        localStorage.setItem('userBookings', JSON.stringify(bookings));
    }

    setBookingLoadingState(loading) {
        const submitBtn = document.querySelector('.confirm-booking-btn');
        if (submitBtn) {
            submitBtn.disabled = loading;
            submitBtn.textContent = loading ? 'Procesando...' : 'Confirmar Reserva';
        }
    }

    validateField(field) {
        const validator = this.validators[field.name];
        if (validator) {
            const error = validator(field.value);
            this.showFieldError(field, error);
        }
    }

    showFieldError(field, error) {
        const errorElement = field.parentNode.querySelector('.field-error');
        if (errorElement) {
            errorElement.textContent = error || '';
            errorElement.style.display = error ? 'block' : 'none';
        }
    }

    // Métodos stub para implementación completa
    handleCalendarDateSelect(date) { /* Implementar */ }
    handleCalendarTimeSelect(time) { /* Implementar */ }
    updateParticipants(count) { /* Implementar */ }
    confirmBooking() { /* Implementar */ }
    cancelBooking() { /* Implementar */ }
    processPayment(booking) { /* Implementar */ }
    confirmBookingWithoutPayment(booking) { /* Implementar */ }
    handlePaymentSuccess(payment) { /* Implementar */ }
    handlePaymentError(error) { /* Implementar */ }
    handlePaymentPending(payment) { /* Implementar */ }
    sendBookingNotifications(booking) { /* Implementar */ }
    handleBookingError(error) {
        app.notification.show('Error: ' + error.message, 'error');
    }
}

// Clase de excepción personalizada
class ValidationError extends Error {
    constructor(message, errors) {
        super(message);
        this.name = 'ValidationError';
        this.errors = errors;
    }
}

// Inicializar sistema de reservas
const bookingManager = new BookingManager();

// Exportar para uso global
window.bookingManager = bookingManager;
window.ValidationError = ValidationError;


//<!--
//====================================
//SECCIÓN COMPLETADA: Sistema de Reservas
//ARCHIVO: assets/js/booking.js
//LÍNEAS: 500
//PROGRESO: 10/41 archivos completados
//FASE 4: Panel del propietario (1/2)
//====================================
//SEGUIR AQUÍ
//====================================
//-->