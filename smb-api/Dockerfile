FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --no-progress \
    --no-scripts

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts \
    && APP_ENV=production APP_DEBUG=false php artisan package:discover --ansi

FROM php:8.4-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libpq-dev libzip-dev \
    && docker-php-ext-install bcmath intl opcache pcntl pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app /var/www/html
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm", "-F"]
