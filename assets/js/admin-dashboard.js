/**
 * Admin Dashboard JavaScript
 * Proyecto de Reservas - Fase 2
 * @version 2.1.0
 */

'use strict';

class AdminDashboard {
    constructor() {
        this.sidebar = document.getElementById('sidebar');
        this.sidebarToggle = document.getElementById('sidebar-toggle');
        this.mainContent = document.getElementById('main-content');
        this.isMobile = window.innerWidth <= 768;

        this.init();
    }

    init() {
        this.setupEventListeners();
        this.setupResponsive();
        this.setupAnimations();
        this.setupNotifications();
        this.setupTooltips();
        this.loadDashboardData();
    }

    setupEventListeners() {
        // Sidebar toggle
        this.sidebarToggle?.addEventListener('click', () => {
            this.toggleSidebar();
        });

        // Click outside sidebar on mobile
        document.addEventListener('click', (e) => {
            if (this.isMobile && this.sidebar?.classList.contains('show')) {
                if (!this.sidebar.contains(e.target) && !this.sidebarToggle.contains(e.target)) {
                    this.hideSidebar();
                }
            }
        });

        // Resize window
        window.addEventListener('resize', () => {
            this.handleResize();
        });

        // Navigation links
        this.setupNavigation();

        // Quick action buttons
        this.setupQuickActions();

        // Table actions
        this.setupTableActions();
    }

    toggleSidebar() {
        if (this.isMobile) {
            this.sidebar?.classList.toggle('show');
        } else {
            this.sidebar?.classList.toggle('collapsed');
        }
    }

    hideSidebar() {
        if (this.isMobile) {
            this.sidebar?.classList.remove('show');
        }
    }

    handleResize() {
        const wasMobile = this.isMobile;
        this.isMobile = window.innerWidth <= 768;

        if (wasMobile !== this.isMobile) {
            this.setupResponsive();
        }
    }

    setupResponsive() {
        if (this.isMobile) {
            this.sidebar?.classList.remove('collapsed');
            this.sidebar?.classList.remove('show');
        } else {
            this.sidebar?.classList.remove('show');
        }
    }

    setupNavigation() {
        const navLinks = document.querySelectorAll('.sidebar-menu a');

        navLinks.forEach(link => {
            link.addEventListener('click', (e) => {
                // Remove active class from all links
                navLinks.forEach(l => l.parentElement.classList.remove('active'));

                // Add active class to clicked link
                e.target.closest('li').classList.add('active');

                // Hide sidebar on mobile after navigation
                if (this.isMobile) {
                    this.hideSidebar();
                }
            });
        });
    }

    setupQuickActions() {
        const quickActionBtns = document.querySelectorAll('.quick-action-btn');

        quickActionBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                // Add loading state
                this.addLoadingState(btn);

                // Simulate loading (remove this in production)
                setTimeout(() => {
                    this.removeLoadingState(btn);
                }, 1000);
            });
        });
    }

    setupTableActions() {
        const tableActionBtns = document.querySelectorAll('.btn-group .btn');

        tableActionBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                if (btn.title === 'Ver detalles') {
                    this.showReservaDetails(e);
                } else if (btn.title === 'Editar') {
                    this.editReserva(e);
                }
            });
        });
    }

    setupAnimations() {
        // Add fade-in animation to cards
        const cards = document.querySelectorAll('.card, .stat-card');

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('fade-in-up');
                }
            });
        }, {
            threshold: 0.1
        });

        cards.forEach(card => {
            observer.observe(card);
        });
    }

    setupNotifications() {
        const notificationBell = document.querySelector('.notification-bell');

        notificationBell?.addEventListener('click', () => {
            this.showNotifications();
        });

        // Auto-refresh notifications every 30 seconds
        setInterval(() => {
            this.refreshNotifications();
        }, 30000);
    }

    setupTooltips() {
        // Initialize Bootstrap tooltips
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }

    loadDashboardData() {
        this.refreshStats();
        this.loadRecentActivity();
        this.updateChart();
    }

    refreshStats() {
        const statCards = document.querySelectorAll('.stat-card');

        // Simulate real-time updates
        statCards.forEach((card, index) => {
            const statNumber = card.querySelector('h3');
            if (statNumber) {
                this.animateCounter(statNumber, parseInt(statNumber.textContent.replace(/[^\d]/g, '')));
            }
        });
    }

    animateCounter(element, target) {
        let current = 0;
        const increment = target / 50;
        const timer = setInterval(() => {
            current += increment;
            if (current >= target) {
                current = target;
                clearInterval(timer);
            }

            if (element.textContent.includes('$')) {
                element.textContent = '$' + Math.floor(current).toLocaleString();
            } else {
                element.textContent = Math.floor(current).toLocaleString();
            }
        }, 20);
    }

    loadRecentActivity() {
        // This would typically fetch from an API
        console.log('Loading recent activity...');
    }

    updateChart() {
        // Chart updates are handled by Chart.js configuration in the HTML
        console.log('Chart updated');
    }

    showNotifications() {
        const notifications = [
            {
                id: 1,
                title: 'Nueva reserva',
                message: 'Juan Pérez ha realizado una nueva reserva',
                time: 'Hace 5 minutos',
                type: 'info'
            },
            {
                id: 2,
                title: 'Reserva cancelada',
                message: 'La reserva #1234 ha sido cancelada',
                time: 'Hace 15 minutos',
                type: 'warning'
            },
            {
                id: 3,
                title: 'Pago recibido',
                message: 'Pago de $150 recibido para reserva #1235',
                time: 'Hace 30 minutos',
                type: 'success'
            }
        ];

        this.createNotificationModal(notifications);
    }

    createNotificationModal(notifications) {
        const modalHTML = `
            <div class="modal fade" id="notificationModal" tabindex="-1">
                <div class="modal-dialog modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-bell"></i> Notificaciones
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            ${notifications.map(notification => `
                                <div class="notification-item">
                                    <div class="notification-icon notification-${notification.type}">
                                        <i class="fas fa-${this.getNotificationIcon(notification.type)}"></i>
                                    </div>
                                    <div class="notification-content">
                                        <h6>${notification.title}</h6>
                                        <p>${notification.message}</p>
                                        <small class="text-muted">${notification.time}</small>
                                    </div>
                                    <button class="notification-close" onclick="adminDashboard.removeNotification(${notification.id})">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            `).join('')}
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-primary">Ver todas</button>
                            <button type="button" class="btn btn-primary" onclick="adminDashboard.markAllAsRead()">
                                Marcar como leídas
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // Remove existing modal
        const existingModal = document.getElementById('notificationModal');
        if (existingModal) {
            existingModal.remove();
        }

        // Add new modal
        document.body.insertAdjacentHTML('beforeend', modalHTML);

        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('notificationModal'));
        modal.show();
    }

    getNotificationIcon(type) {
        const icons = {
            info: 'info-circle',
            warning: 'exclamation-triangle',
            success: 'check-circle',
            error: 'times-circle'
        };
        return icons[type] || 'bell';
    }

    removeNotification(id) {
        const notification = document.querySelector(`[data-notification-id="${id}"]`);
        if (notification) {
            notification.style.opacity = '0';
            setTimeout(() => {
                notification.remove();
            }, 300);
        }
    }

    markAllAsRead() {
        const notificationBadge = document.querySelector('.notification-badge');
        if (notificationBadge) {
            notificationBadge.style.opacity = '0';
            setTimeout(() => {
                notificationBadge.remove();
            }, 300);
        }

        this.showToast('Todas las notificaciones han sido marcadas como leídas', 'success');
    }

    refreshNotifications() {
        // Simulate new notifications
        const badge = document.querySelector('.notification-badge');
        if (badge && Math.random() > 0.7) {
            const currentCount = parseInt(badge.textContent) || 0;
            badge.textContent = currentCount + 1;

            // Animate badge
            badge.style.transform = 'scale(1.2)';
            setTimeout(() => {
                badge.style.transform = 'scale(1)';
            }, 200);
        }
    }

    showReservaDetails(e) {
        e.preventDefault();
        const reservaId = this.getReservaIdFromButton(e.target);

        // Simulate loading reservation details
        this.showToast(`Cargando detalles de la reserva #${reservaId}...`, 'info');

        // In a real application, you would fetch the data and show a modal
        setTimeout(() => {
            this.showToast(`Detalles de la reserva #${reservaId} cargados`, 'success');
        }, 1000);
    }

    editReserva(e) {
        e.preventDefault();
        const reservaId = this.getReservaIdFromButton(e.target);

        this.showToast(`Redirigiendo al editor para la reserva #${reservaId}...`, 'info');
    }

    getReservaIdFromButton(button) {
        const href = button.closest('a')?.href || '';
        const match = href.match(/id=(\d+)/);
        return match ? match[1] : '000';
    }

    addLoadingState(button) {
        const originalText = button.innerHTML;
        button.dataset.originalText = originalText;
        button.innerHTML = '<span class="loading"></span> Cargando...';
        button.disabled = true;
    }

    removeLoadingState(button) {
        button.innerHTML = button.dataset.originalText;
        button.disabled = false;
    }

    showToast(message, type = 'info') {
        const toastId = 'toast-' + Date.now();
        const toastHTML = `
            <div class="toast align-items-center text-white bg-${type} border-0" role="alert" id="${toastId}">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas fa-${this.getNotificationIcon(type)} me-2"></i>
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        `;

        // Create toast container if it doesn't exist
        let toastContainer = document.querySelector('.toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.className = 'toast-container position-fixed bottom-0 end-0 p-3';
            document.body.appendChild(toastContainer);
        }

        // Add toast
        toastContainer.insertAdjacentHTML('beforeend', toastHTML);

        // Show toast
        const toastElement = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastElement, { delay: 3000 });
        toast.show();

        // Remove toast after it's hidden
        toastElement.addEventListener('hidden.bs.toast', () => {
            toastElement.remove();
        });
    }

    // Utility functions
    formatCurrency(amount) {
        return new Intl.NumberFormat('es-AR', {
            style: 'currency',
            currency: 'ARS'
        }).format(amount);
    }

    formatDate(date) {
        return new Intl.DateTimeFormat('es-AR', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        }).format(new Date(date));
    }

    // API methods (to be implemented with real endpoints)
    async fetchDashboardStats() {
        try {
            const response = await fetch('/api/dashboard/stats', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error('Error fetching dashboard stats');
            }

            return await response.json();
        } catch (error) {
            console.error('Error:', error);
            this.showToast('Error al cargar las estadísticas', 'error');
        }
    }

    async fetchRecentReservations() {
        try {
            const response = await fetch('/api/reservations/recent', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error('Error fetching recent reservations');
            }

            return await response.json();
        } catch (error) {
            console.error('Error:', error);
            this.showToast('Error al cargar las reservas recientes', 'error');
        }
    }
}

// Initialize dashboard when DOM is loaded
document.addEventListener('DOMContentLoaded', function () {
    window.adminDashboard = new AdminDashboard();
});

// Additional styles for notifications and toasts
const additionalStyles = `
<style>
.notification-item {
    display: flex;
    align-items: flex-start;
    padding: 15px;
    border-bottom: 1px solid #e9ecef;
    transition: background-color 0.2s;
}

.notification-item:hover {
    background-color: #f8f9fa;
}

.notification-item:last-child {
    border-bottom: none;
}

.notification-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 15px;
    color: white;
}

.notification-info { background: var(--info-color); }
.notification-warning { background: var(--warning-color); }
.notification-success { background: var(--success-color); }
.notification-error { background: var(--danger-color); }

.notification-content {
    flex: 1;
}

.notification-content h6 {
    margin: 0 0 5px 0;
    font-weight: 600;
}

.notification-content p {
    margin: 0 0 5px 0;
    color: #6c757d;
}

.notification-close {
    background: none;
    border: none;
    color: #6c757d;
    cursor: pointer;
    padding: 5px;
    border-radius: 50%;
    transition: all 0.2s;
}

.notification-close:hover {
    background: #e9ecef;
    color: #495057;
}

.toast-container {
    z-index: 1060;
}
</style>
`;

// Inject additional styles
document.head.insertAdjacentHTML('beforeend', additionalStyles);