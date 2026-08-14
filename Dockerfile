# syntax=docker/dockerfile:1.7
FROM node:22-bookworm-slim AS frontend
WORKDIR /app
COPY package.json ./
RUN npm install --no-audit --no-fund
COPY vite.config.ts tsconfig.json ./
COPY resources ./resources
COPY public ./public
RUN npm run build

FROM php:8.4-fpm-bookworm AS php-base
COPY docker/php/conf.d/zz-production.ini /usr/local/etc/php/conf.d/zz-production.ini
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev libzip-dev libonig-dev libicu-dev libxml2-dev unzip curl \
    && docker-php-ext-install pdo_pgsql pcntl zip mbstring intl dom simplexml \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

FROM php-base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction

FROM php-base AS app
WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY --from=frontend /app/public/build /var/www/html/public/build
COPY docker/entrypoint.sh /usr/local/bin/happy-family-entrypoint
RUN chmod +x /usr/local/bin/happy-family-entrypoint \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache
ENTRYPOINT ["happy-family-entrypoint"]
CMD ["php-fpm"]

FROM nginx:1.28-alpine AS web
WORKDIR /var/www/html
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY public /var/www/html/public
COPY --from=frontend /app/public/build /var/www/html/public/build
RUN ln -s /var/www/html/storage/app/public /var/www/html/public/storage
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
