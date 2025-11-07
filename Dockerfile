# Usa una imagen oficial de PHP con Apache
FROM php:8.2-apache

# Instala Composer (el manejador de paquetes de PHP)
# Se copia desde una imagen temporal para asegurar la versión más reciente
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Instala las dependencias del sistema necesarias
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
    # Instalar librerías de MySQL para las extensiones de PHP
    default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd \
    && docker-php-ext-install soap \
    && docker-php-ext-install zip \
    && docker-php-ext-install intl \
    # AÑADIDO: Extensiones para conectar a base de datos MySQL/MariaDB
    && docker-php-ext-install mysqli pdo_mysql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Establece el directorio de trabajo dentro del contenedor
WORKDIR /var/www/html

# Copia los archivos de tu proyecto al contenedor
COPY . .

# Instala las dependencias de PHP (como Greenter)
RUN composer install --no-dev --optimize-autoloader

# Copia el archivo de configuración personalizado de Apache y habilítalo.
# Nota: Tu apache-config.conf DEBE tener DocumentRoot /var/www/html/public
COPY apache-config.conf /etc/apache2/sites-available/000-default.conf

# Asegúrate de que el servidor web (www-data) tenga permisos sobre los archivos de la aplicación
RUN chown -R www-data:www-data /var/www/html

# Exponer el puerto 80 (aunque el FROM ya lo hace, es una buena práctica)
EXPOSE 80

# Comando de inicio para forzar el inicio de Apache en primer plano (necesario para Docker)
CMD ["/usr/sbin/apache2ctl", "-D", "FOREGROUND"]
