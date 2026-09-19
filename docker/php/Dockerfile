# syntax=docker/dockerfile:1

# ---------- base: PHP extensions shared by dev and prod ----------
# FROM php:8.3-fpm-alpine AS base
FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache icu-dev oniguruma-dev libzip-dev \
 && docker-php-ext-install pdo_mysql bcmath intl opcache zip \
 && rm -rf /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

# ---------- dev: source is bind-mounted by Compose ----------
FROM base AS dev
RUN apk add --no-cache $PHPIZE_DEPS linux-headers \
 && pecl install xdebug && docker-php-ext-enable xdebug
CMD ["php-fpm"]

# ---------- vendor: production composer install ----------
FROM base AS vendor
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction

# ---------- assets: build the React bundle ----------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# ---------- prod: the image you push to ECR ----------
FROM base AS prod

RUN apk add --no-cache nginx supervisor \
 && mkdir -p /run/nginx

COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

COPY docker/nginx/default.prod.conf /etc/nginx/http.d/default.conf
COPY docker/php/supervisord.conf /etc/supervisord.conf
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

RUN composer dump-autoload --optimize --no-dev \
 && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=3s --start-period=20s \
  CMD wget -qO- http://127.0.0.1/up || exit 1

CMD ["supervisord", "-c", "/etc/supervisord.conf"]
