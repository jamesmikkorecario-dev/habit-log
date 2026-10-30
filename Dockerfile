# syntax=docker/dockerfile:1
FROM composer:2 AS composer
FROM node:22-bookworm-slim AS node

FROM php:8.3-apache-bookworm AS php-base
RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libzip-dev libonig-dev unzip git curl \
    && docker-php-ext-install -j2 pdo_mysql mbstring intl zip bcmath pcntl opcache \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite \
    && sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/apache/app.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/app.ini /usr/local/etc/php/conf.d/habit-log.ini
COPY docker/healthcheck.sh /usr/local/bin/habit-log-healthcheck
WORKDIR /var/www/html
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD ["sh", "/usr/local/bin/habit-log-healthcheck"]

FROM php-base AS dependencies
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts \
    && php artisan package:discover --ansi \
    && composer check-platform-reqs --no-dev

# Tailwind scans Blade/Flux templates, including installed PHP packages.
FROM node AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
COPY --from=dependencies /var/www/html/vendor ./vendor
RUN npm run build

FROM php-base AS development
ARG LOCAL_UID=1000
ARG LOCAL_GID=1000
RUN groupmod -g "$LOCAL_GID" www-data \
    && usermod -u "$LOCAL_UID" -g "$LOCAL_GID" www-data \
    && mkdir -p /home/www-data /var/run/apache2 /var/lock/apache2 \
    && chown -R www-data:www-data /home/www-data /var/www/html /var/run/apache2 /var/lock/apache2
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
ENV COMPOSER_HOME=/tmp/composer NPM_CONFIG_CACHE=/tmp/npm
COPY --chown=www-data:www-data . .
USER www-data
RUN composer install --prefer-dist --no-interaction \
    && npm ci
CMD ["apache2-foreground"]

FROM php-base AS production
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr
COPY --from=dependencies /var/www/html/app ./app
COPY --from=dependencies /var/www/html/bootstrap ./bootstrap
COPY --from=dependencies /var/www/html/config ./config
COPY --from=dependencies /var/www/html/database ./database
COPY --from=dependencies /var/www/html/routes ./routes
COPY --from=dependencies /var/www/html/resources ./resources
COPY --from=dependencies /var/www/html/vendor ./vendor
COPY --from=dependencies /var/www/html/artisan ./artisan
COPY --from=dependencies /var/www/html/composer.json ./composer.json
COPY --from=dependencies /var/www/html/public ./public
COPY --from=assets /app/public/build ./public/build
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs \
        /var/run/apache2 /var/lock/apache2 \
    && chown -R www-data:www-data storage bootstrap/cache /var/run/apache2 /var/lock/apache2
USER www-data
CMD ["apache2-foreground"]
