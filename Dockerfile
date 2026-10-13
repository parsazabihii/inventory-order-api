FROM composer:2 AS composer_binary
FROM php:8.4-cli
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libonig-dev libxml2-dev libzip-dev unzip git && docker-php-ext-install pdo_pgsql mbstring dom xml zip && rm -rf /var/lib/apt/lists/*
COPY --from=composer_binary /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY . /app
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
