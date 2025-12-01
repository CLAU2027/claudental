FROM php:8.2-apache

# Extensiones necesarias para MySQL (incluimos mysqli)
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Carpeta de trabajo en el contenedor
WORKDIR /var/www/html

# Copiar el contenido de la carpeta CLAUDENTAL al servidor web
COPY CLAUDENTAL/ /var/www/html/

# Permisos
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
