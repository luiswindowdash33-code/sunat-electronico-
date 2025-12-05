# Usa una imagen base de PHP con Apache
FROM php:8.2-apache

# 1. Instalación de dependencias del sistema y extensiones de PHP
# Se añaden las librerías de desarrollo de CURL (libcurl4-openssl-dev) y PKG-CONFIG
RUN apt-get update && apt-get install -y \
    libxml2-dev \
    libzip-dev \
    libcurl4-openssl-dev \
    pkg-config \
    # Instala las extensiones de PHP (SOAP, CURL, ZIP, etc.)
    && docker-php-ext-install pdo_mysql soap curl zip \
    # Habilita el módulo rewrite de Apache
    && a2enmod rewrite \
    # Limpieza de caché
    && rm -rf /var/lib/apt/lists/*

# 2. Instalación de Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 3. Copiar la configuración de Apache
# Esto reemplaza la configuración por defecto y le dice a Apache que use la carpeta 'public'
COPY 000-default.conf /etc/apache2/sites-available/000-default.conf

# 4. Copiar el código fuente
# Copia todo tu proyecto al directorio de Apache
COPY . /var/www/html/

# 5. Instalar dependencias de Composer
RUN if [ -f /var/www/html/composer.json ]; then \
    composer install --no-dev --optimize-autoloader; \
    fi

# 6. Permisos de Apache
RUN chown -R www-data:www-data /var/www/html

# El puerto 80 es el puerto HTTP por defecto
EXPOSE 80
