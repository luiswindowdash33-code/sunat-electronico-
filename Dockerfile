# Usa la imagen base de PHP con Apache
FROM php:8.2-apache

# Copia el binario de Composer desde su imagen oficial (Multi-stage build)
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
# ✅ CRÍTICO: Se añade 'zip' para ZipArchive (Greenter)
RUN docker-php-ext-install -j$(nproc) mysqli pdo pdo_mysql zip

# Configurar e instalar la extensión GD con soporte para FreeType y JPEG
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd

# 3. Copiar el código fuente
WORKDIR /var/www/html

# Copiar archivos de configuración antes de copiar la aplicación
# Asegúrate de que este archivo contiene DocumentRoot /var/www/html/public
COPY apache-config.conf /etc/apache2/sites-enabled/000-default.conf

# Copiar la aplicación
COPY . /var/www/html

# 4. Configurar permisos y dependencias
# Permisos recursivos para el usuario 'www-data' (Apache) sobre todo el directorio.
RUN chown -R www-data:www-data /var/www/html

# Asegurar que el directorio metadatos exista y sea escribible
RUN mkdir -p /var/www/html/metadatos && \
    chown -R www-data:www-data /var/www/html/metadatos

# Instalar dependencias de Composer
RUN composer install --no-dev --optimize-autoloader

# 🔥🔥🔥 LÍNEA CRÍTICA AGREGADA: Habilitar el módulo de reescritura de URLs
RUN a2enmod rewrite

# 5. Configuración final
# Expone el puerto por defecto de Apache
EXPOSE 80

# Comando para forzar el inicio de Apache en primer plano
CMD ["/usr/sbin/apache2ctl", "-D", "FOREGROUND"]
