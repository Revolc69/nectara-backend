FROM php:8.2-apache

RUN a2enmod rewrite \
    && docker-php-ext-install pdo pdo_mysql mysqli

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html
