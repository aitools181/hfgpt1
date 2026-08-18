# syntax=docker/dockerfile:1.7
FROM node:22-bookworm-slim AS frontend
WORKDIR /app
COPY package*.json ./
RUN set -eu; \
    npm config set fetch-retries 5; \
    npm config set fetch-retry-mintimeout 2000; \
    npm config set fetch-retry-maxtimeout 120000; \
    attempt=1; \
    while [ "$attempt" -le 3 ]; do \
      if [ -f package-lock.json ]; then npm ci --no-audit --no-fund && break; else npm install --no-audit --no-fund && break; fi; \
      [ "$attempt" -eq 3 ] && exit 1; \
      sleep $((attempt * 10)); attempt=$((attempt + 1)); \
    done
COPY vite.config.ts tsconfig.json ./
COPY resources ./resources
COPY public ./public
RUN npm run build

FROM php:8.4-fpm-bookworm AS php-base
COPY docker/php/conf.d/zz-production.ini /usr/local/etc/php/conf.d/zz-production.ini
COPY docker/php/fpm/zz-happy-family.conf /usr/local/etc/php-fpm.d/zz-happy-family.conf
RUN apt-get -o Acquire::Retries=3 update && apt-get -o Acquire::Retries=3 install -y --no-install-recommends \
    libpq-dev libzip-dev libonig-dev libicu-dev libxml2-dev unzip curl \
    && docker-php-ext-install pdo_pgsql pcntl zip mbstring intl dom simplexml xmlreader \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

FROM php-base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.* ./
RUN set -eu; attempt=1; \
    while [ "$attempt" -le 3 ]; do \
      composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts && break; \
      [ "$attempt" -eq 3 ] && exit 1; \
      sleep $((attempt * 10)); attempt=$((attempt + 1)); \
    done
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction

FROM php-base AS app
WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY --from=frontend /app/public/build /var/www/html/public/build
COPY docker/entrypoint.sh /usr/local/bin/happy-family-entrypoint
COPY docker/runtime-healthcheck.sh /usr/local/bin/happy-family-healthcheck
COPY docker/background-start.sh /usr/local/bin/happy-family-background
RUN chmod +x /usr/local/bin/happy-family-entrypoint /usr/local/bin/happy-family-healthcheck /usr/local/bin/happy-family-background \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache
ENTRYPOINT ["happy-family-entrypoint"]
CMD ["php-fpm"]

# Production HTTP service. Nginx and PHP-FPM intentionally run in the same
# container so reverse-proxy traffic never depends on cross-container FastCGI DNS.
# Queue and scheduler remain separate containers using the app target above.
FROM app AS web
USER root
RUN apt-get -o Acquire::Retries=3 update && apt-get -o Acquire::Retries=3 install -y --no-install-recommends nginx \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default /etc/nginx/conf.d/default.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/happy-family.conf
COPY docker/web-start.sh /usr/local/bin/happy-family-web
RUN chmod +x /usr/local/bin/happy-family-web
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=8s --start-period=90s --retries=5 CMD /usr/local/bin/happy-family-healthcheck web
CMD ["happy-family-web"]
