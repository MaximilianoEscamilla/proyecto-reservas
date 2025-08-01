/**
 * Sistema de Formularios Seguros con Validación
 * 
 * @author MaximilianoEscamilla
 * @version 2.0
 */

class SecureFormHandler {
    constructor() {
        this.csrfToken = this.getCSRFToken();
        this.initializeEventListeners();
    }

    // Obtener token CSRF
    getCSRFToken() {
        const tokenElement = document.querySelector('meta[name="csrf-token"]');
        return tokenElement ? tokenElement.getAttribute('content') : '';
    }

    // Inicializar listeners
    initializeEventListeners() {
        // Auto-validación en tiempo real
        document.addEventListener('input', (e) => {
            if (e.target.hasAttribute('data-validate')) {
                this.validateField(e.target);
            }
        });

        // Validación al enviar formularios
        document.addEventListener('submit', (e) => {
            if (e.target.classList.contains('secure-form')) {
                e.preventDefault();
                this.handleFormSubmit(e.target);
            }
        });

        // Mostrar/ocultar contraseña
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('toggle-password')) {
                this.togglePasswordVisibility(e.target);
            }
        });
    }

    // Validar campo individual
    validateField(field) {
        const fieldType = field.getAttribute('data-validate');
        const value = field.value.trim();
        let isValid = true;
        let errorMessage = '';

        // Limpiar errores previos
        this.clearFieldError(field);

        switch (fieldType) {
            case 'email':
                isValid = this.validateEmail(value);
                errorMessage = 'Ingresa un email válido';
                break;

            case 'password':
                const passwordValidation = this.validatePassword(value);
                isValid = passwordValidation.isValid;
                errorMessage = passwordValidation.errors.join(', ');
                break;

            case 'required':
                isValid = value.length > 0;
                errorMessage = 'Este campo es requerido';
                break;

            case 'phone':
                isValid = this.validatePhone(value);
                errorMessage = 'Formato de teléfono inválido';
                break;

            case 'date':
                isValid = this.validateDate(value);
                errorMessage = 'Fecha inválida';
                break;

            case 'name':
                isValid = value.length >= 2 && /^[a-záéíóúñü\s]+$/i.test(value);
                errorMessage = 'El nombre debe tener al menos 2 caracteres y solo letras';
                break;
        }

        if (!isValid && value.length > 0) {
            this.showFieldError(field, errorMessage);
        } else if (isValid && value.length > 0) {
            this.showFieldSuccess(field);
        }

        return isValid;
    }

    // Validaciones específicas
    validateEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    validatePassword(password) {
        const errors = [];
        let isValid = true;

        if (password.length < 8) {
            errors.push('Mínimo 8 caracteres');
            isValid = false;
        }

        if (!/[A-Z]/.test(password)) {
            errors.push('Una mayúscula');
            isValid = false;
        }

        if (!/[a-z]/.test(password)) {
            errors.push('Una minúscula');
            isValid = false;
        }

        if (!/[0-9]/.test(password)) {
            errors.push('Un número');
            isValid = false;
        }

        if (!/[^A-Za-z0-9]/.test(password)) {
            errors.push('Un carácter especial');
            isValid = false;
        }

        return { isValid, errors };
    }

    validatePhone(phone) {
        const phoneRegex = /^[\+]?[0-9\s\-\(\)]{8,15}$/;
        return phoneRegex.test(phone);
    }

    validateDate(date) {
        const dateObj = new Date(date);
        return dateObj instanceof Date && !isNaN(dateObj) && date === dateObj.toISOString().split('T')[0];
    }

    // Manejo visual de errores
    showFieldError(field, message) {
        field.classList.add('is-invalid');
        field.classList.remove('is-valid');

        let errorDiv = field.parentNode.querySelector('.invalid-feedback');
        if (!errorDiv) {
            errorDiv = document.createElement('div');
            errorDiv.className = 'invalid-feedback';
            field.parentNode.appendChild(errorDiv);
        }
        errorDiv.textContent = message;
    }

    showFieldSuccess(field) {
        field.classList.add('is-valid');
        field.classList.remove('is-invalid');

        const errorDiv = field.parentNode.querySelector('.invalid-feedback');
        if (errorDiv) {
            errorDiv.remove();
        }
    }

    clearFieldError(field) {
        field.classList.remove('is-invalid', 'is-valid');
        const errorDiv = field.parentNode.querySelector('.invalid-feedback');
        if (errorDiv) {
            errorDiv.remove();
        }
    }

    // Toggle visibilidad de contraseña
    togglePasswordVisibility(button) {
        const passwordField = button.previousElementSibling;
        const icon = button.querySelector('i');

        if (passwordField.type === 'password') {
            passwordField.type = 'text';
            icon.className = 'fas fa-eye-slash';
        } else {
            passwordField.type = 'password';
            icon.className = 'fas fa-eye';
        }
    }

    // Manejar envío de formulario
    async handleFormSubmit(form) {
        // Validar todos los campos
        const fields = form.querySelectorAll('[data-validate]');
        let isFormValid = true;

        fields.forEach(field => {
            if (!this.validateField(field)) {
                isFormValid = false;
            }
        });

        if (!isFormValid) {
            this.showFormError(form, 'Por favor, corrige los errores antes de continuar');
            return;
        }

        // Mostrar loading
        this.showFormLoading(form, true);

        try {
            // Preparar datos
            const formData = new FormData(form);
            formData.append('csrf_token', this.csrfToken);

            // Enviar request
            const response = await fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-CSRF-TOKEN': this.csrfToken
                }
            });

            const result = await response.json();

            if (result.success) {
                this.showFormSuccess(form, result.message || 'Operación exitosa');

                // Redirigir si es necesario
                if (result.redirect) {
                    setTimeout(() => {
                        window.location.href = result.redirect;
                    }, 1000);
                }
            } else {
                this.showFormError(form, result.errors ? result.errors.join(', ') : 'Error desconocido');
            }

        } catch (error) {
            console.error('Error:', error);
            this.showFormError(form, 'Error de conexión. Intenta nuevamente.');
        } finally {
            this.showFormLoading(form, false);
        }
    }

    // Mostrar estado de carga
    showFormLoading(form, isLoading) {
        const submitButton = form.querySelector('button[type="submit"]');
        const spinner = submitButton.querySelector('.spinner-border');

        if (isLoading) {
            submitButton.disabled = true;
            if (spinner) {
                spinner.style.display = 'inline-block';
            } else {
                submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + submitButton.textContent;
            }
        } else {
            submitButton.disabled = false;
            if (spinner) {
                spinner.remove();
                submitButton.textContent = submitButton.getAttribute('data-original-text') || submitButton.textContent;
            }
        }
    }

    // Mostrar mensaje de error del formulario
    showFormError(form, message) {
        this.showFormMessage(form, message, 'danger');
    }

    // Mostrar mensaje de éxito del formulario
    showFormSuccess(form, message) {
        this.showFormMessage(form, message, 'success');
    }

    // Mostrar mensaje general del formulario
    showFormMessage(form, message, type) {
        // Remover mensajes anteriores
        const existingAlert = form.querySelector('.alert');
        if (existingAlert) {
            existingAlert.remove();
        }

        // Crear nuevo mensaje
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
        alertDiv.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

        // Insertar al inicio del formulario
        form.insertBefore(alertDiv, form.firstChild);

        // Auto-remover después de 5 segundos
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.remove();
            }
        }, 5000);
    }

    // Sanitizar entrada (básico para frontend)
    sanitizeInput(input) {
        return input.trim().replace(/[<>]/g, '');
    }

    // Rate limiting del lado cliente (básico)
    checkClientRateLimit(action, maxAttempts = 5, timeWindow = 300000) {
        const key = `rate_limit_${action}`;
        const now = Date.now();

        let attempts = JSON.parse(localStorage.getItem(key) || '[]');

        // Filtrar intentos antiguos
        attempts = attempts.filter(timestamp => (now - timestamp) < timeWindow);

        if (attempts.length >= maxAttempts) {
            return false;
        }

        attempts.push(now);
        localStorage.setItem(key, JSON.stringify(attempts));

        return true;
    }
}

// Utilidades adicionales
class FormUtils {

    // Generar campo de contraseña con toggle
    static createPasswordField(container, fieldName, placeholder = 'Contraseña') {
        container.innerHTML = `
            <div class="input-group">
                <input type="password" 
                       class="form-control" 
                       name="${fieldName}" 
                       placeholder="${placeholder}"
                       data-validate="password" 
                       required>
                <button class="btn btn-outline-secondary toggle-password" type="button">
                    <i class="fas fa-eye"></i>
                </button>
            </div>
            <div class="password-strength mt-2">
                <div class="progress" style="height: 5px;">
                    <div class="progress-bar" role="progressbar"></div>
                </div>
                <small class="text-muted">Fortaleza de la contraseña</small>
            </div>
        `;
    }

    // Actualizar indicador de fortaleza de contraseña
    static updatePasswordStrength(passwordField) {
        const password = passwordField.value;
        const strengthBar = passwordField.parentNode.parentNode.querySelector('.progress-bar');

        if (!strengthBar) return;

        let strength = 0;
        let color = 'danger';
        let text = 'Muy débil';

        if (password.length >= 8) strength += 20;
        if (/[A-Z]/.test(password)) strength += 20;
        if (/[a-z]/.test(password)) strength += 20;
        if (/[0-9]/.test(password)) strength += 20;
        if (/[^A-Za-z0-9]/.test(password)) strength += 20;

        if (strength >= 80) {
            color = 'success';
            text = 'Muy fuerte';
        } else if (strength >= 60) {
            color = 'info';
            text = 'Fuerte';
        } else if (strength >= 40) {
            color = 'warning';
            text = 'Media';
        } else if (strength >= 20) {
            color = 'danger';
            text = 'Débil';
        }

        strengthBar.style.width = strength + '%';
        strengthBar.className = `progress-bar bg-${color}`;
        strengthBar.parentNode.nextElementSibling.textContent = text;
    }

    // Crear campo con validación en tiempo real
    static createValidatedField(type, name, placeholder, validation) {
        return `
            <div class="mb-3">
                <input type="${type}" 
                       class="form-control" 
                       name="${name}" 
                       placeholder="${placeholder}"
                       data-validate="${validation}" 
                       required>
            </div>
        `;
    }
}

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => {
    const formHandler = new SecureFormHandler();

    // Listener para fortaleza de contraseña
    document.addEventListener('input', (e) => {
        if (e.target.getAttribute('data-validate') === 'password') {
            FormUtils.updatePasswordStrength(e.target);
        }
    });
});

// Exportar para uso en otros módulos
window.SecureFormHandler = SecureFormHandler;
window.FormUtils = FormUtils;