<?php
/**
 * Dashboard Administrativo Principal
 * Proyecto de Reservas - Fase 2
 * @version 2.1.0
 */

// Configuración de seguridad
require_once '../config/security.php';
require_once '../config/database.php';

// Verificar autenticación y rol de administrador
SecurityManager::requireAdmin();

// Obtener estadísticas del dashboard
try {
    $db = Database::getInstance();
    
    // Estadísticas generales
    $stats = [
        'total_reservas' => $db->query("SELECT COUNT(*) as count FROM reservas")->fetch()['count'],
        'reservas_hoy' => $db->query("SELECT COUNT(*) as count FROM reservas WHERE DATE(fecha_reserva) = CURDATE()")->fetch()['count'],
        'usuarios_activos' => $db->query("SELECT COUNT(*) as count FROM usuarios WHERE activo = 1")->fetch()['count'],
        'ingresos_mes' => $db->query("SELECT COALESCE(SUM(precio), 0) as total FROM reservas WHERE MONTH(fecha_reserva) = MONTH(CURDATE()) AND YEAR(fecha_reserva) = YEAR(CURDATE()) AND estado = 'confirmada'")->fetch()['total']
    ];
    
    // Reservas recientes
    $stmt = $db->prepare("SELECT r.*, u.nombre as usuario_nombre, s.nombre as servicio_nombre 
                         FROM reservas r 
                         JOIN usuarios u ON r.usuario_id = u.id 
                         JOIN servicios s ON r.servicio_id = s.id 
                         ORDER BY r.fecha_creacion DESC 
                         LIMIT 5");
    $stmt->execute();
    $reservas_recientes = $stmt->fetchAll();
    
    // Datos para gráficos
    $stmt = $db->prepare("SELECT DATE(fecha_reserva) as fecha, COUNT(*) as total 
                         FROM reservas 
                         WHERE fecha_reserva >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) 
                         GROUP BY DATE(fecha_reserva) 
                         ORDER BY fecha_reserva");
    $stmt->execute();
    $datos_grafico = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Error en dashboard: " . $e->getMessage());
    $stats = ['total_reservas' => 0, 'reservas_hoy' => 0, 'usuarios_activos' => 0, 'ingresos_mes' => 0];
    $reservas_recientes = [];
    $datos_grafico = [];
}

$usuario = SecurityManager::getCurrentUser();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Administrativo - Sistema de Reservas</title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/admin-dashboard.css" rel="stylesheet">
    
    <!-- Meta tags de seguridad -->
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="Referrer-Policy" content="strict-origin-when-cross-origin">
</head>
<body class="admin-body">
    
    <!-- Sidebar -->
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-calendar-alt"></i>
            <h4>Admin Panel</h4>
        </div>
        
        <ul class="sidebar-menu">
            <li class="active">
                <a href="dashboard.php">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li>
                <a href="reservas.php">
                    <i class="fas fa-calendar-check"></i>
                    <span>Reservas</span>
                </a>
            </li>
            <li>
                <a href="usuarios.php">
                    <i class="fas fa-users"></i>
                    <span>Usuarios</span>
                </a>
            </li>
            <li>
                <a href="servicios.php">
                    <i class="fas fa-cogs"></i>
                    <span>Servicios</span>
                </a>
            </li>
            <li>
                <a href="reportes.php">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reportes</span>
                </a>
            </li>
            <li>
                <a href="configuracion.php">
                    <i class="fas fa-settings"></i>
                    <span>Configuración</span>
                </a>
            </li>
        </ul>
        
        <div class="sidebar-footer">
            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <span><?= htmlspecialchars($usuario['nombre']) ?></span>
            </div>
            <a href="../api/auth.php?action=logout" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                Cerrar Sesión
            </a>
        </div>
    </nav>
    
    <!-- Main Content -->
    <main class="main-content" id="main-content">
        <!-- Header -->
        <header class="top-header">
            <button class="sidebar-toggle" id="sidebar-toggle">
                <i class="fas fa-bars"></i>
            </button>
            
            <div class="header-actions">
                <div class="notification-bell">
                    <i class="fas fa-bell"></i>
                    <span class="notification-badge">3</span>
                </div>
                
                <div class="user-dropdown">
                    <img src="../assets/img/default-avatar.png" alt="Avatar" class="user-avatar">
                    <span><?= htmlspecialchars($usuario['nombre']) ?></span>
                    <i class="fas fa-chevron-down"></i>
                </div>
            </div>
        </header>
        
        <!-- Dashboard Content -->
        <div class="dashboard-content">
            <!-- Page Title -->
            <div class="page-header">
                <h1><i class="fas fa-home"></i> Dashboard</h1>
                <p>Bienvenido al panel administrativo del sistema de reservas</p>
            </div>
            
            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="stat-card stat-primary">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?= number_format($stats['total_reservas']) ?></h3>
                            <p>Total Reservas</p>
                            <span class="stat-trend">
                                <i class="fas fa-arrow-up"></i> +12%
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="stat-card stat-success">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-day"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?= number_format($stats['reservas_hoy']) ?></h3>
                            <p>Reservas Hoy</p>
                            <span class="stat-trend">
                                <i class="fas fa-arrow-up"></i> +5%
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="stat-card stat-info">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?= number_format($stats['usuarios_activos']) ?></h3>
                            <p>Usuarios Activos</p>
                            <span class="stat-trend">
                                <i class="fas fa-arrow-up"></i> +8%
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="stat-card stat-warning">
                        <div class="stat-icon">
                            <i class="fas fa-dollar-sign"></i>
                        </div>
                        <div class="stat-content">
                            <h3>$<?= number_format($stats['ingresos_mes'], 0) ?></h3>
                            <p>Ingresos del Mes</p>
                            <span class="stat-trend">
                                <i class="fas fa-arrow-up"></i> +15%
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Charts and Tables Row -->
            <div class="row">
                <!-- Chart -->
                <div class="col-lg-8 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-chart-line"></i> Reservas de los Últimos 7 Días</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="reservasChart" width="400" height="150"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="col-lg-4 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-bolt"></i> Acciones Rápidas</h5>
                        </div>
                        <div class="card-body">
                            <div class="quick-actions">
                                <a href="reservas.php?action=create" class="quick-action-btn btn-primary">
                                    <i class="fas fa-plus"></i>
                                    Nueva Reserva
                                </a>
                                <a href="usuarios.php?action=create" class="quick-action-btn btn-success">
                                    <i class="fas fa-user-plus"></i>
                                    Nuevo Usuario
                                </a>
                                <a href="reportes.php" class="quick-action-btn btn-info">
                                    <i class="fas fa-file-alt"></i>
                                    Generar Reporte
                                </a>
                                <a href="configuracion.php" class="quick-action-btn btn-warning">
                                    <i class="fas fa-cog"></i>
                                    Configuración
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Recent Reservations -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5><i class="fas fa-clock"></i> Reservas Recientes</h5>
                            <a href="reservas.php" class="btn btn-sm btn-outline-primary">
                                Ver Todas <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Cliente</th>
                                            <th>Servicio</th>
                                            <th>Fecha</th>
                                            <th>Estado</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($reservas_recientes)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center">
                                                <i class="fas fa-inbox fa-2x text-muted mb-2"></i>
                                                <p class="text-muted">No hay reservas recientes</p>
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach ($reservas_recientes as $reserva): ?>
                                        <tr>
                                            <td>#<?= $reserva['id'] ?></td>
                                            <td>
                                                <i class="fas fa-user text-muted me-1"></i>
                                                <?= htmlspecialchars($reserva['usuario_nombre']) ?>
                                            </td>
                                            <td><?= htmlspecialchars($reserva['servicio_nombre']) ?></td>
                                            <td>
                                                <i class="fas fa-calendar text-muted me-1"></i>
                                                <?= date('d/m/Y H:i', strtotime($reserva['fecha_reserva'])) ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= $reserva['estado'] === 'confirmada' ? 'success' : ($reserva['estado'] === 'pendiente' ? 'warning' : 'danger') ?>">
                                                    <?= ucfirst($reserva['estado']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="reservas.php?action=view&id=<?= $reserva['id'] ?>" 
                                                       class="btn btn-outline-primary" 
                                                       title="Ver detalles">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="reservas.php?action=edit&id=<?= $reserva['id'] ?>" 
                                                       class="btn btn-outline-secondary" 
                                                       title="Editar">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="../assets/js/admin-dashboard.js"></script>
    
    <script>
        // Datos para el gráfico
        const chartData = {
            labels: <?= json_encode(array_column($datos_grafico, 'fecha')) ?>,
            datasets: [{
                label: 'Reservas por Día',
                data: <?= json_encode(array_column($datos_grafico, 'total')) ?>,
                borderColor: '#007bff',
                backgroundColor: 'rgba(0, 123, 255, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4
            }]
        };
        
        // Configuración del gráfico
        const chartConfig = {
            type: 'line',
            data: chartData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
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
        };
        
        // Inicializar gráfico
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('reservasChart').getContext('2d');
            new Chart(ctx, chartConfig);
        });
    </script>
</body>
</html>