# Usa una imagen oficial de PHP con Apache
FROM php:8.2-apache

# Copia el archivo de configuración personalizado de Apache y habilítalo
COPY apache-config.conf /etc/apache2/sites-available/000-default.conf

# Instala las dependencias del sistema necesarias para Greenter y Composer
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
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd \
    && docker-php-ext-install soap \
    && docker-php-ext-install zip \
    && docker-php-ext-install intl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Instala Composer (el manejador de paquetes de PHP)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Establece el directorio de trabajo dentro del contenedor
WORKDIR /var/www/html

# Copia los archivos de tu proyecto al contenedor
COPY . .

# Instala las dependencias de PHP (como Greenter)
RUN composer install --no-dev --optimize-autoloader

# Asegúrate de que el servidor web (Apache) tenga permisos
RUN chown -R www-data:www-data /var/www/html

# Exponer el puerto 80
EXPOSE 80
