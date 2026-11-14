# KaloAI · imagen para desarrollo local (PHP 8.3 + Apache)
FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip libzip-dev \
    && docker-php-ext-install pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY composer.json ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-autoloader

COPY . .
# La configuración lee la base de datos y la clave de Gemini de las variables de entorno
RUN composer dump-autoload --no-dev --optimize \
    && cp core/configuracion-example.php core/configuracion.php \
    && chown -R www-data:www-data /var/www/html
