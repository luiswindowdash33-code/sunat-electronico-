# Usa una imagen base de PHP con Apache
FROM php:8.2-apache

# 1. Instalación de dependencias del sistema y extensiones de PHP
# Greenter requiere extensiones como SOAP, CURL y ZIP.
RUN apt-get update && apt-get install -y \
    libxml2-dev \
    libzip-dev \
    # Instala las extensiones de PHP esenciales
    && docker-php-ext-install pdo_mysql soap curl zip \
    # Habilita el módulo rewrite de Apache (necesario si usas .htaccess)
    && a2enmod rewrite \
    # Limpieza de caché para optimizar el tamaño
    && rm -rf /var/lib/apt/lists/*

# 2. Instalación de Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 3. Copiar el código fuente
# Copia todo el contenido de tu proyecto a la raíz de Apache
COPY . /var/www/html/

# 4. Instalar dependencias de Composer
# Esto instala Greenter y sus dependencias en la carpeta 'vendor/'
RUN if [ -f /var/www/html/composer.json ]; then \
    composer install --no-dev --optimize-autoloader; \
    fi

# 5. Permisos de Apache
# Asegura que Apache pueda leer y ejecutar tus scripts
RUN chown -R www-data:www-data /var/www/html

# El puerto 80 es el puerto HTTP por defecto
EXPOSE 80
