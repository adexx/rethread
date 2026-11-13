# Development image: PHP 8.3 + Apache with the extensions Rethread needs.
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libwebp-dev libfreetype6-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j"$(nproc)" gd pdo_mysql \
 && pecl install apcu && docker-php-ext-enable apcu \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite headers expires \
 && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

COPY docker/php.ini /usr/local/etc/php/conf.d/rethread.ini
