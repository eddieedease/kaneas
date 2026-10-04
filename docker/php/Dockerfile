# Mirrors a typical shared hosting setup: Apache + mod_php + .htaccess support.
FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

COPY php.ini /usr/local/etc/php/conf.d/kaneas.ini
