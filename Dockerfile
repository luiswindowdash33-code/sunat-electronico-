# Usa una imagen base de PHP con Apache
FROM php:8.2-apache

# 1. Instalación de dependencias del sistema y extensiones de PHP
# **ATENCIÓN: Se añade libcurl4-openssl-dev para resolver el error.**
RUN apt-get update && apt-get install -y \
    libxml2-dev \
    libzip-dev \
    libcurl4-openssl-dev \
    # Compiladores necesarios para todas las extensiones (a veces también falta)
    pkg-config \
    # Ejecuta la instalación de extensiones de PHP
    && docker-php-ext-install pdo_mysql soap curl zip \
    # Habilita el módulo rewrite de Apache
    && a2enmod rewrite \
    # Limpieza de caché
    && rm -rf /var/lib/apt/lists/*

# 2. Instalación de Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 3. Copiar el código fuente
COPY . /var/www/html/

# 4. Instalar dependencias de Composer
RUN if [ -f /var/www/html/composer.json ]; then \
    composer install --no-dev --optimize-autoloader; \
    fi

# 5. Permisos de Apache
RUN chown -R www-data:www-data /var/www/html

# El puerto 80 es el puerto HTTP por defecto
EXPOSE 80
