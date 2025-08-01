# 🏨 Sistema de Reservas - v2.0

## 📋 Descripción

Sistema completo de gestión de reservas desarrollado con PHP, JavaScript y MySQL. Incluye autenticación segura, panel de administración, API RESTful y interfaz moderna responsive.

## ✨ Características Principales

### 🔒 Seguridad
- ✅ Autenticación robusta con sesiones seguras
- ✅ Protección CSRF en todos los formularios
- ✅ Validación y sanitización de datos
- ✅ Prepared statements para prevenir SQL injection
- ✅ Hash seguro de contraseñas (Argon2ID)
- ✅ Rate limiting para prevenir ataques de fuerza bruta
- ✅ Headers de seguridad HTTP

### 🎨 Interfaz de Usuario
- ✅ Diseño responsive (móvil, tablet, desktop)
- ✅ Validación en tiempo real de formularios
- ✅ Interfaz moderna con Bootstrap 5
- ✅ Indicadores de carga y feedback visual
- ✅ Experiencia de usuario optimizada

### ⚡ Performance
- ✅ Sistema de caché integrado
- ✅ Consultas optimizadas a base de datos
- ✅ Compresión de assets (CSS/JS)
- ✅ Lazy loading de imágenes

### 📊 Funcionalidades
- ✅ Gestión completa de reservas
- ✅ Panel de administración
- ✅ Sistema de usuarios y roles
- ✅ Reportes y estadísticas
- ✅ Notificaciones por email
- ✅ API RESTful documentada
- ✅ Calendario interactivo
- ✅ Gestión de servicios

## 🛠️ Tecnologías Utilizadas

### Backend
- **PHP 8.0+** - Lenguaje principal
- **MySQL** - Base de datos
- **Composer** - Gestión de dependencias
- **PHPMailer** - Envío de emails
- **JWT** - Tokens de autenticación

### Frontend
- **HTML5** - Estructura
- **CSS3** - Estilos (Bootstrap 5)
- **JavaScript ES6+** - Interactividad
- **Font Awesome** - Iconografía

### Herramientas
- **Git** - Control de versiones
- **PHPUnit** - Testing
- **PHPStan** - Análisis estático
- **Apache** - Servidor web

## 📦 Instalación

### Requisitos Previos
- PHP 8.0 o superior
- MySQL 5.7 o superior
- Composer
- Servidor web (Apache/Nginx)

### Pasos de Instalación

1. **Clonar el repositorio**
```bash
git clone https://github.com/MaximilianoEscamilla/proyecto-reservas.git
cd proyecto-reservas