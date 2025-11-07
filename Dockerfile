# Usa la imagen base de PHP con Apache
FROM php:8.2-apache

# Copia el binario de Composer desde su imagen oficial
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 1. Instalar dependencias del sistema y librerías de desarrollo
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    libzip-dev \
    libxml2-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    default-mysql-client \
    # Limpieza para reducir el tamaño de la imagen
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# 2. Instalar y configurar extensiones PHP
# Esenciales para bases de datos (mysqli) y Composer/manejo de archivos (zip)
RUN docker-php-ext-install -j$(nproc) mysqli pdo pdo_mysql zip

# Configurar e instalar la extensión GD con soporte para FreeType y JPEG
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd

# 3. Copiar el código fuente
WORKDIR /var/www/html

# Copiar archivos de configuración antes de copiar la aplicación
# Esto mejora el caching de Docker si solo cambian los archivos de configuración
COPY apache-config.conf /etc/apache2/sites-enabled/000-default.conf

# Copiar la aplicación
COPY . /var/www/html

# 4. Configurar permisos y dependencias
# Permisos para el usuario 'www-data' de Apache sobre el directorio principal
RUN chown -R www-data:www-data /var/www/html

# Instalar dependencias de Composer (si tienes un archivo composer.json)
# Se asume que no quieres instalar dependencias de desarrollo (--no-dev)
# Asegúrate de que tu archivo composer.json esté en el directorio raíz de tu proyecto.
RUN composer install --no-dev --optimize-autoloader

# 5. Configuración final
# Expone el puerto por defecto de Apache
EXPOSE 80

# Comando para forzar el inicio de Apache en primer plano
CMD ["/usr/sbin/apache2ctl", "-D", "FOREGROUND"]
