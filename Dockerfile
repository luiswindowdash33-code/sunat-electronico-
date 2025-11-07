
# Usa una imagen oficial de PHP con Apache
FROM php:8.2-apache

# Configura Apache para que use /var/www/html/src como el directorio raíz
ENV APACHE_DOCUMENT_ROOT /var/www/html/src
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

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
# Si tienes un composer.json en la carpeta src, descomenta la siguiente línea
# RUN cd src && composer install --no-dev --optimize-autoloader
# Si tu composer.json está en la raíz, usa esta:
RUN composer install --no-dev --optimize-autoloader

# Asegúrate de que el servidor web (Apache) tenga permisos
RUN chown -R www-data:www-data /var/www/html

# Exponer el puerto 80
EXPOSE 80
