FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
    libpq-dev libzip-dev zip unzip git \
    && docker-php-ext-install pdo pdo_mysql opcache zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . .

RUN composer install --optimize-autoloader --no-interaction --ignore-platform-reqs --no-scripts

RUN chown -R www-data:www-data /var/www/var /var/www/public

USER www-data

CMD ["php-fpm"]