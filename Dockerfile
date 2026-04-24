FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-scripts

FROM node:20-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY public ./public
COPY vite.config.js postcss.config.js tailwind.config.js ./
RUN npm run build

FROM php:8.2-cli-alpine
WORKDIR /app

RUN apk add --no-cache bash icu-dev libzip-dev sqlite-dev oniguruma-dev \
    && docker-php-ext-install pdo pdo_sqlite mbstring intl

COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

RUN mkdir -p database storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
    && touch database/database.sqlite \
    && chmod -R 775 storage bootstrap/cache database \
    && php artisan package:discover --ansi

EXPOSE 10000

CMD sh -c "php artisan config:cache && php artisan view:cache && php artisan serve --host=0.0.0.0 --port=${PORT:-10000}"
