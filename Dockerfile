# Usa una imagen oficial de PHP con Apache
FROM php:8.2-apache

# Instala Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# --- PASO 1: INSTALAR DEPENDENCIAS DEL SISTEMA ---
RUN apt-get update && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    libzip-dev \
    libxml2-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    default-mysql-client \
    # Limpieza inmediata de cache
    && rm -rf /var/lib/apt/lists/*

# --- PASO 2: CONFIGURAR E INSTALAR EXTENSIONES DE PHP ---
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd soap zip intl mysqli pdo_mysql
    # Usamos -j$(nproc) para aprovechar múltiples núcleos en la compilación si es posible.

# Establece el directorio de trabajo
WORKDIR /var/www/html

# Copia los archivos de tu proyecto al contenedor
COPY . .

# Instala las dependencias de PHP (composer)
RUN composer install --no-dev --optimize-autoloader

# Copia el archivo de configuración personalizado de Apache
COPY apache-config.conf /etc/apache2/sites-available/000-default.conf

# Asegúrate de que el servidor web (www-data) tenga permisos
RUN chown -R www-data:www-data /var/www/html

# Exponer el puerto 80
EXPOSE 80

# Comando de inicio para forzar el inicio de Apache
CMD ["/usr/sbin/apache2ctl", "-D", "FOREGROUND"]
